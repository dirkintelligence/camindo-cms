<?php
/**
 * Schlüsselrotation: .camindo.key wechseln, ohne Daten zu verlieren
 * (`php Core/cron/camindo.php key rotate|status|retire`, 16-sicherheit.md §19).
 *
 * WOFÜR DER SCHLÜSSEL STEHT
 * --------------------
 *     umkehrbar verschlüsselt   data_enc.data, record_cache (Werte "encrypted"),
 *                               admin.email/mobile/phone, admin.totp_secret,
 *                               user_second_factor.totp_secret,
 *                               message.body/html, scheduler.details.password,
 *                               var/audit (verschlüsselte Werte der Schnappschüsse)
 *                               -> werden neu verschlüsselt; die HMAC-Kette des
 *                               Audit-Logs wird nach Prüfung neu berechnet
 *     HMAC über bekannten Text  data_enc.hash (Suche), Signaturen der Klasse Integrity
 *                               (admin, group, rel_group_*, admin_session)
 *                               -> werden neu berechnet ("neu signieren")
 *     HMAC über geheimen Text   Wiederherstellungscodes, Gerätekennungen: der Klartext
 *                               ist unbekannt -> gelten über Crypt::hashAll() weiter,
 *                               solange der alte Schlüssel im Schlüsselbund ist
 *     Pfeffer der Passwörter    ohne Klartext nicht umrechenbar -> die Hashes behalten
 *                               die Kennung des alten Schlüssels und werden bei der
 *                               nächsten Anmeldung neu berechnet
 *     abgeleitet                CSRF-Tokens (Session), Ersatz-Hash -> neu
 *
 * ABLAUF VON rotate()
 * --------------------
 *  1. Datenbanksicherung (Backup::beforeChange('key-rotate'))
 *  2. Wartungsmodus an (sofern nicht schon an)
 *  3. Passwort-Hashes ohne Kennung bekommen die des alten Schlüssels
 *  4. alter Schlüssel -> {key_file}.retired, neuer -> key_file (beide mit den Rechten
 *     der bisherigen key_file, ohne Datei permissions.key_file - keyFileMode()); ab hier
 *     entschlüsselt Crypt mit beiden, ein Abbruch verliert also nichts - rotate()
 *     lässt sich einfach erneut aufrufen (dann mit einem weiteren Schlüssel)
 *  5. alles Umkehrbare neu verschlüsseln, data_enc.hash neu
 *  6. Integrity::signAll() - alle Signaturen mit dem neuen Schlüssel, löscht alle
 *     Admin-Sessions; Gruppen-Cache neu
 *  7. mit $invalidatePasswords (Passwörter öffentlich aufgetaucht): alle Passwörter
 *     leer (Admins: `camindo.php admin password NAME`, User: "Passwort vergessen"),
 *     alle Sessions, Geräte und Wiederherstellungscodes weg, der alte Schlüssel
 *     wird sofort entfernt
 *  8. Wartungsmodus aus
 *
 * Höchstens eine Rotation je 24 Stunden (MIN_INTERVAL): eine zweite so kurz danach
 * wäre ein Versehen und schöbe den gerade abgelösten Schlüssel zu schnell weiter.
 * Der Zeitpunkt steht als Zeile "# rotated {ISO 8601}" in {key_file}.retired - auch
 * nach -f, wenn der alte Schlüssel selbst schon entfernt ist.
 *
 * Kommt der Schlüssel aus der Umgebung (CAMINDO_KEY, Crypt::keySource()), schreibt
 * rotate() den neuen trotzdem nach key_file - die Datei hat Vorrang. Die Variable
 * zieht der Betreiber von Hand nach; erst danach darf key_file wieder weg
 * (status(): env_key).
 *
 * Der alte Schlüssel bleibt danach im Schlüsselbund, bis retire() ihn entfernt -
 * status() zeigt, was noch an ihm hängt (Passwörter, die sich seitdem nicht
 * angemeldet haben; Wiederherstellungscodes von vorher). retire() entfernt ihn nur,
 * wenn nichts Verschlüsseltes mehr daran hängt; übrige Passwörter werden dabei
 * ungültig (mit $force).
 */
namespace Camindo\Core;

class KeyRotation {

