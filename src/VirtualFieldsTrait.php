<?php
/**
 * Trait für Dataset Kind-Klassen
 * Verarbeitet Daten aus virtuellen Tabellenfeldern in den data-Tabellen
 *
 * Konzept "Einzelschritt-Updates" (Dataset::save() mit $stayTemporary = true):
 * Die temporäre Arbeitskopie sammelt Zwischenstände in ihrem Arbeitsstand
 * (siehe saveTemporaryStep()) - für ALLE Sprachen: mehrsprachige Felder stehen
 * dort als Array language_id => Eingabe, leer heißt "nicht übersetzt".
 * Datei-Uploads werden dabei nur physisch unter der Id der Arbeitskopie abgelegt
 * und im Arbeitsstand mit 'temporary' = true markiert.
 * `data_fil` wird für die Arbeitskopie NICHT beschrieben.
 * Relations werden weiterhin sofort an die Arbeitskopie gebunden (`data_rel`).
 * Erst beim finalen Speichern ($stayTemporary = false) werden die Zwischenstände
 * mit den aktuellen Eingaben zusammengeführt und in die Meta-/data-Felder des
 * produktiven Datensatzes gelegt - je Sprache der Instanz eine data_*-Zeile, mit
 * dem Fallback der Hauptsprache, wo eine Übersetzung fehlt (saveVirtualFields());
 * Dateien ziehen beim checkIn über promoteTmpFiles() um, das dabei die
 * `data_fil`-Einträge aus den Datei-Metadaten des Arbeitsstands erzeugt.
 *
 * Der produktive Datensatz trägt KEIN Arbeitsstand. Sein Inhalts-Cache liegt je Sprache
 * in `record_cache` (_writeRecordCache()), mit Kennzeichnung der Fallback-Werte;
 * eine neue Arbeitskopie holt sich daraus ihr Arbeitsstand (_tmpDataFromRecordCache()).
 *
 * Konzept "Dateien sofort sichern, Thumbnails später":
 * Eine Datei (Upload aus $_FILES oder ausgewählte Server-Datei aus $data) wird
 * SOFORT auf Typ geprüft und - wenn zulässig - physisch unter der Id der
 * Arbeitskopie abgelegt und in deren Arbeitsstand eingetragen (secureUploads()).
 * Damit übersteht sie eine anschließend scheiternde Eingabeprüfung und erscheint
 * in der erneut angezeigten Eingabemaske.
 * Die Thumbnail-Erzeugung ist davon entkoppelt: der Datei-Eintrag wird mit
 * 'thumbnails_pending' = true markiert und erst beim FINALEN Speichern über
 * writePendingThumbnails() (aus saveVirtualFields()) abgearbeitet - also einmal
 * pro Datensatz statt bei jedem Zwischenschritt. 
 */
namespace Camindo\Core;
use Camindo\Core\DirectoryHelpers;
use Camindo\Core\InstanceCache;
use Camindo\Core\MediaAccess;

trait VirtualFieldsTrait {

    // Feldtypen, deren Inhalte in Volltextfelder aufgenommen werden können
    protected array $fulltextSourceTypes = ['text', 'chars', 'json', 'file'];   // file: der Dateiname
    protected array $processedParentFieldIds = [];
    protected array $processedUploadFields = [];
    protected array $recordCache = [];
    // Upload-Felder [name, language_id], deren Thumbnails erst NACH dem Umzug der
    // Dateien auf die produktive Id erzeugt werden (siehe saveVirtualFields() /
    // writeDeferredThumbnails())
    protected array $deferredThumbnails = [];
    // Ids der geänderten Textfelder (Quellen für Volltext-Index):
    // werden in saveVirtualFields() gesammelt und in refreshAffectedFulltext()
    // verarbeitet (NACH checkIn(), da die zurückkopierten Relations benötigt werden)
    protected array $affectedTextFieldIds = [];
    protected array $mediaTypeGroups = [
        'image' => ['image/*'],
        'pdf'   => ['application/pdf', '.pdf'],
        'audio' => ['audio/*'],
        'video' => ['video/*'],
        'zip'   => [
            'application/zip', 'application/x-zip-compressed', 'application/gzip',
            'application/x-tar', 'application/x-bzip2', 'application/x-7z-compressed',
            'application/x-rar-compressed', 'application/vnd.rar'
        ],
        'text'  => [
            'text/plain', 'text/rtf', 'application/rtf', 'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.oasis.opendocument.text',
            '.txt', '.rtf', '.doc', '.docx', '.odt'
        ],
        'excel' => [
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.oasis.opendocument.spreadsheet', 'text/csv',
            '.xls', '.xlsx', '.ods', '.csv'
        ],
        'ppt'   => [
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.oasis.opendocument.presentation',
            '.ppt', '.pptx', '.odp'
        ],
    ];

    // Ausnahmen aus einer Gruppe: passt eine Datei nur über die Gruppe, zählen
    // diese Muster nicht mit. SVG ist ein Bild, kann aber JavaScript enthalten und
    // läuft beim direkten Aufruf aus /media im Ursprung der Website (gespeichertes
    // XSS) - wer SVG will, nennt es ausdrücklich in media_types_incl
    // (developer_docs/16-sicherheit.md §9.3)
    protected array $mediaTypeGroupsExcl = [
        'image' => ['image/svg+xml', '.svg', '.svgz'],
    ];

