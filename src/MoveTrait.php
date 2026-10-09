<?php
/**
 * Trait für die Tabelle `record`: einen Datensatz in einen anderen Inhaltsbereich
 * verschieben.
 *
 * Ein Datensatz besteht aus der `record`-Zeile (Metafelder) und den Inhalten seiner
 * benutzerdefinierten Felder in den data_*-Tabellen - und die Felder gehören dem
 * Bereich. Ein Bereichswechsel ist deshalb kein UPDATE der area_id, sondern eine
 * Übertragung: jedes Feld des Herkunftsbereichs wird auf ein Feld des Zielbereichs
 * abgebildet oder fällt weg.
 *
 * DIE ABBILDUNG (MAPPING)
 * --------------------
 *     [quellfeldname => zielfeldname | '']       '' = Inhalt verwerfen
 *
 * Zulässig sind nur Zielfelder, die dem Quellfeld entsprechen (moveCompatible()):
 * gleicher Typ, gleiche Mehrsprachigkeit, bei Datei-Feldern beide Upload-Felder,
 * bei Verknüpfungen derselbe Zielbereich (relation) - für Verweise (pointer) wird
 * beim Verschieben geprüft, ob die verwiesenen Datensätze in den Zielbereichen des
 * Zielfeldes liegen. Ohne Abbildung sind Metafelder (sie stehen in `record` selbst
 * und bleiben stehen), Volltextfelder (werden erzeugt) und Thumbnails (werden aus
 * dem Upload-Feld des Zielbereichs neu gerechnet).
 *
 * DER WEG ÜBER DIE ARBEITSKOPIE
 * --------------------
 * Verschoben wird nicht mit direkten UPDATEs, sondern über Dataset::save(): so
 * gelten die Felddefinitionen des Zielbereichs (Pflichtfelder, Eindeutigkeit,
 * Grenzwerte, Zielbereiche von Verknüpfungen, Pflicht-Uploads), und alle Folgen
 * eines Speicherns laufen von selbst - data_*-Zeilen je Sprache, `data_dft`,
 * `record_cache`, Thumbnails, Volltext, Baumpflege in beiden Bereichen. Der Ablauf:
 *
 *   movePrepare() - eine Transaktion:
 *   1. checkOut(): Arbeitskopie mit dem produktiven Stand (Arbeitsstand in
 *      `record_cache`, Verknüpfungen)
 *   2. die Arbeitskopie wird zum Zielbereich umgebaut: area_id, die Felder des
 *      Arbeitsstands nach dem Mapping umbenannt, Dateien der Upload-Felder in die Verzeichnisse
 *      der Zielfelder kopiert (als 'temporary' + 'thumbnails_pending' markiert),
 *      data_rel-Zeilen auf die Zielfelder umgehängt, Passwort-Hashes im Arbeitsstand
 *   moveSave():
 *   3. save() mit area_id = Zielbereich und den abgebildeten Werten aus dem
 *      Arbeitsstand. Die Werte liegen im Datenbankformat vor - $storedFormatFields
 *      lässt Dataset die Eingabe-Umwandlung aus, prüft aber alle Regeln des
 *      Zielbereichs
 *   4. Record::hookOnSave() räumt die data_*-Zeilen der Felder des Herkunftsbereichs
 *      ab (removeAreaData()), bevor die neuen geschrieben werden, hookOnSaved()
 *      danach die Mediendateien (removeAreaFiles()) - beides in der Transaktion
 *      von save(), und nur beim endgültigen Speichern (nicht bei stayTemporary)
 *
 * Nach movePrepare() ist die Kopie eine gewöhnliche Arbeitskopie des Zielbereichs
 * mit dem Original im Herkunftsbereich als Bearbeitungssperre: die Eingabemaske des
 * Zielbereichs kann sie anzeigen, Reiterwechsel zwischenspeichern (stayTemporary),
 * cancel() verwirft sie samt kopierter Dateien - das Original bleibt bis zum
 * Check-in unverändert. Scheitert die Prüfung in moveSave(), zeigt RecordBackendMove
 * deshalb die Eingabemaske des Zielbereichs mit den Fehlern; ihr Speichern ist der
 * Check-in (Record::save() mit area_id = Zielbereich), ihr Abbrechen lässt den
 * Datensatz im bisherigen Bereich. moveToArea() (Stapelverarbeitung) verwirft die
 * Kopie bei einem Fehler selbst. Dateioperationen sind nicht transaktional: die
 * kopierten Dateien räumt cancel() über die 'temporary'-Einträge ab.
 *
 * NICHT VERSCHOBEN WERDEN
 * --------------------
 *   - Datensätze mit Kindern im Baum des Herkunftsbereichs (die Kinder verlören
 *     ihren Vater)
 *   - Datensätze, an denen gerade jemand anderes arbeitet (Bearbeitungssperre)
 *   - Sprachen, die die Instanz des Zielbereichs nicht führt: liegt der Zielbereich
 *     in einer anderen Instanz, gehen deren Inhalte verloren (Hinweis in der Maske)
 *
 * @see Camindo\Core\Modules\Record\RecordBackendMove  die Maske dazu
 * @see Camindo\Core\Dataset::$storedFormatFields
 * @see Camindo\Core\TemporaryTrait                     checkOut / checkIn
 */
