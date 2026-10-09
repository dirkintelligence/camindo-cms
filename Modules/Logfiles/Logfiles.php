<?php
/**
 * Die Logdateien unter var/log lesen, zerlegen und einordnen - ohne Oberfläche.
 * Instanzname im Code: $logfiles.
 *
 * KEIN DATASET, KEINE DATENBANK
 * --------------------
 * Die Ablage sind die Dateien, die System::log() und das PHP-eigene Fehlerlog
 * schreiben: var/log/{Y-m-d}_{name}.log, eine Datei je Tag und Zweck. Geschrieben
 * wird hier nichts - das Modul liest nur.
 *
 * EINE ZEILE IST NICHT IMMER EIN EINTRAG
 * --------------------
 * Drei Zeilenformate kommen im Bestand vor:
 *
 *     [14:22:07] text                              System::log()
 *     [22-Sep-2026 00:18:23 Europe/Berlin] text    PHP-Fehlerlog (Config)
 *     2026-09-21 17:45:47 text                     Upgrade-Protokoll
 *
 * Alles, was mit keinem dieser Zeitstempel beginnt, gehört zum Eintrag davor -
 * typischerweise der Stacktrace einer Exception. entries() fügt solche Zeilen
 * deshalb an den laufenden Eintrag an, statt sie einzeln zu zeigen; sonst zerfällt
 * ein Fatal Error in zwanzig sinnlose Zeilen.
 *
 * KATEGORIE UND DRINGLICHKEIT
 * --------------------
 * classify() ordnet jeden Eintrag einer Kategorie zu (CATEGORIES) - aus dem
 * Dateinamen und, wo der nicht reicht, aus dem Text. Die Reihenfolge in CATEGORIES
 * ist die Rangfolge: was oben steht, zeigt das Widget zuerst. Sie folgt dem, was
 * ein Betreiber zuerst wissen will - gesperrte Anmeldungen, nicht zugestellte
 * E-Mails, abgestürzte Requests, Datenbankfehler - und nicht der Häufigkeit.
 *
 * GROSSE DATEIEN
 * --------------------
 * Ein durchgelaufenes Fehlerlog kann sehr groß werden. Gelesen wird deshalb immer
 * nur das ENDE einer Datei (MAX_BYTES) - das Neueste steht hinten - und die erste,
 * womöglich angeschnittene Zeile fliegt weg. truncated() sagt, ob das passiert ist.
 *
 * DIE DATEINAMEN KOMMEN AUS DEM VERZEICHNIS, NICHT AUS DEM REQUEST
 * --------------------
 * files() liefert die vorhandenen Dateien; alles, was von außen kommt (Name, Datum),
 * wird gegen diese Liste geprüft, bevor es einen Pfad ergibt. Zusammengesetzt wird
 * ein Pfad nur hier, aus geprüften Bestandteilen.
 *
 * @see Camindo\Core\System::log()                            schreibt die Dateien
 * @see Camindo\Core\Modules\Logfiles\LogfilesBackend         Oberfläche und Widget
 */
namespace Camindo\Core\Modules\Logfiles;

use Camindo\Core\Config;

class Logfiles {

    /** Je Datei wird höchstens dieses Ende gelesen (siehe Klassenkommentar) */
    public const MAX_BYTES = 2097152;   // 2 MB

    /** Obergrenze der Einträge je Aufruf von entries() */
    public const MAX_ENTRIES = 500;

    /** Einträge je Seite der Recherche (LogfilesBackend::list(), blätterbar) */
    public const PAGE_SIZE = 100;

    /** Zeitraum des Widgets in Tagen */
    public const SUMMARY_DAYS = 7;

