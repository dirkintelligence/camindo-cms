<?php
/**
 * class BackgroundProcess, Instanzname $bgproc
 *
 * Verwaltet langlaufende Kommandos, die abgekoppelt vom Webrequest im Hintergrund laufen.
 * Der eigentliche Start und die Prozessüberwachung liegen in Execute, diese Klasse hält
 * ausschließlich den Zustand in der Tabelle `bgproc` (siehe bgproc.sql).
 *
 * Ablauf:
 *  1. start() erzeugt ein Token (falls nicht als Parameter mitgegeben), startet das Kommando
 *     und schreibt PID + Token in die Datenbank.
 *  2. Der Hintergrundprozess meldet seinen Fortschritt über das Token zurück
 *     (setProgressByToken()), er braucht dafür keine Session.
 *  3. list() gleicht bei jedem Aufruf den gespeicherten mit dem tatsächlichen Zustand ab.
 *
 * Sicherheit: abort() und remove() beenden bzw. löschen ausschließlich PIDs, die auch in
 * `bgproc` stehen. Eine beliebige PID des Systems lässt sich darüber nicht abschießen.
 */
namespace Camindo\Core;

class BackgroundProcess {

    /**
     * Werte der Spalte `status`, identisch zu den STATE_*-Konstanten von Execute
     */
    public const STATUS_FINISHED = 0;
    public const STATUS_RUNNING  = 1;
    public const STATUS_TIMEOUT  = 2;
    public const STATUS_FAILED   = 3;

    /**
     * Prozesstypen, deren Einträge nach dem Ende noch gebraucht werden: der Trigger
     * (TriggerMedia::finalize()) liest das Ergebnis aus und entfernt den Eintrag
     * selbst. Alle anderen beendeten Einträge räumt die Liste im Backend ab
     * (purgeFinishedOfAdmin()) - ein fertiger Prozess interessiert dort nicht mehr.
     */
    public const TRIGGER_TYPES = ['ffmpeg', 'magick'];

    /** File Ressource für Methode lock(), null = keine Sperre gehalten */
    private mixed $triggerLock = null;

    /** Der Browser wurde in diesem Request bereits zur Überwachung aufgefordert */
    private bool $watchScriptSent = false;

    public function __construct(
        private Database $db,
        private Config $config,
        private Crypt $crypt,
        private Session $session,
        private Execute $execute,
        private SecurityHeaders $securityHeaders,
    )
    {
    }

    /**
     * Alle Hintergrundprozesse aus der Datenbank laden inkl. Status und Fortschritt.
     * Für jeden noch nicht beendeten Prozess tatsächlichen Status ermitteln.
     * Wenn beendet dann auch als "beendet" in der Datenbank aktualisieren.
     * @param  int   $adminId Prozesse dieses Admin listen, 0 = alle Admins
     * @return array Datensätze aus `bgproc`, neueste zuerst; leeres Array, wenn keine existieren
     */
    public function list(int $adminId = 0): array
    {
        $this->refresh($adminId);

        if (!empty($adminId)) {
            $this->db->query("SELECT * FROM `bgproc` WHERE `admin_id` = ? ORDER BY `created_at` DESC", 'i', [$adminId]);
        } else {
            $this->db->query("SELECT * FROM `bgproc` ORDER BY `created_at` DESC");
        }

        return $this->db->fetchAll();
    }

    /**
     * Kommando im Hintergrund starten und den Prozess registrieren.
     *
     * @param array       $command    Kommando inklusive aller Argumente
     * @param string      $type       Freie Typkennung, z. B. 'import' oder 'thumbnails'
     * @param string|null $stdoutFile Logdatei für die Ausgabe, null = verwerfen
     * @param string|null $stderrFile Logdatei für die Fehlerausgabe, null = verwerfen
     * @param string|null $error      Grund eines Fehlschlags
     * @return array|null             ['pid' => int, 'token' => string, 'id' => int] oder null
     */
    public function start(array $command, string $type, ?string $token = null, ?string $stdoutFile = null, ?string $stderrFile = null, ?string &$error = null): ?array
    {
        $error = '';
        if ($token === null) $token = $this->crypt->randomKey(16);

        $pid = $this->execute->executeBackground($command, $stdoutFile, $stderrFile, $error);

        if ($pid <= 0) {
            return null;
        }

        if (!$this->create($pid, $type, $token)) {
            // Datenbankeintrag fehlgeschlagen: der Prozess wäre sonst unbeaufsichtigt
            $this->execute->stopProcess($pid, 2);
            $error = 'process could not be registered';
            return null;
        }

        return [
            'id'    => (int)$this->db->insert_id,
            'pid'   => $pid,
            'token' => $token,
        ];
    }

