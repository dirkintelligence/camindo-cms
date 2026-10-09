<?php
/**
 * Datensatzliste eines Inhaltsbereichs (Erweiterung für RecordBackend).
 *
 * Einstiegspunkt: backend/record_list.php -> list()
 *
 * Die aufwendigste Liste im Backend, weil sie im Gegensatz zu allen anderen keine
 * festen Spalten hat: welche Felder erscheinen, entscheidet der Redakteur je Bereich
 * über das Kennzeichen visibility_list der Felddefinition.
 *
 * DAS SCHEMA WIRD ZUR LAUFZEIT GEBAUT
 * --------------------
 * Record::virtualSchema($areaId) liefert die Felddefinitionen des Bereichs als Schema -
 * Meta-Felder und benutzerdefinierte zusammen. Daraus ergeben sich Spaltenköpfe,
 * Sortierbarkeit und die Aufbereitung der Werte (RecordPresenter::listFields() /
 * listValue()). Ohne eindeutigen Bereich (globale Suche) gibt es das nicht; dann
 * bleibt eine einzige Spalte mit dem Default-Feld. Ein Listen-Template des Bereichs
 * (`area`.`list_template_id`) ergänzt eine letzte Spalte ohne Überschrift.
 *
 * DREI BETRIEBSARTEN
 * --------------------
 *     ein Bereich       Spalten nach visibility_list, Baumdarstellung wenn der Bereich
 *                       ein parent_id-Feld hat
 *     mehrere Bereiche  globale Suche - nur die Default-Feld-Spalte
 *     Lookup            src_field gesetzt: zusätzliche Checkbox-Spalte, mit der sich
 *                       die Verknüpfung im aufrufenden Formular setzen lässt
 *
 * SORTIERUNG
 * --------------------
 * Ohne Angabe im Request gilt die Vorgabe des Bereichs (area.sort_field_id /
 * sort_order), sonst changed_at DESC.
 *
 * @see Camindo\Core\Modules\Record\RecordBackend        gemeinsame Basis, _resolveParams()
 * @see Camindo\Core\Record::virtualSchema()               Schema eines Bereichs
 * @see Camindo\Core\Backend::backendValue()               Werte für die Anzeige aufbereiten
 * @see docs/developers/05-select-und-filter.md
 */
namespace Camindo\Core\Modules\Record;

class RecordBackendList extends RecordBackend {

