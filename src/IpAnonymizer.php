<?php
/**
 * IP-Adressen nach Frist kürzen (DSGVO: berechtigtes Interesse an der vollen
 * Adresse höchstens einige Tage). run() läuft aus der Zeitplaner-Aufgabe
 * "Aufbewahrungsfristen anwenden" (Typ retention, Vorgabe täglich nachts).
 *
 * WAS GEKÜRZT WIRD
 * --------------------
 *     audit_log.ip                Einträge älter als privacy.ip_anonymize_days
 *     admin_login_attempt.ip      dito (die Zeilen leben meist nur Minuten -
 *     user_login_attempt.ip       LoginGuard::purge() -, gekürzt wird zur Sicherheit)
 *     login_device.ip             immer, ohne Frist: neue Geräte speichern ohnehin
 *                                 nur die gekürzte IP (LoginDevices::register()), hier
 *                                 geht es um Zeilen von vor dem Update
 *     var/log/{Y-m-d}_*.log       Dateien, deren Tag älter ist als die Frist - jede
 *                                 Zeichenfolge, die eine gültige IP ist
 * Gekürzt wird mit ClientIp::anonymize(): IPv4 ohne das letzte bzw. die letzten
 * zwei Oktette (privacy.ip_v4_octets), IPv6 ohne die letzten 80 Bit.
 *
 * Die Inhalte des Audit-Logs unter var/audit tragen die IP von Beginn an gekürzt
 * (AuditLog::write()); Altbestand: `camindo.php audit anonymize-ip`. Wird
 * privacy.ip_v4_octets erhöht (1 -> 2), kürzt run() sie einmal nach
 * (AuditLog::anonymizeFileIps(), neuer Kontrollpunkt je Datei).
 *
 * WIE ES SCHNELL BLEIBT
 * --------------------
 * Ein Wasserstand (var/tmp/ip-anonymize.stamp, JSON) merkt sich die Grenze des
 * letzten Laufs; jeder Lauf nimmt nur das Zeitfenster seitdem - über den Index der
 * Zeitspalte, je Tag des Fensters ein SELECT DISTINCT der IPs und je zu kürzender
 * IP ein UPDATE. Es gibt wenige verschiedene IPs je Tag, also wenige Abfragen; das
 * Kürzen selbst rechnet PHP, damit es unter MySQL, MariaDB, Linux und Windows
 * dasselbe Ergebnis gibt wie beim Schreiben. Fehlt der Wasserstand (erster Lauf,
 * var/tmp geleert) oder wurde privacy.ip_v4_octets geändert, geht der Lauf einmal
 * über alles. Logdateien werden zeilenweise in eine temporäre Datei geschrieben
 * und nur ersetzt, wenn sich etwas geändert hat - auch große Dateien brauchen
 * kaum Speicher.
 *
 * Wird die Frist erhöht (Angriff verfolgen), bleibt der Wasserstand stehen, bis
 * die neue Grenze ihn überholt - Gekürztes kommt nicht zurück.
 *
 * @see Camindo\Core\ClientIp::anonymize()
 * @see Camindo\Core\Modules\Scheduler\SchedulerRunner   Typ retention
 */
namespace Camindo\Core;

class IpAnonymizer {

    /** Wasserstand unter paths.tmp_dir (*.stamp überlebt das Aufräumen von var/tmp, SchedulerRunner::cleanupTmp()) */
    public const STAMP = 'ip-anonymize.stamp';

    /** Tabellen mit Zeitspalte, deren IPs nach der Frist gekürzt werden */
    private const TABLES = [
        'audit_log'           => 'created_at',
        'admin_login_attempt' => 'attempt_date',
        'user_login_attempt'  => 'attempt_date',
    ];

    /** Ältester denkbarer Zeitpunkt (Lauf ohne Wasserstand) */
    private const BEGIN = '1000-01-01 00:00:00';

    public function __construct(
        private Config $config,
        private Database $db,
        private ContainerInterface $container,
    )
    {}

    /** privacy.ip_anonymize_days (0 = beim nächsten Lauf) */
    public function days(): int
    {
        return max(0, (int)($this->config->get('privacy.ip_anonymize_days') ?? 7));
    }

    /**
     * Alles kürzen, was über der Frist liegt.
     *
     * @return array  rows (gekürzte Zeilen je Tabelle), files (umgeschriebene Logdateien),
     *                audit (nachgekürzte Einträge unter var/audit)
     */
    public function run(): array
    {
        $octets = ClientIp::v4Octets($this->config);
        $days = $this->days();
        $stamp = $this->readStamp();
        $full = ($stamp['octets'] ?? null) !== $octets;

        // Datenbank: UTC wie die Spalten
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $from = $full ? self::BEGIN : (string)($stamp['db'] ?? self::BEGIN);
        $result = ['rows' => [], 'files' => 0];
        foreach (self::TABLES as $table => $column) {
            $result['rows'][$table] = $from < $cutoff ? $this->table($table, $column, $from, $cutoff, $octets) : 0;
        }
        $result['rows']['login_device'] = $this->table('login_device', null, '', '', $octets);

        // Logdateien: Dateiname im Datum der Systemzeitzone (System::log())
        $logCutoff = date('Y-m-d', time() - $days * 86400);
        $logFrom = $full ? '0000-00-00' : (string)($stamp['log'] ?? '0000-00-00');
        if ($logFrom < $logCutoff) $result['files'] = $this->logFiles($logFrom, $logCutoff, $octets);

        // privacy.ip_v4_octets erhöht: auch die Inhalte des Audit-Logs (var/audit) weiter
        // kürzen - sie wurden mit der früheren Einstellung geschrieben. Gesenkt bringt
        // nichts zurück, dann bleibt es beim Stand der Dateien.
        $result['audit'] = 0;
        if (isset($stamp['octets']) && $octets > (int)$stamp['octets']) {
            $result['audit'] = (int)$this->container->get(AuditLog::class)->anonymizeFileIps()['entries'];
        }

        $this->writeStamp([
            'octets' => $octets,
            'db'  => max($cutoff, $full ? '' : (string)($stamp['db'] ?? '')),
            'log' => max($logCutoff, $full ? '' : (string)($stamp['log'] ?? '')),
        ]);
        return $result;
    }

