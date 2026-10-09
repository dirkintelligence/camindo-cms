<?php
/**
 * Backend-Oberfläche für die Instances - die einzelnen Websites (Tabelle `instance`).
 *
 * Einstiegspunkte (Scripte in backend/):
 *
 *     instance_list.php  -> list()   Instance-Liste
 *     instance_info.php  -> info()   Detailansicht
 *     instance_edit.php  -> edit()   anlegen / kopieren / ändern / löschen
 *
 * DIE STANDARD-INSTANCE
 * --------------------
 * Instance::DEFAULT_ID ist das Sammelbecken für alles, was keine konfigurierte Domain
 * trifft. Ändern lässt sie sich wie jede andere - nur zwei Dinge sind fest:
 *
 *     die Zusatz-Domains sind immer ".*" (statt der Zeilenliste steht dort ein
 *     Hinweis, siehe _editForm())
 *     sie steht in der Liste immer am Ende und kann nicht als Vorgänger einer
 *     anderen Instanz gewählt werden - sie fängt ja auf, was die übrigen nicht
 *     abdecken
 *
 * Kopieren und Löschen bietet das Backend für sie nicht an; Instance::hookOnDelete()
 * weist eine Löschung zusätzlich ab.
 *
 * LÖSCHEN
 * --------------------
 * `instance` hat kein `deleted`-Feld - es gibt keinen Papierkorb, Dataset::delete()
 * löscht sofort endgültig. Und mit der Instanz gehen ihre gesamten Inhalte:
 * Inhaltsbereiche, deren Eingabefelder, Datensätze und Templates
 * (Instance::hookOnDelete()). _editDeleteForm() zeigt die Mengen deshalb vorher an.
 *
 * @see Camindo\Core\Modules\Instance\Instance           Dataset/Model
 * @see Camindo\Core\Modules\Area\AreaBackend            dort wird der Container zugeordnet
 * @see Camindo\Core\Modules\Language\LanguageBackend    die Vorlage dieser Klasse
 */
namespace Camindo\Core\Modules\Instance;

use Camindo\Core\Backend;
use Camindo\Core\Database;
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Crypt;
use Camindo\Core\SecurityHeaders;
use Camindo\Core\Modules\Instance\Instance;
use Camindo\Core\Modules\Record\RecordBackendEdit;
use Camindo\Core\Modules\Template\TemplateBackend;

class InstanceBackend {

    protected int $id = 0;
    protected ?array $data = [];
    protected string $function = '';

    public function __construct(
        private Backend $backend,
        private Database $db,
        private Cms $cms,
        private Crypt $crypt,
        private Instance $instance,
        // Beide nur für die Lookup-Felder der Startseite: sie rendern den
        // Vorschaublock ihrer eigenen Tabelle (siehe _editFormStartPage()).
        private RecordBackendEdit $recordBackend,
        private TemplateBackend $templateBackend,
        private Config $config,
        private SecurityHeaders $securityHeaders,
    )
    {}

