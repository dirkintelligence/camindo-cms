<?php
/**
 * Inhalte des Audit-Logs im Dateisystem - je Objekt eine Datei, nur angehängt,
 * mit HMAC-Kette (developer_docs/16-sicherheit.md §20). Die Datenbank (`audit_log`)
 * hält nur die Metadaten für Listen und Recherche (AuditLog).
 *
 * ABLAGE
 * --------------------
 *     var/audit/{object_type}/{bucket}/{object_id}.jsonl        Seite 1
 *     var/audit/{object_type}/{bucket}/{object_id}.{n}.jsonl    Seite n
 * bucket = object_id / 1000 (höchstens 1000 Dateien je Verzeichnis). Wird eine Seite
 * größer als audit.page_bytes (Vorgabe 1 MB), beginnt die nächste; die Kette läuft
 * über die Seiten weiter.
 *
 * ZEILENFORMAT
 * --------------------
 *     {h}<TAB>{json}\n
 * json enthält id (audit_id), t (UTC mit ms), action, type, oid, actor, label, area,
 * instance, changes - dazu prev (h der vorigen Zeile, '' am Anfang) und k (Id des
 * Schlüssels, Crypt::currentKeyId()). h = HMAC über die json-Zeichenkette, so wie sie
 * in der Datei steht - geprüft wird byteweise, ohne erneutes Kodieren.
 *
 * Kontrollpunkte (json mit "cp") beginnen eine Kette neu, wo Zeilen bewusst entfernt
 * oder umgeschrieben wurden:
 *     trim     Aufbewahrungsfrist - "anchor" ist das prev der ersten verbliebenen Zeile
 *     rekey    Schlüsselrotation - Werte neu verschlüsselt, Kette neu berechnet;
 *              "verified" sagt, ob die alte Kette vorher intakt war
 *     migrate  Übernahme aus der früheren Spalte audit_log.changes
 *     restore  Datenbanksicherung zurückgespielt - Einträge nach dem Stand der
 *              Sicherung (audit_id > "max_id") entfernt, siehe cutAfter()
 *     reseal   gebrochene Kette nach Prüfung neu verkettet (`camindo.php audit reseal`,
 *              reseal()) - die Einträge ab der Bruchstelle; "relinked" zählt sie,
 *              "reason" nennt den Anlass
 *     anonymize  IP-Adressen früherer Einträge gekürzt (anonymizeIps()) - Einträge
 *              auf ihren Seiten belassen, Kette neu berechnet; "entries" zählt sie
 *     privacy    Felder nach einer Lösch-Policy überschrieben (rewriteObject());
 *              "verified" sagt, ob die Kette vorher intakt war
 * Nach einem Kontrollpunkt erwartet die nächste Zeile prev = anchor bzw. h des
 * Kontrollpunkts.
 *
 * WAS DAS SCHÜTZT
 * --------------------
 * Wer nur die Datenbank erreicht, kann die Inhalte weder lesen noch ändern; gelöschte,
 * hinzugefügte oder geänderte Metazeilen fallen im Abgleich auf (AuditLog::verifyObject(),
 * verifyAll()). Eine in der Datei geänderte, entfernte
 * oder eingeschobene Zeile bricht die Kette - fälschen kann sie nur, wer den Schlüssel
 * hat. Das abgeschnittene Ende einer Datei erkennt der Abgleich mit der Datenbank.
 *
 * Geschrieben wird nach dem Commit (Database::afterCommit(), AuditLog::write()) -
 * ein Rollback hinterlässt nichts. Parallele Schreiber serialisiert flock().
 */
namespace Camindo\Core;

class AuditStore {

    /** Vorgabe für audit.page_bytes */
    public const PAGE_BYTES = 1048576;

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    public function __construct(
        private Config $config,
        private Crypt $crypt,
    )
    {
    }

    /** var/audit */
    public function dir(): string
    {
        return rtrim((string)$this->config->get('paths.var_dir'), '/') . '/audit';
    }

    /**
     * Datei einer Seite.
     */
    public function path(string $type, int $objectId, int $page = 1): string
    {
        $type = preg_replace('/[^a-z0-9_]/', '', strtolower($type)) ?: 'unknown';
        $bucket = intdiv(max(0, $objectId), 1000);
        return $this->dir() . "/$type/$bucket/$objectId" . ($page > 1 ? ".$page" : '') . '.jsonl';
    }

