<?php
/**
 * Dateiauslieferung und Dateibrowser des Backends.
 *
 * Einstiegspunkte:
 *
 *     public/backend/file.php       -> filePreview()        Datei ausliefern
 *     backend/server_file.php       -> serverFileSelect()   Serververzeichnis anzeigen
 *
 * WARUM MEDIENDATEIEN NICHT DIREKT VERLINKT WERDEN
 * --------------------
 * Eine Datei kann an drei verschiedenen Stellen liegen, je nachdem, wo im
 * Bearbeitungsvorgang sie gerade steckt:
 *
 *     am produktiven Datensatz            {media_dir}/{instance_id}/{field_id}/{record_id}.{ext}
 *     an der Arbeitskopie (frisch hochgeladen, noch nicht gespeichert)
 *                                         dieselbe Ablage, aber unter der tmp-Id
 *     gar nicht mehr (als gelöscht markiert, Eintrag nur noch für den checkIn)
 *
 * Ein fester Link aus der Eingabemaske könnte das nicht abbilden. filePreview() ist
 * deshalb die eine Stelle, die entscheidet, welche Datei gemeint ist, und sie
 * ausliefert. Sie ersetzt zugleich den direkten Zugriff auf das Medienverzeichnis -
 * das Backend verlinkt NIE direkt dorthin (Backend::backendValue(),
 * RecordBackendEdit::_editFileReplacements()); geliefert wird nur nach Anmeldung
 * und nur, was Record::load() dem Benutzer zeigen würde.
 *
 * UMLEITEN ODER LESEN
 * --------------------
 * Das Medienverzeichnis ist zweifach gesperrt (Camindo\Core\MediaAccess): je
 * Instanz auf ihre Domains, je Feld mit user_auth vollständig. Das Backend läuft
 * oft unter einer anderen Domain als die Instanz des Datensatzes - ein Verweis ins
 * Medienverzeichnis liefe dann in ein 403. filePreview() leitet deshalb nur um
 * (header Location), wenn alle vier Bedingungen erfüllt sind:
 *
 *     - die Datei hängt am produktiven Datensatz (keine Arbeitskopie: deren
 *       Dateien werden beim Check-in umbenannt, und ihr Name soll nicht im
 *       Browser-Cache bleiben)
 *     - das Feld ist nicht mit user_auth gesperrt
 *     - der Host dieses Requests darf laut Domain-Sperre der Instanz zugreifen
 *       (MediaAccess::hostAllowed())
 *     - es ist ein browsertaugliches Bild (Backend::$webImageFormats): alles
 *       andere soll als Download kommen und nicht im Backend-Kontext gerendert
 *       werden
 *
 * Sonst liest das Script die Datei selbst (readfile) - langsamer, aber immer
 * richtig. Der Verweis trägt `version` bzw. einen Zufallswert als Cache-Brecher.
 *
 * ERBT NICHT VON RecordBackend
 * --------------------
 * Wie RecordBackendFolderList bringt die Klasse einen eigenen, kürzeren Konstruktor
 * mit. Das passt hier besonders: filePreview() läuft über ein eigenes Einstiegsscript
 * (public/backend/file.php) und darf nichts ausgeben außer der Datei selbst - der
 * ganze Seitenapparat der Basisklasse wäre hier hinderlich.
 *
 * @see Camindo\Core\Modules\Record\RecordBackendUpload  übernimmt die gewählte Datei
 * @see Camindo\Core\TemporaryTrait                       Arbeitskopien
 * @see Camindo\Core\VirtualFieldsTrait                   Ablageschema der Dateien
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\Config;
use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\MediaAccess;
use Camindo\Core\MediaDelivery;
use Camindo\Core\Session;
use Camindo\Core\System;
use Camindo\Core\Modules\Record\Record;

class RecordBackendServerFile {

    /** gewünschte Dateiendungen des Dateibrowsers (Request 'ext'), leer = alle */
    private array $extensions = [];

    public function __construct(
        private Config $config,
        private Backend $backend,
        private Cms $cms,
        private Session $session,
        private System $system,
        private Record $record,
        private MediaAccess $mediaAccess,
        private MediaDelivery $mediaDelivery
    )
    {}

    /**
     * Eine Mediendatei ausliefern (public/backend/file.php).
     *
     * Der Parameter file hat die Form "{field_id}/{record_id}" - keine Dateinamen, kein
     * Pfad. Endung und Mime-Type stammen aus dem `data_fil`-Eintrag, die Datei selbst
     * wird daraus zusammengesetzt. Ein Verzeichniswechsel über den Parameter ist damit
     * ausgeschlossen.
     *
     * PRÜFUNGEN, IN DIESER REIHENFOLGE
     *  1. angemeldet? sonst Abbruch
     *  2. Datensatz über Record::load() holen - das ist zugleich die
     *     Zugriffsprüfung; wer den Datensatz nicht sehen darf, bekommt 404
     *  3. Feld muss existieren
     *  4. als gelöscht markierte Datei -> 404 (der Eintrag besteht nur noch für den
     *     checkIn, siehe RecordBackendUpload)
     *
     * WO DIE DATEI LIEGT
     * Der entscheidende Fall: Wird eine Arbeitskopie angezeigt, deren Datei aber noch
     * am Original hängt (sie wurde in dieser Bearbeitung nicht angefasst), muss unter
     * der origin_id gesucht werden. Das Kennzeichen 'temporary' im Cache-Eintrag sagt,
     * welcher der beiden Fälle vorliegt.
     *
     * AUSLIEFERUNG
     * Entweder Umleitung ins Medienverzeichnis (siehe Klassenkommentar "Umleiten
     * oder lesen") oder readfile(). Beim Lesen wird der Ausgabepuffer vorher
     * geleert - ein einzelnes Leerzeichen aus einer anderen Datei würde die
     * Binärdaten beschädigen. Bilder in browsertauglichen Formaten gehen als
     * 'inline' heraus, alles andere als 'attachment' (Download); ein PDF oder eine
     * HTML-Datei soll nicht im Kontext des Backends gerendert werden.
     *
     * Ausgeliefert wird mit MediaDelivery::sendFile() unter dem ursprünglichen
     * Dateinamen (mit Endung) und `Cache-Control: private, no-store` - der Browser
     * zeigt also keine zwischengespeicherte Fassung. (Früher hing eine Zufallsfolge
     * hinter der Endung; heruntergeladene Dateien hatten dann keinen Typ mehr.)
     *
     * Request: file = "{field_id}/{record_id}[/{language_id}]" - die Sprache bei
     * mehrsprachigen Datei-Feldern (sonst die Sprache des Datensatzes, siehe
     * Cms::recordLanguageId()). Der Eintrag der Sprache kommt bei einer Arbeitskopie
     * aus dem Arbeitsstand (eigene Datei der Sprache, sonst die der Hauptsprache), sonst aus
     * `record_cache`; file_language_id sagt, welche Datei das ist.
     */
    public function filePreview()
    {
        if (!$this->session->load()) {
            die('##accessdenied##');
        }
        $file    = (string)($_REQUEST['file'] ?? '');

        $parts = explode('/', $file, 3);
        $fieldId = (int)($parts[0] ?? 0);
        $recordId = (int)($parts[1] ?? 0);
        $langPart = trim((string)($parts[2] ?? ''));
        if (empty($fieldId) or empty($recordId)) {
            $this->system->error404();
            return;
        }
        
        // record->load nutzen -> prüft Zugriffsberechtigung
        if (!$data = $this->record->load($recordId)) {
            $this->system->error404();
            return;
        }
        $areaId = (int)$data['area_id'];
        // Leserecht der Gruppe auf den Bereich (12-group-rights.md)
        if (!$this->backend->access()->areaRead($areaId)) {
            $this->system->error404();
            return;
        }
        if (!$fieldData = $this->record->fieldData($fieldId)) {
            $this->system->error404();
            return;
        }
        $name = $fieldData['name'] ?? '';

        $tmp = (int)($data['tmp'] ?? 0);
        $originId = (int)($data['origin_id'] ?? 0);

        // Sprache des Eintrags
        $languageId = ($langPart !== '' && ctype_digit($langPart))
            ? (int)$langPart
            : $this->cms->recordLanguageId((int)($data['instance_id'] ?? 0));

        // Ermitteln wo die Datei liegt
        $fileData = (array)($data[$name] ?? []);
        if ($this->cms->isMultilingualField($fieldData)) {
            if ($tmp === 1) {
                $entry = (array)($this->record->loadTmpData($recordId)[$name] ?? []);
                $entries = $this->record->fileEntries($entry);
                $primaryId = $this->record->languageIdsOf($recordId)[0] ?? $languageId;
                $fileData = (array)($entries[$languageId] ?? $entries[$primaryId] ?? []);
            }
            else {
                $cache = $this->cms->loadRecordCache($recordId, $languageId) ?? [];
                $fileData = (array)($cache[$name][1] ?? $fileData);
            }
        }
        $fileLanguageId = $this->cms->fileLanguageId($fileData);
        $isTemporary = (bool)($fileData['temporary'] ?? false);
        $isDeleted = (bool)($fileData['deleted'] ?? false);
        if ($isDeleted) {
            // Datei wurde als enfernt markiert,
            // Eintrag besteht nur noch für checkIn-Prozess
            $this->system->error404();
            return;
        }
        if ($tmp === 1 && !$isTemporary) {
            // Record ist temporär - aber die Datei ist dem produktiven Datensatz zugeordnet
            $storageId = $originId;
        }
        else {
            // Die Datei ist dem angegebenen Datensatz selbst zugeordnet.
            // (Angegebener Datensatz kann temporär oder produktiv sein sein.)
            $storageId = $recordId;
        }

        $instanceId = (int)($data['instance_id'] ?? 0);
        $relativePath = $this->cms->mediaPath($fieldId, $storageId, $fileLanguageId, (string)$fileData['extension'], $instanceId);
        $mediaDir = $this->config->get('paths.media_dir');
        $file = "$mediaDir/$relativePath";
        if (!file_exists($file)) {
            // Datei ist nicht da wo sie sein sollte
            $this->system->error404();
            return;
        }

        // Standard-MIME-Type, falls keiner in der DB hinterlegt ist
        $mimeType = $fileData['mime_type'] ?? 'application/octet-stream';

        // Umleiten statt lesen? (siehe Klassenkommentar)
        // --------------------
        $details = json_decode((string)($fieldData['details'] ?? ''), true) ?: [];
        $isProductiveFile = ($tmp === 0);
        if ($isProductiveFile
            && empty($details['user_auth'])
            && in_array($mimeType, $this->backend->webImageFormats, true)
            && !headers_sent()
            && $this->mediaAccess->hostAllowed($instanceId, (string)($_SERVER['HTTP_HOST'] ?? ''))
        ) {
            if (ob_get_level()) ob_end_clean();
            header('Location: ' . $this->config->get('paths.media') . '/' . $relativePath
                . '?v=' . (int)($fileData['version'] ?? 0), true, 302);
            return;
        }
        
        // Ausliefern wie im Frontend (MediaDelivery::sendFile()): Dateiname maskiert
        // (filename*), aktive Inhalte wie SVG nur als Download in der Sandbox.
        // Der ursprüngliche Name behält seine Endung - ohne sie erkennt z. B. macOS
        // den Dateityp nicht; fehlt sie im gespeicherten Namen, kommt die der Datei dazu.
        $filename = (string)($fileData['filename'] ?? '') ?: 'file';
        $extension = strtolower((string)($fileData['extension'] ?? ''));
        if ($extension !== '' && strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== $extension) {
            $filename .= '.' . $extension;
        }
        $this->mediaDelivery->sendFile($file, (string)$mimeType, $filename,
            download: !in_array($mimeType, $this->backend->webImageFormats, true), private: true);
    }

    /**
     * Dateibrowser für das Upload-Verzeichnis (backend/server_file.php).
     *
     * Zeigt den Inhalt des paths.upload_dir als Baum. Gedacht für Dateien, die per FTP
     * oder aus einem anderen Vorgang auf dem Server gelandet sind und in ein
     * Upload-Feld übernommen werden sollen, ohne sie erneut hochzuladen. Ein Klick
     * geht an RecordBackendUpload::fileServerSelect().
     *
     * Unterverzeichnisse werden angezeigt, aber der Einstieg IN ein Unterverzeichnis
     * ist noch nicht umgesetzt ($relativePath) - der Baum wird ohnehin vollständig
     * ausgegeben.
     *
     * Der Pfad einer Datei geht doppelt maskiert in die Vorlage: json_encode für das
     * Javascript, htmlspecialchars für das Attribut, in dem der Aufruf steht.
     *
     * @param string $relativePath  relativer Pfad zu paths.upload_dir (noch ohne Wirkung)
     */
    public function serverFileSelect($relativePath = ''): void
    {
        $srcField = (int)($_REQUEST['src_field'] ?? 0);
        // Arbeitskopie, zu der das Upload-Feld gehört (0: unbekannt) - unterscheidet
        // gleiche Felder mehrerer eingebetteter Inhalte (cApp.ui.selectUploadFile())
        $srcId = (int)($_REQUEST['src_id'] ?? 0);
        // Sprache des Upload-Feldes (mehrsprachig), '' bei einsprachigen Feldern
        $lang = preg_replace('/[^0-9]/', '', (string)($_REQUEST['lang'] ?? ''));
        // ext: nur Dateien mit diesen Endungen zeigen (Kommaliste, z. B. der Import:
        // zip,xml,csv,docx), Groß-/Kleinschreibung egal; leer = alle
        $this->extensions = array_values(array_filter(array_map(
            fn($e) => strtolower(trim($e)),
            explode(',', (string)($_REQUEST['ext'] ?? ''))
        )));

        // Bread crum navi
        $this->backend->setNaviLabel('#record.server_files');

        echo $this->backend->template('container_header');

        // Container Header
        echo $this->backend->template('headline1', [
            '{text}' => '{#record.server_files}'
        ]);

        $uploadDir = $this->config->get('paths.upload_dir'); // (endet immer ohne Slash)
        $currentDir = $uploadDir . DIRECTORY_SEPARATOR . $relativePath;

        if (!empty($relativePath)) {
            echo "error: not supported yet.";
            $this->backend->template('container_footer');
            exit;
        }
        else {
            echo $this->backend->template('record.upload_tree_head', [
                '{upload_dir}' => $uploadDir
            ]);
        }
        $files = $this->buildDirectoryTree($currentDir);

        if (empty($files)) $this->backend->template('div-mt', [
            '{id}' => '',
            '{text}' => '{#record.message_folder_empty}'
        ]);
        $this->backend->buttonAdd('button_cancel_default', 'cApp.layers.close()');
        echo $this->backend->buttonBar();

        $items = [];
        foreach ($files as $file) {
            // sicher für javascript verpacken
            $pathFile = $file['path'] . DIRECTORY_SEPARATOR . $file['file'];
            $pathFileEncoded = htmlspecialchars(
                json_encode($pathFile, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)
            );
            // sicher für Anzeige
            $fileNameEncoded = htmlspecialchars($file['file']);
            // Template-Ersetzungen
            $replacements = [
                '{file}' => $fileNameEncoded,
                '{path_file}' => $pathFileEncoded,
                '{indent}' => ($file['indent'] * 20),
                '{src_field}' => $srcField,
                '{src_id}' => $srcId,
                '{lang}' => $lang,
            ];
            if ($file['type'] == 'folder') {
                $items[] = $this->backend->template('record.upload_tree_folder', $replacements);
            }
            elseif ($file['type'] == 'file') {
                $items[] = $this->backend->template('record.upload_tree_file', $replacements);
            }
            // else: unknown type
        }
        // ---- Ende ----
        echo $this->backend->template('record.upload_tree', [
            '{list_items}' => implode("\n", $items)
        ]);

        echo $this->backend->template('container_footer');
    }

    /**
     * Scannt ein Verzeichnis rekursiv und liefert eine flache Liste 
     * in der richtigen Baum-Reihenfolge zur Darstellung zurück.
     * Schließt versteckte Dateien und Ordner (die mit einem Punkt beginnen) aus.
     *
     * Flach und nicht verschachtelt, weil die Ausgabe eine Liste mit Einrückung ist:
     * die Rekursion hängt die Kinder unmittelbar hinter ihren Ordner und erhöht dabei
     * nur die Einrückungsebene. Die Sortierung setzt Ordner vor Dateien und vergleicht
     * innerhalb der Gruppen natürlich ("bild10" nach "bild9").
     *
     * Punkt-Einträge fallen heraus - .htaccess und Ähnliches gehören nicht in ein
     * Auswahlfenster.
     *
     * @param string $dir Aktuelles absolutes Zielverzeichnis
     * @param string $currentRelativePath Der bisherige relative Pfad zum Basisverzeichnis
     * @param int $indent Aktuelle Einrückungsebene (intern genutzt)
     * @return array
     */
    private function buildDirectoryTree(string $dir, string $currentRelativePath = '', int $indent = 0): array
    {
        $tree = [];

        // Sicherstellen, dass das Verzeichnis existiert und lesbar ist
        if (!is_dir($dir) || !is_readable($dir)) {
            return $tree;
        }

        // Pfad bereinigen (am Ende kein Trailing Slash)
        $dir = rtrim($dir, '/\\');

        // Dateien und Ordner einlesen und alle Dotfiles (beginnend mit '.') filtern
        $items = array_filter(scandir($dir), function ($item) {
            return !str_starts_with($item, '.');
            // Für PHP-Versionen < 8.0 stattdessen: return $item[0] !== '.';
        });

        // Sortierung: Ordner zuerst, dann Dateien (jeweils alphabetisch)
        usort($items, function ($a, $b) use ($dir) {
            $pathA = $dir . '/' . $a;
            $pathB = $dir . '/' . $b;

            $isDirA = is_dir($pathA);
            $isDirB = is_dir($pathB);

            if ($isDirA !== $isDirB) {
                return $isDirA ? -1 : 1;
            }

            return strnatcasecmp($a, $b);
        });

        foreach ($items as $item) {
            $fullPath = $dir . '/' . $item;
            $isDir = is_dir($fullPath);

            // Dateien außerhalb der gewünschten Endungen übergehen
            if (!$isDir && !empty($this->extensions)
                && !in_array(strtolower((string)pathinfo($item, PATHINFO_EXTENSION)), $this->extensions, true)
            ) {
                continue;
            }

            // Eintrag für das aktuelle Element.
            // $currentRelativePath ist hier exakt das gewünschte "subdir/subsubdir"
            $entry = [
                'path'   => $currentRelativePath,
                'file'   => $item,
                'indent' => $indent,
                'type'   => $isDir ? 'folder' : 'file'
            ];
            if (!$isDir) {
                $tree[] = $entry;
                continue;
            }

            // Ordner: rekursiv dessen Inhalt verarbeiten
            // Den relativen Pfad für die Kinder dieses Ordners erweitern
            $nextRelativePath = $currentRelativePath !== ''
                ? $currentRelativePath . '/' . $item
                : $item;
            $subItems = $this->buildDirectoryTree($fullPath, $nextRelativePath, $indent + 1);
            // Mit Endungsfilter bleiben Ordner ohne passende Datei weg - ein leerer
            // Ordner über einer Datei der Ebene darunter sah aus, als läge die
            // Datei darin
            if (!empty($this->extensions) && !in_array('file', array_column($subItems, 'type'), true)) {
                continue;
            }
            $tree[] = $entry;
            $tree = array_merge($tree, $subItems);
        }

        return $tree;
    }
}