    /**
     * Instance-Liste (backend/instance_list.php).
     *
     * limit -1: keine Paginierung, alle Instances auf einer Seite. Sortiert nach
     * `position`.
     *
     * Die Standard-Instance steht mit in der Liste, aber ohne Kontextmenü und ohne
     * Klick auf die Detailansicht - an ihr gibt es nichts zu tun.
     */
    public function list(): void
    {
        // Seitenelemente
        // --------------------

        // Bread crum navi
        $this->backend->setNaviLabel('#instance.module_label');

        // Container header
        echo $this->backend->template('container_header');

        // H1
        echo $this->backend->headline('#instance.headline_instance_list');

        // Buttons
        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_create_primary', "cApp.layers.add('Instance/instance_edit.php?function=create');");
        $this->backend->buttonAdd('button_refresh', "cApp.layers.refresh();");
        echo $this->backend->buttonBar();

        // Instance-Liste
        // --------------------
        $this->cms->select([
            'request' => 'all',
            'limit' => -1,
            'filtertext' => 'true'
        ], $this->instance);

        echo $this->backend->paginationTopShort();

        if ($this->db->num_rows > 0) {

            // Sprachen einmal holen - je Zeile eine Abfrage wäre Verschwendung
            $languages = $this->_languageOptions();

            // Table-Head
            $headColumns = $this->backend->template('instance.table_head_columns');

            // Table-Rows
            $tableRows = '';

            // Hole Ergebnisse (Dataset::fetch: mit Datennachbeareitung)
            $tableRowLast = ''; // Default-Instanz
            while ($data = $this->instance->fetch()) {
                $instanceId = (int)$data['instance_id'];
                $labelText = $this->cms->textOrSnippet($data['label']);
                $iconHtml = $this->backend->template('instance.icon_instance');
                $languageId = (int)$data['primary_language_id'];

                $tableRow = $this->backend->template('instance.table_row', [
                    '{data_label}' => $iconHtml . ' ' . $labelText,
                    '{data_domain}' => htmlspecialchars((string)$data['primary_domain']),
                    '{data_language}' => htmlspecialchars($languages[$languageId] ?? ''),
                    '{data_id}' => $instanceId
                ]);
                // {name} steht nicht in der Vorlage, sondern im Sprach-Snippet
                // ('Instanz "{name}"' als aria-label) - das setzt Backend::template()
                // erst NACH seinen eigenen Ersetzungen ein.
                $tableRow = strtr($tableRow, ['{name}' => $labelText]) . "\n";
                if ($instanceId === Instance::DEFAULT_ID) {
                    $tableRowLast = $tableRow;
                }
                else {
                    $tableRows .= $tableRow;
                }
            }
            $tableRows .= $tableRowLast;

            // Render table
            echo $this->backend->template('instance.table_with_head', [
                '{head_columns}' => $headColumns,
                '{table_rows}' => $tableRows
            ]);

            // Context menu
            echo $this->backend->template('instance.context_menu');
        }

        // Container Footer
        echo $this->backend->template('container_footer');
    }