    /** Vorhandene Seiten eines Objekts, aufsteigend: [Seite => Pfad] */
    public function pages(string $type, int $objectId): array
    {
        $pages = [];
        $first = $this->path($type, $objectId, 1);
        if (is_file($first)) $pages[1] = $first;
        foreach (glob(substr($first, 0, -6) . '.*.jsonl') ?: [] as $file) {
            if (preg_match('/\.(\d+)\.jsonl$/', $file, $m)) $pages[(int)$m[1]] = $file;
        }
        ksort($pages);
        return $pages;
    }

    // ====================
    // Schreiben

    /**
     * Einen Eintrag anhängen (siehe Klassenkommentar).
     *
     * @param array $entry  id, t, action, type, oid, actor, label, area, instance, changes
     * @return int          Seite, in der der Eintrag steht
     */
    public function append(array $entry): int
    {
        $type = (string)$entry['type'];
        $objectId = (int)$entry['oid'];
        $pages = $this->pages($type, $objectId);
        $page = $pages ? (int)array_key_last($pages) : 1;
        $file = $this->path($type, $objectId, $page);
        $this->ensureDir(dirname($file));

        $handle = fopen($file, 'c+');
        if ($handle === false) throw new \RuntimeException("cannot open $file");
        try {
            flock($handle, LOCK_EX);
            $size = (int)fstat($handle)['size'];
            $prev = $size > 0 ? $this->lastHash($handle, $size) : $this->previousPageHash($type, $objectId, $page);
            // Seite voll: nächste beginnen (unter der Sperre der vollen Seite)
            if ($size >= $this->pageBytes()) {
                flock($handle, LOCK_UN);
                fclose($handle);
                $page++;
                $file = $this->path($type, $objectId, $page);
                $handle = fopen($file, 'c+');
                if ($handle === false) throw new \RuntimeException("cannot open $file");
                flock($handle, LOCK_EX);
                $size = (int)fstat($handle)['size'];
                if ($size > 0) $prev = $this->lastHash($handle, $size);
            }
            fseek($handle, 0, SEEK_END);
            fwrite($handle, $this->line($entry, $prev));
            fflush($handle);
            flock($handle, LOCK_UN);
        }
        finally {
            if (is_resource($handle)) fclose($handle);
        }
        $this->chmodFile($file);
        return $page;
    }

    /** Eine Zeile: h TAB json mit prev und k */
    private function line(array $body, string $prev): string
    {
        $body['prev'] = $prev;
        $body['k'] = $this->crypt->currentKeyId();
        $json = json_encode($body, self::JSON);
        return $this->crypt->hash($json) . "\t" . $json . "\n";
    }

    /** h der letzten Zeile einer offenen Datei */
    private function lastHash($handle, int $size): string
    {
        $chunk = min($size, 65536);
        fseek($handle, -$chunk, SEEK_END);
        $tail = rtrim((string)fread($handle, $chunk), "\n");
        $pos = strrpos($tail, "\n");
        $last = $pos === false ? $tail : substr($tail, $pos + 1);
        if (strlen($tail) === $chunk && $pos === false && $chunk < $size) {
            // Zeile länger als der Ausschnitt: ganz lesen
            $lines = $this->readLines(stream_get_meta_data($handle)['uri']);
            $last = (string)end($lines);
        }
        return (string)strstr($last, "\t", true);
    }

    /** h der letzten Zeile der Seite davor ('' bei Seite 1) */
    private function previousPageHash(string $type, int $objectId, int $page): string
    {
        if ($page <= 1) return '';
        $lines = $this->readLines($this->path($type, $objectId, $page - 1));
        return $lines ? (string)strstr((string)end($lines), "\t", true) : '';
    }

    // ====================
    // Lesen und prüfen

    /**
     * Einträge eines Objekts: audit_id => Eintrag (ohne Kontrollpunkte).
     *
     * @param int|null $page  nur diese Seite
     */
    public function entries(string $type, int $objectId, ?int $page = null): array
    {
        $files = $page !== null ? [$page => $this->path($type, $objectId, $page)] : $this->pages($type, $objectId);
        $entries = [];
        foreach ($files as $file) {
            foreach ($this->readLines($file) as $line) {
                $json = substr($line, (int)strpos($line, "\t") + 1);
                $body = json_decode($json, true);
                if (!is_array($body) || isset($body['cp'])) continue;
                $entries[(int)$body['id']] = $body;
            }
        }
        return $entries;
    }