namespace Camindo\Core;

use Camindo\Core\Modules\Area\Area;

trait MoveTrait
{
    /**
     * Felder eines Bereichs, die beim Verschieben abgebildet werden können.
     *
     * Ohne Metafelder (Spalten von `record`), Volltextfelder und Thumbnails - siehe
     * Klassenkommentar. Gilt für Quell- UND Zielbereich.
     *
     * @param array $schemaVirtual  virtualSchema($areaId, false)
     * @return array  name => Felddefinition, in Feldreihenfolge
     */
    public function moveFields(array $schemaVirtual): array
    {
        $fields = [];
        foreach ($schemaVirtual as $name => $colDef) {
            if (isset($this->cms->schema[$name])) continue;
            $type = (string)($colDef['type'] ?? '');
            if ($type === 'fulltext') continue;
            if ($type === 'file' && ($colDef['display_type'] ?? '') === 'thumbnail') continue;
            $fields[$name] = $colDef;
        }
        return $fields;
    }

    /**
     * Passt ein Zielfeld zu einem Quellfeld?
     *
     *     Typ                 gleich (relation und relation1 sind verschiedene Typen)
     *     multilingual        gleich (Cms::isMultilingualField())
     *     file                nur Upload-Felder
     *     relation/relation1  derselbe Zielbereich - das Gegenfeld liegt dort
     *     pointer/pointer1    keine Vorprüfung; die verwiesenen Datensätze werden
     *                         beim Verschieben gegen die Zielbereiche geprüft
     *     link                das Zielfeld lässt alle Zielbereiche des Quellfeldes zu.
     *                         Ohne Abbildung werden die fest verknüpften Inhalte beim
     *                         Check-in gelöscht (LinkTrait::checkInDependents())
     *
     * @param array $source  Felddefinition im Herkunftsbereich
     * @param array $target  Felddefinition im Zielbereich
     */
    public function moveCompatible(array $source, array $target): bool
    {
        $type = (string)($source['type'] ?? '');
        if ($type === '' || $type !== (string)($target['type'] ?? '')) return false;
        if ($this->cms->isMultilingualField($source) !== $this->cms->isMultilingualField($target)) return false;
        if ($type === 'file') {
            return ($source['display_type'] ?? '') === 'upload' && ($target['display_type'] ?? '') === 'upload';
        }
        if (str_starts_with($type, 'relation')) {
            return (int)($source['rel_area_id'] ?? 0) === (int)($target['rel_area_id'] ?? 0);
        }
        if (in_array($type, $this->cms->dependentTypes, true)) {
            // link: das Zielfeld muss alle Zielbereiche des Quellfeldes zulassen -
            // sonst verlören die fest verknüpften Inhalte ihren Quelldatensatz
            $sourceAreas = array_map('intval', $this->getRelAreaIds((int)($source['field_id'] ?? 0)));
            $targetAreas = array_map('intval', $this->getRelAreaIds((int)($target['field_id'] ?? 0)));
            return empty(array_diff($sourceAreas, $targetAreas));
        }
        return true;
    }

    /**
     * Zielfelder, die für ein Quellfeld in Frage kommen.
     *
     * @param array $source        Felddefinition im Herkunftsbereich
     * @param array $targetFields  moveFields() des Zielbereichs
     * @return array  name => Felddefinition
     */
    public function moveTargets(array $source, array $targetFields): array
    {
        return array_filter($targetFields, fn(array $target) => $this->moveCompatible($source, $target));
    }

    /**
     * Vorgabe der Abbildung: jedes Quellfeld auf das gleichnamige Zielfeld, wenn es
     * passt (moveCompatible()), sonst '' (verwerfen) - die Vorauswahl der Maske
     * (RecordBackendMove) und der API (record.move mit auto_map).
     *
     * @param array $sourceFields  moveFields() des Herkunftsbereichs
     * @param array $targetFields  moveFields() des Zielbereichs
     * @return array               quellfeld => zielfeld | ''
     */
    public function moveDefaultMapping(array $sourceFields, array $targetFields): array
    {
        $mapping = [];
        foreach ($sourceFields as $name => $colDef) {
            $mapping[$name] = isset($targetFields[$name]) && $this->moveCompatible($colDef, $targetFields[$name]) ? (string)$name : '';
        }
        return $mapping;
    }