    /**
     * Datensatzliste ausgeben (backend/record_list.php).
     *
     * Ablauf:
     *
     *  1. Ohne jeden Bezug (kein Bereich, kein Filter, kein Lookup) schließt sich der
     *     Layer, statt sämtliche Datensätze des Systems zu listen. Das passiert, wenn
     *     der Filter einer globalen Suche über den Schließen-Knopf entfernt wurde.
     *  2. Seitenparameter auflösen (RecordBackend::_resolveParams()).
     *  3. Schema des Bereichs holen - daraus entstehen die Spalten.
     *  4. Bereichsfilter als fertiges SQL-Fragment ('sql'), damit er nicht im
     *     Filtertext über der Liste erscheint, den der Redakteur als seine
     *     Sucheingabe liest. Die Ids sind vorher zu int gecastet bzw. durch
     *     sanitizeIds() gelaufen.
     *  5. Sortierung festlegen (siehe Klassenkommentar).
     *  6. Kopfzeile, dann Zeile für Zeile die Tabelle.
     *
     * Einige Felder bekommen in der Tabelle eine eigene Darstellung statt der
     * allgemeinen aus backendValue(): status_id als farbiges Symbol (mit
     * Gegenlese-Hinweis), template_id, parent_id und needs_review als Klartext
     * (RecordPresenter::listValue()). Das Default-Feld wird hervorgehoben und trägt bei
     * hierarchischen Bereichen die Baum-Einrückung. Hat der Bereich ein
     * Listen-Template (`area`.`list_template_id`), folgt als letzte Spalte ohne
     * Kopf dessen Ausgabe je Datensatz (RecordPresenter::listTemplateHtml()).
     *
     * Die Statusspalte trägt zusätzlich ein Schnellfilter-Menü im Kopf und steht
     * deshalb immer ganz vorn (array_unshift).
     *
     * pushResult()/popResult() klammern jeden Schleifendurchlauf: teaser() und
     * backendValue() setzen selbst Abfragen ab und würden das Ergebnis der Liste
     * sonst überschreiben.
     *
     * Request: area_id | filter | src_field | dest_id (mindestens eines), src_id,
     *          checked, sort, order, page, relation
     *
     * relation + dest_id: die Datensätze, die über das Verweisfeld (bzw. mit
     * relation=any über irgendeines) auf dest_id zeigen - "Alle Verweise auf diesen
     * Datensatz" im Metafeld-Reiter der Detailansicht.
     * relation + src_id (ohne src_field): die Datensätze, auf die das Verweisfeld von
     * src_id zeigt - "mehr" hinter einem gekürzten Verknüpfungsfeld der Detailansicht.
     * select() übernimmt beides nicht aus dem Request (request=all gilt nur für
     * filter, sort …), deshalb reicht _list() es geprüft weiter. Mit src_field ist
     * src_id die Quelle eines Lookup-Aufrufs, nicht die der Verknüpfung.
     */
    public function list(): void
    {
        // Wenn keine filter und keine area_id angegeben ist (filter wurde aus globaler Suche wurde per Close-Button gelöscht)
        // dann Layer schließen - anstatt ALLE Datensätze zu listen
        if (empty($_REQUEST['area_id']) && empty($_REQUEST['filter']) && empty($_REQUEST['src_field'])
            && empty($_REQUEST['dest_id']) && empty($_REQUEST['relation'])) {
            $this->backend->script('cApp.layers.close();');
            return;
        }

        // Container header
        echo $this->backend->template('container_header');
        $this->function = 'list';

        // Suchfeld '_url' (Id, Slug oder URL) in Filterwerte übersetzen - vor
        // _resolveParams(), das filter[area_id] auswertet
        $this->_applyUrlFilter();

        $this->_resolveParams();
        
        if ($this->function !== 'list') {
            $this->errors();
            echo $this->backend->template('container_footer');
            return;
        }

        // Leserecht der Benutzergruppe auf den Bereich (12-group-rights.md)
        $access = $this->backend->access();
        if ($this->areaId > 0 && !$access->areaRead($this->areaId)) {
            echo $access->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }

        // Bread crum navi
        if (!empty($this->areaId)) {
            $naviLabel = $this->cms->_strip_tags($this->cms->textOrSnippet($this->areaData['label']));
        }
        else {
            $naviLabel = '#record.headline_global_results';
        }
        $this->backend->setNaviLabel($naviLabel);

        // H1
        echo $this->backend->headline('#record.headline_record_list');

        if (!empty($this->areaData['note'])) {
            echo $this->backend->template('module_main_note', [
                '{text}' => $this->cms->_nl2br($this->cms->textOrSnippet((string)$this->areaData['note'])) // Notiz darf ein Snippet sein (#custom.…)
            ]);
        }

        // Record-Liste mit Rückgabewerten
        [$destId, $count, $isLinked] = $this->_list();

        // Gelisteten Datensatz direkt verknüpfen?
        $this->_linkImmediately((int)($destId ?? 0), (int)($count ?? 0), (bool)($isLinked ?? false));

        echo $this->backend->template('container_footer');
    }