    /**
     * Kategorien in ihrer Rangfolge (siehe Klassenkommentar), je mit der Stufe für
     * die farbliche Darstellung.
     */
    public const CATEGORIES = [
        'login'    => 'danger',    // gesperrte Anmeldungen (LoginGuard)
        'security' => 'danger',    // manipulierte Signaturen u. Ä. (Integrity)
        'mail'     => 'warning',   // nicht versendete E-Mails (Email)
        'php'      => 'danger',    // Fatal Error, Uncaught, Parse Error
        'database' => 'danger',    // SQL-Fehler
        'job'      => 'warning',   // Hintergrundprozesse: *-errors
        'warning'  => 'warning',   // PHP-Warnungen und -Notices
        'info'     => 'secondary', // alles Übrige (scheduler, upgrade, trash, sql …)
    ];

    /** Logdateien, deren Einträge grundsätzlich 'job' sind */
    private const JOB_LOGS = [
        'batch-errors', 'cron-errors', 'export-errors', 'file-errors', 'import-errors',
        'massdelete-errors', 'thumbnails-errors', 'widget-errors', 'template-sync',
    ];

    /** Beim letzten read() wurde am Anfang abgeschnitten */
    private bool $truncated = false;

    public function __construct(
        private Config $config,
    )
    {}

    // ==== Dateien ====

    /**
     * Vorhandene Logdateien, die neueste zuerst.
     *
     * @return array  ['2026-09-22_php_errors.log' => ['name' => 'php_errors',
     *                'date' => '2026-09-22', 'size' => int], …]
     */
    public function files(): array
    {
        $list = [];
        foreach ((array)glob($this->dir() . '/*.log') as $path) {
            $file = basename((string)$path);
            if (!preg_match('/^(\d{4}-\d{2}-\d{2})_(.+)\.log$/', $file, $m)) continue;
            $list[$file] = [
                'name' => $m[2],
                'date' => $m[1],
                'size' => (int)filesize((string)$path),
            ];
        }
        // Neueste zuerst; bei gleichem Tag nach Name
        uasort($list, fn(array $a, array $b): int => [$b['date'], $a['name']] <=> [$a['date'], $b['name']]);
        return $list;
    }

    /**
     * Vorkommende Logbezeichnungen mit der Anzahl ihrer Dateien.
     *
     * @return array  'php_errors' => 5, …  (alphabetisch)
     */
    public function names(): array
    {
        $names = [];
        foreach ($this->files() as $file) {
            $names[$file['name']] = ($names[$file['name']] ?? 0) + 1;
        }
        ksort($names);
        return $names;
    }

    /**
     * Vorkommende Tage, der neueste zuerst.
     *
     * @return array  ['2026-09-22', …]
     */
    public function dates(): array
    {
        $dates = [];
        foreach ($this->files() as $file) $dates[$file['date']] = true;
        $dates = array_keys($dates);
        rsort($dates);
        return $dates;
    }

    // ==== Lesen ====

    /**
     * Einträge lesen und filtern, die jüngsten zuerst.
     *
     * Gelesen wird Datei für Datei in der Reihenfolge von files() (Tag absteigend)
     * und abgebrochen, sobald $limit erreicht ist - bei mehreren Protokollen eines
     * Tages ist die Reihenfolge innerhalb des Tages also die der Dateinamen, nicht
     * die der Uhrzeiten. Wer eine Zeitleiste braucht, schränkt auf ein Protokoll ein.
     *
     * @param string $name    Logbezeichnung, '' = alle
     * @param string $date    Tag (Y-m-d), '' = alle
     * @param string $search  Suchtext (Teilstring, ohne Beachtung der Groß-/Kleinschreibung)
     * @param string $category Kategorie aus CATEGORIES, '' = alle
     * @param int    $limit   Höchstzahl der Einträge
     * @param int    $skip    so viele passende Einträge überspringen (Blättern)
     * @return array  ['file', 'name', 'date', 'time', 'text', 'category'] je Eintrag
     */
    public function entries(string $name = '', string $date = '', string $search = '', string $category = '', int $limit = self::MAX_ENTRIES, int $skip = 0): array
    {
        $this->truncated = false;
        $limit = max(1, min($limit, self::MAX_ENTRIES));
        $search = trim($search);

        $entries = [];
        // files() ist bereits nach Datum absteigend sortiert: die jüngsten Dateien
        // zuerst lesen und aufhören, sobald genug beisammen ist
        foreach ($this->files() as $file => $info) {
            if ($name !== '' && $info['name'] !== $name) continue;
            if ($date !== '' && $info['date'] !== $date) continue;

            $fileEntries = $this->parse($file, $info);
            // Innerhalb einer Datei steht das Neueste unten
            $fileEntries = array_reverse($fileEntries);
            foreach ($fileEntries as $entry) {
                if ($category !== '' && $entry['category'] !== $category) continue;
                if ($search !== '' && stripos($entry['text'], $search) === false) continue;
                if ($skip > 0) {
                    $skip--;
                    continue;
                }
                $entries[] = $entry;
                if (count($entries) >= $limit) return $entries;
            }
        }
        return $entries;
    }