    /**
     * Neuen Prozess in die Datenbank eintragen
     * @param  int         $pid   PID des laufenden Prozesses, muss > 0 sein
     * @param  string      $type  Freie Typkennung
     * @param  string|null $token Vorgegebenes Token, null = neues Token erzeugen
     * @return bool        true, wenn der Datensatz angelegt wurde
     */
    public function create(int $pid, string $type, ?string $token = null): bool
    {
        if ($pid <= 0) return false;

        $sessionAdminId = (int)$this->session->get('admin_id');
        $token ??= $this->crypt->randomKey(16);

        $this->db->query(
            "INSERT INTO `bgproc` (`token`, `type`, `admin_id`, `pid`, `status`, `progress`, `created_at`, `updated_at`)
             VALUES (?, ?, ?, ?, ?, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            'ssiii',
            [$token, $type, $sessionAdminId, $pid, $this::STATUS_RUNNING],
            true
        );
        if ($this->db->affected_rows !== 1) return false;

        // Backend-Request: den Browser zur Überwachung auffordern (cApp.polling
        // fragt den Stand der Prozesse nur ab, solange einer läuft). Das Script geht
        // mit der Antwort dieses Requests zurück, einmal je Request genügt.
        if (!$this->watchScriptSent && defined('CAMINDO_BACKEND') && PHP_SAPI !== 'cli') {
            $this->watchScriptSent = true;
            echo '<script' . $this->securityHeaders->nonceAttr() . ">if (window.cApp && cApp.polling) cApp.polling.watchBgproc();</script>\n";
        }
        return true;
    }

    /**
     * Bricht einen laufenden Prozess ab und entfernt seinen Datensatz.
     *
     * Nur PIDs, die in `bgproc` stehen, werden angefasst.
     *
     * @param  string $token    Token des abzubrechenden Prozesses
     * @return bool             true, wenn der Datensatz entfernt wurde; false, wenn die PID unbekannt ist
     */
    public function abort(string $token): bool
    {
        $row = $this->getByToken($token);
        if ($row === null) return false;
        $pid = (int)$row['pid'];

        if ((int)$row['status'] !== self::STATUS_FINISHED) {
            // Erst SIGTERM, damit der Prozess aufräumen kann, danach SIGKILL
            $this->execute->stopProcess($pid, 3, (string)$row['token']);
        }

        return $this->deleteById((int)$row['id']);
    }

    /**
     * Regulär beendeten Prozess aus der Datenbank entfernen
     * @param  string $token    Token des zu entfernenden Prozesses
     * @return bool             true, wenn der Datensatz entfernt wurde; false, wenn die PID unbekannt ist oder der Prozess noch läuft
     */
    public function remove(string $token): bool
    {
        $row = $this->getByToken($token);
        if ($row === null) return false;
        $pid = (int)$row['pid'];

        // Läuft der Prozess wider Erwarten noch, bleibt der Eintrag bestehen.
        // Zum Beenden ist abort() zuständig.
        if ($this->execute->processState($pid, (string)$row['token']) === Execute::STATE_RUNNING) {
            return false;
        }
        
        return $this->deleteById((int)$row['id']);
    }

    /**
     * Prozess-Fortschritt aktualiseren
     *
     * @param  string      $token       Token aus dem Startargument
     * @param  int         $progress    Fortschritt in Prozent, 0-100
     * @param  string|null $message     null: keine Änderung, der JSON-String
     * @return bool                     true, wenn ein Datensatz aktualisiert wurde
     */
    public function setProgressByToken(string $token, int $progress, ?string $message = null): bool
    {
        if ($token === '') return false;
        // progress: min 0, max 100
        $progress = max(0, min(100, $progress));

        $sqlCols = "`progress` = ?, `updated_at` = UTC_TIMESTAMP()";
        $sqlTypes = 'i';
        $sqlValues = [$progress];
        
        // message
        if ($message !== null) {
            $sqlCols .= ', `message` = ?';
            $sqlTypes .= 's';
            $sqlValues[] = $message;
        }
        $sql = "UPDATE `bgproc` SET $sqlCols WHERE `token` = ?";
        $sqlTypes .= 's';
        $sqlValues[] = $token;
        $this->db->query($sql, $sqlTypes, $sqlValues, true);

        return $this->db->affected_rows > 0;
    }

    /**
     * Prozess-Status aktualiseren
     *
     * @param  string $token    Token aus dem Startargument
     * @param  int $status      Status 0: beendet, 1: laufend
     * @return bool             true, wenn ein Datensatz aktualisiert wurde
     */
    public function setStatusByToken(string $token, int $status): bool
    {
        if ($token === '') return false;
        // Bei Status 0 ("completed") den Fortschritt auf 100% setzen
        $sqlProgress = '';
        if ($status === 0) {
            $sqlProgress = '`progress` = 100, `finished_at` = UTC_TIMESTAMP(), ';
        }
        $sql = "UPDATE `bgproc` SET $sqlProgress `status` = ?, `updated_at` = UTC_TIMESTAMP() WHERE `token` = ?";
        $this->db->query($sql, 'is', [$status, $token], true);
        return $this->db->affected_rows > 0;
    }

    /**
     * Prozess-Message aktualiseren
     *
     * @param  string $token    Token aus dem Startargument
     * @param  string $message  JSON-String
     * @return bool             true, wenn ein Datensatz aktualisiert wurde
     */
    public function setMessageByToken(string $token, string $message): bool
    {
        if ($token === '') return false;
        $sql = "UPDATE `bgproc` SET `message` = ?, `updated_at` = UTC_TIMESTAMP() WHERE `token` = ?";
        $this->db->query($sql, 'ss', [$message, $token], true);
        return $this->db->affected_rows > 0;
    }


   /**
     * Holt einen einzelnen Datensatz anhand der PID.
     *
     * @param  int        $pid PID des Prozesses
     * @return array|null      Datensatz aus `bgproc` oder null, wenn die PID unbekannt ist
     */
    public function get(int $pid): ?array
    {
        if ($pid <= 0) return null;

        $this->db->query("SELECT * FROM `bgproc` WHERE `pid` = ? ORDER BY `id` DESC LIMIT 1", 'i', [$pid], true);
        $row = $this->db->fetch();

        return $row ?: null;
    }

    /**
     * Holt einen einzelnen Datensatz anhand des Tokens.
     *
     * @param  string     $token Token des Prozesses
     * @return array|null        Datensatz aus `bgproc` oder null, wenn das Token unbekannt ist
     */
    public function getByToken(string $token): ?array
    {
        if ($token === '') return null;

        $this->db->query("SELECT * FROM `bgproc` WHERE `token` = ? LIMIT 1", 's', [$token], true);
        $row = $this->db->fetch();

        return $row ?: null;
    }

    /**
     * Räumt beendete Einträge auf, deren letzte Änderung länger zurückliegt.
     *
     * Laufende und als Zombie markierte Prozesse bleiben in jedem Fall erhalten.
     * Sinnvoll per Cronjob oder beim Öffnen der Übersicht, damit die Tabelle nicht unbegrenzt wächst.
     *
     * @param  int $hours Mindestalter in Stunden, minimal 1
     * @return int        Anzahl der gelöschten Einträge
     */
    public function purgeFinished(int $hours = 24): int
    {
        $this->db->query(
            "DELETE FROM `bgproc` WHERE `status` = 0 AND `updated_at` < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? HOUR)",
            'i',
            [max(1, $hours)],
            true
        );

        return $this->db->affected_rows;
    }

    /**
     * Regulär beendete Einträge eines Admins entfernen - außer denen, die ein Trigger
     * noch finalisieren muss (TRIGGER_TYPES). Läuft vor jeder Listenausgabe im Backend.
     *
     * @return int  Anzahl der gelöschten Einträge
     */
    public function purgeFinishedOfAdmin(int $adminId): int
    {
        if ($adminId < 1) return 0;
        $placeholders = implode(',', array_fill(0, count(self::TRIGGER_TYPES), '?'));
        $this->db->query(
            "DELETE FROM `bgproc` WHERE `status` = ? AND `admin_id` = ? AND `type` NOT IN ($placeholders)",
            'ii' . str_repeat('s', count(self::TRIGGER_TYPES)),
            array_merge([self::STATUS_FINISHED, $adminId], self::TRIGGER_TYPES),
            true
        );
        return $this->db->affected_rows;
    }

    /**
     * Gleicht den gespeicherten Status mit dem tatsächlichen Zustand der Prozesse ab.
     *
     * Alle noch nicht als beendet markierten Einträge werden dafür mit einem einzigen
     * Aufruf von ps bzw. tasklist geprüft, die Kosten sind also unabhängig von der Anzahl
     * der Prozesse. Geschrieben wird nur, wenn sich der Status tatsächlich geändert hat.
     *
     * Liefert Execute STATE_UNKNOWN, ist der Zustand nicht ermittelbar (weder ps/tasklist
     * noch posix_kill verfügbar). Der Eintrag bleibt dann unverändert, sonst würde ein
     * laufender Prozess fälschlich als beendet angezeigt.
     *
     * Achtung: PIDs werden wiederverwendet. Ein Prozess gilt hier als laufend, solange es
     * irgendeinen Prozess mit dieser PID gibt.
     *
     * @param  int  $adminId Nur Prozesse dieses Admin prüfen, 0 = alle Admins
     * @return void
     */
    private function refresh(int $adminId = 0): void
    {
        
        // Nur LAUFENDE Einträge abgleichen. Ein als fehlgeschlagen oder abgelaufen
        // eingetragener Prozess (Trigger, mass_delete, Scheduler) bleibt es - sein
        // Prozess ist ohnehin weg, und aus "weg" darf hier nicht "erfolgreich
        // beendet" werden. (STATUS_TIMEOUT und Execute::STATE_ZOMBIE teilen sich
        // den Wert 2 - ein Zombie gilt unten deshalb als noch laufend, bis er
        // eingesammelt ist.)
        if (!empty($adminId)) {
            $this->db->query("SELECT `id`, `pid`, `status`, `token` FROM `bgproc` WHERE `status` = ? AND `admin_id` = ?", 'ii', [self::STATUS_RUNNING, $adminId]);
        } else {
            $this->db->query("SELECT `id`, `pid`, `status`, `token` FROM `bgproc` WHERE `status` = ?", 'i', [self::STATUS_RUNNING]);
        }

        // Erst vollständig einlesen, danach schreiben: die folgenden UPDATEs
        // überschreiben das aktuelle Result der Datenbankklasse.
        $rows = $this->db->fetchAll();
        if (empty($rows)) return;

        $states = $this->execute->processStates(
            array_column($rows, 'pid'),
            array_column($rows, 'token', 'pid')
        );

        foreach ($rows as $row) {
            $pid   = (int)$row['pid'];
            $state = $states[$pid] ?? Execute::STATE_UNKNOWN;

            // Zustand nicht ermittelbar: Eintrag unverändert lassen, sonst würde
            // ein laufender Prozess fälschlich als beendet angezeigt.
            if ($state === Execute::STATE_UNKNOWN) continue;
            if ($state === Execute::STATE_ZOMBIE) continue; // noch nicht eingesammelt
            if ($state === (int)$row['status']) continue;

            if ($state === Execute::STATE_FINISHED) {
                $this->db->query(
                    "UPDATE `bgproc` SET `status` = ?, `finished_at` = IFNULL(`finished_at`, UTC_TIMESTAMP()), `updated_at` = UTC_TIMESTAMP() WHERE `id` = ?",
                    'ii',
                    [self::STATUS_FINISHED, (int)$row['id']],
                    true
                );
                continue;
            }

            $this->db->query(
                "UPDATE `bgproc` SET `status` = ?, `updated_at` = UTC_TIMESTAMP() WHERE `id` = ?",
                'ii',
                [$state, (int)$row['id']],
                true
            );
        }
    }

    /**
     * Löscht einen Datensatz anhand des Primärschlüssels.
     *
     * Bewusst über `id` statt über `pid`: bei wiederverwendeten PIDs würde sonst der
     * Datensatz eines anderen Prozesses mitgelöscht.
     *
     * @param  int  $id Primärschlüssel aus `bgproc`
     * @return bool     true, wenn genau ein Datensatz gelöscht wurde
     */
    private function deleteById(int $id): bool
    {
        if ($id <= 0) return false;

        $this->db->query("DELETE FROM `bgproc` WHERE `id` = ?", 'i', [$id], true);

        return $this->db->affected_rows === 1;
    }


    /**
     * Erzeugt Lock, damit Prozesse vom selben Typ nicht parallel gestartet werden.
     * Liefert false, wenn Prozess bereits läuft, true wenn neues Lock erzeugt wurde.
     * Garbage collection hebt Lock auf, oder auch unlock()
     * 
     * @param string $type  Zur Unterscheidung parallel laufender Prozesse
     */
    public function lock(string $type): bool
    {
        $tmpDir = $this->config->get('paths.tmp_dir');
        $lockFile = $tmpDir . DIRECTORY_SEPARATOR . $type . '-trigger.lock';
        $f = @fopen($lockFile, 'c');
        if ($f === false) return false;

        // Versuchen, ein exklusives, nicht-blockierendes Lock zu bekommen
        if (!flock($f, LOCK_EX | LOCK_NB)) {
            fclose($f);
            return false;
        }
        $this->triggerLock = $f;
        return true;
    }

    /**
     * siehe lock()
     */
    public function unlock(): void
    {
        if (!is_resource($this->triggerLock)) return;

        flock($this->triggerLock, LOCK_UN);
        fclose($this->triggerLock);
        $this->triggerLock = null;
    }

}