    /** Mindestabstand zweier Rotationen in Sekunden */
    public const MIN_INTERVAL = 86400;

    public function __construct(
        private Config $config,
        private Database $db,
        private Crypt $crypt,
        private Integrity $integrity,
        private InstanceCache $instanceCache,
        private SystemState $systemState,
        private Backup $backup,
        private System $system,
        private AuditStore $auditStore,
        private Cms $cms,
    )
    {
    }

    // ====================
    // rehash

    /**
     * Suchwerte verschlüsselter Felder (data_enc.hash) neu berechnen - ohne neuen
     * Schlüssel: aus dem entschlüsselten Klartext mit der aktuellen Normalisierung
     * (Cms::encryptedHash()). Nötig für Daten, die vor der Normalisierung gespeichert
     * wurden (`php Core/cron/camindo.php key rehash`); danach findet die Suche sie
     * auch in anderer Groß-/Kleinschreibung. Zeilen, die sich nicht entschlüsseln
     * lassen, bleiben unverändert.
     *
     * @return int  Anzahl geänderter Zeilen
     */
    public function rehashEncrypted(): int
    {
        $n = 0;
        foreach ($this->rows("SELECT `language_id`, `record_id`, `fieldname_id`, `data`, `hash` FROM `data_enc`") as $row) {
            $plain = $this->crypt->decrypt((string)$row['data'], '', true);
            if ($plain === '' && (string)$row['data'] !== '') continue;
            $hash = $this->cms->encryptedHash($plain);
            if ($hash === (string)$row['hash']) continue;
            $this->db->query(
                "UPDATE `data_enc` SET `hash` = ? WHERE `language_id` = ? AND `record_id` = ? AND `fieldname_id` = ?",
                'siii', [$hash, (int)$row['language_id'], (int)$row['record_id'], (int)$row['fieldname_id']]
            );
            $n++;
        }
        return $n;
    }

    // ====================
    // rotate

    /**
     * Neuen Schlüssel einführen (siehe Klassenkommentar).
     *
     * @param bool   $invalidatePasswords  alle Passwörter sofort ungültig
     * @param bool   $backup               vorher sichern (false nur für Tests)
     * @param callable|null $log           fn(string $line) für Fortschrittsmeldungen
     * @return array  old, new (Schlüssel-Ids), counts je Bereich
     */
    public function rotate(bool $invalidatePasswords = false, bool $backup = true, ?callable $log = null): array
    {
        $log ??= fn(string $line) => null;
        $keyFile = $this->keyFile();
        $last = $this->lastRotation();
        if ($last !== null && time() - $last < self::MIN_INTERVAL) {
            throw new \RuntimeException('key was rotated on ' . gmdate('Y-m-d H:i', $last) . ' UTC - next rotation possible from '
                . gmdate('Y-m-d H:i', $last + self::MIN_INTERVAL) . ' UTC');
        }
        if (!is_writable(dirname($keyFile))) {
            throw new \RuntimeException('directory of ' . $keyFile . ' is not writable');
        }
        if ($backup) {
            $log('backup: ' . $this->backup->beforeChange('key-rotate'));
        }
        $wasMaintenance = $this->systemState->isMaintenance();
        if (!$wasMaintenance) $this->systemState->lock('key rotation');

        $counts = [];
        try {
            $oldId = $this->crypt->currentKeyId();

            // 3. Passwort-Hashes ohne Kennung: mit dem alten Schlüssel gepfeffert
            $counts['passwords tagged'] = $this->tagPasswords($oldId);

            // 4. Schlüsselbund: alter -> .retired, neuer -> key_file
            // (der aktuelle kann aus CAMINDO_KEY stammen - der neue landet in jedem
            // Fall in key_file und hat damit Vorrang, bis die Variable nachgezogen ist)
            $oldKey = $this->crypt->currentKeyForRotation();
            $newKey = base64_encode(random_bytes(32));
            $mode = $this->keyFileMode();
            $this->appendRetired($oldKey);
            $this->writeSecret($keyFile . '.new', $newKey, $mode);
            if (!@rename($keyFile . '.new', $keyFile)) throw new \RuntimeException("cannot replace $keyFile");
            $this->crypt->reloadKeys();
            $newId = $this->crypt->currentKeyId();
            $log("key: $oldId -> $newId");

            // 5. neu verschlüsseln
            $counts += $this->reencryptAll($log);

            // 7. Passwörter öffentlich aufgetaucht: alles Geheime weg
            if ($invalidatePasswords) {
                $counts += $this->invalidatePasswords();
                $this->removeRetired($oldId);
                $this->crypt->reloadKeys();
                $log("old key $oldId removed");
            }

            // 6. Signaturen (löscht alle Admin-Sessions), Caches
            foreach ($this->integrity->signAll() as $table => $count) {
                $counts['signed ' . $table] = $count;
            }
            $this->instanceCache->rebuild();
            @unlink(rtrim((string)$this->config->get('paths.var_dir'), '/') . '/cache/password_dummy.txt');
        }
        finally {
            if (!$wasMaintenance) $this->systemState->unlock();
        }
        $this->system->log('security', "key rotated: {$oldId} -> {$newId}" . ($invalidatePasswords ? ' (passwords invalidated)' : ''));
        return ['old' => $oldId, 'new' => $newId, 'counts' => $counts];
    }