    /**
     * Zusammenfassung für das Widget: je Kategorie die Anzahl der Einträge der
     * letzten $days Tage und der jüngste Eintrag als Beispiel.
     *
     * Die Rangfolge ist die von CATEGORIES, nicht die Häufigkeit (Klassenkommentar).
     * 'info' bleibt draußen: das sind die Zeilen, die im Normalbetrieb ohnehin
     * anfallen (jeder erfolgreiche Cron-Lauf), und ein paar hundert davon würden
     * die drei Meldungen zudecken, auf die es ankommt. In der Recherche sind sie
     * weiterhin da.
     *
     * @return array  Kategorie => ['count' => int, 'level' => string,
     *                'latest' => ?array (Eintrag)]
     */
    public function summary(int $days = self::SUMMARY_DAYS): array
    {
        $from = date('Y-m-d', strtotime('-' . max(0, $days - 1) . ' days'));

        $counts = [];
        foreach ($this->files() as $file => $info) {
            if ($info['date'] < $from) continue;   // files() ist absteigend sortiert
            foreach ($this->parse($file, $info) as $entry) {
                $category = $entry['category'];
                if ($category === 'info') continue;
                if (!isset($counts[$category])) {
                    $counts[$category] = ['count' => 0, 'level' => self::CATEGORIES[$category], 'latest' => null];
                }
                $counts[$category]['count']++;
                $latest = $counts[$category]['latest'];
                if ($latest === null || [$entry['date'], $entry['time']] >= [$latest['date'], $latest['time']]) {
                    $counts[$category]['latest'] = $entry;
                }
            }
        }

        // In die Rangfolge von CATEGORIES bringen
        $summary = [];
        foreach (self::CATEGORIES as $category => $level) {
            if (isset($counts[$category])) $summary[$category] = $counts[$category];
        }
        return $summary;
    }

    /**
     * Wurde beim letzten Lesen am Anfang einer Datei abgeschnitten (MAX_BYTES)?
     */
    public function truncated(): bool
    {
        return $this->truncated;
    }

    /**
     * Kategorie eines Eintrags - siehe CATEGORIES.
     *
     * @param string $name  Logbezeichnung (Dateiname ohne Datum)
     * @param string $text  Text des Eintrags
     */
    public function classify(string $name, string $text): string
    {
        if ($name === 'security') {
            return str_contains($text, 'Login blocked') ? 'login' : 'security';
        }
        if ($name === 'mail') return 'mail';
        // Verstöße gegen die Script-Policy des Backends (StartCspReport)
        if ($name === 'csp') return 'warning';
        if ($name === 'sql-errors') return 'database';
        if (in_array($name, self::JOB_LOGS, true)) return 'job';

        if ($name === 'php_errors') {
            // Datenbankfehler sind auch dann Datenbankfehler, wenn sie als
            // unbehandelte Exception im PHP-Log landen (System::handleException()
            // ist nicht registriert)
            if (stripos($text, 'mysqli') !== false || stripos($text, 'SQLSTATE') !== false) return 'database';
            if (preg_match('/\b(Fatal error|Parse error|Uncaught)\b/i', $text)) return 'php';
            if (preg_match('/\b(Warning|Notice|Deprecated)\b/i', $text)) return 'warning';
            return 'php';
        }
        return 'info';
    }