    /**
     * Detailansicht einer Instance (backend/instance_info.php).
     *
     * Bearbeiten, Kopieren und Löschen erscheinen nicht für die Standard-Instance
     * (siehe Klassenkommentar).
     */
    public function info(): void
    {
        $this->id = (int)($_REQUEST['id'] ?? 0);
        $this->function = 'info';

        // Bread crum navi
        $this->backend->setNaviLabel('#button_info');

        // Container header
        echo $this->backend->template('container_header');

        // Instance-Data laden
        // --------------------
        if (!$this->data = $this->instance->load($this->id)) {
            echo $this->backend->template('alert_danger', [
                '{text}' => $this->cms->errorMsg()
            ]);
            echo $this->backend->template('container_footer');
            return;
        }

        // H1
        echo $this->_editHeadline();

        // Buttons
        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_edit_primary', "cApp.layers.replace('Instance/instance_edit.php?id={$this->id}');");
        if ($this->id !== Instance::DEFAULT_ID) {
            $this->backend->buttonAdd('button_copy', "cApp.layers.replace('Instance/instance_edit.php?id={$this->id}&function=copy');");
            $this->backend->buttonAdd('button_delete', "cApp.layers.replace('Instance/instance_edit.php?id={$this->id}&function=delete');");
        }
        if (!empty($_REQUEST['back'])) {
            $this->backend->buttonAdd('button_back', "history.back()");
        }
        else {
            $this->backend->buttonAdd('button_close', "cApp.layers.close()");
        }
        echo $this->backend->buttonBar();

        // Reiter: Info | Änderungen (Audit-Log, Backend::infoAuditTabs())
        if ($this->backend->infoAuditTabs("Instance/instance_info.php?id={$this->id}", 'instance', $this->id)) return;

        // Data - in den Abschnitten der Eingabemaske (edit_form.html)
        // --------------------
        $languages = $this->_languageOptions();
        $instances = $this->_instanceOptions();
        $details = is_array($this->data['details'] ?? null) ? $this->data['details'] : (json_decode((string)($this->data['details'] ?? ''), true) ?: []);
        $yesNo = fn($value) => $this->cms->snippet(!empty($value) ? 'yes' : 'no');
        $section = function (string $headline) {
            echo $this->backend->infoTable();
            $this->backend->infoReset();
            echo $this->backend->template('headline4_mt', ['{text}' => $this->cms->snippet($headline)]);
        };

        $this->backend->infoReset();
        $this->backend->infoAdd($this->instance, 'label', $this->cms->textOrSnippet($this->data['label']), ['primary']);
        $this->backend->infoAdd($this->instance, 'position', $this->_positionLabel());

        // Sprachen
        $section('instance.headline_languages');
        $this->backend->infoAdd(
            $this->instance,
            'primary_language_id',
            $languages[(int)$this->data['primary_language_id']] ?? ''
        );
        // Die Sprach-Ids in lesbare Namen übersetzen (backendValue gibt die Liste aus)
        $this->backend->infoAdd(
            $this->instance,
            'additional_languages',
            $this->_translateIds($this->data['additional_languages'], $languages)
        );

        // Domains
        $section('instance.headline_domains');
        $this->backend->infoAdd($this->instance, 'primary_domain', (string)($this->data['primary_domain'] ?? ''));
        $this->backend->infoAdd($this->instance, 'protocol', (string)($this->data['protocol'] ?? ''));
        $this->backend->infoAdd($this->instance, 'canonical', $yesNo($this->data['canonical'] ?? 0));
        if ($this->id === Instance::DEFAULT_ID) {
            // Standard-Instanz - immer ".*" (wie im Formular)
            $this->backend->infoAdd($this->instance, 'additional_domains', $this->backend->template('instance.all_other_domains'), ['html']);
        }
        else {
            // je Domain eine Zeile; reguläre Ausdrücke mit Kennzeichnung "RegEx"
            $regex = array_values((array)($this->data['regex'] ?? []));
            $domains = [];
            foreach (array_values((array)($this->data['domain'] ?? [])) as $i => $domain) {
                $domains[] = (string)$domain . (!empty($regex[$i]) ? ' (' . $this->cms->snippet('instance.field_regex') . ')' : '');
            }
            $this->backend->infoAdd($this->instance, 'additional_domains', $domains);
        }

        // Zugriff
        $section('instance.headline_access');
        $this->backend->infoAdd(
            $this->instance,
            'readable_instances',
            $this->_translateIds($this->data['readable_instances'], $instances)
        );

        // Script-Policy des Frontends (19-csp.md §9) - ohne eigene Angabe die Vorgabe
        // der Konfiguration, wie in der Auswahl der Maske
        $section('instance.headline_script_policy');
        $mode = $this->cms->snippet('instance.option_csp_' . $this->securityHeaders->frontendCspMode(['details' => $details]));
        if ((string)($details['csp'] ?? '') === '') {
            $mode = str_replace('{mode}', $mode, $this->cms->snippet('instance.option_csp_default'));
        }
        $this->backend->infoAdd($this->instance, 'details.csp', $mode);
        $this->backend->infoAdd($this->instance, 'details.csp_script_sources', (string)($details['csp_script_sources'] ?? ''));
        $this->backend->infoAdd($this->instance, 'details.csp_strict_dynamic', $yesNo($details['csp_strict_dynamic'] ?? 0));
        $this->backend->infoAdd($this->instance, 'details.csp_form_action', $yesNo($details['csp_form_action'] ?? 0));
        if (!empty($details['csp_form_action'])) {
            $this->backend->infoAdd($this->instance, 'details.csp_form_sources', "'self' " . str_replace("\n", ' ', (string)($details['csp_form_sources'] ?? '')));
        }

        // Startseite
        $section('instance.headline_start_page');
        $recordId = (int)($this->data['record_id'] ?? 0);
        $templateId = (int)($this->data['template_id'] ?? 0);
        $html = $recordId > 0 ? $this->backend->teaser('record', $recordId) : '';
        $this->backend->infoAdd($this->instance, 'record_id', $html, $html !== '' ? ['html'] : []);
        $html = $templateId > 0 ? $this->backend->teaser('template', $templateId) : '';
        $this->backend->infoAdd($this->instance, 'template_id', $html, $html !== '' ? ['html'] : []);

        echo $this->backend->infoTable();

        echo $this->backend->template('container_footer');
    }