    // Extensions, die aus Sicherheitsgründen NIEMALS gespeichert werden
    // (ausführbarer Code im Media-Verzeichnis), unabhängig von media_types_incl
    protected array $forbiddenExtensions = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phar',
        'cgi', 'pl', 'asp', 'aspx', 'jsp', 'sh', 'htaccess'
    ];

    // Uploads stammen von Website-Besuchern (CMS:USER_INPUT / USER_REGISTER, siehe
    // setUntrustedUploads()): aktiver Inhalt, den der Browser als Dokument bzw.
    // Skript ausführt (MediaAccess::isActiveContent() - Extension ODER erkannter
    // Mime-Typ), wird dann unabhängig von den Feldeinstellungen abgelehnt. Im
    // Backend entscheiden die Felder.
    protected bool $untrustedUploads = false;

    // Fallback Mime-Typ -> Extension (falls der Original-Dateiname keine Extension enthält)
    protected array $mimeToExtension = [
        'image/jpeg' => 'jpg',  'image/png' => 'png', 'image/gif' => 'gif',
        'image/webp' => 'webp', 'image/bmp' => 'bmp', 'application/pdf' => 'pdf',
        'audio/mpeg' => 'mp3',  'video/mp4' => 'mp4', 'application/zip' => 'zip',
        'text/plain' => 'txt',
    ];

    // Konsistenzprüfung Mime-Typ <-> Extension für die wichtigsten Bildtypen:
    // Wird der Mime-Typ serverseitig als einer dieser Typen erkannt, MUSS die
    // Dateiendung dazu passen (verhindert z. B. JPEG-Inhalt mit .zip-Endung).
    // Andere Mime-Typen: keine Konsistenzprüfung.
    //
    // Die Liste deckt alle Formate ab, die ein Medien-Prozessor öffnet - eine
    // hochgeladene Datei soll niemals unter einer Endung liegen, die ihren Inhalt
    // falsch ankündigt (developer_docs/18-imagemagick.md, 2.2.5).
    protected array $mimeExtensionMap = [
        'image/jpeg'                => ['jpg', 'jpeg', 'jpe'],
        'image/png'                 => ['png'],
        'image/gif'                 => ['gif'],
        'image/webp'                => ['webp'],
        'image/bmp'                 => ['bmp', 'dib'],
        'image/x-ms-bmp'            => ['bmp', 'dib'],
        'image/tiff'                => ['tif', 'tiff'],
        'image/svg+xml'             => ['svg'],
        'application/pdf'           => ['pdf'],
        'image/vnd.adobe.photoshop' => ['psd'],
        'application/x-photoshop'   => ['psd'],
        'image/heic'                => ['heic', 'heif'],
        'image/heif'                => ['heic', 'heif'],
        'image/avif'                => ['avif'],
        'image/x-icon'              => ['ico'],
        'image/vnd.microsoft.icon'  => ['ico'],
    ];

    /**
     * Ausführliches Schema mit virtuellen Felder liefern
     * Hiinweis: Lädt nicht die 'rel_area_ids' aus Tabelle `rel_field_area`, verwende dazu: getRelAreaIds()
     * 
     * @param bool $addMetaFields  true: alle Metafelder hinzufügen, false: nur Metafelder auf denen Eingabefeld Feld basiert
     * @param int $areaId          Id des Inhaltsbereichs
     * @return array               Schema
     */
    public function virtualSchema(int $areaId, $addMetaFields = true): array
    {
        if ($areaId < 1) {
            throw new \Exception('Error: virtualSchema(): called with areaId < 1.');
        }
        // Virtuelle Felder laden, sortiert in der richtigen Reihenfolge
        $schema = [];
        $this->db->pushResult();
        $sql = "SELECT * FROM `field` WHERE `area_id`=? ORDER BY `position` ASC";
        $this->db->query($sql, 'i', [$areaId]);
        if ($this->db->num_rows > 0) {
            while ($row = $this->db->fetch()) {
                // Beschriftung, Hinweis, details: Lesefilter wie Cms::fetch()
                // (16-sicherheit.md §6)
                $row = $this->cms->_sanitizeValue($row);
                $name = (string)$row['name'];
                // Flags als Array bereitstellen
                if (empty($row['flags'])) $row['flags'] = [];
                else $row['flags'] = json_decode((string)$row['flags'], false) ?? [];
                // meta = 1 Markierung setzen
                if (isset($this->cms->schema[$name])) $row['meta'] = 1;
                $schema[$name] = $row;
            }
        }
        $this->db->popResult();

        if ($addMetaFields) {
            // Metafelder anfügen, die nicht ohnehin schon enthalten sind
            $schema = $schema + $this->cms->schema;
        }
        return $schema;
    }

    /**
     * Inhalte in virtueller Felder speichern (finaler Speichervorgang)
     *
     * Die Zwischenstände der Arbeitskopie (Einzelschritt-Updates, siehe
     * saveTemporaryStep()) wurden bereits in Dataset::save() mit den aktuell
     * übermittelten Daten zusammengeführt ($data / $affectedFields) und werden
     * hier in die data-Tabellen des produktiven Datensatzes gelegt.
     * Neue Datei-Uploads aus diesem Request liegen physisch unter der Id der
     * Arbeitskopie und sind in deren Arbeitsstand mit 'temporary' markiert; der Umzug
     * auf die produktive Id samt Erzeugen der `data_fil`-Einträge erfolgt beim
     * Produktivgehen in TemporaryTrait::checkIn() -> promoteTmpFiles().
     *
     * SPRACHEN
     * --------------------
     * Je Feld entsteht in seiner data_*-Tabelle EINE Zeile je Sprache der Instanz
     * ($langCtx, Hauptsprache zuerst):
     *
     *     einsprachiges Feld     derselbe Wert in allen Sprachen
     *     mehrsprachiges Feld    der Wert der Sprache; ist er leer (nicht übersetzt),
     *                            der Wert der Hauptsprache (Fallback)
     *
     * Dasselbe gilt für `data_dft`. So filtert und sortiert Cms::select() jede
     * Sprache mit einer einzigen JOIN-Bedingung. Der Inhalts-Cache je Sprache
     * (`record_cache`) entsteht am Ende aus dem Arbeitsstand $this->recordCache
     * (Arbeitsstand-Format, siehe _recordCacheFromTmpData()).
     *
     * Hinweise für den Aufrufer (Dataset::save()):
     * - VOR checkIn() aufrufen (promoteTmpFiles() liest den Arbeitsstand der
     *   Arbeitskopie - sie muss dazu noch existieren)
     * - Alle Werte in $data liegen bereits im Datenbankformat vor
     *   (validateFieldValue(): timestampl in UTC, currency in Cent, json als Array)
     * - Die Volltext-Erneuerung erfolgt separat über refreshAffectedFulltext()
     *   NACH checkIn() (benötigt die zurückkopierten Relations)
     * 
     * @param array &$affectedFields  Felder, deren Inhalte sich ändern (inkl. Zwischenstände)
     * @param array &$data            zu schreibende Inhalte (inkl. Zwischenstände)
     * @param array &$previousData    bei UPDATE: Inhalte des produktiven Datensatzes zuvor
     * @param array &$schemaVirtual   Schema mit virtuellen Feldern
     * @param int   $tmpId            Id der temporären Arbeitskopie (0 = keine)
     * @param array $langCtx          Sprachen der Instanz (Dataset::languageContext())
     */
    protected function saveVirtualFields(array &$affectedFields, array &$data, array &$previousData, array &$schemaVirtual, int $tmpId = 0, array $langCtx = []): void
    {
        $recordId = (int)$data['record_id'];
        if (empty($recordId)) {
            throw new \Exception('Error: saveVirtualFields() called with $recordId = 0.');
        }
        $languageIds = (array)($langCtx['language_ids'] ?? []);
        if (empty($languageIds)) {
            $langCtx = $this->languageContext((int)($data['area_id'] ?? $previousData['area_id'] ?? 0));
            $languageIds = (array)($langCtx['language_ids'] ?? [0]);
        }
        $primaryId = (int)($langCtx['primary_id'] ?? $languageIds[0]);

        // Id, unter der neue Dateien physisch ZUNÄCHST abgelegt werden:
        // die temporäre Arbeitskopie (sofern übergeben), ansonsten der Datensatz selbst.
        // (Bei neuen Datensätzen geht die Arbeitskopie unter ihrer eigenen Id produktiv,
        //  dann ist $tmpId === $recordId und es findet kein Umzug statt)
        $storageId = ($tmpId > 0) ? $tmpId : $recordId;
        // Arbeitsstand (Arbeitsstand-Format: rohe Eingaben, mehrsprachig als
        // language_id => Wert): bei Arbeitskopie deren Zwischenstand (enthält
        // Einzelschritt-Updates inkl. Datei-Infos und Löschmarkierungen), ansonsten
        // der bisherige produktive Stand aus `record_cache`
        if ($tmpId > 0) {
            $this->recordCache = $this->_loadTmpData($tmpId);
        }
        else {
            $this->recordCache = $this->_tmpDataFromRecordCache($recordId, $schemaVirtual, $langCtx);
        }
        // Verschlüsselte Inhalte: der Geheimtext der data_enc-Zeile (base64) je Sprache
        // kommt unverändert in den Cache (statt einer zweiten Verschlüsselung)
        $encryptedCache = [];

        // Ids der betroffenen Felder für Volltext-Index sammeln
        // (Verarbeitung erst in refreshAffectedFulltext(), siehe Methoden-Kommentar)
        $this->affectedTextFieldIds = [];
        // Default-Feld: Werte je Sprache für data_dft
        $defaultValues = null;

        // Sammlung bereits verarbeiteter Parent-Fields
        $this->processedParentFieldIds = [];

        // Inhalte speichern
        // --------------------
        foreach ($affectedFields as $name) {
            if (isset($this->cms->schema[$name])) {
                // Meta-Feld oder virtuelles Feld basierend auf Meta-Feld
                continue;
            }

            $colDef = $schemaVirtual[$name] ?? [];
            if (empty($colDef)) continue;

            // field_id
            $fieldId = (int)$colDef['field_id'];
            if (empty($fieldId)) continue;

            $type = (string)($colDef['type'] ?? 'text');
            $displayType = $colDef['display_type'];
            $label = $colDef['label'] ?? $name;
            $isMultilingual = !empty($colDef['multilingual'])
                && in_array($type, $this->cms->multilingualTypes, true);
            // Volltext wird anschließend generiert
            if ($type == 'fulltext') continue;

            if (in_array($type, $this->fulltextSourceTypes, true)) {
                // Indizierbares Textfeld
                $this->affectedTextFieldIds[] = (int)$fieldId;
            }

            $dataTable = $this->cms->dataSuffix[$type] ?? 'txt';
            $fieldNameId = $this->cms->resolveVirtualField($name)['fieldname_id'];
            $valueRef = &$data[$name];

            if (in_array($type, $this->cms->relationTypes, true)) {
                // Verknüpfungen
                // wurden in Dataset::save() vorgezogen, um min / max prüfen zu können
                // (hängen an der Arbeitskopie, checkIn kopiert sie zurück)
                continue;
            }
            if ($type === 'file') {
                if ($displayType === 'upload') {
                    // upload
                    // --------------------
                    // (Verarbeitung ausgelagert, damit sie auch bei
                    //  Einzelschritt-Speicherungen genutzt werden kann;
                    //  schreibt NUR physische Datei + Arbeitsstand mit 'temporary'- und
                    //  'thumbnails_pending'-Flag, der `data_fil`-Eintrag entsteht
                    //  erst beim checkIn, die Thumbnails weiter unten)
                    // $data enthält ggf. eine ausgewählte Server-Datei;
                    // mehrsprachig: je Sprache ein Upload-Feld
                    foreach ($this->_uploadLanguages($colDef, $languageIds) as $lang) {
                        $this->_saveUploadField($storageId, $name, $colDef, $schemaVirtual, $data, $lang);
                    }
                }
                // display_type 'thumbnail' wird ausschließlich automatisch
                // über writeThumbnails() befüllt, nie direkt aus $data
                continue;
            }

            // Sonstige Typen
            // --------------------
            // Werte je Sprache ermitteln: rohe Eingabe (Arbeitsstand-Format) und der
            // Wert, der in die data_*-Zeile geschrieben wird (mit Fallback)
            $rawValues = [];   // language_id => Eingabe ('' = nicht übersetzt)
            $rowValues = [];   // language_id => Wert der Zeile
            if ($isMultilingual) {
                $input = is_array($valueRef) ? $valueRef : [$primaryId => $valueRef];
                $primaryValue = $input[$primaryId] ?? ($type === 'json' ? [] : '');
                foreach ($languageIds as $lang) {
                    $raw = $input[$lang] ?? ($type === 'json' ? [] : '');
                    $rawValues[$lang] = $raw;
                    $rowValues[$lang] = $this->_isEmptyInput($raw) ? $primaryValue : $raw;
                }
            }
            else {
                foreach ($languageIds as $lang) {
                    $rawValues[$lang] = $valueRef;
                    $rowValues[$lang] = $valueRef;
                }
            }

            // Passwort leer gelassen: bisheriges behalten (Dataset::validateValue()
            // lässt das bei einer Änderung zu) - die data_pwd-Zeilen bleiben stehen.
            if (($type === 'password' || $displayType === 'password') && $this->_isEmptyInput($valueRef)) {
                $this->recordCache[$name] = [$type, ''];
                continue;
            }

            // Vorhandene Werte löschen (alle Sprachen)
            $sql = "DELETE FROM `data_{$dataTable}` WHERE `record_id` = ? AND `field_id` = ?";
            $this->db->query($sql, 'ii', [$recordId, $fieldId]);

            $isDateType = in_array($type, $this->cms->dateTypes, true);
            $hasEmptyFlag = $this->cms->hasEmptyFlag($type);
            foreach ($languageIds as $lang) {
                $value = $rowValues[$lang];
                // Query erstellen
                $sqlExpressions = ['`language_id` = ?', '`record_id` = ?', '`field_id` = ?', '`fieldname_id` = ?'];
                $sqlTypes = 'iiii';
                $sqlValues = [$lang, $recordId, $fieldId, $fieldNameId];
                // `empty` = 1: die Zeile trägt den Fallback aus der Hauptsprache, weil
                // in dieser Sprache nichts eingegeben wurde (Cms::hasEmptyFlag())
                if ($hasEmptyFlag) {
                    $isFallback = $isMultilingual && $lang !== $primaryId && $this->_isEmptyInput($rawValues[$lang]);
                    $sqlExpressions[] = '`empty` = ?';
                    $sqlTypes .= 'i';
                    $sqlValues[] = $isFallback ? 1 : 0;
                }
                if ($type === 'encrypted') {
                    // Verschlüsselten Text + Hash
                    // --------------------
                    // encrypt(text, salt, binary) - hash(normalisierter Text, binary), KEIN Salt;
                    // der Hash ist der Suchwert: klein, ohne Leerraum und Satzzeichen
                    // (Cms::encryptedHash(), dieselbe Normalisierung beim Suchen)
                    $encVal = $this->crypt->encrypt((string)($value ?? ''), '', true); // binary
                    $hashVal = $this->cms->encryptedHash((string)($value ?? '')); // binary, 32 Bytes
                    $sqlExpressions[] = '`data` = ?';
                    $sqlExpressions[] = '`hash` = ?';
                    $sqlTypes .= 'ss';
                    $sqlValues[] = $encVal;
                    $sqlValues[] = $hashVal;
                    // Cache: base64, sonst nicht JSON-fähig (siehe Cms::createRecordCache())
                    $encryptedCache[$lang][$name] = [$type, base64_encode($encVal)];
                    if ($isMultilingual && $lang !== $primaryId && $this->_isEmptyInput($rawValues[$lang])) {
                        $encryptedCache[$lang][$name][] = 1;
                    }
                }
                else {
                    if ($type === 'json') {
                        // JSON-enkodieren (Arrays liegen als solche vor)
                        $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                        if ($value == '""') $value = '';
                    }
                    elseif ($displayType === 'password' && !in_array($name, $this->prehashedFields, true)) {
                        // Passwort hashen - 64 Zeichen mit eingebettetem Salt, passend
                        // zur Spalte `data_pwd`.`data` varchar(64); den Vergleich beim
                        // Anmelden macht RecordUser::passwordOk(). Ein Wert aus dem
                        // Arbeitsstand ($prehashedFields) ist schon der Hash.
                        $value = $this->crypt->passwordHash((string)$value);
                    }
                    if ($isDateType
                        && str_ends_with((string)$value, '()')
                        && in_array(strtoupper((string)$value), $this->cms->funcWhitelist, true)
                    ) {
                        // Erlaubte SQL-Datumsfunktion aus White-List (z. B. UTC_TIMESTAMP())
                        $sqlExpressions[] = "`data` = $value";
                    }
                    elseif ($isDateType && preg_match($this->dateExpression, (string)$value, $matches)) {
                        // CMS-Datumsfunktion (z. B. "(now)", "(today)+3d")
                        $sqlExpressions[] = "`data` = '" . $this->parseDateExpression($matches) ."'";
                    }
                    else {
                        // Werte getrennt vom Statement übertragen
                        // (timestampl liegt bereits in UTC vor - validateFieldValue())
                        $sqlExpressions[] = '`data` = ?';
                        $sqlTypes .= $this->getBindType($type);
                        $sqlValues[] = $value;
                    }
                }
                // Query erstellen
                $sql = "INSERT INTO `data_{$dataTable}` SET " . implode(', ', $sqlExpressions);
                $this->db->query($sql, $sqlTypes, $sqlValues);
            }

            // default-field? -> Textinhalt je Sprache auch in Tabelle data_dft schreiben
            // (keine verschlüsselten Daten, kein Passwort, kein Array)
            if ((int)$colDef['default_field'] === 1
                && !in_array($type, ['password', 'encrypted', 'json'], true)
                && $displayType !== 'password'
            ) {
                $defaultValues = [];
                foreach ($languageIds as $lang) {
                    $defaultValues[$lang] = mb_substr((string)$rowValues[$lang], 0, 255);
                }
            }

            // Arbeitsstand fortschreiben (rohe Eingaben; Passwörter nie - weder
            // Klartext noch Hash gehören in den Cache, siehe _writeRecordCache())
            if ($type === 'password' || $displayType === 'password') {
                $this->recordCache[$name] = [$type, ''];
            }
            else {
                $this->recordCache[$name] = [$type, $isMultilingual ? $rawValues : $valueRef];
            }
        }

        // Zurückgestellte Thumbnails jetzt erzeugen (finaler Speichervorgang)
        // --------------------
        // Uploads wurden bereits beim Sichern (secureUploads() / Einzelschritte)
        // physisch unter $storageId abgelegt und im Arbeitsstand mit
        // 'thumbnails_pending' markiert. Die Erzeugung erfolgt einmalig hier -
        // VOR checkIn()/promoteTmpFiles(), damit die entstehenden Thumbnail-
        // Metadaten mit umziehen und `data_fil`-Einträge erhalten.
        // $persist: bei einer Arbeitskopie muss das aktualisierte Arbeitsstand (Maße
        // der Quelldatei, neue Thumbnail-Einträge) zurückgeschrieben werden, denn
        // promoteTmpFiles() liest DEREN Arbeitsstand - auch wenn die Kopie unter ihrer
        // eigenen Id produktiv geht.
        //
        // AUSNAHME - vorhandener Datensatz ($tmpId != $recordId): die Dateien liegen
        // jetzt noch unter der Id der Arbeitskopie und werden beim checkIn auf die
        // produktive Id umbenannt. Ein Hintergrundprozess (ffmpeg), der hier startete,
        // würde seine Quelle unter der tmp-Id öffnen - oft erst, nachdem sie schon
        // umbenannt ist ("no such file") - und sein Ziel unter der tmp-Id ablegen, wo
        // es kein Trigger mehr findet. Deshalb werden die Thumbnails dort erst nach
        // dem checkIn erzeugt (writeDeferredThumbnails(), aus Dataset::save()), mit
        // den endgültigen Pfaden. Bei neuen Datensätzen (tmp-Id = record-Id) findet
        // kein Umzug statt.
        $this->deferredThumbnails = [];
        if ($tmpId > 0 && $tmpId !== $recordId) {
            foreach ($this->recordCache as $cacheName => $entry) {
                if (($entry[0] ?? '') !== 'file') continue;
                foreach ($this->fileEntries($entry) as $lang => $fileData) {
                    if (empty($fileData['thumbnails_pending'])) continue;
                    $languageId = ($lang === '') ? null : (int)$lang;
                    $this->deferredThumbnails[] = [(string)$cacheName, $languageId];
                    unset($fileData['thumbnails_pending']);
                    $this->setFileEntry($this->recordCache, (string)$cacheName, $languageId, $fileData);
                }
            }
        }
        else {
            $this->writePendingThumbnails($storageId, $schemaVirtual, ($tmpId > 0));
        }
                        
        // data_dft
        // --------------------
        // Gibt es ein Default-Feld? Wurde es geschrieben? Sonst die Id eintragen.
        // Ein verschlüsseltes Default-Feld (oder Passwort, Array) zählt nicht - sein
        // Klartext gehört nicht in data_dft, dort steht dann ebenfalls die Id.
        $flagDefaultAvailable = false;
        foreach ($schemaVirtual as $colDef) {
            if ((int)$colDef['default_field'] === 1
                && !in_array((string)$colDef['type'], ['password', 'encrypted', 'json'], true)
                && ($colDef['display_type'] ?? '') !== 'password'
            ) {
                $flagDefaultAvailable = true;
            }
        }
        if ($defaultValues !== null || !$flagDefaultAvailable) {
            $this->db->query("DELETE FROM `data_dft` WHERE `record_id` = ?", 'i', [$recordId]);
            foreach ($languageIds as $lang) {
                $defaultData = $defaultValues[$lang] ?? "ID " . $recordId;
                $sql = "INSERT INTO `data_dft` SET `language_id` = ?, `record_id` = ?, `data` = ?";
                $this->db->query($sql, 'iis', [$lang, $recordId, $defaultData]);
            }
        }
        // Datei-Löschmarkierungen ('deleted'-Flag aus der Eingabemaske, x-Button)
        // für den produktiven Cache bereinigen: der Eintrag wird geleert -
        // die eigentliche Löschung der produktiven Datei erfolgt beim checkIn
        // in promoteTmpFiles() anhand des (unveränderten) Arbeitsstand der Arbeitskopie
        // ('temporary'-Flags bleiben hier bewusst erhalten: promoteTmpFiles()
        //  benötigt sie noch für den Fall, dass die Arbeitskopie unter ihrer
        //  eigenen Id produktiv geht, und entfernt sie anschließend selbst)
        foreach ($this->recordCache as $cacheName => $entry) {
            if (($entry[0] ?? '') !== 'file') continue;
            foreach ($this->fileEntries($entry) as $langKey => $fileData) {
                if (empty($fileData['deleted'])) continue;
                if ($langKey === '') {
                    $this->recordCache[$cacheName] = ['file', [
                        'extension' => '', 'mime_type' => '', 'width' => 0,
                        'height' => 0, 'size' => 0, 'filename' => '', 'file_language_id' => null
                    ]];
                }
                else {
                    // mehrsprachig: die Sprache hat keine eigene Datei mehr -> Eintrag
                    // weg, es gilt der Fallback (_writeRecordCache())
                    $this->setFileEntry($this->recordCache, (string)$cacheName, (int)$langKey, null);
                }
            }
        }
        // Inhalts-Cache je Sprache schreiben (`record_cache`)
        $this->_writeRecordCache($recordId, $this->recordCache, $schemaVirtual, $langCtx, $encryptedCache);
        // Hinweise:
        // - Der Umzug der Dateien auf die produktive Id und das Erzeugen der
        //   `data_fil`-Einträge (aus den Datei-Metadaten des Arbeitsstands) erfolgt beim
        //   Produktivgehen der Arbeitskopie in
        //   TemporaryTrait::checkIn() -> promoteTmpFiles()
        // - Die Erneuerung der Volltext-Inhalte erfolgt separat über
        //   refreshAffectedFulltext() und muss NACH checkIn() aufgerufen werden
        //   (siehe Dataset::save()), da sie die zurückkopierten Relations der
        //   Arbeitskopie benötigt
    }

    /**
     * Sprachen, für die ein Upload-Feld Dateien entgegennimmt.
     *
     * @return array  [null] bei einsprachigen Feldern, sonst die language_ids
     */
    protected function _uploadLanguages(array $colDef, array $languageIds): array
    {
        if (!$this->cms->isMultilingualField($colDef) || empty($languageIds)) return [null];
        return array_map('intval', $languageIds);
    }

    /**
     * Ist eine Eingabe leer ("nicht übersetzt")?
     * Skalare: Leerstring; json: Array ohne nicht-leere Einträge.
     */
    protected function _isEmptyInput(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $v) {
                if (!is_string($v) || trim($v) !== '') return false;
            }
            return true;
        }
        return trim((string)$value) === '';
    }

    /**
     * Einzelschritt-Speicherung (Dataset::save() mit $stayTemporary = true):
     * Der Datensatz bleibt temporär, die (bereits geprüften und umgewandelten)
     * Inhalte werden nur im Arbeitsstand-Feld der Arbeitskopie zwischengespeichert -
     * bei mehrsprachigen Feldern je Sprache (language_id => Wert, leer = nicht
     * übersetzt; Dataset::save() hat die nicht übermittelten Sprachen zuvor aus dem
     * bisherigen Zwischenstand ergänzt).
     *
     * Ausnahmen:
     * - Datei-Uploads werden sofort physisch unter der Id der Arbeitskopie
     *   abgelegt und im Arbeitsstand mit 'temporary' = true markiert (eine Datei lässt
     *   sich nicht "cachen"; `data_fil` wird erst beim checkIn beschrieben)
     * - Relations wurden bereits in Dataset::save() (validateFieldValue ->
     *   createRelations) an die Arbeitskopie gebunden
     * - Passwörter werden nicht zwischengespeichert (kein Klartext, kein Hash im
     *   Cache) - sie können nur im finalen Speichervorgang geschrieben werden;
     *   verschlüsselte Felder liegen im Cache der Arbeitskopie als Geheimtext
     *   (_writeRecordCache())
     *
     * Geschrieben werden nur die Sprachzeilen, die sich ändern: bei einem Reiter-
     * wechsel die Sprache des Reiters - sobald aber ein einsprachiges Feld oder die
     * Hauptsprache betroffen ist, alle (Fallbacks der übrigen Sprachen).
     *
     * Beim finalen Speichern führt Dataset::save() die Zwischenstände mit den
     * aktuellen Eingaben zusammen und schreibt sie produktiv.
     *
     * @param array &$affectedFields  Felder, deren Inhalte sich ändern
     * @param array &$data            zu schreibende Inhalte (bereits umgewandelt)
     * @param array &$schemaVirtual   Schema mit virtuellen Feldern
     * @param int $tmpId              Id der temporären Arbeitskopie
     * @param array $tmpCache         bisheriger Zwischenstand der Arbeitskopie
     * @param array $langCtx          Sprachen der Instanz (Dataset::languageContext())
     */
    protected function saveTemporaryStep(array &$affectedFields, array &$data, array &$schemaVirtual, int $tmpId, array $tmpCache, array $langCtx = []): void
    {
        if ($tmpId < 1) {
            throw new \Exception('Error: saveTemporaryStep() called with $tmpId < 1.');
        }
        // bisherigen Zwischenstand als Basis
        $this->recordCache = $tmpCache;
        // Sammlung bereits verarbeiteter Parent-Fields (für Thumbnails)
        $this->processedParentFieldIds = [];
        // Sprachzeilen, die neu geschrieben werden müssen (null = alle)
        $primaryId = (int)($langCtx['primary_id'] ?? 0);
        $changedLanguages = [];
        $allLanguages = false;
        foreach ($affectedFields as $name) {
            // Nur virtuelle Felder werden zwischengespeichert
            // (inkl. Eingabefelder, die auf Meta-Feldern basieren - deren Werte
            //  werden erst beim finalen Speichern in die Spalten geschrieben)
            $colDef = $schemaVirtual[$name] ?? [];
            if (empty($colDef)) continue;
            $type = (string)($colDef['type'] ?? 'text');
            $displayType = (string)($colDef['display_type'] ?? '');
            // Volltext wird erst beim finalen Speichern generiert
            if ($type === 'fulltext') continue;
            // Relations hängen bereits an der Arbeitskopie (data_rel)
            if (in_array($type, $this->cms->relationTypes, true)) continue;
            // Passwörter nicht zwischenspeichern (siehe Methoden-Kommentar)
            if ($type === 'password' || $displayType === 'password') continue;
            if ($type === 'file') {
                $allLanguages = true;
                if ($displayType === 'upload') {
                    // Upload sofort an die Arbeitskopie binden
                    // (physisch + Arbeitsstand mit 'temporary'- und 'thumbnails_pending'-Flag;
                    //  Thumbnails werden erst beim finalen Speichern erzeugt;
                    //  ein evtl. gesetztes 'deleted'-Flag wird dabei überschrieben)
                    foreach ($this->_uploadLanguages($colDef, (array)($langCtx['language_ids'] ?? [])) as $lang) {
                        $this->_saveUploadField($tmpId, $name, $colDef, $schemaVirtual, $data, $lang);
                    }
                }
                // display_type 'thumbnail' wird ausschließlich automatisch
                // über writeThumbnails() befüllt
                continue;
            }
            // Wert (bereits umgewandelt, vgl. Dataset::validateFieldValue())
            // in den Zwischenstand übernehmen
            $value = $data[$name] ?? '';
            $previous = $this->recordCache[$name][1] ?? null;
            $this->recordCache[$name] = [$type, $value];
            // Welche Sprachzeilen ändern sich dadurch?
            $isMultilingual = $this->cms->isMultilingualField($colDef) && is_array($value);
            if (!$isMultilingual) {
                $allLanguages = true;
                continue;
            }
            foreach ($value as $lang => $langValue) {
                if (is_array($previous) && array_key_exists($lang, $previous) && $previous[$lang] === $langValue) continue;
                if ((int)$lang === $primaryId) $allLanguages = true;   // Fallbacks der anderen
                $changedLanguages[] = (int)$lang;
            }
        }
        // Zwischenstand in die `record_cache`-Zeilen der Arbeitskopie schreiben
        $this->_saveTmpData($tmpId, $this->recordCache, $allLanguages ? null : array_unique($changedLanguages));
    }

     /**
     * Verarbeitet die Datei-Eingabe eines einzelnen Upload-Feldes.
     *
     * Quelle der Datei (in dieser Reihenfolge):
     * 1. Echter Upload in $_FILES['data'][$name]  -> hat VORRANG
     * 2. Auf dem Server liegende, ausgewählte Datei: $data[$name]['serverfile']
     *    (relativ zu paths.upload_dir)
     * 3. Datei eines Imports: $data[$name]['importfile'] (absoluter Pfad, muss in
     *    paths.tmp_dir liegen - dort entpackt RecordImport die Importdatei), dazu
     *    optional $data[$name]['filename'] als ursprünglicher Dateiname
     * Liegt beides gleichzeitig vor, wird 'serverfile' verworfen.
     * Der Eintrag wird aus dem übergebenen $data gelesen (nicht aus $_REQUEST),
     * damit die Methode unabhängig von der Herkunft der Daten funktioniert
     * (Eingabemaske, Importer, CLI, API, ...).
     *
     * Ablauf: Mime-Typ / Extension prüfen (ungültige Datei wird sofort verworfen),
     * Datei physisch unter $storageId ablegen (Id der Arbeitskopie) und
     * $this->recordCache aktualisieren (Eintrag mit 'temporary' = true).
     *
     * NEU: Thumbnails werden hier NICHT mehr erzeugt. Der cache-Eintrag erhält
     * stattdessen 'thumbnails_pending' = true; die Erzeugung erfolgt erst beim
     * finalen Speichern über writePendingThumbnails() (siehe saveVirtualFields()).
     * Deshalb bleiben 'width', 'height' und 'data' hier zunächst leer/0 -
     * diese Angaben liefert ggf. erst der Prozessor bei der Thumbnail-Erzeugung.
     *
     * Es wird KEIN `data_fil`-Eintrag geschrieben: dieser entsteht erst beim
     * Produktivgehen in promoteTmpFiles() aus den cache-Metadaten.
     *
     * @param int $storageId         Id, unter der die Datei physisch abgelegt wird
     * @param string $name           Feldname
     * @param array $colDef          Feld-Definition aus dem virtuellen Schema
     * @param array &$schemaVirtual  Schema mit virtuellen Feldern
     * @param array $data            Zu speichernde Inhalte (aus Dataset::save());
     *                               ungeprüft, daher defensiver Zugriff
     * @param int|null $languageId   mehrsprachiges Feld: Sprache der Datei
     *                               (Formularfeld data[name][{language_id}]); null bei
     *                               einsprachigen Feldern
     * @return bool                  true: Datei verarbeitet,
     *                               false: keine (neue) Datei angegeben
     */
    protected function _saveUploadField(int $storageId, string $name, array $colDef, array &$schemaVirtual, array $data = [], ?int $languageId = null): bool
    {
        $fieldId = (int)$colDef['field_id'];
        if (empty($fieldId) || $storageId < 1) return false;
        // Sicherstellen, dass jedes Upload-Feld pro Request nur einmal verarbeitet wird
        $processedKey = $name . ($languageId === null ? '' : '.' . $languageId);
        if (isset($this->processedUploadFields[$processedKey])) return false;
        $label = $colDef['label'] ?? $name;
        if ($languageId !== null) $label = $this->cms->textOrSnippet($label) . ' | ' . $languageId;

        // Quelle ermitteln
        // --------------------
        // Upload-Datei hat Vorrang: liegt ein $_FILES-Eintrag vor (auch fehlerhaft,
        // aber nicht UPLOAD_ERR_NO_FILE), wird eine ausgewählte Server-Datei verworfen.
        // Mehrsprachig: $_FILES['data'][...][$name][$languageId]
        $filesInfo = [];
        foreach (['error', 'size', 'tmp_name', 'name', 'type'] as $key) {
            $item = $_FILES['data'][$key][$name] ?? null;
            if ($languageId !== null) $item = is_array($item) ? ($item[$languageId] ?? null) : null;
            $filesInfo[$key] = is_array($item) ? null : $item;
        }
        $fileError  = (int)($filesInfo['error'] ?? UPLOAD_ERR_NO_FILE);
        $hasUpload  = ($fileError !== UPLOAD_ERR_NO_FILE);
        // Server-Datei aus den übergebenen Inhalten
        // ($data[$name] ist noch ungeprüft und kann jeden Typ haben)
        $fieldValue = $data[$name] ?? null;
        if ($languageId !== null) $fieldValue = is_array($fieldValue) ? ($fieldValue[$languageId] ?? null) : null;
        $serverFile = is_array($fieldValue) ? trim((string)($fieldValue['serverfile'] ?? '')) : '';
        $importFile = is_array($fieldValue) ? trim((string)($fieldValue['importfile'] ?? '')) : '';

        $isUploaded = false;
        $deleteServerFile = '';
        if ($hasUpload) {
            // ---- 1. Datei aus File-Upload (Vorrang, $serverFile wird verworfen) ----
            // Übertragungsfehler (z. B. upload_max_filesize überschritten)? Dann Fehler
            if ($fileError !== UPLOAD_ERR_OK) {
                $this->createFieldError('error_file_upload', $name, $label);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            // Hochgeladene Datei leer? Dann Fehler
            if (empty($filesInfo['size'])) {
                $this->createFieldError('error_file_empty', $name, $label);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            $tmpFile = (string)($filesInfo['tmp_name'] ?? '');
            // Upload-Datei bereits verarbeitet oder nicht (mehr) vorhanden? Dann überspringen
            // (z. B. wenn im selben Request nach einem Fehlversuch erneut gespeichert wird;
            //  die Server-Datei bleibt auch dann verworfen - der Upload hatte Vorrang)
            if ($tmpFile === '' || !is_uploaded_file($tmpFile)) return false;
            // Ursprünglicher Dateiname, gegen Injections gesäubert
            // (Pfadanteile und Steuerzeichen entfernen, Länge begrenzen)
            $fileOriginName = basename(str_replace('\\', '/', (string)($filesInfo['name'] ?? '')));
            $isUploaded = true;
        }
        elseif ($serverFile !== '') {
            // ---- 2. Datei im Upload-Verzeichnis (Server-Datei) ----
            // Pfad normalisieren. (Wirft Exception wenn ungültig.)
            $relativePath = DirectoryHelpers::checkPath($serverFile);
            $tmpFile = $this->config->get('paths.upload_dir') . '/' . $relativePath;
            if (!is_file($tmpFile)) {
                // Ausgewählte Datei existiert nicht (mehr)
                $this->createFieldError('error_file_move_uploaded', $name, $label);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            $fileOriginName = basename(str_replace('\\', '/', $tmpFile));
            // Schalter "nach erfolgreichem Übernehmen löschen": gelöscht wird erst,
            // wenn der Datensatz gespeichert ist (promoteTmpFiles())
            if (!empty($fieldValue['serverfile_delete'])) $deleteServerFile = $relativePath;
        }
        elseif ($importFile !== '') {
            // ---- 3. Datei aus einem entpackten Import (RecordImport) ----
            // Nur innerhalb von paths.tmp_dir - ein anderer Pfad wäre ein Zugriff
            // auf beliebige Dateien des Servers
            $tmpDir = realpath((string)$this->config->get('paths.tmp_dir'));
            $real = realpath($importFile);
            if ($tmpDir === false || $real === false || !is_file($real)
                || !str_starts_with($real, $tmpDir . DIRECTORY_SEPARATOR)
            ) {
                $this->createFieldError('error_file_move_uploaded', $name, $label);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            $tmpFile = $real;
            $fileOriginName = basename(str_replace('\\', '/', trim((string)($fieldValue['filename'] ?? ''))));
            if ($fileOriginName === '') $fileOriginName = basename($real);
        }
        else {
            // Keine (neue) Datei angegeben? Dann überspringen
            // (Pflichtfeld wird beim finalen Speichervorgang geprüft)
            return false;
        }

        // Feld als verarbeitet markieren (siehe oben)
        $this->processedUploadFields[$processedKey] = true;
        // Dateiname für Speicherung aufbereiten
        $fileOriginName = DirectoryHelpers::checkPath($fileOriginName);
        // Mime-Typ serverseitig ermitteln
        // (die Client-Angabe in $_FILES['data']['type'] ist nicht vertrauenswürdig)
        $fileMime = '';
        if ($finfo = finfo_open(FILEINFO_MIME_TYPE)) {
            $fileMime = (string)(finfo_file($finfo, $tmpFile) ?: '');
        }
        if ($fileMime === '') {
            $fileMime = strtolower((string)($filesInfo['type'] ?? ''));
        }
        // Dateierweiterung muss in Kleinbuchstaben umgewandelt werden
        // (zusätzlich säubern; Fallback über Mime-Typ, falls keine Extension vorhanden)
        $fileExtension = strtolower((string)pathinfo($fileOriginName, PATHINFO_EXTENSION));
        $fileExtension = (string)preg_replace('/[^a-z0-9]/', '', $fileExtension);
        if ($fileExtension === '') {
            $fileExtension = $this->mimeToExtension[$fileMime] ?? 'bin';
        }
        // Ausführbare / gefährliche Extensions grundsätzlich ablehnen
        if (in_array($fileExtension, $this->forbiddenExtensions, true)) {
            $this->createFieldError('error_file_denied', $name, $label);
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }
        // Frontend: kein aktiver Inhalt (HTML, SVG, XML, JavaScript)
        if ($this->untrustedUploads && MediaAccess::isActiveContent($fileMime, $fileExtension)) {
            $this->createFieldError('error_file_denied', $name, $label);
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }
        // Mime-Typ / Extension-Konsistenz für die wichtigsten Bildtypen prüfen
        // (siehe $mimeExtensionMap): ein serverseitig als z. B. image/jpeg erkannter
        // Inhalt darf nicht mit fremder Endung (.zip, .png, ...) gespeichert werden.
        // Die Datei würde sonst unter irreführendem Namen im Media-Verzeichnis liegen.
        // (eigene Meldung mit dem erkannten Typ - "nicht erlaubt" führt sonst in die Irre,
        //  z. B. bei einem als .webp gespeicherten PNG)
        if (isset($this->mimeExtensionMap[$fileMime])
            && !in_array($fileExtension, $this->mimeExtensionMap[$fileMime], true)
        ) {
            $this->createFieldError('error_file_mismatch', $name, $label, "{$fileMime}, .{$fileExtension}");
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }
        $allowed = $this->fileTypeAllowed($colDef, $fileMime, $fileExtension);
        // wenn kein Treffer dann Fehlermeldung (ungültige Datei wird sofort verworfen)
        if (!$allowed) {
            $this->createFieldError('error_file_denied', $name, $label);
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }
        // Ablage {instance_id}/{field_id} (Cms::mediaFolder())
        $fileFolder = $this->cms->mediaFolder($fieldId);
        // Datei wird zunächst unter der Id der Arbeitskopie abgelegt
        // (Umzug auf die produktive Id erst beim checkIn, siehe promoteTmpFiles());
        // bei mehrsprachigen Feldern mit der Sprache im Namen (Cms::mediaBaseName())
        $fileBaseName = $this->cms->mediaBaseName($storageId, $languageId);
        // Zieldatei:
        $filePath = "{$fileFolder}/{$fileBaseName}.{$fileExtension}";
        // tmp-Datei Datei verschieben und Rechte setzen
        $mediaDir = $this->config->get('paths.media_dir');

        if (!$fileSize = $this->_moveUploadedFile($tmpFile, $mediaDir, $filePath, $isUploaded)) {
            $this->createFieldError('error_file_move_uploaded', $name, $label);
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }

        // Vorhandene alte Datei mit abweichender Extension löschen
        // (beim mehrfachen Ersetzen innerhalb der Arbeitskopie kann sich die
        //  Extension ändern; die bisherige Extension steht im 'temporary'-
        //  cache-Eintrag der Arbeitskopie. Einträge OHNE 'temporary'-Flag
        //  beschreiben die Datei des produktiven Originals - diese wird erst
        //  beim checkIn in promoteTmpFiles() ersetzt)
        $previousEntry = $this->fileEntry((array)($this->recordCache[$name] ?? []), $languageId);
        if (!empty($previousEntry['temporary'])) {
            $previousExtension = (string)($previousEntry['extension'] ?? '');
            if ($previousExtension !== '' && $previousExtension !== $fileExtension) {
                @unlink("{$mediaDir}/{$fileFolder}/{$fileBaseName}.{$previousExtension}");
            }
        }
        // Metadaten der Datei (im Fehlerfall bleibt vorhandener Eintrag und vorhandene Datei bestehen)
        $fileData = [
            'width' => 0,   // wird ggfs. bei der Thumbnail-Erzeugung nachgetragen
            'height' => 0,  // " "
            'data' => '{}', // " "
            'size' => $fileSize,
            'mime_type' => $fileMime,
            'extension' => $fileExtension,
            'filename'  => $fileOriginName,  // ursprünglicher Dateiname, gegen Injections gesäubert
            'field_id'  => $fieldId,         // für Datei-Pfad / `data_fil`-Eintrag beim checkIn
            'file_language_id' => $languageId, // Sprache der Datei (null: einsprachig)
            'temporary' => true,
            // Thumbnails werden erst beim finalen Speichern erzeugt
            // (writePendingThumbnails(), aufgerufen aus saveVirtualFields())
            'thumbnails_pending' => true
        ];
        // Server-Datei nach dem Speichern löschen (Pfad relativ zu paths.upload_dir)
        if ($deleteServerFile !== '') $fileData['serverfile_delete'] = $deleteServerFile;
        // Datei-Eintrag setzen (überschreibt auch ein evtl. 'deleted'-Flag
        // einer zuvor per x-Button entfernten Datei)
        // KEIN `data_fil`-Eintrag für die Arbeitskopie: dieser entsteht erst
        // beim Produktivgehen in promoteTmpFiles() aus diesen Datei-Metadaten des Arbeitsstands
        $this->setFileEntry($this->recordCache, $name, $languageId, $fileData);
        return true;
    }

    /**
     * Erzeugt die beim Sichern der Uploads zurückgestellten Thumbnails.
     *
     * Aufruf beim FINALEN Speichern (saveVirtualFields()) - also einmal pro
     * Datensatz, nicht bei jedem Zwischenschritt. Verarbeitet werden alle
     * Datei-Einträge des cache, die von _saveUploadField() mit
     * 'thumbnails_pending' = true markiert wurden (auch solche aus früheren
     * Einzelschritten).
     *
     * Arbeitet auf $this->recordCache:
     * - erzeugt die Thumbnails rekursiv über writeThumbnails() (deren Einträge
     *   landen ebenfalls im recordCache, mit 'temporary'-Flag)
     * - trägt die vom Prozessor gelieferten Infos zur QUELL-Datei nach
     *   ('width', 'height', 'data')
     * - entfernt das 'thumbnails_pending'-Flag
     *
     * Muss VOR checkIn()/promoteTmpFiles() laufen, damit die Thumbnail-Metadaten
     * mit umziehen und `data_fil`-Einträge erhalten.
     *
     * @param int $storageId         Id, unter der die Dateien physisch liegen
     *                               (Arbeitskopie bzw. produktiver Datensatz)
     * @param array &$schemaVirtual  Schema mit virtuellen Feldern
     * @param bool $persist          true: aktualisierten cache in Datensatz
     *                               $storageId zurückschreiben. Nötig, wenn die
     *                               Dateien an einer Arbeitskopie mit produktivem
     *                               Original hängen: promoteTmpFiles() liest den
     *                               cache DER ARBEITSKOPIE.
     * @return bool                  true, wenn Thumbnails verarbeitet wurden
     */
    protected function writePendingThumbnails(int $storageId, array &$schemaVirtual, bool $persist = true): bool
    {
        if ($storageId < 1) return false;
        $mediaDir = $this->config->get('paths.media_dir');
        // Einträge zuerst sammeln: writeThumbnails() verändert $this->recordCache
        // (je Sprache ein Eintrag bei mehrsprachigen Datei-Feldern)
        $pending = [];
        foreach ($this->recordCache as $cacheName => $entry) {
            if (($entry[0] ?? '') !== 'file') continue;
            foreach ($this->fileEntries($entry) as $lang => $fileData) {
                if (empty($fileData['thumbnails_pending'])) continue;
                $pending[] = [(string)$cacheName, ($lang === '') ? null : (int)$lang];
            }
        }
        if (empty($pending)) return false;

        $processed = false;
        foreach ($pending as [$cacheName, $languageId]) {
            $fileData = $this->fileEntry((array)($this->recordCache[$cacheName] ?? []), $languageId);
            // Flag in jedem Fall entfernen (keine Wiederholung im selben Vorgang)
            unset($fileData['thumbnails_pending']);
            $this->setFileEntry($this->recordCache, $cacheName, $languageId, $fileData);

            $colDef = $schemaVirtual[$cacheName] ?? [];
            if (empty($colDef)) continue;
            $fieldId   = (int)($fileData['field_id'] ?? ($colDef['field_id'] ?? 0));
            $extension = (string)($fileData['extension'] ?? '');
            if ($fieldId < 1 || $extension === '') continue;
            // Quell-Datei (unter der storage-Id abgelegt)
            $filePath = $this->cms->mediaPath($fieldId, $storageId, $languageId, $extension);
            if (!is_file($mediaDir . '/' . $filePath)) continue; // Datei nicht (mehr) vorhanden

            // Erzeuge Thumbnails rekursiv, ausgehend von diesem Upload-Feld
            $info = $this->writeThumbnails($storageId, $fieldId, $filePath, $fileData, $schemaVirtual, false, $languageId);
            $processed = true;
            if (!empty($info)) {
                // die Thumbnailgenerierung hatte Zugriff auf Tools und liefert ggf
                // Werte zu den Schlüsseln 'width', 'height', 'data'.
                // `data` (später in `data_fil`) JSON-Feld - keinen Leerstring speichern!
                if ((string)($info['data'] ?? '') === '') $info['data'] = '{}';
                $fileData = $this->fileEntry((array)($this->recordCache[$cacheName] ?? []), $languageId);
                $fileData['width']  = (int)$info['width'];
                $fileData['height'] = (int)$info['height'];
                $fileData['data']   = (string)$info['data'];
                $this->setFileEntry($this->recordCache, $cacheName, $languageId, $fileData);
            }
        }

        if ($persist) {
            // Arbeitsstand der Arbeitskopie fortschreiben ('temporary'- und 'deleted'-Flags
            // bleiben erhalten, promoteTmpFiles() benötigt sie noch)
            $this->_saveTmpData($storageId, $this->recordCache);
        }
        return $processed;
    }

    /**
     * Zurückgestellte Thumbnails eines VORHANDENEN Datensatzes erzeugen - nach dem
     * checkIn, wenn die Quelldateien unter der produktiven Id liegen (siehe
     * saveVirtualFields()). Aufgerufen aus Dataset::save().
     *
     * Wie promoteBackgroundThumbnails() für den Trigger: es gibt keine Arbeitskopie
     * mehr, die `data_fil`-Zeilen der Thumbnails werden hier selbst geschrieben.
     * Bild-Thumbnails entstehen sofort, Videos wie konfiguriert im Hintergrund -
     * mit Quelle und Ziel unter der produktiven Id. Die Quell-Datei bekommt die vom
     * Konverter ermittelten Maße (width, height, data) nachgetragen.
     *
     * @param int   $recordId       produktiver Datensatz
     * @param array $schemaVirtual  Schema des Bereichs
     */
    public function writeDeferredThumbnails(int $recordId, array &$schemaVirtual): void
    {
        $pending = $this->deferredThumbnails;
        $this->deferredThumbnails = [];
        if ($recordId < 1 || empty($pending)) return;
        $mediaDir = $this->config->get('paths.media_dir');
        $written = false;

        foreach ($pending as [$name, $languageId]) {
            $colDef = $schemaVirtual[$name] ?? [];
            $fieldId = (int)($colDef['field_id'] ?? 0);
            if ($fieldId < 1) continue;
            $fn = $this->cms->resolveVirtualField($name);
            $fieldNameId = (int)($fn['fieldname_id'] ?? 0);

            // Quell-Datei: ihre `data_fil`-Zeile hat promoteTmpFiles() gerade geschrieben
            $this->db->pushResult();
            $this->db->query(
                'SELECT * FROM `data_fil` WHERE `record_id` = ? AND `field_id` = ? AND `file_language_id` <=> ? LIMIT 1',
                'iii', [$recordId, $fieldId, $languageId]
            );
            $parentData = $this->db->fetch() ?? [];
            $this->db->popResult();
            $extension = (string)($parentData['extension'] ?? '');
            if ($extension === '') continue;
            $parentPath = $this->cms->mediaPath($fieldId, $recordId, $languageId, $extension);
            if (!is_file($mediaDir . '/' . $parentPath)) continue;

            // Thumbnails erzeugen (writeThumbnails() arbeitet auf $this->recordCache)
            $this->clearRecordCache();
            $info = $this->writeThumbnails($recordId, $fieldId, $parentPath, $parentData, $schemaVirtual, false, $languageId);

            foreach ($this->recordCache as $thumbName => $entry) {
                if (!is_array($entry) || ($entry[0] ?? '') !== 'file') continue;
                foreach ($this->fileEntries($entry) as $langKey => $fileData) {
                    $lang = ($langKey === '') ? null : (int)$langKey;
                    $thumbFieldId = (int)($fileData['field_id'] ?? 0);
                    $thumbNameId  = (int)($fileData['fieldname_id'] ?? 0);
                    if ($thumbFieldId < 1 || $thumbNameId < 1) continue;
                    // Alte Thumbnail-Datei mit anderer Extension nicht liegen lassen
                    $previous = $this->_getStoredExtension($recordId, $thumbNameId, $lang);
                    if ($previous !== '' && $previous !== (string)($fileData['extension'] ?? '')) {
                        @unlink("{$mediaDir}/" . $this->cms->mediaPath($thumbFieldId, $recordId, $lang, $previous));
                    }
                    $this->_writeFileRow($recordId, $thumbNameId, $thumbFieldId, $fileData, $lang);
                    if ($lang !== null) $this->_syncFileRows($recordId, $thumbNameId, $thumbFieldId);
                    $written = true;
                }
            }

            // Maße der Quell-Datei nachtragen (vom Konverter ermittelt)
            if (!empty($info) && $fieldNameId > 0) {
                $data = (string)($info['data'] ?? '');
                if ($data === '') $data = '{}';
                $this->db->query(
                    'UPDATE `data_fil` SET `width` = ?, `height` = ?, `data` = ?'
                    . ' WHERE `record_id` = ? AND `fieldname_id` = ? AND `file_language_id` <=> ?',
                    'iisiii', [(int)$info['width'], (int)$info['height'], $data, $recordId, $fieldNameId, $languageId]
                );
                $written = true;
            }
        }

        if ($written) {
            // Inhalts-Cache verwerfen: Cms::fetch() baut ihn beim nächsten Zugriff
            // aus den data_*-Zeilen neu auf (mit den Thumbnails)
            $this->db->query("DELETE FROM `record_cache` WHERE `record_id` = ?", 'i', [$recordId]);
        }
    }

    /**
     * Alle Thumbnails eines produktiven Datensatzes neu erzeugen - für das Werkzeug
     * "Thumbnails neu erzeugen" (Tools -> Daten, ToolThumbnails), etwa nach
     * geänderten Konverter-Einstellungen oder einem neuen Thumbnail-Feld.
     *
     * Quelle ist jede Datei des Datensatzes in einem Feld, von dem ein Thumbnail-Feld
     * abhängt (parent_field_id) und das nicht selbst ein Thumbnail ist - die Kaskade
     * darunter erledigt writeThumbnails() rekursiv. Je Sprachversion der Datei
     * (file_language_id) ein Durchlauf. Die Erzeugung selbst ist dieselbe wie nach
     * dem Speichern (writeDeferredThumbnails()): Bilder sofort, Videos wie
     * konfiguriert im Hintergrund; der record_cache wird verworfen.
     *
     * @param int   $recordId       produktiver Datensatz
     * @param array $schemaVirtual  Schema des Bereichs
     * @return int  Anzahl der Quelldateien, für die Thumbnails erzeugt wurden
     */
    public function regenerateThumbnails(int $recordId, array &$schemaVirtual): int
    {
        if ($recordId < 1) return 0;
        // Quellfelder: Datei-Felder, auf die ein Thumbnail-Feld zeigt
        $parentIds = [];
        foreach ($schemaVirtual as $colDef) {
            if ((string)($colDef['type'] ?? '') !== 'file') continue;
            if ((string)($colDef['display_type'] ?? '') !== 'thumbnail') continue;
            $parentIds[(int)($colDef['parent_field_id'] ?? 0)] = true;
        }
        $sources = [];
        foreach ($schemaVirtual as $name => $colDef) {
            if ((string)($colDef['type'] ?? '') !== 'file') continue;
            if ((string)($colDef['display_type'] ?? '') === 'thumbnail') continue;
            if (isset($parentIds[(int)($colDef['field_id'] ?? 0)])) $sources[(string)$name] = (int)$colDef['field_id'];
        }
        if (empty($sources)) return 0;

        // Dateien des Datensatzes in diesen Feldern (eine je Sprachversion)
        $this->deferredThumbnails = [];
        $this->db->pushResult();
        foreach ($sources as $name => $fieldId) {
            $this->db->query(
                "SELECT DISTINCT `file_language_id` FROM `data_fil` WHERE `record_id` = ? AND `field_id` = ? AND `extension` != ''",
                'ii', [$recordId, $fieldId]
            );
            foreach ($this->db->fetchAll() as $languageId) {
                $this->deferredThumbnails[] = [$name, ($languageId === null) ? null : (int)$languageId];
            }
        }
        $this->db->popResult();
        $count = count($this->deferredThumbnails);
        if ($count > 0) $this->writeDeferredThumbnails($recordId, $schemaVirtual);
        return $count;
    }

    /**
     * Volltext eines gespeicherten Datensatzes erneuern.
     *
     * 1. Die Volltextfelder SEINES Bereichs, in die eines der geänderten Felder
     *    eingeht (gesammelt in saveVirtualFields()), und die mit Quellen über ein
     *    link- oder relation-Feld (die Kanten können sich geändert haben), dazu der
     *    Standard-Volltext. Ändert sich ein Zieldatensatz einer relation, erfährt
     *    der Volltext davon nichts (bewusst: ein Schlagwort kann an Tausenden
     *    Datensätzen hängen) - das holt die Zeitplaner-Aufgabe fulltext nach.
     * 2. Ist er Inhalt eines link-Feldes (LinkTrait), auch der Volltext seines
     *    Besitzers: dessen Volltextfelder mit Quellen über dieses link-Feld und
     *    dessen Standard-Volltext.
     *
     * Muss NACH checkIn() aufgerufen werden, da die Relations der Arbeitskopie
     * erst dort auf den produktiven Datensatz zurückkopiert werden.
     *
     * @param int $recordId  Id des produktiven Datensatzes
     */
    public function refreshAffectedFulltext(int $recordId): void
    {
        $affectedTextFieldIds = array_map('intval', $this->affectedTextFieldIds);
        // Sammlung zurücksetzen (einmalige Verarbeitung je Speichervorgang)
        $this->affectedTextFieldIds = [];
        $this->db->query("SELECT `area_id` FROM `record` WHERE `record_id` = ?", 'i', [$recordId]);
        $areaId = (int)($this->db->fetchNum()[0] ?? 0);
        if ($areaId < 1) return;

        // 1. eigener Bereich
        $in = $affectedTextFieldIds ? implode(',', $affectedTextFieldIds) : '0';
        $this->db->query(
            "SELECT DISTINCT `r`.`fulltext_field_id` FROM `rel_fulltext_text` `r`"
            . " INNER JOIN `field` `f` ON `f`.`field_id` = `r`.`fulltext_field_id`"
            . " WHERE `f`.`area_id` = ? AND (`r`.`rel_field_id` IS NOT NULL OR `r`.`text_field_id` IN ({$in}))",
            'i', [$areaId]
        );
        foreach (array_map('intval', $this->db->fetchAll()) as $fulltextFieldId) {
            $this->refreshFulltext($fulltextFieldId, $recordId);
        }
        $this->refreshStandardFulltext($areaId, $recordId);

        // 2. Besitzer über ein link-Feld
        $this->db->query(
            "SELECT `d`.`src_id`, `d`.`field_id`, `o`.`area_id` FROM `data_rel` `d`"
            . " INNER JOIN `field` `l` ON `l`.`field_id` = `d`.`field_id` AND `l`.`type` = 'link'"
            . " INNER JOIN `record` `o` ON `o`.`record_id` = `d`.`src_id` AND `o`.`tmp` = 0"
            . " WHERE `d`.`dest_id` = ?",
            'i', [$recordId]
        );
        foreach ($this->db->fetchAll() as $owner) {
            $this->db->query(
                "SELECT DISTINCT `fulltext_field_id` FROM `rel_fulltext_text` WHERE `rel_field_id` = ?",
                'i', [(int)$owner['field_id']]
            );
            foreach (array_map('intval', $this->db->fetchAll()) as $fulltextFieldId) {
                $this->refreshFulltext($fulltextFieldId, (int)$owner['src_id']);
            }
            $this->refreshStandardFulltext((int)$owner['area_id'], (int)$owner['src_id']);
        }
    }

    // Volltext-Standardfeld `fulltext`
    // ====================
    // Jeder Datensatzbereich (Area::CONTENT_TYPES) hat ihn, ohne Zeile in `field`: Der
    // Name ist reserviert (Field::FULLTEXT) und steht fest in `fieldname` (Typ
    // 'fulltext'), die Zeilen in `data_ftx` haben field_id NULL. Quellen sind immer
    // alle Felder des Bereichs vom Typ text, chars, json und file (Dateiname, ohne
    // Thumbnails) - standardFulltextSources(). select() filtert über den Feldnamen
    // und braucht die Felddefinition nicht; die Suchmasken bieten ihn von selbst an.

    /** Name des Volltext-Standardfelds (reserviert) */
    public const STANDARD_FULLTEXT = 'fulltext';

    /**
     * fieldname_id des Standardfelds - legt den Eintrag in `fieldname` an, falls er
     * fehlt (Field::cleanupFieldname() lässt ihn stehen).
     */
    public function standardFulltextFieldnameId(): int
    {
        $resolved = $this->cms->resolveVirtualField(self::STANDARD_FULLTEXT);
        if ($resolved !== null) return (int)$resolved['fieldname_id'];
        $this->db->pushResult();
        try {
            $this->db->query("INSERT IGNORE INTO `fieldname` (`name`, `type`) VALUES (?, 'fulltext')", 's', [self::STANDARD_FULLTEXT]);
        }
        finally {
            $this->db->popResult();
        }
        $this->cms->clearFieldCaches();
        return (int)($this->cms->resolveVirtualField(self::STANDARD_FULLTEXT)['fieldname_id'] ?? 0);
    }

    /**
     * Quellfelder des Standardfelds: Text-, Zeichen-, JSON- und Upload-Felder des
     * Bereichs (keine Thumbnails) und, über seine link-Felder, die der Zielbereiche
     * (fest verknüpfte Inhalte, eine Ebene) - Format wie `rel_fulltext_text`.
     */
    public function standardFulltextSources(int $areaId): array
    {
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT `field_id` AS `text_field_id`, 0 AS `rel_field_id` FROM `field`"
                . " WHERE `area_id` = ? AND `type` IN ('text', 'chars', 'json', 'file')"
                . " AND NOT (`type` = 'file' AND `display_type` = 'thumbnail')"
                . " ORDER BY `position`, `field_id`",
                'i', [$areaId]
            );
            $rows = [];
            while ($row = $this->db->fetch()) $rows[] = $row;
            $this->db->query(
                "SELECT `t`.`field_id` AS `text_field_id`, `l`.`field_id` AS `rel_field_id` FROM `field` `l`"
                . " INNER JOIN `rel_field_area` `ra` ON `ra`.`field_id` = `l`.`field_id`"
                . " INNER JOIN `field` `t` ON `t`.`area_id` = `ra`.`area_id`"
                . " WHERE `l`.`area_id` = ? AND `l`.`type` = 'link'"
                . " AND `t`.`type` IN ('text', 'chars', 'json', 'file')"
                . " AND NOT (`t`.`type` = 'file' AND `t`.`display_type` = 'thumbnail')"
                . " ORDER BY `l`.`position`, `t`.`position`, `t`.`field_id`",
                'i', [$areaId]
            );
            while ($row = $this->db->fetch()) $rows[] = $row;
            return $rows;
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Standard-Volltext eines Datensatzes bzw. aller Datensätze eines Bereichs neu
     * aufbauen - nach dem Speichern, nach geänderten Feldern des Bereichs, per
     * Stapelverarbeitung.
     */
    public function refreshStandardFulltext(int $areaId, ?int $recordId = null): void
    {
        $sources = $this->standardFulltextSources($areaId);
        $fieldNameId = $this->standardFulltextFieldnameId();
        if ($fieldNameId < 1) return;
        $recordIds = [$recordId];
        if ($recordId === null) {
            $this->db->query("SELECT `record_id` FROM `record` WHERE `area_id` = ? AND `tmp` = 0", 'i', [$areaId]);
            $recordIds = array_map('intval', $this->db->fetchAll());
        }
        foreach ($recordIds as $id) {
            if (count($recordIds) > 1) set_time_limit(300);
            if (empty($sources)) {
                $this->db->query("DELETE FROM `data_ftx` WHERE `record_id` = ? AND `fieldname_id` = ?", 'ii', [$id, $fieldNameId]);
                continue;
            }
            $this->_refreshFulltext(null, (int)$id, $sources, $fieldNameId);
        }
    }

    /**
     * Überträgt die Dateien einer temporären Arbeitskopie auf den produktiven
     * Datensatz und erzeugt dabei die `data_fil`-Einträge aus den Metadaten
     * des cache-Feldes der Arbeitskopie.
     *
     * Wird von TemporaryTrait::checkIn() aufgerufen, wenn die Arbeitskopie
     * produktiv geht (analog zum Zurückkopieren der Relations). Auch beim
     * Produktivgehen unter der eigenen Id (neuer Datensatz / Kopie:
     * $tmpId === $recordId) - dann entfällt der physische Umzug.
     *
     * Verarbeitung je Datei-Eintrag im cache der Arbeitskopie:
     * 1. Flag 'deleted' (x-Button in der Eingabemaske): eine ggf. am produktiven
     *    Datensatz hängende Datei und deren `data_fil`-Eintrag werden gelöscht
     * 2. Flag 'temporary' (neue / ersetzte Datei der Arbeitskopie): die physische
     *    Datei wird auf die produktive Id umbenannt (eine alte produktive Datei
     *    und deren Eintrag werden ersetzt; die alte Extension kann abweichen)
     *    und der `data_fil`-Eintrag aus den cache-Metadaten erzeugt.
     *    Erfasst werden ALLE 'temporary'-Einträge - auch solche aus früheren
     *    Einzelschritten (mehrschrittiges Updaten, bevor der Datensatz final
     *    produktiv geht).
     * 3. Einträge ohne Flags beschreiben die unveränderte Datei des produktiven
     *    Originals - nichts zu tun
     *
     * Abschließend werden die 'temporary'-Flags im cache des produktiven
     * Datensatzes entfernt (die Dateien sind nun produktiv).
     *
     * Vorbereitung für mehrstufiges Speichern: Wenn künftig weitere Feldtypen aus dem cache
     * heraus umgezogen werden sollen, kann das hier zentral ergänzt werden.
     *
     * Hinweise:
     * - Muss innerhalb von checkIn() VOR dem Löschen der Arbeitskopie erfolgen
     *   (liest deren cache)
     * - Datei-Operationen sind nicht transaktional: schlägt der Speichervorgang
     *   danach noch fehl (Rollback), sind die produktiven Dateien bereits ersetzt
     *
     * @param int $tmpId     Id der temporären Arbeitskopie
     * @param int $recordId  Id des produktiven Datensatzes (kann $tmpId sein)
     */
    protected function promoteTmpFiles(int $tmpId, int $recordId): void
    {
        if ($tmpId < 1 || $recordId < 1) return;
        $mediaDir = $this->config->get('paths.media_dir');
        $this->db->pushResult();
        try {
            $tmpCache = $this->_loadTmpData($tmpId);
            // Mehrsprachige Datei-Felder, deren `data_fil`-Zeilen am Ende je Sprache
            // abgeglichen werden (Fallback-Zeilen): fieldname_id => field_id
            $syncFields = [];
            foreach ($tmpCache as $name => $entry) {
                if (($entry[0] ?? '') !== 'file') continue;
                if (!$fn = $this->cms->resolveVirtualField((string)$name)) continue;
                $fieldNameId = (int)$fn['fieldname_id'];
                $entries = $this->fileEntries($entry);
                $isMultilingual = !isset($entries['']);

                foreach ($entries as $langKey => $fileData) {
                    $languageId = ($langKey === '') ? null : (int)$langKey;
                    $fieldId = (int)($fileData['field_id'] ?? 0);
                    if ($isMultilingual && $fieldId > 0) $syncFields[$fieldNameId] = $fieldId;

                    // 1. Löschmarkierung ('deleted'-Flag aus der Eingabemaske, x-Button)
                    // --------------------
                    if (!empty($fileData['deleted'])) {
                        // neue Kopie / neuer Datensatz: kein produktiver Bestand vorhanden
                        if ($tmpId === $recordId) continue;
                        // produktive Datei DIESER Sprache samt Zeile(n) entfernen
                        // (bei mehrsprachigen Feldern nur die eigene Datei der Sprache;
                        //  Fallback-Zeilen anderer Sprachen bereinigt _syncFileRows())
                        $sql = 'SELECT DISTINCT `field_id`, `extension` FROM `data_fil`'
                            . ' WHERE `record_id` = ? AND `fieldname_id` = ? AND `file_language_id` <=> ?';
                        $this->db->query($sql, 'iii', [$recordId, $fieldNameId, $languageId]);
                        if ($this->db->num_rows < 1) continue;
                        [$prodFieldId, $prodExtension] = $this->db->fetchNum();
                        if ((string)$prodExtension !== '') {
                            @unlink("{$mediaDir}/" . $this->cms->mediaPath((int)$prodFieldId, $recordId, $languageId, (string)$prodExtension));
                        }
                        $sql = 'DELETE FROM `data_fil` WHERE `record_id` = ? AND `fieldname_id` = ? AND `file_language_id` <=> ?';
                        $this->db->query($sql, 'iii', [$recordId, $fieldNameId, $languageId]);
                        continue;
                    }

                    // 2. Nur 'temporary'-Dateien umziehen
                    // --------------------
                    // Einträge ohne Flag beschreiben die unveränderte Datei des
                    // produktiven Originals
                    if (empty($fileData['temporary'])) continue;
                    if ($fieldId < 1) continue; // ohne field_id nicht zuordenbar
                    $fileFolder = $this->cms->mediaFolder($fieldId);
                    $extension  = (string)($fileData['extension'] ?? '');

                    if ($tmpId !== $recordId) {
                        // Vorhandene alte produktive Datei ersetzen
                        // (ihre Extension kann von der neuen abweichen)
                        $prodExtension = $this->_getStoredExtension($recordId, $fieldNameId, $languageId);
                        if ($prodExtension !== '') {
                            @unlink("{$mediaDir}/{$fileFolder}/" . $this->cms->mediaFileName($recordId, $languageId, $prodExtension));
                        }
                        // Physische Datei auf die produktive Id umbenennen
                        // (leere Extension -> keine Datei)
                        if ($extension !== '') {
                            $tmpPath  = "{$mediaDir}/{$fileFolder}/" . $this->cms->mediaFileName($tmpId, $languageId, $extension);
                            $prodPath = "{$mediaDir}/{$fileFolder}/" . $this->cms->mediaFileName($recordId, $languageId, $extension);
                            if (is_file($tmpPath) && !@rename($tmpPath, $prodPath)) {
                                throw new \Exception("Error: promoteTmpFiles(): could not rename \"{$tmpPath}\".");
                            }
                        }
                    }

                    // `data_fil`-Eintrag aus den Datei-Metadaten des Arbeitsstands erzeugen
                    $this->_writeFileRow($recordId, $fieldNameId, $fieldId, $fileData, $languageId);

                    // Übernommene Server-Datei löschen (Schalter in der Eingabemaske) -
                    // erst nach dem Commit, ein Rollback ließe sie sonst verloren gehen
                    $serverFile = (string)($fileData['serverfile_delete'] ?? '');
                    if ($serverFile !== '') {
                        $this->db->afterCommit(fn() => $this->_deleteServerFile($serverFile));
                    }
                }
            }

            // Mehrsprachige Datei-Felder: Fallback-Zeilen der Sprachen ohne eigene
            // Datei nachziehen
            foreach ($syncFields as $fieldNameId => $fieldId) {
                $this->_syncFileRows($recordId, $fieldNameId, $fieldId);
            }

            // 'temporary'-Flags im Inhalts-Cache des produktiven Datensatzes entfernen
            // (die Dateien sind nun produktiv; saveVirtualFields() hat den Cache
            //  zuvor inkl. der Flags aus der Arbeitskopie übernommen) und die
            // `version` der Zeilen nachtragen (Cache-Brecher der Verweise, siehe
            // Cms::cmsMedia() / Backend::backendValue())
            $versions = [];
            $this->db->query(
                "SELECT `language_id`, `field_id`, `version` FROM `data_fil` WHERE `record_id` = ?",
                'i', [$recordId]
            );
            foreach ($this->db->fetchAll() as $row) {
                $versions[(int)$row['language_id']][(int)$row['field_id']] = (int)$row['version'];
            }
            foreach ($this->_loadRecordCacheRows($recordId) as $lang => $prodCache) {
                $changed = false;
                foreach ($prodCache as $cacheName => $entry) {
                    if (($entry[0] ?? '') !== 'file') continue;
                    if (isset($entry[1]['temporary'])) {
                        unset($prodCache[$cacheName][1]['temporary']);
                        $changed = true;
                    }
                    $entryFieldId = (int)($entry[1]['field_id'] ?? 0);
                    $version = $versions[(int)$lang][$entryFieldId] ?? null;
                    if ($version !== null && (int)($entry[1]['version'] ?? -1) !== $version) {
                        $prodCache[$cacheName][1]['version'] = $version;
                        $changed = true;
                    }
                    // Sicherheitsnetz: zurückgestellte Thumbnails wurden in
                    // writePendingThumbnails() abgearbeitet, das Flag darf nicht
                    // in den produktiven Cache gelangen
                    if (isset($entry[1]['thumbnails_pending'])) {
                        unset($prodCache[$cacheName][1]['thumbnails_pending']);
                        $changed = true;
                    }
                    // die Server-Datei ist erledigt (siehe oben)
                    if (isset($entry[1]['serverfile_delete'])) {
                        unset($prodCache[$cacheName][1]['serverfile_delete']);
                        $changed = true;
                    }
                }
                if ($changed) {
                    $jsonData = (string)json_encode($prodCache, JSON_UNESCAPED_UNICODE);
                    $this->db->query(
                        "UPDATE `record_cache` SET `cache` = ? WHERE `record_id` = ? AND `language_id` = ?",
                        'sii', [$jsonData, $recordId, (int)$lang]
                    );
                }
            }

        } finally {
            $this->db->popResult();
        }
    }

    /**
     * `data_fil`-Zeilen eines MEHRSPRACHIGEN Datei-Feldes je Sprache abgleichen.
     *
     * Eigene Zeilen (file_language_id = language_id) bleiben. Jede Sprache der
     * Instanz ohne eigene Datei bekommt die Zeile der Hauptsprache mit deren
     * file_language_id (Fallback) - hat auch die Hauptsprache keine Datei, eine leere
     * Zeile. Veraltete Fallback-Zeilen werden dabei ersetzt.
     *
     * Aufzurufen, nachdem eigene Dateien geschrieben oder gelöscht wurden
     * (promoteTmpFiles(), promoteBackgroundThumbnails()).
     *
     * @param int $recordId
     * @param int $fieldNameId
     * @param int $fieldId
     */
    protected function _syncFileRows(int $recordId, int $fieldNameId, int $fieldId): void
    {
        $languageIds = $this->recordLanguageIds($recordId);
        $primaryId   = $languageIds[0];

        // eigene Zeilen lesen
        $own = [];
        $this->db->query(
            'SELECT * FROM `data_fil` WHERE `record_id` = ? AND `fieldname_id` = ?'
            . ' AND `file_language_id` IS NOT NULL AND `file_language_id` = `language_id`',
            'ii', [$recordId, $fieldNameId]
        );
        while ($row = $this->db->fetch()) {
            $own[(int)$row['language_id']] = $row;
        }

        // alle übrigen Zeilen (Fallbacks, Reste) entfernen ...
        $this->db->query(
            'DELETE FROM `data_fil` WHERE `record_id` = ? AND `fieldname_id` = ?'
            . ' AND NOT (`file_language_id` IS NOT NULL AND `file_language_id` = `language_id`)',
            'ii', [$recordId, $fieldNameId]
        );
        // ... und je Sprache ohne eigene Datei neu schreiben
        foreach ($languageIds as $lang) {
            if (isset($own[$lang])) continue;
            $source = $own[$primaryId] ?? null;
            $fileData = $source ? $source + ['file_language_id' => $primaryId]
                : ['extension' => '', 'mime_type' => '', 'filename' => '', 'size' => 0,
                   'width' => 0, 'height' => 0, 'token' => '', 'data' => '{}',
                   'file_language_id' => $lang];
            $this->_writeFileRow($recordId, $fieldNameId, $fieldId, $fileData, $lang, (int)$fileData['file_language_id']);
        }
    }

    /**
     * Eine in ein Upload-Feld übernommene Datei aus paths.upload_dir löschen
     * (Schalter "nach erfolgreichem Übernehmen löschen", siehe _saveUploadField()).
     * Nur innerhalb des Upload-Verzeichnisses; fehlt die Datei schon, nichts tun.
     *
     * @param string $relativePath  Pfad relativ zu paths.upload_dir
     */
    private function _deleteServerFile(string $relativePath): void
    {
        $uploadDir = realpath((string)$this->config->get('paths.upload_dir'));
        $file = realpath((string)$this->config->get('paths.upload_dir') . '/' . $relativePath);
        if ($uploadDir === false || $file === false || !is_file($file)
            || !str_starts_with($file, $uploadDir . DIRECTORY_SEPARATOR)
        ) return;
        @unlink($file);
    }

    /**
     * Eine `data_fil`-Zeile aus den Datei-Metadaten des Arbeitsstands eines file-Eintrags schreiben.
     *
     * Einzige Stelle im Projekt, an der `data_fil`-Zeilen entstehen - benutzt von
     * promoteTmpFiles() (Arbeitskopie geht produktiv), _syncFileRows() (Fallback-Zeilen)
     * und promoteBackgroundThumbnails() (Hintergrundprozess hat eine Quelldatei
     * geliefert, die Thumbnails darauf entstehen jetzt).
     *
     * `version` zählt bei jedem Schreiben hoch. Zwei Zwecke:
     *   1. Anti-Caching beim Ausliefern der Datei (?v=...)
     *   2. eindeutiger Name im Papierkorb, siehe trashFileName()
     * Nur die jeweils aktuelle Version wird aufbewahrt - der Zähler beschreibt den
     * Stand der Datei, nicht eine Historie.
     *
     * Ein Eintrag OHNE Datei (leere Extension, size 0) wird bewusst ebenfalls
     * geschrieben: erst er macht den Datensatz für die Filter von Cms::select()
     * auffindbar, siehe Klassenkommentar von Cms.
     *
     * SPRACHEN
     *     einsprachiges Feld ($languageId null): dieselbe Zeile für JEDE Sprache der
     *         Instanz, file_language_id NULL - so greift die Sprachbedingung der
     *         Filter auch bei Datei-Feldern
     *     mehrsprachiges Feld: EINE Zeile für $languageId; file_language_id ist die
     *         Sprache der physischen Datei ($fileLanguageId, sonst $languageId)
     *
     * @param int      $recordId        Id des produktiven Datensatzes
     * @param int      $fieldNameId     fieldname_id (zusammen mit language_id, record_id der PK)
     * @param int      $fieldId         field_id des Feldes (bestimmt das Medienverzeichnis)
     * @param array    $fileData        Metadaten: extension, mime_type, data, filename,
     *                                  size, width, height, token
     * @param int|null $languageId      Sprache der Zeile (null: alle Sprachen, einsprachig)
     * @param int|null $fileLanguageId  Sprache der Datei (null: = $languageId)
     */
    private function _writeFileRow(int $recordId, int $fieldNameId, int $fieldId, array $fileData, ?int $languageId = null, ?int $fileLanguageId = null): void
    {
        // weil `data` ein JSON-Feld ist - keinen Leerstring speichern!
        $jsonData = (string)($fileData['data'] ?? '');
        if ($jsonData === '') $jsonData = '{}';

        $sql = 'INSERT INTO `data_fil`'
            . ' (`language_id`, `file_language_id`, `record_id`, `fieldname_id`, `field_id`, `extension`, `mime_type`,'
            . ' `data`, `filename`, `size`, `width`, `height`, `token`, `version`)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1) AS new_data'
            . ' ON DUPLICATE KEY UPDATE'
            // PK: language_id, record_id, fieldname_id
            // field_id kann sich nicht ändern
            . ' `file_language_id` = new_data.`file_language_id`,'
            . ' `extension` = new_data.`extension`,'
            . ' `mime_type` = new_data.`mime_type`,'
            . ' `data` = new_data.`data`,'
            . ' `filename` = new_data.`filename`,'
            . ' `size` = new_data.`size`,'
            . ' `width` = new_data.`width`,'
            . ' `height` = new_data.`height`,'
            . ' `token` = new_data.`token`,'
            . ' `version` = `data_fil`.`version` + 1';

        $rows = ($languageId === null)
            ? array_map(fn($lang) => [$lang, null], $this->recordLanguageIds($recordId))
            : [[$languageId, $fileLanguageId ?? $languageId]];
        foreach ($rows as [$lang, $fileLang]) {
            $this->db->query($sql, 'iiiiissssiiis', [
                $lang,
                $fileLang,
                $recordId,
                $fieldNameId,
                $fieldId,
                (string)($fileData['extension'] ?? ''),
                (string)($fileData['mime_type'] ?? ''),
                $jsonData,
                (string)($fileData['filename'] ?? ''),
                (int)($fileData['size'] ?? 0),
                (int)($fileData['width'] ?? 0),
                (int)($fileData['height'] ?? 0),
                (string)($fileData['token'] ?? '')
            ]);
        }
    }

    /**
     * Thumbnails erzeugen, die auf einer im HINTERGRUND fertiggestellten Datei aufbauen,
     * und ihre `data_fil`-Einträge anlegen.
     *
     * Gegenstück zu writePendingThumbnails() für den Hintergrundpfad, aufgerufen von
     * TriggerMedia, sobald ein Prozess durch ist. Der Unterschied zum Speichervorgang:
     * hier gibt es keine Arbeitskopie und kein anschließendes checkIn(), das die
     * cache-Metadaten in `data_fil` überführen würde - das muss hier selbst passieren.
     *
     * writeThumbnails() läuft mit forceBgproc: true, die erzeugten Dateien entstehen
     * also ihrerseits im Hintergrund. Ihre Zeilen bekommen deshalb size/width/height 0
     * und das Token ihres Prozesses; die endgültigen Werte trägt TriggerMedia nach,
     * wenn AUCH dieser Prozess fertig ist. So arbeitet sich eine mehrstufige
     * Thumbnail-Kette Cron-Lauf für Cron-Lauf vor.
     *
     * @param int    $recordId        Id des produktiven Datensatzes
     * @param int    $parentFieldId   field_id der fertiggestellten Datei
     * @param string $parentPath      Pfad zu ihr ab paths.media_dir
     * @param array  $parentData      Ihre Metadaten (mime_type, extension, filename, ...)
     * @param array &$schemaVirtual   Schema des Bereichs
     * @return array                  Namen der Felder, für die eine Zeile geschrieben wurde
     */
    public function promoteBackgroundThumbnails(
        int $recordId,
        int $parentFieldId,
        string $parentPath,
        array $parentData,
        array &$schemaVirtual
    ): array
    {
        if ($recordId < 1 || $parentFieldId < 1) return [];

        // writeThumbnails() schreibt ausschließlich in $this->recordCache und hält in
        // $this->processedParentFieldIds fest, welche Felder schon dran waren. Beides
        // muss je Datensatz frisch sein - der Trigger arbeitet mehrere Prozesse in
        // einem Lauf ab, sonst würde der zweite Datensatz übersprungen.
        $this->clearRecordCache();

        // Sprache der Quell-Datei (mehrsprachiges Feld) - die Thumbnails gehören
        // derselben Sprache; Fallback-Zeilen der übrigen Sprachen zieht
        // _syncFileRows() nach
        $languageId = $this->cms->fileLanguageId($parentData);

        $this->writeThumbnails(
            $recordId, $parentFieldId, $parentPath, $parentData, $schemaVirtual, forceBgproc: true, languageId: $languageId
        );

        $written = [];
        foreach ($this->recordCache as $name => $entry) {
            if (!is_array($entry) || ($entry[0] ?? '') !== 'file') continue;
            foreach ($this->fileEntries($entry) as $langKey => $fileData) {
                $lang = ($langKey === '') ? null : (int)$langKey;
                $fieldId     = (int)($fileData['field_id'] ?? 0);
                // fieldname_id trägt writeThumbnails() in den Eintrag ein
                $fieldNameId = (int)($fileData['fieldname_id'] ?? 0);
                // Ohne beide Ids ist der Eintrag nicht zuordenbar (PK: language_id + record_id + fieldname_id)
                if ($fieldId < 1 || $fieldNameId < 1) continue;

                $this->_writeFileRow($recordId, $fieldNameId, $fieldId, $fileData, $lang);
                if ($lang !== null) $this->_syncFileRows($recordId, $fieldNameId, $fieldId);
                $written[] = (string)$name;
            }
        }

        if ($written !== []) {
            // Inhalts-Cache verwerfen: Cms::fetch() baut ihn beim nächsten Zugriff
            // aus den data_*-Zeilen neu auf
            $this->db->query("DELETE FROM `record_cache` WHERE `record_id` = ?", 'i', [$recordId]);
        }

        return $written;
    }

    /**
     * Löscht die physischen Dateien eines Datensatzes, z. B.
     * - einer temporären Arbeitskopie beim Abbrechen (TemporaryTrait::cancel())
     *   oder beim Entwerten alter Arbeitskopien (TemporaryTrait::checkOut())
     * - eines produktiven Datensatzes beim endgültigen Löschen (hookOnDelete)
     * Produktive Datensätze: anhand der `data_fil`-Einträge (diese werden beim
     * Löschen der Datensatz-Zeile per FK-Cascade mit entfernt).
     * Arbeitskopien: anhand der 'temporary'-Einträge in ihrem cache
     * (Arbeitskopien besitzen keine `data_fil`-Einträge; Einträge ohne
     *  'temporary'-Flag beschreiben Dateien des produktiven Originals und
     *  werden bewusst NICHT angefasst).
     *
     * @param int $recordId  Id des Datensatzes (temporär oder produktiv)
     */
    public function deleteRecordFiles(int $recordId): void
    {
        if ($recordId < 1) return;
        $mediaDir = $this->config->get('paths.media_dir');
        $this->db->pushResult();
        foreach ($this->recordFileRows($recordId) as $row) {
            @unlink("{$mediaDir}/" . $this->cms->mediaPath((int)$row['field_id'], $recordId, $row['file_language_id'], $row['extension']));
        }
        $this->db->popResult();

        // 'temporary'-Dateien einer Arbeitskopie anhand ihres Arbeitsstand
        $cache = $this->_loadTmpData($recordId);
        foreach ($cache as $entry) {
            if (($entry[0] ?? '') !== 'file') continue;
            foreach ($this->fileEntries($entry) as $langKey => $fileData) {
                if (empty($fileData['temporary'])) continue;
                $extension = (string)($fileData['extension'] ?? '');
                $fieldId = (int)($fileData['field_id'] ?? 0);
                if ($extension === '' || $fieldId < 1) continue;
                $lang = ($langKey === '') ? null : (int)$langKey;
                @unlink("{$mediaDir}/" . $this->cms->mediaPath($fieldId, $recordId, $lang, $extension));
            }
        }
    }

    // ====================
    // Mediendateien beim Löschen / Wiederherstellen
    //
    // Ein Datensatz durchläuft drei Zustände, und seine Dateien wandern mit:
    //
    //     produktiv    {media_dir}/{instanceId}/{fieldId}/{recordId}.{ext}
    //        |  softDelete()  -> trashRecordFiles()
    //        v
    //     Papierkorb   {var_dir}/deleted/{fieldId}_{recordId}_{version}.{ext}
    //        |  restoreOne()  -> untrashRecordFiles()   zurück nach oben
    //        |  finalDelete() -> purgeRecordFiles()     endgültig weg
    //
    // Die `data_fil`-Zeilen bleiben beim Soft-Delete unangetastet - nur über sie ist
    // später wiederauffindbar, welche Datei zu welchem Feld gehört und welche
    // Extension und Version sie hat. Erst beim endgültigen Löschen fallen sie per
    // FK-Cascade mit der record-Zeile weg.
    //
    // Warum die Version im Dateinamen: `data_fil`.`version` zählt bei jedem Upload
    // hoch (siehe promoteTmpFiles()). Der Name im Papierkorb bleibt dadurch auch dann
    // eindeutig, wenn derselbe Datensatz nach einer Wiederherstellung eine neue Datei
    // bekommt und erneut gelöscht wird.
    // ====================

    /**
     * Dateiname einer Datei im Papierkorb-Verzeichnis.
     *
     * @param int    $fieldId    `data_fil`.`field_id`
     * @param int    $recordId   `data_fil`.`record_id`
     * @param int    $version    `data_fil`.`version`
     * @param string $extension  ohne führenden Punkt
     * @return string            Dateiname ohne Verzeichnis
     */
    protected function trashFileName(int $fieldId, int $recordId, int $version, string $extension, ?int $fileLanguageId = null): string
    {
        return $fieldId . '_' . $this->cms->mediaBaseName($recordId, $fileLanguageId) . '_' . $version . '.' . $extension;
    }

    /**
     * Datei-Metadaten eines Datensatzes aus `data_fil` holen.
     *
     * Gemeinsame Grundlage der drei Datei-Operationen unten. Einträge ohne Extension
     * (Thumbnail-Feld ohne greifenden Konverter) werden übersprungen - zu ihnen gibt
     * es keine physische Datei.
     *
     * @param int $recordId
     * @return array  Zeilen mit field_id, extension, version
     */
    protected function recordFileRows(int $recordId): array
    {
        if ($recordId < 1) return [];
        $this->db->pushResult();
        // Nur die Zeilen der physischen Dateien: einsprachige Felder führen je
        // Sprache dieselbe Zeile (file_language_id NULL -> DISTINCT), mehrsprachige
        // je Sprache eine eigene Datei (file_language_id = language_id); Fallback-
        // Zeilen anderer Sprachen zeigen auf eine dieser Dateien und zählen nicht.
        $sql = 'SELECT DISTINCT `field_id`, `extension`, `version`, `file_language_id` FROM `data_fil`'
             . ' WHERE `record_id` = ? AND (`file_language_id` IS NULL OR `file_language_id` = `language_id`)';
        $this->db->query($sql, 'i', [$recordId]);
        $rows = $this->db->fetchAll();
        $this->db->popResult();

        $result = [];
        foreach ($rows as $row) {
            if ((string)$row['extension'] === '') continue;
            $result[] = [
                'field_id'         => (int)$row['field_id'],
                'extension'        => (string)$row['extension'],
                'version'          => (int)$row['version'],
                'file_language_id' => $this->cms->fileLanguageId($row),
            ];
        }
        return $result;
    }

    /**
     * SOFT-DELETE: Mediendateien eines Datensatzes in den Papierkorb verschieben.
     *
     * Aufgerufen aus Record::hookOnSoftDelete(). Fehlt eine Datei bereits (z. B. weil
     * ein früherer Vorgang abgebrochen wurde), wird sie stillschweigend übersprungen -
     * das Löschen des Datensatzes soll daran nicht scheitern.
     *
     * Arbeitskopien bleiben unberührt: ihre Dateien sind Zwischenstände ohne
     * `data_fil`-Eintrag und werden erst beim endgültigen Löschen entfernt.
     *
     * @param int $recordId  Id des produktiven Datensatzes
     */
    public function trashRecordFiles(int $recordId): void
    {
        $rows = $this->recordFileRows($recordId);
        if (empty($rows)) return;

        $varDir     = $this->config->get('paths.var_dir');
        $deletedDir = $varDir .'/deleted';
        $mediaDir   = $this->config->get('paths.media_dir');

        // Zielverzeichnis sicherstellen (Rechte aus permissions.dir)
        if (!is_dir($deletedDir) && !$this->system->createDir(dirname($deletedDir), basename($deletedDir))) {
            throw new \Exception("Error: trashRecordFiles(): could not create \"{$deletedDir}\".");
        }

        foreach ($rows as $row) {
            $source = "{$mediaDir}/" . $this->cms->mediaPath((int)$row['field_id'], $recordId, $row['file_language_id'], $row['extension']);
            if (!is_file($source)) continue;
            $target = $deletedDir . '/' . $this->trashFileName(
                $row['field_id'], $recordId, $row['version'], $row['extension'], $row['file_language_id']
            );
            // Eine Datei gleichen Namens kann nur ein Rest eines abgebrochenen
            // Vorgangs sein - der aktuelle Stand hat Vorrang.
            if (is_file($target)) @unlink($target);
            @rename($source, $target);
        }
    }

    /**
     * RESTORE: Mediendateien eines Datensatzes aus dem Papierkorb zurückholen.
     *
     * Gegenstück zu trashRecordFiles(), aufgerufen aus Record::hookOnRestore().
     * Das Zielverzeichnis {media_dir}/{instanceId}/{fieldId} wird bei Bedarf neu
     * angelegt - es kann zwischenzeitlich leer geräumt worden sein.
     *
     * @param int $recordId  Id des produktiven Datensatzes
     */
    public function untrashRecordFiles(int $recordId): void
    {
        $rows = $this->recordFileRows($recordId);
        if (empty($rows)) return;

        $varDir     = $this->config->get('paths.var_dir');
        $deletedDir = $varDir .'/deleted';
        $mediaDir   = $this->config->get('paths.media_dir');

        foreach ($rows as $row) {
            $source = $deletedDir . '/' . $this->trashFileName(
                $row['field_id'], $recordId, $row['version'], $row['extension'], $row['file_language_id']
            );
            if (!is_file($source)) continue;
            $folder = $this->cms->mediaFolder((int)$row['field_id']);
            if (!$this->system->createDir($mediaDir, $folder)) continue;
            $target = "{$mediaDir}/{$folder}/" . $this->cms->mediaFileName($recordId, $row['file_language_id'], $row['extension']);
            if (is_file($target)) @unlink($target);
            @rename($source, $target);
        }
    }

    /**
     * ENDGÜLTIGE LÖSCHUNG: alle Mediendateien eines Datensatzes entfernen.
     *
     * Aufgerufen aus Record::hookOnDelete(), also bevor die record-Zeile (und mit ihr
     * per FK-Cascade die `data_fil`-Zeilen) verschwindet - danach wären Extension und
     * Version nicht mehr zu ermitteln.
     *
     * Aufgeräumt wird an BEIDEN Orten: im Papierkorb (der Normalfall, der Datensatz war
     * ja soft-gelöscht) und im Medienverzeichnis (falls das Verschieben beim Soft-Delete
     * fehlgeschlagen ist oder ein Aufrufer direkt endgültig löscht).
     *
     * @param int $recordId  Id des produktiven Datensatzes
     */
    public function purgeRecordFiles(int $recordId): void
    {
        $rows       = $this->recordFileRows($recordId);

        $varDir     = $this->config->get('paths.var_dir');
        $deletedDir = $varDir .'/deleted';
        $mediaDir   = $this->config->get('paths.media_dir');

        foreach ($rows as $row) {
            @unlink($deletedDir . '/' . $this->trashFileName(
                $row['field_id'], $recordId, $row['version'], $row['extension'], $row['file_language_id']
            ));
            @unlink("{$mediaDir}/" . $this->cms->mediaPath((int)$row['field_id'], $recordId, $row['file_language_id'], $row['extension']));
        }

        // Dateien noch vorhandener Arbeitskopien mit aufräumen
        // (sie liegen unter der tmp-Id und haben keine `data_fil`-Einträge)
        $this->db->pushResult();
        $this->db->query("SELECT `record_id` FROM `record` WHERE `tmp` = 1 AND `origin_id` = ?", 'i', [$recordId]);
        $tmpIds = $this->db->fetchAll();
        $this->db->popResult();
        foreach ($tmpIds as $tmpId) {
            $this->deleteRecordFiles((int)$tmpId);
        }
    }

    // ====================
    // Datei-Einträge im Arbeitsstand-Format
    //
    //     einsprachig      ['file', {extension, mime_type, size, ..., file_language_id: null}]
    //     mehrsprachig     ['file', {language_id: {...}, language_id: {...}}]
    //                      - nur die Sprachen mit EIGENER Datei; eine Sprache ohne
    //                      Eintrag nutzt die Datei der Hauptsprache (Fallback), das
    //                      steht im Cache und in `data_fil` (file_language_id)
    //
    // Die physische Datei heißt media/{field_id}/{record_id}[_{language_id}].{ext}
    // (Cms::mediaFileName()). Thumbnails eines mehrsprachigen Upload-Feldes sind
    // selbst mehrsprachig (Field::hookOnSave()) und entstehen je Sprache aus deren
    // Datei.
    // ====================

    /**
     * Datei-Einträge eines Datei-Eintrags als Liste je Sprache.
     *
     * @param array $entry  ['file', ...]
     * @return array        file_language_id|null => Metadaten
     *                      (null-Schlüssel steht als '' im Array)
     */
    public function fileEntries(array $entry): array
    {
        $value = (array)($entry[1] ?? []);
        if ($this->cms->isLanguageKeyedFiles($value)) {
            $result = [];
            foreach ($value as $lang => $fileData) $result[(int)$lang] = (array)$fileData;
            return $result;
        }
        return ['' => $value];
    }

    /**
     * Datei-Eintrag EINER Sprache (null = einsprachig).
     */
    public function fileEntry(array $entry, ?int $languageId): array
    {
        $entries = $this->fileEntries($entry);
        if ($languageId === null) {
            // einsprachig - liegt trotzdem eine Sprachliste vor, die Hauptsprache
            return (array)($entries[''] ?? (reset($entries) ?: []));
        }
        return (array)($entries[$languageId] ?? []);
    }

    /**
     * Datei-Eintrag einer Sprache setzen (null = einsprachig) bzw. mit null entfernen.
     *
     * @param array      &$tmpData    Arbeitsstand-Format (feldname => [typ, wert])
     * @param string     $name        Feldname
     * @param int|null   $languageId  Sprache der Datei, null bei einsprachigen Feldern
     * @param array|null $fileData    Metadaten; null entfernt den Eintrag der Sprache
     */
    public function setFileEntry(array &$tmpData, string $name, ?int $languageId, ?array $fileData): void
    {
        if ($languageId === null) {
            $tmpData[$name] = ['file', $fileData ?? []];
            return;
        }
        $value = (array)($tmpData[$name][1] ?? []);
        if (!$this->cms->isLanguageKeyedFiles($value)) $value = [];
        if ($fileData === null) unset($value[$languageId]);
        else $value[$languageId] = $fileData + ['file_language_id' => $languageId];
        $tmpData[$name] = ['file', $value];
    }

    /**
     * Gespeicherte Inhalte eines verschlüsselten Feldes je Sprache (entschlüsselt).
     *
     * Verschlüsselte Inhalte stehen nicht im Arbeitsstand der Arbeitskopie (data_enc-
     * Prinzip, siehe Crypt) - beim finalen Speichern holt Dataset::save() sie
     * hierüber vom produktiven Datensatz nach, wenn der Request sie nicht (für alle
     * Sprachen) mitbringt. Fallback-Zeilen (`empty` = 1, Inhalt aus der Hauptsprache)
     * kommen als '' zurück, damit saveVirtualFields() den Fallback neu setzt.
     *
     * @param int   $recordId  produktiver Datensatz
     * @param int   $fieldId   Feld vom Typ 'encrypted'
     * @param array $langCtx   languageContext() des Bereichs
     * @return array           language_id => Klartext; leer, wenn nichts gespeichert ist
     */
    public function encryptedValues(int $recordId, int $fieldId, array $langCtx): array
    {
        if ($recordId < 1 || $fieldId < 1) return [];
        $primaryId = (int)($langCtx['primary_id'] ?? 0);
        $this->db->pushResult();
        $this->db->query(
            "SELECT `language_id`, `data`, `empty` FROM `data_enc` WHERE `record_id` = ? AND `field_id` = ?",
            'ii', [$recordId, $fieldId]
        );
        $values = [];
        foreach ($this->db->fetchAll() as $row) {
            $languageId = (int)$row['language_id'];
            $isFallback = !empty($row['empty']) && $languageId !== $primaryId;
            $values[$languageId] = $isFallback ? '' : $this->crypt->decrypt((string)$row['data'], '', true);
        }
        $this->db->popResult();
        return $values;
    }

    /**
     * Arbeitsstand (Zwischenstand) einer Arbeitskopie laden.
     *
     * Der Zwischenstand liegt - wie der produktive Stand - je Sprache in
     * `record_cache`, unter der Id der Arbeitskopie (siehe Klassenkommentar von
     * TemporaryTrait). Hier kommt er ins Arbeitsstand-Format: feldname => [typ, wert];
     * bei mehrsprachigen Feldern ist der Wert ein Array language_id => Eingabe
     * (leer = nicht übersetzt), bei Datei-Feldern die Metadaten samt Flags
     * 'temporary' / 'deleted' / 'thumbnails_pending', verschlüsselte Inhalte
     * entschlüsselt. Fehlende Sprachzeilen werden NICHT nachgebaut - eine
     * Arbeitskopie hat keine data_*-Zeilen (Neuanlage: leerer Stand).
     *
     * @param int $recordId  Id der Arbeitskopie
     * @return array         Arbeitsstand, ggf. []
     */
    protected function _loadTmpData(int $recordId): array
    {
        [$schemaVirtual, $langCtx] = $this->_tmpContext($recordId);
        if (empty($langCtx)) return [];
        return $this->_tmpDataFromRecordCache($recordId, $schemaVirtual, $langCtx, rebuild: false);
    }

    /**
     * Arbeitsstand einer Arbeitskopie schreiben - in ihre `record_cache`-Zeilen,
     * abgeleitet je Sprache wie beim produktiven Stand (_writeRecordCache()).
     *
     * @param int        $recordId     Id der Arbeitskopie
     * @param array      $tmpData      Arbeitsstand (Format siehe _loadTmpData())
     * @param int[]|null $languageIds  nur diese Sprachzeilen erneuern (null = alle);
     *                                 siehe saveTemporaryStep()
     * @param bool       $touch        Bearbeitungssperre auffrischen (changed_at/by)
     */
    protected function _saveTmpData(int $recordId, array $tmpData, ?array $languageIds = null, bool $touch = false): void
    {
        [$schemaVirtual, $langCtx] = $this->_tmpContext($recordId);
        if (empty($langCtx)) return;
        $this->_writeRecordCache($recordId, $tmpData, $schemaVirtual, $langCtx, [], $languageIds, keepPasswordHash: true);
        if ($touch) {
            $this->db->query(
                "UPDATE `record` SET `changed_at` = UTC_TIMESTAMP(), `changed_by` = ? WHERE `record_id` = ?",
                'ii', [$this->adminIdForDb(), $recordId]
            );
        }
    }

    /**
     * Arbeitsstand aller Arbeitskopien eines Bereichs nach einer Feldumwandlung
     * (Field::convert()) nachziehen: der Eintrag des Feldes bekommt den neuen Typ,
     * die Werte rechnet $convert um (Klartext rein, Datenbankformat raus;
     * _writeRecordCache() verschlüsselt bei Bedarf). Leere Eingaben bleiben leer.
     *
     * Aufzurufen, nachdem `field` Typ und Mehrsprachigkeit des Ziels trägt: welche
     * Form der Wert hat (Array je Sprache oder einzeln), liest
     * _tmpDataFromRecordCache() aus dem Schema; dazwischen wird nur umgeformt -
     * mehrsprachig -> einsprachig behält die Hauptsprache.
     *
     * @param int      $areaId
     * @param string   $name     Feldname
     * @param string   $toType   neuer Typ
     * @param callable $convert  fn(string $value): int|float|string
     * @return int               Anzahl der angepassten Arbeitskopien
     */
    public function retypeWorkingCopies(int $areaId, string $name, string $toType, callable $convert): int
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `record_id` FROM `record` WHERE `area_id` = ? AND `tmp` = 1", 'i', [$areaId]);
            $tmpIds = array_map('intval', $this->db->fetchAll());
            $count = 0;
            foreach ($tmpIds as $tmpId) {
                [$schemaVirtual, $langCtx] = $this->_tmpContext($tmpId);
                if (empty($langCtx)) continue;
                $tmpData = $this->_tmpDataFromRecordCache($tmpId, $schemaVirtual, $langCtx, rebuild: false);
                if (!isset($tmpData[$name])) continue;
                $primaryId = (int)($langCtx['primary_id'] ?? 0);
                $one = fn($value) => $this->_isEmptyInput($value) ? '' : $convert((string)$value);
                $raw = $tmpData[$name][1] ?? '';
                $multilingual = !empty($schemaVirtual[$name]['multilingual'])
                    && in_array($toType, $this->cms->multilingualTypes, true);
                if (is_array($raw)) {
                    $value = $multilingual ? array_map($one, $raw) : $one($raw[$primaryId] ?? '');
                }
                else {
                    $value = $multilingual ? [$primaryId => $one($raw)] : $one($raw);
                }
                $tmpData[$name] = [$toType, $value];
                $this->_saveTmpData($tmpId, $tmpData);
                $count++;
            }
            return $count;
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Arbeitsstand der Arbeitskopien nach dem Umstellen eines Datei-Feldes zwischen
     * ein- und mehrsprachig (Field::migrateFileLanguage()) nachziehen - direkt in den
     * `record_cache`-Zeilen, unabhängig vom Schema:
     *
     *     -> mehrsprachig   der Eintrag wird die Datei der Hauptsprache, die übrigen
     *                       Sprachen bekommen ihn als Fallback; eine neu hochgeladene
     *                       Datei ('temporary') heißt danach {tmpId}_{hauptsprache}
     *     -> einsprachig    der Eintrag der Hauptsprache gilt für alle Sprachen (ohne
     *                       file_language_id); neue Dateien anderer Sprachen werden
     *                       gelöscht, die der Hauptsprache heißt danach {tmpId}
     *
     * @param int  $fieldId
     * @param bool $toMultilingual
     */
    public function migrateWorkingCopyFiles(int $fieldId, bool $toMultilingual): void
    {
        $mediaDir = $this->config->get('paths.media_dir');
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `name`, `area_id` FROM `field` WHERE `field_id` = ?", 'i', [$fieldId]);
            $field = $this->db->fetch();
            if (!$field) return;
            $name = (string)$field['name'];
            $this->db->query("SELECT `record_id` FROM `record` WHERE `area_id` = ? AND `tmp` = 1", 'i', [(int)$field['area_id']]);
            foreach (array_map('intval', $this->db->fetchAll()) as $tmpId) {
                $rows = $this->_loadRecordCacheRows($tmpId);
                if (empty($rows)) continue;
                $languageIds = $this->recordLanguageIds($tmpId);
                $primaryId = $languageIds[0];
                $primaryEntry = $rows[$primaryId][$name] ?? null;
                if (!is_array($primaryEntry) || ($primaryEntry[0] ?? '') !== 'file') continue;
                $fileData = (array)($primaryEntry[1] ?? []);
                $extension = (string)($fileData['extension'] ?? '');
                $path = fn(?int $lang, string $ext) => "{$mediaDir}/" . $this->cms->mediaPath($fieldId, $tmpId, $lang, $ext);

                if ($toMultilingual) {
                    if (!empty($fileData['temporary']) && $extension !== '' && is_file($path(null, $extension))) {
                        @rename($path(null, $extension), $path($primaryId, $extension));
                    }
                    $fileData['file_language_id'] = $primaryId;
                    foreach ($rows as $lang => $cache) {
                        $rows[$lang][$name] = ($lang === $primaryId) ? ['file', $fileData] : ['file', $fileData, 1];
                    }
                }
                else {
                    // neue Dateien anderer Sprachen fallen weg
                    foreach ($rows as $lang => $cache) {
                        $entry = (array)($cache[$name][1] ?? []);
                        $ext = (string)($entry['extension'] ?? '');
                        if ($lang !== $primaryId && !empty($entry['temporary']) && $ext !== ''
                            && $this->cms->fileLanguageId($entry) === $lang) {
                            @unlink($path($lang, $ext));
                        }
                    }
                    if (!empty($fileData['temporary']) && $extension !== '' && is_file($path($primaryId, $extension))) {
                        @rename($path($primaryId, $extension), $path(null, $extension));
                    }
                    $fileData['file_language_id'] = null;
                    foreach ($rows as $lang => $cache) {
                        $rows[$lang][$name] = ['file', $fileData];
                    }
                }
                foreach ($rows as $lang => $cache) {
                    $this->db->query(
                        "REPLACE INTO `record_cache` (`record_id`, `language_id`, `cache`) VALUES (?, ?, ?)",
                        'iis', [$tmpId, (int)$lang, (string)json_encode($cache, JSON_UNESCAPED_UNICODE)]
                    );
                }
            }
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Schema und Sprachen einer Arbeitskopie (aus ihrer eigenen `record`-Zeile:
     * area_id - beim Verschieben bereits der Zielbereich).
     *
     * @return array  [schemaVirtual, langCtx]; [[], []] wenn es den Datensatz nicht gibt
     */
    private function _tmpContext(int $recordId): array
    {
        $this->db->pushResult();
        $this->db->query('SELECT `area_id` FROM `record` WHERE `record_id` = ?', 'i', [$recordId]);
        $areaId = (int)($this->db->fetchNum()[0] ?? 0);
        $this->db->popResult();
        if ($areaId < 1) return [[], []];
        return [$this->virtualSchema($areaId, false), $this->languageContext($areaId)];
    }

    /**
     * Sprachen der Instanz eines Datensatzes (Hauptsprache zuerst).
     *
     * @param int $recordId
     * @return int[]  language_ids; mindestens ein Eintrag
     */
    protected function recordLanguageIds(int $recordId): array
    {
        $this->db->pushResult();
        $this->db->query('SELECT `instance_id` FROM `record` WHERE `record_id` = ?', 'i', [$recordId]);
        $instanceId = (int)($this->db->fetchNum()[0] ?? 0);
        $this->db->popResult();
        $instanceCache = $this->container->get(InstanceCache::class);
        $ids = $instanceCache->languageIds($instanceId);
        return $ids ?: [$instanceCache->primaryLanguageId($instanceId)];
    }

    /**
     * Den produktiven Stand eines Datensatzes aus `record_cache` ins Arbeitsstand-Format
     * bringen - die Grundlage jeder Arbeitskopie (TemporaryTrait::checkOut()).
     *
     * Der Cache trägt je Sprache die Werte MIT Fallback; das dritte Element 1 an
     * einem Eintrag sagt, dass der Wert aus der Hauptsprache stammt. Daraus wird
     * hier wieder die Eingabe: bei mehrsprachigen Feldern ein Array language_id =>
     * Wert, Fallbacks als leere Eingabe. json-Werte werden dekodiert, verschlüsselte
     * Inhalte entschlüsselt (im Cache base64-kodierter Geheimtext); Passwörter
     * stehen nicht im Cache (leer).
     *
     * Fehlt eine Sprachzeile, wird der Cache über Cms::createRecordCache() aus den
     * data_*-Zeilen neu aufgebaut - nur bei produktiven Datensätzen ($rebuild); der
     * Arbeitsstand einer Arbeitskopie (_loadTmpData()) hat keine andere Quelle.
     *
     * @param int   $recordId       Datensatz (produktiv oder Arbeitskopie)
     * @param array $schemaVirtual  Schema des Bereichs (multilingual je Feld)
     * @param array $langCtx        Sprachen der Instanz (Dataset::languageContext())
     * @param bool  $rebuild        fehlende Sprachzeilen aus data_* nachbauen
     * @return array                Arbeitsstand-Format
     */
    protected function _tmpDataFromRecordCache(int $recordId, array $schemaVirtual, array $langCtx, bool $rebuild = true): array
    {
        $languageIds = (array)($langCtx['language_ids'] ?? []);
        if (empty($languageIds)) return [];
        $primaryId = (int)($langCtx['primary_id'] ?? $languageIds[0]);

        // Alle Sprachzeilen laden - fehlende nachbauen
        $rows = $this->_loadRecordCacheRows($recordId);
        foreach ($languageIds as $lang) {
            if (isset($rows[$lang]) || !$rebuild) continue;
            $this->db->pushResult();
            $this->db->query('SELECT `area_id`, `instance_id` FROM `record` WHERE `record_id` = ?', 'i', [$recordId]);
            $row = $this->db->fetch() ?? [];
            $this->db->popResult();
            if (empty($row)) return [];
            $this->cms->createRecordCache($recordId, (int)$row['area_id'], (int)$row['instance_id'], (int)$lang);
            $rows = $this->_loadRecordCacheRows($recordId);
            break;
        }

        $primaryCache = $rows[$primaryId] ?? (reset($rows) ?: []);
        $tmpData = [];
        foreach ($primaryCache as $name => $entry) {
            $type = (string)($entry[0] ?? 'text');
            $isMultilingual = !empty($schemaVirtual[$name]['multilingual'])
                && in_array($type, $this->cms->multilingualTypes, true);
            if ($type === 'encrypted') {
                // Geheimtext (base64) -> Klartext; Fallbacks als leere Eingabe
                $decrypt = fn($e) => $this->crypt->decrypt((string)($e[1] ?? ''), '', false);
                if ($isMultilingual) {
                    $values = [];
                    foreach ($languageIds as $lang) {
                        $langEntry = $rows[$lang][$name] ?? null;
                        $values[$lang] = ($langEntry === null || !empty($langEntry[2])) ? '' : $decrypt($langEntry);
                    }
                    $tmpData[$name] = [$type, $values];
                }
                else {
                    $tmpData[$name] = [$type, $decrypt($entry)];
                }
                continue;
            }
            if ($type === 'file' && $this->cms->isMultilingualField((array)($schemaVirtual[$name] ?? []))) {
                // mehrsprachiges Datei-Feld: nur die Sprachen mit EIGENER Datei
                // (Fallback-Einträge zeigen auf die Datei einer anderen Sprache)
                $files = [];
                foreach ($languageIds as $lang) {
                    $langEntry = $rows[$lang][$name] ?? null;
                    if ($langEntry === null || !empty($langEntry[2])) continue;
                    $fileData = (array)($langEntry[1] ?? []);
                    if ($this->cms->fileLanguageId($fileData) !== (int)$lang) continue;
                    $files[(int)$lang] = $fileData;
                }
                $tmpData[$name] = [$type, $files];
                continue;
            }
            if ($isMultilingual) {
                $values = [];
                foreach ($languageIds as $lang) {
                    $langEntry = $rows[$lang][$name] ?? null;
                    if ($langEntry === null || !empty($langEntry[2])) {
                        // fehlt bzw. Fallback -> leere Eingabe
                        $values[$lang] = ($type === 'json') ? [] : '';
                        continue;
                    }
                    $values[$lang] = ($type === 'json')
                        ? (json_decode((string)($langEntry[1] ?? ''), true) ?: [])
                        : ($langEntry[1] ?? '');
                }
                $tmpData[$name] = [$type, $values];
                continue;
            }
            $value = $entry[1] ?? '';
            if ($type === 'json') $value = json_decode((string)$value, true) ?: [];
            $tmpData[$name] = [$type, $value];
        }
        return $tmpData;
    }

    /**
     * Alle `record_cache`-Zeilen eines Datensatzes laden.
     *
     * @return array  language_id => dekodierter Cache
     */
    protected function _loadRecordCacheRows(int $recordId): array
    {
        $rows = [];
        $this->db->pushResult();
        $this->db->query('SELECT `language_id`, `cache` FROM `record_cache` WHERE `record_id` = ?', 'i', [$recordId]);
        while ($row = $this->db->fetch()) {
            $rows[(int)$row['language_id']] = json_decode((string)$row['cache'], true) ?: [];
        }
        $this->db->popResult();
        return $rows;
    }

    /**
     * Inhalts-Cache je Sprache aus dem Arbeitsstand (Arbeitsstand-Format) ableiten und
     * in `record_cache` schreiben - das Gegenstück zu _tmpDataFromRecordCache().
     *
     *     einsprachig     [typ, wert] in jeder Sprache
     *     mehrsprachig    [typ, wert] mit dem Wert der Sprache; ist die Eingabe leer,
     *                     [typ, wert der Hauptsprache, 1]
     *     json            als JSON-String (so liest Cms::fetch() ihn)
     *     file            unverändert in jeder Sprache
     *
     * @param int   $recordId
     * @param array $tmpData         Arbeitsstand
     * @param array $schemaVirtual   Schema des Bereichs (multilingual je Feld)
     * @param array $langCtx         Sprachen der Instanz
     * @param array $extraEntries    language_id => [name => eintrag] - fertige Einträge,
     *                               die den abgeleiteten überlagern (verschlüsselte Felder
     *                               beim finalen Speichern: Geheimtext der data_enc-Zeile)
     * @param int[]|null $onlyLanguages  nur diese Sprachzeilen ersetzen (null = alle)
     * @param bool $keepPasswordHash  Arbeitskopie: den Passwort-Hash des Arbeitsstands
     *                               behalten (securePasswords()); produktiv nie
     */
    protected function _writeRecordCache(int $recordId, array $tmpData, array $schemaVirtual, array $langCtx, array $extraEntries = [], ?array $onlyLanguages = null, bool $keepPasswordHash = false): void
    {
        $languageIds = (array)($langCtx['language_ids'] ?? []);
        if (empty($languageIds)) $languageIds = $this->recordLanguageIds($recordId);
        $primaryId = (int)($langCtx['primary_id'] ?? $languageIds[0]);
        $writeIds = ($onlyLanguages === null)
            ? $languageIds
            : array_values(array_intersect(array_map('intval', $languageIds), array_map('intval', $onlyLanguages)));

        if ($onlyLanguages === null) {
            $this->db->query("DELETE FROM `record_cache` WHERE `record_id` = ?", 'i', [$recordId]);
        }
        foreach ($writeIds as $lang) {
            $cache = [];
            foreach ($tmpData as $name => $entry) {
                $type = (string)($entry[0] ?? 'text');
                $raw  = $entry[1] ?? '';
                if (in_array($type, $this->cms->relationTypes, true)) continue;
                if ($type === 'file') {
                    if (is_array($raw) && $this->cms->isLanguageKeyedFiles($raw)) {
                        // mehrsprachig: eigene Datei der Sprache, sonst die der
                        // Hauptsprache (Fallback, Kennzeichen 1), sonst leer
                        if (isset($raw[$lang])) {
                            $cache[$name] = [$type, $raw[$lang]];
                        }
                        elseif (isset($raw[$primaryId])) {
                            $cache[$name] = [$type, $raw[$primaryId], 1];
                        }
                        else {
                            $cache[$name] = [$type, ['extension' => '', 'mime_type' => '', 'filename' => '',
                                'size' => 0, 'width' => 0, 'height' => 0, 'file_language_id' => (int)$lang]];
                        }
                        continue;
                    }
                    $cache[$name] = [$type, $raw];
                    continue;
                }
                // Mehrsprachig: Wert ist ein Array language_id => Eingabe. Bei json ist
                // auch der Wert selbst ein Array - dort entscheidet die Indizierung.
                $isMultilingual = !empty($schemaVirtual[$name]['multilingual'])
                    && in_array($type, $this->cms->multilingualTypes, true)
                    && is_array($raw)
                    && ($type !== 'json' || $this->_isLanguageKeyedArray($raw, $languageIds));
                $fallback = false;
                if ($isMultilingual) {
                    $value = $raw[$lang] ?? ($type === 'json' ? [] : '');
                    if ($lang !== $primaryId && $this->_isEmptyInput($value)) {
                        $value = $raw[$primaryId] ?? ($type === 'json' ? [] : '');
                        $fallback = true;
                    }
                }
                else {
                    $value = $raw;
                }
                if ($type === 'json') {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                    if ($value == '""') $value = '';
                }
                elseif ($type === 'encrypted') {
                    // Klartext des Arbeitsstands -> Geheimtext, base64 (JSON-fähig)
                    $value = base64_encode($this->crypt->encrypt((string)$value, '', true));
                }
                elseif ($type === 'password' || ($schemaVirtual[$name]['display_type'] ?? '') === 'password') {
                    // Passwörter gehören nicht in den produktiven Cache (siehe
                    // Cms::createRecordCache()); im Arbeitsstand einer Arbeitskopie
                    // darf der Hash stehen - er ist derselbe Wert wie in data_pwd
                    if (!$keepPasswordHash) $value = '';
                }
                $cache[$name] = $fallback ? [$type, $value, 1] : [$type, $value];
            }
            foreach ((array)($extraEntries[$lang] ?? []) as $name => $entry) {
                $cache[$name] = $entry;
            }
            $jsonData = (string)json_encode($cache, JSON_UNESCAPED_UNICODE);
            $this->db->query(
                "REPLACE INTO `record_cache` (`record_id`, `language_id`, `cache`) VALUES (?, ?, ?)",
                'iis', [$recordId, (int)$lang, $jsonData]
            );
        }
    }

    /**
     * Ist ein Array nach Sprachen indiziert (language_id => Wert)?
     */
    private function _isLanguageKeyedArray(array $values, array $languageIds): bool
    {
        if (empty($values)) return false;
        foreach (array_keys($values) as $key) {
            if (!is_numeric($key) || !in_array((int)$key, array_map('intval', $languageIds), true)) return false;
        }
        return true;
    }

    /**
     * Hilfsfunktion: bisher gespeicherte Extension eines `data_fil`-Eintrags ermitteln
     * (Primary Key ist language_id + record_id + fieldname_id; gesucht wird die Zeile
     * der Datei mit file_language_id - NULL bei einsprachigen Feldern)
     *
     * @param int      $recordId        record_id des Eintrags (produktiver Datensatz;
     *                                  Arbeitskopien besitzen keine `data_fil`-Einträge,
     *                                  deren Extension steht im Arbeitsstand)
     * @param int      $fieldNameId     fieldname_id des Eintrags
     * @param int|null $fileLanguageId  Sprache der Datei (null: einsprachig)
     * @return string                   Extension oder '' wenn kein Eintrag / keine Datei vorhanden
     */
    protected function _getStoredExtension(int $recordId, int $fieldNameId, ?int $fileLanguageId = null): string
    {
        $this->db->pushResult();
        $sql = 'SELECT `extension` FROM `data_fil` WHERE `record_id` = ? AND `fieldname_id` = ?'
             . ' AND `file_language_id` <=> ?';
        $this->db->query($sql, 'iii', [$recordId, $fieldNameId, $fileLanguageId]);
        $extension = ($this->db->num_rows > 0) ? (string)$this->db->fetchNum()[0] : '';
        $this->db->popResult();
        return $extension;
    }

    /**
     * Arbeitet rekursiv Thumbnails für ein file-Feld ab.
     * Wird auf die temporäre Arbeitskopie eines Datensatzes angewendet, über
     * promoteBackgroundThumbnails() aber auch nach Beendigung eines Hintergrundprozesses
     * auf einen produktiven Datensatz.
     * 
     * @param int $storageId         Id, unter der die Dateien physisch abgelegt werden
     *                               (beim Speichervorgang: Id der temporären Arbeitskopie,
     *                               vgl. _saveUploadField() / promoteTmpFiles())
     * @param int $parentFieldId     Id des Quell-Feldes (Upload/Thumbnail)
     * @param string $parentPath     Relativer Pfad zur Quell-Datei inkl. Extension, ab paths.media_dir
     * @param array $parentData      Daten der Quell-Datei
     * @param array &$schemaVirtual  Zeiger auf virtuelles Schema
     * @param bool $forceBgproc      true: erzwingt, dass das Thumbnail als Hintergrundprozess erzeugt wird
     *                               (wird erzwungen wenn bereits parent-Thumnail als Hintergrund erzeugt wurde)
     * @param int|null $languageId   Sprache der Quell-Datei bei mehrsprachigen Feldern:
     *                               die Thumbnails entstehen für dieselbe Sprache
     *                               (Dateiname mit Sprache, Datei-Eintrag je Sprache)
     * @return ?array                ggf. Array mit Infos zur Quell-Datei ('width', 'height', 'data')
     */
    public function writeThumbnails(int $storageId, int $parentFieldId, string $parentPath, array $parentData, array &$schemaVirtual, bool $forceBgproc = false, ?int $languageId = null): ?array
    {
        $parentMime = (string)($parentData['mime_type'] ?? '');
        $parentExtension = (string)($parentData['extension'] ?? '');

        // Infos zur Quell-Datei, die ein Prozessor ggf. mitliefert (Rückgabewert)
        $srcInfo = null;

        foreach ($schemaVirtual as $name => $colDef) {
            // Suche nach allen Thumbnail-Feldern, die auf $parentFieldId basieren
            $type = (string)($colDef['type'] ?? '');
            $displayType = (string)($colDef['display_type'] ?? '');
            $fieldId = (int)($colDef['field_id'] ?? 0);
            // Verhindere Endlos-Rekursion durch gegenseitige Zuweisung über parent_field_id:
            // jedes Thumbnail-Feld wird pro Speichervorgang (und Sprache) nur einmal verarbeitet
            $processedKey = $fieldId . ':' . ($languageId ?? '');
            if ($type !== 'file'
                || $displayType !== 'thumbnail'
                || (int)($colDef['parent_field_id'] ?? 0) !== $parentFieldId
                || in_array($processedKey, $this->processedParentFieldIds, true)
            ) {
                continue;
            }
            $this->processedParentFieldIds[] = $processedKey;

            $label = $colDef['label'] ?? $name;

            $fileData = [
                'width'     => 0,
                'height'    => 0,
                'mime_type' => '',
                'size'      => 0,
                'filename'  => '',
                'extension' => ''
            ];
            $filePath = '';

            // Verarbeite die Media Converter, die dem Thumbnail-Feld zugewiesen sind
            // (`rel_field_converter`, in der dort gespeicherten Reihenfolge):
            // den ersten nehmen, der auf den Medientyp passt UND dessen Prozessor den
            // Typ verarbeiten kann (MediaProcessor::supports(); stellt sich das erst in
            // convert() heraus, meldet der Prozessor 'unsupported'). Beispiel: GD ohne
            // WebP-Unterstützung, dahinter ImageMagick - das WebP nimmt ImageMagick.
            // Kann KEIN Konverter die Datei verarbeiten, ist das ein Fehler (unten).
            $converterIds = $this->getConverterIds($fieldId);
            $converterFound = false;      // ein Konverter hat die Datei übernommen
            $unsupportedErrors = [];      // Snippet-Ids der übergangenen Konverter (für die Meldung)
            foreach ($converterIds as $converterId) {
                // Daten des Converters laden
                $converter = $this->loadConverter($converterId);
                // Mime-Typen oder Extensions, auf die der Konverter anspringen / nicht anspringen soll
                // z. B. image/p-jpeg, oder audio/* oder .pdf (Punkt vor Extension)
                $mediaTypeIncl = !empty($converter['media_types_incl'])
                    ? explode("\n", (string)$converter['media_types_incl']) : [];
                $mediaTypeExcl = !empty($converter['media_types_excl'])
                    ? explode("\n", (string)$converter['media_types_excl']) : [];

                // wenn kein Treffer, dann nächsten Converter prüfen...
                // (leere Incl-Liste = Converter akzeptiert alles, Excl-Liste hat Vorrang)
                $matched = empty($mediaTypeIncl)
                    || $this->_matchMediaType($parentMime, $parentExtension, $mediaTypeIncl);
                if ($matched && !empty($mediaTypeExcl)
                    && $this->_matchMediaType($parentMime, $parentExtension, $mediaTypeExcl)
                ) {
                    $matched = false;
                }
                if (!$matched) { continue; }

                // Bei Treffer:
                $fileFolder = $this->cms->mediaFolder($fieldId);
                // Thumbnail wird ebenfalls unter der storage-Id (Arbeitskopie) abgelegt,
                // bei mehrsprachigen Feldern mit der Sprache der Quell-Datei im Namen
                $fileBaseName = $this->cms->mediaBaseName($storageId, $languageId);

                // Dateierweiterung der erzeugten Datei:
                // wenn keine Extension explizit vorgegeben wird, dann die der Quelldatei
                $parentExtension = pathinfo($parentPath, PATHINFO_EXTENSION);
                $fileExtension = $converter['extension'] ?: $parentExtension;
                // Lade die Prozessor-Klasse
                $this->processor = $this->factory->create($converter['processor']);
                // Prozessor beherrscht Quell- oder Zielformat nicht -> nächster Konverter
                if (!$this->processor->supports($parentMime, $parentExtension, $fileExtension)) {
                    $unsupportedErrors[] = 'proc.' . strtolower($converter['processor']) . '.error_file_not_supported';
                    continue;
                }
                // Zieldatei
                $filePath = "{$fileFolder}/{$fileBaseName}.{$fileExtension}";
                // Wenn Verzeichnis noch nicht existiert, dann jetzt erstellen
                $this->system->createDir($this->config->get('paths.media_dir'), $fileFolder);
                // Umwandlung der Datei
                $converterDetails = json_decode((string)($converter['details'] ?? ''), true) ?? [];
                $converterDetails['extension'] = $fileExtension;
                $fileData = $this->processor->convert(
                    $parentPath, $parentData, $filePath, $converterDetails, $forceBgproc
                );
                // Erst beim Lesen festgestellt, dass das Format nicht geht -> nächster Konverter
                if (!empty($fileData['unsupported'])) {
                    $unsupportedErrors[] = (string)($fileData['errors'] ?? 'error_file_conversion');
                    $filePath = '';
                    continue;
                }
                $converterFound = true;
                /**
                 * Folgende Daten liefert der Prozessor im Erfolgsfall zurück:
                 *     'width',
                 *     'height',
                 *     'mime_type',
                 *     'size',
                 *     'filename',
                 *     'extension',
                 *     'data',          (fertiger JSON-String)
                 * optional zusätzlich Infos zur QUELL-Datei:
                 *     'source_width',
                 *     'source_height',
                 *     'source_data'    (fertiger JSON-String)
                 * Hintergrundprozess gestartet?
                 *     'token'          Token des Hintergrundprozesses, der die Datei gerade erzeugt
                 * im Fehlerfall:
                 * alle Werte leer/0 und:
                 *     'errors'         Snippet-ID
                 *     'errors_defails' "Zusätzliche Fehlermeldung, z. B. aus der CLI"
                 */
                if ((int)$fileData['size'] === 0) {
                    if (!empty($fileData['token'])) {
                        // Hintergrundprozess laufend
                        // Speichern erlauben, auch wenn size und dimensions 0 sind.
                    }
                    else {
                        // Datei leer UND kein Hintergrundprozess erstellt -> Fehler
                        if (empty($fileData['errors'])) $fileData['errors'] = 'error_file_conversion';
                        $this->createFieldError($fileData['errors'], $name, $label);
                        if (!empty($fileData['error_details'])) {
                            $this->cms->error($fileData['error_details'], $name, $label);
                        }
                        throw new \Exception('#' . $this->cms->errorMsgLast());
                    }
                }
                // Es kann je Thumbnail-Feld nur eine Datei erzeugt werden
                break;
            }

            // Konverter zugewiesen, aber keiner kann diesen Dateityp verarbeiten
            // (Medientyp passt nirgends oder alle Prozessoren haben abgelehnt) -> Fehler.
            // Ohne zugewiesenen Konverter bleibt das Feld einfach leer.
            if (!$converterFound && !empty($converterIds)) {
                $fileData = [
                    'width' => 0, 'height' => 0, 'mime_type' => '',
                    'size' => 0, 'filename' => '', 'extension' => ''
                ];
                $this->createFieldError('error_file_no_converter', $name, $label, $parentMime ?: $parentExtension);
                // Gründe der übergangenen Konverter (ohne Dubletten) als Zusatz
                foreach (array_unique($unsupportedErrors) as $snippetId) {
                    $this->cms->error($this->cms->snippet($snippetId), $name, $label);
                }
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }

            // Infos zur Quell-Datei übernehmen
            // (vom ersten Converter, der sie liefert; Reihenfolge: width, height, data)
            if ($srcInfo === null
                && (!empty($fileData['source_width'])
                    || !empty($fileData['source_height'])
                    || !empty($fileData['source_data']))
            ) {
                $srcInfo = [
                    'width'  => (int)($fileData['source_width'] ?? 0),
                    'height' => (int)($fileData['source_height'] ?? 0),
                    'data'   => (string)($fileData['source_data'] ?? '')
                ];
            }
            // Vorhandene alte Thumbnail-Datei mit abweichender Extension löschen
            // (je nach greifendem Converter kann sich die Ziel-Extension ändern;
            //  bisherige Extension steht im 'temporary'-cache-Eintrag der
            //  Arbeitskopie. Einträge ohne Flag beschreiben die produktive Datei
            //  und werden erst beim checkIn in promoteTmpFiles() ersetzt)
            $previousEntry = $this->fileEntry((array)($this->recordCache[$name] ?? []), $languageId);
            if (!empty($previousEntry['temporary'])) {
                $previousExtension = (string)($previousEntry['extension'] ?? '');
                if ($previousExtension !== '' && $previousExtension !== (string)$fileData['extension']) {
                    $mediaDir = $this->config->get('paths.media_dir');
                    @unlink("{$mediaDir}/" . $this->cms->mediaPath($fieldId, $storageId, $languageId, $previousExtension));
                }
            }
            // Werte in cache aufnehmen, auch wenn kein Converter gematcht hat
            // ('temporary': der `data_fil`-Eintrag entsteht erst beim checkIn
            //  in promoteTmpFiles() aus diesen Metadaten;
            //  leere Extension = Thumbnail-Feld ohne greifenden Converter, keine Datei)
            unset($fileData['errors'], $fileData['source_width'], $fileData['source_height'], $fileData['source_data']);

            $fileData['field_id']  = $fieldId;
            // fieldname_id mitschreiben: promoteTmpFiles() löst sie zwar selbst über den
            // cache-Schlüssel auf, promoteBackgroundThumbnails() legt seine
            // `data_fil`-Einträge aber direkt aus diesen Metadaten an
            $fileData['fieldname_id'] = (int)($this->cms->resolveVirtualField($name)['fieldname_id'] ?? 0);
            $fileData['data'] = (string)($fileData['data'] ?? '{}');
            $fileData['file_language_id'] = $languageId;
            $fileData['temporary'] = true;
            $this->setFileEntry($this->recordCache, $name, $languageId, $fileData);

            // Rekursion: Thumbnails erzeugen, die auf diesem Thumbnail basieren
            // Bei Backgroundprozess: Verschachtelte Thumbnails werden erst erfasst, wenn
            // der Backgroundprozess fertig ist - siehe promoteBackgroundThumbnails()
            if ((int)$fileData['size'] > 0 && $filePath !== '' && empty($fileData['token'])) {
                $this->writeThumbnails($storageId, $fieldId, $filePath, $fileData, $schemaVirtual, false, $languageId);
                // (Rückgabewert nicht mehr beachten weil Infos bei verschachtelten Thumbnails sofort bekannt sind)
            }
        }
        // Rückgabewert
        return $srcInfo;
    }

    /**
     * Erlaubt ein Upload-Feld Dateien dieses Typs? Medien-Typ-Gruppen
     * (details.media_types, ohne die Ausnahmen der Gruppe), media_types_incl,
     * media_types_excl - wie die Eingabeprüfung in _saveUploadField(). Endungen, die
     * nie gespeichert werden (forbiddenExtensions()), prüft der Aufrufer.
     *
     * @param array  $colDef     Felddefinition (virtuelles Schema)
     * @param string $fileMime   erkannter Mime-Typ
     * @param string $fileExtension  Endung, kleingeschrieben, ohne Punkt
     */
    public function fileTypeAllowed(array $colDef, string $fileMime, string $fileExtension): bool
    {
        $fieldDetails = json_decode((string)($colDef['details'] ?? ''), true) ?? [];
        // Prüfe den Mime Types bzw. Dateiendungen der hochgeladenen Datei
        // 1. Medien-Typ-Gruppen (siehe $mediaTypeGroups), mehrere möglich
        //    (ohne die Ausnahmen der Gruppe, siehe $mediaTypeGroupsExcl)
        $mediaTypeGroup = (array)($fieldDetails['media_types'] ?? []);
        $hasPatterns = false;
        $allowed = false;
        foreach ($mediaTypeGroup as $group) {
            $groupPatterns = $this->mediaTypeGroups[(string)$group] ?? [];
            if (empty($groupPatterns)) continue;
            $hasPatterns = true;
            if ($this->_matchMediaType($fileMime, $fileExtension, $groupPatterns)
                && !$this->_matchMediaType($fileMime, $fileExtension, $this->mediaTypeGroupsExcl[(string)$group] ?? [])
            ) {
                $allowed = true;
            }
        }
        // 2. Explizit angegbene Mime-Typen oder Extensions, die übereinstimmen dürfen
        // z. B. image/p-jpeg, oder audio/* oder .pdf (Punkt vor Extension)
        // (Groß-Kleinschreibung nicht unterscheiden)
        $mediaTypeIncl = !empty($fieldDetails['media_types_incl'])
            ? array_filter(array_map('trim', explode("\n", (string)$fieldDetails['media_types_incl']))) : [];
        if (!empty($mediaTypeIncl)) {
            $hasPatterns = true;
            if ($this->_matchMediaType($fileMime, $fileExtension, $mediaTypeIncl)) $allowed = true;
        }
        // Weder Gruppen noch Incl-Liste angegeben -> alle Typen erlaubt (bis auf Excl-Liste)
        if (!$hasPatterns) $allowed = true;
        // 3. Explizit angegbene Mime-Typen oder Extensions, die NICHT übereinstimmen dürfen
        $mediaTypeExcl = !empty($fieldDetails['media_types_excl'])
            ? explode("\n", (string)$fieldDetails['media_types_excl']) : [];
        if ($allowed && !empty($mediaTypeExcl)
            && $this->_matchMediaType($fileMime, $fileExtension, $mediaTypeExcl)
        ) {
            $allowed = false;
        }
        return $allowed;
    }

    /**
     * Endungen, die aus Sicherheitsgründen nie gespeichert werden.
     * @return string[]
     */
    public function forbiddenExtensions(): array
    {
        return $this->forbiddenExtensions;
    }

    /**
     * Passt die Endung nicht zum erkannten Inhalt ($mimeExtensionMap)? Eine solche
     * Datei lehnt _saveUploadField() mit error_file_mismatch ab.
     */
    public function mimeExtensionMismatch(string $fileMime, string $fileExtension): bool
    {
        return isset($this->mimeExtensionMap[$fileMime])
            && !in_array(strtolower($fileExtension), $this->mimeExtensionMap[$fileMime], true);
    }

    /**
     * Prüft Mime-Typ / Extension einer Datei gegen eine Liste von Mustern
     * Muster: exakter Mime-Typ (image/jpeg), Wildcard-Mime (image/*), Extension mit Punkt (.pdf)
     * Groß-/Kleinschreibung wird nicht unterschieden
     *
     * @param string $mime       Mime-Typ der Datei
     * @param string $extension  Extension der Datei (ohne Punkt)
     * @param array $patterns    Muster-Liste
     * @return bool              true bei Treffer
     */
    protected function _matchMediaType(string $mime, string $extension, array $patterns): bool
    {
        $mime = strtolower(trim($mime));
        $extension = strtolower(ltrim(trim($extension), '.'));
        foreach ($patterns as $pattern) {
            $pattern = strtolower(trim((string)$pattern));
            if ($pattern === '') continue;
            if ($pattern[0] === '.') {
                // Extension-Vergleich
                if (ltrim($pattern, '.') === $extension) return true;
            }
            elseif (str_ends_with($pattern, '/*')) {
                // Wildcard-Mime, z. B. image/*
                if (str_starts_with($mime, substr($pattern, 0, -1))) return true;
            }
            elseif ($pattern === $mime) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Prüfe, ob Verknüpfung besteht (für lookup-Feld Handhabung)
     * @param int $srcFieldId  Id des Relation-Feldes
     * @param int $srcId       Id des Quell-Datensatzes
     * @param int $destId      Id des Ziel-Datensatzes
     */
    public function checkRelation(int $srcFieldId, int $srcId, int $destId): bool
    {
        if ($srcFieldId < 1) return false;
        if ($srcId  < 1) return false;
        if ($destId  < 1) return false;
        $this->db->pushResult();
        $sql = "SELECT 1 FROM `data_rel` WHERE `field_id` = ? AND `src_id`= ? AND `dest_id` = ?";
        $this->db->query($sql, 'iii', [$srcFieldId, $srcId, $destId]);
        $linked = ($this->db->num_rows > 0);
        $this->db->popResult();
        return $linked;
    }

    /**
     * Relation zwischen Quell- und Ziel-Datensätzen herstellen
     * Abhängig vom Feldtyp: unidirektional oder bidirektional
     * * Format der Ids für die Ziel-Datensätze:
     * 1. Einzelne Id
     * 2. Kommaseparierte Ids
     * 3. Array mit Ids
     *
     * HÖCHSTENS EIN ZIEL (relation1 / pointer1, max = 1)
     * --------------------
     * Die Regel wird HIER durchgesetzt - alle Wege (Lookup, select-Felder in save(),
     * Stapelverarbeitung, Import, CMS:USER_INPUT) laufen durch diese Methode:
     *   - eigene Seite: mehr als eine dest_id ist ein Feldfehler; eine bestehende
     *     Verknüpfung wird auch bei $replace = false ersetzt (es gibt kein "dazu")
     *   - Gegenseite: erlaubt das Gegenfeld eines 'relation'-Feldes nur ein Ziel,
     *     verliert der Zieldatensatz beim CHECK-IN seine bisherige Verknüpfung dort
     *     (TemporaryTrait::_enforceSingleRelations()) - "ein Artikel hat genau eine
     *     Rubrik: wer ihn einer neuen zuordnet, nimmt ihn aus der alten". Nicht schon
     *     hier: die Verknüpfung hängt an der Arbeitskopie und muss mit ihr verworfen
     *     werden können (cancel), ohne dass fremde Datensätze etwas verlieren.
     *     Cms::select() joint …1-Felder als n:1 ohne DISTINCT und verlässt sich
     *     auf genau eine Zeile je src_id.
     *
     * @param array  $colDef   Quell-Feld-Eigenschaften
     * @param int    $srcId    Id des Quell-Datensatzes
     * @param array  $destIds  Ids der Ziel-Datensätze
     * @param bool   $replace  true: lösche vorhande Verknüpfungen in diesem Feld
     * @param bool   $checkTmp true: prüfe ob der Datensatz srcId temporär ist und Session-Admin gehört
     */
    public function createRelations(array &$colDef, int $srcId, int|array|string $destIds, bool $replace = true, bool $checkTmp = true): void
    {
        $this->db->pushResult();
        if (empty($colDef)) throw new \Exception('empty colDef');
        if ($srcId < 1) throw new \Exception('invalid srcId');

        $name = $colDef['name'];
        $label = $colDef['label'] ?? $name;
        $fieldId = $colDef['field_id'] ?? 0;
        if (empty($fieldId)) throw new \Exception('empty fieldId');
        $type = $colDef['type'] ?? '';
        if (!in_array($type, $this->cms->relationTypes, true)) {
            throw new \Exception('not a relation field');
        }

        // dest_ids
        if (is_string($destIds)) {
            $destIds = explode(',', $destIds);
        } elseif (is_int($destIds)) {
            $destIds = [$destIds];
        }

        // nur echte Ids behalten und Duplikate direkt entfernen
        $destIds = array_unique(array_filter($destIds, fn($value) => (int)$value > 0 && is_numeric($value)));
        $destCount = count($destIds);
        if ($destCount === 0 && !$replace) {
            // nichts zum Hinzufügen
            return;
        }

        // Sicherheitslimit: Maximal 5000 Datensätze auf einmal
        if ($destCount > 5000) {
            $this->createFieldError('record.error_too_many_relations', $name, $label);
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }

        // Höchstens ein Ziel (siehe Methodenkommentar): mehrere Ids sind ein Fehler,
        // eine vorhandene Verknüpfung wird immer ersetzt
        $isSingle = in_array($type, $this->cms->singleRelationTypes, true) || (int)($colDef['max'] ?? 0) === 1;
        if ($isSingle) {
            if ($destCount > 1) {
                $this->createFieldError('record.error_relation_single', $name, $label);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            $replace = true;
        }

        if ($checkTmp) {
            // prüfe ob Datensatz temporär ist und Session-Admin gehört

            if (!$prodData = $this->getProdData($srcId)) {
                throw new \Exception('not temporary or access denied');
            }
            $areaId = $prodData[1];

            // Vergleiche area_id des Feldes mit Area-Id des Quelldatensatzes
            if ((int)$colDef['area_id'] !== $areaId) {
                throw new \Exception('wrong area_id');
            }
        }

        // fieldname_id
        if ($fn = $this->cms->resolveVirtualField($name)) {
            $fieldNameId = $fn['fieldname_id'];
        }
        else {
            throw new \Exception('fieldname not resolved');
        }
        
        if (str_starts_with($type, 'relation')) {
            // -> rel_field_id + rel_area_id
            $destFieldId = (int)$colDef['rel_field_id'];
            $destAreaId = (int)$colDef['rel_area_id'];

            // Auch die fieldname_id Gegenfeldes wird benötigt
            $sql = 'SELECT `fn`.`fieldname_id` FROM `field`'
                .' LEFT JOIN `fieldname` `fn` ON `field`.`name` = `fn`.`name`'
                .' WHERE `field`.`field_id`=?';
            $this->db->query($sql, 'i', [$destFieldId]);
            if ($this->db->num_rows < 1) {
                // Gegen-Feld nicht gefunden
                $this->createFieldError('record.error_dest_relationfield', $name, $label);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            $destFieldnameId = $this->db->fetchNum()[0];
        }
        else {
            // pointer, pointer1
            // -> rel_area_ids
            $destAreaIds = $this->getRelAreaIds($fieldId);
        }

        $this->db->beginTransaction();

        try {
            if ($replace) {
                // Die neuen Verknüpfungen ersetzen alle vorhandenen Verknüpfungen
                // zw. Quell-Datensatz und Zielbereich
                $sql = "DELETE FROM `data_rel` WHERE `field_id`=? AND `src_id`=?";
                $this->db->query($sql, 'ii', [$fieldId, $srcId]);
                if (str_starts_with($type, 'relation')) {
                    // Bidirektionalität beachten
                    $sql = "DELETE FROM `data_rel` WHERE `field_id`=? AND `dest_id`=?";
                    $this->db->query($sql, 'ii', [$destFieldId, $srcId]);
                }
                // position-Wert auf 0 setzen
                $position = 0;
                $destPosition = 0;

            } else {
                // Die neuen Verknüpfungen werden zu den vorhandenen hinzugefügt
                // Der Reihe nach ans Ende setzen (position-Wert max + 1)
                // Schon bestehende Verknüpfungen werden durch unique key ignoriert
                $sql = "SELECT COALESCE(MAX(`position`) + 1, 0) FROM `data_rel` WHERE `field_id`=? AND `src_id`=?";
                $this->db->query($sql, 'ii', [$fieldId, $srcId], true);
                $position = $this->db->fetchNum()[0];
                if (str_starts_with($type, 'relation')) {
                    $sql = "SELECT COALESCE(MAX(`position`) + 1, 0) FROM `data_rel` WHERE `field_id`=? AND `dest_id`=?";
                    $this->db->query($sql, 'ii', [$destFieldId, $srcId], true);
                    $destPosition = $this->db->fetchNum()[0];
                }
            }

            if ($destCount === 0) {
                // $replace war true, aber es gibt nichts zum Hinzufügen
                $this->db->commit();
                return;
            }

            // area_id der zu verknüpfenden Datensätze gebündelt ermitteln
            $placeholders = implode(',', array_fill(0, $destCount, '?'));
            $types = str_repeat('i', $destCount);
            $sql = "SELECT `record_id`, `area_id` FROM `record` WHERE `record_id` IN ($placeholders)";
            $this->db->query($sql, $types, $destIds);
            
            $areaMap = [];
            foreach ($this->db->fetchAll() as $row) {
                $areaMap[(int)$row['record_id']] = (int)$row['area_id'];
            }

            // stmt-Vorlage fürs Einfügen
            $sqlInsert = "INSERT INTO `data_rel`"
                ." (`field_id`, `fieldname_id`, `src_id`, `dest_id`, `position`) "
                ." VALUES (?, ?, ?, ?, ?)";

            // Ids abarbeiten
            foreach ($destIds as $destId) {
                $destId = (int)$destId;

                if (!isset($areaMap[$destId])) {
                    // Zu verknüpfender Datensatz nicht gefunden
                    $this->createFieldError('record.error_dest_record_not_found', $name, $label, $destId);
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
                $areaId = $areaMap[$destId];

                if (str_starts_with($type, 'relation')) {
                    // relation: Ziel-Datensatz im Ziel-Bereich?
                    if ($areaId != $destAreaId) {
                        $this->createFieldError('record.error_dest_record_area', $name, $label, $destId);
                        throw new \Exception('#' . $this->cms->errorMsgLast());
                    }
                }
                else {
                    // pointer, pointer1, link
                    // Ziel-Datensatz in einem der Ziel-Bereiche?
                    if (!in_array($areaId, $destAreaIds, false)) {
                        // (leicht andere Fehlermeldung als bei Typ 'relation')
                        $this->createFieldError('record.error_dest_record_areas', $name, $label, $destId);
                        throw new \Exception('#' . $this->cms->errorMsgLast());
                    }
                    // link: der Zieldatensatz gehört höchstens EINEM Quelldatensatz -
                    // gehört er schon einem anderen (nicht dem Original der Arbeitskopie
                    // $srcId), ist er nicht verknüpfbar (LinkTrait::linkOwner())
                    if (in_array($type, $this->cms->dependentTypes, true) && method_exists($this, 'linkOwner')) {
                        $owner = $this->linkOwner($destId, $srcId);
                        if ($owner !== null) {
                            $this->createFieldError('record.error_link_owned', $name, $label, $destId);
                            throw new \Exception('#' . $this->cms->errorMsgLast());
                        }
                    }
                }

                // Abfangen von Duplicate-Keys bei bestehenden Einträgen
                // (spart Abfrage)
                try {
                    $this->db->query($sqlInsert, 'iiiii', [$fieldId, $fieldNameId, $srcId, $destId, $position], true);
                    $position++;

                    // Bidirektionalität beachten
                    if (str_starts_with($type, 'relation')) {
                        $this->db->query($sqlInsert, 'iiiii', [$destFieldId, (int)$destFieldnameId, $destId, $srcId, $destPosition], true);
                        $destPosition++;
                    }
                } catch (\mysqli_sql_exception $e) {
                    // ANSI-SQL-Standard für Integrity Constraint Violation prüfen
                    if ($e->getSqlState() === '23000') {
                        continue;
                    }
                    throw $e;
                }
            }

            $this->db->commit();

        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;

        } finally {
            $this->db->popResult();
        }

    }

    /**
     * Hebt Verknüpfung(en) im angegebenen Relation-Feld eines temporären Datensatzes auf
     * 
     * @param array &$colDef     Daten Des Relation-Feldes
     * @param int $srcId        Id des Quell-Datensatzes
     * @param int|null $destId  Id des Ziel-Datensatzes (wenn nicht angegeben: alle Verknüpfiungen)
     * @return bool             true im Erfolgsfall, false bei Fehler (keine Meldungen)
     */
    public function removeRelations(array &$colDef, int $srcId, ?int $destId = null): bool
    {
        if (empty($colDef)) throw new \Exception('empty colDef');
        $name = $colDef['name'];
        $label = $colDef['label'] ?? $name;
        $fieldId = (int)($colDef['field_id'] ?? 0);
        if (empty($fieldId)) throw new \Exception('empty fieldId');
        $type = $colDef['type'] ?? '';
        if (!in_array($type, $this->cms->relationTypes, true)) {
            throw new \Exception('not a relation field');
        }

        if ($srcId  < 1) throw new \Exception('invalid srcId');
        if ($destId !== null && $destId  < 1) throw new \Exception('invalid destId');

        if (!$prodData = $this->getProdData($srcId)) {
            throw new \Exception('not temporary or access denied');
        }
        $areaId = $prodData[1];

        // Vergleiche area_id des Feldes mit Area-Id des Quelldatensatzes
        if ((int)$colDef['area_id'] !== $areaId) {
            throw new \Exception('wrong area_id');
        }

        $sql = "DELETE FROM `data_rel` WHERE `field_id` = ? AND `src_id`= ?";
        $sqlParams = 'ii';
        $sqlValues = [$fieldId, $srcId];
        if ($destId !== null) {
            $sql .= " AND `dest_id` = ?";
            $sqlParams .= 'i';
            $sqlValues[] = $destId;
        }
        $this->db->query($sql, $sqlParams, $sqlValues);

        // Bidirektionalität beachten
        if (str_starts_with($type, 'relation')) {
            $relFieldId = (int)($colDef['rel_field_id'] ?? 0);
            $sql = "DELETE FROM `data_rel` WHERE `field_id` = ? AND `dest_id`= ?";
            $sqlParams = 'ii';
            $sqlValues = [$relFieldId, $srcId];
            if ($destId !== null) {
                $sql .= " AND `src_id` = ?";
                $sqlParams .= 'i';
                $sqlValues[] = $destId;
            }
            $this->db->query($sql, $sqlParams, $sqlValues);
        }

        return true;
    }

    /**
     * Sortierung von Datensätzen innerhalb Relation:
     * vertausche Position der Datensätze mit Id $destId1 und $destId2
     * Quelldatensatz muss temporär sein und dem Session-Admin gehören
     * 
     * @param array &$colDef  Relationfeld
     * @param int $srcId      Id des Quell-Datensatzes
     * @param int $destId1    Id des Zieldatensatzes 1
     * @param int $destId2    Id des Zieldatensatzes 2
     */
    public function swapRelationPosition(array &$colDef, int $srcId, int $destId1, int $destId2): void
    {
        // colDef
        if (empty($colDef)) throw new \Exception('empty colDef');
        $fieldId = (int)($colDef['field_id'] ?? 0);
        if (empty($fieldId)) throw new \Exception('empty fieldId');
        $type = $colDef['type'] ?? '';
        if (!in_array($type, $this->cms->relationTypes, true)) {
            throw new \Exception('not a relation field');
        }

        if ($srcId  < 1) throw new \Exception('invalid srcId');
        if ($destId1  < 1) throw new \Exception('invalid destId1');
        if ($destId2  < 1) throw new \Exception('invalid destId2');

        if (!$prodData = $this->getProdData($srcId)) {
            throw new \Exception('not temporary or access denied');
        }
        $areaId = $prodData[1];

        // Vergleiche area_id des Feldes mit Area-Id des Quelldatensatzes
        if ((int)$colDef['area_id'] !== $areaId) {
            throw new \Exception('wrong area_id');
        }

        try {
            $this->db->beginTransaction();

            // Validiere Zieldatensätze, indem bestehende Verknüpfung geprüft wird,
            // ermittle ihre Position
            $sql = "SELECT `position` FROM `data_rel` WHERE `field_id` = ? AND `src_id` = ? AND `dest_id` = ? FOR UPDATE";
            $this->db->query($sql, 'iii', [$fieldId, $srcId, $destId1], true);
            if ($this->db->num_rows < 1) {
                throw new \Exception('records are not linked');
            }
            $position1 = (int)$this->db->fetchNum()[0];

            // Statement 2: $sql bleibt gleich
            $this->db->query($sql, 'iii', [$fieldId, $srcId, $destId2], true);
            if ($this->db->num_rows < 1) {
                throw new \Exception('records are not linked');
            }
            $position2 = (int)$this->db->fetchNum()[0];

            // Positionen kann innerhalb der Transaktion überkreuz getauscht werden
            $sql = "UPDATE `data_rel` SET `position` = ? WHERE `field_id` = ? AND `src_id` = ? AND `dest_id` = ?";
            $this->db->query($sql, 'iiii', [$position2, $fieldId, $srcId, $destId1], true);

            // Statement 2: $sql bleibt gleich
            $this->db->query($sql, 'iiii', [$position1, $fieldId, $srcId, $destId2], true);

            $this->db->commit();
            
        } catch (\Exception $e) {
            $this->db->rollback();
            $this->cms->error($e->getMessage());
        }
    }

    /**
     * Inhalt von Volltext-Feldern erneuern
     * 
     * Wenn keine Datensatz-ID angegeben wird, betrifft die Erneuerung des Volltextfeldinhaltes
     * alle Datensätze seines Bereichs (bei Änderung der Quellfeld-Auswahl).
     * 
     * @param int $fulltextFieldId  Id des Volltextfeldes, deren Inhalte erneuert werden sollen
     * @param ?int $recordId        Id eines Datensatzes, dessen Inhalte geändert wurden (optional).
     */
    public function refreshFulltext(int $fulltextFieldId, ?int $recordId = null): void
    {
        // Ids der Quell-Felder (und ggf Relation-Felder über die Textfeld und Volltext zusammenhängen)
        $this->db->query("SELECT `rel_field_id`, `text_field_id` FROM `rel_fulltext_text` WHERE `fulltext_field_id`=?", 'i', [$fulltextFieldId]);
        $sources = $this->db->fetchAll();
        if (empty($sources)) {
            // keine Quell-Felder
            if (!empty($recordId)) {
                // Einzelner Datensatz wird gespeichert: Es kann auch schon vorher kein Inhalt enthalten gewesen sein
                return;
            }
            // Alle Datensätze: Dieser Fall tritt ein, wenn letztes Quellfeld vom Volltext entfernt wird
            if (!$fieldData = $this->fieldData($fulltextFieldId)) {
                return;
            }
            $sql = "UPDATE `data_ftx` SET `data` = '' WHERE `field_id` = ?";
            $this->db->query($sql, 'i', [$fulltextFieldId]);
            return;
        }

        if (!empty($recordId)) $this->_refreshFulltext($fulltextFieldId, $recordId, $sources);
        else {
            // Für jeden Datensatz des Inhaltsbereichs Volltext neu erzeugen
            if (!$fieldData = $this->fieldData($fulltextFieldId)) {
                return;
            }
            $areaId = (int)$fieldData['area_id'];
            $sql = "SELECT `record_id` FROM `record` WHERE `area_id` = ? AND `tmp` = 0";
            $this->db->query($sql, 'i', [$areaId]);
            // Ids vorab holen: _refreshFulltext() setzt eigene Queries ab
            // (einspaltiges fetchAll() liefert die Werte direkt).
            foreach ($this->db->fetchAll() as $recordId) {
                set_time_limit(300);
                $this->_refreshFulltext($fulltextFieldId, (int)$recordId, $sources);
            }
        }
    }

    /**
     * Helper function für refreshFulltext()
     *
     * Der Volltext entsteht JE SPRACHE der Instanz des Datensatzes: aus den
     * data_*-Zeilen der Quellfelder in dieser Sprache (die bei nicht übersetzten
     * mehrsprachigen Feldern den Fallback der Hauptsprache tragen). So findet die
     * Volltextsuche in jeder Sprache das, was dort auch angezeigt wird - gefaltet nach
     * den Regeln dieser Sprache (Cms::indexText(), Collation::fold()).
     */
    private function _refreshFulltext(?int $fulltextFieldId, int $recordId, array $sources, int $fieldNameId = 0): void
    {
        // $fulltextFieldId null: Volltext-Standardfeld (ohne `field`-Zeile, $fieldNameId)
        if ($fulltextFieldId !== null && empty($fulltextFieldId)) return;
        if (empty($recordId)) return;
        if (empty($sources)) return;

        // Typ je Quellfeld einmal ermitteln
        $sourceTables = [];
        foreach ($sources as $source) {
            $textFieldId = (int)$source['text_field_id'];
            $sql = "SELECT `type` FROM `field` WHERE `field_id` = ?";
            $this->db->query($sql, 'i', [$textFieldId]);
            if ($this->db->num_rows < 1) continue;
            $type = $this->db->fetchNum()[0];
            $sourceTables[] = [
                'text_field_id' => $textFieldId,
                'rel_field_id'  => (int)($source['rel_field_id'] ?? 0),
                'table'         => 'data_' . $this->cms->dataSuffix[$type],
                // Upload-Feld: sein Beitrag ist der ursprüngliche Dateiname
                'column'        => $type === 'file' ? 'filename' : 'data',
            ];
        }
        if ($fulltextFieldId !== null && ($fieldData = $this->fieldData($fulltextFieldId))) {
            $fieldNameId = (int)($this->cms->resolveVirtualField((string)$fieldData['name'])['fieldname_id'] ?? 0);
        }
        if ($fieldNameId < 1) return;

        // Volltext je Sprache sammeln und schreiben
        $sql = "DELETE FROM `data_ftx` WHERE `record_id` = ? AND `fieldname_id` = ?";
        $this->db->query($sql, 'ii', [$recordId, $fieldNameId]);

        foreach ($this->recordLanguageIds($recordId) as $languageId) {
            $ft = [];
            foreach ($sourceTables as $source) {
                $textFieldId = $source['text_field_id'];
                $tableName   = $source['table'];
                $column      = $source['column'];
                if (empty($source['rel_field_id'])) {
                    // Fall 1: kein Relation-Feld angegeben
                    // Volltextfeld und Textfeld liegen zusammen in einem Inhaltsbereich
                    $sql = "SELECT `$column` FROM `$tableName`"
                         . " WHERE `record_id` = ? AND `field_id` = ? AND `language_id` = ?";
                    $this->db->query($sql, 'iii', [$recordId, $textFieldId, $languageId]);
                    if ($this->db->num_rows > 0) {
                        $ft[] = $this->cms->indexText((string)$this->db->fetchNum()[0], false, $languageId);
                    }
                }
                else {
                    // Fall 2: Relation-Feld angegeben
                    // Textfeld liegt in einem anderen Bereich. Dort gilt dieselbe
                    // Sprache; führt die Instanz des Ziels sie nicht, bleibt der
                    // Beitrag leer.
                    $relFieldId = $source['rel_field_id'];
                    // Suche (über das Relationfeld) verknüpfte Inhalte aus dem Zielbereich
                    $sql = "SELECT `t`.`$column` FROM `data_rel` `r`"
                        ." LEFT JOIN `$tableName` `t` ON `r`.`dest_id` = `t`.`record_id`"
                        ." AND `t`.`field_id` = ? AND `t`.`language_id` = ?"
                        ." WHERE `r`.`src_id` = ? AND `r`.`field_id` = ?";
                    $this->db->query($sql, 'iiii', [$textFieldId, $languageId, $recordId, $relFieldId]);
                    for ($i = 0; $i < $this->db->num_rows; $i++) {
                        $ft[] = $this->cms->indexText((string)($this->db->fetchNum()[0] ?? ''), false, $languageId);
                    }
                }
            }
            $sql = "INSERT INTO `data_ftx` SET `language_id` = ?, `data` = ?, `record_id` = ?, `field_id` = ?, `fieldname_id` = ?";
            $this->db->query($sql, 'isiii', [
                $languageId,
                implode(' ', $ft),
                $recordId,
                $fulltextFieldId,
                $fieldNameId
            ]);
        }
    }

    /**
     * Uploads als nicht vertrauenswürdig kennzeichnen (Frontend): Dateien mit
     * aktivem Inhalt (MediaAccess::isActiveContent()) werden dann
     * unabhängig von den Feldeinstellungen abgelehnt. RecordUser schaltet das für
     * die Dauer eines Speicherns ein und danach wieder aus.
     */
    public function setUntrustedUploads(bool $untrusted): void
    {
        $this->untrustedUploads = $untrusted;
    }

    /**
     * Helper function: Daten eines Feldes ermitteln
     */
    /**
     * Entfernt eine hochgeladene Datei aus einer temporären Arbeitskopie
     * (x-Button neben dem Preview-Link in der Eingabemaske, Parameter 'removefiles'
     * von CMS:USER_INPUT).
     * - löscht Datei und `data_fil`-Eintrag der Arbeitskopie
     *   (inklusive aller rekursiv abhängigen Thumbnail-Felder)
     * - setzt im Zwischenstand (Arbeitsstand) der Arbeitskopie das Flag 'deleted':
     *   beim checkIn löscht promoteTmpFiles() dann auch die ggf. am produktiven
     *   Datensatz hängende Datei
     *
     * @param int $tmpId            Id der temporären Arbeitskopie
     * @param int $fieldId          Id des Upload-Feldes
     * @param int|null $languageId  Sprache der Datei bei mehrsprachigen Upload-Feldern
     * @return bool                 true im Erfolgsfall
     */
    public function markFileDeleted(int $tmpId, int $fieldId, ?int $languageId = null): bool
    {
        if (!$prodData = $this->getProdData($tmpId)) {
            $this->cms->error('_markFileDeleted() Exception: Dataset is not temporary.');
            return false;
        }
        $areaId = $prodData[1];
        // Feld muss existieren, ein Upload-Feld sein UND zur Area der Arbeitskopie
        // gehören (sonst ließe sich fremde Datei über field_id löschen)
        if (!$fieldData = $this->fieldData($fieldId)) {
            $this->cms->error('_markFileDeleted() Exception: Field not found.');
            return false;
        }
        if ((string)($fieldData['type'] ?? '') !== 'file'
            || (string)($fieldData['display_type'] ?? '') !== 'upload'
        ) {
            $this->cms->error('_markFileDeleted() Exception: Wrong field type.');
            return false;
        }
        if ((int)$fieldData['area_id'] !== $areaId) {
            $this->cms->error('_markFileDeleted() Exception: Wrong area_id.');
            return false;
        }
        $fieldName = $fieldData['name'];
        $schemaVirtual = $this->virtualSchema($areaId, false);

        $this->db->pushResult();
        try {
            $this->db->beginTransaction();
            // Zwischenstand laden (Zeilensperre gegen parallele Änderungen)
            $this->db->query('SELECT `record_id` FROM `record` WHERE `record_id` = ? FOR UPDATE', 'i', [$tmpId]);
            if ($this->db->num_rows < 1) {
                throw new \Exception('removeUpload() Exception: working copy not found.');
            }
            $cache = $this->_loadTmpData($tmpId);
            // Mehrsprachiges Feld ohne Sprachangabe bzw. einsprachiges mit: die
            // Sprache richtet sich nach dem Feld
            if (!$this->cms->isMultilingualField($fieldData)) $languageId = null;
            elseif ($languageId === null) $languageId = $this->languageIdsOf($tmpId)[0] ?? 0;

            // Ausgangs-Feld + rekursiv abhängige Thumbnails abräumen.
            // $handled schützt vor Endlos-Rekursion durch gegenseitige
            // parent_field_id-Zuweisung (jedes Feld wird nur einmal verarbeitet).
            $current = $this->fileEntry((array)($cache[$fieldName] ?? []), $languageId);
            if (empty($current['extension'])) {
                // Keine Datei gespeichert
                $this->db->commit();
                return true; // finally wird trotzdem aufgerufen
            }
            $handled = [];
            $this->_removeFileEntries($tmpId, $cache, $fieldName, $schemaVirtual, $handled, $languageId);

            // Aktualisierten Arbeitsstand schreiben, Edit-Lock auffrischen
            $this->_saveTmpData($tmpId, $cache, null, touch: true);
            $this->db->commit();

        } catch (\Exception $e) {
            $this->db->rollback();
            $this->cms->error($e->getMessage());
            return false;
        } finally {
            $this->db->popResult();
        }
        return true;
    }


    /**
     * Helper für markFileDeleted(), rekursiver Aufruf
     *
     * @param array &$handled  bereits verarbeitete Feldnamen (Endlos-Schutz)
     */
    private function _removeFileEntries(int $tmpId, array &$cache, string $fieldName, array $schemaVirtual, array &$handled, ?int $languageId = null): void
    {
        // Endlos-Rekursion durch gegenseitige parent_field_id-Zuweisung verhindern
        if (in_array($fieldName, $handled, true)) return;
        $handled[] = $fieldName;

        $fieldData = $schemaVirtual[$fieldName] ?? null;
        if (empty($fieldData)) return;
        $fieldId = (int)$fieldData['field_id'];

        // Eintrag hat das Format [$type, $fileData] - bei mehrsprachigen
        // Feldern je Sprache (VirtualFieldsTrait::fileEntry())
        $fileData = $this->fileEntry((array)($cache[$fieldName] ?? []), $languageId);
        $extension = (string)($fileData['extension'] ?? '');

        $mediaDir = $this->config->get('paths.media_dir');
        // Ablageschema: {field_id}/{record_id}[_{language_id}].{extension}
        if ($extension !== '' && !empty($fileData['temporary'])
            && file_exists($tmpFile = "$mediaDir/" . $this->cms->mediaPath($fieldId, $tmpId, $languageId, $extension))
        ) {
            if (!@unlink($tmpFile)) {
                $this->system->log('file-errors', "$tmpFile : deletion failed.");
            }
        }

        // `data_fil`-Eintrag der Arbeitskopie entfernen, sonst würde ihn
        // promoteTmpFiles() beim checkIn auf den produktiven Datensatz umschreiben
        // (Eintrag ohne zugehörige Datei -> kaputter Preview-Link)
        if ($fn = $this->cms->resolveVirtualField($fieldName)) {
            $sql = 'DELETE FROM `data_fil` WHERE `record_id` = ? AND `fieldname_id` = ?';
            $this->db->query($sql, 'ii', [$tmpId, (int)$fn['fieldname_id']]);
        }

        // Löschmarkierung im Zwischenstand setzen - Format [$type, $fileData]
        // beibehalten: promoteTmpFiles() prüft $entry[0] === 'file' und löscht nur
        // dann die ggf. am produktiven Datensatz hängende Datei (dieser Sprache)
        $this->setFileEntry($cache, $fieldName, $languageId, [
            'extension' => '', 'mime_type' => '', 'width' => 0,
            'height' => 0, 'size' => 0, 'filename' => '',
            'file_language_id' => $languageId,
            'deleted' => true
        ]);

        // Abhängige Thumbnails ebenfalls löschen
        // (Thumbnail-Felder haben type 'file' und display_type 'thumbnail')
        foreach ($schemaVirtual as $name => $colDef) {
            if ((string)($colDef['type'] ?? '') !== 'file') continue;
            if ((string)($colDef['display_type'] ?? '') !== 'thumbnail') continue;
            if ((int)($colDef['parent_field_id'] ?? 0) === $fieldId) {
                $this->_removeFileEntries($tmpId, $cache, $name, $schemaVirtual, $handled, $languageId);
            }
        }
    }

    public function fieldData(int $fieldId): ?array
    {
        $this->db->query("SELECT * FROM `field` WHERE `field_id`=?", 'i', [$fieldId]);
        if ($this->db->num_rows < 1) return null;
        return $this->db->fetch();
    }

    /**
     * Hilfsfunktion: 'rel_area_ids' eines Pointer-Feldes ermitteln
     */
    public function getRelAreaIds(int $fieldId): array
    {
        $sql = 'SELECT `area_id` FROM `rel_field_area` WHERE `field_id`=?';
        $this->db->query($sql, 'i', [$fieldId]);
        return $this->db->fetchAll(); // flaches Array, ggf []
    }

    /**
     * Hilfsfunktion: 'converter_ids' eines Thumbnail-Feldes in ihrer Reihenfolge
     * (`rel_field_converter`, position) - die Thumbnail-Erzeugung nimmt den ersten
     * passenden. Dasselbe liefert Field::getConverterIds().
     */
    public function getConverterIds(int $fieldId): array
    {
        $this->db->pushResult();
        $sql = 'SELECT `converter_id` FROM `rel_field_converter` WHERE `field_id`=? ORDER BY `position` ASC, `converter_id` ASC';
        $this->db->query($sql, 'i', [$fieldId]);
        $ids = array_map('intval', $this->db->fetchAll()); // flaches Array, ggf []
        $this->db->popResult();
        return $ids;
    }

     /**
     * Ziel-Datensatz innerhalb Relation nach oben oder unten schieben
     * Datensatz muss tempoär sein (check-out)
     * 
     * @param int $srcField     Relationfeld-Id (relation/pointer)
     * @param int $srcId        Datensatz von dem aus die Verknüpfungen stattfinden
     * @param int $destId       Verknüpfter Zieldatensatz der verschoben werden soll
     * @param string $function  'up' / 'down'
     * @return bool             true Im Ergolgsfall
     */
    /*
    public function moveRelationDestId(int $srcField, int $srcId, int $destId, string $function): bool 
    {
        if ($srcField < 1) return false;
        if ($srcId < 1) return false;
        if ($destId < 1) return false;
        
        // srcId
        if (!$prodData = $this->getProdData($srcId)) {
            // Datensatz ist nicht temporär oder gehört nicht dem Session-Admin
            return false;
        }
        $areaId = $prodData[1];

        // srcField
        if (!$fieldData = $this->fieldData($srcField)) {
            // nicht gefunden
            return false;
        }

        // Vergleiche area_id des Feldes mit Area-Id des Quelldatensatzes
        if ((int)$fieldData['area_id'] !== $areaId) {
            // nicht gefunden
            return false;
        }

        if (!in_array($fieldData['type'], $this->cms->relationTypes, true)) {
            // kein Relationfeld
            return false;
        }

        // destId
        // Aktuelle Position des Datensatzes ermitteln
        $sql = "SELECT `position` FROM `data_rel` WHERE `field_id` = ? AND `src_id` = ? AND `dest_id` = ?";
        $this->db->query($sql, 'iii', [$srcField, $srcId, $destId]);
        if ($this->db->num_rows < 1) {
            // Ausgangsverknüpfung nicht gefunden
            return false;
        }
        $currentPos = $this->db->fetchNum()[0];

        // Den direkten Tauschpartner (nächstkleinere oder nächstgrößere Position) finden
        // (nur produktive Datensätze beachten)
        if ($function === 'up') {
            $sign = '<';
            $order = 'DESC';
        }
        else {
            $sign = '>';
            $order = 'ASC';
        }
        $sql = "SELECT `d`.`dest_id`, `d`.`position` FROM `data_rel` `d`"
            ." LEFT JOIN `record` `r` ON `d`.`dest_id` = `r`.`record_id`"
            ." WHERE `d`.`field_id` = ? AND `d`.`src_id` = ? AND `d`.`position` $sign ?"
            ." AND `r`.`tmp`=0 AND `r`.`deleted`=0"
            ." ORDER BY `d`.`position` $order LIMIT 1";
        $this->db->query($sql, 'iii', [$srcField, $srcId, (int)$currentPos]);
        if ($this->db->num_rows < 1) {
            // Tauschpartner nicht gefunden
            return false;
        }
        [$swapDestId, $swapPos] = $this->db->fetchNum();
        
        // Positionen innerhalb einer Transaktion überkreuz tauschen
        try {
            $this->db->beginTransaction();

            // Aktuellen Eintrag auf die Position des Tauschpartners setzen
            $sql = "UPDATE `data_rel` SET `position` = ? WHERE `field_id` = ? AND `src_id` = ? AND `dest_id` = ?";
            $this->db->query($sql, 'iiii', [(int)$swapPos, $srcField, $srcId, $destId]);

            // Tauschpartner auf die alte Position des aktuellen Eintrags setzen
            $sql = "UPDATE `data_rel` SET `position` = ? WHERE `field_id` = ? AND `src_id` = ? AND `dest_id` = ?";
            $this->db->query($sql, 'iiii', [(int)$currentPos, $srcField, $srcId, (int)$swapDestId]);

            $this->db->commit();
            
        } catch (\Exception $e) {
            $this->db->rollback();
            return false;
        }

        return true;
    }
    */

    /**
     * Verschiebt eine hochgeladene temporäre Datei ins Zielverzeichnis.
     * 
     * @param string $tmpFile        Pfad zur temporären hochgeladenen Datei (z. B. $_FILES['file']['tmp_name'])
     * @param string $baseDir        Grundverzeichnis
     * @param string $relativePath   relativer Pfad zum Grundverzeichnis inkl. Dateiname
     * @param bool   $isUploaded     true: Datei kommt per File-Upload, false: Datei liegt im FTP-Upload-Verzeichnis
     * @return int                   Dateigröße in Bytes oder 0 Bytes im Fehlerfall (auch bei 0-Byte-Datei)
     */
    protected function _moveUploadedFile(string $tmpFile, string $baseDir, string $relativePath, bool $isUploaded = true): ?int
    {
        // Prüfen, ob die temporäre Datei überhaupt existiert
        if ($isUploaded) {
            if (!is_uploaded_file($tmpFile)) {
                return null;
            }
        } else {
            if (!file_exists($tmpFile)) {
                return null;
            }
        }

        // Pfade bereinigen und normalisieren
        $baseDir = rtrim($baseDir, '/\\');
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');

        // Den relativen Verzeichnispfad von der eigentlichen Datei trennen
        $relativeDir = dirname($relativePath);

        // Sicherstellen, dass die Verzeichnisstruktur existiert
        if ($relativeDir !== '.' && $relativeDir !== '') {
            if (!$this->system->createDir($baseDir, $relativeDir)) {
                return null;
            }
        }
        $fullPath = $baseDir . '/' . $relativePath;

        // Datei aus dem Temp-Ordner an das Ziel verschieben
        if ($isUploaded) {
            // aus File-Upload
            if (!move_uploaded_file($tmpFile, $fullPath)) {
                return null;
            }
        }
        else {
            // aus dem Upload-Verzeichnis
            if (!copy($tmpFile, $fullPath)) {
                return null;
            }
        }

        // Berechtigungen ermitteln
        $filePermissions = $this->config->get('permissions.file') ?? 0664;

        // Dateirechte explizit setzen, um umask-Einschränkungen zu umgehen.
        chmod($fullPath, $filePermissions);

        $fileSize = @filesize($fullPath);
        return (int)$fileSize;
    }

    /**
     * Sichert Datei-Eingaben VOR der Eingabeprüfung des Haupt-Speichervorgangs.
     * Jede akzeptierte Datei (Upload aus $_FILES bzw. ausgewählte Server-Datei aus
     * $data) wird sofort auf Typ geprüft und an die Arbeitskopie gebunden
     * (physisch unter deren Id + cache-Eintrag mit 'temporary'-Flag) und in einer
     * EIGENEN Transaktion committet.
     * Dadurch überstehen bereits akzeptierte Dateien eine anschließende, an anderen
     * Feldern (z. B. leerer Pflicht-Titel) scheiternde Prüfung - die Datei muss nicht
     * erneut hochgeladen werden und erscheint in der wieder angezeigten Eingabemaske.
     *
     * Thumbnails werden hier NICHT erzeugt: die cache-Einträge werden mit
     * 'thumbnails_pending' markiert und erst beim finalen Speichern über
     * writePendingThumbnails() abgearbeitet.
     *
     * Doppel-Verarbeitung im finalen saveVirtualFields() ist ausgeschlossen:
     * _saveUploadField() bricht dort über $this->processedUploadFields ab
     * (zusätzlich bei Uploads über is_uploaded_file() == false, da die Datei hier
     * bereits mit move_uploaded_file() verschoben wurde).
     *
     * @param int   $tmpId           Id der temporären Arbeitskopie
     * @param array &$schemaVirtual  Schema mit virtuellen Feldern
     * @param array $data            Zu speichernde Inhalte aus Dataset::save()
     *                               (enthält ggf. $data[$name]['serverfile'])
     * @return bool                  true: alle (ggf. keine) Dateien ok
     *                               false: eine Datei wurde abgelehnt (Mime/Größe/
     *                                      verbotene Extension) - Meldung in Cms::errors
     */
    protected function secureUploads(int $tmpId, array &$schemaVirtual, array $data = []): bool
    {
        if ($tmpId < 1) return true;
        // Je Speichervorgang von vorn: die Liste verhindert nur die doppelte
        // Verarbeitung INNERHALB eines save() (hier und noch einmal in
        // saveVirtualFields()). Ohne den Neustart bliebe beim zweiten Datensatz eines
        // Laufs (Import, Stapelverarbeitung) jede Datei liegen, deren Feld der erste
        // Datensatz schon verarbeitet hat.
        $this->processedUploadFields = [];
        $this->db->pushResult();
        try {
            $this->db->beginTransaction();
            // Arbeitsstand der Arbeitskopie als Basis (wird von _saveUploadField fortgeschrieben)
            $this->recordCache = $this->_loadTmpData($tmpId);
            $languageIds = $this->recordLanguageIds($tmpId);
            $processed = false;
            foreach ($schemaVirtual as $name => $colDef) {
                if ((string)($colDef['type'] ?? '') !== 'file') continue;
                if ((string)($colDef['display_type'] ?? '') !== 'upload') continue;
                // bindet die Datei an $tmpId (physisch + Arbeitsstand mit 'temporary'- und
                // 'thumbnails_pending'-Flag; kein `data_fil`-Eintrag, siehe
                // promoteTmpFiles()), wirft bei Ablehnung;
                // liefert false wenn keine (neue) Datei angegeben ist
                foreach ($this->_uploadLanguages($colDef, $languageIds) as $lang) {
                    if ($this->_saveUploadField($tmpId, $name, $colDef, $schemaVirtual, $data, $lang)) {
                        $processed = true;
                    }
                }
            }
            if ($processed) {
                // angepassten Arbeitsstand zurückschreiben, Edit-Lock auffrischen
                $this->_saveTmpData($tmpId, $this->recordCache, null, touch: true);
            }
            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollback();
            $msg = $e->getMessage();
            if (!str_starts_with($msg, '#')) $this->cms->error($msg);
            return false;
        } finally {
            $this->db->popResult();
        }
    }

    /**
     * Eingegebene Passwörter VOR der Eingabeprüfung in der Arbeitskopie sichern -
     * das Gegenstück zu secureUploads() für Passwort-Felder.
     *
     * Ein Passwortfeld wird in der Maske nie vorbelegt: scheitert die Prüfung an
     * einem anderen Feld oder wechselt der Redakteur den Reiter, wäre die Eingabe
     * verloren. Deshalb wandert sie hier - gehasht, wie sie später in `data_pwd`
     * steht - in den Arbeitsstand der Arbeitskopie (eigene Transaktion, bleibt
     * auch bei einer scheiternden Prüfung bestehen). Dataset::save() nimmt den Hash
     * als Eingabe, wenn das Feld beim nächsten Mal leer bleibt; die Maske zeigt
     * dann den Hinweis "Passwort ist gesetzt" statt eines Pflichtfelds.
     *
     * Geprüft (Mindestlänge usw.) wird weiterhin die Roheingabe in $data.
     *
     * @param int   $tmpId          Arbeitskopie
     * @param array $schemaVirtual  Schema des Bereichs
     * @param array $data           Eingaben (Roheingabe der Passwort-Felder)
     */
    protected function securePasswords(int $tmpId, array $schemaVirtual, array $data): void
    {
        if ($tmpId < 1) return;
        $hashes = [];
        foreach ($schemaVirtual as $name => $colDef) {
            if (($colDef['type'] ?? '') !== 'password' && ($colDef['display_type'] ?? '') !== 'password') continue;
            $value = $data[$name] ?? '';
            if (is_array($value) || trim((string)$value) === '') continue;
            // Ein Wert aus einer Exportdatei ist bereits der Hash (Record::importSave())
            $hashes[$name] = in_array($name, $this->prehashedFields, true)
                ? (string)$value
                : $this->crypt->passwordHash((string)$value);
        }
        if (empty($hashes)) return;

        $this->db->pushResult();
        try {
            $this->db->beginTransaction();
            $cache = $this->_loadTmpData($tmpId);
            foreach ($hashes as $name => $hash) {
                $cache[$name] = ['password', $hash];
            }
            $this->_saveTmpData($tmpId, $cache, null, touch: true);
            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollback();
        } finally {
            $this->db->popResult();
        }
    }

    /**
     * Pflichtprüfung eines Upload-Feldes (Flag 'not_empty' bzw. min >= 1).
     *
     * Für Dateien greift die Zählung in Dataset::validateValue() nicht: der Wert eines
     * Upload-Feldes steht nicht in $data, sondern - nach secureUploads() - im
     * Zwischenstand (Arbeitsstand) der Arbeitskopie, beim Speichern ohne Arbeitskopie in
     * `data_fil` bzw. im Request. Eine Datei gilt als vorhanden, wenn
     *     1. der Zwischenstand einen nicht gelöschten Eintrag mit Dateiendung führt, oder
     *     2. der Request eine Datei bringt (Upload oder 'serverfile'), oder
     *     3. `data_fil` des produktiven Datensatzes eine Datei kennt.
     * 2. und 3. nur ohne Arbeitskopie - mit Arbeitskopie hat secureUploads() den
     * Request bereits in den Zwischenstand übernommen.
     *
     * Bei mehrsprachigen Upload-Feldern zählt allein die Hauptsprache der Instanz:
     * eine weitere Sprache ohne eigene Datei bekommt den Fallback (so wie die
     * Eingabemaske dort auch keinen Stern zeigt). Thumbnail-Felder werden
     * automatisch befüllt und übergangen.
     *
     * @param string $name      Feldname
     * @param array  $colDef    Felddefinition aus virtualSchema()
     * @param array  $data      zu speichernde Inhalte (ggf. $data[$name]['serverfile'])
     * @param array  $tmpCache  Arbeitsstand der Arbeitskopie ([] ohne Arbeitskopie)
     * @param array  $langCtx   Sprachen der Instanz (Dataset::languageContext())
     * @param int    $recordId  produktiver Datensatz beim Speichern OHNE Arbeitskopie,
     *                          sonst 0
     * @return bool  true: ok; false: Fehler (Meldung in Cms::errors)
     * @see Camindo\Core\Dataset::save()
     */
    protected function validateFileRequired(string $name, array $colDef, array $data, array $tmpCache, array $langCtx, int $recordId = 0): bool
    {
        if ((string)($colDef['display_type'] ?? '') !== 'upload') return true;
        $flags = (array)($colDef['flags'] ?? []);
        if (!in_array('not_empty', $flags, true) && (int)($colDef['min'] ?? 0) < 1) return true;

        $isMultilingual = $this->cms->isMultilingualField($colDef) && !empty($langCtx['language_ids']);
        $languageId = $isMultilingual ? (int)($langCtx['primary_id'] ?? 0) : null;

        // 1. Zwischenstand der Arbeitskopie
        if (isset($tmpCache[$name])) {
            $entry = $this->fileEntry((array)$tmpCache[$name], $languageId);
            if (!empty($entry) && empty($entry['deleted']) && (string)($entry['extension'] ?? '') !== '') {
                return true;
            }
        }
        elseif (empty($tmpCache)) {
            // 2. Ohne Arbeitskopie: Datei im Request?
            $fileError = $_FILES['data']['error'][$name] ?? null;
            if ($languageId !== null) $fileError = is_array($fileError) ? ($fileError[$languageId] ?? null) : null;
            if ((int)($fileError ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) return true;
            $fieldValue = $data[$name] ?? null;
            if ($languageId !== null) $fieldValue = is_array($fieldValue) ? ($fieldValue[$languageId] ?? null) : null;
            if (is_array($fieldValue) && trim((string)($fieldValue['serverfile'] ?? '')) !== '') return true;

            // 3. Datei des produktiven Datensatzes
            if ($recordId > 0) {
                $sql = "SELECT 1 FROM `data_fil` WHERE `record_id` = ? AND `field_id` = ? AND `extension` != ''";
                $sqlTypes = 'ii';
                $sqlValues = [$recordId, (int)($colDef['field_id'] ?? 0)];
                if ($languageId !== null) {
                    $sql .= " AND `language_id` = ?";
                    $sqlTypes .= 'i';
                    $sqlValues[] = $languageId;
                }
                $this->db->pushResult();
                $this->db->query($sql . " LIMIT 1", $sqlTypes, $sqlValues);
                $found = ($this->db->num_rows > 0);
                $this->db->popResult();
                if ($found) return true;
            }
        }

        // Fehler - bei mehrsprachigen Feldern am Eingabefeld der Hauptsprache
        $label = $this->cms->textOrSnippet((string)($colDef['label'] ?? $name));
        $errName = $name;
        if ($languageId !== null) {
            $label .= ' | ' . (string)($langCtx['codes'][$languageId] ?? $languageId);
            $errName .= '.' . $languageId;
        }
        $this->createFieldError('error_value_mandatory', $errName, $label);
        return false;
    }

    /**
     * Lade Media Converter
     */
    private function loadConverter(int $converterId): ?array
    {
        if (empty($converterId)) return [];
        $this->db->query('SELECT * FROM `converter` WHERE `converter_id` = ?', 'i', [$converterId]);
        return $this->db->fetch();
    }

    // Öffentlicher Zugriff auf den Arbeitsstand
    // ====================

    /**
     * Arbeitsstand zurücksetzen, bevor ein Datensatz bearbeitet wird, der nicht aus
     * einem Speichervorgang kommt (promoteBackgroundThumbnails()). Beide Listen sind
     * je Datensatz zu führen: $processedParentFieldIds merkt sich field_ids, nicht
     * Datensatz-Ids, und würde sonst beim zweiten Datensatz eines Laufs alle
     * Thumbnail-Felder als "schon erledigt" ansehen.
     */
    public function clearRecordCache(): void
    {
        $this->recordCache = [];
        $this->processedParentFieldIds = [];
        $this->processedUploadFields = [];
    }

    /** Aktueller Arbeitsstand, z. B. zur Kontrolle nach writeThumbnails() */
    public function getRecordCache(): array
    {
        return $this->recordCache ?? [];
    }
}