    /**
     * Datensatzliste
     * Rückgabewert Array:
     *  $destId   (int)  letzter gelisteter Datensatz (record_id)
     *  $count    (int)  num_rows_unlimited der Liste
     *  $isLinked (bool) 
     */
    private function _list(): array
    {
        $isLinked = false;

        // Bei Suchmaske direkt übergebene Ids:
        $checkedIds = $this->system->sanitizeIds(
            explode(',', (string)($_REQUEST['checked'] ?? ''))
        );

        // Buttons ("Neu" nur, wenn die Gruppe im Bereich anlegen darf; ohne
        // festen Bereich entscheidet die Bereichsliste der Maske)
        $access = $this->backend->access();
        $canCreate = ($this->areaId < 1) || $access->areaCreate($this->areaId);
        $this->backend->buttonBarReset();
        if ($this->hasLookup()) {
            $srcFieldJs = $this->srcFieldJs();
            if ($canCreate) {
                $this->backend->buttonAdd('button_create', "cApp.layers.add('Record/record_edit.php?{$this->params}&function=create');");
            }
            $this->backend->buttonAdd('button_search', "cApp.layers.add('Record/record_search.php?' + cApp.layers.queryString());");
            $this->backend->buttonAdd('button_refresh', "cApp.layers.refresh();");
            $this->backend->buttonAdd('record.button_apply', "cApp.layers.close();cApp.ui.lookupFocus({$srcFieldJs},false,{$this->srcId});");
        }
        else {
            if ((int)$this->areaData['dependent'] !== 1 && $canCreate) {
                $this->backend->buttonAdd('button_create_primary', "cApp.layers.add('Record/record_edit.php?{$this->params}&function=create');");
            }
            $this->backend->buttonAdd('button_search', "cApp.layers.add('Record/record_search.php?' + cApp.layers.queryString());");
            $this->backend->buttonAdd('button_refresh', "cApp.layers.refresh();");

            if (!empty($this->areaId)) {
                // Vorschau der Übersicht des Bereichs (Übersichts-Template + Slug-Präfix)
                $this->backend->buttonAddAreaPreview((int)$this->areaId);

                // Menü rechts: Export, Import, Medien-Import, Stapelverarbeitung, Einstellungen des
                // Bereichs - je nach Zusatzrecht bzw. Modulzugriff (Area). Export und
                // Stapelverarbeitung bekommen die Seitenparameter dieser Liste mit
                // (cApp.layers.queryString(): Filter, Sortierung)
                $items = '';
                if (!empty($this->allowedTypes('export'))) $items .= $this->backend->template('record.record_list_more_item_export');
                if (!empty($this->allowedTypes('import'))) $items .= $this->backend->template('record.record_list_more_item_import');
                if ($this->mediaImportAllowed()) $items .= $this->backend->template('record.record_list_more_item_media_import');
                if ($this->batchAllowed()) $items .= $this->backend->template('record.record_list_more_item_batch');
                if ($this->backend->access()->module('Area')) {
                    if ($items !== '') $items .= $this->backend->template('record.record_list_more_item_divider');
                    $items .= $this->backend->template('record.record_list_more_item_settings');
                }
                if ($items !== '') {
                    $this->backend->buttonAdd('record.record_list_more_dropdown', '', '', alignRight:true);
                    $this->backend->buttonBarReplace('{items}', $items);
                    $this->backend->buttonBarReplace('{area_id}', (string)$this->areaId);
                }
            }
        }
        echo $this->backend->buttonBar();

        // Schema
        if ($this->areaId > 0) {
            $schemaVirtual = $this->record->virtualSchema($this->areaId, false);
            // schema mit virtuellen Feldern und Metafeldern,
            // korrekt markierte Felder mit "meta=1", die auf Meta-Feld basieren
            $completedSchema = $this->record->virtualSchema($this->areaId, true);
        }
        else {
            // Nur Felder, die in allen Content-Bereichen vorkommen (Meta-Felder)
            $schemaVirtual = [];
            $completedSchema = $this->cms->schema;
        }
        $this->hasParentId = isset($schemaVirtual['parent_id']);

        // area-Filer (nicht im Filtertext)
        // --------------------
        $sql = '';
        if (!empty($this->areaId)) {
            $sql = "`record`.`area_id` = {$this->areaId}";
        }
        elseif (!empty($this->areaIds)) {
            $ids =  implode(',', $this->areaIds);
            $sql = "`record`.`area_id` IN ({$ids})";
        }
        // Ohne festen Bereich (globale Suche, Verweise): nur Bereiche, die die Gruppe
        // lesen darf - auch ein filter[area_id] darf daran nichts ändern
        if (empty($this->areaId)) {
            $accessSql = $this->backend->areaAccessSql(false, '`record`.`area_id`');
            if ($accessSql !== '') {
                $accessSql = substr($accessSql, 5); // führendes " AND "
                $sql = ($sql === '') ? $accessSql : $sql . ' AND ' . $accessSql;
            }
        }

        // Verweise auf einen Datensatz (siehe list())
        $relationParams = $this->_relationParams();

        // Auswahl des Vaterdatensatzes: der Datensatz selbst und sein eigener Zweig
        // scheiden aus - sonst hinge der Baum an sich selbst.
        if ($this->srcIsParent) {
            $excludeSql = $this->_parentExcludeSql();
            if ($excludeSql !== '') {
                $sql = ($sql === '') ? $excludeSql : $sql . ' AND ' . $excludeSql;
            }
        }

        // Sortierung
        // --------------------
        if (empty($_REQUEST['sort'])) {
            // Fallback
            $sort = 'changed_at';
            $order = 'DESC';
            // Ein Bereich?
            if ($this->areaId > 0) {
                // Default-Sortierung des Bereichs ermitteln - die Richtung gilt auch
                // ohne Sortierfeld (dann changed_at), sonst zeigte filterText() die
                // Sortierung als geändert an und ließe sie nicht entfernen
                if ($sortFieldId = (int)($this->areaData['sort_field_id'] ?? 0)) {
                    if ($fieldData = $this->record->fieldData($sortFieldId)) {
                        $sort = $fieldData['name'];
                    }
                }
                $order = strtoupper((string)($this->areaData['sort_order'] ?? '')) === 'ASC' ? 'ASC' : 'DESC';
            }
            $_REQUEST['sort'] = $sort;
            $_REQUEST['order'] = $order;
        }

        // Baumbereich: nach `position` sortieren hieße flach über alle Zweige hinweg
        // sortieren - `position` zählt je Vaterdatensatz und beginnt in JEDEM Zweig
        // wieder bei 1 (siehe TreeManagerTrait). Die Reihenfolge des Baums steht
        // allein in `lft`; gemeint ist also lft.
        // ASC ist dabei zwingend, auch wenn der Bereich DESC vorgibt: die Liste rückt
        // die Zeilen nach `depth` ein (Baumdarstellung), was nur aufgeht, wenn jeder
        // Vaterdatensatz vor seinen Kindern steht.
        if ($_REQUEST['sort'] === 'position' && $this->areaId > 0 && $this->hasParentId) {
            $_REQUEST['sort'] = 'lft';
            $_REQUEST['order'] = 'ASC';
        }

        // SELECT
        // --------------------
        $f = $this->cms->select([
            'request' => 'all',
            'limit' => $this->cms->list_rows,
            'count' => 'true',
            'filtertext' => 'true',
            'sql' => $sql,
        ] + $relationParams, null, $completedSchema);

        // Suchbegriffe oder geänderte Sortierung als lesbarer Text
        echo $this->filterText($f);

        // Pagination top
        echo $this->backend->paginationTop();
        // Gesamtzahl gleich hier - die Zeilen unten stapeln eigene Abfragen
        $count = (int)$this->db->num_rows_unlimited;

        if ($this->db->num_rows < 1) {
            // Keine Ergebnisse keine Tabelle
            echo $this->backend->template('container_footer');
            return [0, 0, false];
        }
        
        // Create Head columns
        if ($this->hasLookup()) {
            // Wenn lookup call, dann zusätzliche erste Spalte
            $headColumns = $this->backend->template('record.lookup_table_head_column');
        }
        else {
            $headColumns = '';
        }

        // Listen-Template des Bereichs: zusätzliche letzte Spalte ohne Kopf
        // (siehe RecordPresenter::listTemplateHtml())
        $listTemplateId = (int)($this->areaData['list_template_id'] ?? 0);
        $hasListTemplate = ($this->areaId > 0 && $listTemplateId > 0);

        if (empty($this->areaId)) {
            // Mehrere Bereiche -> Default-Feld als Spalte
            $headColumns .= '<td>' . $this->cms->snippet('record.field_default') . "</td>\n";
        }
        else {
            // Eine Spalte je sichtbarem Feld - Verknüpfungsfelder nur, wenn die
            // Gruppe mindestens einen Zielbereich lesen darf
            $visibleFields = array_values(array_filter(
                $this->presenter->listFields($schemaVirtual),
                fn($name) => $this->record->relationTargetReadable($schemaVirtual[$name])
            ));
            foreach ($visibleFields as $name) {
                $headColumn = $this->backend->tableHeadColumn($schemaVirtual[$name], $f, $name);
                if ($name == 'status_id') {
                    // Status has dropdown
                    $statusItems = [];
                    $statusDropdownItems = $this->_statusDropdownItems(icon:true);
                    foreach ($statusDropdownItems as $statusValue => $statusLabel) {
                        $statusItems[] = $this->backend->template('record.record_table_dropdown_item', [
                            '{name}' => 'status_id',
                            '{value}' => $statusValue,
                            '{label}' => $statusLabel
                        ]);
                    }
                    $statusItems[] = $this->backend->template('record.dropdown_items_review');
                    $statusLabelDropdown = $this->backend->template('record.record_table_head_column_dropdown',[
                        '{dropdown_id}' => $this->crypt->randomKey(16),
                        '{column_label}' => $headColumn,
                        '{items}' => implode("\n", $statusItems)
                    ]);
                    $headColumns .= '<td style="width:1px">' . $statusLabelDropdown . '</td>';
                }
                else {
                    $headColumns .= '<td>' . $headColumn . "</td>\n";
                }
            }
            if ($hasListTemplate) $headColumns .= "<td></td>\n";
        }

        // Baumdarstellung nur, wenn der Bereich überhaupt eine Hierarchie hat -
        // also ein Eingabefeld für parent_id definiert ist. Sonst ist die Liste flach.
        $hasParentId = isset($schemaVirtual['parent_id']);

        // Create table rows
        $tplTableRow = $this->backend->template('record.record_table_row');
        $tableRows = '';
        $destId = 0;
        for ($i = 0; $i < $this->db->num_rows; $i++) {
            $row = $this->cms->fetch();
            $this->db->pushResult();
            // Neue Zeile
            $destId = (int)$row['record_id'];

            // Wenn lookup call, dann zusätzliche erste Spalte
            if ($this->hasLookup()) {
                $srcFieldJs = $this->srcFieldJs();

                if ($this->srcIsParent && $this->srcId > 0) {
                    // 0. Vaterdatensatz für die EINGABEMASKE wählen: es wird nichts
                    // geschrieben, die Wahl wandert in das Formular im darunter-
                    // liegenden Layer. Welcher bereits gewählt ist, steht im Seiten-
                    // parameter 'checked' - die Arbeitskopie selbst weiß es nicht.
                    // (Ohne src_id kommt der Aufruf aus der Suchmaske; dort sind
                    //  mehrere Vaterdatensätze erlaubt und Fall 3 greift.)
                    $isLinked = in_array($destId, $checkedIds, false);
                    $clickHandler = "cApp.ui.plookupUpdate($srcFieldJs,\$(this).prop('checked')?'link':'unlink',$destId);";
                }
                elseif ($this->srcId > 0) {
                    // 1. srcId angegeben: Verknüpfung mit src-Datensatz soll hegestellt werden können
                    // Prüfen ob Verknüpfung bereits besteht        
                    $isLinked = $this->record->checkRelation($this->srcField, $this->srcId, $destId);
                    $clickHandler = "cApp.ui.lookupRUpdate($srcFieldJs,\$(this).prop('checked')?'link':'unlink',$destId,0,false,{$this->srcId});";
                }
                else {
                    // 2. srcId nicht angegeben: der Datensatz wandert in das Feld des
                    // aufrufenden Formulars (Suchmaske oder ein fremdes Formular wie
                    // instance.record_id). Seitenparameter 'checked' wird ausgewertet.
                    $isLinked = in_array($destId, $checkedIds, false);
                    $clickHandler = "cApp.ui.slookupUpdate($srcFieldJs,\$(this).prop('checked')?'link':'unlink',$destId);";
                };

                $formCheck = $this->backend->template('list_checkbox', [
                    '{onclick}' => $clickHandler,
                    '{checked}' => $isLinked ? ' checked' : ''
                ]);

                // Checkbox in Spalte packen
                $tableCols = $this->backend->template('table_column_noctx_lg', [
                    '{html}' => $formCheck
                ]);
            }
            else {
                $tableCols = '';
            }
            $defaultField = $this->backend->getDefaultField($row['area_id']);
            if (empty($schemaVirtual)) {
                // Mehrere Bereiche: eine einzige Spalte mit dem Default-Feld.
                // Das Default-Feld wird je ZEILE bestimmt - bei einer bereichsüber-
                // greifenden Trefferliste kann es in jeder Zeile ein anderes sein.

                $defaultFieldName = $defaultField['name'] ?? 'record_id';
                $value = $this->backend->backendValue($row[$defaultFieldName], $defaultField, id: $row['record_id']);
                $tableCols .= $this->backend->template('table_column_default', [
                    '{text}' => $value
                ]);
            }
            else {
                // Eine Spalte je sichtbarem Feld hinzufügen
                foreach ($visibleFields as $name) {
                    $defaultField = (int)($schemaVirtual[$name]['default_field'] ?? 0);
                    $value = $this->presenter->listValue($name, $row, $schemaVirtual);
                    if ($defaultField == 1) {
                        if ($hasParentId) {
                            // Gefettet mit Baumstruktur
                            // depth ist 0-basiert: Wurzelknoten rücken nicht ein
                            $depth = (int)($row['depth'] ?? 0);
                            if ($depth > 0) {
                                $tableCols .= $this->backend->template('table_column_tree_x', [
                                    '{text}' => $value,
                                    '{depth}' => $depth * 15
                                ]);
                            }
                            else {
                                $tableCols .= $this->backend->template('table_column_tree_0', [
                                    '{text}' => $value,
                                    '{depth}' => $depth * 15
                                ]);
                            }
                        }
                        else {
                            // Nur gefettet
                            $tableCols .= $this->backend->template('table_column_default', [
                                '{text}' => $value
                            ]);
                        }
                    }
                    else {
                        $tableCols .= '<td>' .$value . "</td>\n";
                    }
                }
                // Listen-Template des Bereichs als letzte Spalte
                if ($hasListTemplate) {
                    $tableCols .= '<td>' . $this->presenter->listTemplateHtml($this->areaId, $row) . "</td>\n";
                }
            }
            // Kontextmenü: Einträge ohne Recht für diesen Datensatz ausblenden
            // (Owner-Gruppe entscheidet über Bearbeiten/Löschen, Kopieren = Anlegen)
            $rowAreaId = (int)$row['area_id'];
            $rowOwnerId = isset($row['owner_id']) ? (int)$row['owner_id'] : null;
            $hide = [];
            if (!$access->recordWrite($rowAreaId, $rowOwnerId, (int)($row['status_id'] ?? 0))) $hide[] = 'edit';
            if (!$access->areaCreate($rowAreaId)) $hide[] = 'copy';
            if (!$access->areaDelete($rowAreaId, $rowOwnerId)) $hide[] = 'delete';

            // Zur Zeilen-Sammlung hinzufügen
            $tableRows .= strtr($tplTableRow, [
                '{data_id}' => $row['record_id'],
                '{ctx_hide}' => $hide ? ' data-ctx-hide="' . implode(',', $hide) . '"' : '',
                '{columns}' => $tableCols
            ]);
            $this->db->popResult();
        }

        // Tabelle rendern
        echo $this->backend->template('record.record_table_with_head', [
            '{head_columns}' => $headColumns,
            '{table_rows}' => $tableRows,
            '{params}' => $this->params ? "&{$this->params}" : ''
        ]);


        // Context menu
        echo $this->backend->template('record.record_context_menu', [
            '{params}' => $this->params ? "&{$this->params}" : ''
        ]);

        // Pagination bottom
        echo $this->backend->paginationBottom();

        // positionsweise - list() zerlegt mit [$destId, $count, $isLinked]
        return [$destId ?? null, $count, $isLinked];
    }