    // ====================

    /** Verzeichnis der Logdateien */
    private function dir(): string
    {
        return rtrim((string)$this->config->get('paths.var_dir'), '/\\') . '/log';
    }

    /**
     * Eine Datei in Einträge zerlegen (siehe Klassenkommentar).
     *
     * @param string $file  Dateiname aus files() - bereits geprüft
     * @return array        Einträge in Dateireihenfolge (ältester zuerst)
     */
    private function parse(string $file, array $info): array
    {
        $content = $this->read($file, $info['size']);
        if ($content === '') return [];

        $entries = [];
        $current = null;
        foreach (explode("\n", $content) as $line) {
            $line = rtrim($line, "\r");
            if ($line === '' && $current === null) continue;

            $time = $this->lineTime($line, $rest);
            if ($time !== null) {
                if ($current !== null) $entries[] = $current;
                $current = [
                    'file' => $file,
                    'name' => $info['name'],
                    'date' => $info['date'],
                    'time' => $time,
                    'text' => $rest,
                    'category' => '',
                ];
                continue;
            }
            // Fortsetzung (Stacktrace) - an den laufenden Eintrag anhängen
            if ($current !== null) $current['text'] .= "\n" . $line;
        }
        if ($current !== null) $entries[] = $current;

        foreach ($entries as $i => $entry) {
            $entries[$i]['category'] = $this->classify($entry['name'], $entry['text']);
        }
        return $entries;
    }

    /**
     * Beginnt die Zeile mit einem der drei Zeitstempel (Klassenkommentar)?
     *
     * @param ?string $rest  Rest der Zeile ohne Zeitstempel
     * @return ?string       Uhrzeit H:i:s, null wenn es kein Zeilenanfang ist
     */
    private function lineTime(string $line, ?string &$rest): ?string
    {
        // [14:22:07] text
        if (preg_match('/^\[(\d{2}:\d{2}:\d{2})\]\s?(.*)$/s', $line, $m)) {
            $rest = $m[2];
            return $m[1];
        }
        // [22-Sep-2026 00:18:23 Europe/Berlin] text
        if (preg_match('/^\[\d{2}-[A-Za-z]{3}-\d{4} (\d{2}:\d{2}:\d{2})[^\]]*\]\s?(.*)$/s', $line, $m)) {
            $rest = $m[2];
            return $m[1];
        }
        // 2026-09-21 17:45:47 text
        if (preg_match('/^\d{4}-\d{2}-\d{2} (\d{2}:\d{2}:\d{2})\s?(.*)$/s', $line, $m)) {
            $rest = $m[2];
            return $m[1];
        }
        $rest = null;
        return null;
    }

    /**
     * Das Ende einer Datei lesen - höchstens MAX_BYTES (siehe Klassenkommentar).
     *
     * @param string $file  Dateiname aus files()
     */
    private function read(string $file, int $size): string
    {
        $path = $this->dir() . '/' . $file;
        if (!is_file($path)) return '';

        if ($size <= self::MAX_BYTES) {
            return (string)file_get_contents($path);
        }

        $this->truncated = true;
        $handle = fopen($path, 'rb');
        if ($handle === false) return '';
        fseek($handle, -self::MAX_BYTES, SEEK_END);
        $content = (string)stream_get_contents($handle);
        fclose($handle);

        // Die erste Zeile ist angeschnitten
        $pos = strpos($content, "\n");
        return $pos === false ? '' : substr($content, $pos + 1);
    }
}
