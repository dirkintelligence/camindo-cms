<?php
/**
 * Backend-Oberfläche für die zeitgesteuerten Aufgaben (Tabelle `scheduler`).
 *
 * Einstiegspunkte (Scripte in backend/):
 *
 *     scheduler_list.php    -> list()     Aufgabenliste mit nächstem und letztem Lauf
 *     scheduler_info.php    -> info()     Detailansicht
 *     scheduler_create.php  -> create()   Schritt 1: Aufgabentyp wählen
 *     scheduler_edit.php    -> edit()     Schritt 2 und alles weitere
 *     scheduler_run.php     -> run()      "Jetzt ausführen" - als Hintergrundprozess
 *     scheduler_toggle.php  -> toggle()   deaktivieren / reaktivieren (vorübergehend
 *                                         aussetzen, Einstellungen bleiben)
 *
 * Aufbau wie StatusBackend (kurze Liste ohne Suche) mit der Typauswahl vorweg wie im
 * Modul Area: der Typ bestimmt die Eingabefelder und steht danach fest.
 *
 * Die Zeiten (nächster/letzter Lauf) stehen in der Zeitzone des Systems, in der der
 * Cronjob läuft - sie werden so angezeigt, mit dem Namen der Zeitzone daneben.
 *
 * @see Camindo\Core\Modules\Scheduler\Scheduler        Dataset/Model
 * @see Camindo\Core\Modules\Scheduler\SchedulerRunner  Ausführung
 */
namespace Camindo\Core\Modules\Scheduler;

use Camindo\Core\Backend;
use Camindo\Core\Backup;
use Camindo\Core\BackgroundProcess;
use Camindo\Core\Config;
use Camindo\Core\Crypt;
use Camindo\Core\Database;
use Camindo\Core\Cms;
use Camindo\Core\IncludeCache;
use Camindo\Core\System;

class SchedulerBackend {
    protected int $id = 0;
    protected ?array $data = [];
    protected string $function = '';

    public function __construct(
        private Backend $backend,
        private Database $db,
        private Cms $cms,
        private Crypt $crypt,
        private Config $config,
        private System $system,
        private BackgroundProcess $bgproc,
        private Scheduler $scheduler,
        private SchedulerRunner $runner,
        private Backup $backup,
        private IncludeCache $includeCache
    )
    {}

    /**
     * Aufgabenliste (backend/scheduler_list.php).
     */
    public function list(): void
    {
        $this->backend->setNaviLabel('#scheduler.module_label');
        echo $this->backend->template('container_header');
        echo $this->backend->headline('#scheduler.headline_scheduler_list');

        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_create_primary', "cApp.layers.add('Scheduler/scheduler_create.php');");
        $this->backend->buttonAdd('button_refresh', "cApp.layers.refresh();");
        echo $this->backend->buttonBar();

        echo $this->backend->template('p', [
            '{text}' => '<small class="c9-grey-50">' . str_replace('{timezone}', htmlspecialchars($this->scheduler->systemTimezone()->getName()), $this->cms->snippet('scheduler.note_timezone')) . '</small>'
        ]);

        $this->cms->select([
            'request' => 'all',
            'limit' => -1,
            'filtertext' => 'true'
        ], $this->scheduler);

        echo $this->backend->paginationTopShort();

        if ($this->db->num_rows > 0) {
            $tplTableRow = $this->backend->template('scheduler.table_row');
            $tableRows = '';
            while ($data = $this->scheduler->fetch()) {
                $tableRows .= strtr($tplTableRow, [
                    '{data_id}' => (int)$data['scheduler_id'],
                    '{name}' => htmlspecialchars($this->cms->textOrSnippet((string)$data['label'])),
                    '{data_label}' => htmlspecialchars($this->cms->textOrSnippet((string)$data['label'])),
                    '{data_type}' => $this->cms->snippet('scheduler.label_type_' . $data['type']),
                    '{data_enabled}' => $this->_enabledText($data),
                    '{ctx_hide}' => !empty($data['enabled']) ? 'enable' : 'disable',
                    '{data_cron}' => $this->_cronText($data),
                    '{data_next_run}' => $this->_dateText((string)($data['next_run'] ?? '')),
                    '{data_last_run}' => $this->_statusIcon((int)$data['last_status']) . ' ' . $this->_dateText((string)($data['last_run'] ?? '')),
                ]) . "\n";
            }
            echo $this->backend->template('scheduler.table_with_head', [
                '{head_columns}' => $this->backend->template('scheduler.table_head_columns'),
                '{table_rows}' => $tableRows
            ]);
            echo $this->backend->template('scheduler.context_menu');
        }

        echo $this->backend->template('container_footer');
    }