    /**
     * Kette eines Objekts prüfen.
     *
     * @return array  ok (bool), lines, ids (Liste der audit_ids), entries (audit_id =>
     *                Eintrag, nur Zeilen mit gültigem HMAC), errors (Liste aus page,
     *                line, reason), keys (verwendete Schlüssel-Ids)
     */
    public function verify(string $type, int $objectId): array
    {
        $result = ['ok' => true, 'lines' => 0, 'ids' => [], 'entries' => [], 'errors' => [], 'keys' => []];
        $expected = '';
        $first = true;
        foreach ($this->pages($type, $objectId) as $page => $file) {
            foreach ($this->readLines($file) as $number => $line) {
                $result['lines']++;
                $error = function (string $reason) use (&$result, $page, $number) {
                    $result['ok'] = false;
                    $result['errors'][] = ['page' => $page, 'line' => $number + 1, 'reason' => $reason];
                };
                $tab = strpos($line, "\t");
                if ($tab === false) { $error('format'); continue; }
                $h = substr($line, 0, $tab);
                $json = substr($line, $tab + 1);
                $body = json_decode($json, true);
                if (!is_array($body)) { $error('json'); continue; }
                $kid = (string)($body['k'] ?? '');
                $result['keys'][$kid] = true;
                $check = $this->crypt->hashWithKey($kid, $json);
                $signed = false;
                if ($check === null) { $error('unknown key ' . $kid); }
                elseif (!hash_equals($check, $h)) { $error('hmac'); }
                else $signed = true;
                if (isset($body['cp'])) {
                    // Kontrollpunkt: beginnt die Kette neu
                    $expected = (string)($body['anchor'] ?? $h);
                    $first = false;
                    continue;
                }
                if (!$first && (string)($body['prev'] ?? '') !== $expected) $error('chain');
                if ($first && (string)($body['prev'] ?? '') !== '') $error('chain start');
                $first = false;
                $expected = $h;
                $result['ids'][] = (int)($body['id'] ?? 0);
                if ($signed) $result['entries'][(int)($body['id'] ?? 0)] = $body;
            }
        }
        $result['keys'] = array_keys($result['keys']);
        return $result;
    }

    /**
     * Alle Objekt-Dateien: Liste aus [type, object_id].
     */
    public function objects(): array
    {
        $objects = [];
        foreach (glob($this->dir() . '/*/*/*.jsonl') ?: [] as $file) {
            if (!preg_match('~/([a-z0-9_]+)/\d+/(\d+)(?:\.\d+)?\.jsonl$~', $file, $m)) continue;
            $objects[$m[1] . ':' . $m[2]] = [$m[1], (int)$m[2]];
        }
        return array_values($objects);
    }

    // ====================
    // Aufbewahrung, Umschreiben

    /** Alle Seiten eines Objekts löschen */
    public function delete(string $type, int $objectId): void
    {
        foreach ($this->pages($type, $objectId) as $file) @unlink($file);
    }

    /**
     * Einträge entfernen (Aufbewahrungsfrist): $remove(array $body): bool entscheidet je
     * Eintrag. Seiten, die ganz wegfallen, werden gelöscht; verbleibt etwas, beginnt die
     * erste verbliebene Seite mit einem Kontrollpunkt 'trim'.
     *
     * @return int  entfernte Einträge
     */
    public function trim(string $type, int $objectId, callable $remove): int
    {
        $removed = 0;
        $anchor = '';
        $pendingCheckpoints = [];
        foreach ($this->pages($type, $objectId) as $file) {
            $lines = $this->readLines($file);
            $keep = [];
            $started = false;
            foreach ($lines as $line) {
                if (!$started) {
                    $body = json_decode(substr($line, (int)strpos($line, "\t") + 1), true);
                    if (is_array($body) && isset($body['cp'])) {
                        $pendingCheckpoints[] = $line;
                        continue;
                    }
                    if (is_array($body) && $remove($body)) {
                        $removed++;
                        $anchor = (string)strstr($line, "\t", true);
                        continue;
                    }
                    $started = true;
                    // Nichts entfernt: die bisherigen Kontrollpunkte bleiben stehen,
                    // sonst ersetzt sie ein neuer mit dem h der letzten entfernten Zeile
                    if ($removed === 0) {
                        array_push($keep, ...$pendingCheckpoints);
                    }
                    else {
                        $checkpoint = ['cp' => 'trim', 't' => gmdate('Y-m-d H:i:s'), 'removed' => $removed, 'anchor' => $anchor];
                        $keep[] = rtrim($this->line($checkpoint, ''), "\n");
                    }
                }
                $keep[] = $line;
            }
            if ($removed === 0) return 0;
            if ($keep) {
                $this->writeFile($file, implode("\n", $keep) . "\n");
                return $removed;
            }
            // Seite ganz entfallen - weiter mit der nächsten
            @unlink($file);
        }
        return $removed;
    }

