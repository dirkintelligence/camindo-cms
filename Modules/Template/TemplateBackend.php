<?php
/**
 * Backend-Oberfläche für die Frontend-Templates (Tabelle `template`).
 *
 * Einstiegspunkte (Scripte in backend/):
 *
 *     index.php                 -> folderList()       Baum der Template-Bereiche
 *     template_list.php         -> list()             Templates eines Bereichs
 *     template_info.php         -> info()             Quelltext ansehen (Reiter 1)
 *     template_access.php       -> infoAccess()       Zuweisungen (Reiter 2)
 *     template_edit.php         -> edit()             anlegen / ändern / löschen
 *     template_move.php         -> move()             in anderen Bereich verschieben
 *     template_sync.php         -> TemplateFiles::sync()  Refresh-Knopf der Listen
 *     template_search.php       -> search()           Suchmaske und Suchausführung
 *     template_global_search.php-> searchCrossArea()  Zwischen-Layer der globalen Suche
 *     ajax_lookup_preview.php   -> lookupPreview()    Lookup-Feld aktualisieren
 *     ajax_slookup_preview.php  -> sLookupPreview()   dito in der Suchmaske
 *
 * ZWEI EBENEN: BEREICHE, DANN TEMPLATES
 * --------------------
 * Templates liegen in Inhaltsbereichen vom Typ 'template'. Der Einstieg ist deshalb
 * nicht die Template-Liste, sondern der Bereichsbaum (folderList()); ein Klick darauf
 * legt die Template-Liste als weiteren Layer darüber. "Neu" im Baum legt entsprechend
 * keinen Datensatz an, sondern öffnet das Formular des Moduls Area.
 *
 * Welche Ordner aufgeklappt sind, merkt sich die SESSION (expanded_folder_ids) - der
 * Baum steht nach dem Neuladen also so da, wie man ihn verlassen hat. Zugeklappte
 * Zweige werden über das Nested Set übersprungen: ab dem geschlossenen Ordner gilt
 * alles mit lft < dessen rgt als Kind (siehe $skipUntilRgt in printTree()).
 *
 * Sind Mandanten eingerichtet, bekommt der Baum wie im Modul Record je Instanz einen
 * Hauptordner (expanded_instance_ids) - siehe RecordBackendFolderList, dessen
 * folderList() hier wortgleich nachgebaut ist; beide Module teilen sich die
 * Session-Spalten, ein hier geöffneter Mandant ist auch dort geöffnet.
 *
 * DER EDITOR FÄRBT NICHT SELBST EIN
 * --------------------
 * info() zeigt nicht `code`, sondern `styled_code` - das ist der beim SPEICHERN vom
 * Parser erzeugte, bereits eingefärbte HTML-Quelltext samt Zeilennummern. Ebenso
 * kommen die Fehlermeldungen aus `error_text` und nicht aus einem erneuten Parserlauf.
 * Bearbeitet wird dagegen im Formular der Quelltext als schlichte Textarea - er
 * kommt aus der Datei var/templates/{instance_id}/{bereich}/{name}.cms.php
 * (Template::hookOnLoaded()) und wird beim Speichern dorthin zurückgeschrieben; die
 * Datei führt, die Spalte `code` dient nur der Suche (siehe TemplateFiles).
 *
 * ZUWEISUNGEN (Reiter "Zugriff")
 * --------------------
 * infoAccess() pflegt, in welchen Inhaltsbereichen das Template benutzt werden darf -
 * je Bereich drei Häkchen (default / selectable / override) plus das standalone-Flag.
 * Die Methode ist ein kleiner Zustandsautomat für sich:
 *
 *     ''        Stand aus der Datenbank laden
 *     update    Formularstand übernehmen und eine Bereichszeile hinzufügen
 *     save      über Template::saveAccess() schreiben
 * Alle drei enden in 'form', das die Tabelle rendert.
 *
 * LOOKUP-BETRIEB
 * --------------------
 * list() dient doppelt: als normale Trefferliste und - wenn src_field im Request steht -
 * als Auswahlfenster eines Lookup-Feldes in einem anderen Formular (z. B.
 * "Default-Template" im Bereichsformular). Dann bekommt jede Zeile eine Checkbox, die
 * per Javascript die Verknüpfung im aufrufenden Formular setzt. Bleibt genau ein noch
 * nicht verknüpfter Treffer übrig, wird er direkt übernommen und der Layer schließt
 * sich - das erspart bei eindeutiger Suche den zusätzlichen Klick.
 *
 *     src_field   Name des Lookup-Feldes im aufrufenden Formular
 *     checked     Bereits verknüpfte Ids
 *
 * @see Camindo\Core\Modules\Template\Template            Dataset/Model, kompiliert beim Speichern
 * @see Camindo\Core\Modules\Template\TemplateParserTrait  Parser und Einfärbung
 * @see Camindo\Core\Modules\Area\AreaBackend              nutzt die Lookup-Methoden hier
 */
namespace Camindo\Core\Modules\Template;

use Camindo\Core\Backend;
use Camindo\Core\System;
use Camindo\Core\Session;
use Camindo\Core\Database;
use Camindo\Core\Cms;
use Camindo\Core\Crypt;
use Camindo\Core\History;
use Camindo\Core\InstanceCache;
use Camindo\Core\Modules\Area\Area;
use Camindo\Core\Modules\Template\Template;

class TemplateBackend {
    // Refresh-Knopf der Listen: erst die Quelltext-Dateien in var/templates in die
    // Datenbank übernehmen (backend/template_sync.php -> TemplateFiles::sync()),
    // dann die Ansicht neu laden
    // POST, weil sync() schreibt (17-csrf.md §2.7); das CSRF-Token kommt aus
    // cApp.system.csrfHeaders()
    /**
     * Parser-Meldungen (`error_text`, durch <br> getrennt) in Fehler und Hinweise
     * ("Line n: Note: …", TemplateParserTrait::inlineHandlerWarnings()) teilen.
     *
     * @return array{0:string[],1:string[]}
     */
    private function _splitParserMessages(string $errorText): array
    {
        $messages = array_values(array_filter(array_map('trim', explode('<br>', $errorText))));
        $notes = array_values(array_filter($messages, fn($m) => str_contains($m, ': Note: ')));
        return [array_values(array_diff($messages, $notes)), $notes];
    }

    /** Höchstzahl Templates im Widget */
    private const WIDGET_MAX = 20;

    private const REFRESH_SCRIPT = "cApp.system.addSpinner();"
        . "$.ajax({type:'POST',url:cConfig.backend_path + 'Modules/Template/template_sync.php',"
        . "data:new FormData(),contentType:false,processData:false,"
        . "headers:cApp.system.csrfHeaders()})"
        . ".always(function(){cApp.layers.refresh();});";

    protected string $iconTemplate = '';

    // Kann Zahl ODER Name sein: info() erlaubt den Aufruf über den Templatenamen,
    // damit ein Verweis aus einem anderen Template direkt anspringbar ist.
    protected string|int $id = 0;
    protected int $areaId = 0;
    protected string $srcField = '';  // lookup call
    protected array $checked = [];    // lookup call
    protected array $areaFilter = [];
    protected ?array $data = [];
    protected ?array $areaData = [];
    protected string $function = '';
    protected string $params = '';

    public function __construct(
        private Backend $backend,
        private System $system,
        private Session $session,
        private Database $db,
        private Cms $cms,
        private Crypt $crypt,
        private Area $area,
        private Template $template,
        private InstanceCache $instanceCache,
    )
    {
        $this->iconTemplate = $this->backend->template('template.icon_template');
    }

    /**
     * Widget für die Startseite (settings.php 'widget'): Parser-Meldungen der
     * Templates (`template`.`error_text`) - wichtig, wenn Templates im Dateisystem
     * (var/templates) bearbeitet werden und niemand den Editor sieht. Fehler und
     * Hinweise (Script ohne CMS:NONCE, Inline-Handler) je Template, nur Bereiche,
     * die die Gruppe lesen darf; dazu immer der Hinweis auf CMS:NONCE.
     */
    public function widget(): string
    {
        $html = $this->backend->template('template.widget_nonce_note');
        $access = $this->backend->access();
        $this->db->query(
            "SELECT `t`.`template_id`, `t`.`area_id`, `t`.`name`, `t`.`error_text`, `a`.`name` AS `area_name`"
            . " FROM `template` `t` INNER JOIN `area` `a` ON `a`.`area_id` = `t`.`area_id` AND `a`.`deleted` = 0"
            . " WHERE `t`.`error_text` <> '' ORDER BY `a`.`name`, `t`.`name`"
        );
        $items = [];
        $more = 0;
        foreach ($this->db->fetchAll() as $row) {
            if (!$access->templateRead((int)$row['area_id'])) continue;
            if (count($items) >= self::WIDGET_MAX) {
                $more++;
                continue;
            }
            [$errors, $notes] = $this->_splitParserMessages((string)$row['error_text']);
            $messages = array_merge($errors, $notes);
            $items[] = $this->backend->template('template.widget_template_button', [
                '{id}' => (string)(int)$row['template_id'],
                '{area_id}' => (string)(int)$row['area_id'],
                '{label}' => htmlspecialchars($row['area_name'] . '.' . $row['name']),
                '{title}' => htmlspecialchars($messages[0] ?? ''),
                '{badge_class}' => $errors ? 'bg-danger' : 'bg-warning text-dark',
                '{number}' => (string)count($messages),
            ]);
        }
        if (!$items) {
            return $html . $this->backend->template('alert_success', ['{text}' => '{#template.widget_no_messages}']);
        }
        $list = '<li>' . implode("</li>\n<li>", $items) . '</li>';
        if ($more > 0) {
            $list .= $this->backend->template('template.widget_more', [
                '{text}' => $this->backend->escape(str_replace('{count}', (string)$more, $this->cms->snippet('template.widget_more'))),
            ]);
        }
        return $html . $this->backend->template('ul_unstyled', ['{items}' => $list]);
    }

