<?php
/**
 * Backend des Moduls Privacy (Datenschutz). Instanzname im Code: $privacyBackend.
 *
 *     policy_list.php      -> list()          Lösch-Policies (Klick: Infoseite,
 *                                             Rechtsklick: Kontextmenü)
 *     policy_info.php      -> info()          Infoseite einer Policy
 *     policy_edit.php      -> edit()          Policy anlegen, ändern, löschen; ohne
 *                                             Recht weiter zur Infoseite
 *     template_list.php    -> templateList()  Vorlagen der Datenauskunft je Instanz
 *                                             und Sprache
 *     template_edit.php    -> templateEdit()  Vorlage anlegen, ändern, löschen
 *                                             (Zusatzrecht template_edit)
 *     export.php           -> export()        Datenexport personenbezogener Daten
 *                                             (Zusatzrecht export)
 *     export_download.php  -> download()      Zip ausliefern und löschen
 *     deleted_admins.php   -> deletedAdmins() Gelöschte Benutzer (Klick: Infoseite im
 *                                             Modul Benutzer, dort "Anonymisieren")
 *     admin_anonymize.php  -> adminAnonymize() gelöschten Benutzer anonymisieren
 *                                             (Zusatzrecht admin_anonymize,
 *                                             AdminAnonymizer)
 *
 * Dazu die Hinweise für den Löschdialog anderer Module: hintHtml() (ein Datensatz,
 * RecordBackendEdit) und areaHintHtml() (Stapelverarbeitung).
 *
 * DATENEXPORT
 * --------------------
 * Ein Teilauszug dessen, was camindo CMS zu einer Person gespeichert hat - vor der
 * Weitergabe an die betroffene Person von Hand zu ergänzen (Rechtsgrundlagen,
 * Server-Logfiles außerhalb des CMS, Buchhaltung …). Erlaubt für:
 *     Website-User  Profil-Datensätze aus Bereichen, die die Gruppe lesen darf
 *                   (RecordPersonalData)
 *     Benutzer      aus Gruppen, deren Benutzer die Gruppe im Modul Benutzer sehen
 *                   darf: eigene Gruppe mit admin_read_group, andere mit
 *                   admin_read_other (AdminPersonalData)
 * Die Auskunft entsteht aus einer Vorlage (ExportTemplate) - getrennt für Website-User
 * und Benutzer. Website-User: Vorlagen der Instanz des Profils - ohne Vorlage kein
 * Export, bei mehreren Sprachen wählt man; Benutzer: Benutzer-Vorlagen aller lesbaren
 * Instanzen. Vor dem Erstellen bestätigt man mit einer
 * Checkbox, dazu berechtigt zu sein (Datenschutzbeauftragter); die betroffene Person
 * erfährt aus jeder späteren Auskunft von dieser (Eintrag 'export' im Audit-Log).
 * Das Zip liegt in var/export, bis es geladen wird (dann gelöscht) oder
 * admin.export_retention_hours abläuft.
 *
 * @see Camindo\Core\Modules\Privacy\DeletePolicy
 * @see Camindo\Core\Modules\Privacy\PrivacyJobs
 */
namespace Camindo\Core\Modules\Privacy;

use Camindo\Core\AuditDiff;
use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Database;
use Camindo\Core\Session;
use Camindo\Core\System;
use Camindo\Core\Modules\Admin\AdminPersonalData;
use Camindo\Core\Modules\Record\Record;
use Camindo\Core\Modules\Record\RecordBackendEdit;
use Camindo\Core\Modules\Record\RecordExport;
use Camindo\Core\Modules\Record\RecordPersonalData;

class PrivacyBackend {

    public function __construct(
        private Backend $backend,
        private Cms $cms,
        private Config $config,
        private Database $db,
        private Session $session,
        private System $system,
        private DeletePolicy $policy,
        private PrivacyJobs $jobs,
        private ExportTemplate $exportTemplate,
    )
    {}

    // ====================
    // Policies

    /**
     * Liste der Lösch-Policies (backend/policy_list.php) - alle, die die Gruppe sehen
     * darf: für alle Instanzen und die ihrer Instanzen. Klick: Infoseite, Rechtsklick:
     * Kontextmenü.
     */
    public function list(): void
    {
        $this->backend->setNaviLabel('#privacy.module_label');
        echo $this->backend->template('container_header');
        echo $this->backend->headline('#privacy.headline_policies');
        echo $this->backend->template('p', ['{text}' => '<small class="c9-grey-50">' . $this->backend->escape($this->_s('privacy.note_policies')) . '</small>']);
        echo $this->_tabs('policies');

        $this->backend->buttonBarReset();
        if ($this->_canCreate()) {
            $this->backend->buttonAdd('button_create_primary', "cApp.layers.add('Privacy/policy_edit.php');");
        }
        $this->backend->buttonAdd('button_refresh', "cApp.layers.refresh();");
        echo $this->backend->buttonBar();

        $rows = '';
        foreach ($this->_policies() as $row) {
            $id = (int)$row['privacy_policy_id'];
            $hide = [];
            if (!$this->policy->canEdit((int)$row['instance_id'])) $hide[] = 'edit';
            if (!$this->_deletable($row)) $hide[] = 'delete';
            $rows .= $this->backend->template('privacy.policy_row', [
                '{id}' => (string)$id,
                '{ctx_hide}' => implode(',', $hide),
                '{label}' => $this->backend->escape($this->cms->textOrSnippet((string)$row['label'])),
                '{standard}' => (string)$row['standard'] !== '' ? $this->backend->template('privacy.badge_standard') : '',
                '{instance}' => $this->backend->escape($this->_instanceLabel((int)$row['instance_id'])),
                '{categories}' => $this->backend->escape($this->_categoriesText(DeletePolicy::normalizeBlocks(json_decode((string)$row['blocks'], true) ?: []))),
            ]);
        }
        echo $this->backend->template('privacy.policy_table', ['{table_rows}' => $rows]);
        echo $this->backend->template('privacy.policy_context_menu');
        echo $this->backend->template('container_footer');
    }

    /**
     * Infoseite einer Policy (backend/policy_info.php): Label, Instanz, Kategorien,
     * Bereiche, die sie benutzen, Blöcke; Reiter "Änderungen".
     *
     * Request: id
     */
    public function info(): void
    {
        $id = (int)($_REQUEST['id'] ?? 0);
        $this->backend->setNaviLabel('#button_info');
        echo $this->backend->template('container_header');

        $data = $this->policy->load($id);
        if (!$data || !$this->_canSee((int)$data['instance_id'])) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }
        $blocks = DeletePolicy::normalizeBlocks((array)($data['blocks'] ?? []));
        echo $this->backend->template('headline1', ['{text}' => $this->backend->template('privacy.icon_policy') . ' '
            . $this->backend->escape($this->cms->textOrSnippet((string)$data['label']))]);

        $this->backend->buttonBarReset();
        if ($this->policy->canEdit((int)$data['instance_id'])) {
            $this->backend->buttonAdd('button_edit_primary', "cApp.layers.replace('Privacy/policy_edit.php?id={$id}');");
        }
        if ($this->_deletable($data)) {
            $this->backend->buttonAdd('button_delete', "cApp.layers.replace('Privacy/policy_edit.php?id={$id}&function=delete');");
        }
        $this->backend->buttonAdd('button_close', "cApp.layers.close()");
        echo $this->backend->buttonBar();

        // Reiter: Info | Änderungen (Audit-Log)
        if ($this->backend->infoAuditTabs("Privacy/policy_info.php?id={$id}", 'privacy_policy', $id)) return;