    /**
     * SQL-Bedingung, die den bearbeiteten Datensatz und seinen Zweig ausschließt.
     *
     * Gebraucht nur beim Lookup-Aufruf für `parent_id`: weder man selbst noch ein
     * eigener Nachkomme kann der eigene Vaterdatensatz sein - der Baum hinge sonst an
     * sich selbst. Der Zweig wird über das Nested Set (lft/rgt) begrenzt.
     *
     * src_id ist die Arbeitskopie; gemeint ist das Original, das getProdData()
     * liefert - das prüft zugleich, dass die Arbeitskopie dem angemeldeten Redakteur
     * gehört. Ein neu angelegter Datensatz hat noch kein Original und noch keinen
     * Zweig, dann gibt es nichts auszuschließen.
     *
     * @return string  '' oder eine WHERE-Teilbedingung
     */
    private function _parentExcludeSql(): string
    {
        if ($this->srcId < 1) return '';
        if (!$prodData = $this->record->getProdData($this->srcId)) return '';
        $originId = (int)$prodData[0];
        if ($originId < 1) return '';

        $this->db->query("SELECT `lft`,`rgt`,`area_id` FROM `record` WHERE `record_id` = ?", 'i', [$originId]);
        if ($this->db->num_rows < 1) return '';
        [$lft, $rgt, $areaId] = $this->db->fetchNum();
        $lft = (int)$lft;
        $rgt = (int)$rgt;
        $areaId = (int)$areaId;

        // Ohne gültige Nested-Set-Grenzen (Bereich ohne Baum) gibt es keinen Zweig,
        // dann ist nur der Datensatz selbst auszuschließen.
        if ($lft < 1 || $rgt <= $lft) {
            return "`record`.`record_id` != {$originId}";
        }
        // lft/rgt zählen je Inhaltsbereich ab 1 (siehe TreeManagerTrait) - ohne den
        // Bereichsfilter träfe der Zweig-Ausschluss auch fremde Bereiche.
        return "`record`.`record_id` != {$originId}"
            . " AND NOT (`record`.`area_id` = {$areaId}"
            . " AND `record`.`lft` > {$lft} AND `record`.`rgt` < {$rgt})";
    }