    /**
     * Passwort-Hashes ohne Schlüssel-Kennung mit $kid versehen (admin, data_pwd).
     * admin.password steht in der Signatur - signAll() folgt in rotate().
     */
    private function tagPasswords(string $kid): int
    {
        $count = 0;
        foreach ($this->passwordRows() as $row) {
            [$tag, $hash] = Crypt::splitPasswordHash($row['value']);
            if ($tag !== '') continue;
            $this->setPassword($row, $kid . ':' . $hash);
            $count++;
        }
        return $count;
    }

    /**
     * Alle gespeicherten Passwort-Hashes (admin.password, data_pwd.data) mit ihrem
     * Schlüssel. Einzeln statt per SQL: die Spalten sind latin1, eine Verkettung mit
     * utf8mb4-Parametern scheitert an der Kollation.
     *
     * @return array  Liste aus table, value, key (Spalten => Werte des Primärschlüssels)
     */
    private function passwordRows(): array
    {
        $result = [];
        foreach ($this->rows("SELECT `admin_id`, `password` FROM `admin` WHERE `password` <> ''") as $row) {
            $result[] = ['table' => 'admin', 'value' => (string)$row['password'], 'key' => ['admin_id' => (int)$row['admin_id']]];
        }
        foreach ($this->rows("SELECT `language_id`, `record_id`, `fieldname_id`, `data` FROM `data_pwd` WHERE `data` <> ''") as $row) {
            $result[] = ['table' => 'data_pwd', 'value' => (string)$row['data'], 'key' => [
                'language_id' => (int)$row['language_id'], 'record_id' => (int)$row['record_id'], 'fieldname_id' => (int)$row['fieldname_id']]];
        }
        return $result;
    }

    /** Passwort-Hash einer Zeile aus passwordRows() schreiben */
    private function setPassword(array $row, string $value): void
    {
        $column = $row['table'] === 'admin' ? 'password' : 'data';
        $where = implode(' AND ', array_map(fn($c) => "`$c` = ?", array_keys($row['key'])));
        $this->db->query("UPDATE `{$row['table']}` SET `$column` = ? WHERE $where",
            's' . str_repeat('i', count($row['key'])), array_merge([$value], array_values($row['key'])));
    }