    /**
     * Baum der Template-Bereiche (backend/index.php) - der Einstieg ins Modul.
     *
     * Zeigt Ordner und Bereiche vom Typ 'template' als flache Tabelle mit
     * Pixel-Einrückung nach `depth`; die Reihenfolge liefert das Nested Set (lft).
     *
     * AUF- UND ZUKLAPPEN
     * Der Zustand steht in der Session (expanded_folder_ids, als JSON). Ein Klick ruft
     * dieselbe Methode mit folder_id und toggle auf: die Id wird erst in jedem Fall aus
     * der Liste entfernt und beim Öffnen wieder hinzugefügt - so bleibt sie eindeutig,
     * ohne dass vorher geprüft werden muss, ob sie schon drin ist.
     *
     * Zugeklappte Zweige werden nicht etwa aus der Abfrage ausgeschlossen, sondern beim
     * Durchlaufen übersprungen: $skipUntilRgt merkt sich den rechten Grenzwert des
     * geschlossenen Ordners, und alles mit kleinerem lft liegt in seinem Teilbaum.
     * Eine einzige Abfrage genügt damit für den ganzen Baum.
     *
     * "Neu" legt hier kein Template an, sondern einen Bereich - der Knopf öffnet das
     * Formular des Moduls Area mit vorbelegtem Typ.
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
        $this->backend->setNaviLabel('#template.module_label');
        
        // Container header
        echo $this->backend->template('container_header');
        echo $this->backend->template('row_start');
        echo $this->backend->template('col_start_50');

        // Linker Seitenbereich
        // --------------------

        // H1
        echo $this->backend->headline('#template.module_label');

        // Buttons
        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_create_primary', "cApp.layers.add('Area/area_edit.php?function=create&data[type]=template&data[icon]=extension');");
        $this->backend->buttonAdd('button_search', "cApp.layers.add('Template/template_global_search.php');cApp.layers.add('Template/template_search.php?' + cApp.layers.queryString());");
        // Refresh: erst var/templates abgleichen (TemplateFiles::sync()), dann neu laden
        $this->backend->buttonAdd('button_refresh', self::REFRESH_SCRIPT);
        echo $this->backend->buttonBar();

        // Folders and Areas
        // --------------------
        // Nur die Instanzen und lesbaren Template-Bereiche der Benutzergruppe (null = alle)
        $access = $this->backend->access();
        $instanceFilter = $access->instanceFilter();
        $areaFilter = $access->areaFilter(['template']);
        if ($this->instanceCache->hasMultipleInstances()) {
            // Mandanten als Hauptordner, darunter je Instanz ihr Baum
            foreach ($this->instanceCache->instanceLabels() as $instanceId => $label) {
                if ($instanceFilter !== null && !in_array((int)$instanceId, $instanceFilter, true)) continue;
                $open = in_array($instanceId, $expandedInstanceIds, true);
                echo $this->backend->template($open ? 'template.row_instance_open' : 'template.row_instance', [
                    '{instance_id}' => $instanceId,
                    '{label}' => $this->cms->textOrSnippet($label),
                    '{left}' => 20
                ]);
                if ($open) {
                    $this->area->loadAreas(['template'], $instanceId, areaIds:$areaFilter);
                    $this->printTree($expandedFolderIds, 1);
                }
            }
        }
        else {
            $this->area->loadAreas(areaTypes:['template'], areaIds:$areaFilter);
            $this->printTree($expandedFolderIds, 0);
        }

        echo $this->backend->template('col_end');
        echo $this->backend->template('col_start_50');

        // Rechter Seitenbereich
        // --------------------

        $this->_lastChangedTemplates();

        echo $this->backend->template('col_end');
        echo $this->backend->template('row_end');

        // Container Footer
        echo $this->backend->template('container_footer');
    }

    /**
     * Auf- bzw. zuklappen (Request $requestKey + toggle) in der Session vermerken und
     * die Liste der geöffneten Ids liefern (wie RecordBackendFolderList).
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
            // erst in jedem Fall entfernen, beim Öffnen wieder anhängen - so bleibt
            // die Id eindeutig, ohne vorher nachsehen zu müssen
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
                    $tpl = 'template.row_folder_open';
                }
                else {
                    // geschlossener Ordner
                    $tpl = 'template.row_folder';
                    // Rgt-Wert des Ordners setzen, um alle folgenden Kinder zu überspringen
                    $skipUntilRgt = $data['rgt'];
                }
            }
            else {
                $icon = $data['icon'];
                $tpl = 'template.row_area';
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
     * Template-Liste (backend/template_list.php).
     *
     * Zwei Betriebsarten (siehe Klassenkommentar):
     *   - normal: Liste der Templates eines Bereichs
     *   - Lookup: mit src_field im Request wird daraus ein Auswahlfenster für ein
     *     Lookup-Feld in einem anderen Formular; jede Zeile bekommt dann eine
     *     Checkbox, deren onclick die Verknüpfung dort setzt.
     *
     * Der Bereichsfilter geht als fertiges SQL-Fragment ('sql') in select() und nicht
     * als normaler Filter - er soll nicht im Filtertext über der Liste auftauchen, den
     * der Redakteur als seine Sucheingabe liest. Die area_id ist vorher zu int
     * gecastet.
     *
     * Request: area_id | filter | src_field (mindestens eines - sonst schließt sich
     *          der Layer, statt sämtliche Templates zu listen), checked
     */
    public function list()
    {
        // Wenn keine filter, kein area_id und kein Lookup-Feld angegeben ist,
        // dann Layer schließen - anstatt ALLE Datensätze zu listen
        if (empty($_REQUEST['area_id']) && empty($_REQUEST['filter']) && empty($_REQUEST['src_field'])) {
            $this->backend->script('cApp.layers.close();');
            return;
        }

        // Container header
        echo $this->backend->template('container_header');

        $this->_resolveParams();

        // Rechte der Benutzergruppe: Leserecht auf den Template-Bereich (12-group-rights.md)
        $access = $this->backend->access();
        if ($this->areaId > 0 && !$access->templateRead($this->areaId)) {
            echo $access->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }
        $canWrite = ($this->areaId < 1) || $access->templateWrite($this->areaId);

        // Bread crum navi
        if (!empty($this->areaId)) {
            $naviLabel = $this->cms->_strip_tags($this->cms->textOrSnippet($this->areaData['label']));
        }
        else {
            $naviLabel = '#record.headline_global_results';
        }
        $this->backend->setNaviLabel($naviLabel);
        
        // H1
        echo $this->backend->headline('#template.headline_template_list');
        
        // Buttons
        $this->backend->buttonBarReset();
        if (!empty($this->srcField)) {
            if ($canWrite) $this->backend->buttonAdd('button_create', "cApp.layers.add('Template/template_edit.php?{$this->params}&function=create&{$this->params}');");
            $this->backend->buttonAdd('button_search', "cApp.layers.add('Template/template_search.php?' + cApp.layers.queryString());");
            $this->backend->buttonAdd('button_refresh', self::REFRESH_SCRIPT);
            $this->backend->buttonAdd('record.button_apply', "cApp.layers.close();cApp.ui.lookupFocus('{$this->srcField}');");
        }
        else {
            if ($canWrite) $this->backend->buttonAdd('button_create_primary', "cApp.layers.add('Template/template_edit.php?function=create&{$this->params}');");
            $this->backend->buttonAdd('button_search', "cApp.layers.add('Template/template_search.php?' + cApp.layers.queryString());");
            $this->backend->buttonAdd('button_refresh', self::REFRESH_SCRIPT);
        }
        if (!empty($this->areaId) && $this->backend->access()->module('Area')) {
            // Settings des Areas (nur mit Zugriff auf das Modul Area)
            $this->backend->buttonAdd('button_settings', "cApp.layers.replace('Area/area_info.php?id={$this->areaId}&back=true');", alignRight:true);
        }
        echo $this->backend->buttonBar();

        // area-Filer (nicht im Filtertext)
        // --------------------
        $sql = '';
        if (!empty($this->areaId)) {
            $sql = "`template`.`area_id` = {$this->areaId}";
        }

        // Liste
        // --------------------
        $f = $this->cms->select([
            'request' => 'all',
            'limit' => $this->cms->list_rows,
            'count' => 'true',
            'filtertext' => 'true',
            'sql' => $sql,
        ], $this->template);

        // Suchbegriffe oder geänderte Sortierung als lesbarer Text
        echo $this->backend->filterText($f);

        if ($this->db->num_rows < 1) {
            // Keine Ergebnisse keine Tabelle
            echo $this->backend->template('container_footer');
            return;
        }

        // Pagination top
        echo $this->backend->paginationTop('template.listed_records');

        // Prepare Table-Head
        $schema = $this->template->getSchema();
        $thName = $this->backend->tableHeadColumn($schema['name'], $f, 'name');
        $thLabel = $this->backend->tableHeadColumn($schema['label'], $f, 'label');

        // Create Head columns
        if (!empty($this->srcField)) {
            $checkboxHeadColumn = $this->backend->template('template.template_table_checkbox_head_column');
        }
        else {
            $checkboxHeadColumn = '';
        }

        // Render table head
        $headColumns = $this->backend->template('template.template_table_head_columns', [
            '{checkbox_column}' => $checkboxHeadColumn,
            '{label_name}' => $thName,
            '{label_label}' => $thLabel,
        ]);

        // Collect table rows
        $tplTableRow = $this->backend->template('template.template_table_row');
        $tableRows = '';
        
        // Hole Ergebnisse (Dataset::fetch: mit Datennachbeareitung)
        $isLinked = false;
        while ($row = $this->template->fetch()) {
            // Neue Zeile
            $destId = (int)$row['template_id'];

            // Wenn lookup call, dann zusätzliche erste Spalte
            if (!empty($this->srcField)) {

                $isLinked = in_array($destId, $this->checked, false);
                $clickHandler = "cApp.ui.slookupUpdate('{$this->srcField}',\$(this).prop('checked')?'link':'unlink',$destId);";

                $formCheck = $this->backend->template('list_checkbox', [
                    '{onclick}' => $clickHandler,
                    '{checked}' => $isLinked ? ' checked' : ''
                ]);

                // Checkbox in Spalte packen
                $checkboxColumn = $this->backend->template('table_column_noctx_lg', [
                    '{html}' => $formCheck
                ]);
            }
            else {
                $checkboxColumn = '';
            }

            // Zeile (Kontextmenü ohne Schreibrecht auf den Bereich der Zeile leer)
            $rowWritable = $access->templateWrite((int)$row['area_id']);
            $tableRow = strtr($tplTableRow, [
                '{data_id}' => $row['template_id'],
                '{ctx_hide}' => $rowWritable ? '' : ' data-ctx-hide="edit,copy,delete"',
                '{checkbox_column}' => $checkboxColumn,
                '{data_name}' => $row['name'],
                '{data_label}' => $row['label'],
            ]) ."\n";

            $tableRows .= $tableRow;
        }

        // Render table
        echo $this->backend->template('template.template_table_with_head', [
            '{head_columns}' => $headColumns,
            '{table_rows}' => $tableRows,
            '{params}' => $this->params ? "&{$this->params}" : ''
        ]);

        // Context menu - Bearbeiten/Kopieren/Löschen nur mit Schreibrecht auf den
        // Bereich der Zeile (data-ctx-hide, backend-ctx-menu.js)
        echo $this->backend->template('template.template_context_menu');

        // Pagination bottom
        echo $this->backend->paginationBottom();

        // link immediately
        // --------------------
        // Von Lookup geöffnete Liste:
        // Wenn genau ein Datensatz gefunden wurde, der noch nicht verknüpft ist,
        // dann diesen direkt verknüpfen und Layer schließen

        if (!empty($this->srcField)
            && $this->db->num_rows_unlimited === 1
            && !$isLinked
        ) {
            $this->backend->script("
                cApp.ui.slookupUpdate('{$this->srcField}','link',$destId);
            ");
            // Container Footer
            echo $this->backend->template('container_footer');
            return;
        }

        // Container Footer
        echo $this->backend->template('container_footer');
    }

    /**
     * Die wiederkehrenden Seitenparameter einlesen und aufbereiten.
     *
     * Wird von list(), info(), edit() und search() gleich zu Beginn aufgerufen und füllt
     * $id, $areaId, $srcField, $checked, $areaFilter, $areaData und $params.
     *
     * Zwei Dinge sind hier bemerkenswert:
     *
     *  - $id wird nur auf [a-z0-9_.] gefiltert, nicht zu int gecastet: info() erlaubt
     *    auch die Referenz "[bereich.]name" statt der Id.
     *  - $params ist die Sammlung der Parameter, die an jeden weiterführenden Link
     *    angehängt werden müssen (Bereich und Lookup-Zusammenhang), damit der nächste
     *    Layer weiß, woher er kommt.
     *
     * Kann der Bereich nicht geladen werden, wird $this->function auf 'errors' gesetzt -
     * der Aufrufer muss das prüfen (siehe info()).
     */
    private function _resolveParams(): void
    {
        // Seitenparameter
        // --------------------
        // Id oder Referenz "[bereich.]name" (siehe info())
        $this->id = preg_replace('/[^a-z0-9_.]+/i', '', (string)($_REQUEST['id'] ?? ''));
        $this->areaId = (int)($_REQUEST['data']['area_id'] ?? $_REQUEST['area_id'] ?? 0);

        // lookup calls:
        // Steht in Javascript-Aufrufen (lookupFocus/slookupUpdate) - nur Feldnamen
        $this->srcField = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($_REQUEST['src_field'] ?? '')));
        $this->checked = $this->system->sanitizeIds(
            explode(',', (string)($_REQUEST['checked'] ?? ''))
        );