    /**
     * Einträge mit einer audit_id über $maxId entfernen - nach dem Zurückspielen einer
     * Datenbanksicherung (`camindo.php audit restore`, AuditLog::restoreToDatabase()):
     * die Metazeilen dieser Einträge gibt es nicht mehr, und die Datenbank vergibt
     * ihre Ids neu.
     *
     * Die Zeilen vor der ersten entfernten bleiben unverändert (die Kette bis dahin
     * ist intakt); danach folgt ein Kontrollpunkt 'restore' und - selten, nur wenn
     * Einträge nicht in Id-Reihenfolge angehängt wurden - die übrigen verbliebenen
     * Einträge, neu verkettet. Bleibt kein Eintrag, entfällt die Datei.
     *
     * @param string|null $archiveDir  Kopie der betroffenen Seiten vor dem Ändern
     * @return int                     entfernte Einträge
     */
    public function cutAfter(string $type, int $objectId, int $maxId, ?string $archiveDir = null): int
    {
        $pages = $this->pages($type, $objectId);
        $keep = [];          // [Seite => Zeilen] unverändert
        $rest = [];          // nach der ersten entfernten Zeile verbliebene Einträge
        $removed = 0;
        $entriesLeft = 0;
        $lastPage = 1;
        foreach ($pages as $page => $file) {
            foreach ($this->readLines($file) as $line) {
                $body = json_decode(substr($line, (int)strpos($line, "\t") + 1), true);
                $isEntry = is_array($body) && !isset($body['cp']);
                if ($isEntry && (int)($body['id'] ?? 0) > $maxId) {
                    $removed++;
                    continue;
                }
                if ($removed === 0) {
                    $keep[$page][] = $line;
                    $lastPage = $page;
                    if ($isEntry) $entriesLeft++;
                    continue;
                }
                // Kontrollpunkte hinter der Schnittstelle ersetzt der neue
                if ($isEntry) {
                    $rest[] = $body;
                    $entriesLeft++;
                }
            }
        }
        if ($removed === 0) return 0;

        if ($archiveDir !== null) {
            foreach ($pages as $file) {
                $target = $archiveDir . '/' . substr($file, strlen($this->dir()) + 1);
                $this->ensureDir(dirname($target));
                if (!@copy($file, $target)) throw new \RuntimeException("cannot copy $file");
            }
        }
        if ($entriesLeft === 0) {
            $this->delete($type, $objectId);
            return $removed;
        }

        $checkpoint = ['cp' => 'restore', 't' => gmdate('Y-m-d H:i:s'), 'removed' => $removed, 'max_id' => $maxId];
        $tail = $this->line($checkpoint, '');
        $prev = (string)strstr($tail, "\t", true);
        foreach ($rest as $body) {
            unset($body['prev'], $body['k']);
            $line = $this->line($body, $prev);
            $prev = (string)strstr($line, "\t", true);
            $tail .= $line;
        }
        foreach ($pages as $page => $file) {
            if ($page > $lastPage) {
                @unlink($file);
                continue;
            }
            $content = isset($keep[$page]) ? implode("\n", $keep[$page]) . "\n" : '';
            if ($page === $lastPage) $content .= $tail;
            $this->writeFile($file, $content);
        }
        if (!$pages) $this->writeFile($this->path($type, $objectId, 1), $tail);
        return $removed;
    }