    /**
     * IPs einer Tabelle im Fenster [$from, $to) kürzen; ohne $column alle Zeilen.
     *
     * @return int  geänderte Zeilen
     */
    private function table(string $table, ?string $column, string $from, string $to, int $octets): int
    {
        $changed = 0;
        $this->db->pushResult();
        try {
            if ($column === null) return $this->window($table, '', [], $octets);

            // Fenster tageweise: der erste vorhandene Zeitpunkt ab $from überspringt Lücken
            while ($from < $to) {
                $this->db->query("SELECT MIN(`$column`) FROM `$table` WHERE `$column` >= ? AND `$column` < ? AND `ip` <> ''",
                    'ss', [$from, $to]);
                $first = $this->db->fetchNum()[0] ?? null;
                if ($first === null) break;
                $from = substr((string)$first, 0, 19);
                $until = min($to, gmdate('Y-m-d H:i:s', (int)strtotime($from . ' UTC') + 86400));
                $changed += $this->window($table, " AND `$column` >= ? AND `$column` < ?", [$from, $until], $octets);
                $from = $until;
            }
        }
        finally {
            $this->db->popResult();
        }
        return $changed;
    }

    /** Die verschiedenen IPs eines Fensters lesen und je IP mit einem UPDATE kürzen */
    private function window(string $table, string $where, array $values, int $octets): int
    {
        $types = str_repeat('s', count($values));
        $this->db->query("SELECT DISTINCT `ip` FROM `$table` WHERE `ip` <> ''" . $where, $types, $values);
        $ips = [];
        while ($row = $this->db->fetchNum()) $ips[] = (string)$row[0];
        $changed = 0;
        foreach ($ips as $ip) {
            $short = ClientIp::anonymize($ip, $octets);
            if ($short === $ip) continue;
            $this->db->query("UPDATE `$table` SET `ip` = ? WHERE `ip` = ?" . $where, 'ss' . $types, array_merge([$short, $ip], $values));
            $changed += max(0, (int)$this->db->affected_rows);
        }
        return $changed;
    }

    /**
     * Logdateien var/log/{Y-m-d}_*.log mit $from <= Tag < $to umschreiben.
     *
     * @return int  umgeschriebene Dateien
     */
    private function logFiles(string $from, string $to, int $octets): int
    {
        $dir = rtrim((string)$this->config->get('paths.var_dir'), '/\\') . '/log';
        $count = 0;
        foreach (glob($dir . '/*.log') ?: [] as $path) {
            if (!preg_match('/^(\d{4}-\d{2}-\d{2})_.+\.log$/', basename($path), $m)) continue;
            if ($m[1] < $from || $m[1] >= $to) continue;
            if ($this->logFile($path, $octets)) $count++;
        }
        return $count;
    }

    /** Eine Logdatei zeilenweise kürzen; true = geändert */
    private function logFile(string $path, int $octets): bool
    {
        $in = @fopen($path, 'rb');
        if ($in === false) return false;
        $tmp = $path . '.anon';
        $out = @fopen($tmp, 'wb');
        if ($out === false) {
            fclose($in);
            return false;
        }
        $changed = false;
        while (($line = fgets($in)) !== false) {
            $new = self::anonymizeText($line, $octets);
            if ($new !== $line) $changed = true;
            fwrite($out, $new);
        }
        fclose($in);
        fclose($out);
        if (!$changed) {
            @unlink($tmp);
            return false;
        }
        @chmod($tmp, (int)($this->config->get('permissions.file') ?: 0664));
        // rename() ersetzt die Zieldatei auch unter Windows
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * Alle IP-Adressen in einem Text kürzen. Kandidaten sind Folgen aus Ziffern,
     * Hex-Zeichen, Punkten und Doppelpunkten; ersetzt wird nur, was filter_var() als
     * IP anerkennt - Uhrzeiten wie 12:34:56 bleiben stehen.
     */
    public static function anonymizeText(string $text, int $octets = 1): string
    {
        if (!preg_match('/\d\.\d|:/', $text)) return $text;
        return (string)preg_replace_callback(
            '/(?<![0-9A-Za-z.:])(?:[0-9A-Fa-f]{0,4}:){2,7}(?:\d{1,3}(?:\.\d{1,3}){3}|[0-9A-Fa-f]{0,4})(?![0-9A-Za-z:]|\.\d)'
            . '|(?<![0-9A-Za-z.])\d{1,3}(?:\.\d{1,3}){3}(?![0-9A-Za-z]|\.\d)/',
            function (array $m) use ($octets) {
                if (filter_var($m[0], FILTER_VALIDATE_IP) === false) return $m[0];
                return ClientIp::anonymize($m[0], $octets);
            },
            $text
        );
    }

    private function stampFile(): string
    {
        return rtrim((string)$this->config->get('paths.tmp_dir'), '/\\') . '/' . self::STAMP;
    }

    private function readStamp(): array
    {
        $file = $this->stampFile();
        if (!is_file($file)) return [];
        $data = json_decode((string)@file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private function writeStamp(array $data): void
    {
        @file_put_contents($this->stampFile(), (string)json_encode($data), LOCK_EX);
    }
}