    /**
     * filter[_url] auflösen (RecordService::urlFilter()) und durch record_id bzw.
     * slug ersetzen. Grenzt ein Slug-Präfix auf Bereiche ein, gilt in einer Liste
     * mit festem Bereich: ist er nicht darunter, gibt es keinen Treffer; in der
     * globalen Liste die Schnittmenge mit einer Bereichsauswahl.
     * Der Wert bleibt als filter[_url] in der Adresse (die Suchmaske zeigt ihn
     * wieder an), select() sieht ihn nicht.
     */
    private function _applyUrlFilter(): void
    {
        $filter = $_REQUEST['filter'] ?? null;
        if (!is_array($filter) || !isset($filter['_url']) || !is_scalar($filter['_url'])) return;
        $url = $this->service->urlFilter((string)$filter['_url']);
        unset($filter['_url']);
        if (isset($url['area_id'])) {
            $areaId = (int)($_REQUEST['data']['area_id'] ?? $_REQUEST['area_id'] ?? 0);
            $selected = $this->system->sanitizeIds((array)($filter['area_id'] ?? []));
            if ($areaId > 0) {
                if (!in_array($areaId, $url['area_id'], true)) $url = ['record_id' => '0'];
                else unset($url['area_id']);
            }
            elseif ($selected) {
                $url['area_id'] = array_values(array_intersect($selected, $url['area_id'])) ?: [0];
            }
        }
        $_REQUEST['filter'] = array_replace($filter, $url);
    }