    /**
     * Alle Dateien neu schreiben - Schlüsselrotation (KeyRotation): vorher die alte
     * Kette prüfen, dann jeden Eintrag durch $transform(array $body): array schicken
     * (Werte neu verschlüsseln) und die Kette mit dem aktuellen Schlüssel neu
     * berechnen, beginnend mit Kontrollpunkt 'rekey'.
     *
     * @return array  files, entries, broken (Dateien, deren alte Kette nicht intakt war)
     */
    public function rewriteAll(callable $transform, string $reason = 'rekey'): array
    {
        $result = ['files' => 0, 'entries' => 0, 'broken' => []];
        foreach ($this->objects() as [$type, $objectId]) {
            $check = $this->verify($type, $objectId);
            $entries = $this->entries($type, $objectId);
            $pages = $this->pages($type, $objectId);
            if (!$check['ok']) $result['broken'][] = "$type:$objectId";
            // Alles auf Seite 1 neu, übrige Seiten entfallen (Seitenwechsel beim Anhängen)
            $file = $this->path($type, $objectId, 1);
            $checkpoint = ['cp' => $reason, 't' => gmdate('Y-m-d H:i:s'), 'verified' => $check['ok'],
                'from' => $check['keys']];
            $prevLine = $this->line($checkpoint, '');
            $content = $prevLine;
            $prev = (string)strstr($prevLine, "\t", true);
            foreach ($entries as $body) {
                unset($body['prev'], $body['k']);
                $line = $this->line($transform($body), $prev);
                $prev = (string)strstr($line, "\t", true);
                $content .= $line;
                $result['entries']++;
            }
            foreach ($pages as $page => $path) if ($page > 1) @unlink($path);
            $this->writeFile($file, $content);
            $result['files']++;
        }
        return $result;
    }

    /**
     * Eine gebrochene Kette neu verketten (`camindo.php audit reseal TYP ID`).
     *
     * Für den Fall, dass die Einträge selbst echt sind (jede Zeile trägt einen
     * gültigen HMAC), die Verkettung aber nicht stimmt - etwa weil eine Datei
     * zwischenzeitlich ersetzt oder aus einer Sicherung zurückgeholt wurde. Die Zeilen
     * bis zur ersten Bruchstelle bleiben unverändert; danach folgt ein Kontrollpunkt
     * 'reseal', und alle übrigen Einträge werden mit dem aktuellen Schlüssel neu
     * verkettet (frühere Kontrollpunkte dahinter entfallen). Alles steht danach auf
     * Seite 1.
     *
     * Hat eine Zeile keinen gültigen HMAC (Inhalt geändert, unbekannter Schlüssel,
     * kaputtes Format), wird nur mit $force neu verkettet - dann ist ihr Inhalt nicht
     * mehr belegt, und der Kontrollpunkt vermerkt das ("unverified"). Zeilen, die sich
     * nicht lesen lassen, fallen weg.
     *
     * Das ist ein Vertrauensakt dessen, der die Kommandozeile hat - wie
     * `camindo.php admin sign`: danach gilt die Kette wieder als intakt.
     *
     * @param string      $reason      Anlass (steht im Kontrollpunkt)
     * @param bool        $force       auch Zeilen ohne gültigen HMAC übernehmen
     * @param bool        $dryRun      nur prüfen
     * @param string|null $archiveDir  Kopie der Seiten vor dem Ändern
     * @return array  status (intact | refused | resealed | would_reseal), relinked
     *                (neu verkettete Einträge), unverified (Zeilen ohne gültigen HMAC),
     *                errors (Befunde der Prüfung vorher)
     */
    public function reseal(string $type, int $objectId, string $reason = '', bool $force = false, bool $dryRun = false, ?string $archiveDir = null): array
    {
        $check = $this->verify($type, $objectId);
        $result = ['status' => 'intact', 'relinked' => 0, 'unverified' => 0, 'errors' => $check['errors']];
        if ($check['ok']) return $result;

        $unverified = array_filter($check['errors'], fn($e) => !in_array($e['reason'], ['chain', 'chain start'], true));
        $result['unverified'] = count($unverified);
        if ($unverified && !$force) {
            $result['status'] = 'refused';
            return $result;
        }

        // Zeilen aller Seiten der Reihe nach; Bruchstelle = erster Befund
        $first = $check['errors'][0];
        $keep = [];
        $rest = [];
        $broken = false;
        foreach ($this->pages($type, $objectId) as $page => $file) {
            foreach ($this->readLines($file) as $number => $line) {
                if (!$broken && $page === (int)$first['page'] && $number + 1 === (int)$first['line']) $broken = true;
                if (!$broken) {
                    $keep[] = $line;
                    continue;
                }
                $body = json_decode(substr($line, (int)strpos($line, "\t") + 1), true);
                if (!is_array($body) || isset($body['cp'])) continue;
                $rest[] = $body;
            }
        }
        $result['relinked'] = count($rest);
        if ($dryRun) {
            $result['status'] = 'would_reseal';
            return $result;
        }

        $pages = $this->pages($type, $objectId);
        if ($archiveDir !== null) {
            foreach ($pages as $file) {
                $target = $archiveDir . '/' . substr($file, strlen($this->dir()) + 1);
                $this->ensureDir(dirname($target));
                if (!@copy($file, $target)) throw new \RuntimeException("cannot copy $file");
            }
        }
        $checkpoint = ['cp' => 'reseal', 't' => gmdate('Y-m-d H:i:s'), 'relinked' => count($rest), 'reason' => $reason];
        if ($unverified) $checkpoint['unverified'] = count($unverified);
        $content = $keep ? implode("\n", $keep) . "\n" : '';
        $line = $this->line($checkpoint, '');
        $content .= $line;
        $prev = (string)strstr($line, "\t", true);
        foreach ($rest as $body) {
            unset($body['prev'], $body['k']);
            $line = $this->line($body, $prev);
            $prev = (string)strstr($line, "\t", true);
            $content .= $line;
        }
        foreach ($pages as $page => $path) if ($page > 1) @unlink($path);
        $this->writeFile($this->path($type, $objectId, 1), $content);
        $result['status'] = 'resealed';
        return $result;
    }