    /**
     * Abbildung prüfen und vervollständigen.
     *
     * Jedes Quellfeld bekommt einen Eintrag ('' = verwerfen), unbekannte Quellfelder
     * fallen weg, unpassende Zielfelder sind ein Fehler, und kein Zielfeld darf
     * zweimal belegt werden. Fehler landen in Cms::errors (Schlüssel = Quellfeld).
     *
     * @param array $sourceFields  moveFields() des Herkunftsbereichs
     * @param array $targetFields  moveFields() des Zielbereichs
     * @param array $mapping       quellfeld => zielfeld | ''
     * @return array|null          bereinigte Abbildung, null bei Fehlern
     */
    public function moveMapping(array $sourceFields, array $targetFields, array $mapping): ?array
    {
        $result = [];
        $used = [];
        $ok = true;
        foreach ($sourceFields as $name => $colDef) {
            $target = preg_replace('/[^a-z0-9_\-]/', '', (string)($mapping[$name] ?? ''));
            $result[$name] = '';
            if ($target === '') continue;
            $label = (string)($colDef['label'] ?? $name);
            if (!isset($targetFields[$target]) || !$this->moveCompatible($colDef, $targetFields[$target])) {
                $this->createFieldError('record.error_move_target_field', $name, $label, $target);
                $ok = false;
                continue;
            }
            if (isset($used[$target])) {
                $this->createFieldError('record.error_move_target_twice', $name, $label, $target);
                $ok = false;
                continue;
            }
            $used[$target] = true;
            $result[$name] = $target;
        }
        return $ok ? $result : null;
    }

    /**
     * Datensatz in einen anderen Inhaltsbereich verschieben - siehe Klassenkommentar.
     *
     * movePrepare() + moveSave() in einem Zug: scheitert die Prüfung im Zielbereich,
     * wird die Arbeitskopie verworfen (cancel()) und der Datensatz bleibt unverändert
     * im Herkunftsbereich; die Fehlermeldungen stehen in Cms::errors (Feldfehler
     * unter dem Namen des ZIELFELDES, Fehler der Abbildung unter dem Quellfeld).
     * Wer die Arbeitskopie im Fehlerfall behalten will (Eingabemaske des Zielbereichs
     * mit den Fehlern, RecordBackendMove), ruft die beiden Schritte selbst.
     *
     * @param int   $recordId      produktiver Datensatz
     * @param int   $targetAreaId  Zielbereich
     * @param array $mapping       quellfeld => zielfeld | '' (siehe moveMapping())
     * @param bool  $simulate      true: nur prüfen (Stapelverarbeitung), siehe moveSave()
     * @return bool                true, wenn verschoben (Simulation: ohne Beanstandung)
     */
    public function moveToArea(int $recordId, int $targetAreaId, array $mapping, bool $simulate = false): bool
    {
        if (!$tmpId = $this->movePrepare($recordId, $targetAreaId, $mapping)) return false;
        $ok = $this->moveSave($tmpId, $simulate);
        if ($simulate || !$ok) {
            // Fehlermeldungen über das cancel() hinweg retten
            $errors = $this->cms->errors;
            $this->cancel($tmpId);
            $this->cms->errorsReset();
            foreach ($errors as $key => $error) {
                $this->cms->error((string)$error['text'], is_int($key) ? '' : (string)$key, (string)($error['label'] ?? ''));
            }
        }
        return $ok;
    }