    /**
     * relation + dest_id bzw. src_id aus dem Request für select() (siehe list()):
     * Ids als positive Zahlen, relation 'any' oder Feldnamen (kommasepariert).
     * src_id nur ohne Lookup-Aufruf (src_field). relation ohne beides: keine
     * Treffer (select() findet mit relation ohne Quelle nichts).
     *
     * @return array  ['relation' => …, 'dest_id' => …, 'src_id' => …] oder []
     */
    private function _relationParams(): array
    {
        $relation = strtolower(trim((string)($_REQUEST['relation'] ?? '')));
        if ($relation === '') return [];
        if (!preg_match('/^[a-z0-9_]+(,[a-z0-9_]+)*$/', $relation)) return ['relation' => 'any'];
        $params = ['relation' => $relation];
        $destId = (int)($_REQUEST['dest_id'] ?? 0);
        if ($destId > 0) $params['dest_id'] = $destId;
        $srcId = (int)($_REQUEST['src_id'] ?? 0);
        if ($srcId > 0 && !$this->hasLookup()) $params['src_id'] = $srcId;
        return $params;
    }

    /**
     * Ergebnisse aus Dataset::select aufbereiten
     * Inkl. Close-Button, analog zu Backend->filterText()
     * aber mit Default-Sortierung des Bereichs und
     * Ausnahme 'lft' ist gemeint, wenn 'position' bei vorhandenem 'parent_id' angefordert wird
     * 
     * @param array $f           Rückgabewerte von Dataset::select
     */
    public function filterText(array $f): string
    {
        $filterText = (string) $f['filter_text'] ?? '';
        // defaultSort kommt bei Record aus dem Area
        $sortOrder = strtoupper((string)($this->areaData['sort_order'] ?? '')) === 'ASC' ? 'ASC' : 'DESC';
        if ($sortFieldName = $this->areaData['sort_field_name'] ?? '') {
            if ($sortFieldName === 'position' && $this->hasParentId) {
                // wie oben in list(): Baumreihenfolge ist lft ASC
                $sortFieldName = 'lft';
                $sortOrder = 'ASC';
            }
        }
        else {
            $sortFieldName = 'changed_at';
        }
        $defaultSort = $sortFieldName . ' ' . $sortOrder;
        $usedSort = (string) $f['used_sort'] ?? '';
        $sortingChanged = ($defaultSort != $usedSort);

        $textParts = [];
        if (!empty($filterText) || $sortingChanged) {
            if (!empty($filterText)) {
                $prefixSearchFor = $this->backend->template('prefix_search_for');
                $textParts[] = $prefixSearchFor . ' ' . $filterText;
            }
            if ($sortingChanged) {
                $sortText = (string) $f['sort_text'] ?? '';
                $prefixSortBy = $this->backend->template('prefix_sort_by');
                $textParts[] = $prefixSortBy . ' ' . $sortText;
            }
        }
        if (!empty($textParts)) {
            return $this->backend->template('search_where_clause', [
                    '{text}' => implode('<br>', $textParts)
            ]);
        }
        else return '';
    }