    /**
     * Detailansicht (backend/scheduler_info.php).
     */
    public function info(): void
    {
        $id = (int)($_REQUEST['id'] ?? 0);
        $this->backend->setNaviLabel('#button_info');
        echo $this->backend->template('container_header');

        if (!$data = $this->scheduler->load($id)) {
            echo $this->backend->template('alert_danger', ['{text}' => $this->cms->errorMsg()]);
            echo $this->backend->template('container_footer');
            return;
        }
        $this->id = $id;
        $this->data = $data;
        $this->function = 'info';
        $type = (string)$data['type'];
        echo $this->_editHeadline();

        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_edit_primary', "cApp.layers.replace('Scheduler/scheduler_edit.php?id={$id}');");
        $this->backend->buttonAdd('button_copy', "cApp.layers.replace('Scheduler/scheduler_edit.php?id={$id}&function=copy');");
        $this->backend->buttonAdd('button_delete', "cApp.layers.replace('Scheduler/scheduler_edit.php?id={$id}&function=delete');");
        $this->backend->buttonAdd('scheduler.button_run', "cApp.layers.replacePost('Scheduler/scheduler_run.php?id={$id}');");
        if (!empty($data['enabled'])) {
            $this->backend->buttonAdd('scheduler.button_disable', "cApp.layers.replacePost('Scheduler/scheduler_toggle.php?id={$id}&enabled=0');");
        }
        else {
            $this->backend->buttonAdd('scheduler.button_enable', "cApp.layers.replacePost('Scheduler/scheduler_toggle.php?id={$id}&enabled=1');");
        }
        if (!empty($_REQUEST['back'])) {
            $this->backend->buttonAdd('button_back', "history.back()");
        }
        else {
            $this->backend->buttonAdd('button_close', "cApp.layers.close()");
        }
        echo $this->backend->buttonBar();

        // Reiter: Info | Änderungen (Audit-Log, Backend::infoAuditTabs())
        if ($this->backend->infoAuditTabs("Scheduler/scheduler_info.php?id={$id}", 'scheduler', $id)) return;

        $yes = $this->backend->template('icon_checked');
        $no = $this->backend->emptyPlaceholder;
        $details = (array)($data['details'] ?? []);

        $this->backend->infoReset();
        // Beschriftung: Text oder Snippet (#prefix.key, etwa die Vorgabe-Aufgaben)
        $this->backend->infoAdd($this->scheduler, 'label', $this->cms->textOrSnippet((string)$data['label']), ['primary']);
        $this->backend->infoAdd($this->scheduler, 'type', $this->cms->snippet('scheduler.label_type_' . $type));
        $adminId = (int)($data['admin_id'] ?? 0);
        $adminError = $this->scheduler->adminError($adminId);
        $this->backend->infoAdd($this->scheduler, 'admin_id',
            ($adminId > 0 ? $this->backend->teaser('admin', $adminId) : '')
            . ($adminError !== null ? ' ' . $this->backend->template('alert_danger', ['{text}' => $this->cms->snippet($adminError)]) : ''),
            ['html']);
        $this->backend->infoAdd($this->scheduler, 'enabled', $this->_enabledText($data), ['html']);
        $this->backend->infoAdd($this->scheduler, 'max_minutes', (int)$data['max_minutes'] . ' ' . $this->cms->snippet('scheduler.unit_minutes'));
        $this->backend->infoAdd($this->scheduler, 'log', !empty($data['log']) ? $yes : $no, ['html']);
        $this->backend->infoStatic($this->cms->snippet('scheduler.headline_cron'), $this->_cronText($data), ['monospace']);
        foreach (array_keys(Scheduler::CRON_FIELDS) as $name) {
            $this->backend->infoAdd($this->scheduler, $name, htmlspecialchars((string)$data[$name]), ['html', 'monospace']);
        }

        if ($type === 'url') {
            $this->backend->infoAdd($this->scheduler, 'details.url', (string)($details['url'] ?? ''));
            $this->backend->infoAdd($this->scheduler, 'details.auth', !empty($details['auth']) ? $yes : $no, ['html']);
            if (!empty($details['auth'])) {
                $this->backend->infoAdd($this->scheduler, 'details.user', (string)($details['user'] ?? ''));
                $this->backend->infoAdd($this->scheduler, 'details.password', ((string)($details['password'] ?? '') !== '') ? '&bull;&bull;&bull;&bull;&bull;&bull;' : $no, ['html']);
            }
        }
        elseif ($type === 'php') {
            $this->backend->infoAdd($this->scheduler, 'details.script', 'var/custom/' . Scheduler::SCRIPT_DIR . '/' . (string)($details['script'] ?? ''));
        }
        elseif ($type === 'dbbackup') {
            $this->backend->infoAdd($this->scheduler, 'details.backups', (int)($details['backups'] ?? 0));
            $this->backend->infoStatic($this->cms->snippet('scheduler.label_backup_files'), $this->_backupFiles());
        }
        elseif ($type === 'logcleanup') {
            $days = ' ' . $this->cms->snippet('scheduler.unit_days');
            $this->backend->infoAdd($this->scheduler, 'details.log_days', (int)($details['log_days'] ?? 90) . $days);
            $this->backend->infoAdd($this->scheduler, 'details.security_days', (int)($details['security_days'] ?? 365) . $days);
            $this->backend->infoAdd($this->scheduler, 'details.attempt_days', (int)($details['attempt_days'] ?? 30) . $days);
        }
        elseif ($type === 'fulltext') {
            $areas = $this->scheduler->fulltextAreas();
            $labels = [];
            foreach ((array)($details['area_ids'] ?? []) as $areaId) {
                $labels[] = $areas[(int)$areaId] ?? ('#' . (int)$areaId);
            }
            $this->backend->infoAdd($this->scheduler, 'details.area_ids',
                $labels ? implode(', ', $labels) : $this->cms->snippet('scheduler.option_all_areas'));
            $this->backend->infoAdd($this->scheduler, 'details.only_stale',
                $this->cms->snippet(!empty($details['only_stale']) ? 'yes' : 'no'));
        }
        elseif ($type === 'cachecleanup') {
            $stats = $this->includeCache->stats();
            $this->backend->infoStatic($this->cms->snippet('scheduler.label_cache_files'),
                $stats['files'] . ' (' . $this->system->convertSize((float)$stats['bytes']) . ')');
        }
        elseif ($type === 'template') {
            $templates = $this->scheduler->schedulerTemplates();
            $templateId = (int)($details['template_id'] ?? 0);
            $template = $templates[$templateId] ?? null;
            $this->backend->infoAdd($this->scheduler, 'details.template_id', $template['label'] ?? ('#' . $templateId));
            $languageId = (int)($details['language_id'] ?? 0);
            $this->backend->infoAdd($this->scheduler, 'details.language_id',
                $languageId > 0 ? (string)($template['languages'][$languageId] ?? $languageId) : $this->cms->snippet('scheduler.option_language_primary'));
            $windowEnd = (string)($details['window_end'] ?? '');
            $this->backend->infoStatic($this->cms->snippet('scheduler.label_window_end'),
                $windowEnd !== '' ? $windowEnd . ' UTC' : $this->backend->emptyPlaceholder);
        }
        if (in_array($type, ['url', 'php', 'template'], true)) {
            $this->backend->infoAdd($this->scheduler, 'details.output', $this->_outputText($details));
        }
        if (in_array($type, Scheduler::REPORT_TYPES, true)) {
            $this->backend->infoAdd($this->scheduler, 'details.report', !empty($details['report']) ? $yes : $no, ['html']);
            if (!empty($details['report'])) {
                $groups = $this->scheduler->groupOptions();
                $labels = [];
                foreach ((array)($details['report_group_ids'] ?? []) as $groupId) {
                    $labels[] = $groups[(int)$groupId] ?? ('#' . (int)$groupId);
                }
                $this->backend->infoAdd($this->scheduler, 'details.report_group_ids', implode(', ', $labels));
                $this->backend->infoStatic($this->cms->snippet('scheduler.label_report_subscribers'),
                    (string)count($this->scheduler->reportSubscribers($data)));
            }
        }

        $tz = ' <small class="c9-grey-50">' . htmlspecialchars($this->scheduler->systemTimezone()->getName()) . '</small>';
        $this->backend->infoAdd($this->scheduler, 'next_run', $this->_dateText((string)($data['next_run'] ?? '')) . $tz, ['html']);
        $this->backend->infoAdd($this->scheduler, 'last_run', $this->_dateText((string)($data['last_run'] ?? '')) . $tz, ['html']);
        $this->backend->infoAdd($this->scheduler, 'last_status', $this->_statusIcon((int)$data['last_status']) . ' ' . $this->cms->snippet('scheduler.status_' . (int)$data['last_status']), ['html']);
        $this->backend->infoAdd($this->scheduler, 'last_message', (string)($data['last_message'] ?? ''), ['ifnotempty']);
        $this->backend->infoAdd($this->scheduler, 'scheduler_id', $id);
        echo $this->backend->infoTable();

        echo $this->backend->template('container_footer');
    }