        // $filter[area_ids][]
        $this->areaFilter = $this->system->sanitizeIds(
            (array)($_REQUEST['filter']['area_id'] ?? [])
        );
        $_REQUEST['filter']['area_id'] = $this->areaFilter;


        // Bereichslabel ermitteln
        // --------------------
        $this->areaData = [];
        if ($this->areaId > 0) {
            // Ein Bereich -> Daten laden
            if (!$this->areaData = $this->area->load($this->areaId)) {
                // (Fehlermeldungen in $this->cms->errors)
                $this->function = 'errors';
                return;
            }
        }
        else {
            $this->areaData = [
                'label' => $this->cms->snippet('template.headline_all_areas')
            ];
        }

        // Weiterzureichende Seitenparameter
        // --------------------
        if ($this->areaId > 0) {
            $this->params = 'area_id=' . $this->areaId;
        }
        if (!empty($this->srcField)) {
            $this->params .= '&src_field=' . $this->srcField;
            $this->params .= '&checked=' . implode(',', (array)($this->checked ?? []));
        }
    }

    /**
     * Render Info
     */
    /**
     * Quelltext eines Templates ansehen (backend/template_info.php, Reiter "Inhalt").
     *
     * Aufrufbar über die Id ODER eine Referenz "[bereich.]name" (Cms::findTemplates())
     * - is_numeric() entscheidet. Ein bloßer Name, den es in mehreren Bereichen gibt,
     * wird zuerst im Bereich des verweisenden Templates gesucht (area_id aus dem Link
     * im styled_code, siehe TemplateParserTrait::styleCommandInner()); bleibt er
     * mehrdeutig, gibt es eine Fehlermeldung. Ist unter dem Namen nichts zu finden,
     * wird nicht einfach ein Fehler gezeigt, sondern die Rückfrage, ob das Template
     * jetzt angelegt werden soll: der Normalfall ist ein Verweis aus einem anderen
     * Template auf ein noch nicht existierendes.
     *
     * Gezeigt wird `styled_code`, nicht `code` - die Einfärbung entstand beim
     * Speichern (siehe Klassenkommentar). Davor stehen die gespeicherten
     * Parser-Meldungen aus `error_text`.
     *
     * Die Aufbereitung des styled_code ist reine Darstellung: Tabulatoren zu vier
     * Leerzeichen, jedes zweite Leerzeichen zu einem geschützten (damit HTML die
     * Einrückung nicht zusammenzieht), Zeilenumbrüche zu <br>. Die Zeilennummern
     * daneben sind eine schlichte Aufzählung - sie stimmen, weil der Parser die
     * Zeilenzahl erhält.
     *
     * "Vorschau" erscheint nur bei standalone-Templates: nur die lassen sich ohne
     * Datensatz aufrufen (/0,{name}).
     */
    public function info()
    {
        echo $this->backend->template('container_header');

        // Vorbelegen und danach prüfen: _resolveParams() setzt bei einem nicht
        // ladbaren Bereich 'errors' - der Vergleich unten fängt genau das ab.
        $this->function = 'info';
        $this->_resolveParams();
        if ($this->function !== 'info') {
            $this->_editErrors();
            echo $this->backend->template('container_footer');
            return;
        }

        // Seitenelemente
        // --------------------

        // Bread crum navi
        $this->backend->setNaviLabel('#button_info');

        // Container header
        echo $this->backend->template('container_header');

        // Load data
        if (!is_numeric($this->id)) {
            // Referenz "[bereich.]name" -> template_id
            $templates = $this->cms->findTemplates((string)$this->id);
            if (count($templates) > 1 && $this->areaId > 0) {
                // Bereich des verweisenden Templates bevorzugen
                $inContext = array_values(array_filter(
                    $templates, fn($row) => (int)$row['area_id'] === $this->areaId
                ));
                if (count($inContext) === 1) $templates = $inContext;
            }
            if (count($templates) > 1) {
                echo $this->backend->template('alert_danger', [
                    '{text}' => str_replace('{name}', htmlspecialchars((string)$this->id), $this->cms->snippet('template.error_name_ambiguous'))
                ]);
                echo $this->backend->template('container_footer');
                return;
            }
            if (count($templates) < 1) {
                // Template nicht gefunden.
                // -> Rückfrage: Soll es jetzt erstellt werden?
                $refParts = explode('.', (string)$this->id);
                $name = end($refParts);
                echo $this->backend->template('template.message_with_create_link', [
                    '{area_id}' => $this->areaId,
                    '{name}' => $name
                ]);
                echo $this->backend->template('container_footer');
                return;
            }
            $this->id = (int)$templates[0]['template_id'];
        }
        if (!$this->data = $this->template->load((int)$this->id)) {
            $this->_editErrors();
            echo $this->backend->template('container_footer');
            return;
        }
        $this->id = (int)$this->data['template_id'];
        $this->areaId = (int)$this->data['area_id'];

        // Rechte der Benutzergruppe: Leserecht auf den Template-Bereich
        $access = $this->backend->access();
        if (!$access->templateRead($this->areaId)) {
            echo $access->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }
        $canWrite = $access->templateWrite($this->areaId);

        // H1
        echo $this->backend->headline("{$this->iconTemplate} {$this->data['name']}");
        
        // Tabs ("Änderungen" = Audit-Log, Backend::auditTabAdd())
        $auditActive = empty($this->srcField) && $this->backend->auditTabActive();
        if (empty($this->srcField)) {
            $this->backend->tabBarReset();
            $this->backend->tabAdd('#template.headline_content', $auditActive ? "cApp.layers.replace('Template/template_info.php?id={$this->id}')" : '', !$auditActive);
            $this->backend->tabAdd('#template.headline_access', "cApp.layers.replace('Template/template_access.php?id={$this->id}')");
            $this->backend->auditTabAdd("Template/template_info.php?id={$this->id}");
            echo $this->backend->tabBar();
        }

        // buttons (Ändern nur mit Schreibrecht)
        $this->backend->buttonBarReset();
        if (empty($this->srcField)) {
            if ($canWrite) {
                $this->backend->buttonAdd('button_edit_primary', "cApp.layers.replace('Template/template_edit.php?id={$this->id}&{$this->params}');");
                $this->backend->buttonAdd('button_copy', "cApp.layers.replace('Template/template_edit.php?id={$this->id}&function=copy&{$this->params}');");
                $this->backend->buttonAdd('button_delete', "cApp.layers.replace('Template/template_edit.php?id={$this->id}&function=delete&{$this->params}');");
                $this->backend->buttonAdd('button_move', "cApp.layers.replace('Template/template_move.php?id={$this->id}&{$this->params}');");
            }
            if ((int)$this->data['standalone'] > 0 && (string)($this->data['type'] ?? '') !== 'scheduler') {
                $this->backend->buttonAddPreview("0,{$this->data['name']}", (int)($this->data['instance_id'] ?? 0));
            }
        }
        if (!empty($_REQUEST['back'])) {
            $this->backend->buttonAdd('button_back', "history.back()");
        }
        else {
            $this->backend->buttonAdd('button_close', "cApp.layers.close()");
        }
        echo $this->backend->buttonBar();

        if ($auditActive) {
            echo $this->backend->auditHistory('template', (int)$this->id);
            echo $this->backend->template('container_footer');
            return;
        }

        // data fields
        // --------------------
        $areaLabel = $this->backend->teaser('area', $this->areaId);
        $this->backend->infoAdd($this->template, 'label', $this->data['label'], ['primary']);
        $this->backend->infoAdd($this->template, 'name', $this->data['name']);
        $this->backend->infoAdd($this->template, 'area_id', $areaLabel, ['html']);
        $this->backend->infoAdd($this->template, 'template_id', $this->data['template_id']);
        $this->backend->infoAdd($this->template, 'instance_id',
            $this->backend->teaser('instance', (int)$this->data['instance_id'])
            .' &middot; instance_id : ' . $this->data['instance_id'],
            ['html']
        );
        echo $this->backend->infoTable();

        // code
        // --------------------
        echo $this->backend->template('spacer');
        echo $this->backend->template('headline3', [
            '{text}' => '{#template.headline_code}'
        ]);

        // parsing errors
        // --------------------
        // Fehler rot, Hinweise (Script-Policy, "Note:") gelb; maskiert - die
        // Meldungen zitieren Quelltext (<script …>)
        if (!empty($this->data['error_text'])) {
            [$errors, $notes] = $this->_splitParserMessages((string)$this->data['error_text']);
            if ($errors) echo $this->backend->template('alert_danger', ['{text}' => implode('<br>', array_map('htmlspecialchars', $errors))]);
            if ($notes) echo $this->backend->template('alert_warning', ['{text}' => implode('<br>', array_map('htmlspecialchars', $notes))]);
        }

        // styled_code
        // --------------------
        $styledCode = $this->data['styled_code'] ?? '';
        // Multiple Spaces
        $styledCode = str_replace("\t",'    ', $styledCode);
        $styledCode = str_replace("  ","\u{00A0} ", $styledCode);
        // Linebreak
        $styledCode = $this->cms->_nl2br($styledCode);

        $lineCount = count(preg_split('/\R/', $styledCode));
        $lineNumbers = implode("<br>\n", range(1, $lineCount));

        echo $this->backend->template('template.template_code', [
            '{line_numbers}' => $lineNumbers,
            '{code}' => $styledCode
        ]);

        echo $this->backend->template('container_footer');
    }

    /**
     * Zuweisungen pflegen (backend/template_access.php, Reiter "Zugriff").
     *
     * Legt fest, in welchen Inhaltsbereichen das Template benutzt werden darf. Je
     * Bereich eine Zeile mit drei Häkchen:
     *
     *     default      Standard-Template des Bereichs (höchstens eines je Bereich;
     *                  landet in area.default_template_id)
     *     selectable   im Datensatz wählbar
     *     override     per URL erzwingbar (/{id},{template})
     *
     * Dazu das Feld `standalone`: das Template darf ohne Datensatz aufgerufen werden.
     *
     * Kleiner Zustandsautomat über function (siehe Klassenkommentar): '' lädt den
     * gespeicherten Stand, 'update' übernimmt den Formularstand und fügt eine Zeile
     * hinzu, 'save' schreibt. Das Hinzufügen läuft ohne eigenen Knopf - die
     * Auswahlliste schickt das Formular per onchange mit function=update ab.
     *
     * Zu jeder Zeile gehört ein verstecktes Feld [nop]: eine Zeile, in der ALLE
     * Häkchen abgewählt sind, würde sonst gar nicht mitgeschickt und beim Speichern
     * verschwinden, statt als "nichts erlaubt" erhalten zu bleiben.
     *
     * Geschrieben wird beides zusammen in Template::saveAccess().
     */
    public function infoAccess()
    {
        echo $this->backend->template('container_header');

        $this->function = (string)($_REQUEST['function'] ?? '');
        $this->id = (int)($_REQUEST['id'] ?? 0);
        $this->data = (array)($_REQUEST['data'] ?? []);

        // Seitenelemente
        // --------------------

        // Bread crum navi
        $this->backend->setNaviLabel('#button_info');

        // Container header
        echo $this->backend->template('container_header');

        // Load data
        if (!$templateData = $this->template->load($this->id)) {
            // Template nicht gefunden.
            echo $this->backend->template('alert_danger', [
                '{text}' => '{#template.error_template_not_found}'
            ]);
            echo $this->backend->template('container_footer');
            return;
        }

        $this->areaId = (int)$templateData['area_id'];

        // Rechte der Benutzergruppe: Lesen des Bereichs; Ändern der Zuweisungen
        // nur mit Schreibrecht (Anzeige bleibt lesend)
        $access = $this->backend->access();
        if (!$access->templateRead($this->areaId) || ($this->function !== '' && !$access->templateWrite($this->areaId))) {
            echo $access->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }

        if ($this->function === '') {
            $this->data['standalone'] = (int)$templateData['standalone'];
            $this->data['type'] = (string)$templateData['type'];
        }

        // H1
        echo $this->backend->headline("{$this->iconTemplate} {$templateData['name']}");
        
        // Tabs
        if (empty($this->srcField)) {
            $this->backend->tabBarReset();
            $this->backend->tabAdd('#template.headline_content', "cApp.layers.replace('Template/template_info.php?id={$this->id}')");
            $this->backend->tabAdd('#template.headline_access', '', true);
            $this->backend->auditTabAdd("Template/template_info.php?id={$this->id}");
            echo $this->backend->tabBar();
        }

        // Zugriff
        // --------------------
        echo $this->backend->template('spacer');
        echo $this->backend->template('headline3', [
            '{text}' => '{#template.headline_access}'
        ]);

        echo '<form action="Template/template_access.php" method="post">';

        $this->backend->formInit('template.access_form');

        // standalone
        // --------------------
        $this->backend->formField($this->template, [
            'name' => 'standalone',
            'value' => $this->data['standalone'],
            'id' => $this->id
        ]);

        // Verwendung: zeitgesteuert (Scheduler) - dann nie per URL aufrufbar
        // --------------------
        $this->backend->formField($this->template, [
            'name' => 'type',
            'value' => (string)($this->data['type'] ?? ''),
            'id' => $this->id,
            'options' => [
                '' => $this->cms->snippet('template.option_type_page'),
                'scheduler' => $this->cms->snippet('template.option_type_scheduler'),
            ],
        ]);

        // Template-Zuweisung zu Content-Bereichen
        // --------------------
        if ($this->function === '') {
            // Aktuellen Stand der Zuweisungen laden
            $rows = $this->loadAreaAssignment($this->id);
    
            // Zuweisungen in $data umwandeln
            $this->data['access'] = [];
            foreach ($rows as $row) {
                $rowAreaId = (int)($row['area_id']);
                $isDefault = ((int)$row['template_id'] === (int)$row['default_template_id']);
                $this->data['access'][$rowAreaId] = [
                    'default' => (int)$isDefault,
                    'selectable' => (int)$row['selectable'],
                    'override' => (int)$row['override']
                ];
            }
            $this->function = 'form';
        }
        elseif ($this->function === 'update') {
            // Zuweisungen aus Formualar empfangen
            $this->data['access'] = (array)($this->data['access'] ?? []);
            // Zeile hinzufügen
            $addAreaId = (int)($this->data['add_area_id'] ?? 0);
            if (!empty($addAreaId)) {
                $this->data['access'][$addAreaId] = [
                    'default' => 0,
                    'selectable' => 0,
                    'override' => 0
                ];
            }
            $this->function = 'form';
        }
        elseif ($this->function === 'save') {
            // Zuweisungen schreiben
            if (!$this->_saveAccess()) {
                $this->_editErrors();
            };
            echo $this->backend->template('alert_success', [
                '{text}' => '{#template.message_data_saved}'
            ]);
            $this->function = 'form';
            $this->backend->balloon('success', $this->cms->snippet('template.message_data_saved'));

            // Umleiten auf Reiter "Content"
            $this->backend->script("cApp.layers.replace('Template/template_info.php?id={$this->id}');");
        }


        // Leerer if-Rumpf - ohne Wirkung. Alle drei Zweige oben enden in 'form',
        // die Tabelle wird also ohnehin immer gebaut. Kann entfallen.
        if ($this->function === 'form') {}
        // Tabelle mit Checkboxen aufbauen - ohne Zuweisungen (typisch für Standalone- und
        // zeitgesteuerte Templates) schickt das Formular kein data[access]
        $this->data['access'] = (array)($this->data['access'] ?? []);
        $tableRows = [];
        if (count($this->data['access']) > 0) {
            foreach($this->data['access'] as $rowAreaId => $rowData) {
                $defaultCheckbox = $this->_accessCheckbox($rowAreaId, 'default', (bool)($rowData['default'] ?? 0));
                $electableCheckbox = $this->_accessCheckbox($rowAreaId, 'selectable', (bool)($rowData['selectable'] ?? 0));
                $overrideCheckbox = $this->_accessCheckbox($rowAreaId, 'override', (bool)($rowData['override'] ?? 0));
                $areaLabel = $this->backend->teaser('area', $rowAreaId, linked:true);
                $tableRows[] = $this->backend->template('template.access_table_row', [
                    '{content_area}' => $areaLabel,
                    '{default}' => $defaultCheckbox,
                    '{selectable}' => $electableCheckbox,
                    '{override}' => $overrideCheckbox,
                ]);

                // Hiddenfeld, sichert, dass area_id registriert bleibt, auch bei fehlenden Checkboxen
                $this->backend->formHidden("data[access][{$rowAreaId}][nop]", 1);
            }
            $table = $this->backend->template('template.access_table', [
                '{table_rows}' => implode("\n", $tableRows)
            ]);
        }
        else {
            $table = $this->backend->template('template.no_access_yet');
        }

        $this->backend->formReplace('{access_table}', $table);

        // Auswahl-Liste
        // --------------------
        $colDef = [
            'type' => 'id',
            'display_type' => 'select',
            'label' => '#template.button_add_area'
        ];
        $areaOptions = [];
        // Ids der bereits verwendeten Contentbereiche ausschließen
        $excludedAreaIds = implode(',', $this->system->sanitizeIds(
            array_keys($this->data['access']), true
        ));
        if (!empty($excludedAreaIds)) $areaFilter = " AND `area_id` NOT IN ($excludedAreaIds)";
        else $areaFilter = '';
        // Inhaltsbereiche nur die lesbaren der Gruppe; Ordner (nicht wählbar) bleiben
        $accessSql = $this->backend->areaAccessSql();
        if ($accessSql !== '') $areaFilter .= " AND (`type` = 'folder' OR (1=1" . $accessSql . "))";
        $this->db->query("SELECT `area_id`,`depth`,`label`,`type` FROM `area`"
            ." WHERE `type` != 'template' AND `deleted`=0" . $areaFilter
            ." ORDER BY `lft` ASC");
        while ($areaData = $this->db->fetch()) {
            $fullPath = str_repeat('&nbsp; ', (int)$areaData['depth'] * 2) . $this->cms->textOrSnippet($areaData['label']);
            if ($areaData['type'] === 'folder') {
                $relAreaId = 'disabled_' . $areaData['area_id'];
            }
            else {
                $relAreaId = (string)$areaData['area_id'];
            }
            $areaOptions[$relAreaId] = $fullPath;
        }
        $this->backend->formField($this->template, [
            'name' => 'add_area_id',
            'value' => $this->data['add_area_id'],
            'colDef' => $colDef,
            'options' => $areaOptions,
            'id' => $this->id,
            'attr' => ['onchange' => "if(this.value!=''){\$('.c9-layer:last input[name=\'function\']').val('update');\$('.c9-layer:last form').submit();}"],
            'selectPlease' => true
        ]);

        // hidden fields
        $this->backend->formHidden('id', $this->id);
        $this->backend->formHidden('function', 'save');

        // Formular rendern
        echo $this->backend->form('template.form_save_cancel', [
            '{template_id}' => $this->id
        ]);

        echo '</form>';
    }

    /**
     * Eine Häkchen-Zelle der Zuweisungstabelle rendern.
     *
     * Der Feldname trägt Bereich und Spalte im Namen
     * (data[access][{area_id}][{spalte}]) - so kommt die ganze Tabelle als
     * verschachteltes Array zurück und lässt sich unverändert an
     * Template::saveAccess() weitergeben.
     */
    private function _accessCheckbox(int $rowAreaId, string $column, bool $isChecked): string
    {
        return $this->backend->template('edit_form_checkbox', [
            '{name}' => "data[access][{$rowAreaId}][{$column}]",
            '{value}' => 1,
            '{checked}' => $isChecked ? 'checked' : '',
            '{id}' => ''
        ]);
    }

    /**
     * Hilfsfunction: Geänderte Bereich-Template-Zuweisungen aktualisieren
     * sowie das Standalone-Flag
     */
    private function _saveAccess(): bool
    {
        return $this->template->saveAccess((int)$this->id, $this->data['access'] ?? [], $this->data['standalone'] ?? 0, (string)($this->data['type'] ?? ''));
    }

    /**
     * Template in einen anderen Template-Bereich verschieben (backend/template_move.php).
     *
     * Erreichbar über den Button "Verschieben" der Detailansicht - wie bei einem
     * Datensatz (RecordBackendMove), nur ohne Feldzuordnung: ein Template hat keine
     * bereichsabhängigen Felder. Ein Schritt: Zielbereich wählen und abschicken;
     * function=move verschiebt über Template::moveToArea(). Scheitert das (etwa weil der Name im Zielbereich
     * schon vergeben ist - unique_in_area), erscheint die Maske mit den Fehlern erneut.
     *
     * Verweise anderer Templates über den bloßen Namen bleiben gültig, solange der
     * Name danach nicht in mehreren Bereichen vorkommt; sonst müssen sie den
     * Bereich mit angeben ("bereich.name", siehe Cms::templateFile()).
     *
     * Request: id, target_area_id (auch als data[target_area_id]), function ('move')
     */
    public function move(): void
    {
        echo $this->backend->template('container_header');

        $this->function = 'info';
        $this->_resolveParams();
        if ($this->function !== 'info') {
            $this->_editErrors();
            echo $this->backend->template('container_footer');
            return;
        }
        $this->backend->setNaviLabel('#button_move');

        if (!$this->data = $this->template->load((int)$this->id)) {
            $this->_editErrors();
            echo $this->backend->template('container_footer');
            return;
        }
        $this->id = (int)$this->data['template_id'];
        $this->areaId = (int)$this->data['area_id'];
        $this->params = 'area_id=' . $this->areaId;

        // Rechte der Benutzergruppe: Schreibrecht im Quellbereich (Ziel: Template::moveToArea())
        if (!$this->backend->access()->templateWrite($this->areaId)) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }

        echo $this->backend->template('headline1', [
            '{text}' => '{#template.headline_template_move}'
        ]);
        echo $this->backend->template('p', [
            '{text}' => $this->backend->teaser('template', $this->id)
        ]);

        $function = (string)($_REQUEST['function'] ?? '');
        $targetAreaId = (int)($_REQUEST['data']['target_area_id'] ?? $_REQUEST['target_area_id'] ?? 0);

        if ($function === 'move' && $targetAreaId > 0) {
            if ($this->template->moveToArea($this->id, $targetAreaId)) {
                $this->backend->balloon('success', $this->cms->snippet('template.message_template_moved'));
                $this->backend->script(
                    "cApp.layers.invalidate();"
                    ."cApp.layers.replace('Template/template_info.php?area_id={$targetAreaId}&id={$this->id}');"
                );
                echo $this->backend->template('container_footer');
                return;
            }
            $this->_editErrors();
        }

        // Zielbereich wählen: alle Template-Bereiche außer dem eigenen
        $areaOptions = $this->_areaOptions();
        unset($areaOptions[(string)$this->areaId]);

        echo '<form action="Template/template_move.php" method="post">';
        $this->backend->formInit('template.template_move_form');
        $this->backend->formField($this->template, [
            'name' => 'target_area_id',
            'value' => $targetAreaId,
            'id' => $this->id,
            'colDef' => [
                'type' => 'integer',
                'display_type' => 'select',
                'label' => '#template.headline_move_target'
            ],
            'options' => $areaOptions,
            'selectPlease' => true
        ]);
        $this->backend->formHidden('id', $this->id);
        $this->backend->formHidden('function', 'move');
        echo $this->backend->form('template.form_move_cancel', [
            '{id}' => $this->id,
            '{params}' => $this->params
        ]);
        echo '</form>';

        echo $this->backend->template('container_footer');
    }

    /**
     * Erstellen / kopieren / ändern / löschen (backend/template_edit.php).
     *
     * Zustandsautomat über $_REQUEST['function'] wie in den anderen Modulen
     * (docs/developers/03-backend-entwicklung.md). Anders als dort ist der Standardwert direkt im
     * Zugriff hinterlegt ('edit'), und die Seitenparameter kommen aus
     * _resolveParams() - unter anderem der Lookup-Zusammenhang, der über das Formular
     * hinweg erhalten bleiben muss.
     */
    public function edit(): void
    {
        // Seiten-Parameter
        $this->_resolveParams();
        $this->function = (string)($_REQUEST['function'] ?? 'edit');

        $this->data = (array) ($_REQUEST['data'] ?? []);

        // Bread crum navi
        $this->backend->setNaviLabel('#button_' . $this->function);

        // Container header
        echo $this->backend->template('container_header');

        // Create
        if ($this->function == 'create') {
            $this->_editCreate();
        }

        // Load
        if (in_array($this->function, ['edit', 'copy', 'delete'], true)) {
            $this->_editLoad();
        }

        // Rechte der Benutzergruppe: Schreibrecht auf den Template-Bereich (beim
        // Speichern zählt der Bereich des Formulars; Dataset::save() prüft erneut)
        $accessAreaId = (int)($this->data['area_id'] ?? $this->areaId);
        if ($this->function !== 'skip' && $accessAreaId > 0 && !$this->backend->access()->templateWrite($accessAreaId)) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }

        // H1
        echo $this->_editHeadline();

        // Save
        if ($this->function == 'save') {
            $this->_editSave();
        }

        // Delete confirm
        if ($this->function == 'delete_confirm') {
            $this->_editDeleteConfirm();
        }

        // Errors
        if (in_array($this->function, ['errors','skip'], true)) {
            $this->_editErrors();
        }

        // Edit form
        if (in_array($this->function, ['create', 'edit', 'copy', 'errors'], true)) {
            $this->_editForm();
        }

        // Delete form
        if ($this->function == 'delete') {
            $this->_editDeleteForm();
        }

        echo $this->backend->template('container_footer');
    }


    // ====================
    // edit() Hilfsfunktionen

    /**
     * function=create: leeres Formular mit den Schema-Defaults.
     *
     * $this->id kann hier ausnahmsweise ein NAME sein: kommt der Aufruf aus der
     * Rückfrage "Template existiert nicht - jetzt anlegen?" (siehe info()), steht dort
     * der gesuchte Name. Er wird als Vorbelegung übernommen, danach ist die Id 0.
     */
    private function _editCreate(): void
    {
        $this->data = $this->template->init();
        // Name in der URL angeben?
        // siehe html snippet "template.message_with_create_link"
        $this->data['name'] = $this->id;
        $this->id = 0;
    }

    /**
     * function=edit|copy|delete: Template laden.
     *
     * Beim Kopieren wird nur die Id verworfen; der Name bleibt zunächst gleich und
     * läuft beim Speichern in die unique_in_area-Prüfung - der Redakteur muss ihn
     * also bewusst ändern.
     */
    private function _editLoad(): void
    {
        $this->data = $this->template->load($this->id);
        if (!$this->data) {
            // Angeforderter Datensatz nicht gefunden
            $this->function = 'skip';
        }
        else {
            $this->areaId = (int)$this->data['area_id'];
        }
        // Wenn Daten kopiert werden sollen, dann jetzt Id zurücksetzen
        if ($this->function == 'copy') {
            $this->id = 0;
        }
    }

    /**
     * Überschrift des Formulars. Beim Löschen der Templatename, sonst die allgemeine
     * Überschrift.
     *
     * (Als einzige der edit()-Hilfsmethoden public - abweichend von der Konvention,
     * wie in AdminBackend.)
     */
    private function _editHeadline() {
        if ($this->function == 'delete') {
            $label = $this->data['name'] ?? '';
        }
        else {
            $label = $this->cms->snippet('template.headline_template_info');
        }
        return $this->backend->template('headline1', [
            '{text}' => $this->iconTemplate . ' ' . $label,
        ]);
    }

    /**
     * function=save: speichern.
     *
     * Geschrieben wird nur, was in der Liste steht. `styled_code` und `error_text`
     * stehen mit drin, obwohl sie kein Eingabefeld haben - Template::hookOnSave()
     * befüllt sie aus dem Quelltext, und ohne Eintrag in der Liste käme das Ergebnis
     * nicht in die Tabelle. Den Quelltext selbst schreibt Template::hookOnSaved()
     * in die Datei var/templates/... - sie wird überschrieben, auch wenn sie
     * zwischenzeitlich von Hand geändert wurde (das Formular zeigte den Stand beim
     * Öffnen).
     */
    private function _editSave(): void
    {
        // Prüfe Daten und speichere
        $fields= [
            'area_id',
            'name',
            'label',
            'code',
            'styled_code',
            'error_text',
        ];
        $this->data['template_id'] = $this->id;
        if ($this->template->save($fields, $this->data)) {
            $this->id = (int)$this->data['template_id'];
            // Liste "Zuletzt geändert" (folderList())
            $GLOBALS['app']->get(History::class)->touch('template', $this->id);
            // Balloon Message
            $msg = $this->cms->snippet('template.message_template_saved');
            $this->backend->balloon('success', $msg);
            if (!empty($this->srcField)) {
                // Referrer ist Verknüpfungsliste
                $this->backend->script("
                    cApp.layers.close();
                    cApp.ui.slookupUpdate('{$this->srcField}','link',$this->id);
                ");
            }
            else {
                // Referer (Liste) aktualisieren und Weiterleiten auf Info-Seite
                $this->backend->script("
                    cApp.layers.invalidate();
                    cApp.layers.replace('Template/template_info.php?id={$this->id}');
                ");
            }
        }
        else {
            $this->function = 'errors';
        }
    }

    /**
     * function=delete_confirm: löschen.
     *
     * Die kompilierte Datei var/php/{area_id}/{name}.php entfernt
     * Template::hookOnDelete().
     */
    private function _editDeleteConfirm(): void
    {
        if ($this->template->delete($this->id)) {
            // Balloon Message
            $msg = $this->cms->snippet('template.message_template_deleted');
            $this->backend->balloon('success', $msg);
            
            // Fenster schließen
            $this->backend->script("
                cApp.layers.invalidate();
                cApp.layers.close();
            ");
        }
        else {
            $this->function = 'errors';
        }
    }

    /**
     * Fehlermeldungen aus Cms::errors als roten Kasten ausgeben.
     */
    private function _editErrors(): void
    {
        echo $this->backend->template('alert_danger',[
            '{text}' => $this->cms->errorMsg()
        ]);
    }

    /**
     * Das Eingabeformular.
     *
     * Vier Felder: Bereich (Auswahl unter den Template-Bereichen), Name, Beschriftung
     * und der Quelltext als mitwachsende Textarea - der Quelltext stammt aus der
     * Datei in var/templates (Template::hookOnLoaded()), nicht aus der Spalte `code`.
     * Kompiliert wird beim Speichern in Template::hookOnSave() - hier passiert nichts
     * dergleichen.
     *
     * src_field und checked reisen als versteckte Felder mit, damit ein aus einem
     * Lookup-Feld heraus angelegtes Template anschließend dort verknüpft werden kann.
     *
     * (Der Aufruf von _initTextareaGrow() steht zweimal da - einmal genügt.)
     */
    private function _editForm(): void
    {
        if ($this->function == 'save') $changed = ' data-changed="true"';
        else $changed = '';
        echo '<form action="Template/template_edit.php" method="post"' . $changed . '>';

        $this->backend->formInit('template.template_edit_form');

        // label
        $this->backend->formField($this->template, [
            'name' => 'label',
            'value' => $this->data['label'] ?? '',
            'id' => $this->id,
            'primary' => true
        ]);

        // name
        $this->backend->formField($this->template, [
            'name' => 'name',
            'value' => $this->data['name'] ?? '',
            'id' => $this->id
        ]);

        // area_id
        $areaOptions = $this->_areaOptions();
        $this->backend->formField($this->template, [
            'name' => 'area_id',
            'value' => $this->areaId,
            'display_type' => 'select',
            'options' => $areaOptions,
            'id' => $this->id
        ]);

        $this->backend->formField($this->template, [
            'name' => 'code',
            'value' => $this->data['code'] ?? '',
            'id' => $this->id,
            'template' => 'template.edit_form_code_growing',
            'fullWidth' => true
        ]);
        
        // JavaScript Anweisung für growing textarea, nach Ajax-Load
        $this->backend->script("cApp.ui._initTextareaGrow(\$('.c9-layer:last'));");

        // hidden fields
        $this->backend->formHidden('function', 'save');
        $this->backend->formHidden('id', $this->id);

        // form called via lookup field?
        $this->backend->formHidden('src_field', $this->srcField);
        $this->backend->formHidden('checked', implode(',',(array)($this->checked ?? [])));

        // Formular rendern
        echo $this->backend->form('form_save_cancel_note');

        // Textarea initialisieren
        $this->backend->script("cApp.ui._initTextareaGrow(\$('.c9-layer:last'));");
        
        echo '</form>';
    }

    /**
     * Wirklich löschen? Formular
     */
    private function _editDeleteForm(): void
    {
        echo '<form action="Template/template_edit.php" method="post">';

        $this->backend->formInit('template.template_delete_form');

        $this->backend->formHidden('id', $this->id);
        $this->backend->formHidden('function', 'delete_confirm');
        // form called via lookup field?
        $this->backend->formHidden('src_field', $this->srcField);
        $this->backend->formHidden('checked', implode(',',(array)($this->checked ?? [])));

        // Print form
        echo $this->backend->form('form_delete_cancel');

        echo '</form>';
    }


    // ====================
    // Suchfunktion


    /**
     * Search results
     */
    public function search():void
    {
        $this->_resolveParams();
        $function = (string)($_POST['function'] ?? '');

        if ($function === 'search') {
            $this->_search();
        }

        if ($function === '') {
            $this->_searchForm();
        }
    }

    /**
     * function=search: Suche ausführen.
     *
     * Wie in den anderen Modulen: keine eigene Trefferliste, sondern Suchlayer
     * schließen und den Listenlayer mit den Suchparametern in der URL neu laden.
     * json_encode maskiert alles, was das Javascript-Literal beenden könnte;
     * substr(..., 1) entfernt nur das öffnende Anführungszeichen, das schließende
     * beendet unten den String.
     */
    private function _search(): void
    {
        // Parameter aus Post
        $queryString = http_build_query($_POST);

        // Sicher für Javascript verpacken
        $safeParams = json_encode($queryString, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $safeUri = '"Template/template_list.php?' . substr($safeParams, 1);

        // Referer-Layer mit diesen Parametern neu laden
        $this->backend->script("
            cApp.layers.close();
            cApp.layers.replace({$safeUri});
        ");
    }

    /**
     * Leerer Zwischen-Layer für die bereichsübergreifende Suche.
     *
     * Die Layer stapeln sich: Bereichsbaum, darüber die Trefferliste. Eine Suche über
     * alle Bereiche hat aber keinen Bereich, aus dem heraus sie geöffnet wäre - ohne
     * diesen Platzhalter läge die Trefferliste direkt auf dem Baum und würde ihn beim
     * Schließen mitnehmen. Der Layer enthält deshalb nichts außer einem
     * Schließen-Knopf.
     */
    public function searchCrossArea():void
    {
        $this->backend->setNaviLabel('#record.headline_global_results');

        echo $this->backend->template('container_header');
        echo $this->backend->template('button_close', [
            '{onclick}' => 'cApp.layers.close();'
        ]);
        echo $this->backend->template('container_footer');
    }

    /**
     * Search form
     */
    private function _searchForm():void
    {
        // Seiten-Parameter
        $filter = (array) ($_REQUEST['filter'] ?? []);

        echo '<form action="Template/template_search.php" method="post">';

        // Bread crum navi
        $this->backend->setNaviLabel('#button_search');
        
        // Container header
        echo $this->backend->template('container_header');

        // H1
        echo $this->backend->headline('#template.headline_search');

        $this->backend->formInit('template.template_search_form');


        // label, name
        $this->backend->searchField($this->template, ['name' => 'label', 'value' => $filter['label'] ?? '']);
        $this->backend->searchField($this->template, ['name' => 'name', 'value' => $filter['name'] ?? '']);

        // area_id
        if (empty($this->areaId)) {

            $areaOptions = $this->_areaOptions();
            $areaColDef = [
                'type' => 'json', // Mehrfachauswahl fester Werte erlauben
                'display_type' => 'multiselect',
                'label' => '#template.template_area'
            ];

            $this->backend->searchField($this->template, [
                'name' => 'area_id',
                'value' => $this->areaFilter,
                'colDef' => $areaColDef,
                'options' => $areaOptions
            ]);
        }
        else {
            echo $this->backend->template('edit_form_static', [
                '{label}' => '{#template.template_area}',
                '{html}' => $this->cms->textOrSnippet($this->areaData['label']),
            ]);
        }

        // weitere Felder
        $this->backend->searchField($this->template, ['name' => 'template_id', 'value' => $filter['template_id'] ?? '']);
        $this->backend->searchField($this->template, ['name' => 'code', 'value' => $filter['code'] ?? '']);

        // Sort, Order
        $sortOptions = [            
            'name' => $this->cms->snippet('template.field_name'),
            'label' => $this->cms->snippet('template.field_label'),
            'created_at' => $this->cms->snippet('template.field_created_at'),
            'changed_at' => $this->cms->snippet('template.field_changed_at'),
            'standalone' => $this->cms->snippet('template.field_standalone'),
            'area_id' => $this->cms->snippet('area_id'),
            'template_id' => 'template_id'
        ];
        $this->backend->searchFieldSortOrder($sortOptions);

        // Hidden fields
        $this->backend->formHidden('function', 'search');
        $this->backend->formHidden('area_id', $this->areaId);
        // form called via lookup field?
        $this->backend->formHidden('src_field', $this->srcField);
        $this->backend->formHidden('checked', implode(',',(array)($this->checked ?? [])));

        // Print form
        echo $this->backend->form('form_search_cancel', removePlaceholders:true);

        // Container footer
        echo $this->backend->template('container_footer');

        echo '</form>';
    }

    /**
     * Wählbare Template-Areas für Optionsliste zusammentragen
     */
    /**
     * Auswahlliste der Template-Bereiche.
     *
     * Nur Bereiche vom Typ 'template' - in andere lassen sich keine Templates legen.
     * Die Einrückung entsteht aus `depth`, die Reihenfolge aus `lft`; anders als in
     * FieldBackend sind Ordner hier gar nicht erst enthalten.
     */
    private function _areaOptions(): array
    {
        $this->db->query("SELECT `area_id`, `depth`, `label` FROM `area`"
            ." WHERE `type` = 'template' AND `deleted`=0" . $this->backend->areaAccessSql(writable:true)
            ." ORDER BY `lft` ASC");
        $areaOptions = [];
        while ($areaData = $this->db->fetch()) {
            $fullPath = str_repeat('&nbsp; ', (int)$areaData['depth'] * 2) . $this->cms->textOrSnippet($areaData['label']);
            $areaId = (string)$areaData['area_id'];
            $areaOptions[$areaId] = $fullPath;
        }
        return $areaOptions;
    }

    // ====================
    // LookupField mit Template-Inhalten

    /**
     * Ajax: Vorschau eines Lookup-Feldes im EINGABEFORMULAR aktualisieren.
     *
     * Aufgerufen von cApp.ui.lookupRUpdate(), nachdem der Redakteur in der
     * Auswahlliste ein Häkchen gesetzt oder entfernt hat. Antwortet mit dem fertigen
     * Vorschaublock, den das Javascript an die Stelle des alten setzt.
     *
     * Der Unterschied zu sLookupPreview() ist allein das Formularpräfix: hier 'data'
     * (Eingabeformular), dort 'filter' (Suchmaske). Danach richten sich die Namen der
     * erzeugten versteckten Felder.
     *
     * Request: src_field (Pflicht), ids (Kommaliste), max, function, ordered (Hoch/Runter-Schaltflächen)
     */
    public function lookupPreview(): void
    {
        // durchgereichte Parameter aus cApp.ui.slookupUpdate()
        $function = (string)($_REQUEST['function'] ?? '');
        $srcField = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($_REQUEST['src_field'] ?? '')));
        $max = (int)($_REQUEST['max'] ?? 0);
        if (empty($srcField)) {
            $this->cms->error('lookupPreview() Exception: parameter src_field is empty.');
            $this->_editErrors();
            return;
        }
        $idString = preg_replace('/[^0-9,]/', '', (string)($_REQUEST['ids'] ?? ''));
        $ids = explode(',', $idString);
        $ordered = !empty($_REQUEST['ordered']);
        echo $this->lookupPreviewCreate($srcField, $ids, 'data', $max, $ordered);
        if ($function == 'link') {
            $this->backend->script(
                "cApp.ui.lookupFocus('$srcField', true);"
            );
        }
    }

    /**
     * Ajax: Vorschau eines Lookup-Feldes in der SUCHMASKE aktualisieren.
     *
     * Wie lookupPreview(), nur mit dem Formularpräfix 'filter' - die Auswahl ist hier
     * ein Suchkriterium und keine zu speichernde Verknüpfung. Entsprechend gibt es
     * auch kein Zurücksetzen des Eingabefokus.
     *
     * Request: src_field (Pflicht), ids (Kommaliste), max
     */
    public function sLookupPreview(): void
    {
        $srcField = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($_REQUEST['src_field'] ?? '')));
        $max = (int)($_REQUEST['max'] ?? 0);
        if (empty($srcField)) {
            $this->cms->error('sLookupPreview() Exception: parameter src_field is empty.');
            $this->_editErrors();
            return;
        }
        $idString = preg_replace('/[^0-9,]/', '', (string)($_REQUEST['ids'] ?? ''));
        $ids = explode(',', $idString);
        echo $this->lookupPreviewCreate($srcField, $ids, 'filter', $max);
    }

    /**
     * Den Vorschaublock eines Lookup-Feldes rendern.
     *
     * Gemeinsamer Kern von lookupPreview() und sLookupPreview(); wird außerdem direkt
     * aufgerufen, wenn ein Formular zum ersten Mal aufgebaut wird (z. B. aus
     * AreaBackend::_editForm()).
     *
     * Je ausgewähltem Template entsteht eine Zeile mit Namen, Entfernen-Verweis und
     * einem versteckten Feld, das die Id trägt - der Vorschaublock IST also das
     * Eingabefeld; ein sichtbares Textfeld gibt es nicht.
     *
     * Die Reihenfolge der Zeilen folgt der übergebenen Id-Liste, nicht der
     * Sortierung der Datenbank: der Redakteur hat sie so angelegt. Dafür wird die
     * Ausgangsreihenfolge mit array_flip() zu einem Rang und uksort() danach sortiert.
     * Bei max == 1 entfällt das - dort gibt es nur eine Zeile.
     *
     * $ordered: die Reihenfolge hat eine Bedeutung (Trigger-Templates eines Bereichs
     * laufen nacheinander) - jede Zeile bekommt Hoch/Runter-Schaltflächen, die zwei
     * Ids in der Liste tauschen (cApp.ui.slookupSwap()).
     *
     * @param string $srcField  Name des Lookup-Feldes
     * @param array $ids        Im Feld gespeicherte Ids
     * @param string $prefix    Name des Formularfeldes ('data'/'filter')
     * @param int $max          Weitergereichter Parameter $max aus JavaScript (nicht verbindlich!)
     * @param bool $ordered     Hoch/Runter-Schaltflächen
     */
    public function lookupPreviewCreate(string $srcField, array $ids, string $prefix, int $max, bool $ordered = false): string
    {
        if (empty($srcField)) return '';
        $ids = $this->system->sanitizeIds($ids, true);
        if (empty($ids)) return '';

        // Lade Datensätze - alle gewählten: was hier fehlt, fehlt nach dem
        // Speichern auch in der Zuweisung (die Vorschau trägt die Hiddenfelder)
        $this->template->select([
            'filter' => [
                'template_id' => $ids
            ],
            'limit' => count($ids)
        ]);
        if ($this->db->num_rows < 1) return '';
        
        $records = [];
        while ($row = $this->template->fetch()) {
            // Id im Schlüssel
            $records[(int)$row['template_id']] = $row;

            if ($max == 1) break;
        }

        if ($max != 1) {
            // Ergebnisse nach ursprünglicher Id-Reihenfolge sortieren
            $flippedOrder = array_flip($ids); 
            uksort($records, function($a, $b) use ($flippedOrder) {
                return (int)$flippedOrder[$a] <=> (int)$flippedOrder[$b];
            });
        }
        
        // Hidden-Feld mit gespeicherter Id
        $varName = "{$prefix}[$srcField]";
        // Mehrfachauswahl
        if ($max != 1) $varName .= '[]';

        $rows = [];
        $orderedIds = array_keys($records);
        foreach ($orderedIds as $index => $id) {
            $data = $records[$id];
            $buttons = '';
            if ($ordered) {
                // Tauschpartner unten / oben; am Rand unsichtbar (Spalten bleiben bündig)
                $nextId = (int)($orderedIds[$index + 1] ?? 0);
                $previousId = (int)($orderedIds[$index - 1] ?? 0);
                $buttons = $this->backend->template('record.lookup_button_down', [
                    '{down_url}' => "cApp.ui.slookupSwap($id, $nextId);",
                    '{class}' => $nextId > 0 ? '' : ' invisible',
                ]) . $this->backend->template('record.lookup_button_up', [
                    '{up_url}' => "cApp.ui.slookupSwap($id, $previousId);",
                    '{class}' => $previousId > 0 ? '' : ' invisible',
                ]);
            }
            $rows[] = $this->backend->template($ordered ? 'template.lookup_table_ordered_row' : 'template.lookup_table_row', [
                '{data_id}' => $id,
                '{text}' => $data['name'],
                '{name}' => $srcField,
                '{buttons}' => $buttons,
                '{unlink_url}' => "cApp.ui.slookupUnlink($id);",
                '{var_name}' => $varName
            ]);
            // (Hier stand ein popResult() ohne zugehöriges pushResult(): es holte den
            //  Ergebnis-Zustand des AUFRUFERS vom Stapel und ließ ihn dort verschwinden.
            //  Die Schleife läuft über das bereits eingesammelte $records-Array und
            //  setzt selbst keine Abfrage ab - es gibt hier nichts wiederherzustellen.)
        }

        // render table
        $table = $this->backend->template('template.lookup_table', [
            '{table_rows}' => implode("\n", $rows)
        ]);

        // render preview container
        $previewContainer = $this->backend->template('record.lookup_preview_container', [
            '{html}' => $table
        ]);

        return $previewContainer;
    }

    /**
     * Zuweisungen aus Sicht EINES BEREICHS laden - welche Templates gehören dazu.
     *
     * Für die Übersichtstabelle in AreaBackend::info(). LEFT JOIN, damit eine
     * verwaiste Zeile in rel_area_template nicht unter den Tisch fällt (dort blieben
     * name und label dann leer).
     */
    public function loadTemplateAssignment(int $areaId):array
    {
        $sql = "SELECT `template`.`name`, `template`.`label`, `r`.* FROM `rel_area_template` `r`"
        ." LEFT JOIN `template` ON `r`.`template_id` = `template`.`template_id`"
        ." WHERE `r`.`area_id` = ?"
        ." ORDER BY `template`.`name` ASC";
        $this->db->query($sql, 'i', [$areaId]);
        return $this->db->fetchAll();
    }

    /**
     * Zuweisungen aus Sicht EINES TEMPLATES laden - in welchen Bereichen ist es
     * benutzbar.
     *
     * Die Gegenrichtung zu loadTemplateAssignment(), gebraucht von infoAccess().
     * `default_template_id` des Bereichs kommt mit, weil sich "ist Standard-Template"
     * nur daraus ablesen lässt - in rel_area_template gibt es dafür keine Spalte.
     */
    public function loadAreaAssignment(int $templateId):array
    {
        $sql = "SELECT `area`.`label`, `area`.`default_template_id`, `r`.* FROM `rel_area_template` `r`"
        ." LEFT JOIN `area` ON `r`.`area_id` = `area`.`area_id`"
        ." WHERE `r`.`template_id` = ?"
        ." ORDER BY `area`.`lft` ASC";
        $this->db->query($sql, 'i', [$templateId]);
        return $this->db->fetchAll();
    }

    /**
     * Vom Session-Admin zuletzt geänderte Templates listen (rechte Spalte von
     * folderList()).
     *
     * Die Ids kommen aus `history` (Camindo\Core\History), jüngste zuerst; je
     * Template untereinander Name, Beschriftung und Bereich - die Spalten der
     * Template-Liste plus der Bereich, weil die Einträge aus verschiedenen Bereichen
     * stammen. Templates, deren Bereich die Benutzergruppe nicht (mehr) lesen darf,
     * fallen weg. Ein Klick öffnet die Detailansicht.
     */
    private function _lastChangedTemplates(): void
    {
        $entries = $GLOBALS['app']->get(History::class)->entries('template');
        if (empty($entries)) return;

        $this->db->pushResult();
        $this->cms->select([
            'filter' => ['template_id' => array_keys($entries)],
            'limit'  => count($entries)
        ], $this->template);
        $templates = [];
        while ($row = $this->template->fetch()) {
            $templates[(int)$row['template_id']] = $row;
        }
        $this->db->popResult();

        $access = $this->backend->access();
        $rows = '';
        foreach ($entries as $templateId => $changedAt) {
            $row = $templates[$templateId] ?? null;
            if ($row === null || !$access->templateRead((int)$row['area_id'])) continue;

            $items = [$this->iconTemplate . ' <strong>' . $row['name'] . '</strong>'];
            $label = $this->cms->textOrSnippet((string)$row['label']);
            if ($label !== '') $items[] = $label;
            $areaLabel = $this->cms->textOrSnippet((string)($this->backend->areaData((int)$row['area_id'])['label'] ?? ''));
            if ($areaLabel !== '') $items[] = $areaLabel;

            $text = '';
            foreach ($items as $i => $item) {
                $text .= ($i === 0)
                    ? '<div>' . $item . '</div>'
                    : $this->backend->template('record.lookup_table_row_div', ['{text}' => $item]);
            }
            $rows .= $this->backend->template('template.last_changed_row', [
                '{data_id}'    => $templateId,
                '{text}'       => $text,
                '{changed_at}' => $this->cms->_formatDate($this->cms->utcToUserTZ($changedAt)),
            ]) . "\n";
        }
        if ($rows === '') return;

        echo $this->backend->template('template.last_changed_table', [
            '{table_rows}' => $rows
        ]);
    }
}