        $items = '';
        foreach ($blocks as $block) {
            $items .= $this->backend->template('privacy.block_readonly', ['{text}' => $this->backend->escape($this->_blockText($block, true))]);
        }
        $this->backend->infoReset();
        $this->backend->infoAdd($this->policy, 'label', $this->cms->textOrSnippet((string)$data['label']), ['primary']);
        $this->backend->infoAdd($this->policy, 'instance_id', $this->_instanceLabel((int)$data['instance_id']));
        if ((string)$data['standard'] !== '') {
            $this->backend->infoAdd($this->policy, 'standard', $this->_s('privacy.option_standard_' . $data['standard']));
        }
        $this->backend->infoStatic($this->_s('privacy.label_category'), $this->backend->escape($this->_categoriesText($blocks)) ?: $this->backend->emptyPlaceholder);
        $this->backend->infoStatic($this->_s('privacy.column_areas'), $this->_areasHtml($id));
        $this->backend->infoStatic($this->_s('privacy.headline_blocks'), $this->backend->template('privacy.block_list', ['{items}' => $items]));
        $this->backend->infoAdd($this->policy, 'privacy_policy_id', $id);
        echo $this->backend->infoTable();
        echo $this->backend->template('container_footer');
    }

    /**
     * Policy anlegen bzw. ändern (backend/policy_edit.php).
     *
     * Request: id (0 = neu), function: '' | update (Block hinzufügen/entfernen ohne
     * zu speichern, block_action = add | remove, block_remove = Index) | save |
     * delete (Rückfrage) | delete_confirm; data[label], data[instance_id],
     * blocks[i][sources][], blocks[i][delay], blocks[i][unit], blocks[i][category],
     * blocks[i][action], blocks[i][fields] (Text "feld = wert" je Zeile),
     * blocks[i][trigger].
     */
    public function edit(): void
    {
        $id = (int)($_REQUEST['id'] ?? 0);
        $function = (string)($_REQUEST['function'] ?? '');
        $this->backend->setNaviLabel($function === 'delete' ? '#button_delete' : ($id > 0 ? '#button_edit' : '#button_create'));
        echo $this->backend->template('container_header');

        $data = $id > 0 ? $this->policy->load($id) : ['privacy_policy_id' => 0, 'label' => '', 'instance_id' => $this->_defaultInstance(), 'standard' => '', 'blocks' => []];
        if (!$data || ($id > 0 && !$this->_canSee((int)$data['instance_id']))) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }
        $data['blocks'] = DeletePolicy::normalizeBlocks((array)($data['blocks'] ?? []));
        $editable = $id > 0 ? $this->policy->canEdit((int)$data['instance_id']) : $this->_canCreate();
        if (!$editable) {
            // ohne Recht: die Infoseite
            $this->backend->script("cApp.layers.replace('Privacy/policy_info.php?id={$id}');");
            echo $this->backend->template('container_footer');
            return;
        }
        echo $this->backend->template('headline1', ['{text}' => $this->backend->template('privacy.icon_policy') . ' '
            . $this->backend->escape($id > 0 ? $this->cms->textOrSnippet((string)$data['label']) : $this->_s('privacy.headline_policy_create'))]);

        if ($function === 'delete') {
            echo '<form action="Privacy/policy_edit.php" method="post">';
            $this->backend->formInit('privacy.delete_form');
            $this->backend->formHidden('id', $id);
            $this->backend->formHidden('function', 'delete_confirm');
            echo $this->backend->form('form_delete_cancel');
            echo '</form>';
            echo $this->backend->template('container_footer');
            return;
        }
        if ($function === 'delete_confirm' && $this->backend->requirePost()) {
            if ($this->policy->delete($id)) {
                $this->backend->balloon('success', $this->_s('privacy.message_policy_deleted'));
                $this->backend->script("cApp.layers.invalidate(); cApp.layers.close();");
                echo $this->backend->template('container_footer');
                return;
            }
            echo $this->backend->template('alert_danger', ['{text}' => $this->backend->escape($this->cms->errorMsg())]);
        }

        // Eingaben übernehmen (Block hinzufügen/entfernen, Speichern)
        $blocks = $data['blocks'];
        $changed = false;
        if (in_array($function, ['update', 'save'], true)) {
            $input = (array)($_POST['data'] ?? []);
            $data['label'] = trim((string)($input['label'] ?? ''));
            if ((string)$data['standard'] === '') {
                $data['instance_id'] = (int)($input['instance_id'] ?? $data['instance_id']);
            }
            $blocks = array_values(array_filter((array)($_POST['blocks'] ?? []), 'is_array'));
            if ((string)($_POST['block_action'] ?? '') === 'add') {
                $blocks[] = ['sources' => ['record'], 'delay' => 0, 'unit' => 'days', 'action' => 'soft_delete', 'category' => 'trash', 'fields' => ''];
            }
            if ((string)($_POST['block_action'] ?? '') === 'remove') {
                unset($blocks[(int)($_POST['block_remove'] ?? -1)]);
                $blocks = array_values($blocks);
            }
            $changed = true;
        }

        if ($function === 'save' && $this->backend->requirePost()) {
            $normalized = DeletePolicy::normalizeBlocks($blocks);
            // Trigger-Aktion ohne gültige Kennung fiele beim Bereinigen weg
            $badTrigger = array_filter($blocks, fn($b) => in_array((string)($b['action'] ?? ''), DeletePolicy::TRIGGER_ACTIONS, true)
                && !preg_match(DeletePolicy::TRIGGER_PATTERN, strtolower(trim((string)($b['trigger'] ?? '')))));
            if (!$this->policy->canEdit((int)$data['instance_id'])) {
                $this->cms->error($this->_s('error_access_denied'));
            }
            elseif ($badTrigger) {
                $this->cms->error($this->_s('privacy.error_trigger_name', ['number' => (int)array_key_first($badTrigger) + 1]));
            }
            elseif (!$normalized) {
                $this->cms->error($this->_s('privacy.error_no_blocks'));
            }
            else {
                $save = ['privacy_policy_id' => $id, 'label' => $data['label'], 'instance_id' => (int)$data['instance_id'], 'blocks' => $normalized];
                if ($this->policy->save(['label', 'instance_id', 'blocks'], $save)) {
                    $this->backend->balloon('success', $this->_s('privacy.message_policy_saved'));
                    $this->backend->script("cApp.layers.invalidate(); cApp.layers.close();");
                    echo $this->backend->template('container_footer');
                    return;
                }
            }
            echo $this->backend->template('alert_danger', ['{text}' => $this->backend->escape($this->cms->errorMsg())]);
        }

        echo $this->_form($id, $data, $blocks, $changed);
        echo $this->backend->template('container_footer');
    }

    /**
     * Das Formular mit seinen Blöcken - Felder über Backend::formField(), die eines
     * Blocks mit varName 'blocks' und Name '{i}.{feld}' (blocks[{i}][{feld}],
     * Platzhalter {blocks_{i}_{feld}}, Schema DeletePolicy::BLOCK_SCHEMA).
     */
    private function _form(int $id, array $data, array $blocks, bool $changed): string
    {
        $blocks = array_values($blocks);
        $cards = '';
        foreach ($blocks as $i => $block) {
            $action = (string)($block['action'] ?? '');
            $cards .= $this->backend->template('privacy.block', [
                '{i}' => (string)$i,
                '{number}' => (string)($i + 1),
                '{fields_class}' => in_array($action, ['set_fields', 'audit_set_fields'], true) ? '' : ' d-none',
                '{trigger_class}' => in_array($action, DeletePolicy::TRIGGER_ACTIONS, true) ? '' : ' d-none',
            ]);
        }
        $this->backend->formInit('privacy.policy_form', ['{blocks}' => $cards]);

        $this->backend->formField($this->policy, ['name' => 'label', 'value' => (string)$data['label'], 'id' => $id, 'primary' => true]);
        if ((string)$data['standard'] !== '') {
            // Standard-Policies gelten immer für alle Instanzen
            $this->backend->formStatic($this->policy, 'instance_id', $this->backend->escape($this->_instanceLabel(DeletePolicy::ALL_INSTANCES)));
            $this->backend->formHidden('data[instance_id]', DeletePolicy::ALL_INSTANCES);
        }
        else {
            $this->backend->formField($this->policy, [
                'name' => 'instance_id', 'value' => (string)(int)$data['instance_id'], 'id' => $id,
                'options' => $this->_instanceOptions(),
            ]);
        }

        $sources = [];
        foreach (DeletePolicy::SOURCES as $source) $sources[$source] = $this->_s('privacy.source_' . $source);
        $units = [];
        foreach (DeletePolicy::UNITS as $unit) $units[$unit] = $this->_s('privacy.unit_' . $unit);
        $actions = [];
        foreach (DeletePolicy::ACTION_GROUPS as $group => $list) {
            foreach ($list as $a) $actions[$this->_s('privacy.action_group_' . $group)][$a] = $this->policy->actionLabel($a);
        }
        $categories = [];
        foreach (DeletePolicy::CATEGORIES as $c) $categories[$c] = $this->_s('privacy.category_' . $c);
        // Felder bzw. Kennung nur bei den Aktionen, die sie brauchen
        $onAction = "var c=$(this).closest('.card-body'),v=this.value;"
            . "c.find('.c9-privacy-fields').toggleClass('d-none',v!=='set_fields'&&v!=='audit_set_fields');"
            . "c.find('.c9-privacy-trigger').toggleClass('d-none',v!=='record_trigger'&&v!=='audit_trigger');";

        foreach ($blocks as $i => $block) {
            $fields = $block['fields'] ?? '';
            $values = [
                'sources' => array_values((array)($block['sources'] ?? [])),
                'delay' => (string)max(0, (int)($block['delay'] ?? 0)),
                'unit' => (string)($block['unit'] ?? 'days'),
                'action' => (string)($block['action'] ?? ''),
                'category' => (string)($block['category'] ?? ''),
                'fields' => is_array($fields) ? DeletePolicy::fieldsText($fields) : (string)$fields,
                'trigger' => (string)($block['trigger'] ?? ''),
            ];
            $options = ['sources' => $sources, 'unit' => $units, 'action' => $actions, 'category' => $categories];
            foreach (DeletePolicy::BLOCK_SCHEMA as $name => $colDef) {
                $param = [
                    'name' => $i . '.' . $name,
                    'varName' => 'blocks',
                    'colDef' => $colDef,
                    'value' => $values[$name],
                    'id' => $id,
                ];
                if (isset($options[$name])) $param['options'] = $options[$name];
                if ($name === 'category') $param['selectPlease'] = true;
                if ($name === 'action') $param['attr'] = ['onchange' => $onAction];
                if (in_array($name, ['fields', 'trigger'], true)) $param['attr'] = ['class' => 'c9-monospace'];
                $this->backend->formField($this->policy, $param);
            }
        }

        $this->backend->formHidden('function', 'save');
        $this->backend->formHidden('id', $id);
        $this->backend->formHidden('block_action', '');
        $this->backend->formHidden('block_remove', '');
        $deletable = $id > 0 && $this->_deletable($data);
        return '<form action="Privacy/policy_edit.php" method="post"' . ($changed ? ' data-changed="true"' : '') . '>'
            . $this->backend->form('privacy.form_buttons', [
                '{delete}' => $deletable ? $this->backend->template('privacy.button_delete', ['{id}' => (string)$id]) : '',
            ])   // ohne removePlaceholders: Hinweise nennen Platzhalter wie {record_id}
            . '</form>';
    }

    /** Löschbar: keine Standard-Policy, kein Bereich benutzt sie, Recht zum Ändern */
    private function _deletable(array $row): bool
    {
        return (string)$row['standard'] === '' && $this->policy->canEdit((int)$row['instance_id'])
            && $this->policy->usageCount((int)$row['privacy_policy_id']) === 0;
    }

    /** Kategorien der Blöcke, in der Reihenfolge von DeletePolicy::CATEGORIES */
    private function _categoriesText(array $blocks): string
    {
        $used = array_unique(array_filter(array_map(fn($b) => (string)($b['category'] ?? ''), $blocks)));
        $labels = [];
        foreach (DeletePolicy::CATEGORIES as $category) {
            if (in_array($category, $used, true)) $labels[] = $this->_s('privacy.category_' . $category);
        }
        return implode(', ', $labels);
    }

    /** Bereiche, die die Policy benutzen (Infoseite) */
    private function _areasHtml(int $policyId): string
    {
        $this->db->query("SELECT a.`area_id`, a.`label`, a.`name`, a.`instance_id` FROM `area` a WHERE a.`privacy_policy_id` = ? AND a.`deleted` = 0"
            . " ORDER BY a.`instance_id`, a.`lft`", 'i', [$policyId]);
        $items = '';
        foreach ($this->db->fetchAll() as $row) {
            $text = $this->cms->textOrSnippet((string)(($row['label'] ?? '') ?: $row['name'])) . ' (' . $this->_instanceLabel((int)$row['instance_id']) . ')';
            $items .= $this->backend->template('privacy.block_readonly', ['{text}' => $this->backend->escape($text)]);
        }
        return $items === '' ? $this->backend->emptyPlaceholder : $this->backend->template('privacy.area_list', ['{items}' => $items]);
    }

    /**
     * Die Lösch-Policy eines Bereichs als Sätze, je Block einer - Platzhalter
     * {delete_policy} der Vorlage des Datenexports (RecordPersonalData).
     *
     * @return string[]
     */
    public function policyLines(int $areaId): array
    {
        $policy = $this->jobs->policyOfArea($areaId);
        return $policy ? array_map(fn($block) => $this->_blockText($block), $policy['blocks']) : [];
    }

    /**
     * Ein Block als Satz: "Datensatz wird gelöscht, …: nach 7 Tagen – Felder im Audit-Log überschreiben (_label = …)";
     * mit $withCategory davor die Kategorie ("Vollständig anonymisieren: …").
     */
    private function _blockText(array $block, bool $withCategory = false): string
    {
        $sources = implode(', ', array_map(fn($s) => $this->_s('privacy.source_' . $s), (array)$block['sources']));
        $when = (int)$block['delay'] === 0
            ? $this->_s('privacy.when_now')
            : $this->_s('privacy.when_' . $block['unit'], ['count' => (int)$block['delay']]);
        $text = $sources . ': ' . $when . ' – ' . $this->policy->actionLabel((string)$block['action']);
        if ((string)($block['trigger'] ?? '') !== '') $text .= ' "' . $block['trigger'] . '"';
        if (!empty($block['fields'])) {
            $text .= ' (' . implode(', ', array_map(fn($k, $v) => $k . ' = "' . $v . '"', array_keys($block['fields']), $block['fields'])) . ')';
        }
        if ($withCategory && (string)($block['category'] ?? '') !== '') {
            $text = $this->_s('privacy.category_' . $block['category']) . ' – ' . $text;
        }
        return $text;
    }

    // ====================
    // Hinweis im Löschdialog

    /**
     * Was das Löschen eines Datensatzes nach der Policy seines Bereichs mit der
     * Datenbank tut (Löschdialog der Eingabemaske, RecordBackendEdit).
     */
    public function hintHtml(int $recordId): string
    {
        return $this->_hint($this->jobs->describe($recordId));
    }

    /** Dasselbe für die Datensätze eines Bereichs (Stapelverarbeitung) */
    public function areaHintHtml(int $areaId): string
    {
        return $this->_hint($this->jobs->describeArea($areaId));
    }

    private function _hint(array $description): string
    {
        if (!$description || $description['policy'] === '') return '';
        $body = '';
        if ($description['pending'] !== '') {
            $body .= $this->_hintText($this->_s('privacy.hint_pending', [
                'date' => $this->cms->_formatDate($this->cms->utcToUserTZ($description['pending']))]));
        }
        $subjectKey = in_array($description['type'], ['profile', 'ugc'], true) ? $description['type'] : 'record';
        $body .= $this->_planHtml($description['plan'], $this->_s('privacy.hint_subject_' . $subjectKey), 'single', 0);

        if ((int)$description['linked_count'] > 0) {
            foreach ($description['linked'] as $linked) {
                $count = (int)$linked['count'];
                if ($linked['plan'] === null) {
                    $body .= $this->_hintText($this->_s('privacy.hint_linked_keep', ['count' => $count, 'area' => $linked['area']]));
                    continue;
                }
                $subject = $this->_s('privacy.hint_linked_subject', ['count' => $count, 'area' => $linked['area']]);
                $body .= $this->_planHtml($linked['plan'], $subject, 'plural', $count);
            }
        }
        return $this->backend->template('privacy.hint', [
            '{headline}' => $this->backend->escape($this->_s('privacy.hint_headline', ['policy' => $description['policy']])),
            '{body}' => $body,
        ]);
    }

    /**
     * Ein Plan (PrivacyJobs::plan()) als Sätze und Listen.
     *
     * @param string $number  single | plural (Verbform des Subjekts)
     */
    private function _planHtml(array $plan, string $subject, string $number, int $count): string
    {
        $suffix = $number === 'plural' ? '_plural' : '';
        $html = $this->_hintText($this->_s('privacy.hint_now_' . $plan['now'] . $suffix, ['count' => $count, 'subject' => $subject]));
        if ($plan['now'] === 'change') $html .= $this->_hintList($plan['now_fields']);
        foreach ($plan['later'] as $group) {
            $html .= $this->_hintText($this->_s('privacy.hint_after_' . $group['unit'], ['count' => (int)$group['delay']]));
            $html .= $this->_hintList($group['items']);
        }
        return $html;
    }

    private function _hintText(string $text): string
    {
        return $this->backend->template('privacy.hint_text', ['{text}' => $this->backend->escape($text)]);
    }

    private function _hintList(array $items): string
    {
        $html = '';
        foreach ($items as $item) {
            $text = match ($item['kind']) {
                'purge' => $this->_s('privacy.hint_item_purge'),
                'trash' => $this->_s('privacy.hint_item_trash'),
                'trigger' => $this->_s('privacy.hint_item_trigger', ['name' => $item['value']]),
                default => $item['label'] . ': ' . (($item['format'] ?? '') === 'quoted' ? '"' . $item['value'] . '"' : $item['value']),
            };
            $html .= $this->backend->template('privacy.hint_item', ['{text}' => $this->backend->escape($text)]);
        }
        return $html === '' ? '' : $this->backend->template('privacy.hint_list', ['{items}' => $html]);
    }

    // ====================
    // Datenexport

    /**
     * Datenexport personenbezogener Daten (backend/export.php).
     *
     * Request: kind (user | admin), id, function: '' (Auswahl) | confirm (Vorlage
     * wählen, Hinweise, Bestätigung) | export (POST: Zip erstellen, verlangt
     * authorized = 1 und template_id)
     *
     * Ohne Vorlage für die Instanz des Profils (Benutzer: für keine zugängliche
     * Instanz) gibt es keinen Export; bei mehreren Vorlagen (Sprachen) wählt man.
     */
    public function export(): void
    {
        $kind = (string)($_REQUEST['kind'] ?? 'user') === 'admin' ? 'admin' : 'user';
        // Profil: aus dem Lookup (data[id]) bzw. der Auswahl im Papierkorb (trashed_id)
        $id = (int)($_REQUEST['id'] ?? 0) ?: $this->pickedProfileId('profile_id');
        $function = (string)($_REQUEST['function'] ?? '');
        $this->backend->setNaviLabel('#privacy.headline_export');
        echo $this->backend->template('container_header');
        echo $this->backend->headline('#privacy.headline_export');
        echo $this->_tabs('export');

        if (!$this->_canExport()) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }
        if ($function === '' || $id < 1) {
            $this->backend->tabBarReset();
            $this->backend->tabAdd('#privacy.tab_export_user', $kind === 'user' ? '' : "cApp.layers.replace('Privacy/export.php?kind=user')", $kind === 'user');
            $this->backend->tabAdd('#privacy.tab_export_admin', $kind === 'admin' ? '' : "cApp.layers.replace('Privacy/export.php?kind=admin')", $kind === 'admin');
            echo $this->backend->tabBar();
            echo $kind === 'admin' ? $this->_exportFormAdmin() : $this->_exportFormUser();
            echo $this->backend->template('container_footer');
            return;
        }

        $person = $kind === 'admin' ? $this->_exportableAdmin($id) : $this->_exportableProfile($id);
        if ($person === null) {
            echo $this->backend->template('alert_danger', ['{text}' => $this->backend->escape($this->_s('privacy.error_export_person'))]);
            echo $this->backend->template('container_footer');
            return;
        }
        [$instanceId, $scopeId] = $kind === 'user' ? $this->_profileScope($id) : [0, $this->_adminGroup($id)];
        $templates = $this->_exportTemplates($kind, $instanceId, $scopeId);

        $error = '';
        if ($function === 'export') {
            // Schreibt eine Datei mit personenbezogenen Daten - nur per POST (17-csrf.md)
            if (!$this->backend->requirePost()) {
                echo $this->backend->access()->deniedHtml();
                echo $this->backend->template('container_footer');
                return;
            }
            $templateId = (int)($_POST['template_id'] ?? 0);
            if ((string)($_POST['authorized'] ?? '') !== '1') {
                $error = $this->_s('privacy.error_export_authorized');
            }
            elseif (!isset($templates[$templateId])) {
                $error = $this->_s('privacy.error_export_template');
            }
            else {
                $this->_exportRun($kind, $id, $templateId);
                echo $this->backend->template('container_footer');
                return;
            }
        }

        if ($error !== '') echo $this->backend->template('alert_danger', ['{text}' => $this->backend->escape($error)]);
        echo $this->_exportConfirm($kind, $id, $person, $instanceId, $templates);
        echo $this->backend->template('container_footer');
    }

    /** Bestätigungsseite: Person, Inhalt, Vorlage, Warnhinweis, Checkbox */
    private function _exportConfirm(string $kind, int $id, string $person, int $instanceId, array $templates): string
    {
        $items = '';
        foreach (['account', 'login', 'activity', 'retention'] as $item) {
            $items .= $this->backend->template('privacy.hint_item', ['{text}' => $this->backend->escape($this->_s('privacy.export_item_' . $kind . '_' . $item))]);
        }
        $intro = $this->backend->template('privacy.export_intro', [
            '{person}' => $person,
            '{instance}' => $kind === 'user' ? $this->backend->escape($this->_instanceLabel($instanceId)) : $this->backend->emptyPlaceholder,
            '{items}' => $items,
        ]);
        if (!$templates) {
            // Ohne Vorlage kein Export
            $create = $kind === 'user' ? $this->exportTemplate->canEdit($instanceId) : $this->_templateCreatable();
            $create = $create ? $this->backend->template('privacy.button_template_create', [
                '{query}' => 'kind=' . $kind . ($kind === 'user' ? '&instance_id=' . $instanceId : '')]) : '';
            return $intro . $this->backend->template('privacy.export_no_template', [
                '{text}' => $this->backend->escape($this->_s($kind === 'user' ? 'privacy.note_export_no_template' : 'privacy.note_export_no_template_admin',
                    ['instance' => $this->_instanceLabel($instanceId)])),
                '{create}' => $create,
            ]);
        }
        if (count($templates) === 1) {
            $templateId = (int)array_key_first($templates);
            $choice = $this->backend->template('privacy.export_template_single', [
                '{label}' => $this->backend->escape($templates[$templateId]),
                '{id}' => (string)$templateId,
            ]);
        }
        else {
            $choice = $this->backend->template('privacy.export_template_select', [
                '{label}' => $this->backend->escape($this->_s($kind === 'user' ? 'privacy.label_export_language' : 'privacy.label_export_template')),
                '{options}' => $this->_options($templates, ''),
            ]);
        }
        return $intro . $this->backend->template('privacy.export_confirm', [
            '{kind}' => $kind,
            '{id}' => (string)$id,
            '{template}' => $choice,
            '{warning}' => $this->backend->escape($this->_s('privacy.note_export_warning_' . $kind)),
        ]);
    }

    /** Zip erstellen - in der Sprache der Vorlage - und den Download anbieten */
    private function _exportRun(string $kind, int $id, int $templateId): void
    {
        $template = $this->exportTemplate->load($templateId);
        $lang = $this->exportTemplate->backendLang((int)$template['instance_id'], (int)$template['language_id']);
        $backendLang = $this->cms->getLang();
        $this->cms->setLang($lang);
        try {
            $result = $kind === 'admin'
                ? $GLOBALS['app']->get(AdminPersonalData::class)->export($id, (string)$template['content'], (int)$template['instance_id'])
                : $GLOBALS['app']->get(RecordPersonalData::class)->export($id, (string)$template['content']);
        }
        catch (\Throwable $e) {
            $this->system->log('export-errors', "personal data $kind #$id: " . $e->getMessage());
            $result = null;
        }
        finally {
            $this->cms->setLang($backendLang);
        }
        if ($result === null) {
            echo $this->backend->template('alert_danger', ['{text}' => $this->backend->escape($this->_s('privacy.error_export_failed'))]);
            return;
        }
        $export = $GLOBALS['app']->get(RecordExport::class);
        echo $this->backend->template('privacy.export_result', [
            '{download_url}' => htmlspecialchars((string)$this->config->get('paths.cms') . '/backendModules/Privacy/export_download.php?'
                . http_build_query(['kind' => $kind, 'id' => $id, 'file' => $result['zip']])),
            '{file}' => htmlspecialchars($result['zip']),
            '{size}' => htmlspecialchars($this->system->convertSize((float)$result['size'])),
            '{validity}' => htmlspecialchars($export->validityText($result['zip'])),
        ]);
    }

    /**
     * Vorlagen, die für den Export in Frage kommen: Website-User - die Vorlagen der
     * Instanz des Profils für seinen Profil-Bereich, sonst die für alle
     * Profil-Bereiche; Benutzer - die Vorlagen lesbarer Instanzen für seine Gruppe,
     * sonst die für alle Gruppen.
     *
     * @param int $scopeId  Profil-Bereich bzw. Benutzergruppe der Person
     * @return array  privacy_template_id => Bezeichnung (Sprache bzw. "Instanz – Sprache")
     */
    private function _exportTemplates(string $kind, int $instanceId, int $scopeId): array
    {
        $instances = $kind === 'user' ? [$instanceId] : array_keys($this->_instanceOptions(false));
        $specific = $general = [];
        foreach ($instances as $instance) {
            $languages = $this->exportTemplate->languageOptions((int)$instance);
            foreach ($this->exportTemplate->forInstance($kind, (int)$instance) as $row) {
                $language = $languages[(int)$row['language_id']] ?? ('#' . (int)$row['language_id']);
                $label = $kind === 'user' ? $language : $this->_instanceLabel((int)$instance) . ' – ' . $language;
                if ($scopeId > 0 && (int)$row['scope_id'] === $scopeId) $specific[(int)$row['privacy_template_id']] = $label;
                elseif ((int)$row['scope_id'] === 0) $general[(int)$row['privacy_template_id']] = $label;
            }
        }
        // Vorlagen für den Bereich bzw. die Gruppe gehen vor - bei Benutzern über
        // alle Instanzen hinweg
        return $specific ?: $general;
    }

    /** Instanz und Profil-Bereich eines Profil-Datensatzes */
    private function _profileScope(int $recordId): array
    {
        $this->db->query("SELECT `instance_id`, `area_id` FROM `record` WHERE `record_id` = ?", 'i', [$recordId]);
        $row = $this->db->fetchNum() ?? [0, 0];
        return [(int)$row[0], (int)$row[1]];
    }

    /** Benutzergruppe eines Benutzers */
    private function _adminGroup(int $adminId): int
    {
        $this->db->query("SELECT `group_id` FROM `admin` WHERE `admin_id` = ?", 'i', [$adminId]);
        return (int)($this->db->fetchNum()[0] ?? 0);
    }

    /**
     * Zip eines Exports ausliefern (backend/export_download.php) und löschen - nur
     * eine Datei dieser Person, nur wer sie exportieren darf.
     *
     * Request: kind, id, file
     */
    public function download(): void
    {
        $kind = (string)($_REQUEST['kind'] ?? '') === 'admin' ? 'admin' : 'user';
        $id = (int)($_REQUEST['id'] ?? 0);
        $file = basename((string)($_REQUEST['file'] ?? ''));
        $path = (string)$this->config->get('paths.var_dir') . '/export/' . $file;
        $owned = $kind === 'admin'
            ? AdminPersonalData::ownsFile($id, $file)
            : (bool)preg_match('/^personal-data-' . $id . '-[0-9a-f]{24}\.zip$/', $file);
        $allowed = $this->_canExport() && ($kind === 'admin' ? $this->_exportableAdmin($id) : $this->_exportableProfile($id)) !== null;
        if ($id < 1 || !$owned || !$allowed || !is_file($path)) {
            $this->system->error404();
            return;
        }
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/zip');
        header('Content-Length: ' . (int)filesize($path));
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Cache-Control: private, no-store');
        readfile($path);
        @unlink($path);
        exit;
    }

    /** Auswahl Website-User: Id des Profils, dazu die lesbaren Profil-Bereiche zum Suchen */
    private function _exportFormUser(): string
    {
        return $this->backend->template('privacy.export_form_user', ['{picker}' => $this->profilePickerHtml('profile_id')]);
    }

    // ====================
    // Profil auswählen (Datenexport, Werkzeuge > Papierkorb eines Users)

    /**
     * Auswahl eines Profils: Lookup über alle Profil-Bereiche, die die Gruppe lesen
     * darf (Datensatzliste Record/record_list.php mit filter[area_id][]), dazu eine
     * Auswahl der Profile im Papierkorb - die Datensatzliste zeigt keine gelöschten.
     * Gesendet wird data[{name}] (Lookup) bzw. trashed_{name} - pickedProfileId().
     *
     * @param string $name      Feldname im Formular ([a-z_])
     * @param int    $selected  vorbelegtes Profil (aktiv oder im Papierkorb)
     */
    public function profilePickerHtml(string $name, int $selected = 0): string
    {
        $name = preg_replace('/[^a-z_]/', '', $name) ?: 'profile_id';
        $recordBackend = $GLOBALS['app']->get(RecordBackendEdit::class);
        $areaIds = $recordBackend->readableProfileAreaIds();
        $active = $selected > 0 ? $selected : 0;

        // Profile im Papierkorb (neueste zuerst)
        $trashed = [];
        if ($areaIds) {
            $this->db->query("SELECT `record_id`, `area_id` FROM `record` WHERE `deleted` = 1 AND `tmp` = 0 AND `area_id` IN ("
                . implode(',', array_map('intval', $areaIds)) . ") ORDER BY `changed_at` DESC LIMIT " . self::TRASHED_PROFILES);
            foreach ($this->db->fetchAll() as $row) {
                $trashed[(string)(int)$row['record_id']] = $this->_recordLabel((int)$row['record_id'], (int)$row['area_id']);
            }
        }
        if (isset($trashed[(string)$selected])) $active = 0;

        // derselbe Lookup wie das Feld "Frontend-User" der Suchmaske
        // (RecordBackend::profileLookupReplace(), Familie cApp.ui.slookup*)
        $lookup = $this->backend->template('edit_form_lookup_foreign_table', [
            '{name}' => $name,
            '{id}' => 'c9-privacy-' . $name,
            '{class}' => '',
        ] + $recordBackend->profileLookupReplace($name, 'data', $active > 0 ? [$active] : [], 1, $areaIds));
        return $this->backend->template('privacy.profile_picker', [
            '{name}' => $name,
            '{lookup}' => $lookup,
            '{trashed}' => $trashed ? $this->backend->template('privacy.profile_picker_trashed', [
                '{name}' => $name,
                '{options}' => $this->_options(['0' => '–'] + $trashed, isset($trashed[(string)$selected]) ? (string)$selected : '0'),
            ]) : '',
        ]);
    }

    /** Das Profil aus profilePickerHtml(): Lookup, sonst die Auswahl im Papierkorb */
    public function pickedProfileId(string $name): int
    {
        $data = $_REQUEST['data'] ?? [];
        $id = is_array($data) ? (int)($data[$name] ?? 0) : 0;
        return max(0, $id ?: (int)($_REQUEST['trashed_' . $name] ?? 0));
    }

    /** Höchstzahl der Profile im Papierkorb in der Auswahl */
    private const TRASHED_PROFILES = 200;

    /** Bezeichnung eines Datensatzes (Default-Feld seines Bereichs) samt Id - auch im Papierkorb */
    private function _recordLabel(int $recordId, int $areaId): string
    {
        $data = $GLOBALS['app']->get(Record::class)->load($recordId) ?? [];
        $field = $this->backend->getDefaultField($areaId);
        $label = trim(strip_tags((string)$this->backend->backendValue($data[$field['name'] ?? ''] ?? '', ['type' => $field['type'] ?? 'text'])));
        return ($label !== '' ? $label . ' ' : '') . '(#' . $recordId . ')';
    }

    /** Auswahl Benutzer: alle aus den Gruppen, deren Benutzer die Gruppe sehen darf */
    private function _exportFormAdmin(): string
    {
        $options = [];
        $this->db->query("SELECT `admin_id`, `name`, `group_id` FROM `admin` ORDER BY `name`");
        foreach ($this->db->fetchAll() as $row) {
            if ($this->_groupAccessible((int)$row['group_id'])) $options[(string)(int)$row['admin_id']] = (string)$row['name'];
        }
        return $this->backend->template('privacy.export_form_admin', ['{options}' => $this->_options($options, '')]);
    }

    /** Teaser des Profils, wenn es exportiert werden darf, sonst null */
    private function _exportableProfile(int $id): ?string
    {
        $this->db->query("SELECT r.`area_id` FROM `record` r JOIN `area` a ON a.`area_id` = r.`area_id`"
            . " WHERE r.`record_id` = ? AND r.`tmp` = 0 AND a.`type` = 'profile'", 'i', [$id]);
        $areaId = (int)($this->db->fetchNum()[0] ?? 0);
        if ($areaId < 1 || !$this->backend->access()->areaRead($areaId)) return null;
        return $this->backend->teaser('record', $id);
    }

    /** Name des Benutzers, wenn er exportiert werden darf, sonst null */
    private function _exportableAdmin(int $id): ?string
    {
        $this->db->query("SELECT `name`, `group_id` FROM `admin` WHERE `admin_id` = ?", 'i', [$id]);
        $row = $this->db->fetch();
        if (!$row || !$this->_groupAccessible((int)$row['group_id'])) return null;
        return $this->backend->escape((string)$row['name']) . ' (#' . $id . ')';
    }

    /** Darf die Gruppe die Benutzer der Gruppe $groupId sehen (Modul Benutzer)? */
    private function _groupAccessible(int $groupId): bool
    {
        $access = $this->backend->access();
        if ($access->isSuperuser()) return true;
        if (!$access->module('Admin')) return false;
        return $access->moduleRight('Admin', $groupId === $access->groupId() ? 'admin_read_group' : 'admin_read_other');
    }

    // ====================
    // Gelöschte Benutzer

    /**
     * Gelöschte Benutzer (backend/deleted_admins.php): Name, Gruppe, gelöscht am
     * (letzter Eintrag 'delete' im Audit-Log), anonymisiert. Nur Benutzer aus Gruppen,
     * die die Gruppe im Modul Benutzer sehen darf. Klick: Infoseite im Modul Benutzer
     * (mit "Zurück"), dort die Schaltfläche "Anonymisieren".
     */
    public function deletedAdmins(): void
    {
        $this->backend->setNaviLabel('#privacy.module_label');
        echo $this->backend->template('container_header');
        echo $this->backend->headline('#privacy.headline_deleted_admins');
        echo $this->backend->template('p', ['{text}' => '<small class="c9-grey-50">' . $this->backend->escape($this->_s('privacy.note_deleted_admins')) . '</small>']);
        echo $this->_tabs('deleted_admins');
        if (!$this->_canSeeDeletedAdmins()) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }

        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_refresh', "cApp.layers.refresh();");
        echo $this->backend->buttonBar();

        $this->db->query(
            "SELECT a.`admin_id`, a.`name`, a.`group_id`,"
            . " (SELECT MAX(l.`created_at`) FROM `audit_log` l WHERE l.`object_type` = 'admin' AND l.`object_id` = a.`admin_id` AND l.`action` = 'delete') AS `deleted_at`"
            . " FROM `admin` a WHERE a.`deleted` = 1 ORDER BY `deleted_at` DESC, a.`name` ASC"
        );
        $groups = $this->backend->getGroups();
        $auditDiff = $GLOBALS['app']->get(AuditDiff::class);
        $rows = '';
        foreach ($this->db->fetchAll() as $row) {
            if (!$this->_groupAccessible((int)$row['group_id'])) continue;
            $anonymized = AdminAnonymizer::isAnonymized($row);
            $rows .= $this->backend->template('privacy.deleted_admin_row', [
                '{id}' => (string)(int)$row['admin_id'],
                '{name}' => $this->backend->escape((string)$row['name']),
                // ohne Gruppe: die Gruppe wurde nach dem Löschen des Benutzers gelöscht
                '{group}' => isset($groups[(int)$row['group_id']]) ? $this->backend->escape($groups[(int)$row['group_id']]) : $this->backend->emptyPlaceholder,
                '{deleted_at}' => $row['deleted_at'] ? $this->backend->escape($auditDiff->localTime((string)$row['deleted_at'])) : $this->backend->emptyPlaceholder,
                '{anonymized}' => $anonymized ? $this->backend->template('privacy.badge_anonymized') : '',
            ]);
        }
        echo $rows === ''
            ? $this->backend->template('p', ['{text}' => $this->backend->escape($this->_s('privacy.note_deleted_admins_empty'))])
            : $this->backend->template('privacy.deleted_admin_table', ['{table_rows}' => $rows]);
        echo $this->backend->template('container_footer');
    }

    /**
     * Gelöschten Benutzer anonymisieren (backend/admin_anonymize.php, aufgerufen von
     * der Infoseite des Benutzers): Rückfrage, dann per POST AdminAnonymizer.
     *
     * Request: id, function ('' | confirm)
     */
    public function adminAnonymize(): void
    {
        $id = (int)($_REQUEST['id'] ?? 0);
        $function = (string)($_REQUEST['function'] ?? '');
        $this->backend->setNaviLabel('#privacy.button_admin_anonymize');
        echo $this->backend->template('container_header');

        $this->db->query("SELECT `admin_id`, `name`, `group_id`, `deleted` FROM `admin` WHERE `admin_id` = ?", 'i', [$id]);
        $row = $this->db->fetch();
        if (!$row || !$this->canAnonymizeAdmins() || !$this->_groupAccessible((int)$row['group_id'])) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }
        echo $this->backend->template('headline1', ['{text}' => $this->backend->template('admin.icon_admin') . ' ' . $this->backend->escape((string)$row['name'])]);
        if (empty($row['deleted']) || AdminAnonymizer::isAnonymized($row)) {
            echo $this->backend->template('alert_danger', ['{text}' => $this->backend->escape($this->_s(empty($row['deleted']) ? 'privacy.error_anonymize_not_deleted' : 'privacy.error_anonymize_done'))]);
            echo $this->backend->template('container_footer');
            return;
        }

        if ($function === 'confirm' && $this->backend->requirePost()) {
            $result = $GLOBALS['app']->get(AdminAnonymizer::class)->anonymize($id);
            if ($result !== null) {
                $this->backend->balloon('success', $this->_s('privacy.message_admin_anonymized', ['name' => AdminAnonymizer::anonymousName($id)]));
                $this->backend->script("cApp.layers.invalidate(); cApp.layers.replace('Admin/admin_info.php?id={$id}&back=true');");
                echo $this->backend->template('container_footer');
                return;
            }
            echo $this->backend->template('alert_danger', ['{text}' => $this->backend->escape($this->cms->errorMsg())]);
        }

        echo '<form action="Privacy/admin_anonymize.php" method="post">';
        echo $this->backend->template('privacy.admin_anonymize_form', [
            '{text}' => $this->backend->escape($this->_s('privacy.alert_admin_anonymize', ['name' => AdminAnonymizer::anonymousName($id)])),
            '{id}' => (string)$id,
        ]);
        echo '</form>';
        echo $this->backend->template('container_footer');
    }

    // ====================
    // Vorlagen der Datenauskunft

    /**
     * Vorlagen je Instanz und Sprache (backend/template_list.php). Klick: bearbeiten
     * (ohne Recht: ansehen), Rechtsklick: Kontextmenü.
     */
    public function templateList(): void
    {
        $this->backend->setNaviLabel('#privacy.module_label');
        echo $this->backend->template('container_header');
        echo $this->backend->headline('#privacy.headline_templates');
        echo $this->backend->template('p', ['{text}' => '<small class="c9-grey-50">' . $this->backend->escape($this->_s('privacy.note_templates')) . '</small>']);
        echo $this->_tabs('templates');

        $this->backend->buttonBarReset();
        if ($this->_templateCreatable()) {
            $this->backend->buttonAdd('button_create_primary', "cApp.layers.add('Privacy/template_edit.php');");
        }
        $this->backend->buttonAdd('button_refresh', "cApp.layers.refresh();");
        echo $this->backend->buttonBar();

        $rows = '';
        foreach (ExportTemplate::KINDS as $kind) foreach (array_keys($this->_instanceOptions(false)) as $instanceId) {
            $languages = $this->exportTemplate->languageOptions((int)$instanceId);
            foreach ($this->exportTemplate->forInstance($kind, (int)$instanceId) as $row) {
                $rows .= $this->backend->template('privacy.template_row', [
                    '{id}' => (string)(int)$row['privacy_template_id'],
                    '{kind}' => $this->backend->escape($this->_kindLabel($kind)),
                    '{scope}' => $this->backend->escape($this->exportTemplate->scopeOptions($kind, (int)$instanceId)[(int)$row['scope_id']] ?? ('#' . (int)$row['scope_id'])),
                    '{ctx_hide}' => $this->exportTemplate->canEdit((int)$instanceId) ? '' : 'edit,delete',
                    '{instance}' => $this->backend->escape($this->_instanceLabel((int)$instanceId)),
                    '{language}' => $this->backend->escape($languages[(int)$row['language_id']] ?? ('#' . (int)$row['language_id'])),
                ]);
            }
        }
        if ($rows === '') {
            echo $this->backend->template('alert_warning', ['{text}' => $this->backend->escape($this->_s('privacy.note_templates_empty'))]);
        }
        else {
            echo $this->backend->template('privacy.template_table', ['{table_rows}' => $rows]);
            echo $this->backend->template('privacy.template_context_menu');
        }
        echo $this->backend->template('container_footer');
    }

    /**
     * Vorlage anlegen, ändern, löschen (backend/template_edit.php).
     *
     * Request: id (0 = neu), function: '' | create (nach der Wahl von Art und
     * Instanz: Formular, vorbelegt mit der Vorlage des Core) | save | delete |
     * delete_confirm; data[slot] ("art:instanz", Schritt 1 beim Anlegen),
     * data[kind], data[instance_id], data[scope_id] (Profil-Bereich bzw.
     * Benutzergruppe, 0 = alle), data[language_id], data[content]; kind und
     * instance_id (Vorauswahl)
     */
    public function templateEdit(): void
    {
        $id = (int)($_REQUEST['id'] ?? 0);
        $function = (string)($_REQUEST['function'] ?? '');
        $input = (array)($_POST['data'] ?? []);
        $this->backend->setNaviLabel($function === 'delete' ? '#button_delete' : ($id > 0 ? '#button_edit' : '#button_create'));
        echo $this->backend->template('container_header');

        if ($id > 0) {
            $data = $this->exportTemplate->load($id);
            if (!$data || !$this->_canSee((int)$data['instance_id'])) {
                echo $this->backend->access()->deniedHtml();
                echo $this->backend->template('container_footer');
                return;
            }
        }
        else {
            [$kind, $instanceId] = explode(':', (string)($input['slot'] ?? '') . ':');
            $kind = $kind !== '' ? $kind : (string)($input['kind'] ?? '');
            $instanceId = (int)$instanceId ?: (int)($input['instance_id'] ?? 0);
            $data = ['privacy_template_id' => 0, 'kind' => $kind, 'instance_id' => $instanceId, 'scope_id' => 0, 'language_id' => 0, 'content' => ''];
        }
        $kind = in_array((string)$data['kind'], ExportTemplate::KINDS, true) ? (string)$data['kind'] : '';
        $instanceId = (int)$data['instance_id'];
        $editable = $id > 0 ? $this->exportTemplate->canEdit($instanceId) : $this->_templateCreatable();
        $label = $this->_templateLabel($data);
        echo $this->backend->template('headline1', ['{text}' => $this->backend->template('privacy.icon_template') . ' '
            . $this->backend->escape($id > 0 ? $label : $this->_s('privacy.headline_template_create'))]);

        if (!$editable) {
            echo $this->backend->template('privacy.template_readonly', [
                '{label}' => $this->backend->escape($label),
                '{content}' => $this->backend->escape((string)$data['content']),
            ]);
            echo $this->backend->template('container_footer');
            return;
        }

        if ($function === 'delete' && $id > 0) {
            echo '<form action="Privacy/template_edit.php" method="post">';
            $this->backend->formInit('privacy.template_delete_form');
            $this->backend->formHidden('id', $id);
            $this->backend->formHidden('function', 'delete_confirm');
            echo $this->backend->form('form_delete_cancel');
            echo '</form>';
            echo $this->backend->template('container_footer');
            return;
        }
        if ($function === 'delete_confirm' && $id > 0 && $this->backend->requirePost()) {
            if ($this->exportTemplate->delete($id)) {
                $this->backend->balloon('success', $this->_s('privacy.message_template_deleted'));
                $this->backend->script("cApp.layers.invalidate(); cApp.layers.close();");
                echo $this->backend->template('container_footer');
                return;
            }
            echo $this->backend->template('alert_danger', ['{text}' => $this->backend->escape($this->cms->errorMsg())]);
        }

        // Neu, Schritt 1: Art und Instanz wählen
        if ($id === 0 && ($function === '' || $kind === '' || !$this->exportTemplate->canEdit($instanceId))) {
            $slots = $this->_templateSlots();
            // Vorauswahl aus dem Datenexport ohne Vorlage (kind, instance_id)
            $presetKind = (string)($_REQUEST['kind'] ?? '');
            $presetInstance = (int)($_REQUEST['instance_id'] ?? 0);
            $selected = '';
            foreach (array_keys($slots) as $slot) {
                [$slotKind, $slotInstance] = explode(':', $slot);
                if ($slotKind === $presetKind && ($presetInstance === 0 || (int)$slotInstance === $presetInstance)) { $selected = $slot; break; }
            }
            echo '<form action="Privacy/template_edit.php" method="post">';
            $this->backend->formInit('privacy.template_create_form');
            $this->backend->formField($this->exportTemplate, [
                'name' => 'slot', 'value' => $selected, 'id' => 0, 'options' => $slots,
                'colDef' => ['type' => 'chars', 'display_type' => 'select', 'label' => '#privacy.field_slot',
                    'tooltip' => '#privacy.tooltip_template_slot', 'flags' => ['not_empty']],
            ]);
            $this->backend->formHidden('function', 'create');
            echo $this->backend->form('privacy.form_continue', removePlaceholders: true);
            echo '</form>';
            echo $this->backend->template('container_footer');
            return;
        }

        $languages = $this->exportTemplate->languageOptions($instanceId);
        if ($function === 'save' && $this->backend->requirePost()) {
            $data['content'] = (string)($input['content'] ?? '');
            $data['scope_id'] = (int)($input['scope_id'] ?? 0);
            $data['language_id'] = (int)($input['language_id'] ?? 0);
            $save = ['privacy_template_id' => $id, 'kind' => $kind, 'instance_id' => $instanceId, 'scope_id' => $data['scope_id'],
                'language_id' => $data['language_id'], 'content' => $data['content']];
            if ($this->exportTemplate->save(['kind', 'instance_id', 'scope_id', 'language_id', 'content'], $save)) {
                $this->backend->balloon('success', $this->_s('privacy.message_template_saved'));
                $this->backend->script("cApp.layers.invalidate(); cApp.layers.close();");
                echo $this->backend->template('container_footer');
                return;
            }
            echo $this->backend->template('alert_danger', ['{text}' => $this->backend->escape($this->cms->errorMsg())]);
        }
        elseif ($function === 'create') {
            // Vorbelegung in der Hauptsprache der Instanz (die erste der Liste)
            $data['language_id'] = (int)array_key_first($languages);
            $data['content'] = $this->exportTemplate->defaultContent($kind, $this->exportTemplate->backendLang($instanceId, (int)$data['language_id']));
        }

        echo '<form action="Privacy/template_edit.php" method="post"' . ($function === 'save' ? ' data-changed="true"' : '') . '>';
        $this->backend->formInit('privacy.template_form');
        $this->backend->formStatic($this->exportTemplate, 'kind', $this->backend->escape($this->_kindLabel($kind)));
        $this->backend->formStatic($this->exportTemplate, 'instance_id', $this->backend->escape($this->_instanceLabel($instanceId)));
        $this->backend->formField($this->exportTemplate, [
            'name' => 'scope_id', 'value' => (string)(int)$data['scope_id'], 'id' => $id,
            'options' => $this->exportTemplate->scopeOptions($kind, $instanceId),
            'colDef' => ['label' => $kind === 'admin' ? '#privacy.field_scope_group' : '#privacy.field_scope_area']
                + $this->exportTemplate->getSchema()['scope_id'],
        ]);
        $this->backend->formField($this->exportTemplate, [
            'name' => 'language_id', 'value' => (string)(int)$data['language_id'], 'id' => $id, 'options' => $languages,
        ]);
        $this->backend->formField($this->exportTemplate, [
            'name' => 'content', 'value' => (string)$data['content'], 'id' => $id, 'dir' => 'ltr',
            'attr' => ['class' => 'c9-monospace', 'spellcheck' => 'false'],
        ]);
        $this->backend->formHidden('data[kind]', $kind);
        $this->backend->formHidden('data[instance_id]', $instanceId);
        $this->backend->formHidden('function', 'save');
        $this->backend->formHidden('id', $id);
        echo $this->backend->form('privacy.form_buttons', [
            '{delete}' => $id > 0 ? $this->backend->template('privacy.button_template_delete', ['{id}' => (string)$id]) : '',
        ]);   // ohne removePlaceholders: der Hinweis nennt Platzhalter der Vorlage
        echo '</form>';
        echo $this->backend->template('container_footer');
    }

    /** "Art – Instanz – Gilt für – Sprache" einer Vorlage */
    private function _templateLabel(array $row): string
    {
        $kind = (string)$row['kind'];
        $instanceId = (int)$row['instance_id'];
        $parts = [$this->_kindLabel($kind), $this->_instanceLabel($instanceId)];
        if ((int)($row['scope_id'] ?? 0) > 0) {
            $parts[] = $this->exportTemplate->scopeOptions($kind, $instanceId)[(int)$row['scope_id']] ?? ('#' . (int)$row['scope_id']);
        }
        if ((int)($row['language_id'] ?? 0) > 0) {
            $parts[] = $this->exportTemplate->languageOptions($instanceId)[(int)$row['language_id']] ?? ('#' . (int)$row['language_id']);
        }
        return implode(' – ', $parts);
    }

    /** Art einer Vorlage: "Website-User" bzw. "Benutzer" */
    private function _kindLabel(string $kind): string
    {
        return $this->_s($kind === 'admin' ? 'privacy.tab_export_admin' : 'privacy.tab_export_user');
    }

    /** Darf der Admin überhaupt eine Vorlage anlegen? */
    private function _templateCreatable(): bool
    {
        foreach (array_keys($this->_instanceOptions(false)) as $instanceId) {
            if ($this->exportTemplate->canEdit((int)$instanceId)) return true;
        }
        return false;
    }

    /**
     * Art und Instanz für eine neue Vorlage - nur Instanzen, die der Admin bearbeiten
     * darf. Geltung und Sprache wählt man danach im Formular.
     *
     * @return array  "art:instanz" => "Art – Instanz"
     */
    private function _templateSlots(): array
    {
        $slots = [];
        foreach (ExportTemplate::KINDS as $kind) foreach (array_keys($this->_instanceOptions(false)) as $instanceId) {
            if (!$this->exportTemplate->canEdit((int)$instanceId)) continue;
            $slots[$kind . ':' . (int)$instanceId] = $this->_kindLabel($kind) . ' – ' . $this->_instanceLabel((int)$instanceId);
        }
        return $slots;
    }

    // ====================
    // Hilfen

    /** Reiter des Moduls */
    private function _tabs(string $active): string
    {
        $this->backend->tabBarReset();
        $this->backend->tabAdd('#privacy.tab_policies', $active === 'policies' ? '' : "cApp.layers.replace('Privacy/policy_list.php')", $active === 'policies');
        $this->backend->tabAdd('#privacy.tab_templates', $active === 'templates' ? '' : "cApp.layers.replace('Privacy/template_list.php')", $active === 'templates');
        if ($this->_canExport()) {
            $this->backend->tabAdd('#privacy.tab_export', $active === 'export' ? '' : "cApp.layers.replace('Privacy/export.php')", $active === 'export');
        }
        if ($this->_canSeeDeletedAdmins()) {
            $this->backend->tabAdd('#privacy.tab_deleted_admins', $active === 'deleted_admins' ? '' : "cApp.layers.replace('Privacy/deleted_admins.php')", $active === 'deleted_admins');
        }
        return $this->backend->tabBar();
    }

    /**
     * Reiter "Gelöschte Benutzer": Superuser und Gruppen mit Zugriff auf das Modul
     * Benutzer (die Liste zeigt nur Benutzer, die die Gruppe dort sehen darf).
     */
    private function _canSeeDeletedAdmins(): bool
    {
        $access = $this->backend->access();
        return $access->isSuperuser() || $access->module('Admin');
    }

    /** Darf die Gruppe gelöschte Benutzer anonymisieren (Superuser oder admin_anonymize)? */
    public function canAnonymizeAdmins(): bool
    {
        $access = $this->backend->access();
        return $access->isSuperuser() || $access->moduleRight('Privacy', 'admin_anonymize');
    }

    private function _canExport(): bool
    {
        $access = $this->backend->access();
        return $access->isSuperuser() || $access->moduleRight('Privacy', 'export');
    }

    /** Darf eine neue Policy angelegt werden (Superuser oder policy_edit)? */
    private function _canCreate(): bool
    {
        $access = $this->backend->access();
        return $access->isSuperuser() || ($access->moduleRight('Privacy', 'policy_edit') && $this->_instanceOptions());
    }

    /** Policy sichtbar: für alle Instanzen oder eine lesbare Instanz */
    private function _canSee(int $instanceId): bool
    {
        $access = $this->backend->access();
        return $instanceId === DeletePolicy::ALL_INSTANCES || $access->isSuperuser() || $access->instance($instanceId);
    }

    /** Alle Policies, die die Gruppe sehen darf */
    private function _policies(): array
    {
        $this->db->query("SELECT * FROM `privacy_policy` ORDER BY `standard` = '', `instance_id`, `label`");
        return array_values(array_filter($this->db->fetchAll(), fn($row) => $this->_canSee((int)$row['instance_id'])));
    }

    /**
     * Instanzen, die die Gruppe bearbeiten darf; mit $withAll für den Superuser zuerst
     * "alle" (Policies).
     */
    private function _instanceOptions(bool $withAll = true): array
    {
        $access = $this->backend->access();
        $options = [];
        if ($withAll && $access->isSuperuser()) $options[(string)DeletePolicy::ALL_INSTANCES] = $this->_instanceLabel(DeletePolicy::ALL_INSTANCES);
        $this->db->query("SELECT `instance_id`, `label` FROM `instance` ORDER BY " . \Camindo\Core\Modules\Instance\Instance::orderSql());
        foreach ($this->db->fetchAll() as $row) {
            if ($access->isSuperuser() || $access->instance((int)$row['instance_id'])) {
                $options[(string)(int)$row['instance_id']] = $this->cms->textOrSnippet((string)$row['label']);
            }
        }
        return $options;
    }

    /** Vorbelegung der Instanz einer neuen Policy */
    private function _defaultInstance(): int
    {
        $keys = array_keys($this->_instanceOptions());
        return $keys ? (int)$keys[0] : DeletePolicy::ALL_INSTANCES;
    }

    private function _instanceLabel(int $instanceId): string
    {
        if ($instanceId === DeletePolicy::ALL_INSTANCES) return $this->_s('privacy.option_all_instances');
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `label` FROM `instance` WHERE `instance_id` = ?", 'i', [$instanceId]);
            $label = $this->db->fetchNum()[0] ?? null;
        }
        finally {
            $this->db->popResult();
        }
        return $label === null ? $this->_s('privacy.option_instance_missing') : $this->cms->textOrSnippet((string)$label);
    }

    /**
     * Snippet mit Werten: {count} u. Ä. für plural/select (Cms::snippet()), alle Werte
     * zusätzlich als einfache Platzhalter {name} - die ersetzt der Snippet-Cache nicht.
     */
    private function _s(string $key, array $vars = []): string
    {
        $text = $this->cms->snippet($key, $vars);
        $replace = [];
        foreach ($vars as $name => $value) $replace['{' . $name . '}'] = (string)$value;
        return $replace ? strtr($text, $replace) : $text;
    }

    /** <option>-Liste, Werte maskiert */
    private function _options(array $options, string $selected): string
    {
        $html = '';
        foreach ($options as $value => $label) {
            $html .= '<option value="' . $this->backend->escape((string)$value) . '"' . ((string)$value === $selected ? ' selected' : '') . '>'
                . $this->backend->escape((string)$label) . "</option>\n";
        }
        return $html;
    }
}
