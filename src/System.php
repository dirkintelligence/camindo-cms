<?php
/**
 * Systemnahe Dienste: Logging, Dateien und Verzeichnisse anlegen, kleine Helfer.
 * Instanzname im Code: $system.
 *
 * Sammelstelle für alles, was mit dem Dateisystem oder dem Laufzeitzustand zu tun hat
 * und in keine der fachlichen Klassen gehört. Sie kennt als einzige Abhängigkeit die
 * Config - dadurch ist sie überall einsetzbar, auch sehr früh im Bootstrap.
 *
 * SCHREIBZUGRIFFE IMMER MIT BASISVERZEICHNIS
 * --------------------
 * createDir() und filePutContents() nehmen den Zielpfad grundsätzlich zweiteilig
 * entgegen: ein $baseDir, das existieren muss, und einen $relativePath darunter.
 *
 *     $system->filePutContents($varDir, 'log/2026-09-03_sql.log', $text, append: true);
 *
 * Das ist kein Stilfrage, sondern die Absicherung: der relative Teil kommt in einigen
 * Aufrufen aus Benutzereingaben, und die Trennung erlaubt es, ihn zu normalisieren und
 * ein Ausbrechen aus dem Basisverzeichnis zu verhindern. Beide Methoden legen fehlende
 * Zwischenverzeichnisse an und setzen die Rechte aus permissions.dir / permissions.file.
 *
 * LOGS
 * --------------------
 * log($name, $text) schreibt nach var/log/{Y-m-d}_{name}.log, eine Datei je Tag und
 * Zweck. Im Bestand verwendet:
 *   'sql'         bei system.debugsql + system.development_version,
 *   'sql-errors'  SQL-Fehler
 *   'cron-errors' Fehler
 *   'security' (zu viele falsche Logins)
 * Davon getrennt liegt das PHP-eigene Fehlerlog, das Config im Konstruktor auf
 * var/log/{Y-m-d}_php_errors.log umbiegt.
 *
 * EXCEPTION-HANDLER
 * --------------------
 * handleException() ist als globaler Handler gedacht, die Registrierung im Konstruktor
 * ist derzeit auskommentiert - unbehandelte Exceptions laufen also in die
 * PHP-Standardbehandlung. Wird der Handler aktiviert, behandelt er ausschließlich
 * Datenbankfehler gesondert (loggen, im Entwicklungsmodus ausgeben, beenden); alles
 * andere wirft er unverändert weiter.
 */
namespace Camindo\Core;

class System {

    public function __construct(
        private Config $config
    )
    {
        // Exceptions abfangen
        // Auskommentiert: solange die Zeile deaktiviert ist, wird handleException()
        // nirgends automatisch aufgerufen (siehe Klassenkommentar).
        // set_exception_handler([$this, 'handleException']);
    }
    
    /**
     * Exceptions abfangen, die nicht durch catch behandelt wurden
     *
     * Datenbankfehler werden protokolliert und beenden den Request - im
     * Entwicklungsmodus (system.development_version) zusätzlich mit Klartextausgabe.
     * Alle anderen Exceptions werden unverändert weitergeworfen, damit sie nicht
     * stillschweigend verschluckt werden.
     *
     * Wird derzeit nicht als Handler registriert, siehe Konstruktor.
     */
    public function handleException(\Throwable $e): void {

        if ($e instanceof \mysqli_sql_exception) {
            if ($this->config->get('system.development_version')) {
                // Debug Ausgabe
                echo "Database exception: " . $e->getMessage();
            }
            // Loggen
            $this->log('sql-errors', $e->getMessage());
            exit;
        }

        // Alle anderen Fehler werden unverändert weitergeworfen!
        throw $e;
    }

    /**
     * Schreibe in Logfile, z. B. cms_errors
     * @param string $logName  Logfile-Name (wird um Tag und Extension erweitert)
     * @param string $logText  Inhalt
     */
    public function log(string $logName, string $logText): void {
        // Pfad zur Datei zusammenstellen
        $varDir = $this->config->get('paths.var_dir');
        $date = date('Y-m-d');
        $file = $date . '_' . $logName . '.log';
        $relativePath = 'log/' . $file;

        // Zeile zusammenstellen
        $time = date('H:i:s');
        $logEntry = "[$time] " . $logText . PHP_EOL;

        // Schreiben mit Flag FILE_APPEND
        $this->filePutContents($varDir, $relativePath, $logEntry, append: true);
    }

    /**
     * Erstelle Verzeichnis (rekursiv) innerhalb baseDir
     * und setze Rechte entsprechend der Konfiguration
     * @param string $baseDir       Grundverzeichnis (muss existieren)
     * @param string $relativePath  relativer Pfad zum Grundverzeichnis
     * @return bool                 Im Erfolgsfall true
     */
    
    public function createDir(string $baseDir, string $relativePath): bool
    {
        // Gewünschte Berechtigungen ermitteln
        $permissions = $this->config->get('permissions.dir') ?? 0775;

        // Pfade bereinigen und normalisieren
        $baseDir = rtrim($baseDir, '/\\');
        $relativePath = trim(
            str_replace('\\', '/', $relativePath),
            '/'
        );

        if ($relativePath === '') {
            return is_dir($baseDir);
        }

        $parts = explode('/', $relativePath);
        $currentPath = $baseDir;

        foreach ($parts as $part) {
            $currentPath .= '/' . $part;

            // Prüfen, ob das Verzeichnis auf dieser Ebene bereits existiert
            if (!is_dir($currentPath)) {
                
                // Verzeichnis erstellen. 
                // Der zusätzliche is_dir() Check verhindert Fehler durch Race Conditions 
                // (falls ein anderer Prozess das Verzeichnis im selben Millisekunden-Bruchteil erstellt).
                if (!mkdir($currentPath) && !is_dir($currentPath)) {
                    return false; 
                }

                // Rechte explizit setzen, unabhängig von der Server-umask
                if (!chmod($currentPath, $permissions)) {
                    return false; 
                }
            }
        }

        return true;
    }

