<?php
/**
 * Shorten IP addresses after a retention period (GDPR: legitimate interest in the full
 * address lasts at most a few days). run() runs from the scheduler task
 * "Apply retention periods" (type retention, default daily at night).
 *
 * WHAT IS SHORTENED
 * --------------------
 *     audit_log.ip                entries older than privacy.ip_anonymize_days
 *     admin_login_attempt.ip      likewise (the rows usually live only minutes -
 *     user_login_attempt.ip       LoginGuard::purge() -, but shortening is done to be safe)
 *     login_device.ip             always, without a period: new devices only store
 *                                 the shortened IP anyway (LoginDevices::register()); this
 *                                 concerns rows from before the update
 *     var/log/{Y-m-d}_*.log       files whose date is older than the period - every
 *                                 character string that is a valid IP
 * Shortening is done with ClientIp::anonymize(): IPv4 without the last or the last
 * two octets (privacy.ip_v4_octets), IPv6 without the last 80 bits.
 *
 * The contents of the audit log under var/audit have the IP shortened from the start
 * (AuditLog::write()); existing data: `camindo.php audit anonymize-ip`. If
 * privacy.ip_v4_octets is increased (1 -> 2), run() shortens it once more
 * (AuditLog::anonymizeFileIps(), new checkpoint per file).
 *
 * HOW IT STAYS FAST
 * --------------------
 * A high-water mark (var/tmp/ip-anonymize.stamp, JSON) remembers the boundary of the
 * last run; each run only takes the time window since then - via the index of the
 * time column, per day of the window a SELECT DISTINCT of the IPs and per IP to be
 * shortened an UPDATE. There are few different IPs per day, hence few queries; the
 * shortening itself is computed by PHP, so that it gives the same result under MySQL,
 * MariaDB, Linux and Windows as when writing. If the high-water mark is missing (first
 * run, var/tmp emptied) or privacy.ip_v4_octets was changed, the run goes over
 * everything once. Log files are written line by line to a temporary file
 * and only replaced if something has changed - even large files need
 * hardly any memory.
 *
 * If the period is increased (to follow an attack), the high-water mark stays until
 * the new boundary overtakes it - what has been shortened does not come back.
 *
 * @see Camindo\Core\ClientIp::anonymize()
 * @see Camindo\Core\Modules\Scheduler\SchedulerRunner   type retention
 */
namespace Camindo\Core;

class IpAnonymizer {

    /** High-water mark under paths.tmp_dir (*.stamp survives the cleanup of var/tmp, SchedulerRunner::cleanupTmp()) */
    public const STAMP = 'ip-anonymize.stamp';

    /** Tables with a time column whose IPs are shortened after the period */
    private const TABLES = [
        'audit_log'           => 'created_at',
        'admin_login_attempt' => 'attempt_date',
        'user_login_attempt'  => 'attempt_date',
    ];

    /** Earliest conceivable point in time (run without a high-water mark) */
    private const BEGIN = '1000-01-01 00:00:00';

    public function __construct(
        private Config $config,
        private Database $db,
        private ContainerInterface $container,
    )
    {}

    /** privacy.ip_anonymize_days (0 = at the next run) */
    public function days(): int
    {
        return max(0, (int)($this->config->get('privacy.ip_anonymize_days') ?? 7));
    }

    /**
     * Shorten everything that is older than the period.
     *
     * @return array  rows (shortened rows per table), files (rewritten log files),
     *                audit (re-shortened entries under var/audit)
     */
    public function run(): array
    {
        $octets = ClientIp::v4Octets($this->config);
        $days = $this->days();
        $stamp = $this->readStamp();
        $full = ($stamp['octets'] ?? null) !== $octets;

        // Database: UTC like the columns
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $from = $full ? self::BEGIN : (string)($stamp['db'] ?? self::BEGIN);
        $result = ['rows' => [], 'files' => 0];
        foreach (self::TABLES as $table => $column) {
            $result['rows'][$table] = $from < $cutoff ? $this->table($table, $column, $from, $cutoff, $octets) : 0;
        }
        $result['rows']['login_device'] = $this->table('login_device', null, '', '', $octets);

        // Log files: file name in the date of the system time zone (System::log())
        $logCutoff = date('Y-m-d', time() - $days * 86400);
        $logFrom = $full ? '0000-00-00' : (string)($stamp['log'] ?? '0000-00-00');
        if ($logFrom < $logCutoff) $result['files'] = $this->logFiles($logFrom, $logCutoff, $octets);

        // privacy.ip_v4_octets increased: also shorten the contents of the audit log
        // (var/audit) further - they were written with the earlier setting. Lowering it
        // brings nothing back, then the files stay as they are.
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
     * Shorten the IPs of a table in the window [$from, $to); without $column all rows.
     *
     * @return int  changed rows
     */
    private function table(string $table, ?string $column, string $from, string $to, int $octets): int
    {
        $changed = 0;
        $this->db->pushResult();
        try {
            if ($column === null) return $this->window($table, '', [], $octets);

            // Window day by day: the first existing point in time from $from skips gaps
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

    /** Read the distinct IPs of a window and shorten each one with an UPDATE */
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
     * Rewrite log files var/log/{Y-m-d}_*.log with $from <= day < $to.
     *
     * @return int  rewritten files
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

    /** Shorten one log file line by line; true = changed */
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
        // rename() also replaces the target file on Windows
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * Shorten all IP addresses in a text. Candidates are sequences of digits,
     * hex characters, dots and colons; only what filter_var() accepts as an
     * IP is replaced - times such as 12:34:56 remain.
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