    /**
     * Alles Umkehrbare mit dem aktuellen Schlüssel neu verschlüsseln. Werte, die
     * sich mit keinem Schlüssel entschlüsseln lassen, bleiben unverändert (und
     * erscheinen in status()).
     *
     * @return array  Bereich => Anzahl geänderter Werte
     */
    public function reencryptAll(?callable $log = null): array
    {
        $log ??= fn(string $line) => null;
        $counts = [];

        // data_enc: Geheimtext + Suchhash des Klartexts
        $n = 0;
        foreach ($this->rows("SELECT `language_id`, `record_id`, `fieldname_id`, `data` FROM `data_enc`") as $row) {
            $plain = $this->crypt->decrypt((string)$row['data'], '', true);
            if ($plain === '' && (string)$row['data'] !== '') continue;
            $this->db->query(
                "UPDATE `data_enc` SET `data` = ?, `hash` = ? WHERE `language_id` = ? AND `record_id` = ? AND `fieldname_id` = ?",
                'ssiii', [$this->crypt->encrypt($plain, '', true), $this->cms->encryptedHash($plain),
                    (int)$row['language_id'], (int)$row['record_id'], (int)$row['fieldname_id']]
            );
            $n++;
        }
        $counts['data_enc'] = $n;

        // record_cache: {"feld": ["encrypted", base64(Geheimtext)], …}
        $n = 0;
        foreach ($this->rows("SELECT `record_id`, `language_id`, `cache` FROM `record_cache` WHERE `cache` LIKE '%\"encrypted\"%'") as $row) {
            $cache = json_decode((string)$row['cache'], true);
            if (!is_array($cache)) continue;
            $changed = false;
            foreach ($cache as $name => $entry) {
                if (!is_array($entry) || ($entry[0] ?? '') !== 'encrypted' || !is_string($entry[1] ?? null) || $entry[1] === '') continue;
                $cipher = base64_decode($entry[1], true);
                $plain = $cipher === false ? '' : $this->crypt->decrypt($cipher, '', true);
                if ($plain === '') continue;
                $cache[$name][1] = base64_encode($this->crypt->encrypt($plain, '', true));
                $changed = true;
            }
            if (!$changed) continue;
            $this->db->query("UPDATE `record_cache` SET `cache` = ? WHERE `record_id` = ? AND `language_id` = ?",
                'sii', [json_encode($cache, JSON_UNESCAPED_UNICODE), (int)$row['record_id'], (int)$row['language_id']]);
            $n++;
        }
        $counts['record_cache'] = $n;

        // admin: Kontaktdaten (ohne AAD), TOTP-Secret (AAD totp|{id}, TotpFactor)
        $n = 0;
        foreach ($this->rows("SELECT `admin_id`, `email`, `mobile`, `phone`, `totp_secret` FROM `admin`") as $row) {
            $id = (int)$row['admin_id'];
            $values = [];
            foreach (['email', 'mobile', 'phone'] as $column) {
                $values[$column] = $this->recrypt((string)$row[$column], '', true);
            }
            $values['totp_secret'] = $this->recrypt((string)$row['totp_secret'], 'totp|' . $id, true);
            $this->db->query("UPDATE `admin` SET `email` = ?, `mobile` = ?, `phone` = ?, `totp_secret` = ? WHERE `admin_id` = ?",
                'ssssi', [$values['email'], $values['mobile'], $values['phone'], $values['totp_secret'], $id]);
            $n++;
        }
        $counts['admin'] = $n;

        // user_second_factor: TOTP-Secret der Frontend-User (AAD utotp|{record_id},
        // UserSecondFactor)
        $n = 0;
        foreach ($this->rows("SELECT `record_id`, `totp_secret` FROM `user_second_factor` WHERE `totp_secret` <> ''") as $row) {
            $id = (int)$row['record_id'];
            $this->db->query("UPDATE `user_second_factor` SET `totp_secret` = ? WHERE `record_id` = ?",
                'si', [$this->recrypt((string)$row['totp_secret'], 'utotp|' . $id, true), $id]);
            $n++;
        }
        $counts['user_second_factor'] = $n;

        // message: Text und HTML der internen Nachrichten
        $n = 0;
        foreach ($this->rows("SELECT `message_id`, `body`, `html` FROM `message`") as $row) {
            $this->db->query("UPDATE `message` SET `body` = ?, `html` = ? WHERE `message_id` = ?",
                'ssi', [$this->recrypt((string)$row['body'], '', true), $this->recrypt((string)$row['html'], '', true), (int)$row['message_id']]);
            $n++;
        }
        $counts['message'] = $n;

        // scheduler: HTTP-Auth-Passwort in details (base64)
        $n = 0;
        foreach ($this->rows("SELECT `scheduler_id`, `details` FROM `scheduler` WHERE `details` LIKE '%\"password\"%'") as $row) {
            $details = json_decode((string)$row['details'], true);
            if (!is_array($details) || !is_string($details['password'] ?? null) || $details['password'] === '') continue;
            $details['password'] = $this->recrypt($details['password'], '', false);
            $this->db->query("UPDATE `scheduler` SET `details` = ? WHERE `scheduler_id` = ?",
                'si', [json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int)$row['scheduler_id']]);
            $n++;
        }
        $counts['scheduler'] = $n;

