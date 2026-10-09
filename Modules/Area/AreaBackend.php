<?php
/**
 * Backend-Oberfläche für die Inhaltsbereiche (Tabelle `area`).
 *
 * Controller-Schicht nach dem üblichen Muster (siehe docs/developers/03-backend-entwicklung.md):
 * die drei-Zeilen-Scripte in backend/ holen diese Klasse aus dem Container und rufen
 * genau eine der öffentlichen Methoden auf.
 *
 *     backend/area_list.php     -> list()      Baumliste aller Bereiche
 *     backend/area_info.php     -> info()      Detailansicht eines Bereichs
 *     backend/area_edit.php     -> edit()      anlegen / kopieren / ändern / löschen
 *     backend/area_search.php   -> search()    Suchmaske und Suchausführung
 *     backend/area_previous.php -> updatePreviousOptions()   Ajax-Teilantwort
 *     backend/area_parent.php   -> updateParentOptions()     Ajax-Teilantwort
 *
 * DIE LISTE IST EIN BAUM
 * --------------------
 * `Area` sortiert per Default nach `lft ASC` (Nested Set), die Zeilen kommen also bereits
 * in Anzeigereihenfolge. Die Einrückung entsteht allein aus `depth` * 20 Pixel - es gibt
 * keine verschachtelten <ul>, die Tabelle bleibt flach.
 *
 * MANDANTEN ALS HAUPTVERZEICHNISSE
 * --------------------
 * Sind Mandanten eingerichtet (InstanceCache::hasMultipleInstances()), führt jeder
 * seinen eigenen Baum: die Liste sortiert dann nach Instanz (Pseudo-Sortierfeld
 * 'instance' von Cms::select(), Reihenfolge des Backends) und setzt vor die erste
 * Zeile jeder Instanz eine Ordnerzeile mit ihrem Label (area_table_row_instance.html);
 * die Bereiche rücken eine Stufe ein. Sortiert der Redakteur nach einer Spalte,
 * entfällt die Gruppierung. Damit ein Teilbaum immer zu EINEM Mandanten gehört,
 * lässt Area::hookOnSave() als Vater nur einen Ordner derselben Instanz zu; das
 * Formular zeigt entsprechend nur Ordner und Geschwister der gewählten Instanz und
 * lädt beide Listen per Ajax neu, wenn die Instanz wechselt.
 *
 * KOPIEREN (function=copy)
 * --------------------
 * Eine eigene Maske mit Label, Name, Icon, Instanz, Eltern-Ordner, Vorgänger und
 * Hinweis (vorbelegt vom Original, der Vorgänger mit dem Original selbst - die Kopie
 * steht damit direkt dahinter); alle übrigen Einstellungen übernimmt die Kopie vom
 * Original - bei Datensatzbereichen samt Eingabefeldern (mit Thumbnails, Verweisen,
 * Volltexten), Default-/Sortier-/Anmeldefeld und Template-Zuweisungen. Der Weg führt
 * über das Exportformat: RecordExport::areaBlueprintFile() schreibt das Original wie
 * einen Bauplan, RecordImport::importBlueprint() legt die Felder im neuen Bereich an -
 * derselbe Code wie beim Anlegen aus einem Bauplan (beides in AreaService, den auch
 * die API nutzt). Inhalte (Datensätze, Templates)
 * werden nicht kopiert. (Das frühere "Kopieren" - das volle Formular mit den Werten
 * des Originals, ohne Felder - ist damit abgelöst.)
 *
 * DIE POSITION WIRD ÜBER "previous_id" EINGEGEBEN
 * --------------------
 * `previous_id` ist KEINE Datenbankspalte. Das Formular lässt den Redakteur den
 * VORGÄNGER-Bereich wählen; `Area` bzw. TreeManagerTrait rechnet daraus beim Speichern
 * die `position` aus. Umgekehrt muss _editForm() beim Laden aus der gespeicherten
 * `position` wieder den Vorgänger ermitteln (SQL: größte position unterhalb der
 * eigenen, gleicher Parent). Wechselt der Redakteur im Formular den Parent, ist die
 * Vorgängerliste hinfällig - dann lädt updatePreviousOptions() sie per Ajax neu.
 *
 * TEMPLATE-ZUWEISUNG
 * --------------------
 * Drei Felder im Formular schreiben in dieselbe Kantentabelle `rel_area_template`:
 *
 *     default_template_id    Spalte in `area`; Template, wenn der Datensatz keins wählt
 *     list_template_id       Spalte in `area`; Template für die Backend-Darstellung
 *                            (Listenspalte, ausführliche Lookup-Vorschau)
 *     trigger_template_ids   Tabelle `rel_area_trigger`; Ereignis-Methoden der
 *                            Datensätze, mehrere in Ausführungsreihenfolge (Lookup
 *                            mit Hoch/Runter-Schaltflächen)
 *     overview_template_id   Spalte in `area`; Übersicht unter der Präfix-Adresse
 *     selectable_templates   rel_area_template.selectable=1; im Datensatz wählbar
 *     override_templates     rel_area_template.override=1;   per URL erzwingbar
 *
 * Geschrieben wird die Kantentabelle nicht hier, sondern in Area::hookOnSaved() - die
 * beiden Lookup-Felder reisen als `data[selectable_templates]` / `data[override_templates]`
 * im Formular mit; _editSave() setzt sie leer, wenn nichts gewählt ist (nur
 * übergebene Listen werden ersetzt). Gelesen werden sie hier über
 * selectableTemplateIds() / overrideTemplateIds().
 *
 * LÖSCHEN
 * --------------------
 * Aus dem Backend heraus wird immer nur SOFT gelöscht (Papierkorb). Vor der
 * Bestätigung zählt _editDeleteForm() zusammen, was mit hängt - je nach Bereichstyp
 * Kind-Bereiche, Datensätze oder Templates - und formuliert daraus die Warnung.
 * Große Bereiche wandern in einen Hintergrundprozess (MassDeletion).
 *
 * @see Camindo\Core\Modules\Area\Area          Dataset/Model der Tabelle `area`
 * @see Camindo\Core\Modules\Area\FieldBackend  Backend der Eingabefelder eines Bereichs
 * @see Camindo\Core\Backend                      Formular-, Tabellen- und Layer-Helfer
 */
namespace Camindo\Core\Modules\Area;

use Camindo\Core\BackgroundProcess;
use Camindo\Core\Config;
use Camindo\Core\FulltextState;
use Camindo\Core\Backend;
use Camindo\Core\InstanceCache;
use Camindo\Core\Modules\Instance\Instance;
use Camindo\Core\Database;
use Camindo\Core\System;
use Camindo\Core\Cms;
use Camindo\Core\Crypt;
use Camindo\Core\MassDeletion;
use Camindo\Core\Modules\Area\Area;
use Camindo\Core\Modules\Area\Field;
use Camindo\Core\Modules\Record\UserSecondFactor;
use Camindo\Core\Modules\Template\TemplateBackend;

class AreaBackend {
    protected int $id = 0;
    protected ?array $data = [];
    protected string $function = '';
    /** Bauplan eines neuen Bereichs (Dateiname ohne .xml, siehe create()) */
    protected string $blueprint = '';

    public function __construct(
        private Backend $backend,
        private Database $db,
        private System $system,
        private Cms $cms,
        private Crypt $crypt,
        private MassDeletion $massDeletion,
        private Area $area,
        private Field $field,
        private TemplateBackend $templateBackend,
        private InstanceCache $instanceCache,
        private AreaService $service,
        private FulltextState $fulltextState,
        private BackgroundProcess $bgproc,
        private Config $config,
    )
    {}

    // ====================
    // Volltext veraltet (FulltextState)

    /**
     * Hinweis über der Detailansicht und der Feldliste eines Bereichs: der Volltext
     * passt nach einer Strukturänderung nicht mehr zu den Quellfeldern - mit dem
     * Button "Jetzt aktualisieren" (fulltext()). Läuft der Neuaufbau gerade, nur
     * dieser Hinweis.
     *
     * @param string $return  'info' | 'fields' - wohin der Button danach zurückführt
     */
    public function fulltextNotice(int $areaId, string $return = 'info'): string
    {
        if ($this->fulltextState->lockInfo($areaId) !== null) {
            return $this->backend->template('area.fulltext_running');
        }
        $since = $this->fulltextState->staleSince($areaId);
        if ($since === null) return '';
        $text = str_replace('{date}', $this->cms->_formatDate($this->cms->utcToUserTZ($since)),
            $this->cms->snippet('area.alert_fulltext_stale'));
        return $this->backend->template('area.fulltext_notice', [
            '{text}' => htmlspecialchars($text),
            '{onclick}' => "cApp.layers.replacePost('Area/area_fulltext.php?id={$areaId}&return={$return}');",
        ]);
    }