    /**
     * Schritt 1 beim Anlegen: Aufgabentyp wählen (backend/scheduler_create.php).
     */
    public function create(): void
    {
        $this->backend->setNaviLabel('#button_create');
        echo $this->backend->template('container_header');
        echo $this->backend->headline('#scheduler.headline_scheduler_create');

        $tplOption = $this->backend->template('scheduler.create_option');
        $options = '';
        foreach (Scheduler::TYPES as $type) {
            $options .= strtr($tplOption, [
                '{href}' => "cApp.layers.replace('Scheduler/scheduler_edit.php?function=create&data[type]={$type}');",
                '{label}' => '{#scheduler.label_type_' . $type . '}',
                '{note}' => '{#scheduler.note_type_' . $type . '}',
            ]);
        }
        echo $this->backend->template('scheduler.create_form', ['{options}' => $options]);
        echo $this->backend->template('container_footer');
    }

    /**
     * "Jetzt ausführen" (backend/scheduler_run.php): die Aufgabe als Hintergrundprozess
     * des Admins starten - ihr Ergebnis erscheint danach in der Detailansicht, der
     * Prozess selbst in der Liste der Hintergrundprozesse.
     */
    public function run(): void
    {
        $id = (int)($_REQUEST['id'] ?? 0);
        echo $this->backend->template('container_header');
        // Startet einen Hintergrundprozess - nur per POST (17-csrf.md §2.7)
        if (!$this->backend->requirePost()) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }
        if (!$data = $this->scheduler->load($id)) {
            echo $this->backend->template('alert_danger', ['{text}' => $this->cms->errorMsg()]);
            echo $this->backend->template('container_footer');
            return;
        }
        $error = '';
        if ($this->runner->startNow($id, $error) !== null) {
            $this->backend->balloon('success', $this->cms->snippet('scheduler.message_started'));
        }
        else {
            $this->backend->balloon('danger', $this->cms->snippet('scheduler.message_start_failed') . ' ' . htmlspecialchars($error));
        }
        $this->backend->script("cApp.layers.replace('Scheduler/scheduler_info.php?id={$id}');");
        echo $this->backend->template('container_footer');
    }

    /**
     * Deaktivieren / reaktivieren (backend/scheduler_toggle.php, Request id, enabled
     * 0|1): die Aufgabe vorübergehend aussetzen bzw. wieder nach Zeitplan laufen
     * lassen - ohne die Maske, alle anderen Einstellungen bleiben. Gespeichert wird
     * nur `enabled`; den nächsten Lauf rechnet Scheduler::hookOnSaved() neu (leer,
     * solange ausgesetzt).
     */
    public function toggle(): void
    {
        $id = (int)($_REQUEST['id'] ?? 0);
        $enabled = (int)!empty($_REQUEST['enabled']);
        echo $this->backend->template('container_header');
        // Ändert die Aufgabe - nur per POST (17-csrf.md §2.7)
        if (!$this->backend->requirePost()) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }
        $data = ['scheduler_id' => $id, 'enabled' => $enabled];
        if ($this->scheduler->save(['enabled'], $data)) {
            $this->backend->balloon('success', $this->cms->snippet($enabled ? 'scheduler.message_enabled' : 'scheduler.message_disabled'));
            $this->backend->script("cApp.layers.invalidate(); cApp.layers.replace('Scheduler/scheduler_info.php?id={$id}');");
        }
        else {
            echo $this->backend->template('alert_danger', ['{text}' => $this->cms->errorMsg()]);
        }
        echo $this->backend->template('container_footer');
    }

    /**
     * Erstellen / kopieren / ändern / löschen (backend/scheduler_edit.php).
     *
     * Zustandsautomat über $_REQUEST['function'] wie in den anderen Modulen.
     * Beim Anlegen kommt der Typ aus der Auswahl in create() (data[type]).
     */
    public function edit(): void
    {
        $this->id = (int)($_REQUEST['id'] ?? 0);
        $this->function = (string)($_REQUEST['function'] ?? '');
        if ($this->function == '') $this->function = 'edit';
        $this->data = (array)($_REQUEST['data'] ?? []);
        $this->data['scheduler_id'] = $this->id;
        $this->data['type'] = preg_replace('/[^a-z]/', '', (string)($this->data['type'] ?? ''));

        $this->backend->setNaviLabel('#button_' . $this->function);
        echo $this->backend->template('container_header');

        if ($this->function == 'create') {
            $this->_editCreate();
        }
        if (in_array($this->function, ['edit', 'copy', 'delete'], true)) {
            $this->_editLoad();
        }

        echo $this->_editHeadline();

        if ($this->function == 'save') {
            $this->_editSave();
        }
        if ($this->function == 'delete_confirm') {
            $this->_editDeleteConfirm();
        }
        if (in_array($this->function, ['errors', 'skip'], true)) {
            echo $this->backend->template('alert_danger', ['{text}' => $this->cms->errorMsg()]);
        }
        if (in_array($this->function, ['create', 'edit', 'copy', 'errors'], true)) {
            $this->_editForm();
        }
        if ($this->function == 'delete') {
            $this->_editDeleteForm();
        }

        echo $this->backend->template('container_footer');
    }

    // ====================
    // edit() Hilfsfunktionen

    private function _editCreate(): void
    {
        $type = $this->data['type'];
        if (!in_array($type, Scheduler::TYPES, true)) {
            $this->cms->error($this->cms->snippet('scheduler.error_unknown_type'));
            $this->function = 'skip';
            return;
        }
        $this->data = $this->scheduler->init(['type' => $type]);
        $this->id = 0;
    }

    private function _editLoad(): void
    {
        $this->data = $this->scheduler->load($this->id);
        if (!$this->data) {
            $this->function = 'skip';
            return;
        }
        if ($this->function == 'copy') {
            $this->id = 0;
            $this->data['scheduler_id'] = 0;
            $this->data['last_run'] = null;
            $this->data['last_status'] = 0;
            $this->data['last_message'] = '';
        }
    }

    private function _editHeadline(): string
    {
        if (in_array($this->function, ['delete', 'info'], true)) {
            $label = htmlspecialchars($this->cms->textOrSnippet((string)($this->data['label'] ?? '')));
        }
        else {
            $label = $this->cms->snippet('scheduler.headline_scheduler_' . ($this->function === 'create' ? 'create' : 'edit'));
        }
        return $this->backend->template('headline1', [
            '{text}' => $this->backend->template('scheduler.icon_scheduler') . ' ' . $label
        ]);
    }

    /**
     * function=save: speichern - nur die Felder der Maske, der Laufzeitstand
     * (next_run, last_*) gehört dem Runner (Scheduler::editableFields()).
     */
    private function _editSave(): void
    {
        if ($this->scheduler->save($this->scheduler->editableFields(), $this->data)) {
            $this->backend->balloon('success', $this->cms->snippet('scheduler.message_scheduler_saved'));
            $this->backend->script("
                cApp.layers.invalidate();
                cApp.layers.close();
            ");
        }
        else {
            $this->function = 'errors';
        }
    }

    private function _editDeleteConfirm(): void
    {
        if ($this->scheduler->delete($this->id)) {
            $this->backend->balloon('success', $this->cms->snippet('scheduler.message_scheduler_deleted'));
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
     * Das Eingabeformular: gemeinsame Felder, die sechs Cron-Felder, dann der Block
     * des Aufgabentyps (scheduler.edit_form_{type}).
     */
    private function _editForm(): void
    {
        $changed = ($this->function == 'save') ? ' data-changed="true"' : '';
        echo '<form action="Scheduler/scheduler_edit.php" method="post"' . $changed . '>';

        $type = (string)($this->data['type'] ?? '');
        $details = (array)($this->data['details'] ?? []);

        $this->backend->formInit('scheduler.edit_form', [
            '{type_fields}' => $this->backend->template('scheduler.edit_form_' . $type),
            '{headline_type}' => '{#scheduler.label_type_' . $type . '}',
        ]);

        // Typ steht fest
        $this->backend->formStatic($this->scheduler, 'type', $this->cms->snippet('scheduler.label_type_' . $type));
        $this->backend->formHidden('data[type]', $type);

        foreach (['label', 'enabled', 'max_minutes', 'log'] as $name) {
            $this->backend->formField($this->scheduler, [
                'name' => $name,
                'value' => $this->data[$name] ?? '',
                'id' => $this->id,
                'primary' => ($name === 'label') ? true : false
            ]);
        }
        // Admin, unter dem die Aufgabe läuft - nur solche mit der Oberfläche
        // Kommandozeile; ein nicht (mehr) zulässiger bleibt sichtbar, damit der
        // Fehler beim Speichern verständlich ist
        $adminOptions = $this->scheduler->adminOptions();
        $adminId = (int)($this->data['admin_id'] ?? 0);
        if ($adminId > 0 && !isset($adminOptions[$adminId])) {
            $adminOptions[$adminId] = $this->cms->_strip_tags($this->backend->teaser('admin', $adminId, linked: false, icon: false)) ?: '#' . $adminId;
        }
        $this->backend->formField($this->scheduler, [
            'name' => 'admin_id',
            'value' => $adminId ?: '',
            'id' => $this->id,
            'options' => $adminOptions,
            'selectPlease' => true,
        ]);
        foreach (array_keys(Scheduler::CRON_FIELDS) as $name) {
            $this->backend->formField($this->scheduler, [
                'name' => $name,
                'value' => $this->data[$name] ?? '*',
                'id' => $this->id,
                'attr' => ['class' => 'c9-monospace']
            ]);
        }

        // Typabhängige Felder
        if ($type === 'url') {
            $this->backend->formField($this->scheduler, ['name' => 'details.url', 'value' => $details['url'] ?? '', 'id' => $this->id]);
            $this->backend->formField($this->scheduler, ['name' => 'details.auth', 'value' => (int)($details['auth'] ?? 0), 'id' => $this->id]);
            $this->backend->formField($this->scheduler, ['name' => 'details.user', 'value' => $details['user'] ?? '', 'id' => $this->id]);
            $this->backend->formField($this->scheduler, ['name' => 'details.password', 'value' => '', 'id' => $this->id]);
        }
        elseif ($type === 'php') {
            $scripts = $this->scheduler->customScripts();
            if (empty($scripts)) {
                $this->backend->formReplace('{data_details_script}', $this->backend->template('alert_warning', [
                    '{text}' => '{#scheduler.note_no_scripts}'
                ]));
            }
            else {
                $this->backend->formField($this->scheduler, [
                    'name' => 'details.script',
                    'value' => $details['script'] ?? '',
                    'id' => $this->id,
                    'options' => array_combine($scripts, $scripts),
                    'selectPlease' => true
                ]);
            }
        }
        elseif ($type === 'template') {
            $templates = $this->scheduler->schedulerTemplates();
            if (empty($templates)) {
                $this->backend->formReplace('{data_details_template_id}', $this->backend->template('alert_warning', [
                    '{text}' => '{#scheduler.note_no_templates}'
                ]));
            }
            else {
                $this->backend->formField($this->scheduler, [
                    'name' => 'details.template_id',
                    'value' => $details['template_id'] ?? '',
                    'id' => $this->id,
                    'options' => array_map(fn($t) => $t['label'], $templates),
                    'selectPlease' => true
                ]);
                // Sprachen aller Instanzen mit wählbaren Templates; gespeichert wird nur
                // eine Sprache der Instanz des Templates (Scheduler::hookOnSave())
                $languages = [0 => $this->cms->snippet('scheduler.option_language_primary')];
                foreach ($templates as $template) $languages += $template['languages'];
                $this->backend->formField($this->scheduler, [
                    'name' => 'details.language_id',
                    'value' => (int)($details['language_id'] ?? 0),
                    'id' => $this->id,
                    'options' => $languages,
                ]);
            }
        }
        elseif ($type === 'dbbackup') {
            $this->backend->formField($this->scheduler, ['name' => 'details.backups', 'value' => $details['backups'] ?? 7, 'id' => $this->id]);
        }
        elseif ($type === 'fulltext') {
            $this->backend->formField($this->scheduler, [
                'name' => 'details.area_ids',
                'value' => (array)($details['area_ids'] ?? []),
                'id' => $this->id,
                'options' => $this->scheduler->fulltextAreaOptions(),
            ]);
            $this->backend->formField($this->scheduler, ['name' => 'details.only_stale', 'value' => $details['only_stale'] ?? 0, 'id' => $this->id]);
        }
        elseif ($type === 'logcleanup') {
            foreach (['log_days' => 90, 'security_days' => 365, 'attempt_days' => 30] as $key => $default) {
                $this->backend->formField($this->scheduler, ['name' => 'details.' . $key, 'value' => $details[$key] ?? $default, 'id' => $this->id]);
            }
        }
        if (in_array($type, Scheduler::REPORT_TYPES, true)) {
            $this->backend->formField($this->scheduler, ['name' => 'details.report', 'value' => (int)($details['report'] ?? 0), 'id' => $this->id]);
            $this->backend->formField($this->scheduler, [
                'name' => 'details.report_group_ids',
                'value' => (array)($details['report_group_ids'] ?? []),
                'id' => $this->id,
                'options' => $this->scheduler->groupOptions(),
            ]);
        }
        if (in_array($type, ['url', 'php', 'template'], true)) {
            $this->backend->formField($this->scheduler, [
                'name' => 'details.output',
                'value' => $details['output'] ?? 'save',
                'id' => $this->id,
                'options' => [
                    'save' => $this->cms->snippet('scheduler.option_output_save'),
                    'discard' => $this->cms->snippet('scheduler.option_output_discard'),
                ]
            ]);
            $this->backend->formField($this->scheduler, ['name' => 'details.output_file', 'value' => $details['output_file'] ?? '', 'id' => $this->id]);
        }

        $this->backend->formHidden('function', 'save');
        $this->backend->formHidden('id', $this->id);
        echo $this->backend->form('form_save_cancel_note', removePlaceholders: true);
        echo '</form>';
    }

    private function _editDeleteForm(): void
    {
        echo '<form action="Scheduler/scheduler_edit.php" method="post">';
        $this->backend->formInit('scheduler.delete_form');
        $this->backend->formHidden('id', $this->id);
        $this->backend->formHidden('function', 'delete_confirm');
        echo $this->backend->form('form_delete_cancel');
        echo '</form>';
    }

    // ====================
    // Darstellung

    /**
     * Die sechs Cron-Felder als eine Zeile "m h d M Y w".
     */
    private function _cronText(array $data): string
    {
        $parts = [];
        foreach (array_keys(Scheduler::CRON_FIELDS) as $name) {
            $parts[] = htmlspecialchars((string)($data[$name] ?? '*'));
        }
        return implode(' ', $parts);
    }

    /**
     * Aktiv: Haken, sonst "vorübergehend ausgesetzt".
     */
    private function _enabledText(array $data): string
    {
        if (!empty($data['enabled'])) return $this->backend->template('icon_checked');
        return $this->backend->template('scheduler.label_suspended');
    }

    private function _dateText(string $isoDate): string
    {
        if ($isoDate === '' || str_starts_with($isoDate, '0000')) return $this->backend->emptyPlaceholder;
        return htmlspecialchars($this->cms->_formatDate($isoDate));
    }

    private function _statusIcon(int $status): string
    {
        return $this->backend->template(match ($status) {
            Scheduler::STATUS_RUNNING => 'scheduler.status_running',
            Scheduler::STATUS_ERROR   => 'scheduler.status_error',
            default                   => 'scheduler.status_ok',
        });
    }

    private function _outputText(array $details): string
    {
        if (($details['output'] ?? 'save') !== 'save') return $this->cms->snippet('scheduler.option_output_discard');
        return $this->cms->snippet('scheduler.option_output_save') . ' var/custom/' . Scheduler::OUTPUT_DIR . '/' . (string)($details['output_file'] ?? '');
    }

    /**
     * Vorhandene Sicherungen in var/dbbackup mit Datum und Größe; unbrauchbare
     * (Backup::verify()) rot mit Grund.
     */
    private function _backupFiles(): string
    {
        $dir = rtrim((string)$this->config->get('paths.var_dir'), '/') . '/dbbackup';
        $items = [];
        for ($n = 1; $n <= 200; $n++) {
            $file = "$dir/backup-$n.sql";
            if (!is_file($file)) break;
            $error = $this->backup->verify($file);
            $items[] = '<li class="c9-monospace">backup-' . $n . '.sql &middot; '
                . htmlspecialchars($this->cms->_formatDate(date('Y-m-d H:i:s', (int)filemtime($file))))
                . ' &middot; ' . htmlspecialchars($this->system->convertSize((float)filesize($file)))
                . ($error !== null ? $this->backend->template('scheduler.backup_invalid', [
                    '{text}' => $this->backend->escape($this->cms->snippet('scheduler.label_backup_invalid') . ' ' . $error),
                ]) : '')
                . '</li>';
        }
        if (empty($items)) return $this->backend->emptyPlaceholder;
        return $this->backend->template('ul_unstyled', ['{items}' => implode("\n", $items)]);
    }
}