        // Audit-Log (var/audit, AuditStore): verschlüsselte Werte (t = encrypted) in
        // v/b/a neu, HMAC in c neu - und die Kette mit dem neuen Schlüssel neu, nach
        // Prüfung der alten (Kontrollpunkt 'rekey' mit "verified")
        $recryptNode = function (&$node) use (&$recryptNode) {
            if (!is_array($node)) return;
            if (($node['t'] ?? '') === 'encrypted') {
                foreach (['v', 'b', 'a'] as $key) {
                    if (!is_string($node[$key] ?? null) || $node[$key] === '') continue;
                    $cipher = base64_decode($node[$key], true);
                    $plain = $cipher === false ? '' : $this->crypt->decrypt($cipher, '', true);
                    if ($plain === '') continue;
                    $node[$key] = base64_encode($this->crypt->encrypt($plain, '', true));
                    if ($key === 'v' && isset($node['c'])) $node['c'] = bin2hex($this->crypt->hash($plain, true));
                }
                return;
            }
            foreach ($node as &$child) $recryptNode($child);
        };
        $audit = $this->auditStore->rewriteAll(function (array $body) use ($recryptNode) {
            $recryptNode($body['changes']);
            return $body;
        });
        $counts['audit files'] = $audit['files'];
        $counts['audit entries'] = $audit['entries'];
        if ($audit['broken']) {
            $counts['audit files with broken chain before rotation'] = count($audit['broken']);
            $this->system->log('security', 'key rotation: audit chain broken before rotation: ' . implode(', ', $audit['broken']));
        }