    /**
     * Schritt 1: die Arbeitskopie für den Zielbereich anlegen (Klassenkommentar,
     * Schritte 1 und 2).
     *
     * Prüft Datensatz, Zielbereich, Bearbeitungssperre, Kinder und Abbildung, checkt
     * den Datensatz aus und baut die Arbeitskopie zum Zielbereich um: area_id, die
     * Felder des Arbeitsstands nach dem Mapping umbenannt, Dateien der Upload-Felder
     * in die Verzeichnisse der Zielfelder kopiert, Verknüpfungen umgehängt,
     * Passwort-Hashes im Arbeitsstand. Alles in einer Transaktion - danach steht die
     * Kopie wie eine gewöhnliche Arbeitskopie im Zielbereich: Cms::fetch() liest sie,
     * die Eingabemaske des Zielbereichs kann sie anzeigen, cancel() verwirft sie
     * (samt kopierter Dateien), und Record::save() mit area_id = Zielbereich checkt
     * sie ein - genau das tut moveSave().
     *
     * @param int   $recordId      produktiver Datensatz
     * @param int   $targetAreaId  Zielbereich
     * @param array $mapping       quellfeld => zielfeld | '' (siehe moveMapping())
     * @return int|null            Id der Arbeitskopie, null bei Fehlern (Cms::errors)
     */
    public function movePrepare(int $recordId, int $targetAreaId, array $mapping): ?int
    {
        // Datensatz und Bereiche
        // --------------------
        if (!$data = $this->load($recordId)) return null;
        if (!empty($data['tmp']) || !empty($data['deleted'])) {
            $this->cms->error($this->cms->snippet('record.error_move_not_productive'));
            return null;
        }
        $sourceAreaId = (int)$data['area_id'];
        if ($targetAreaId < 1 || $targetAreaId === $sourceAreaId) {
            $this->cms->error($this->cms->snippet('record.error_move_same_area'));
            return null;
        }
        // Rechte der Benutzergruppe: Quelle Schreiben + Löschen, Ziel Anlegen
        $ownerId = isset($data['owner_id']) ? (int)$data['owner_id'] : null;
        if (!$this->accessCheck('write', $sourceAreaId, $ownerId)
            || !$this->accessCheck('delete', $sourceAreaId, $ownerId)
            || !$this->accessCheck('create', $targetAreaId)
        ) {
            return null;
        }
        // Typ und Instanz aus dem Cache; ob der Bereich als gelöscht markiert ist,
        // weiß nur die Tabelle
        $targetArea = $this->areaInfo($targetAreaId);
        if ($targetArea !== null) {
            $this->db->pushResult();
            $this->db->query("SELECT 1 FROM `area` WHERE `area_id` = ? AND `deleted` = 0", 'i', [$targetAreaId]);
            if ($this->db->num_rows < 1) $targetArea = null;
            $this->db->popResult();
        }
        if ($targetArea === null || !in_array((string)$targetArea['type'], Area::CONTENT_TYPES, true)) {
            $this->cms->error($this->cms->snippet('area.error_area_not_found'));
            return null;
        }
        $targetInstanceId = (int)$targetArea['instance_id'];

        // Bearbeitungssperre
        if ($lock = $this->getLock($data)) {
            $this->cms->error($this->cms->snippet('record.error_move_locked'));
            return null;
        }

        // Kinder im Baum des Herkunftsbereichs
        if ($this->isTreeArea($sourceAreaId)) {
            $this->db->pushResult();
            $this->db->query(
                "SELECT COUNT(*) FROM `record` WHERE `parent_id` = ? AND `tmp` = 0",
                'i', [$recordId]
            );
            $children = (int)($this->db->fetchNum()[0] ?? 0);
            $this->db->popResult();
            if ($children > 0) {
                $this->cms->error($this->cms->snippet('record.error_move_has_children'));
                return null;
            }
        }

        // Abbildung
        // --------------------
        $schemaSource = $this->virtualSchema($sourceAreaId, false);
        $schemaTarget = $this->virtualSchema($targetAreaId, false);
        $sourceFields = $this->moveFields($schemaSource);
        $targetFields = $this->moveFields($schemaTarget);
        if (($mapping = $this->moveMapping($sourceFields, $targetFields, $mapping)) === null) {
            return null;
        }

        $mediaDir = $this->config->get('paths.media_dir');
        $sourceLanguageIds = array_map('intval', (array)($this->languageContext($sourceAreaId)['language_ids'] ?? []));
        $langCtx = $this->languageContext($targetAreaId);
        $targetLanguageIds = array_map('intval', (array)($langCtx['language_ids'] ?? []));
        $targetPrimaryId = (int)($langCtx['primary_id'] ?? ($targetLanguageIds[0] ?? 0));

        // Passwörter: die Hashes lassen sich nicht als Eingabe übergeben (sie würden
        // erneut gehasht). Sie wandern als Hash in den Arbeitsstand der Kopie - wie
        // ein in der Eingabemaske eingegebenes Passwort (securePasswords()); Dataset::
        // save() nimmt den Hash von dort, wenn das Feld leer bleibt ($prehashedFields).
        // Ein Passwort-Feld ist einsprachig: es zählt der Hash der Hauptsprache
        $passwords = [];
        foreach ($mapping as $srcName => $dstName) {
            if ($dstName === '' || ($sourceFields[$srcName]['type'] ?? '') !== 'password') continue;
            $this->db->pushResult();
            $this->db->query(
                "SELECT `language_id`, `data` FROM `data_pwd` WHERE `record_id` = ? AND `field_id` = ?",
                'ii', [$recordId, (int)$sourceFields[$srcName]['field_id']]
            );
            $hashes = array_map('strval', $this->db->fetchPairs());
            $this->db->popResult();
            $hash = (string)($hashes[$targetPrimaryId] ?? (reset($hashes) ?: ''));
            if ($hash !== '') $passwords[$dstName] = $hash;
        }

        $copied = [];   // kopierte Dateien (zum Aufräumen bei einem Fehler)
        $tmpId = 0;
        $this->db->beginTransaction();
        try {
            // 1. Arbeitskopie
            // --------------------
            if (!$tmpId = $this->checkOut($data)) {
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            // Arbeitsstand der Kopie lesen, SOLANGE sie noch zum Herkunftsbereich
            // gehört (_loadTmpData() legt dessen Schema zugrunde - mehrsprachige
            // Felder)
            $tmpData = $this->_loadTmpData($tmpId);
            // Die Arbeitskopie gehört ab jetzt zum Zielbereich: Dataset::save(),
            // getProdData() und _saveTmpData() lesen den Bereich von ihr
            $this->db->query(
                "UPDATE `record` SET `area_id` = ?, `instance_id` = ? WHERE `record_id` = ?",
                'iii', [$targetAreaId, $targetInstanceId, $tmpId]
            );

            // 2. Arbeitsstand und Dateien nach dem Mapping umbauen
            // --------------------
            $newTmpData = [];
            foreach ($mapping as $srcName => $dstName) {
                if ($dstName === '') continue;
                $srcDef = $sourceFields[$srcName];
                $dstDef = $targetFields[$dstName];
                $type = (string)$srcDef['type'];
                $srcFieldId = (int)$srcDef['field_id'];
                $dstFieldId = (int)$dstDef['field_id'];

                if (in_array($type, $this->cms->relationTypes, true)) {
                    continue;   // data_rel, siehe unten
                }
                if ($type === 'password') {
                    if (isset($passwords[$dstName])) $newTmpData[$dstName] = ['password', $passwords[$dstName]];
                    continue;
                }
                if ($type === 'file') {
                    // Produktive Dateien in das Verzeichnis des Zielfeldes kopieren,
                    // unter der Id der Arbeitskopie; promoteTmpFiles() zieht sie beim
                    // checkIn auf den Datensatz um und schreibt `data_fil`,
                    // writePendingThumbnails() rechnet die Thumbnails des Zielfeldes
                    // (Quell- und Zielfeld sind beide ein- oder beide mehrsprachig:
                    //  einsprachig ein Eintrag ohne Sprache, mehrsprachig je Sprache
                    //  mit eigener Datei ein Eintrag - siehe _tmpDataFromRecordCache())
                    $entry = $tmpData[$srcName] ?? null;
                    if ($entry === null) continue;
                    foreach ($this->fileEntries($entry) as $langKey => $fileData) {
                        $languageId = ($langKey === '') ? null : (int)$langKey;
                        if ($languageId !== null && !in_array($languageId, $targetLanguageIds, true)) continue;
                        $extension = (string)($fileData['extension'] ?? '');
                        unset($fileData['deleted'], $fileData['temporary'], $fileData['thumbnails_pending']);
                        $fileData['field_id'] = $dstFieldId;
                        $fileData['file_language_id'] = $languageId;
                        if ($extension !== '') {
                            $srcPath = $this->cms->mediaPath($srcFieldId, $recordId, $languageId, $extension);
                            $dstPath = $this->cms->mediaPath($dstFieldId, $tmpId, $languageId, $extension, $targetInstanceId);
                            if (!is_file("$mediaDir/$srcPath")) {
                                throw new \Exception("Error: movePrepare(): file \"$srcPath\" not found.");
                            }
                            if (!$this->system->createDir($mediaDir, dirname($dstPath))
                                || !@copy("$mediaDir/$srcPath", "$mediaDir/$dstPath")
                            ) {
                                $this->system->log('file-errors', "$srcPath > $dstPath : copy failed.");
                                throw new \Exception("Error: movePrepare(): could not copy \"$srcPath\".");
                            }
                            $copied[] = "$mediaDir/$dstPath";
                            $fileData['temporary'] = true;
                            $fileData['thumbnails_pending'] = true;
                        }
                        $this->setFileEntry($newTmpData, $dstName, $languageId, $fileData);
                    }
                    continue;
                }
                if ($type === 'encrypted') {
                    // Verschlüsselte Inhalte: aus `data_enc` des Originals lesen; im
                    // Arbeitsstand stehen sie entschlüsselt (_saveTmpData() verschlüsselt
                    // neu), save() verschlüsselt sie beim Check-in für data_enc
                    $this->db->pushResult();
                    $this->db->query(
                        "SELECT `language_id`, `data`, `empty` FROM `data_enc` WHERE `record_id` = ? AND `field_id` = ?",
                        'ii', [$recordId, $srcFieldId]
                    );
                    $rows = $this->db->fetchAll();
                    $this->db->popResult();
                    $texts = [];
                    foreach ($rows as $row) {
                        $texts[(int)$row['language_id']] = [
                            $this->crypt->decrypt((string)$row['data'], '', true),
                            !empty($row['empty'])
                        ];
                    }
                    if ($this->cms->isMultilingualField($dstDef)) {
                        $value = [];
                        foreach ($targetLanguageIds as $lang) {
                            [$text, $isFallback] = $texts[$lang] ?? ['', true];
                            $value[$lang] = ($isFallback && $lang !== $targetPrimaryId) ? '' : $text;
                        }
                        if ($value[$targetPrimaryId] === '') {
                            $value[$targetPrimaryId] = (string)($texts[$targetPrimaryId][0] ?? (reset($texts)[0] ?? ''));
                        }
                    }
                    else {
                        $value = (string)($texts[$targetPrimaryId][0] ?? (reset($texts)[0] ?? ''));
                    }
                    $newTmpData[$dstName] = [$type, $value];
                    continue;
                }
                // Alle übrigen Typen: der Eintrag der Arbeitskopie, nur umbenannt.
                // Mehrsprachig: nur die Sprachen des Zielbereichs
                $entry = $tmpData[$srcName] ?? null;
                if ($entry === null) continue;
                $value = $entry[1] ?? '';
                if ($this->cms->isMultilingualField($dstDef) && is_array($value)
                    && ($type !== 'json' || $this->_isLanguageKeyedArray($value, $sourceLanguageIds))
                ) {
                    $value = array_intersect_key($value, array_flip($targetLanguageIds));
                }
                $newTmpData[$dstName] = [$type, $value];
            }
            $this->_saveTmpData($tmpId, $newTmpData);

            // 3. Verknüpfungen auf die Zielfelder umhängen
            // --------------------
            $this->_moveRelations($tmpId, $sourceFields, $targetFields, $mapping, $targetAreaId);

            $this->db->commit();
            return $tmpId;

        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            if (!str_starts_with($errorMsg, '#')) $this->cms->error($errorMsg);
            // Kopierte Dateien der Arbeitskopie entfernen, dann Rollback (die Kopie
            // verschwindet damit samt Arbeitsstand und Verknüpfungen)
            foreach ($copied as $path) @unlink($path);
            $this->db->rollback();
            return null;
        }
    }

    /**
     * Schritt 2: die vorbereitete Arbeitskopie im Zielbereich speichern
     * (Klassenkommentar, Schritte 3 und 4).
     *
     * Die abgebildeten Werte kommen aus dem Arbeitsstand der Kopie und werden
     * Dataset::save() ausdrücklich übergeben, damit sie gegen die Felddefinitionen
     * des Zielbereichs geprüft werden (gestagte Felder prüft save() nicht erneut);
     * sie liegen schon im Datenbankformat vor ($storedFormatFields). Nicht abgebildete
     * Zielfelder bekommen ihre Vorgaben. parent_id / previous_id: im Zielbereich an
     * den Anfang der Wurzelebene - ein Vaterdatensatz aus dem Herkunftsbereich wäre
     * dort ungültig.
     *
     * Scheitert die Prüfung, bleibt die Arbeitskopie stehen (Fehler in Cms::errors
     * unter den Namen der Zielfelder, mehrsprachig "name.{language_id}"); der
     * Aufrufer zeigt die Eingabemaske des Zielbereichs dazu an oder verwirft die
     * Kopie mit cancel() - der Datensatz ist bis dahin unverändert.
     *
     * @param int  $tmpId     Arbeitskopie aus movePrepare()
     * @param bool $simulate  true: nur prüfen (Stapelverarbeitung): Record::save() mit
     *                        stayTemporary - die geprüften Werte landen nur im
     *                        Arbeitsstand der Kopie, der Datensatz bleibt, wie er ist.
     *                        Die Kopie bleibt auch hier stehen (cancel() beim Aufrufer)
     * @return bool           true, wenn eingecheckt (Simulation: ohne Beanstandung)
     */
    public function moveSave(int $tmpId, bool $simulate = false): bool
    {
        if (!$prodData = $this->getProdData($tmpId)) {
            $this->cms->error($this->cms->snippet('error_tmp_record_access_denied'));
            return false;
        }
        [$recordId, $targetAreaId] = $prodData;
        $schemaTarget = $this->virtualSchema($targetAreaId, false);

        $fields = ['area_id'];
        $saveData = [
            'record_id'   => $tmpId,
            'area_id'     => $targetAreaId,
            'parent_id'   => 0,
            'previous_id' => 0,
        ];
        $stored = [];
        foreach ($this->_loadTmpData($tmpId) as $name => $entry) {
            $type = (string)($entry[0] ?? '');
            if (!isset($schemaTarget[$name])) continue;
            // Dateien (Arbeitsstand + promoteTmpFiles), Verknüpfungen (data_rel der
            // Kopie) und Passwörter (Hash im Arbeitsstand) übernimmt save() von selbst
            if ($type === 'file' || $type === 'password' || in_array($type, $this->cms->relationTypes, true)) continue;
            $fields[] = $name;
            $stored[] = $name;
            $saveData[$name] = $entry[1] ?? '';
        }

        if ($simulate) {
            // Ein Einzelschritt prüft nur die übermittelten Felder - die Verknüpfungs-
            // felder des Zielbereichs (not_empty, min/max zählen die data_rel-Zeilen
            // der Kopie) kommen deshalb mit ihrem jetzigen Stand dazu; beim Check-in
            // prüft save() ohnehin alle Felder
            foreach ($schemaTarget as $name => $colDef) {
                if (!in_array((string)($colDef['type'] ?? ''), $this->cms->relationTypes, true)) continue;
                $this->db->pushResult();
                $this->db->query(
                    "SELECT `dest_id` FROM `data_rel` WHERE `field_id` = ? AND `src_id` = ? ORDER BY `position`",
                    'ii', [(int)$colDef['field_id'], $tmpId]
                );
                $saveData[$name] = array_map('intval', $this->db->fetchAll());
                $this->db->popResult();
                $fields[] = $name;
            }
        }

        $this->storedFormatFields = $stored;
        try {
            if ($simulate) {
                return (bool)$this->save($fields, $saveData, stayTemporary: true);
            }
            // Check-in: fest verknüpfte Inhalte (link) zuerst - die Inhalte nicht
            // abgebildeter link-Felder werden dabei gelöscht (LinkTrait)
            $this->db->beginTransaction();
            try {
                if (!$this->checkInDependents($tmpId, $recordId)) {
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
                if (!$this->save($fields, $saveData)) {
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
                $this->db->commit();
                return true;
            } catch (\Exception $e) {
                $errorMsg = $e->getMessage();
                if (!str_starts_with($errorMsg, '#')) $this->cms->error($errorMsg);
                $this->db->rollback();
                return false;
            }
        } finally {
            $this->storedFormatFields = [];
        }
    }

    /**
     * data_rel-Zeilen der Arbeitskopie nach dem Mapping umhängen.
     *
     * Verknüpfungen liegen beidseitig in `data_rel` (VirtualFieldsTrait::
     * createRelations()): für ein relation-Feld F die Zeile (F, src, dest) und für
     * sein Gegenfeld F' die Zeile (F', dest, src). Beide wandern mit - auf das
     * Zielfeld G und dessen Gegenfeld G' (moveCompatible() verlangt denselben
     * Zielbereich, G' liegt also im selben Bereich wie F'). Verweise (pointer) haben
     * nur die eine Zeile; ihre Ziele müssen in den Zielbereichen von G liegen.
     *
     * Nicht abgebildete Felder verlieren ihre Verknüpfungen. Eingehende Verweise
     * anderer Datensätze auf diesen bleiben nur, wenn ihr pointer-Feld den
     * Zielbereich zulässt; eingehende Verknüpfungen (relation) auf den
     * Herkunftsbereich sind nach dem Wechsel ungültig.
     *
     * Läuft VOR save(): validateValue() zählt die Zeilen je Zielfeld (min/max),
     * checkIn() kopiert sie auf den produktiven Datensatz zurück.
     *
     * @param int   $tmpId         Arbeitskopie
     * @param array $sourceFields  moveFields() des Herkunftsbereichs
     * @param array $targetFields  moveFields() des Zielbereichs
     * @param array $mapping       bereinigte Abbildung (moveMapping())
     * @param int   $targetAreaId  Zielbereich
     */
    private function _moveRelations(int $tmpId, array $sourceFields, array $targetFields, array $mapping, int $targetAreaId): void
    {
        foreach ($sourceFields as $srcName => $srcDef) {
            $type = (string)($srcDef['type'] ?? '');
            if (!in_array($type, $this->cms->relationTypes, true)) continue;
            $srcFieldId = (int)$srcDef['field_id'];
            $dstName = $mapping[$srcName] ?? '';

            if ($dstName === '') {
                // verwerfen - beide Richtungen
                $this->db->query("DELETE FROM `data_rel` WHERE `field_id` = ? AND `src_id` = ?", 'ii', [$srcFieldId, $tmpId]);
                if (str_starts_with($type, 'relation')) {
                    $this->db->query(
                        "DELETE FROM `data_rel` WHERE `field_id` = ? AND `dest_id` = ?",
                        'ii', [(int)$srcDef['rel_field_id'], $tmpId]
                    );
                }
                continue;
            }

            $dstDef = $targetFields[$dstName];
            $dstFieldId = (int)$dstDef['field_id'];
            $dstFieldnameId = (int)($this->cms->resolveVirtualField($dstName)['fieldname_id'] ?? 0);
            if ($dstFieldnameId < 1) {
                throw new \Exception("Error: _moveRelations(): fieldname of \"$dstName\" not found.");
            }

            if (in_array($type, $this->cms->multiAreaTypes, true)) {
                // pointer, link: verwiesene Datensätze müssen in den Zielbereichen
                // des Zielfeldes liegen
                $allowed = array_map('intval', $this->getRelAreaIds($dstFieldId));
                $this->db->pushResult();
                $this->db->query(
                    "SELECT DISTINCT `r`.`area_id` FROM `data_rel` `d`"
                    . " INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`dest_id`"
                    . " WHERE `d`.`field_id` = ? AND `d`.`src_id` = ?",
                    'ii', [$srcFieldId, $tmpId]
                );
                $destAreaIds = array_map('intval', $this->db->fetchAll());
                $this->db->popResult();
                if (!empty(array_diff($destAreaIds, $allowed))) {
                    $this->createFieldError('record.error_move_pointer_area', $dstName, (string)($dstDef['label'] ?? $dstName));
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
            }

            // eigene Zeilen: (F, tmp, dest) -> (G, tmp, dest)
            $this->db->query(
                "UPDATE `data_rel` SET `field_id` = ?, `fieldname_id` = ? WHERE `field_id` = ? AND `src_id` = ?",
                'iiii', [$dstFieldId, $dstFieldnameId, $srcFieldId, $tmpId]
            );

            if (str_starts_with($type, 'relation')) {
                // Zeilen des Gegenfeldes: (F', dest, tmp) -> (G', dest, tmp)
                $srcRelFieldId = (int)$srcDef['rel_field_id'];
                $dstRelFieldId = (int)$dstDef['rel_field_id'];
                $this->db->pushResult();
                $this->db->query("SELECT `name` FROM `field` WHERE `field_id` = ?", 'i', [$dstRelFieldId]);
                $dstRelName = (string)($this->db->fetchNum()[0] ?? '');
                $this->db->popResult();
                $dstRelFieldnameId = (int)($this->cms->resolveVirtualField($dstRelName)['fieldname_id'] ?? 0);
                if ($dstRelFieldnameId < 1) {
                    $this->createFieldError('record.error_dest_relationfield', $dstName, (string)($dstDef['label'] ?? $dstName));
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
                $this->db->query(
                    "UPDATE `data_rel` SET `field_id` = ?, `fieldname_id` = ? WHERE `field_id` = ? AND `dest_id` = ?",
                    'iiii', [$dstRelFieldId, $dstRelFieldnameId, $srcRelFieldId, $tmpId]
                );
            }
        }

        // Eingehende Verweise anderer Datensätze: nur, wenn das pointer-Feld den
        // Zielbereich zulässt
        $this->db->query(
            "DELETE `d` FROM `data_rel` `d`"
            . " INNER JOIN `field` `f` ON `f`.`field_id` = `d`.`field_id`"
            . " WHERE `d`.`dest_id` = ? AND (`f`.`type` LIKE 'pointer%' OR `f`.`type` = 'link')"
            . " AND NOT EXISTS (SELECT 1 FROM `rel_field_area` `a` WHERE `a`.`field_id` = `f`.`field_id` AND `a`.`area_id` = ?)",
            'ii', [$tmpId, $targetAreaId]
        );
        // Eingehende Verknüpfungen, deren Feld nicht auf den Zielbereich zeigt
        // (Sicherheitsnetz - die Gegenfelder im Herkunftsbereich wurden oben behandelt)
        $this->db->query(
            "DELETE `d` FROM `data_rel` `d`"
            . " INNER JOIN `field` `f` ON `f`.`field_id` = `d`.`field_id`"
            . " WHERE `d`.`dest_id` = ? AND `f`.`type` LIKE 'relation%' AND `f`.`rel_area_id` != ?",
            'ii', [$tmpId, $targetAreaId]
        );
    }

    // Mediendateien der Felder des verlassenen Bereichs (Pfade ab paths.media_dir),
    // gesammelt von removeAreaData() in hookOnSave(), gelöscht von
    // removeAreaFiles() in hookOnSaved() - erst wenn das Speichern durch ist
    protected array $areaMoveFiles = [];

    /**
     * data_*-Zeilen eines Datensatzes in den Feldern eines Bereichs entfernen, dem
     * er nicht mehr angehört.
     *
     * Aufgerufen aus Record::hookOnSave(), wenn sich die area_id ändert - VOR dem
     * Schreiben der neuen Felder: der Primärschlüssel der data_*-Tabellen ist
     * (language_id, record_id, fieldname_id), und ein gleichnamiges Feld im
     * Zielbereich trägt dieselbe fieldname_id wie das alte. Die Zeilen des alten
     * Feldes stünden dem INSERT von saveVirtualFields() im Weg. Läuft in der
     * Transaktion von save() - scheitert die Prüfung im Zielbereich, kommt alles
     * zurück.
     *
     * Die Mediendateien der alten Upload- und Thumbnail-Felder werden hier nur
     * gesammelt ($areaMoveFiles) und erst in removeAreaFiles() gelöscht: Dateien
     * lassen sich nicht zurückrollen.
     *
     * `data_dft` und `record_cache` schreibt saveVirtualFields() ohnehin neu,
     * Verknüpfungen (data_rel) ersetzt checkIn() - hier geht es um die Zeilen mit
     * field_id im alten Bereich, die Cms::select() über ihre fieldname_id sonst
     * weiterhin fände.
     *
     * @param int $recordId
     * @param int $areaId    der bisherige Bereich
     */
    public function removeAreaData(int $recordId, int $areaId): void
    {
        $this->areaMoveFiles = [];
        if ($recordId < 1 || $areaId < 1) return;
        $this->db->pushResult();

        // Dateien der Upload- und Thumbnail-Felder des alten Bereichs vormerken
        $this->db->query(
            "SELECT DISTINCT `d`.`field_id`, `d`.`extension`, `d`.`file_language_id` FROM `data_fil` `d`"
            . " INNER JOIN `field` `f` ON `f`.`field_id` = `d`.`field_id`"
            . " WHERE `d`.`record_id` = ? AND `f`.`area_id` = ?"
            . " AND (`d`.`file_language_id` IS NULL OR `d`.`file_language_id` = `d`.`language_id`)",
            'ii', [$recordId, $areaId]
        );
        foreach ($this->db->fetchAll() as $row) {
            if ((string)$row['extension'] === '') continue;
            $this->areaMoveFiles[] = $this->cms->mediaPath(
                (int)$row['field_id'], $recordId, $this->cms->fileLanguageId($row), (string)$row['extension']
            );
        }

        // data_*-Zeilen der alten Felder
        $tables = [];
        foreach ($this->cms->dataSuffix as $type => $suffix) {
            if (in_array($type, $this->cms->noColumnTypes, true) || $suffix === 'ids') continue;
            $tables['data_' . $suffix] = true;
        }
        foreach (array_keys($tables) as $table) {
            $column = ($table === 'data_rel') ? 'src_id' : 'record_id';
            $this->db->query(
                "DELETE `d` FROM `{$table}` `d`"
                . " INNER JOIN `field` `f` ON `f`.`field_id` = `d`.`field_id`"
                . " WHERE `d`.`{$column}` = ? AND `f`.`area_id` = ?",
                'ii', [$recordId, $areaId]
            );
        }
        $this->db->popResult();
    }

    /**
     * Die in removeAreaData() vorgemerkten Mediendateien löschen.
     *
     * Aufgerufen aus Record::hookOnSaved() - nach Prüfung und Schreiben, aber noch
     * vor dem Commit; wie bei promoteTmpFiles() ist das der Preis dafür, dass
     * Dateioperationen nicht transaktional sind.
     */
    public function removeAreaFiles(): void
    {
        $mediaDir = $this->config->get('paths.media_dir');
        foreach ($this->areaMoveFiles as $path) {
            @unlink("$mediaDir/$path");
        }
        $this->areaMoveFiles = [];
    }
}
