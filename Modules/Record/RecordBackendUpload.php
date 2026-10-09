<?php
/**
 * Dateien in der Eingabemaske entfernen und auswählen (Erweiterung für RecordBackend).
 *
 * Einstiegspunkte (Scripte in backend/):
 *
 *     ajax_file_remove.php  -> fileRemove()        x-Knopf neben der Vorschau
 *     ajax_file_select.php  -> fileServerSelect()  Datei aus dem Serververzeichnis wählen
 *
 * WARUM DAS AUFWENDIGER IST, ALS ES AUSSIEHT
 * --------------------
 * Gearbeitet wird nie am produktiven Datensatz, sondern an einer Arbeitskopie (tmp=1,
 * siehe TemporaryTrait). Eine Datei zu entfernen heißt deshalb dreierlei:
 *
 *  1. die physische Datei der Arbeitskopie löschen
 *  2. deren `data_fil`-Eintrag löschen - bliebe er stehen, würde promoteTmpFiles()
 *     ihn beim Speichern auf den produktiven Datensatz umschreiben, und der
 *     Vorschau-Link zeigte auf eine nicht mehr vorhandene Datei
 *  3. im Arbeitsstand (record_cache) der Arbeitskopie eine Löschmarkierung setzen.
 *     Nur daran erkennt promoteTmpFiles() beim Speichern, dass auch die am
 *     PRODUKTIVEN Datensatz hängende Datei wegfallen soll. Ein bloßes Fehlen des
 *     Eintrags ließe sich nicht von "nichts geändert" unterscheiden.
 *
 * Der Eintrag behält dabei das Format ['file', [...]] - promoteTmpFiles() prüft den
 * Typ an Position 0 und würde eine anders geformte Markierung übersehen.
 *
 * THUMBNAILS HÄNGEN DARAN
 * --------------------
 * Von einem Upload-Feld abgeleitete Thumbnail-Felder (type 'file', display_type
 * 'thumbnail', parent_field_id zeigt auf das Upload-Feld) verlieren mit ihrer Quelle
 * die Grundlage. _removeFiles() räumt sie deshalb rekursiv mit ab. Die Liste
 * $handled verhindert dabei eine Endlosschleife, falls sich zwei Felder gegenseitig
 * als parent_field_id eintragen.
 *
 * BERECHTIGUNGSPRÜFUNG
 * --------------------
 * _markFileDeleted() prüft dreifach, bevor es etwas löscht: der Datensatz muss eine
 * Arbeitskopie sein, das Feld muss ein Upload-Feld sein, und es muss zum Bereich
 * dieser Arbeitskopie gehören. Ohne die dritte Prüfung ließe sich über eine
 * untergeschobene field_id eine fremde Datei löschen.
 *
 * @see Camindo\Core\TemporaryTrait                    Arbeitskopien, promoteTmpFiles()
 * @see Camindo\Core\VirtualFieldsTrait                Dateiablage und Thumbnails
 * @see Camindo\Core\Modules\Record\RecordBackendEdit  die Eingabemaske
 * @see Camindo\Core\Modules\Record\RecordBackendServerFile  der Dateibrowser
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\DirectoryHelpers;

class RecordBackendUpload extends RecordBackend {

    /**
     * Entfernt eine hochgeladene Datei aus einer temporären Arbeitskopie
     * (x-Button neben dem Preview-Link in der Eingabemaske).
     *
     * Die Arbeit macht VirtualFieldsTrait::markFileDeleted() - dieselbe Routine
     * nutzt CMS:USER_INPUT für den Parameter 'removefiles' (RecordUser).
     *
     * @param int $tmpId            Id der temporären Arbeitskopie
     * @param int $fieldId          Id des Upload-Feldes
     * @param int|null $languageId  Sprache der Datei bei mehrsprachigen Upload-Feldern
     * @return bool                 true im Erfolgsfall
     */
    private function _markFileDeleted(int $tmpId, int $fieldId, ?int $languageId = null): bool
    {
        return $this->record->markFileDeleted($tmpId, $fieldId, $languageId);
    }

    /**
     * Ajax: Datei aus einem Upload-Feld entfernen (x-Knopf neben der Vorschau).
     *
     * Die Arbeit macht _markFileDeleted(), einschließlich der Prüfungen. Endgültig
     * wirksam wird das Entfernen erst beim Speichern des Datensatzes - bis dahin
     * lässt es sich durch Abbrechen der Bearbeitung zurücknehmen.
     *
     * Request: id (Arbeitskopie), field_id
     */
    public function fileRemove():void
    {
        // Backend-Aktion "Datei entfernen" (x-Button) - schreibt, also nur POST
        // (17-csrf.md §2.7)
        if (!$this->backend->requirePost()) {
            echo $this->backend->access()->deniedHtml();
            return;
        }
        $tmpId    = (int)$_REQUEST['id'];           // Id der Arbeitskopie
        $fieldId  = (int)$_REQUEST['field_id'];     // field_id des Upload-Feldes
        $lang     = $this->_langParam();             // Sprache (mehrsprachiges Feld)

        if (!$this->_markFileDeleted($tmpId, $fieldId, $lang)) {
            $this->errors();
        }
    }

    /**
     * Ajax: eine Datei aus dem Serververzeichnis für ein Upload-Feld übernehmen.
     *
     * Der Gegenpart zum Dateibrowser (RecordBackendServerFile). Anders als bei einem
     * Upload wird die Datei hier NICHT kopiert - übernommen wird nur ihr Pfad, den ein
     * verstecktes Feld im Formular trägt; verarbeitet wird sie erst beim Speichern.
     *
     * Eine bereits im Feld liegende Datei wird vorher als gelöscht markiert, sonst
     * blieben zwei Dateien für ein Feld übrig.
     *
     * Der Pfad geht maskiert in die Vorlage; angezeigt wird nur der bereinigte
     * Dateiname ohne Verzeichnisanteil.
     *
     * Daneben der Schalter "nach erfolgreichem Übernehmen löschen"
     * (data[{name}][serverfile_delete]): die Datei in var/upload wird erst gelöscht,
     * wenn der Datensatz gespeichert ist (VirtualFieldsTrait::promoteTmpFiles()) -
     * bricht man die Bearbeitung ab, bleibt sie liegen.
     *
     * Das versteckte Feld heißt data[{name}]... - bei einem eingebetteten Inhalt
     * (Arbeitskopie an einem 'link'-Feld einer anderen Arbeitskopie, LinkTrait::
     * isEmbedded()) embed[{id}][{name}]..., wo RecordBackendEdit::_embedSave() die
     * Eingaben des Inhalts erwartet.
     *
     * Request: id (Arbeitskopie), field_id, filename (relativ zu paths.upload_dir)
     */
    public function fileServerSelect():void
    {
        // Markiert die bisherige Datei als gelöscht - nur POST (17-csrf.md §2.7)
        if (!$this->backend->requirePost()) {
            echo $this->backend->access()->deniedHtml();
            return;
        }
        $tmpId    = (int)$_REQUEST['id'];           // Id der Arbeitskopie
        $fieldId  = (int)$_REQUEST['field_id'];     // field_id des Upload-Feldes
        $lang     = $this->_langParam();             // Sprache (mehrsprachiges Feld)
        $filename = (string)$_REQUEST['filename'];  // Dateiname inkl. relativem Pfad ab paths.upoad_dir

        // Evtl. gespeicherte Datei als gelöscht markieren
        $this->_markFileDeleted($tmpId, $fieldId, $lang);

        // Hiddenfeld mit Pfad erzeugen: data[{name}][serverfile] bzw. bei
        // mehrsprachigen Feldern data[{name}][{language_id}][serverfile]
        $fieldData = $this->record->fieldData($fieldId);
        $name = $fieldData['name'];
        $langKey = ($lang !== null && $this->cms->isMultilingualField($fieldData)) ? '[' . $lang . ']' : '';
        
        $basename = DirectoryHelpers::sanitizeFilename($filename);
        echo $this->backend->template('record.file_server_preview_container', [
            '{file}' => htmlspecialchars($filename),
            '{text}' => htmlspecialchars($basename),
            '{name}' => $name,
            '{lang_key}' => $langKey,
            '{var}' => $this->record->isEmbedded($tmpId) ? 'embed[' . $tmpId . ']' : 'data',
            '{switch_id}' => 'c9-serverfile-delete-' . $tmpId . '-' . $fieldId . ($lang !== null ? '-' . $lang : ''),
        ]);
    }

    /**
     * Request-Parameter lang: Sprache der Datei bei mehrsprachigen Upload-Feldern,
     * null wenn leer (einsprachiges Feld).
     */
    private function _langParam(): ?int
    {
        $lang = trim((string)($_REQUEST['lang'] ?? ''));
        return ($lang === '' || !ctype_digit($lang)) ? null : (int)$lang;
    }
}