        foreach ($counts as $area => $count) $log("re-encrypted $area: $count");
        return $counts;
    }

    /** Einen Wert neu verschlüsseln; nicht entschlüsselbar -> unverändert */
    private function recrypt(string $value, string $aad, bool $binary): string
    {
        if ($value === '') return '';
        $plain = $this->crypt->decrypt($value, $aad, $binary);
        if ($plain === '') return $value;
        return $this->crypt->encrypt($plain, $aad, $binary);
    }

    /**
     * Passwörter öffentlich aufgetaucht: alle Passwörter leer, alle Sessions (Admin
     * und User), Geräte und Wiederherstellungscodes weg.
     */
    private function invalidatePasswords(): array
    {
        $counts = [];
        $this->db->query("UPDATE `admin` SET `password` = '', `password_change` = 0 WHERE `password` <> ''");
        $counts['admin passwords invalidated'] = max(0, $this->db->affected_rows);
        $this->db->query("UPDATE `data_pwd` SET `data` = '' WHERE `data` <> ''");
        $counts['user passwords invalidated'] = max(0, $this->db->affected_rows);
        $this->db->query("DELETE FROM `user_session`");
        $counts['user sessions deleted'] = max(0, $this->db->affected_rows);
        $this->db->query("DELETE FROM `login_device`");
        $counts['devices deleted'] = max(0, $this->db->affected_rows);
        $this->db->query("DELETE FROM `admin_recovery_code`");
        $counts['recovery codes deleted'] = max(0, $this->db->affected_rows);
        $this->db->query("DELETE FROM `user_recovery_code`");
        $counts['user recovery codes deleted'] = max(0, $this->db->affected_rows);
        return $counts;
    }

    // ====================
    // status, retire

    /**
     * Stand des Schlüsselbunds.
     *
     * @return array  current (kid), key_source ('file'|'env'), env_key
     *                (Crypt::envKeyState()), key_file_mode (z. B. '0600', '' ohne Datei),
     *                key_file_mode_config (permissions.key_file), retired (Liste aus
     *                kid, retired_at, grace_until, passwords, recovery_codes), not_current
     *                (Bereich => Anzahl Werte, die NICHT mit dem aktuellen Schlüssel
     *                verschlüsselt sind), passwords (kid|'untagged' => Anzahl)
     */
    public function status(): array
    {
        $keyFile = $this->keyFile();
        $graceDays = max(0, (int)$this->config->get('security.key_password_grace_days'));
        $passwords = [];
        foreach ($this->passwordRows() as $row) {
            [$kid] = Crypt::splitPasswordHash($row['value']);
            $key = $kid === '' ? 'untagged' : $kid;
            $passwords[$key] = ($passwords[$key] ?? 0) + 1;
        }
        $retired = [];
        foreach ($this->crypt->retiredKeys() as $kid => $retiredAt) {
            $recovery = 0;
            if ($retiredAt !== '') {
                foreach (['admin_recovery_code', 'user_recovery_code'] as $table) {
                    $this->db->query("SELECT COUNT(*) FROM `$table` WHERE `created_at` < ?",
                        's', [gmdate('Y-m-d H:i:s', (int)strtotime($retiredAt))]);
                    $recovery += (int)($this->db->fetchNum()[0] ?? 0);
                }
            }
            $retired[] = [
                'kid' => $kid,
                'retired_at' => $retiredAt,
                'grace_until' => ($retiredAt !== '' && $graceDays > 0) ? gmdate('Y-m-d', (int)strtotime($retiredAt) + $graceDays * 86400) : '',
                'passwords' => $passwords[$kid] ?? 0,
                'recovery_codes' => $recovery,
            ];
        }
        $last = $this->lastRotation();
        return [
            'current' => $this->crypt->currentKeyId(),
            'key_source' => $this->crypt->keySource(),
            'env_key' => $this->crypt->envKeyState(),
            'last_rotation' => $last === null ? '' : gmdate('Y-m-d H:i', $last) . ' UTC',
            'next_rotation' => ($last === null || time() - $last >= self::MIN_INTERVAL) ? '' : gmdate('Y-m-d H:i', $last + self::MIN_INTERVAL) . ' UTC',
            'key_file_mode' => is_file($keyFile) ? sprintf('%04o', fileperms($keyFile) & 0777) : '',
            'key_file_mode_config' => sprintf('%04o', $this->configuredMode()),
            'retired' => $retired,
            'not_current' => $this->countNotCurrent(),
            'passwords' => $passwords,
        ];
    }

    /**
     * Verschlüsselte Werte, die (noch) nicht mit dem aktuellen Schlüssel
     * verschlüsselt sind - nach einer abgebrochenen Rotation oder bei Werten, die
     * sich mit keinem Schlüssel mehr entschlüsseln lassen.
     */
    private function countNotCurrent(): array
    {
        $counts = ['data_enc' => 0, 'admin' => 0, 'user_second_factor' => 0, 'message' => 0, 'audit' => 0];
        $current = $this->crypt->currentKeyId();
        foreach ($this->auditStore->objects() as [$type, $objectId]) {
            if (array_diff($this->auditStore->verify($type, $objectId)['keys'], [$current])) $counts['audit']++;
        }
        foreach ($this->rows("SELECT `data` FROM `data_enc`") as $row) {
            if (!$this->crypt->decryptsWithCurrent((string)$row['data'], '', true)) $counts['data_enc']++;
        }
        foreach ($this->rows("SELECT `admin_id`, `email`, `mobile`, `phone`, `totp_secret` FROM `admin`") as $row) {
            foreach (['email', 'mobile', 'phone'] as $column) {
                if (!$this->crypt->decryptsWithCurrent((string)$row[$column], '', true)) $counts['admin']++;
            }
            if (!$this->crypt->decryptsWithCurrent((string)$row['totp_secret'], 'totp|' . (int)$row['admin_id'], true)) $counts['admin']++;
        }
        foreach ($this->rows("SELECT `record_id`, `totp_secret` FROM `user_second_factor` WHERE `totp_secret` <> ''") as $row) {
            if (!$this->crypt->decryptsWithCurrent((string)$row['totp_secret'], 'utotp|' . (int)$row['record_id'], true)) $counts['user_second_factor']++;
        }
        foreach ($this->rows("SELECT `body`, `html` FROM `message`") as $row) {
            if (!$this->crypt->decryptsWithCurrent((string)$row['body'], '', true)) $counts['message']++;
            if (!$this->crypt->decryptsWithCurrent((string)$row['html'], '', true)) $counts['message']++;
        }
        return $counts;
    }

    /**
     * Abgelösten Schlüssel entfernen. Verweigert, solange Verschlüsseltes nicht mit dem
     * aktuellen Schlüssel lesbar ist (erst `key rotate` fertig laufen lassen). Passwörter
     * mit seiner Kennung (seit der Rotation nicht angemeldet) werden nur mit $force
     * ungültig gemacht - Admins brauchen dann `admin password NAME`, User "Passwort
     * vergessen". Wiederherstellungscodes von vor der Rotation gelten danach nicht mehr.
     *
     * @return array  removed (bool), passwords (Anzahl ungültig), error ('' = gut)
     */
    public function retire(string $kid, bool $force = false): array
    {
        $result = ['removed' => false, 'passwords' => 0, 'error' => ''];
        if (!array_key_exists($kid, $this->crypt->retiredKeys())) {
            $result['error'] = "no retired key $kid";
            return $result;
        }
        if (array_sum($this->countNotCurrent()) > 0) {
            $result['error'] = 'encrypted values still depend on retired keys - run `key rotate` again or check `key status`';
            return $result;
        }
        $affected = array_values(array_filter($this->passwordRows(),
            fn($row) => Crypt::splitPasswordHash($row['value'])[0] === $kid));
        $remaining = count($affected);
        if ($remaining > 0 && !$force) {
            $result['error'] = "$remaining password(s) still use key $kid - they become invalid; repeat with --force";
            return $result;
        }
        if ($remaining > 0) {
            foreach ($affected as $row) $this->setPassword($row, '');
            $this->integrity->signAll();
            $result['passwords'] = $remaining;
        }
        $this->removeRetired($kid);
        $this->crypt->reloadKeys();
        $result['removed'] = true;
        $this->system->log('security', "retired key $kid removed" . ($remaining ? " ($remaining passwords invalidated)" : ''));
        return $result;
    }

    // ====================
    // Dateien

    private function keyFile(): string
    {
        return (string)$this->config->get('paths.key_file');
    }

    /**
     * Rechte für key_file und .retired: die der bisherigen key_file (bewusst gesetzte
     * Rechte bleiben erhalten, auch wo 0600 nicht umsetzbar ist), ohne Datei
     * (Schlüssel aus CAMINDO_KEY) permissions.key_file.
     */
    private function keyFileMode(): int
    {
        $keyFile = $this->keyFile();
        if (is_file($keyFile) && ($perms = @fileperms($keyFile)) !== false) return $perms & 0777;
        return $this->configuredMode();
    }

    private function configuredMode(): int
    {
        return (int)($this->config->get('permissions.key_file') ?: 0600) & 0777;
    }

    /** Zeitpunkt der letzten Rotation (Zeile "# rotated …" in .retired), null = nie */
    public function lastRotation(): ?int
    {
        $file = $this->keyFile() . '.retired';
        if (!is_file($file)) return null;
        $last = null;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (preg_match('/^#\s*rotated\s+(\S+)/', $line, $m) && ($time = strtotime($m[1])) !== false) {
                $last = max($last ?? 0, $time);
            }
        }
        return $last;
    }

    private function appendRetired(string $key): void
    {
        $file = $this->keyFile() . '.retired';
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $line = "# rotated $now\n" . Crypt::keyId($key) . ' ' . trim($key) . ' ' . $now . "\n";
        $content = is_file($file) ? (string)file_get_contents($file) : '';
        $this->writeSecret($file, $content . $line, $this->keyFileMode());
    }

    private function removeRetired(string $kid): void
    {
        $file = $this->keyFile() . '.retired';
        if (!is_file($file)) return;
        $lines = array_filter(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
            fn($line) => !str_starts_with(trim($line), $kid . ' '));
        if (!$lines) {
            @unlink($file);
            return;
        }
        $this->writeSecret($file, implode("\n", $lines) . "\n", $this->keyFileMode());
    }

    private function writeSecret(string $file, string $content, int $mode): void
    {
        $old = umask(0077);
        try {
            if (@file_put_contents($file, $content, LOCK_EX) === false) throw new \RuntimeException("cannot write $file");
        }
        finally {
            umask($old);
        }
        @chmod($file, $mode);
    }

    /** Alle Zeilen einer Abfrage (eigener Ergebnisstapel) */
    private function rows(string $sql): array
    {
        $this->db->pushResult();
        try {
            $this->db->query($sql);
            $rows = [];
            while ($row = $this->db->fetch()) $rows[] = $row;
            return $rows;
        }
        finally {
            $this->db->popResult();
        }
    }
}