    /**
     * Schreibe Datei
     * 
     * @param string $baseDir       Grundverzeichnis
     * @param string $relativePath  relativer Pfad zum Grundverzeichnis inkl. Dateiname
     * @param string $content       Inhalt
     * @param bool $append          true: Inhalt wird an angehängt, false: überschreibt vorhandene Datei
     * @return bool                 Im Erfolgsfall true
     */
    public function filePutContents(string $baseDir, string $relativePath, string $content, bool $append = false, bool $lock = false): bool
    {
        // Pfade bereinigen und normalisieren
        $baseDir = rtrim($baseDir, '/\\');
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');

        // Den relativen Verzeichnispfad von der eigentlichen Datei trennen
        $relativeDir = dirname($relativePath);

        // dirname() gibt '.' zurück, wenn kein Schrägstrich im Pfad enthalten ist (Datei liegt direkt im baseDir)
        if ($relativeDir !== '.' && $relativeDir !== '') {
            // Sicherstellen, dass die Verzeichnisstruktur existiert
            if (!$this->createDir($baseDir, $relativeDir)) {
                return false;
            }
        }

        $fullPath = $baseDir . '/' . $relativePath;

        // Flags
        $flags = 0;
        if ($append) {
            $flags |= FILE_APPEND;
        }
        if ($lock) {
            $flags |= LOCK_EX;
        }

        // file_put_contents() gibt bei Erfolg die Anzahl der geschriebenen Bytes zurück, bei Fehler false.
        $writeResult = file_put_contents($fullPath, $content, $flags);

        if ($writeResult === false) {
            return false;
        }

        // Berechtigungen ermitteln
        $filePermissions = $this->config->get('permissions.file') ?? 0664;

        // Dateirechte explizit setzen, um umask-Einschränkungen zu umgehen.
        chmod($fullPath, $filePermissions);

        return true;
    }

    /**
     * Säubert mehrere Integers z. B. für eine IN()-Anweisung in der Datenbank
     * liefert echte int-Werte als Array
     *
     * @param array|string|int $ids   Integers
     * @param bool $removeZero        true: 0-Wert entfernen
     * @return array                  auch [] ist möglich
     *
     * Wird gebraucht, wo Id-Listen in ein IN (...) müssen - dort lassen sich keine
     * Platzhalter binden. intval() + array_filter() stellen sicher, dass nur Zahlen
     * in den SQL-String gelangen. Negative Werte fallen immer raus, die 0 nur mit
     * $removeZero (sie ist an manchen Stellen ein gültiger Wert, z. B. status_id).
     * Doppelte Einträge werden entfernt, die Schlüssel neu durchnummeriert.
     */
    public function sanitizeIds(array|string|int $ids, bool $removeZero = false): array {
        if (!is_array($ids)) {
            $ids = is_int($ids) ? [$ids] : explode(',', $ids);
        }
        $ids = array_unique(
            array_filter(
                array_map('intval', $ids),
                fn($id) => $removeZero ? $id > 0 : $id >= 0
            )
        );
        return array_values($ids);
    }


    /**
     * Konvertiert Byte-Werte in ein lesbares Format (z. B. "1.5 MB" oder "1.5 MiB").
     *
     * @param float|int $bytes  Die Umzurechnende Anzahl an Bytes
     * @param int       $factor 1000 für dezimale Präfixe (SI), 1024 für binäre Präfixe (IEC)
     * @param int       $decimals Anzahl der Nachkommastellen (Standard: 1)
     * @return string
     */
    public function convertSize(float $bytes, int $factor = 1000, int $decimals = 1): string 
    {
        $nbsp = "\u{00A0}"; // &nbsp;
        if ($bytes <= 0) {
            return "0{$nbsp}Bytes";
        }

        $factor = ($factor === 1024) ? 1024 : 1000;
        
        $units = ($factor === 1000) 
            ? ['Bytes', 'kB', 'MB', 'GB', 'TB', 'PB'] 
            : ['Bytes', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];

        // Berechnet den passenden Präfix-Index anhand des Logarithmus
        $i = (int) floor(log($bytes, $factor));
        $i = min($i, count($units) - 1); // Maximal verfügbare Einheit nicht überschreiten

        if ($i === 0) {
            return (int) $bytes . "{$nbsp}Bytes";
        }

        $value = $bytes / pow($factor, $i);
        $decimalSep = localeconv()['decimal_point'] ?? '.';
        $formatted  = number_format($value, $decimals, $decimalSep, '');

        // Schneidet unberechtigte Nuller nach dem Komma ab (z. B. 1.0 -> 1)
        $formatted = rtrim(rtrim($formatted, '0'), '.');

        return $formatted . $nbsp . $units[$i];
    }

    /**
     * 404-Status senden.
     *
     * Setzt nur den Header, gibt keinen Inhalt aus und beendet den Request nicht -
     * darum muss sich der Aufrufer kümmern (vgl. RecordBackendServerFile::filePreview(),
     * wo jedem Aufruf ein return folgt).
     *
     * Im Frontend noch ungenutzt: Page::resolveRequest() endet bei allen Fehlern mit
     * die() und liefert dabei Status 200.
     */
    public function error404() {
        header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found");
    }
}