    /**
     * Prüft ob (der eine gelistete) Datensatz direkt verknüpft werden soll
     * Wenn
     *   - von Lookup geöffnete Liste
     *   - genau ein Datensatz gefunden wurde
     *   - der noch nicht verknüpft ist,
     * dann diesen direkt verknüpfen und Layer schließen
     * Erspart bei eindeutiger Suche den zusätzlichen Klick.
     * num_rows_unlimited ist die Gesamtzahl OHNE Paginierung.
     */
    private function _linkImmediately(?int $destId, ?int $count, bool $isLinked): void
    {
        if ($this->hasLookup()
            && $count === 1
            && !$isLinked
        ) {
            $srcFieldJs = $this->srcFieldJs();
            if ($this->srcIsParent && $this->srcId > 0) {
                // Vaterdatensatz: Fenster schließen, übernehmen, Lookup-Feld leeren
                $this->backend->script("
                    cApp.layers.close();
                    cApp.ui.plookupUpdate({$srcFieldJs},'link',$destId,true);
                    cApp.ui.lookupFocus({$srcFieldJs},true,{$this->srcId});
                ");
            }
            elseif (!empty($this->srcId)) {
                // direkt verlinken, Fenster schließen, lookupfeld leeren und focussieren
                $preventClosing = 'true'; // verhindert doppetes Schließen bei max 1 Verknüpfung
                $this->backend->script("
                    cApp.ui.lookupRUpdate({$srcFieldJs},'link',$destId,0,$preventClosing,{$this->srcId});
                    cApp.layers.close();
                    cApp.ui.lookupFocus({$srcFieldJs},true,{$this->srcId});
                ");
            }
            else {
                // Suchmaske bzw. fremdes Formular
                $this->backend->script("
                    cApp.ui.slookupUpdate({$srcFieldJs},'link',$destId);
                ");
            }
        }
    }
}