    /**
     * Erstellen / kopieren / ändern / löschen (backend/instance_edit.php).
     *
     * Zustandsautomat über $_REQUEST['function'] wie in den anderen Modulen
     * (docs/developers/03-backend-entwicklung.md).
     */
    public function edit(): void
    {
        // Seiten-Parameter
        $this->id = (int)($_REQUEST['id'] ?? 0);
        $this->function = (string)($_REQUEST['function'] ?? '');
        if ($this->function == '') $this->function = 'edit';
        $this->data = (array)($_REQUEST['data'] ?? []);
        $this->data['instance_id'] = $this->id;

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

        // H1
        echo $this->_editHeadline();

        if ($this->function == 'save') {
            $this->_editSave();
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
        if (in_array($this->function, ['create', 'edit', 'copy', 'errors', 'confirm'], true)) {
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

    private function _editCreate(): void
    {
        $this->data = $this->instance->init();
        $this->id = 0;
    }

    /**
     * function=edit|copy|delete: Instance laden.
     *
     * Beim Kopieren wird die Id verworfen (save() legt dadurch einen neuen Datensatz
     * an) und die Position um eins erhöht, damit die Kopie hinter dem Original steht.
     * Die Startseite wird dabei NICHT mitkopiert: Datensatz und Template gehören zu
     * den Inhalten der Vorlage, nicht zur neuen Website.
     */
    private function _editLoad(): void
    {
        $this->data = $this->instance->load($this->id);
        if (!$this->data) {
            // Angeforderter Datensatz nicht gefunden
            $this->function = 'skip';
            $this->data = [];
            return;
        }
        // Wenn Daten kopiert werden sollen, dann jetzt Id zurücksetzen
        if ($this->function == 'copy') {
            $this->id = 0;
            $this->data['instance_id'] = 0;
            $this->data['position']++;
            // Domains sind je Website eindeutig - die Kopie startet ohne sie
            $this->data['primary_domain'] = '';
            $this->data['domain'] = [];
            $this->data['regex'] = [];
            $this->data['additional_domains'] = [];
            // Startseite gehört zu den Inhalten der Vorlage
            $this->data['record_id'] = 0;
            $this->data['template_id'] = 0;
        }
    }

    /**
     * Überschrift für Formular und Detailansicht. Beim Löschen und in der
     * Detailansicht steht die Instance selbst darüber, sonst die allgemeine
     * Überschrift.
     */
    private function _editHeadline(): string
    {
        if (in_array($this->function, ['delete', 'info'], true)) {
            $labelText = $this->cms->textOrSnippet($this->data['label'] ?? '');
            $labelHtml = $this->backend->template('instance.icon_instance') . ' ' . $labelText;
        }
        else {
            // 'errors'/'skip' sind Folgezustände von 'save' bzw. eines fehlgeschlagenen
            // Ladens - sie tragen keine eigene Überschrift
            $headlines = [
                'create' => 'instance.headline_instance_create',
                'copy'   => 'instance.headline_instance_copy',
                'edit'   => 'instance.headline_instance_edit',
            ];
            $labelHtml = $this->cms->snippet(
                $headlines[$this->function] ?? 'instance.headline_instance'
            );
        }
        return $this->backend->template('headline1', [
            '{text}' => $labelHtml
        ]);
    }

    /**
     * function=save: speichern. $fields leer = alle Schema-Felder aus $data.
     *
     * Fallen Sprachen weg, verschwinden ihre Inhalte (LanguageData::removeLanguage()).
     * Das geschieht erst nach einem Zwischenschritt: das Formular erscheint noch
     * einmal mit der Warnung (function=confirm) und trägt die bestätigten Sprachen im
     * Hiddenfeld `confirm_remove_languages`; erst wenn genau diese Sprachen wegfallen,
     * wird gespeichert.
     */
    private function _editSave(): void
    {
        // Prüfe Daten und speichere
        $fields = [];
        $this->data['instance_id'] = $this->id;

        $removed = $this->_removedLanguages();
        if (!empty($removed)
            && (string)($_REQUEST['confirm_remove_languages'] ?? '') !== implode(',', array_keys($removed))
        ) {
            $this->db->query(
                "SELECT COUNT(*) FROM `record` WHERE `instance_id` = ? AND `tmp` = 0 AND `deleted` = 0",
                'i', [$this->id]
            );
            $count = (int)($this->db->fetchNum()[0] ?? 0);
            echo $this->backend->template('alert_warning', [
                '{text}' => '<strong>{#instance.alert_remove_languages}</strong>'
                    . '<ul class="mt-1 mb-1"><li>' . implode('</li><li>', array_map('htmlspecialchars', $removed)) . '</li></ul>'
                    . str_replace('{count}', (string)$count, $this->cms->snippet('instance.alert_remove_languages_note')),
            ]);
            $this->function = 'confirm';
            return;
        }

        if ($this->instance->save($fields, $this->data)) {
            // Balloon Message
            $msg = $this->cms->snippet('instance.message_instance_saved');
            $this->backend->balloon('success', $msg);

            // Referer aktualisieren und Fenster schließen
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
     * function=delete_confirm: löschen.
     *
     * Endgültig und unwiderruflich, samt aller Inhalte der Website - siehe
     * Instance::hookOnDelete().
     */
    private function _editDeleteConfirm(): void
    {
        if ($this->instance->delete($this->id)) {
            // Balloon Message
            $msg = $this->cms->snippet('instance.message_instance_deleted');
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
     * Bei der Position wählt der Redakteur den VORGÄNGER, der Optionsschlüssel ist
     * dessen position + 1.
     * Die "Default"-Instanz ist immer am Ende, da sie alle Domains auffängt, die
     * von den anderen nicht abgedeckt werden.
     *
     * Die weiteren Domains sind kein reguläres Formularfeld: je Domain steht eine
     * Zeile mit zwei Eingaben (data[domain][] und data[regex][]) im Formular. Sie
     * werden hier über den Platzhalter {domain_items} eingesetzt, den jede Zeile
     * wieder hinter sich neu ablegt - so bleibt die Reihenfolge erhalten. Verschieben,
     * Anfügen und Entfernen übernimmt cApp.smartrows im Browser.
     */
    private function _editForm(): void
    {
        if (in_array($this->function, ['save', 'confirm'], true)) $changed = ' data-changed="true"';
        else $changed = '';
        echo '<form action="Instance/instance_edit.php" method="post"' . $changed . '>';

        // Load form
        $this->backend->formInit('instance.edit_form');

        // label
        // --------------------
        $this->backend->formField($this->instance, [
            'id' => $this->id,
            'name' => 'label',
            'value' => $this->data['label'] ?? '',
            'primary' => true,
        ]);

        // position
        // --------------------
        // Der Redakteur wählt den Vorgänger; gespeichert wird dessen position + 1.
        // Die Standard-Instance steht unabhängig von deren Position (0) immer am Ende
        // kann also nicht als Vorgänger gewählt werden
        if ($this->id === 1) {
            $options = [1 => $this->cms->snippet('position_at_end')];
        }
        else {
            $options = [1 => $this->cms->snippet('position_at_start')];
            $this->db->query(
                "SELECT `instance_id`, `label`, `position` FROM `instance`"
                . " WHERE `instance_id` != ? AND `instance_id` != ?"
                . " ORDER BY `position` ASC",
                'ii',
                [$this->id, Instance::DEFAULT_ID]
            );
            while ($option = $this->db->fetch()) {
                $position = (int)$option['position'] + 1;
                $options[$position] = $this->cms->textOrSnippet($option['label']);
            }
        }
        $this->backend->formField($this->instance, [
            'id' => $this->id,
            'name' => 'position',
            'value' => $this->data['position'] ?? 0,
            'options' => $options,
        ]);

        // languages
        // --------------------
        $languageOptions = $this->_languageOptions();

        // primary_language_id
        $this->backend->formField($this->instance, [
            'id' => $this->id,
            'name' => 'primary_language_id',
            'value' => $this->data['primary_language_id'] ?? 0,
            'options' => $languageOptions,
        ]);

        // additional_languages (display_type 'sortable')
        $this->backend->formField($this->instance, [
            'id' => $this->id,
            'name' => 'additional_languages',
            'value' => (array)($this->data['additional_languages'] ?? []),
            'options' => $languageOptions,
        ]);

        // domains
        // --------------------

        // primary_domain
        $this->backend->formField($this->instance, [
            'id' => $this->id,
            'name' => 'primary_domain',
            'value' => $this->data['primary_domain'] ?? '',
        ]);

        // protocol
        $this->backend->formField($this->instance, [
            'id' => $this->id,
            'name' => 'protocol',
            'value' => $this->data['protocol'] ?? 'https',
            'options' => ['https' => 'https', 'http' => 'http'],
        ]);

        // canonical
        $this->backend->formField($this->instance, [
            'id' => $this->id,
            'name' => 'canonical',
            'value' => $this->data['canonical'] ?? 1,
        ]);

        // additional domains
        if ($this->id === Instance::DEFAULT_ID) {
            // Standard-Instanz - immer ".*"
            $snippetAllDomains = $this->backend->template('instance.all_other_domains');
            $this->backend->formStatic($this->instance, 'additional_domains', $snippetAllDomains);
        }
        else {
            // alle anderen Instanzen erlauben mehrere Werte mit Domain und Flag "regex"
            $this->backend->formReplace('{data_additional_domains}',
                $this->backend->template('instance.edit_form_domain_list')
            );
            $domains = (array)($this->data['domain'] ?? []);
            $regex   = (array)($this->data['regex'] ?? []);
            foreach (array_values($domains) as $i => $domain) {
                $tplDomainTableRow = $this->backend->template('instance.edit_form_domain_item', [
                    '{domain}' => htmlspecialchars((string)$domain),
                    '{regex_checked}' => !empty($regex[$i]) ? ' checked' : ''
                ]);
                $this->backend->formReplace('{domain_items}', $tplDomainTableRow . "\n" . '{domain_items}');
            }
            // Letzte Zeile bleibt leer und kann gleich ausgefüllt werden.
            $tplDomainTableRow = $this->backend->template('instance.edit_form_domain_item', [
                '{domain}' => '',
                '{regex_checked}' => ''
            ]);
            $this->backend->formReplace('{domain_items}', $tplDomainTableRow);
        }

        // access
        // --------------------

        // readable_instances
        $this->backend->formField($this->instance, [
            'id' => $this->id,
            'name' => 'readable_instances',
            'value' => (array)($this->data['readable_instances'] ?? []),
            'options' => $this->_instanceOptions(),
        ]);

        // Script-Policy des Frontends (19-csp.md §9)
        // --------------------
        $details = is_array($this->data['details'] ?? null) ? $this->data['details'] : (json_decode((string)($this->data['details'] ?? ''), true) ?: []);
        $default = (string)($this->config->get('security.frontend_csp') ?? 'off');
        $cspOptions = ['' => str_replace('{mode}', $this->cms->snippet('instance.option_csp_' . (in_array($default, SecurityHeaders::FRONTEND_MODES, true) ? $default : 'off')), $this->cms->snippet('instance.option_csp_default'))];
        foreach (SecurityHeaders::FRONTEND_MODES as $mode) {
            $cspOptions[$mode] = $this->cms->snippet('instance.option_csp_' . $mode);
        }
        $this->backend->formField($this->instance, [
            'id' => $this->id,
            'name' => 'details.csp',
            'value' => (string)($details['csp'] ?? ''),
            'options' => $cspOptions,
        ]);
        foreach (['csp_script_sources', 'csp_strict_dynamic', 'csp_form_action', 'csp_form_sources'] as $key) {
            $this->backend->formField($this->instance, [
                'id' => $this->id,
                'name' => 'details.' . $key,
                'value' => $details[$key] ?? '',
            ]);
        }

        // start page
        // --------------------
        // Zwei Lookup-Felder auf FREMDE Tabellen (`record` und `template`) - dasselbe
        // Verfahren wie beim Standard-Template eines Bereichs (AreaBackend). Die
        // Auswahl kommt aus der jeweiligen Trefferliste im nächsten Layer; der
        // Vorschaublock trägt das versteckte Feld, das mit dem Formular abgeht.
        //
        // Beides bleibt auf die Inhalte DIESER Website begrenzt:
        //     Datensätze  über filter[instance_id]
        //     Templates   über filter[area_id][] - die Template-Container der Instanz
        $this->_editFormStartPage();

        // function
        $this->backend->formHidden('function', 'save');
        $this->backend->formHidden('id', $this->id);
        if ($this->function === 'confirm') {
            $this->backend->formHidden('confirm_remove_languages', implode(',', array_keys($this->_removedLanguages())));
        }

        // Print form
        echo $this->backend->form('form_save_cancel_note');

        echo '</form>';
    }

    /**
     * function=delete: Löschbestätigung.
     *
     * Die Inhalte der Website verschwinden mit ihr. Deshalb steht die Aufstellung
     * dessen im Formular, was verloren geht.
     */
    private function _editDeleteForm(): void
    {
        $count = $this->instance->contentCount($this->id);

        echo '<form action="Instance/instance_edit.php" method="post">';

        $this->backend->formInit('instance.delete_form', [
            '{count_areas}' => (string)$count['areas'],
            '{count_fields}' => (string)$count['fields'],
            '{count_records}' => (string)$count['records'],
            '{count_templates}' => (string)$count['templates'],
        ]);

        $this->backend->formHidden('id', $this->id);
        $this->backend->formHidden('function', 'delete_confirm');

        // Print form
        echo $this->backend->form('form_delete_cancel');

        echo '</form>';
    }

    /**
     * Sprachen, die beim Speichern aus der Instanz wegfallen: bisherige Haupt- und
     * weitere Sprachen laut Datenbank, die im Formular nicht mehr vorkommen.
     *
     * @return array  language_id => "code | Label", nach language_id sortiert
     */
    private function _removedLanguages(): array
    {
        if ($this->id < 1) return [];
        $this->db->query(
            "SELECT `primary_language_id` FROM `instance` WHERE `instance_id` = ?"
            . " UNION SELECT `language_id` FROM `rel_instance_language` WHERE `instance_id` = ?",
            'ii', [$this->id, $this->id]
        );
        $old = array_map('intval', $this->db->fetchAll());
        $new = array_map('intval', array_merge(
            [$this->data['primary_language_id'] ?? 0],
            (array)($this->data['additional_languages'] ?? [])
        ));
        $removed = array_values(array_diff($old, $new));
        sort($removed);
        $options = $this->_languageOptions();
        $result = [];
        foreach ($removed as $languageId) {
            $result[$languageId] = $options[$languageId] ?? ('#' . $languageId);
        }
        return $result;
    }

    // ====================
    // Auswahllisten

    /**
     * Sprachversionen als key-value-Pairs
     */
    private function _languageOptions(): array
    {
        $this->db->pushResult();
        $this->db->query("SELECT `language_id`, `code`, `label` FROM `language` ORDER BY `position` ASC");
        $options = [];
        while ($option = $this->db->fetch()) {
            $options[(int)$option['language_id']] =
                $option['code'] . ' | ' . $this->cms->textOrSnippet($option['label']);
        }
        $this->db->popResult();
        return $options;
    }

    /**
     * Instanzen, für readable_instances
     * (lesbare andere Instanzen, die aktuelle also ausgeschlossen)
     * Default-Instanz immer am Ende
     */
    private function _instanceOptions(): array
    {
        $sql = "SELECT `instance_id`, `label` FROM `instance`"
            . " WHERE `instance_id` != ? AND `instance_id` != ?"
            . " ORDER BY `position` ASC";
        $this->db->query($sql, 'ii', [$this->id, Instance::DEFAULT_ID]);
        $options = [];
        foreach ($this->db->fetchPairs() as $instanceId => $label) {
            $options[(int)$instanceId] = $this->cms->textOrSnippet($label);
        }
        if ($this->id > 1) $options[1] = 'Default';
        
        return $options;
    }

    /**
     * Die beiden Lookup-Felder der Startseite ins Formular setzen.
     *
     * Aufbau wie bei jedem Lookup auf eine fremde Tabelle (siehe
     * AreaBackend::_editForm(), Feld default_template_id):
     *
     *     {search_url}   Trefferliste, aus der gewählt wird
     *     {update_url}   Ajax-Endpunkt, der den Vorschaublock neu rendert
     *     {data_ids}     aktuell gewählte Id (cApp.ui merkt sie sich im data-Attribut)
     *     {preview}      der Vorschaublock samt verstecktem Eingabefeld
     *
     * Bei einer noch nicht gespeicherten Instanz ($id < 1) gibt es keine Inhalte, aus
     * denen sich eine Startseite wählen ließe - die Felder erscheinen dann als
     * Hinweis statt als Auswahl.
     */
    private function _editFormStartPage(): void
    {
        if ($this->id < 1) {
            $note = $this->backend->template('instance.note_start_page_new');
            $this->backend->formStatic($this->instance, 'record_id', $note);
            $this->backend->formStatic($this->instance, 'template_id', $note);
            return;
        }

        // record_id
        // --------------------
        $recordId = (int)($this->data['record_id'] ?? 0);
        $this->backend->formField($this->instance, [
            'id' => $this->id,
            'name' => 'record_id',
            'value' => $recordId,
            'template' => 'edit_form_lookup_foreign_table',
            // Das Kontextmenü hinter einem Lookup-Feld arbeitet mit der field_id eines
            // Verknüpfungsfeldes - die gibt es hier nicht (fremde Tabelle).
            'contextMenu' => '',
        ]);
        $urlSuffix = "src_field=record_id&filter[instance_id]={$this->id}";
        $this->backend->formReplace('{src_id}', (string)$this->id);
        $this->backend->formReplace('{src_field}', 'record_id');
        $this->backend->formReplace('{search_url}', "Record/record_list.php?" . $urlSuffix);
        $this->backend->formReplace('{update_url}', "Record/ajax_ext_lookup.php?" . $urlSuffix);
        $this->backend->formReplace('{data_ids}', $recordId > 0 ? (string)$recordId : '');
        $this->backend->formReplace('{max}', '1');
        $this->backend->formReplace(
            '{preview}',
            $this->recordBackend->extLookupPreviewCreate('record_id', [$recordId], 'data', 1)
        );

        // template_id
        // --------------------
        $templateId = (int)($this->data['template_id'] ?? 0);
        $this->backend->formField($this->instance, [
            'id' => $this->id,
            'name' => 'template_id',
            'value' => $templateId,
            'template' => 'edit_form_lookup_foreign_table',
            'contextMenu' => '',
        ]);
        // Nur Templates aus den Template-Containern dieser Website
        $areaFilter = '';
        foreach ($this->_templateAreaIds() as $areaId) {
            $areaFilter .= '&filter[area_id][]=' . $areaId;
        }
        if ($areaFilter === '') {
            // Kein Template-Container - ein Suchaufruf ohne Einschränkung zeigte sonst
            // die Templates aller Websites. Eine unerfüllbare area_id sperrt die Liste.
            $areaFilter = '&filter[area_id][]=0';
        }
        $urlSuffix = "src_field=template_id" . $areaFilter;
        $this->backend->formReplace('{src_id}', (string)$this->id);
        $this->backend->formReplace('{src_field}', 'template_id');
        $this->backend->formReplace('{search_url}', "Template/template_list.php?" . $urlSuffix);
        $this->backend->formReplace('{update_url}', "Template/ajax_lookup_preview.php?src_field=template_id");
        $this->backend->formReplace('{data_ids}', $templateId > 0 ? (string)$templateId : '');
        $this->backend->formReplace('{max}', '1');
        $this->backend->formReplace(
            '{preview}',
            $this->templateBackend->lookupPreviewCreate('template_id', [$templateId], 'data', 1)
        );
    }

    /**
     * area_ids der Template-Container dieser Website.
     */
    private function _templateAreaIds(): array
    {
        $sql = "SELECT `area_id` FROM `area`"
            . " WHERE `instance_id` = ? AND `type` = 'template' AND `deleted` = 0";
        $this->db->pushResult();
        $this->db->query($sql, 'i', [$this->id]);
        $ids = array_map('intval', $this->db->fetchColumn());
        $this->db->popResult();
        return $ids;
    }

    /**
     * Position einer Instanz für die Info-Seite - wie die Auswahl "Position nach..."
     * der Maske: Bezeichnung des Vorgängers, "(an den Anfang)" bzw. bei der
     * Standard-Instanz "(ans Ende)".
     */
    private function _positionLabel(): string
    {
        if ($this->id === Instance::DEFAULT_ID) return $this->cms->snippet('position_at_end');
        $this->db->query(
            "SELECT `label` FROM `instance` WHERE `position` < ? AND `instance_id` != ? AND `instance_id` != ?"
            . " ORDER BY `position` DESC LIMIT 1",
            'iii',
            [(int)($this->data['position'] ?? 0), $this->id, Instance::DEFAULT_ID]
        );
        $label = $this->db->fetchNum()[0] ?? null;
        return $label === null ? $this->cms->snippet('position_at_start') : $this->cms->textOrSnippet((string)$label);
    }

    /**
     * Id-Liste in die zugehörigen Beschriftungen übersetzen.
     * Für die Detailansicht: Backend::backendValue() gibt bei 'reltable' die Werte
     * des Arrays aus - und das sind ohne diesen Schritt nur Zahlen.
     *
     * @param mixed $ids      Id-Liste aus dem Datensatz
     * @param array $options  Id => Beschriftung
     */
    private function _translateIds(mixed $ids, array $options): array
    {
        $labels = [];
        foreach ((array)$ids as $id) {
            $labels[] = $options[(int)$id] ?? ('#' . (int)$id);
        }
        return $labels;
    }
}