    /**
     * Die Einträge EINES Objekts umschreiben - Lösch-Policy (AuditLog::overwriteObject()):
     * $transform(array $body): ?array je Eintrag - null entfernt ihn (Aufbewahrungsfrist
     * der Anmeldungen, AuditLog::purge()). Die Einträge bleiben auf ihren Seiten,
     * die Kette beginnt mit dem Kontrollpunkt $reason neu; "verified" sagt, ob sie
     * vorher intakt war. Anders als anonymizeIps() wird auch eine gebrochene Kette
     * umgeschrieben - personenbezogene Daten müssen weg, der Befund bleibt im
     * Kontrollpunkt vermerkt.
     *
     * @return int  Einträge, die sich geändert haben
     */
    public function rewriteObject(string $type, int $objectId, callable $transform, string $reason = 'privacy'): int
    {
        $pages = [];
        $changed = 0;
        foreach ($this->pages($type, $objectId) as $page => $file) {
            $pages[$page] = [];
            foreach ($this->readLines($file) as $line) {
                $body = json_decode(substr($line, (int)strpos($line, "\t") + 1), true);
                if (!is_array($body) || isset($body['cp'])) continue;
                unset($body['prev'], $body['k']);
                $new = $transform($body);
                if ($new !== $body) $changed++;
                if ($new !== null) $pages[$page][] = $new;
            }
        }
        if ($changed === 0) return 0;
        $verified = $this->verify($type, $objectId)['ok'];
        $prev = '';
        foreach ($pages as $page => $bodies) {
            $content = '';
            if ($page === array_key_first($pages)) {
                $content = $this->line(['cp' => $reason, 't' => gmdate('Y-m-d H:i:s'), 'entries' => $changed, 'verified' => $verified], '');
                $prev = (string)strstr($content, "\t", true);
            }
            foreach ($bodies as $body) {
                $line = $this->line($body, $prev);
                $prev = (string)strstr($line, "\t", true);
                $content .= $line;
            }
            $this->writeFile($this->path($type, $objectId, $page), $content);
        }
        return $changed;
    }

