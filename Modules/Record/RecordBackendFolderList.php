<?php
/**
 * Baum der Inhaltsbereiche - der Einstieg ins Modul Record.
 *
 * Einstiegspunkt: backend/index.php -> folderList()
 *
 * Datensätze liegen immer in einem Inhaltsbereich. Der Einstieg ist deshalb nicht die
 * Datensatzliste, sondern der Bereichsbaum; ein Klick darauf legt die Liste als
 * weiteren Layer darüber (RecordBackendList).
 *
 * MANDANTEN ALS HAUPTORDNER
 * --------------------
 * Sind Mandanten eingerichtet (mehr als die Standard-Instance,
 * InstanceCache::hasMultipleInstances()), bekommt der Baum eine Ebene mehr: je
 * Instanz ein Hauptordner mit ihrem Label, darunter ihr eigener Bereichsbaum
 * (Area::loadAreas() mit Instanzfilter). Die Hauptordner sehen aus wie Ordner-
 * Bereiche und verhalten sich auch so - auf- und zuklappbar, der Zustand liegt in
 * der Admin-Session (expanded_instance_ids, getrennt von expanded_folder_ids, weil
 * sich Instanz- und Bereichs-Ids überschneiden). Mit nur einer Instanz bleibt der
 * Baum wie bisher.
 *
 * ZULETZT GEÄNDERT (rechte Spalte)
 * --------------------
 * Neben dem Baum stehen die zehn Datensätze, die der angemeldete Admin zuletzt in der
 * Eingabemaske gespeichert hat (Camindo\Core\History, Tabelle `history`), jüngste
 * zuerst - in der ausführlichen Darstellung der Lookup-Vorschau
 * (RecordBackend::detailedText()). Dafür erbt die Klasse von RecordBackend; den Baum
 * selbst betrifft das nicht, er kennt weder Datensätze noch Lookup-Aufrufe.
 *
 * FAST WORTGLEICH ZU TemplateBackend::folderList()
 * --------------------
 * Beide zeigen denselben Baum, nur mit anderem Bereichstyp ('record' statt 'template')
 * und anderen HTML-Vorlagen. Sie teilen sich sogar den SESSION-SCHLÜSSEL
 * expanded_folder_ids - wer hier einen Ordner aufklappt, findet ihn im Modul Template
 * ebenfalls aufgeklappt vor. Bei getrennten Bereichsbäumen fällt das kaum auf, ein
 * eigener Schlüssel je Modul wäre aber sauberer.
 *
 * @see Camindo\Core\Modules\Record\RecordBackendList     die Liste dahinter
 * @see Camindo\Core\Modules\Template\TemplateBackend     dieselbe Darstellung für Templates
 * @see Camindo\Core\Modules\Area\Area::loadAreas()       liefert die Zeilen
 * @see Camindo\Core\History                             zuletzt geändert
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\History;
use Camindo\Core\Modules\Area\Area;

class RecordBackendFolderList extends RecordBackend {

    /**
     * Bereichsbaum ausgeben (backend/index.php).
     *
     * Zeigt Ordner und Bereiche vom Typ 'record' als flache Tabelle mit
     * Pixel-Einrückung nach `depth`; die Reihenfolge liefert das Nested Set (lft).
     * Area::loadAreas() lässt dabei Ordner weg, die keinen einzigen Bereich enthalten -
     * leere Zweige stehen also gar nicht erst da.
     *
     * AUF- UND ZUKLAPPEN
     * Der Zustand steht in der Session (expanded_folder_ids, als JSON). Ein Klick ruft
     * dieselbe Methode mit folder_id und toggle auf: die Id wird erst in jedem Fall aus
     * der Liste entfernt und beim Öffnen wieder hinzugefügt - so bleibt sie eindeutig,
     * ohne vorher prüfen zu müssen, ob sie schon enthalten ist.
     *
     * Zugeklappte Zweige werden nicht aus der Abfrage ausgeschlossen, sondern beim
     * Durchlaufen übersprungen: $skipUntilRgt merkt sich den rechten Grenzwert des
     * geschlossenen Ordners, alles mit kleinerem lft liegt in seinem Teilbaum. Eine
     * einzige Abfrage genügt damit für den ganzen Baum.
     *
     * Anders als im Modul Template gibt es hier keinen "Neu"-Knopf - Bereiche werden
     * im Modul Area angelegt.
     *
     * Request: folder_id, toggle (0|1) - beide optional; bei Mandanten-Hauptordnern
     *          instance_id statt folder_id (siehe Klassenkommentar)
     */
    public function folderList()
    {
        // Hole die Ids der geöffneten Ordner aus der Session
        $expandedFolderIds = $this->toggleExpanded('expanded_folder_ids', 'folder_id');
        // dito für die Mandanten-Hauptordner
        $expandedInstanceIds = $this->toggleExpanded('expanded_instance_ids', 'instance_id');

        // Seitenelemente
        // --------------------

        // Bread crum navi
        $this->backend->setNaviLabel('#record.module_label');
        
        // Container header
        echo $this->backend->template('container_header');
        
        // Linker Seitenbereich
        // --------------------
        echo $this->backend->template('row_start');
        echo $this->backend->template('col_start_50');

        // H1
        echo $this->backend->headline('#record.headline_data');

        // Buttons
        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_search', "cApp.layers.add('Record/record_global_search.php');cApp.layers.add('Record/record_search.php?' + cApp.layers.queryString());");
        $this->backend->buttonAdd('button_refresh', "cApp.layers.refresh();");
        // Menü rechts: globaler Export / Import (alle Bereiche, Struktur, Templates) -
        // XML-Backups sind dem Superuser vorbehalten
        if ($this->backend->isSuperuser()) {
            $this->backend->buttonAdd('record.record_folder_more_dropdown', '', '', alignRight:true);
        }
        echo $this->backend->buttonBar();

        // Folders and Areas
        // --------------------
        // Nur die Instanzen und lesbaren Bereiche der Benutzergruppe (null = alle)
        $access = $this->backend->access();
        $instanceFilter = $access->instanceFilter();
        $areaFilter = $access->areaFilter(Area::CONTENT_TYPES);
        if ($this->instanceCache->hasMultipleInstances()) {
            // Mandanten als Hauptordner, darunter je Instanz ihr Baum
            foreach ($this->instanceCache->instanceLabels() as $instanceId => $label) {
                if ($instanceFilter !== null && !in_array((int)$instanceId, $instanceFilter, true)) continue;
                $open = in_array($instanceId, $expandedInstanceIds, true);
                echo $this->backend->template($open ? 'record.row_instance_open' : 'record.row_instance', [
                    '{instance_id}' => $instanceId,
                    '{label}' => $this->cms->textOrSnippet($label),
                    '{left}' => 20
                ]);
                if ($open) {
                    $this->area->loadAreas(Area::CONTENT_TYPES, $instanceId, hideDependent:true, areaIds:$areaFilter);
                    $this->printTree($expandedFolderIds, 1);
                }
            }
        }
        else {
            $this->area->loadAreas(areaTypes:Area::CONTENT_TYPES, hideDependent:true, areaIds:$areaFilter);
            $this->printTree($expandedFolderIds, 0);
        }

        echo $this->backend->template('col_end');
        echo $this->backend->template('col_start_50');

        // Rechter Seitenbereich
        // --------------------

        $this->_lastChangedRecords();

        echo $this->backend->template('col_end');
        echo $this->backend->template('row_end');

        // Container Footer
        echo $this->backend->template('container_footer');
    }

    /**
     * Auf- bzw. zuklappen (Request $requestKey + toggle) in der Session vermerken und
     * die Liste der geöffneten Ids liefern.
     *
     * Die Id wird erst in jedem Fall aus der Liste entfernt und beim Öffnen wieder
     * hinzugefügt - so bleibt sie eindeutig, ohne vorher prüfen zu müssen, ob sie
     * schon enthalten ist.
     *
     * @param string $sessionKey  expanded_folder_ids | expanded_instance_ids (JSON)
     * @param string $requestKey  folder_id | instance_id
     * @return int[]
     */
    private function toggleExpanded(string $sessionKey, string $requestKey): array
    {
        $ids = array_map('intval', (array)json_decode((string)$this->session->get($sessionKey)));

        if (isset($_REQUEST[$requestKey])) {
            $id = (int)($_REQUEST[$requestKey] ?? 0);
            $toggle = (bool)(int)($_REQUEST['toggle'] ?? 0); // 1: open, 0: close
            $ids = array_values(array_diff($ids, [$id]));
            if ($toggle) $ids[] = $id;
            $this->session->set($sessionKey, json_encode($ids, JSON_UNESCAPED_UNICODE) ?? '');
        }
        return $ids;
    }

    /**
     * Das geladene Ergebnis von Area::loadAreas() als Baumzeilen ausgeben.
     *
     * @param int[] $expandedFolderIds  geöffnete Ordner
     * @param int   $depthOffset        Einrückung: 1 unter einem Mandanten-Hauptordner
     */
    private function printTree(array $expandedFolderIds, int $depthOffset): void
    {
        $skipUntilRgt = null; // Speichert den rechten Grenzwert des aktuell geschlossenen Ordners

        for ($i=0; $i < $this->db->num_rows; $i++) {
            $data = $this->db->fetch();
            
            // Prüfen, ob wir uns im Zweig eines geschlossenen Ordners befinden
            if ($skipUntilRgt !== null) {
                if ($data['lft'] < $skipUntilRgt) {
                    // Element ist ein Kind/Kindeskind des geschlossenen Ordners -> überspringen
                    continue;
                } else {
                    // Der Zweig des geschlossenen Ordners wurde verlassen
                    $skipUntilRgt = null;
                }
            }

            $label = $this->cms->textOrSnippet($data['label']);
            if ($data['type'] == 'folder') {
                $icon = 'folder';
                $folderId = (int)($data['area_id']);
                if (in_array($folderId, $expandedFolderIds, true)) {
                    // offener Ordner
                    $tpl = 'record.row_folder_open';
                }
                else {
                    // geschlossener Ordner
                    $tpl = 'record.row_folder';
                    // Rgt-Wert des Ordners setzen, um alle folgenden Kinder zu überspringen
                    $skipUntilRgt = $data['rgt'];
                }
            }
            else {
                $icon = $data['icon'];
                $tpl = 'record.row_area';
            }
            echo $this->backend->template($tpl, [
                '{area_id}' => $data['area_id'],
                '{icon}' => $icon,
                '{label}' => $label,
                '{left}' => ((int)$data['depth'] + $depthOffset) * 40 + 20
            ]);
        }
    }

    /**
     * Vom Session-Admin zuletzt geänderte Datensätze listen (siehe Klassenkommentar).
     *
     * Die Ids kommen aus `history`, die Datensätze über Cms::select() - damit fehlen
     * gelöschte (Papierkorb) und solche, die die Benutzergruppe nicht mehr lesen
     * darf, von selbst. Ein Klick öffnet die Detailansicht.
     */
    private function _lastChangedRecords(): void
    {
        $entries = $GLOBALS['app']->get(History::class)->entries('record');
        if (empty($entries)) return;

        $this->db->pushResult();
        $this->cms->select([
            'filter' => ['record_id' => array_keys($entries)],
            'limit'  => count($entries)
        ]);
        $records = [];
        while ($row = $this->record->fetch()) {
            $records[(int)$row['record_id']] = $row;
        }
        $this->db->popResult();
        if (empty($records)) return;

        $rows = '';
        foreach ($entries as $recordId => $changedAt) {
            if (!isset($records[$recordId])) continue;
            $this->db->pushResult();
            $text = $this->detailedText($records[$recordId]);
            $this->db->popResult();
            $rows .= $this->backend->template('record.last_changed_row', [
                '{data_id}'    => $recordId,
                '{text}'       => $text,
                '{changed_at}' => $this->cms->_formatDate($this->cms->utcToUserTZ($changedAt)),
            ]) . "\n";
        }

        echo $this->backend->template('record.last_changed_table', [
            '{table_rows}' => $rows
        ]);
    }
}