    /**
     * "Jetzt aktualisieren" (backend/area_fulltext.php): den Neuaufbau des Volltexts
     * als Hintergrundprozess starten (CLI-Befehl `fulltext`) - es sei denn, er läuft
     * schon (Sperre von FulltextState). Danach zurück zur Detailansicht bzw. Feldliste.
     */
    public function fulltext(): void
    {
        $id = (int)($_REQUEST['id'] ?? 0);
        $return = (string)($_REQUEST['return'] ?? '') === 'fields'
            ? "Area/field_list.php?area_id={$id}" : "Area/area_info.php?id={$id}";
        echo $this->backend->template('container_header');
        // Startet einen Hintergrundprozess - nur per POST
        if (!$this->backend->requirePost()) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }
        if (!$this->area->load($id)) {
            echo $this->backend->template('alert_danger', ['{text}' => $this->cms->errorMsg()]);
            echo $this->backend->template('container_footer');
            return;
        }
        if ($this->fulltextState->lockInfo($id) !== null) {
            $this->backend->balloon('info', $this->cms->snippet('area.message_fulltext_running'));
        }
        else {
            $error = '';
            $command = [(string)$this->config->get('paths.php'), $this->config->get('paths.core_dir') . '/cron/camindo.php',
                'fulltext', '--area=' . $id];
            $token = bin2hex(random_bytes(8));
            $command[] = '--token=' . $token;
            if ($this->bgproc->start($command, 'fulltext', $token, null, null, $error)) {
                $this->backend->balloon('success', $this->cms->snippet('area.message_fulltext_started'));
            }
            else {
                $this->backend->balloon('danger', $this->cms->snippet('area.message_fulltext_start_failed') . ' ' . htmlspecialchars($error));
            }
        }
        $this->backend->script("cApp.layers.replace('{$return}');");
        echo $this->backend->template('container_footer');
    }

    /**
     * Baumliste aller Bereiche (backend/area_list.php).
     *
     * 'request' => 'all' lässt Cms::select() Filter, Sortierung und Seite aus dem
     * Request übernehmen - deshalb funktionieren Suchmaske und Spalten-Sortierlinks
     * ohne eigenes Zutun. Die Baumdarstellung kostet hier nichts: Area sortiert per
     * Default nach `lft ASC`, die Einrückung ist ein Pixel-Padding aus `depth`.
     */
    public function list()
    {
        // Wenn es mehrere Instanzen gibt, dann dieses anzeigen
        $this->db->query("SELECT `instance_id`,`label` FROM `instance` ORDER BY " . Instance::orderSql());
        $instances = $this->db->fetchPairs();
        // Mandanten als Hauptverzeichnisse (siehe Klassenkommentar) - nur in der
        // Baumsortierung; eine Spaltensortierung aus dem Request hebt sie auf
        $grouped = $this->instanceCache->hasMultipleInstances() && empty($_REQUEST['sort']);

        // Seitenelemente
        // --------------------

        // Bread crum navi
        $this->backend->setNaviLabel('#area.module_label');

        // Container header
        echo $this->backend->template('container_header');

        // H1
        echo $this->backend->headline('#area.headline_area_list');
        
        // Buttons
        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_create_primary', "cApp.layers.add('Area/area_create.php');");
        $this->backend->buttonAdd('button_search', "cApp.layers.add('Area/area_search.php?' + cApp.layers.queryString());");
        $this->backend->buttonAdd('button_refresh', "cApp.layers.refresh();");
        echo $this->backend->buttonBar();
        
        // Area list
        // --------------------
        $param = [
            'request' => 'all',
            'limit' => -1,
            'count' => 'true',
            'filtertext' => 'true',
        ];
        if ($grouped) {
            $param['sort']  = ['instance', 'lft'];
            $param['order'] = ['ASC', 'ASC'];
        }
        $f = $this->cms->select($param, $this->area);

        // Suchbegriffe oder geänderte Sortierung als lesbarer Text
        echo $this->backend->filterText($f);

        if ($this->db->num_rows > 0) {

            // Prepare Table-Head
            $schema = $this->area->getSchema();
            $thLabel = $this->backend->tableHeadColumn($schema['label'], $f, 'label');
            $thName = $this->backend->tableHeadColumn($schema['name'], $f, 'name');
            $thInstance = $this->backend->tableHeadColumn($schema['instance_id'], $f, 'instance_id');

            $headColumns = $this->backend->template(
                $instances ? 'area.area_table_head_columns_instances' : 'area.area_table_head_columns', [
                '{label_label}' => $thLabel,
                '{label_name}' => $thName,
                '{label_instance}' => $thInstance,
                '{label_area_id}' => 'area_id'
            ]);

            // Collect table rows
            $tplTableRow = $this->backend->template(
                $instances ? 'area.area_table_row_instances' : 'area.area_table_row'
            );
            $tableRows = '';
            $instanceLabels = $this->instanceCache->instanceLabels();
            $currentInstanceId = -1;

            // Hole Ergebnisse (Dataset::fetch: mit Datennachbeareitung)
            while ($data = $this->area->fetch()) {

                // Mandant gewechselt -> Ordnerzeile mit seinem Label
                if ($grouped && (int)$data['instance_id'] !== $currentInstanceId) {
                    $currentInstanceId = (int)$data['instance_id'];
                    $tableRows .= $this->backend->template('area.area_table_row_instance', [
                        '{label}' => htmlspecialchars($this->cms->textOrSnippet((string)($instanceLabels[$currentInstanceId] ?? $instances[$currentInstanceId] ?? ''))),
                        '{instance_id}' => $currentInstanceId,
                    ]) . "\n";
                }

                $icon = $this->backend->template('icon_large', [
                    '{name}' => $data['icon'] ?: 'draft'
                ]);
                // depth ist 0-basiert - die Grundeinrückung der obersten Ebene bleibt
                // erhalten; unter einem Mandanten-Verzeichnis eine Stufe mehr
                $paddingLeft = ((int)$data['depth'] + ($grouped ? 1 : 0)) * 20 + 20;

                $label = $icon . ' ' . $this->cms->textOrSnippet($data['label'] ?? '');
                if ((int)$data['dependent'] === 1) {
                    $label = '<span class="c9-grey-30">' . $label . '</span>';
                }
                // Icon + Label | Name | area_id ( | Instanz)
                $tableRow = strtr($tplTableRow, [
                    '{padding_left}' => $paddingLeft,
                    '{data_label}' => $label,
                    '{data_name}' => $data['name'],
                    '{data_area_id}' => $data['area_id'],
                    '{data_instance}' => $instances[(int) $data['instance_id']] ?? '',
                    '{data_id}' => $data['area_id']

                ]) ."\n";

                $tableRows .= $tableRow;
            }

            // Render table
            echo $this->backend->template('area.area_table_with_head', [
                '{head_columns}' => $headColumns,
                '{table_rows}' => $tableRows
            ]);

            // Context menu
            echo $this->backend->template('area.area_context_menu');
        }

        // Container Footer
        echo $this->backend->template('container_footer');
    }

    /**
     * Detailansicht eines Bereichs (backend/area_info.php).
     *
     * Welche Felder gezeigt werden, hängt am Typ: ein 'folder' hat weder Notiz noch
     * Default-Feld, ein 'template'-Bereich bekommt statt des Datensatz-Tabs den
     * Template-Tab.
     *
     * Unten steht die Übersicht der Template-Zuweisungen aus `rel_area_template`
     * (default / selectable / override als Häkchen-Spalten).
     */
    public function info()
    {
        $id = (int)($_REQUEST['id'] ?? 0);

        // Seitenelemente
        // --------------------

        // Container header
        echo $this->backend->template('container_header');

        // Load data
        if (!$data = $this->area->load($id)) {
            echo $this->backend->template('alert_danger', [
                '{text}' => $this->cms->errorMsg()
            ]);
            echo $this->backend->template('container_footer');
            return;
        }
        $type = $data['type'];

        // Bread crum navi
        $this->backend->setNaviLabel($data['label']); // wertet auch snippet aus

        // H1
        $icon = $this->backend->template('icon', [
            '{name}' => $data['icon'] ?: 'draft'
        ]);
        echo $this->backend->headline($icon . ' ' . $this->cms->textOrSnippet($data['label']));
        
        // Tabs ("Änderungen" = Audit-Log des Bereichs, Backend::auditTabAdd())
        $auditActive = $this->backend->auditTabActive();
        $this->backend->tabBarReset();
        $this->backend->tabAdd('#area.headline_settings', $auditActive ? "cApp.layers.replace('Area/area_info.php?id=$id')" : '', !$auditActive);
        if (in_array($data['type'], ['record','profile','ugc'], true)) {
            $this->backend->tabAdd('#area.headline_field_list', "cApp.layers.replace('Area/field_list.php?area_id=$id')");
        }
        if ($type !== 'folder') {
            $this->backend->tabAdd('#area.headline_access', "cApp.layers.replace('Area/area_access.php?id=$id')");
        }
        // (Reiter in andere Module nur, wenn die Gruppe dort Zugriff hat)
        if ($data['type'] == 'template' && $this->backend->access()->module('Template')) {
            $this->backend->tabAdd('#area.headline_templates', "cApp.layers.replace('Template/template_list.php?area_id=$id')");
        }
        elseif (Area::isContentType($type) && $this->backend->access()->module('Record')) {
            $this->backend->tabAdd('#area.headline_records', "cApp.layers.replace('Record/record_list.php?area_id=$id')");
        }
        $this->backend->auditTabAdd("Area/area_info.php?id=$id");
        echo $this->backend->tabBar();

        // Buttons
        $this->backend->buttonBarReset();
        // Bearbeiten/Kopieren als eigener Layer über der Info-Seite: Abbrechen und
        // Speichern (invalidate(null, 2) + close()) führen auf sie zurück
        $this->backend->buttonAdd('button_edit_primary', "cApp.layers.add('Area/area_edit.php?id={$id}')");
        $this->backend->buttonAdd('button_copy', "cApp.layers.add('Area/area_edit.php?id={$id}&function=copy')");
        $this->backend->buttonAdd('button_delete', "cApp.layers.replace('Area/area_edit.php?id={$id}&function=delete')");
        // Vorschau der Übersicht (Übersichts-Template + Slug-Präfix)
        $this->backend->buttonAddAreaPreview($id);
        if (!empty($_REQUEST['back'])) {
            $this->backend->buttonAdd('button_back', "history.back()");
        }
        else {
            $this->backend->buttonAdd('button_close', "cApp.layers.close()");
        }
        echo $this->backend->buttonBar();

        if ($auditActive) {
            echo $this->backend->auditHistory('area', $id);
            echo $this->backend->template('container_footer');
            return;
        }

        echo $this->fulltextNotice($id);

        // label, name, area_id, instance_id
        // ---------------
        $this->backend->infoAdd($this->area, 'label', $data['label'], ['primary']);
        $this->backend->infoAdd($this->area, 'name', $data['name'], ['monospace']);
        $this->backend->infoAdd($this->area, 'icon', $data['icon']);
        $this->backend->infoAdd($this->area, 'area_id', $data['area_id']);
        $this->backend->infoAdd($this->area, 'instance_id',
            $this->backend->teaser('instance', (int)$data['instance_id'])
            .' &middot; instance_id : ' . $data['instance_id'],
            ['html']
        );

        // note
        // --------------------
        if ($type !== 'folder') {
            $this->backend->infoAdd($this->area, 'note', $this->cms->textOrSnippet((string)$data['note'])); // wie label: auch ein Snippet (#custom.…)
        }

        // sort_field_id
        // sort_order
        // --------------------
        if (Area::isContentType($type)) {
            $sortFieldId = (int)$data['sort_field_id'];
            if (!empty($sortFieldId)) {
                // ist FK, liefert immer Ergebnis
                $this->db->query("SELECT `name` FROM `field` WHERE `field_id` = ?", 'i', [$sortFieldId]);
                $sortFieldName = $this->db->fetchNum()[0];
            }
            else {
                $sortFieldName = 'changed_at';            
            }
            $sortOrder = $data['sort_order'] ?: 'DESC';
            $this->backend->infoStatic($this->cms->snippet('area.default_sort'), $sortFieldName . ' ' . $sortOrder);
        }
        
        // details.slug_prefix (je Sprache, aufgelöst wie in der URL)
        // --------------------
        if (Area::isContentType($type)) {
            $items = [];
            $languages = $this->instanceCache->languages((int)$data['instance_id']);
            foreach ($languages as $languageId => $language) {
                $prefix = $this->instanceCache->slugPrefix($id, (int)$languageId);
                if ($prefix === '') continue;
                $items[] = htmlspecialchars($prefix . '/') . (count($languages) > 1 ? $this->backend->template('area.slug_prefix_lang', ['{code}' => $this->backend->escape((string)$language['code'])]) : '');
            }
            $html = empty($items) ? '' : implode('<br>', $items);
            $this->backend->infoAdd($this->area, 'details.slug_prefix', $html, ['html', 'monospace']);
        }

        // dependent
        // --------------------
        if (Area::isContentType($type)) {
            $this->backend->infoAdd($this->area, 'dependent',
                !empty($data['dependent']) ? $this->cms->snippet('yes') : $this->cms->snippet('no'));
        }

        // Lösch-Policy (Modul Privacy)
        // --------------------
        if (Area::isContentType($type)) {
            $policyId = (int)($data['privacy_policy_id'] ?? 0);
            $policies = $GLOBALS['app']->get(\Camindo\Core\Modules\Privacy\DeletePolicy::class);
            $policyLabel = $policyId > 0 ? (string)($policies->optionsFor((int)$data['instance_id'])[$policyId] ?? '#' . $policyId) : '';
            $this->backend->infoAdd($this->area, 'privacy_policy_id', $policyLabel);
        }

        // default_field_id
        // --------------------
        if (Area::isContentType($type)) {
            $defaultFieldId = (int)$data['default_field_id'];
            if (!empty($defaultFieldId)) {
                $defaultFieldHtml = $this->backend->teaser('field', $defaultFieldId);
            }
            else {
                $defaultFieldHtml = 'record_id';
            }
            $this->backend->infoAdd($this->area, 'default_field_id', $defaultFieldHtml, ['html']);
        }

        // default_template_id
        // --------------------
        if (!empty($data['default_template_id'])) {
            $templateLabel = $this->backend->teaser('template', (int)$data['default_template_id']);
            $flags = ['html'];
        }
        else {
            $templateLabel = '';
            $flags = [];
        }
        $this->backend->infoAdd($this->area, 'default_template_id', $templateLabel, $flags);

        // list_template_id
        // --------------------
        if (Area::isContentType($type)) {
            $listTemplateId = (int)($data['list_template_id'] ?? 0);
            $html = $listTemplateId > 0 ? $this->backend->teaser('template', $listTemplateId) : '';
            $this->backend->infoAdd($this->area, 'list_template_id', $html, $html !== '' ? ['html'] : []);

            // Trigger-Templates in Ausführungsreihenfolge, je Zeile eins
            $teasers = array_map(fn($id) => $this->backend->teaser('template', (int)$id), (array)($data['trigger_template_ids'] ?? []));
            $html = implode('<br>', $teasers);
            $this->backend->infoAdd($this->area, 'trigger_template_ids', $html, $html !== '' ? ['html'] : []);

            $overviewTemplateId = (int)($data['overview_template_id'] ?? 0);
            $html = $overviewTemplateId > 0 ? $this->backend->teaser('template', $overviewTemplateId) : '';
            $this->backend->infoAdd($this->area, 'overview_template_id', $html, $html !== '' ? ['html'] : []);
        }

        // Anmeldung an der Website (type 'profile')
        // --------------------
        if ($type === 'profile') {
            foreach (['login_name_field_id', 'login_password_field_id'] as $key) {
                $fieldId = (int)($data[$key] ?? 0);
                $html = $fieldId > 0 ? $this->backend->teaser('field', $fieldId) : '';
                $this->backend->infoAdd($this->area, $key, $html, $html !== '' ? ['html'] : []);
            }
            $policy = (string)($data['details']['second_factor'] ?? 'none');
            $policy = in_array($policy, UserSecondFactor::POLICIES, true) ? $policy : 'none';
            $this->backend->infoAdd($this->area, 'details.second_factor', $this->cms->snippet('area.option_second_factor_' . $policy));
        }

        // Profil-Bereiche (type 'ugc')
        // --------------------
        if ($type === 'ugc') {
            $items = [];
            foreach ($this->system->sanitizeIds((array)($data['details']['profile_area_ids'] ?? []), true) as $profileAreaId) {
                $items[] = $this->backend->teaser('area', (int)$profileAreaId);
            }
            $html = empty($items) ? '' : $this->backend->template('ul', [
                '{items}' => '<li>' . implode("</li>\n<li>", $items) . '</li>'
            ]);
            $this->backend->infoAdd($this->area, 'details.profile_area_ids', $html, $html !== '' ? ['html'] : []);
        }

        echo $this->backend->infoTable();

        // Template-Zuweisung
        // --------------------
        $checked = $this->backend->template('icon_checked');
        $notChecked = $this->backend->emptyPlaceholder;

        $assignments = $this->templateBackend->loadTemplateAssignment($id);
        if (count($assignments) > 0) {
            echo $this->backend->template('headline4_mt', [
                '{text}' => '{#area.headline_templates}'
            ]);
            foreach($assignments as $assignment) {
                // default
                $isDefault = (int)$assignment['template_id'] === (int)$data['default_template_id'];
                // selectable
                $isSelectable = (int)$assignment['selectable'];
                // url-override
                $isOverride = (int)$assignment['override'];
                // linked template label
                $link = $this->backend->teaser('template', (int)$assignment['template_id']);
                // create row
                $rows[] = $this->backend->template('area.template_row', [
                    '{label}' => $link,
                    '{name}' => $assignment['name'],
                    '{default}' => $isDefault ? $checked : $notChecked,
                    '{selectable}' => $isSelectable ? $checked : $notChecked,
                    '{override}' => $isOverride ? $checked : $notChecked,
                ]);
            }
            echo $this->backend->template('area.template_table', [
                '{table_rows}' => implode("\n", $rows),
            ]);
        }

        echo $this->backend->template('container_footer');
    }

    /**
     * Ajax: Optionen des Select-Feldes "previous_id" neu liefern.
     *
     * Wird aufgerufen, sobald der Redakteur im Formular den übergeordneten Ordner
     * wechselt - die bisherige Vorgängerliste gehört dann zu den falschen
     * Geschwistern. Die Antwort besteht absichtlich nur aus <option>-Zeilen, das
     * Javascript ersetzt damit den Inhalt des vorhandenen <select>.
     *
     * Vorausgewählt wird immer der LETZTE Eintrag, der neue Bereich landet also ans
     * Ende seiner neuen Geschwister - dieselbe Vorgabe wie in Area::hookOnCreate().
     *
     * Request: id = eigener Bereich (aus der Liste auszuschließen),
     *          data[parent_id] = neu gewählter Ordner
     */
    /**
     * Reiter "Zugriff" (backend/area_access.php): welche Benutzergruppen den Bereich
     * lesen dürfen und was sie mit seinen Datensätzen tun dürfen (rel_group_area) -
     * alle Gruppen, ein Bereich. Die andere Sicht (alle Bereiche, eine Gruppe) hat
     * die Gruppe selbst (GroupBackend::access()).
     *
     *     ''        Tabelle mit Häkchen, Button "Bearbeiten" (nur Superuser)
     *     edit      Checkboxen; "Hinzufügen": Dropdown der Gruppen ohne Zeile, deren
     *               Instanzliste den Bereich enthält - die Auswahl schickt das
     *               Formular mit function=update
     *     update    Formular mit der neuen Zeile (volle Rechte außer set_owner)
     *     save      Area::saveAccess(), danach die Tabelle
     *
     * Bearbeiten und Speichern nur für Superuser (Rechte vergeben = Verwaltung).
     */
    public function infoAccess(): void
    {
        $id = (int)($_REQUEST['id'] ?? 0);
        $function = (string)($_REQUEST['function'] ?? '');

        $this->backend->setNaviLabel('#area.headline_access');
        echo $this->backend->template('container_header');

        if (!$data = $this->area->load($id)) {
            echo $this->backend->template('alert_danger', ['{text}' => $this->cms->errorMsg()]);
            echo $this->backend->template('container_footer');
            return;
        }
        $type = (string)$data['type'];
        $instanceId = (int)$data['instance_id'];
        $isTemplate = ($type === 'template');
        $canEdit = $this->backend->isSuperuser();
        if ($type === 'folder' || (!$canEdit && $function !== '')) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }

        // H1 und Reiter wie in info()
        $icon = $this->backend->template('icon', ['{name}' => $data['icon'] ?: 'draft']);
        echo $this->backend->headline($icon . ' ' . $data['label']);
        $this->backend->tabBarReset();
        $this->backend->tabAdd('#area.headline_settings', "cApp.layers.replace('Area/area_info.php?id=$id')");
        if (Area::isContentType($type)) {
            $this->backend->tabAdd('#area.headline_field_list', "cApp.layers.replace('Area/field_list.php?area_id=$id')");
        }
        $this->backend->tabAdd('#area.headline_access', '', true);
        if ($isTemplate && $this->backend->access()->module('Template')) {
            $this->backend->tabAdd('#area.headline_templates', "cApp.layers.replace('Template/template_list.php?area_id=$id')");
        }
        elseif (Area::isContentType($type) && $this->backend->access()->module('Record')) {
            $this->backend->tabAdd('#area.headline_records', "cApp.layers.replace('Record/record_list.php?area_id=$id')");
        }
        $this->backend->auditTabAdd("Area/area_info.php?id=$id");
        echo $this->backend->tabBar();

        // Speichern
        // --------------------
        if ($function === 'save') {
            if ($this->area->saveAccess($id, (array)($_REQUEST['data']['access'] ?? []))) {
                $this->backend->balloon('success', $this->cms->snippet('area.message_access_saved'));
                $function = '';
            }
            else {
                echo $this->backend->template('alert_danger', ['{text}' => $this->cms->errorMsg()]);
                $function = 'update';
            }
        }

        // Zeilen: gespeichert (Anzeige) bzw. aus dem Formular (edit/update)
        // --------------------
        $candidates = $this->area->accessCandidates($instanceId);
        $groupLabels = $this->backend->getGroups();
        if ($function === '') {
            $rows = $this->area->accessRows($id);
        }
        else {
            $rows = [];
            if ($function === 'edit') {
                $rows = $this->area->accessRows($id);
            }
            else {
                foreach ((array)($_REQUEST['data']['access'] ?? []) as $groupId => $flags) {
                    $flags = (array)$flags;
                    if (empty($flags['read'])) continue;
                    $rows[(int)$groupId] = [
                        'group_write'  => (int)!empty($flags['group_write']),
                        'group_delete' => (int)!empty($flags['group_delete']),
                        'other_write'  => (int)!empty($flags['other_write']),
                        'other_delete' => (int)!empty($flags['other_delete']),
                        'set_owner'    => (int)!empty($flags['set_owner']),
                    ];
                }
                $addGroupId = (int)($_REQUEST['data']['add_group_id'] ?? 0);
                if ($addGroupId > 0 && in_array($addGroupId, $candidates, true) && !isset($rows[$addGroupId])) {
                    $rows[$addGroupId] = ['group_write' => 1, 'group_delete' => 1, 'other_write' => 1, 'other_delete' => 1, 'set_owner' => 0];
                }
            }
            // nur Gruppen, die für den Bereich in Frage kommen
            $rows = array_intersect_key($rows, array_flip($candidates));
        }
        // Reihenfolge wie getGroups() (Label)
        $ordered = [];
        foreach ($groupLabels as $groupId => $label) {
            if (isset($rows[$groupId])) $ordered[$groupId] = $rows[$groupId];
        }
        $rows = $ordered;
        $edit = ($function !== '');

        // Tabelle
        // --------------------
        $checked = $this->backend->template('icon_checked');
        $empty = $this->backend->emptyPlaceholder;
        $tableRows = '';
        foreach ($rows as $groupId => $flags) {
            if ($edit) {
                $cell = fn(string $key) => $this->backend->template('admin.group_area_checkbox', [
                    '{name}' => "data[access][{$groupId}][{$key}]",
                    '{class}' => ' c9-access-dep',
                    '{checked}' => !empty($flags[$key]) ? ' checked' : '',
                    '{disabled}' => '',
                    '{onchange}' => '',
                ]);
                $read = $this->backend->template('admin.group_area_checkbox', [
                    '{name}' => "data[access][{$groupId}][read]",
                    '{class}' => '',
                    '{checked}' => ' checked',
                    '{disabled}' => '',
                    '{onchange}' => $this->backend->on('change', "$(this).closest('tr').find('.c9-access-dep').prop('disabled', !this.checked);"),
                ]);
            }
            else {
                $cell = fn(string $key) => !empty($flags[$key]) ? $checked : $empty;
                $read = $checked;
            }
            $tableRows .= $this->backend->template('admin.group_area_row', [
                '{class}' => '',
                '{area}' => $this->backend->teaser('group', (int)$groupId, linked: !$edit),
                '{read}' => $read,
                '{group_write}' => $cell('group_write'),
                '{group_delete}' => $isTemplate ? '' : $cell('group_delete'),
                '{other_write}' => $isTemplate ? '' : $cell('other_write'),
                '{other_delete}' => $isTemplate ? '' : $cell('other_delete'),
                '{set_owner}' => $isTemplate ? '' : $cell('set_owner'),
            ]);
        }
        $table = ($tableRows !== '')
            ? $this->backend->template('admin.group_area_table', [
                '{instance}' => '',
                '{first_column}' => $this->cms->snippet('area.access_group'),
                '{table_rows}' => $tableRows,
            ])
            : $this->backend->template('p', ['{text}' => $this->cms->snippet('area.note_no_access_yet')]);

        // Anzeige
        // --------------------
        if (!$edit) {
            $this->backend->buttonBarReset();
            if ($canEdit) {
                $this->backend->buttonAdd('button_edit_primary', "cApp.layers.replace('Area/area_access.php?id={$id}&function=edit');");
            }
            $this->backend->buttonAdd('button_close', "cApp.layers.close()");
            echo $this->backend->buttonBar();
            echo $table;
            echo $this->backend->template('p', ['{text}' => $this->cms->snippet('area.note_access')]);
            echo $this->backend->template('container_footer');
            return;
        }

        // Formular: Hinzufügen-Dropdown, Tabelle, Speichern/Abbrechen
        // --------------------
        echo '<form action="Area/area_access.php" method="post">';
        $this->backend->formInit('area.access_form');
        $addOptions = [];
        foreach ($groupLabels as $groupId => $label) {
            if (isset($rows[$groupId]) || !in_array((int)$groupId, $candidates, true)) continue;
            $addOptions[(string)$groupId] = $label;
        }
        if (!empty($addOptions)) {
            $this->backend->formField($this->area, [
                'name' => 'add_group_id',
                'value' => '',
                'colDef' => ['type' => 'id', 'display_type' => 'select', 'label' => '#area.button_add_group', 'tooltip' => '#area.tooltip_add_group'],
                'options' => $addOptions,
                'id' => $id,
                'attr' => ['onchange' => "if(this.value!=''){\$('.c9-layer:last input[name=\'function\']').val('update');\$('.c9-layer:last form').submit();}"],
                'selectPlease' => true
            ]);
        }
        else {
            $this->backend->formReplace('{data_add_group_id}', '');
        }
        $this->backend->formReplace('{access_table}', $table);
        $this->backend->formHidden('id', $id);
        $this->backend->formHidden('function', 'save');
        echo $this->backend->form('area.access_form_buttons', ['{area_id}' => $id]);
        echo '</form>';
        echo $this->backend->template('container_footer');
    }

    public function updatePreviousOptions(): void
    {
        $areaId = (int)($_REQUEST['id'] ?? 0);
        $parentId = (int)($_REQUEST['data']['parent_id'] ?? 0);
        $instanceId = (int)($_REQUEST['data']['instance_id'] ?? 0);

        $previousOptions = $this->_getPreviousOptions($parentId, $areaId, $instanceId);
        $lastKey = array_key_last($previousOptions);

        foreach ($previousOptions as $key => $val) {
            $selected = ($key === $lastKey) ? ' selected' : '';            
            echo "<option value=\"{$key}\"{$selected}>" . $this->cms->_strip_tags($val) . "</option>\n";
        }
    }

    /**
     * Ajax: Optionen des Select-Feldes "parent_id" neu liefern - wenn der Redakteur
     * im Formular die Instanz wechselt: als Vater kommen nur Ordner derselben
     * Instanz in Frage (Area::hookOnSave()). Vorausgewählt ist "nichts" (Wurzel);
     * die Vorgängerliste holt area_form_instance_select.html im selben Zug neu.
     *
     * Request: id = eigener Bereich, data[instance_id] = neu gewählte Instanz
     */
    public function updateParentOptions(): void
    {
        $areaId = (int)($_REQUEST['id'] ?? 0);
        $instanceId = (int)($_REQUEST['data']['instance_id'] ?? 0);

        foreach ($this->_getParentOptions($areaId, $instanceId) as $key => $val) {
            $selected = ($key === 0) ? ' selected' : '';
            echo "<option value=\"{$key}\"{$selected}>" . $this->cms->_strip_tags($val) . "</option>\n";
        }
    }

    /**
     * Schritt 1 beim Anlegen: Content-Typ auswählen (backend/area_create.php).
     *
     * Ein eigener Schritt, weil der Typ das gesamte Formular bestimmt und sich später
     * nicht mehr ändern lässt. Jeder Eintrag der Auswahl ist ein Link, der den Layer
     * durch area_edit.php mit function=create und dem gewählten Typ ersetzt.
     *
     * Baupläne: unter jedem Typ stehen zusätzlich die Dateien aus
     * blueprints/{type}/*.xml und var/area_blueprints/*.xml (Exportformat, ein
     * Bereich mit Einstellungen und Feldern, keine Datensätze; AreaService::
     * blueprints()) - je Datei ein Link (area_create_form_link.html)
     * mit &blueprint={Dateiname ohne .xml}. Beschriftung und Notiz sind <label> und
     * <note> des Bereichs der Datei (Snippet-Verweise erlaubt). Das Formular übernimmt
     * die Einstellungen der Datei (_editCreate()), nach dem Speichern legt
     * RecordImport::importBlueprint() die Felder an (_editSave()).
     */
    public function create():void {
        // Bread crum navi
        $this->backend->setNaviLabel('#button_create');
        
        // Container header
        echo $this->backend->template('container_header');

        echo '<form action="Area/area_edit.php" method="post">';

        // Formular
        $linkPrefix = "cApp.layers.replace('Area/area_edit.php?function=create";
        $linkSuffix = "');";
        $replace = [
            '{link_prefix}' => $linkPrefix,
            '{link_suffix}' => $linkSuffix
        ];
        foreach (AreaService::BLUEPRINT_TYPES as $type) {
            $links = '';
            foreach ($this->_blueprints($type) as $name => $area) {
                $links .= $this->backend->template('area.area_create_form_link', [
                    '{link_prefix}' => $linkPrefix,
                    '{link_suffix}' => $linkSuffix,
                    '{type}' => $type,
                    '{blueprint}' => $name,
                    '{headline}' => htmlspecialchars($this->cms->textOrSnippet((string)$area['label'])),
                    '{label}' => htmlspecialchars($this->cms->textOrSnippet((string)$area['label'])),
                    '{note}' => htmlspecialchars($this->cms->textOrSnippet((string)$area['note'])),
                ]);
            }
            $replace['{blueprints_' . $type . '}'] = $links;
        }
        $this->backend->formInit('area.area_create_form', $replace);
        echo $this->backend->form();

        echo '</form>';

        echo $this->backend->template('container_footer');
    }

    /**
     * Erstellen / kopieren / ändern / löschen (backend/area_edit.php).
     *
     * Zustandsautomat über $_REQUEST['function'] - der Ablauf ist in allen Modulen
     * gleich (docs/developers/03-backend-entwicklung.md):
     *
     *     create          leeres Formular mit Defaults aus Area::init()
     *     edit            Bereich laden und anzeigen (Default, wenn nichts gesetzt ist)
     *     copy            laden, Kopier-Maske zeigen - siehe Klassenkommentar
     *     copy_save       Kopie anlegen (Einstellungen, Felder, Zuweisungen); bei
     *                     Fehlern 'copy_errors': Maske erneut mit den Eingaben
     *     save            speichern; schlägt das fehl, wird auf 'errors' umgeschaltet
     *     errors          Formular erneut zeigen, mit Eingaben und Fehlermeldung
     *     skip            Bereich nicht ladbar - nur Fehlermeldung, kein Formular
     *     delete          Löschbestätigung anzeigen
     *     delete_confirm  Löschung ausführen
     *
     * Die Reihenfolge der if-Blöcke ist der Ablauf: erst Daten beschaffen (create /
     * load), dann Überschrift, dann die schreibende Aktion, dann das Formular. Weil
     * _editSave() bei Fehlern $this->function auf 'errors' setzt, fällt es
     * anschließend in den Formular-Zweig - ein Redirect ist nicht nötig.
     */
    public function edit(): void
    {
        // Seiten-Parameter
        $this->id = (int)($_REQUEST['id'] ?? 0);
        $this->function = (string) ($_REQUEST['function'] ?? '');
        if ($this->function == '') $this->function = 'edit';
        $this->data = (array) ($_REQUEST['data'] ?? []);
        $this->data['area_id'] = $this->id;
        // Bauplan (nur beim Anlegen; bleibt über 'errors' als Hiddenfeld erhalten)
        $this->blueprint = preg_replace('/[^a-z0-9_\-]/i', '', (string)($_REQUEST['blueprint'] ?? ''));

        // Bread crum navi (copy, copy_save, copy_errors: ein Eintrag)
        $naviFunction = str_starts_with($this->function, 'copy') ? 'copy' : $this->function;
        $this->backend->setNaviLabel('#area.menu_item_' . $naviFunction);

        // Container header
        echo $this->backend->template('container_header');

        // Create
        if ($this->function == 'create') {
            $this->_editCreate();
        }

        // Load
        if (in_array($this->function, ['edit', 'copy', 'delete', 'delete_confirm'], true)) {
            $this->_editLoad();
        }

        // H1
        echo $this->_editHeadline();

        // Save
        if ($this->function == 'save') {
            $this->_editSave();
        }

        // Copy
        if ($this->function == 'copy_save') {
            $this->_copySave();
        }

        // Delete confirm
        if ($this->function == 'delete_confirm') {
            $this->_editDeleteConfirm();
        }

        // Errors
        if (in_array($this->function, ['errors', 'skip'], true)) {
            $this->_editErrors();
        }

        // Edit form
        if (in_array($this->function, ['create', 'edit', 'update', 'errors'], true)) {
            $this->_editForm();
        }

        // Copy form (auch nach einem Fehler beim Kopieren)
        if (in_array($this->function, ['copy', 'copy_errors'], true)) {
            $this->_copyForm();
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
     * function=create: Formular mit den Schema-Defaults vorbelegen.
     */
    private function _editCreate(): void
    {
        // Seiten-Parameter dürfen Default-Werte überschreiben
        // (init() nimmt bereits gesetzte Werte aus $data unverändert mit -
        // so kann z. B. ein Aufruf mit ?data[parent_id]=5 den Ordner vorgeben)
        // Bauplan: dessen Einstellungen als Vorgabe - Label und Notiz bleiben leer,
        // die Feldbezüge (default_field_id …) entstehen erst mit den Feldern
        $defaults = $this->service->blueprintDefaults($this->_blueprintArea((string)($this->data['type'] ?? '')));
        if ($defaults) $this->data = array_merge($defaults, $this->data);
        $this->data = $this->area->init($this->data);
        $this->id = 0;
    }

    /** Baupläne eines Typs (AreaService::blueprints()) */
    private function _blueprints(string $type): array
    {
        return $this->service->blueprints($type);
    }

    /**
     * Der gewählte Bauplan ($this->blueprint) für den Typ - null, wenn keiner
     * gewählt ist, die Datei fehlt oder ihr Bereich einen anderen Typ hat.
     */
    private function _blueprintArea(string $type): ?array
    {
        return $this->blueprint === '' ? null : $this->service->blueprint($type, $this->blueprint);
    }

    /**
     * function=edit|copy|delete|delete_confirm: Bereich aus der Datenbank holen.
     */
    private function _editLoad(): void
    {
        $this->data = $this->area->load($this->id);
        if (!$this->data) {
            // Angeforderter Datensatz nicht gefunden
            $this->function = 'skip';
        }
    }

    /**
     * Das Original einer Kopie: beim Aufruf der Maske aus id, danach aus dem
     * Hiddenfeld source_id. (Die Maske führt bewusst KEIN Feld 'id': die Ajax-
     * Aufrufe für Vater- und Vorgängerliste schlössen den Bereich dieser Id aus -
     * das Original soll aber als Vorgänger wählbar bleiben.)
     */
    private function _copySource(): ?array
    {
        $sourceId = (int)($_REQUEST['source_id'] ?? 0) ?: $this->id;
        $source = ($sourceId > 0) ? $this->area->load($sourceId) : null;
        return $source ?: null;
    }

    /**
     * function=copy: die Kopier-Maske - Label, Name, Icon, Instanz, Eltern-Ordner,
     * Vorgänger und Hinweis; alles andere übernimmt die Kopie vom Original (siehe
     * Klassenkommentar). Vorbelegt mit den Werten des Originals, der Vorgänger mit
     * dem Original selbst; nach einem Fehler mit den Eingaben des Requests.
     */
    private function _copyForm(): void
    {
        $source = $this->_copySource();
        if (!$source) {
            $this->_editErrors();
            return;
        }
        $fromRequest = ($this->function === 'copy_errors');
        $input = $fromRequest ? (array)($_REQUEST['data'] ?? []) : [];
        $changed = $fromRequest ? ' data-changed="true"' : '';
        echo '<form action="Area/area_edit.php" method="post"' . $changed . '>';

        $type = (string)$source['type'];
        $this->backend->formInit('area.area_copy_form', [
            '{source_label}' => htmlspecialchars($this->cms->textOrSnippet((string)$source['label'])),
            '{source_name}' => htmlspecialchars((string)$source['name']),
        ]);
        $this->backend->formHidden('source_id', (int)$source['area_id']);
        $this->backend->formHidden('function', 'copy_save');
        $this->backend->formStatic($this->area, 'type', $type);

        // Vorbelegung: "{label} (Kopie)" und "{name}_copy" (name: höchstens 32 Zeichen)
        $copyLabel = (string)($input['label'] ?? '');
        if ($copyLabel === '') {
            $copyLabel = $this->cms->textOrSnippet((string)$source['label']) . ' ' . $this->cms->snippet('area.suffix_copy_label');
        }
        $copyName = (string)($input['name'] ?? '');
        if ($copyName === '') {
            $copyName = substr((string)$source['name'], 0, 32 - 5) . '_copy';
        }
        $this->backend->formField($this->area, ['name' => 'label', 'value' => $copyLabel, 'id' => 0, 'primary' => true]);
        $this->backend->formField($this->area, ['name' => 'name', 'value' => $copyName, 'id' => 0]);
        $this->backend->formField($this->area, ['name' => 'icon', 'value' => (string)($input['icon'] ?? $source['icon']), 'id' => 0]);

        // Instanz, Eltern-Ordner, Vorgänger - dieselben Felder und Ajax-Wege wie im
        // Bearbeitungsformular (_editForm()); Vater und Vorgänger je Instanz
        $instanceId = (int)($input['instance_id'] ?? $source['instance_id']);
        $parentId = (int)($input['parent_id'] ?? $source['parent_id'] ?? 0);
        $previousId = array_key_exists('previous_id', $input) ? (int)$input['previous_id'] : (int)$source['area_id'];
        $this->backend->formField($this->area, [
            'id' => 0,
            'name' => 'instance_id',
            'value' => $instanceId,
            'options' => $this->_getInstanceOptions(),
            'selectPlease' => false,
            'template' => 'area.area_form_instance_select'
        ]);
        $this->backend->formField($this->area, [
            'id' => 0,
            'name' => 'parent_id',
            'value' => $parentId,
            'display_type' => 'select',
            'options' => $this->_getParentOptions(0, $instanceId),
            'selectPlease' => false,
            'template' => 'area.area_form_parent_select'
        ]);
        $this->backend->formField($this->area, [
            'id' => 0,
            'name' => 'previous_id',
            'value' => $previousId,
            'colDef' => ['type' => 'integer', 'label' => '#area.field_area_previous_id'],
            'display_type' => 'select',
            'options' => $this->_getPreviousOptions($parentId, 0, $instanceId),
        ]);
        $this->backend->formField($this->area, [
            'id' => 0,
            'name' => 'note',
            'value' => (string)($input['note'] ?? $source['note'] ?? '')
        ]);

        // Der Rest: wird übernommen und kann danach geändert werden
        foreach (['default_field_id', 'sort_field_id', 'default_template_id'] as $key) {
            if ($key !== 'default_template_id' && !Area::isContentType($type)) continue;
            $this->backend->formStatic($this->area, $key, $this->cms->snippet('area.blueprint_note_later'));
        }

        // (Platzhalter der Felder, die der Typ nicht kennt, verschwinden)
        echo $this->backend->form('area.form_copy_run', [], true);
        echo '</form>';
    }

    /**
     * function=copy_save: die Kopie anlegen.
     *
     * 1. den Bereich speichern: Label, Name, Icon, Instanz, Vater, Vorgänger und
     *    Hinweis aus der Maske, Typ, Sortierrichtung und details vom Original
     * 2. Datensatzbereiche: die Felder des Originals in der Kopie anlegen und die
     *    Feldbezüge (Default-/Sortier-/Anmeldefeld) sowie Default-Template und
     *    Template-Zuweisungen setzen - über den Bauplan-Import
     *    (RecordImport::importBlueprint(), Quelle: RecordExport::areaBlueprintFile()).
     *    Verweise des Originals auf sich selbst zeigen in der Kopie auf die Kopie,
     *    Verweise auf andere Bereiche bleiben (dort entsteht je Relation ein neues
     *    Gegenfeld, wie beim Anlegen eines Verweisfeldes von Hand).
     *    Ordner und Template-Bereiche: nur Schritt 1 und die Template-Zuweisungen.
     *
     * Fehler in Schritt 1 (etwa ein vergebener Name) zeigen die Maske erneut; Fehler
     * in Schritt 2 lassen den Bereich bestehen - der Layer bleibt mit der Meldung
     * offen (wie beim Anlegen aus einem Bauplan).
     */
    private function _copySave(): void
    {
        $source = $this->_copySource();
        if (!$source) {
            $this->function = 'skip';
            return;
        }
        $input = array_intersect_key((array)($_REQUEST['data'] ?? []), array_flip(AreaService::COPY_FIELDS));
        $areaId = (int)$this->service->copy($source, $input);
        if ($areaId < 1) {
            $this->function = 'copy_errors';
            $this->_editErrors();
            return;
        }
        if (!empty($this->cms->errors)) {
            echo $this->backend->template('alert_danger', [
                '{text}' => $this->cms->snippet('area.message_blueprint_errors') . '<br>' . $this->cms->errorMsg()
            ]);
            $this->cms->errorsReset();
            $this->backend->buttonBarReset();
            $this->backend->buttonAdd('button_close', "cApp.layers.invalidate(null, 2); cApp.layers.close();");
            echo $this->backend->buttonBar();
            return;
        }

        $this->backend->balloon('success', $this->cms->snippet('area.message_area_copied'));
        // Weiter auf der Info-Seite der Kopie: kam die Maske von der Info-Seite des
        // Originals, ersetzt die Kopie diese (sonst führte "Zurück" auf das Original);
        // aus dem Kontextmenü der Liste liegt die Info-Seite direkt über der Liste.
        // Die Liste darunter neu laden (neuer Eintrag)
        $this->backend->script("
            const \$referer = $('article .c9-layer').eq(-2);
            if (\$referer.length && (\$referer.attr('data-url') || '').startsWith('Area/area_info.php')) cApp.layers.removeReferer();
            cApp.layers.invalidate(null, 1);
            cApp.layers.replace('Area/area_info.php?id={$areaId}', true);
        ");
    }

    /**
     * Überschrift des Formulars.
     *
     * Nur beim Löschen steht der Name des betroffenen Bereichs darüber - dort ist
     * er die eigentliche Information. Sonst genügt die generische Überschrift.
     */
    private function _editHeadline() {
        if ($this->function == 'delete') {
            $label = $this->data['label'] ?? '';
        }
        elseif (str_starts_with($this->function, 'copy')) {
            $label = $this->cms->snippet('area.headline_area_copy');
        }
        else {
            $label = $this->cms->snippet('area.headline_area_info');
        }
        return $this->backend->template('headline1', [
            '{text}' => $label
        ]);
    }

    /**
     * function=save: speichern.
     *
     * $fields leer bedeutet: alle Schema-Felder, die in $data stehen, werden
     * geschrieben. Validierung, Transaktion und Baumpflege stecken in Dataset::save()
     * bzw. in den Hooks von Area - hier bleibt nur die Rückmeldung an die Oberfläche.
     *
     * Erfolg schließt den Layer und lädt den darunterliegenden neu; Misserfolg
     * schaltet auf 'errors' um, edit() zeigt dann das Formular mit den Eingaben und
     * der Fehlermeldung aus Cms::errors erneut.
     */
    private function _editSave(): void
    {
        // Prüfe Daten und speichere - neu: mit Bauplan über AreaService
        $isNew = $this->id < 1;
        // Lookups ohne Einträge schicken nichts - "keins mehr" muss trotzdem
        // ankommen (Area::hookOnSaved() schreibt nur übergebene Listen)
        if (Area::isContentType((string)($this->data['type'] ?? ''))) {
            $this->data['trigger_template_ids'] ??= [];
            $this->data['selectable_templates'] ??= [];
            $this->data['override_templates'] ??= [];
        }
        $areaId = $isNew
            ? (int)$this->service->create($this->data, $this->blueprint)
            : (int)$this->area->save([], $this->data);
        if ($areaId > 0) {
            // Balloon Message
            $msg = $this->cms->snippet('area.message_area_saved');
            $this->backend->balloon('success', $msg);

            // Bauplan: Felder anlegen und verknüpfen. Fehler dabei lassen den
            // Bereich bestehen - der Layer bleibt dann mit der Meldung offen,
            // damit sie nicht in einem Balloon untergeht
            if ($isNew && $this->service->blueprintFailed()) {
                echo $this->backend->template('alert_danger', [
                    '{text}' => $this->cms->snippet('area.message_blueprint_errors') . '<br>' . $this->cms->errorMsg()
                ]);
                $this->cms->errorsReset();
                $this->backend->buttonBarReset();
                $this->backend->buttonAdd('button_close', "cApp.layers.invalidate(null, 2); cApp.layers.close();");
                echo $this->backend->buttonBar();
                return;
            }

            // Referer aktualisieren und Fenster schließen - beim Bearbeiten über die
            // Info-Seite auch die Liste darunter (neue Bezeichnung, Position)
            $this->backend->script("
                cApp.layers.invalidate(null, 2);
                cApp.layers.close();
            ");
        }
        else {
            $this->function = 'errors';
        }
    }

    /**
     * Löschung durchführen - immer nur SOFT.
     *
     * Endgültig gelöscht wird ausschließlich vom Systemtool nach Ablauf von
     * admin.trash_retention_days (Zeitplaner-Aufgabe "Papierkorb leeren").
     *
     * Der Bereich nimmt seine Datensätze mit (Area::hookOnSoftDelete), und zwar jeden
     * einzeln - deshalb kann der Vorgang bei großen Bereichen dauern. Ab
     * admin.mass_delete_threshold wandert er deswegen in einen Hintergrundprozess;
     * dort läuft derselbe Aufruf, nur außerhalb des Requests.
     *
     * $withChilds steuert, was mit den Unterbereichen passiert:
     *     true   der ganze Teilbaum wird mitmarkiert, der Baum bleibt unverändert
     *     false  die Kinder rücken eine Ebene hoch (Dataset::liftChildren())
     * Das Radio-Feld dafür steht in area_delete_with_option_form.html und kommt als
     * $_REQUEST['deletechilds'] zurück.
     */
    private function _editDeleteConfirm(): void
    {
        $withChilds = (string)($_REQUEST['deletechilds'] ?? 'false') === 'true';

        // Große Bereiche in den Hintergrund verlagern
        $count = $this->massDeletion->affectedCount('area', $this->id, $withChilds, 'delete');
        if ($this->massDeletion->needsBackground($count)) {
            $error = '';
            if ($this->massDeletion->start('area', $this->id, 'delete', $withChilds, $error)) {
                $this->backend->balloon('info', $this->cms->snippet('message_background_started'));
                $this->backend->script("
                    cApp.layers.invalidate();
                    cApp.layers.close();
                ");
                return;
            }
            // Start fehlgeschlagen -> im Request weitermachen statt gar nicht zu löschen
            $this->system->log('massdelete-errors', "area {$this->id}: {$error}");
        }

        if ($this->area->delete($this->id, $withChilds)) {
            // Balloon Message
            $msg = $this->cms->snippet('area.message_area_deleted');
            $this->backend->balloon('success', $msg);
            
            // Fenster schließen
            $this->backend->script("
                cApp.layers.invalidate();
                cApp.layers.close();
            ");
        }
        else {
            $this->_editErrors();
            $this->function = 'delete';
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
     * Der Aufbau folgt der Reihenfolge im Formular:
     *
     *  1. einfache Schema-Felder (type, name, icon, label)
     *  2. parent_id      - Auswahl unter den Ordnern, mit eigener Vorlage, die bei
     *                      Änderung updatePreviousOptions() per Ajax anstößt
     *  3. previous_id    - kein Datenbankfeld, sondern Eingabehilfe für `position`
     *  4. note
     *  5. default_field_id / sort_field_id / sort_order - Vorgaben für die Datensätze
     *     des Bereichs; die Auswahllisten enthalten nur Feldtypen, die als Titel bzw.
     *     als Sortierkriterium taugen
     *  6. default_template_id, selectable_templates, override_templates - drei
     *     Lookup-Felder auf `template`
     *
     * data-changed="true" am <form> setzt das Formular nach einem fehlgeschlagenen
     * Speichern als "verändert" - das Javascript fragt dann beim Schließen nach,
     * statt die Eingaben stillschweigend zu verwerfen.
     */
    private function _editForm(): void
    {
        if ($this->function == 'save') $changed = ' data-changed="true"';
        else $changed = '';
        echo '<form action="Area/area_edit.php" method="post"' . $changed . '>';

        $type = $this->data['type'];
        $this->backend->formInit('area.area_edit_form_' . $type);

        // Die Eingabemasken der 3 Typen sind unterschiedlich - daher wird 'type' im vorangehenden
        // Schritt bestimmt - siehe create()
        $this->backend->formStatic($this->area, 'type', $type);
        $this->backend->formHidden('data[type]', $type);
        if ($this->id < 1 && $this->blueprint !== '') $this->backend->formHidden('blueprint', $this->blueprint);

        // Label
        $this->backend->formField($this->area, [
            'name' => 'label',
            'value' => $this->data['label'] ?? '',
            'id' => $this->id,
            'primary' => true
        ]);

        // Data fields
        $dataFields = [
            'name',
            'icon',
        ];
        foreach ($dataFields as $key) {
            $this->backend->formField($this->area, [
                'name' => $key,
                'value' => $this->data[$key] ?? '',
                'id' => $this->id
            ]);
        }

        // instance_id
        // --------------------
        // Zu welcher Website gehört der Container? Gilt für alle drei Typen.
        // Die Datensätze bzw. Templates des Containers erben die Zuordnung
        // (siehe Area::hookOnSaved()).
        // Wechselt die Instanz, werden Vater- und Vorgängerliste per Ajax neu
        // geladen (area_form_instance_select.html) - der Baum ist je Mandant.
        $instanceId = (int)($this->data['instance_id'] ?? 0);
        $this->backend->formField($this->area, [
            'id' => $this->id,
            'name' => 'instance_id',
            'value' => $instanceId,
            'options' => $this->_getInstanceOptions(),
            'selectPlease' => false,
            'template' => 'area.area_form_instance_select'
        ]);

        // access_group_ids - nur beim Anlegen (A4, 12-group-rights.md): Gruppen, die
        // den Bereich lesen dürfen; vorgewählt sind die mit der gewählten Instanz
        // --------------------
        if ($this->id < 1 && $type !== 'folder') {
            $selected = ($this->function === 'create')
                ? $this->area->accessCandidates($instanceId)
                : array_map('intval', (array)($this->data['access_group_ids'] ?? []));
            $options = $this->backend->getGroups();
            unset($options[InstanceCache::SUPERUSER_GROUP_ID]);
            $this->backend->formField($this->area, [
                'name' => 'access_group_ids',
                'value' => $selected,
                'id' => $this->id,
                'options' => $options,
            ]);
            // damit "keine Gruppe" als Auswahl ankommt (ohne Häkchen fehlt das Feld im POST)
            $this->backend->formHidden('data[access_group_ids][]', 0);
        }

        // parent_id
        // --------------------
        // Ordner der Instanz listen (außer den Datensatz selbst)
        $areaId = (int)($this->id ?? 0);
        $parentOptions = $this->_getParentOptions($areaId, $instanceId);
        // spezielles Template: update on change
        $this->backend->formField($this->area, [
            'id' => $this->id,
            'name' => 'parent_id',
            'value' => $this->data['parent_id'] ?? '',
            'display_type' => 'select',
            'options' => $parentOptions,
            'selectPlease' => false,
            'template' => 'area.area_form_parent_select'
        ]);

        // previous_id - vorangehender Datensatz
        // --------------------
        // Kein Datenbank-Feld. Der Redakteur wählt den Vorgänger, gespeichert wird
        // daraus die `position`. Hier der Rückweg: aus der gespeicherten position
        // den Bereich suchen, der davor steht.
        $parentId = (int)($this->data['parent_id'] ?? 0);
        $positionOptions = $this->_getPreviousOptions($parentId, $areaId, $instanceId);
        $previousId = 0; // Standard: An den Anfang

        // Vorgänger = größte position, die noch kleiner ist als die eigene,
        // unter demselben Parent. Kein Treffer -> der Bereich steht selbst am Anfang,
        // $previousId bleibt 0.
        // Wurzel-Bereiche haben parent_id IS NULL, nicht 0 - deshalb der Sonderzweig.
        // Dort ist die position tabellenweit über alle Instanzen fortlaufend: ohne
        // Instanzfilter wäre der Vorgänger oft ein Bereich einer anderen Instanz,
        // den die Auswahl (_getPreviousOptions()) nicht enthält - angezeigt würde
        // dann "(an den Anfang)".
        if ($areaId > 0 && isset($this->data['position'])) {
            $currentPos = (int)$this->data['position'];
            $sql = "SELECT `area_id` FROM `area` WHERE `area_id` != ? AND `deleted`=0";
            $bindTypes = 'i';
            $values = [$areaId];

            if ($parentId === 0) {
                $sql .= " AND `parent_id` IS NULL";
                if ($instanceId > 0) {
                    $sql .= " AND `instance_id` = ?";
                    $bindTypes .= 'i';
                    $values[] = $instanceId;
                }
            } else {
                $sql .= " AND `parent_id` = ?";
                $bindTypes .= 'i';
                $values[] = $parentId;
            }

            $sql .= " AND `position` < ? ORDER BY `position` DESC LIMIT 1";
            $bindTypes .= 'i';
            $values[] = $currentPos;

            $this->db->query($sql, $bindTypes, $values);
            if ($row = $this->db->fetch()) {
                $previousId = (int)$row['area_id'];
            }
        } elseif ($this->function === 'create') {
            // Bei neuem Datensatz standardmäßig das Ende der Liste wählen
            $lastId = array_key_last($positionOptions);
            $previousId = $lastId !== null ? $lastId : 0;
        }

        $this->backend->formField($this->area, [
            'id' => $this->id,
            'name' => 'previous_id',
            'value' => $previousId,
            'colDef' => ['type' => 'integer', 'label' => '#area.field_area_previous_id'],
            'display_type' => 'select',
            'options' => $positionOptions,
        ]);

        $this->backend->formField($this->area, [
            'id' => $this->id,
            'name' => 'note',
            'value' => $this->data['note'] ?? ''
        ]);

        if (Area::isContentType($type)) {
            // default_field_id
            // --------------------
            // Feld aus dem Inhalt für kompakte Datensatzliste ausgelesen wird
            // (nur bestimmte Typen)
            if (!empty($this->blueprint)) {
                $this->backend->formStatic($this->area, 'default_field_id', $this->cms->snippet('area.blueprint_note_later'));
            }
            else {
                $defaultFieldTypes = array_merge(
                    $this->cms->textTypes,
                    $this->cms->numericTypes,
                    $this->cms->dateTypes,
                    ['encoded']
                );
                $this->cms->select([
                    'filter' => [
                        'area_id' => $this->id,
                        'type' => $defaultFieldTypes,
                    ],
                    // filtermode[type] = OR
                    'limit' => -1,
                    'sort' => 'position ASC'
                ], $this->field);

                $defaultFieldOptions = ['' => 'record_id'];
                while ($row = $this->db->fetch()) {
                    $label = strtr($this->cms->snippet('parenthetical_note'), [
                        '{text}' => $this->cms->textOrSnippet($row['label'] ?? '')
                    ]);
                    $defaultFieldOptions[(int)$row['field_id']] = $row['name'] . ' ' . $label;
                }

                $this->backend->formField($this->area, [
                    'id' => $this->id,
                    'name' => 'default_field_id',
                    'value' => $this->data['default_field_id'] ?? '',
                    'display_type' => 'select',
                    'options' => $defaultFieldOptions,
                ]);
            }
            
            // sort_field_id
            // --------------------
            // Feld für Standardsortierung im Bereich (nur bestimmte Typen)
            // Fallback: changed_at
            if (!empty($this->blueprint)) {
                $this->backend->formStatic($this->area, 'sort_field_id', $this->cms->snippet('area.blueprint_note_later'));
            }
            else {
                $sortFieldTypes = array_merge(
                    $this->cms->textTypes,
                    $this->cms->numericTypes,
                    $this->cms->dateTypes
                );
                $this->cms->select([
                    'filter' => [
                        'area_id' => $this->id,
                        'type' => $sortFieldTypes,
                    ],
                    // filtermode[type] = OR
                    'limit' => -1,
                    'sort' => 'position ASC'
                ], $this->field);

                $sortFieldOptions = [];
                $containsChangedAt = false;
                while ($row = $this->db->fetch()) {
                    $label = strtr($this->cms->snippet('parenthetical_note'), [
                        '{text}' => $this->cms->textOrSnippet($row['label'] ?? '')
                    ]);
                    $name = $row['name'];
                    $sortFieldOptions[(int)$row['field_id']] = $name . ' ' . $label;
                    if ($name === 'changed_at') $containsChangedAt = true;
                }
                if (!$containsChangedAt) {
                    $sortFieldOptions = ['' => 'changed_at'] + $sortFieldOptions;
                }

                $this->backend->formField($this->area, [
                    'id' => $this->id,
                    'name' => 'sort_field_id',
                    'value' => $this->data['sort_field_id'] ?? '',
                    'display_type' => 'select',
                    'options' => $sortFieldOptions,
                ]);

                // sort_order
                // --------------------
                // Richtung für Standardsortierung
                // Fallback: DESC
                $sortOrderOptions = [
                    'ASC' => $this->cms->snippet('label_sort_asc'),
                    'DESC' => $this->cms->snippet('label_sort_desc')
                ];
                $this->backend->formField($this->area, [
                    'id' => $this->id,
                    'name' => 'sort_order',
                    'value' => $this->data['sort_order'] ?? 'DESC',
                    'display_type' => 'radio',
                    'options' => $sortOrderOptions
                ]);
            }

            // details.slug_prefix
            // --------------------
            // Erster Pfadteil der URLs der Datensätze (/farben/blau), je Sprache der
            // Instanz ein Eingabefeld - alle untereinander, keine Reiter. Leere
            // Sprachen übernehmen den Präfix der Hauptsprache (Area::slugPrefixErrors()).
            // Die Sprachen sind die der Instanz beim Aufbau der Maske; wechselt die
            // Instanz, fallen beim Speichern fremde Sprachen weg.
            $languages = $this->instanceCache->languages((int)($this->data['instance_id'] ?? 0));
            $prefixes = $this->data['details']['slug_prefix'] ?? [];
            if (!is_array($prefixes)) $prefixes = [(int)array_key_first($languages) => (string)$prefixes];
            $placeholders = '';
            foreach (array_keys($languages) as $languageId) {
                $placeholders .= '{data_details_slug_prefix_' . (int)$languageId . "}\n";
            }
            $this->backend->formReplace('{data_details_slug_prefix}', $placeholders);
            $colDef = [
                'type'    => 'chars',
                'label'   => $this->area->getSchema()['details.slug_prefix']['label'],
                'tooltip' => $this->area->getSchema()['details.slug_prefix']['tooltip'],
                'max'     => 64,
                'suffix'  => '/',
            ];
            foreach ($languages as $languageId => $language) {
                $this->backend->formField($this->area, [
                    'id' => $this->id,
                    'name' => 'details.slug_prefix',
                    'value' => (string)($prefixes[$languageId] ?? ''),
                    'colDef' => $colDef,
                    'placeholder' => '{data_details_slug_prefix_' . (int)$languageId . '}',
                    'langId' => (int)$languageId,
                    'langSuffix' => count($languages) > 1 ? (string)($language['code'] ?? '') : '',
                    'textLang' => (string)($language['code'] ?? ''),
                ]);
            }

            // details.tabs
            // --------------------
            // Darstellung mehrsprachiger Felder in der Eingabemaske: alle Sprachen
            // untereinander (0) oder ein Reiter je Sprache (1). Nur angeboten, wenn
            // überhaupt eine Instanz mehr als eine Sprache führt - sonst bliebe die
            // Wahl ohne Wirkung (RecordBackendEdit::_editForm()).
            $hasMultilingualInstance = false;
            foreach ((array)($this->instanceCache->all()['instance_ids'] ?? []) as $instanceId) {
                if (count($this->instanceCache->languageIds((int)$instanceId)) > 1) {
                    $hasMultilingualInstance = true;
                    break;
                }
            }
            if ($hasMultilingualInstance) {
                $this->backend->formField($this->area, [
                    'id' => $this->id,
                    'name' => 'details.tabs',
                    'value' => (int)($this->data['details']['tabs'] ?? 0),
                    'options' => [
                        0 => $this->cms->snippet('area.option_tabs_0'),
                        1 => $this->cms->snippet('area.option_tabs_1'),
                    ],
                ]);
            }

            // dependent
            // --------------------
            // Abhängiger Bereich: Zielbereich von 'link'-Feldern (fest verknüpfte
            // Inhalte, siehe Area::hookDefinition())
            $this->backend->formField($this->area, [
                'id' => $this->id,
                'name' => 'dependent',
                'value' => (int)($this->data['dependent'] ?? 0),
            ]);

            // privacy_policy_id
            // --------------------
            // Lösch-Policy (Modul Privacy): die für alle Instanzen und die der Instanz
            // des Bereichs; ein neuer Bereich bekommt die Standard-Policy seines Typs
            $policies = $GLOBALS['app']->get(\Camindo\Core\Modules\Privacy\DeletePolicy::class);
            $this->backend->formField($this->area, [
                'id' => $this->id,
                'name' => 'privacy_policy_id',
                'value' => (int)($this->data['privacy_policy_id'] ?? 0) ?: $policies->standardId($type),
                'options' => $policies->optionsFor((int)($this->data['instance_id'] ?? 0)),
            ]);


            // default_template_id
            // --------------------
            $this->backend->formField($this->area, [
                'id' => $this->id,
                'name' => 'default_template_id',
                'value' => $this->data['default_template_id'] ?? '',
                'display_type' => 'lookup',
                'template' => 'edit_form_lookup_foreign_table'
            ]);

            $urlSuffix = "src_field=default_template_id";
            $searchUrl = "Template/template_list.php?" . $urlSuffix;
            $updateUrl = "Template/ajax_lookup_preview.php?" . $urlSuffix;
            $ids = [(int)$this->data['default_template_id'] ?? 0];
            $idString = implode(',', $ids);

            $this->backend->formReplace('{src_id}', $this->id);
            $this->backend->formReplace('{src_field}', 'default_template_id');
            $this->backend->formReplace('{search_url}', $searchUrl);
            $this->backend->formReplace('{update_url}', $updateUrl);
            $this->backend->formReplace('{data_ids}', $idString);
            $this->backend->formReplace('{max}', '1');
            $this->backend->formReplace('{preview}', $this->templateBackend->lookupPreviewCreate('default_template_id', $ids, 'data', 1));

            // selectable templates
            // --------------------
            $colDef = [
                'type' => 'ids',
                'label' => '#area.field_area_selectable_templates',
                'tooltip' => '#template.tooltip_access_selectable',
                'display_type' => 'lookup',
                'template' => 'edit_form_lookup_foreign_table'
            ];
            $this->backend->formField($this->area, [
                'id' => $this->id,
                'name' => 'selectable_templates',
                'colDef' => $colDef,
                'template' => 'edit_form_lookup_foreign_table'
            ]);

            $urlSuffix = "src_field=selectable_templates";
            $searchUrl = "Template/template_list.php?" . $urlSuffix;
            $updateUrl = "Template/ajax_lookup_preview.php?" . $urlSuffix;
            $ids = $this->selectableTemplateIds($this->id);
            $idString = implode(',', $ids);

            $this->backend->formReplace('{src_id}', $this->id);
            $this->backend->formReplace('{src_field}', 'selectable_templates');
            $this->backend->formReplace('{search_url}', $searchUrl);
            $this->backend->formReplace('{update_url}', $updateUrl);
            $this->backend->formReplace('{data_ids}', $idString);
            $this->backend->formReplace('{max}', '');
            $this->backend->formReplace('{preview}', $this->templateBackend->lookupPreviewCreate('selectable_templates', $ids, 'data', 0));


            // url-override templates
            // --------------------
            $colDef = [
                'type' => 'ids',
                'label' => '#area.field_area_override_templates',
                'tooltip' => '#template.tooltip_access_override',
                'display_type' => 'lookup',
                'template' => 'edit_form_lookup_foreign_table'
            ];
            $this->backend->formField($this->area, [
                'id' => $this->id,
                'name' => 'override_templates',
                'colDef' => $colDef,
                'template' => 'edit_form_lookup_foreign_table'
            ]);

            $urlSuffix = "src_field=override_templates";
            $searchUrl = "Template/template_list.php?" . $urlSuffix;
            $updateUrl = "Template/ajax_lookup_preview.php?" . $urlSuffix;
            $ids = $this->overrideTemplateIds($this->id);
            $idString = implode(',', $ids);

            $this->backend->formReplace('{src_id}', $this->id);
            $this->backend->formReplace('{src_field}', 'override_templates');
            $this->backend->formReplace('{search_url}', $searchUrl);
            $this->backend->formReplace('{update_url}', $updateUrl);
            $this->backend->formReplace('{data_ids}', $idString);
            $this->backend->formReplace('{max}', '');
            $this->backend->formReplace('{preview}', $this->templateBackend->lookupPreviewCreate('override_templates', $ids, 'data', 0));

            // list_template_id
            // --------------------
            // Darstellung im Backend: Template für die letzte Spalte der
            // Datensatzliste und die ausführliche Lookup-Vorschau
            $this->backend->formField($this->area, [
                'id' => $this->id,
                'name' => 'list_template_id',
                'value' => $this->data['list_template_id'] ?? '',
                'display_type' => 'lookup',
                'template' => 'edit_form_lookup_foreign_table'
            ]);

            $urlSuffix = "src_field=list_template_id";
            $searchUrl = "Template/template_list.php?" . $urlSuffix;
            $updateUrl = "Template/ajax_lookup_preview.php?" . $urlSuffix;
            $ids = [(int)($this->data['list_template_id'] ?? 0)];
            $idString = implode(',', $ids);

            $this->backend->formReplace('{src_id}', $this->id);
            $this->backend->formReplace('{src_field}', 'list_template_id');
            $this->backend->formReplace('{search_url}', $searchUrl);
            $this->backend->formReplace('{update_url}', $updateUrl);
            $this->backend->formReplace('{data_ids}', $idString);
            $this->backend->formReplace('{max}', '1');
            $this->backend->formReplace('{preview}', $this->templateBackend->lookupPreviewCreate('list_template_id', $ids, 'data', 1));

            // trigger_template_ids
            // --------------------
            // Ereignis-Methoden (onCreate, onSave ...) für die Datensätze des
            // Bereichs, siehe TriggerTemplates - mehrere; die Reihenfolge der
            // Vorschau ist die Ausführungsreihenfolge (ordered=1: Hoch/Runter)
            $colDef = [
                'type' => 'ids',
                'label' => '#area.field_area_trigger_template_ids',
                'tooltip' => '#area.tooltip_field_trigger_template_ids',
                'display_type' => 'lookup',
                'template' => 'edit_form_lookup_foreign_table'
            ];
            $this->backend->formField($this->area, [
                'id' => $this->id,
                'name' => 'trigger_template_ids',
                'colDef' => $colDef,
                'template' => 'edit_form_lookup_foreign_table'
            ]);

            $urlSuffix = "src_field=trigger_template_ids";
            $searchUrl = "Template/template_list.php?" . $urlSuffix;
            $updateUrl = "Template/ajax_lookup_preview.php?" . $urlSuffix . "&ordered=1";
            $ids = array_map('intval', (array)($this->data['trigger_template_ids'] ?? []));
            $idString = implode(',', $ids);

            $this->backend->formReplace('{src_id}', $this->id);
            $this->backend->formReplace('{src_field}', 'trigger_template_ids');
            $this->backend->formReplace('{search_url}', $searchUrl);
            $this->backend->formReplace('{update_url}', $updateUrl);
            $this->backend->formReplace('{data_ids}', $idString);
            $this->backend->formReplace('{max}', '');
            $this->backend->formReplace('{preview}', $this->templateBackend->lookupPreviewCreate('trigger_template_ids', $ids, 'data', 0, true));

            // overview_template_id
            // --------------------
            // Übersicht unter der Adresse, die nur aus dem Slug-Präfix besteht
            // (/de/modelle) - ohne Datensatz, siehe Area
            $this->backend->formField($this->area, [
                'id' => $this->id,
                'name' => 'overview_template_id',
                'value' => $this->data['overview_template_id'] ?? '',
                'display_type' => 'lookup',
                'template' => 'edit_form_lookup_foreign_table'
            ]);

            $urlSuffix = "src_field=overview_template_id";
            $searchUrl = "Template/template_list.php?" . $urlSuffix;
            $updateUrl = "Template/ajax_lookup_preview.php?" . $urlSuffix;
            $ids = [(int)($this->data['overview_template_id'] ?? 0)];
            $idString = implode(',', $ids);

            $this->backend->formReplace('{src_id}', $this->id);
            $this->backend->formReplace('{src_field}', 'overview_template_id');
            $this->backend->formReplace('{search_url}', $searchUrl);
            $this->backend->formReplace('{update_url}', $updateUrl);
            $this->backend->formReplace('{data_ids}', $idString);
            $this->backend->formReplace('{max}', '1');
            $this->backend->formReplace('{preview}', $this->templateBackend->lookupPreviewCreate('overview_template_id', $ids, 'data', 1));
        }

        // Anmeldung an der Website (type 'profile')
        // --------------------
        // Welche Eingabefelder des Bereichs tragen Anmeldename und Passwort?
        // Name: 'chars' oder 'encrypted' (E-Mail-Adressen liegen verschlüsselt),
        // Passwort: 'password'. Ein neuer Bereich hat noch keine Felder - die
        // Listen bleiben dann leer und werden nach dem Anlegen der Felder gefüllt.
        if ($type === 'profile') {
            if (!empty($this->blueprint)) {
                $this->backend->formStatic($this->area, 'login_name_field_id', $this->cms->snippet('area.blueprint_note_later'));
                $this->backend->formStatic($this->area, 'login_password_field_id', $this->cms->snippet('area.blueprint_note_later'));
            }
            else {
                $this->backend->formField($this->area, [
                    'id' => $this->id,
                    'name' => 'login_name_field_id',
                    'value' => (int)($this->data['login_name_field_id'] ?? 0),
                    'options' => $this->_getFieldOptions(['chars', 'encrypted']),
                    'selectPlease' => true,
                ]);
                $this->backend->formField($this->area, [
                    'id' => $this->id,
                    'name' => 'login_password_field_id',
                    'value' => (int)($this->data['login_password_field_id'] ?? 0),
                    'options' => $this->_getFieldOptions(['password']),
                    'selectPlease' => true,
                ]);
            }
            // Zweiter Faktor der Anmeldung (UserSecondFactor::POLICIES)
            $options = [];
            foreach (UserSecondFactor::POLICIES as $policy) {
                $options[$policy] = $this->cms->snippet('area.option_second_factor_' . $policy);
            }
            $this->backend->formField($this->area, [
                'id' => $this->id,
                'name' => 'details.second_factor',
                'value' => (string)($this->data['details']['second_factor'] ?? 'none'),
                'options' => $options,
            ]);
        }

        // Profil-Bereiche (type 'ugc')
        // --------------------
        // Aus welchen Profil-Bereichen dürfen die Autoren stammen? Zur Wahl stehen
        // die Profil-Bereiche der Instanz des Bereichs und ihrer lesbaren Instanzen -
        // nur deren Datensätze sieht das Frontend dieser Instanz (Cms::$sqlAccess).
        if ($type === 'ugc') {
            if (!empty($this->blueprint)) {
                $this->backend->formStatic($this->area, 'profile_area_ids', $this->cms->snippet('area.blueprint_note_later'));
            }
            else {
                $instanceId = (int)($this->data['instance_id'] ?? 0);
                $this->backend->formField($this->area, [
                    'id' => $this->id,
                    'name' => 'details.profile_area_ids',
                    'value' => $this->system->sanitizeIds((array)($this->data['details']['profile_area_ids'] ?? []), true),
                    'options' => $this->backend->profileAreaOptions($instanceId),
                ]);
            }
        }

        // Hidden fields
        $this->backend->formHidden('function', 'save');
        $this->backend->formHidden('id', $this->id);

        // Print form
        echo $this->backend->form('form_save_cancel_note', removePlaceholders:true);

        // Textarea initialisieren
        $this->backend->script("cApp.ui._initTextareaGrow(\$('.c9-layer:last'));");

        echo '</form>';
    }


    /**
     * function=delete: Löschbestätigung.
     *
     * Der Text der Warnung hängt daran, was mit dem Bereich verschwindet - deshalb
     * die Zählabfragen. Je Bereichstyp:
     *
     *     folder    Kind-Bereiche im Nested-Set-Teilbaum (lft BETWEEN lft AND rgt),
     *               darin enthaltene produktive Datensätze und Templates.
     *               Nur wenn wirklich Inhalte daran hängen, kommt statt der einfachen
     *               Bestätigung ein Formular mit der Auswahl "mit / ohne Unterbereiche".
     *     template  Anzahl der Templates im Bereich selbst
     *     record    Anzahl der produktiven Datensätze (tmp=0, deleted=0)
     *
     * `tmp = 0` schließt die Arbeitskopien aus, die während einer Bearbeitung
     * entstehen - sie würden die Zahl sonst verfälschen.
     *
     * Die Zählungen laufen über das Nested Set und nicht über parent_id, weil der
     * ganze Teilbaum gemeint ist und nicht nur die direkten Kinder.
     */
    private function _editDeleteForm(): void
    {
        echo '<form action="Area/area_edit.php" method="post">';

        $id = (int)$this->id;
        $areaType = $this->data['type'];

        if ($areaType == 'folder') {
            // Zählung der Kind-Bereiche
            $sql = "SELECT COUNT(`_d2`.`area_id`)"
                ." FROM `area` AS `_d1`"
                ." JOIN `area` AS `_d2` ON `_d2`.`lft` BETWEEN `_d1`.`lft` AND `_d1`.`rgt`"
                ." WHERE `_d1`.`area_id` = ?"
                ." AND `_d2`.`area_id` != ?"
                ." AND `_d2`.`deleted` = 0";
            $this->db->query($sql, 'ii', [$id, $id]);
            $countChilds = $this->db->fetchNum()[0];

            if ($countChilds > 0) {
                // Zählung aller produktiver Datensätze in den Kind-Bereichen
                $sql = "SELECT COUNT(`r`.`record_id`)"
                    ." FROM `area` AS `_d1`"
                    ." JOIN `area` AS `_d2` ON `_d2`.`lft` BETWEEN `_d1`.`lft` AND `_d1`.`rgt`"
                    ." JOIN `record` AS `r` ON `r`.`area_id` = `_d2`.`area_id`"
                    ." WHERE `_d1`.`area_id` = ?"
                    ." AND `_d2`.`area_id` != ?"
                    ." AND `_d2`.`deleted` = 0"
                    ." AND `r`.`tmp` = 0"
                    ." AND `r`.`deleted` = 0";
                $this->db->query($sql, 'ii', [$id, $id]);
                $countChildDatasets = $this->db->fetchNum()[0];

                // Zählung aller Templates in den Kind-Bereichen
                $sql = "SELECT COUNT(`t`.`template_id`)"
                    ." FROM `area` AS `_d1`"
                    ." JOIN `area` AS `_d2` ON `_d2`.`lft` BETWEEN `_d1`.`lft` AND `_d1`.`rgt`"
                    ." JOIN `template` AS `t` ON `t`.`area_id` = `_d2`.`area_id`"
                    ." WHERE `_d1`.`area_id` = ?"
                    ." AND `_d2`.`area_id` != ?"
                    ." AND `_d2`.`deleted` = 0";
                $this->db->query($sql, 'ii', [$id, $id]);
                $countChildTemplates = $this->db->fetchNum()[0];

                if ($countChildDatasets + $countChildTemplates > 0) {
                    // Baue Meldung zusammen 
                    // enthält ... Bereiche
                    $childAreas = $this->cms->snippet('area.x_areas', ['count' => $countChilds]);
                    // ... Datensätze und ... Templates (Anzahl im ICU Message Format)
                    $itemsArr = [];
                    if ($countChildDatasets > 0) {
                        $itemsArr[] = $this->cms->snippet('record.x_records', ['count' => $countChildDatasets]);
                    }
                    if ($countChildTemplates > 0) {
                        $itemsArr[] = $this->cms->snippet('template.x_templates', ['count' => $countChildTemplates]);
                    }
                    $items = implode($this->cms->snippet('area.elements_and'), $itemsArr);
                    $msg = strtr($this->cms->snippet('area.alert_folder_delete_with_contents'), [
                        '{child_areas}' => $childAreas,
                        '{items}' => $items,
                    ]);

                    // Erstelle Radio Select
                    $uid = $this->crypt->randomKey(16);
                    $this->backend->formInit('area.area_delete_with_option_form', [
                        '{text}' => $msg,
                        '{id}' => $uid
                    ]);
                }
                else {
                    $this->backend->formInit('area.area_delete_form', [
                        '{text}' => '{#area.alert_folder_delete}'
                    ]);
                }
            }
        }
        elseif ($areaType == 'template') {
            // Zählung der Templates im Bereich selbst
            $sql = "SELECT COUNT(`template_id`) FROM `template` WHERE `area_id` = ?";
            $this->db->query($sql, 'i', [$id]);
            $countTemplates = $this->db->fetchNum()[0];
            if ($countTemplates > 0) {
                $items = $this->cms->snippet('template.x_templates', ['count' => $countTemplates]);
                $msg = str_replace('{items}', $items, $this->cms->snippet('area.alert_area_delete_with_templates'));
                $this->backend->formInit('area.area_delete_form', [
                    '{text}' => $msg
                ]);
            }
            else {
                $this->backend->formInit('area.area_delete_form', [
                    '{text}' => '{#area.alert_area_delete}'
                ]);
            }
        }
        elseif (Area::isContentType($areaType)) {
            // Datensatzbereich (record, profile, ugc)
            // Zählung produktiver Datensätze im Bereich selbst
            $sql = "SELECT COUNT(`record_id`) FROM `record` WHERE `area_id` = ? AND `tmp`=0 AND `deleted`=0";
            $this->db->query($sql, 'i', [$id]);
            $countRecords = $this->db->fetchNum()[0];
            if ($countRecords > 0) {
                $items = $this->cms->snippet('record.x_records', ['count' => $countRecords]);
                $msg = str_replace('{items}', $items, $this->cms->snippet('area.alert_area_delete_with_records'));
                $this->backend->formInit('area.area_delete_form', [
                    '{text}' => $msg
                ]);
            }
            else {
                $this->backend->formInit('area.area_delete_form', [
                    '{text}' => '{#area.alert_area_delete}'
                ]);
            }
        }
        else {
            echo $this->backend->template('alert_danger', [
                '{text}' => 'Internal error: Area type not supported.'
            ]);
            return;
        }
        
        $this->backend->formHidden('id', $this->id);
        $this->backend->formHidden('function', 'delete_confirm');

        // Print form
        echo $this->backend->form('form_delete_cancel');

        echo '</form>';
    }

    // ====================
    // Suchfunktion

    /**
     * Suche (backend/area_search.php).
     *
     * Zwei Zustände: ohne function die Maske, mit function=search die Ausführung.
     * Anders als list()/edit() gibt es hier keinen dritten Zweig - _search() rendert
     * gar keine Trefferliste, sondern schickt die Suchparameter an die Bereichsliste
     * zurück (siehe dort).
     */
    public function search(): void
    {
        // Seiten-Parameter
        $function = (string) ($_POST['function'] ?? '');

        if ($function == 'search') {
            $this->_search();
        }

        if ($function == '') {
            $this->_searchForm();
        }
    }

    /**
     * Suchmaske.
     *
     * searchField() statt formField(): Suchfelder sind nie Pflichtfelder und schreiben
     * nach `filter[...]` statt nach `data[...]`.
     */
    private function _searchForm():void
    {
        // Seiten-Parameter
        $filter = (array) ($_REQUEST['filter'] ?? []);

        echo '<form action="Area/area_search.php" method="post">';

        // Bread crum navi
        $this->backend->setNaviLabel('#button_search');
        
        // Container header
        echo $this->backend->template('container_header');

        // H1
        echo $this->backend->headline('#area.headline_search');

        $this->backend->formInit('area.area_search_form');

        // label
        $this->backend->searchField($this->area, ['name' => 'label', 'value' => $filter['label'] ?? '']);

        // name
        $this->backend->searchField($this->area, ['name' => 'name', 'value' => $filter['name'] ?? '']);

        // parent_id - in der Suchmaske wird kein Ordner ausgeschlossen
        $parentOptions = $this->_getParentOptions(0);
        $this->backend->searchField($this->area, [
            'name' => 'parent_id',
            'value' => $filter['parent_id'] ?? '0',
            'display_type' => 'select',
            'options' => $parentOptions,
            'selectPlease' => true
        ]);

        // icon
        $this->backend->searchField($this->area, ['name' => 'icon', 'value' => $filter['icon'] ?? '']);

        // area_id
        $this->backend->searchField($this->area, ['name' => 'area_id', 'value' => $filter['area_id'] ?? '']);

        // instance_id
        $instanceOptions = $this->_instanceOptions();
        if (count($instanceOptions) > 0) {
            $this->backend->searchField($this->area, [
                'name' => 'instance_id',
                'value' => $filter['instance_id'] ?? '',
                'options' => $instanceOptions
            ]);
        }
        // default_template_id
        // --------------------
        $this->backend->searchField($this->area, ['name' => 'default_template_id', 'value' => $filter['default_template_id'] ?? '']);

        $urlSuffix = "src_field=default_template_id";
        $searchUrl = "Template/template_list.php?" . $urlSuffix;
        $updateUrl = "Template/ajax_slookup_preview.php?" . $urlSuffix;
        $filterIds = (array)($_REQUEST['filter']['default_template_id'] ?? []);
        $ids = $this->system->sanitizeIds($filterIds, true);
        $idString = implode(',', $ids);
        
        $this->backend->formReplace('{src_field}', 'default_template_id');
        $this->backend->formReplace('{search_url}', $searchUrl);
        $this->backend->formReplace('{update_url}', $updateUrl);
        $this->backend->formReplace('{data_ids}', $idString);
        $this->backend->formReplace('{max}', '1');
        $this->backend->formReplace('{preview}', $this->templateBackend->lookupPreviewCreate('default_template_id', $ids, 'filter', 0));

        // sort, order
        // --------------------
        $sortOptions = [
            'lft' => $this->cms->snippet('area.field_area_lft'), // nested set order
            'name' => $this->cms->snippet('area.field_area_name'),
            'icon' => $this->cms->snippet('area.field_area_icon'),
            'label' => $this->cms->snippet('area.field_area_label'),
            'area_id' => 'area_id'
        ];
        $this->backend->searchFieldSortOrder($sortOptions);

        // function
        $this->backend->formHidden('function', 'search');

        // Print form
        echo $this->backend->form('form_search_cancel');

        // Container footer
        echo $this->backend->template('container_footer');

        echo '</form>';
    }

    /**
     * function=search: Suche ausführen.
     *
     * Die Trefferliste wird nicht hier gerendert. Stattdessen schließt sich der
     * Suchlayer und der darunterliegende Listenlayer wird mit den Suchparametern in
     * der URL neu geladen - list() wertet sie über Cms::select(['request' => 'all'])
     * aus. Vorteil: Sortierung, Paginierung und Filtertext funktionieren danach ganz
     * normal weiter.
     */
    private function _search():void
    {
        // Parameter aus Post
        $queryString = http_build_query($_POST);

        // Sicher für Javascript verpacken
        // json_encode maskiert < > ' " & als \uXXXX, der String kann damit gefahrlos
        // in das Javascript unten eingesetzt werden.
        $safeParams = json_encode($queryString, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        // Das Ergebnis ist ein JSON-String MIT Anführungszeichen ('"a=1&b=2"').
        // substr(..., 1) schneidet nur das öffnende ab - das schließende bleibt
        // stehen und beendet unten das Javascript-Stringliteral.
        $safeUri = 'Area/index.php?' . substr($safeParams, 1);

        // Referer-Layer mit diesen Parametern neu laden
        $this->backend->script("
            cApp.layers.close();
            cApp.layers.replace('{$safeUri}');
        ");
    }


    // ====================
    // Sonstige Hilfsfunktionen

    /**
     * Instanzen (Websites) als key-value-Pairs für die Auswahl im Formular, in der
     * Reihenfolge des Backends (Instance::orderSql(): die Standard-Instance zuletzt).
     */
    private function _getInstanceOptions(): array
    {
        $options = [];
        foreach ($this->_instanceOptions() as $instanceId => $label) {
            $options[$instanceId] = $this->cms->textOrSnippet((string)$label);
        }
        return $options;
    }

    /**
     * Auswahlliste der Eingabefelder des Bereichs mit bestimmten Typen - für die
     * Anmeldefelder eines Profil-Bereichs (details.login_*_field_id).
     *
     * @param string[] $types  Feldtypen (`field`.`type`)
     * @return array  field_id => "name (Label)"
     */
    private function _getFieldOptions(array $types): array
    {
        $options = [];
        if ($this->id < 1 || empty($types)) return $options;
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $this->db->query(
            "SELECT `field_id`, `name`, `label` FROM `field`"
            . " WHERE `area_id` = ? AND `type` IN ($placeholders) ORDER BY `position` ASC",
            'i' . str_repeat('s', count($types)),
            array_merge([$this->id], $types)
        );
        while ($row = $this->db->fetch()) {
            $label = strtr($this->cms->snippet('parenthetical_note'), [
                '{text}' => $this->cms->textOrSnippet($row['label'] ?? '')
            ]);
            $options[(int)$row['field_id']] = $row['name'] . ' ' . $label;
        }
        return $options;
    }

    /**
     * Auswahlliste "übergeordneter Bereich".
     *
     * Es kommen nur Bereiche vom Typ 'folder' in Frage - nur Ordner dürfen Kinder
     * haben. Die Einrückung im Optionstext entsteht aus `depth`, die Reihenfolge aus
     * `lft` (Nested Set), die Liste liest sich also wie der Baum.
     *
     * @param int $areaId      Id eines Bereichs, der aus der Liste ausgeschlossen werden soll
     *                         (man kann sich nicht selbst als Elternteil wählen)
     * @param int $instanceId  nur Ordner dieser Instanz (0 = alle) - ein Bereich darf
     *                         nur im Baum seines Mandanten stehen (Area::hookOnSave())
     */
    private function _getParentOptions(int $areaId = 0, int $instanceId = 0): array
    {
        // Bitte wählen
        $options = [0 => $this->cms->snippet('nothing_selected')];

        // Ordner auflisten
        $sql = "SELECT `area_id`, `label`, `depth` FROM `area`"
            ." WHERE `type` = 'folder' AND `area_id` != ? AND `deleted`=0"
            . ($instanceId > 0 ? " AND `instance_id` = " . $instanceId : '')
            ." ORDER BY `lft` ASC";
        $this->db->query($sql, 'i', [$areaId]);
        while ($row = $this->db->fetch()) {
            $label = str_repeat(' ', $row['depth'] * 4) . $this->cms->textOrSnippet($row['label'] ?? '');
            $options[(int)$row['area_id']] = $label;
        }

        return $options;
    }

    /**
     * Auswahlliste "vorangehender Bereich" - die Eingabehilfe für `position`.
     *
     * Enthält alle Geschwister unter $parentId in ihrer aktuellen Reihenfolge, davor
     * den Eintrag 0 ("an den Anfang").
     *
     * Wurzel-Bereiche haben in der Datenbank `parent_id IS NULL`, nicht 0 - deshalb
     * bekommt $parentId === 0 einen eigenen WHERE-Zweig. Auf der Wurzelebene zählen
     * nur die Bereiche derselben Instanz als Geschwister (jeder Mandant sein Baum);
     * die `position` bleibt dabei tabellenweit über alle Wurzeln fortlaufend.
     */
    private function _getPreviousOptions(int $parentId, int $areaId, int $instanceId = 0): array
    {
        // An den Anfang
        $options = [0 => $this->cms->snippet('position_at_start')];

        // Restliche Optionen
        $sql = "SELECT `area_id`,`label` FROM `area` WHERE `area_id` != ? AND `deleted`=0";
        $bindTypes = 'i';
        $values = [$areaId];
        if ($parentId === 0) {
            $sql .= ' AND `parent_id` IS NULL';
            if ($instanceId > 0) $sql .= ' AND `instance_id` = ' . $instanceId;
        } else {
            $sql .= ' AND `parent_id`=?';
            $bindTypes .= 'i';
            $values[] = $parentId;
        }
        $sql .= ' ORDER BY `position` ASC';
        $this->db->query($sql, $bindTypes, $values);
        while ($row = $this->db->fetch()) {
            $options[(int)$row['area_id']] = $this->cms->textOrSnippet($row['label'] ?? '');
        }

        return $options;
    }

    /**
     * Ids der im Bereich wählbaren Templates (rel_area_template.selectable = 1).
     *
     * Database::fetchAll() liefert bei einer einspaltigen Abfrage eine flache Liste
     * von Werten (kein Array von Zeilen) - das Ergebnis ist also direkt ein Id-Array.
     */
    private function selectableTemplateIds(int $areaId): array
    {
        $sql = "SELECT `template_id` FROM `rel_area_template` WHERE `area_id` = ? AND `selectable`=1";
        $this->db->query($sql, 'i', [$areaId]);
        return $this->db->fetchAll();
    }

    /**
     * Ids der Templates, die per URL erzwungen werden dürfen
     * (rel_area_template.override = 1).
     *
     * Damit ist ein Aufruf /{id},{template} erlaubt, auch wenn der Datensatz ein
     * anderes Template gesetzt hat. Rückgabe wie oben eine flache Id-Liste.
     */
    private function overrideTemplateIds(int $areaId): array
    {
        $sql = "SELECT `template_id` FROM `rel_area_template` WHERE `area_id` = ? AND `override`=1";
        $this->db->query($sql, 'i', [$areaId]);
        return $this->db->fetchAll();
    }

    /**
     * Instanzen als Optionen listen
     */
    private function _instanceOptions(): array
    {
        $this->db->query("SELECT `instance_id`, `label` FROM `instance` ORDER BY " . Instance::orderSql());
        return $this->db->fetchPairs() ?? [];
    }
}