    /**
     * IP-Adressen in den Dateien kürzen (`camindo.php audit anonymize-ip`) - für
     * Einträge aus der Zeit, bevor AuditLog::write() nur noch die gekürzte IP in die
     * Datei schrieb. $anonymize(string $ip): string liefert die gekürzte Adresse.
     *
     * Umgeschrieben wird nur eine Datei, in der wirklich eine IP zu kürzen ist, und
     * nur, wenn ihre Kette intakt ist - sonst würde das Kürzen eine gebrochene Kette
     * stillschweigend wieder gültig machen (dafür gibt es reseal()). Die Einträge
     * bleiben auf ihren Seiten (audit_log.page stimmt weiter); die Kette beginnt mit
     * dem Kontrollpunkt 'anonymize' neu, frühere Kontrollpunkte entfallen wie bei
     * rewriteAll(). Eine Kopie der alten Dateien gibt es bewusst nicht.
     *
     * @return array  files (umgeschrieben), entries (gekürzte IPs), skipped
     *                ("typ:id" mit gebrochener Kette - unverändert)
     */
    public function anonymizeIps(callable $anonymize, bool $dryRun = false): array
    {
        $result = ['files' => 0, 'entries' => 0, 'skipped' => []];
        foreach ($this->objects() as [$type, $objectId]) {
            $pages = [];
            $changed = 0;
            foreach ($this->pages($type, $objectId) as $page => $file) {
                $pages[$page] = [];
                foreach ($this->readLines($file) as $line) {
                    $body = json_decode(substr($line, (int)strpos($line, "\t") + 1), true);
                    if (!is_array($body) || isset($body['cp'])) continue;
                    $ip = (string)($body['actor']['ip'] ?? '');
                    if ($ip !== '') {
                        $short = (string)$anonymize($ip);
                        if ($short !== $ip) {
                            $body['actor']['ip'] = $short;
                            $changed++;
                        }
                    }
                    unset($body['prev'], $body['k']);
                    $pages[$page][] = $body;
                }
            }
            if ($changed === 0) continue;
            if (!$this->verify($type, $objectId)['ok']) {
                $result['skipped'][] = "$type:$objectId";
                continue;
            }
            $result['files']++;
            $result['entries'] += $changed;
            if ($dryRun) continue;

            $prev = '';
            foreach ($pages as $page => $bodies) {
                $content = '';
                if ($page === array_key_first($pages)) {
                    $content = $this->line(['cp' => 'anonymize', 't' => gmdate('Y-m-d H:i:s'), 'entries' => $changed], '');
                    $prev = (string)strstr($content, "\t", true);
                }
                foreach ($bodies as $body) {
                    $line = $this->line($body, $prev);
                    $prev = (string)strstr($line, "\t", true);
                    $content .= $line;
                }
                $this->writeFile($this->path($type, $objectId, $page), $content);
            }
        }
        return $result;
    }

    /**
     * Einträge unter einem Kontrollpunkt neu anlegen (Übernahme aus der Datenbank,
     * AuditLog::migrateToFiles()) - hängt an vorhandene Dateien an.
     */
    public function appendCheckpoint(string $type, int $objectId, string $reason): void
    {
        $file = $this->path($type, $objectId, 1);
        if (is_file($file) && filesize($file) > 0) return;
        $this->ensureDir(dirname($file));
        $this->writeFile($file, $this->line(['cp' => $reason, 't' => gmdate('Y-m-d H:i:s')], ''));
    }

    // ====================

    /** Zeilen einer Datei (ohne Zeilenende, ohne Leerzeilen) */
    private function readLines(string $file): array
    {
        if (!is_file($file)) return [];
        return file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    }

    private function writeFile(string $file, string $content): void
    {
        $this->ensureDir(dirname($file));
        if (@file_put_contents($file, $content, LOCK_EX) === false) throw new \RuntimeException("cannot write $file");
        $this->chmodFile($file);
    }

    private function pageBytes(): int
    {
        $bytes = (int)($this->config->get('audit.page_bytes') ?? 0);
        return $bytes > 0 ? $bytes : self::PAGE_BYTES;
    }

    private function ensureDir(string $dir): void
    {
        if (is_dir($dir)) return;
        $mode = (int)($this->config->get('permissions.dir') ?: 0775);
        if (!@mkdir($dir, $mode, true) && !is_dir($dir)) throw new \RuntimeException("cannot create $dir");
        @chmod($dir, $mode);
    }

    private function chmodFile(string $file): void
    {
        @chmod($file, (int)($this->config->get('permissions.file') ?: 0664));
    }
}
