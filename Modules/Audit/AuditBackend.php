<?php
/**
 * Backend UI of the module Audit: search the audit log.
 *
 * Entry points (scripts in backend/):
 *
 *     index.php        -> list()   filters and hit list, paginated (Cms::$list_rows
 *                                   per page, Backend::paginationTop/Bottom)
 *     audit_info.php   -> info()   one entry with before/after
 *
 * Also adminActivity() for the "Activity" tab of the administrator info
 * (AdminBackend::audit()): the most recently edited records and the entries
 * OF ONE administrator.
 *
 * SUPERUSERS ONLY
 * --------------------
 * The log shows content from all areas and instances, plus names and
 * IP addresses. The module sets 'superuser' => true in its settings.php, and every
 * entry point additionally checks Backend::isSuperuser(). The history of ONE
 * record can be seen by every reader of the area in the "Changes" tab of the
 * record info (RecordBackendInfo).
 *
 * TIMES
 * --------------------
 * Stored in UTC. The filters "from"/"to" are calendar days in the time zone
 * of the administrator and are converted to UTC before the query; "to" includes the
 * whole day.
 *
 * @see Camindo\Core\AuditLog    writing, searching, retention
 * @see Camindo\Core\AuditDiff   presentation of an entry
 */
namespace Camindo\Core\Modules\Audit;

use Camindo\Core\AuditDiff;
use Camindo\Core\AuditLog;
use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\Database;

class AuditBackend {

    /** Filter fields from the request */
    private const FILTERS = ['from', 'to', 'admin_id', 'action', 'object_type', 'object_id', 'search', 'field'];

    /** Object type => snippet of the label */
    private const TYPE_LABELS = [
        'record' => 'audit.type_record', 'area' => 'audit.type_area', 'field' => 'audit.type_field',
        'template' => 'audit.type_template', 'admin' => 'audit.type_admin', 'group' => 'audit.type_group',
        'instance' => 'audit.type_instance', 'language' => 'audit.type_language', 'status' => 'audit.type_status',
        'converter' => 'audit.type_converter', 'scheduler' => 'audit.type_scheduler', 'component' => 'audit.type_component',
        'privacy_policy' => 'audit.type_privacy_policy',
    ];

    public function __construct(
        private Backend $backend,
        private Cms $cms,
        private Database $db,
        private AuditLog $auditLog,
        private AuditDiff $auditDiff,
    )
    {}

    /**
     * Search (backend/index.php).
     *
     * Request: the filters from FILTERS, page (from 1).
     */
    public function list(): void
    {
        // Search form submitted: reload with the filters in the URL - like the
        // search of the other modules (AdminBackend::_search()); afterwards the
        // pagination works via the URL without losing the filters
        if ((string)($_POST['function'] ?? '') === 'search') {
            $query = http_build_query(array_filter($this->_input(), fn($v) => $v !== ''));
            $url = json_encode('Audit/index.php' . ($query !== '' ? '?' . $query : ''),
                JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
            $this->backend->script("cApp.layers.replace({$url});");
            return;
        }

        $this->backend->setNaviLabel('#audit.module_label');
        echo $this->backend->template('container_header');

        if (!$this->backend->isSuperuser()) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }

        echo $this->backend->headline('#audit.headline_list');
        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_refresh', "cApp.layers.refresh();");
        echo $this->backend->buttonBar();

        if (!$this->auditLog->enabled()) {
            echo $this->backend->template('alert_warning', ['{text}' => '{#audit.note_disabled}']);
        }

        $input = $this->_input();
        echo $this->_searchForm($input);

        // Paging as in the other lists (Backend::paginationTop/Bottom): they
        // use Cms::$list_rows, $_REQUEST['skip'] and the hit counts of the
        // database - AuditLog queries with pushResult/popResult, so set them here
        $filter = $this->_filter($input);
        $total = $this->auditLog->count($filter);
        $pageSize = max(1, (int)$this->cms->list_rows);
        $skip = max(0, (int)($_REQUEST['skip'] ?? 0));
        if ($skip >= $total) $skip = max(0, (int)(($total - 1) / $pageSize) * $pageSize);
        $skip = (int)($skip / $pageSize) * $pageSize;
        $_REQUEST['skip'] = $skip;
        $rows = $total > 0 ? $this->auditLog->search($filter, $pageSize, $skip) : [];
        $this->db->num_rows = count($rows);
        $this->db->num_rows_unlimited = $total;

        echo $this->backend->paginationTop('audit.listed_records');
        if ($rows) {
            echo $this->table($rows);
            echo $this->backend->paginationBottom();
        }
        echo $this->backend->template('container_footer');
    }

    /**
     * One entry (backend/audit_info.php). Request: id
     */
    public function info(): void
    {
        $this->backend->setNaviLabel('#button_info');
        echo $this->backend->template('container_header');

        if (!$this->backend->isSuperuser()) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }
        $row = $this->auditLog->load((int)($_REQUEST['id'] ?? 0));
        if ($row === null) {
            echo $this->backend->template('alert_danger', ['{text}' => '{#audit.error_not_found}']);
            echo $this->backend->template('container_footer');
            return;
        }

        echo $this->backend->template('headline1', [
            '{text}' => $this->backend->template('audit.icon_module') . ' ' . htmlspecialchars($this->_typeLabel((string)$row['object_type']))
                . ' #' . (int)$row['object_id'] . ((string)$row['label'] !== '' ? ' · ' . htmlspecialchars((string)$row['label']) : ''),
        ]);

        $this->backend->buttonBarReset();
        // Open the record as long as it exists
        if ($row['object_type'] === 'record' && !in_array($row['action'], ['purge'], true) && (int)$row['area_id'] > 0) {
            $this->backend->buttonAdd('audit.button_open_record', "cApp.layers.add('Record/record_info.php?area_id=" . (int)$row['area_id'] . "&id=" . (int)$row['object_id'] . "&tab=audit');");
        }
        $this->backend->buttonAdd('audit.button_history', "cApp.layers.replace('Audit/index.php?object_type="
            . rawurlencode((string)$row['object_type']) . "&object_id=" . (int)$row['object_id'] . "');");
        $this->backend->buttonAdd('button_close', "cApp.layers.close()");
        echo $this->backend->buttonBar();

        // Chain of the file of this object and comparison with the database (AuditStore)
        $check = $this->auditLog->verifyObject((string)$row['object_type'], (int)$row['object_id']);
        echo $check['ok']
            ? $this->backend->template('alert_success', ['{text}' => htmlspecialchars($this->cms->snippet('audit.text_chain_ok'))])
            : $this->backend->template('alert_danger', ['{text}' => htmlspecialchars($this->cms->snippet('audit.text_chain_failed'))
                . '<br><small>' . implode('<br>', array_map('htmlspecialchars', $check['errors'])) . '</small>']);

        echo $this->auditDiff->entry($row, true);
        echo $this->backend->template('container_footer');
    }

    /**
     * Activity of an administrator (tab of the administrator info, superusers only - checked by the
     * caller): the records they last edited - per record the last action and the
     * number of their entries, linked to the history - and below it all their
     * entries, paginated like list().
     */
    public function adminActivity(int $adminId): string
    {
        $html = '';
        if (!$this->auditLog->enabled()) {
            $html .= $this->backend->template('alert_warning', ['{text}' => '{#audit.note_disabled}']);
        }

        // Recently edited records
        $html .= $this->backend->template('headline2', ['{text}' => htmlspecialchars($this->cms->snippet('audit.headline_admin_records'))]);
        $recent = $this->auditLog->recentObjects($adminId, 'record', 15);
        if (!$recent) {
            $html .= $this->backend->template('audit.admin_none');
        }
        else {
            $headColumns = '';
            foreach (['column_record', 'column_area', 'column_last_action', 'column_entries'] as $key) {
                $headColumns .= $this->backend->template('table_head_column', [
                    '{text}' => htmlspecialchars($this->cms->snippet('audit.' . $key)),
                ]);
            }
            $tplRow = $this->backend->template('audit.recent_row');
            $rows = '';
            foreach ($recent as $row) {
                $recordId = (int)$row['object_id'];
                $action = (string)$row['action'];
                // If the record still exists, the teaser links to it; otherwise the label from the log
                $teaser = $action !== 'purge' ? $this->backend->teaser('record', $recordId) : '';
                if ($teaser === '') {
                    $teaser = $this->backend->template('audit.record_label', [
                        '{label}' => $this->backend->escape((string)$row['label']),
                        '{id}' => (string)$recordId,
                    ]);
                }
                $areaId = (int)($row['area_id'] ?? 0);
                $rows .= strtr($tplRow, [
                    '{record}' => $teaser,
                    '{area}' => $areaId > 0 ? $this->backend->teaser('area', $areaId, linked: false) : '',
                    '{level}' => $this->_level($action),
                    '{action}' => htmlspecialchars($this->auditDiff->actionLabel($action)),
                    '{time}' => htmlspecialchars($this->auditDiff->localTime((string)$row['created_at'])),
                    '{object_id}' => $recordId,
                    '{changes}' => (int)$row['changes'],
                ]) . "\n";
            }
            $html .= $this->backend->template('table_with_head', [
                '{head_columns}' => $headColumns,
                '{table_rows}' => $rows,
            ]);
        }

        // All entries, paginated (see list())
        $html .= $this->backend->template('headline2_mt', ['{text}' => htmlspecialchars($this->cms->snippet('audit.headline_admin_actions'))]);
        $filter = ['admin_id' => $adminId];
        $total = $this->auditLog->count($filter);
        $pageSize = max(1, (int)$this->cms->list_rows);
        $skip = max(0, (int)($_REQUEST['skip'] ?? 0));
        if ($skip >= $total) $skip = max(0, (int)(($total - 1) / $pageSize) * $pageSize);
        $skip = (int)($skip / $pageSize) * $pageSize;
        $_REQUEST['skip'] = $skip;
        $rows = $total > 0 ? $this->auditLog->search($filter, $pageSize, $skip) : [];
        $this->db->num_rows = count($rows);
        $this->db->num_rows_unlimited = $total;
        $html .= $this->backend->paginationTop('audit.listed_records');
        if ($rows) {
            $html .= $this->table($rows);
            $html .= $this->backend->paginationBottom();
        }
        return $html;
    }

    // ====================

    /** Color of the action (badge) */
    private function _level(string $action): string
    {
        return match ($action) {
            'create', 'restore', 'login' => 'success',
            'delete', 'purge', 'login_failed' => 'danger',
            default => 'primary',
        };
    }

    /**
     * Filter values from the request, sanitized.
     */
    private function _input(): array
    {
        $input = [];
        foreach (self::FILTERS as $key) {
            $input[$key] = trim((string)($_REQUEST[$key] ?? ''));
        }
        foreach (['from', 'to'] as $key) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $input[$key])) $input[$key] = '';
        }
        foreach (['admin_id', 'object_id'] as $key) {
            $input[$key] = (int)$input[$key] > 0 ? (string)(int)$input[$key] : '';
        }
        if (!in_array($input['action'], AuditLog::ACTIONS, true)) $input['action'] = '';
        if (!preg_match('/^[a-z_]{1,32}$/', $input['object_type'])) $input['object_type'] = '';
        return $input;
    }

    /**
     * Filter for AuditLog::search(): calendar days of the administrator -> UTC.
     */
    private function _filter(array $input): array
    {
        $filter = $input;
        $tz = new \DateTimeZone($this->cms->userTZ !== '' ? $this->cms->userTZ : date_default_timezone_get());
        $utc = new \DateTimeZone('UTC');
        if ($input['from'] !== '') {
            $filter['from'] = (new \DateTimeImmutable($input['from'] . ' 00:00:00', $tz))->setTimezone($utc)->format('Y-m-d H:i:s');
        }
        if ($input['to'] !== '') {
            $filter['to'] = (new \DateTimeImmutable($input['to'] . ' 00:00:00', $tz))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
        }
        return $filter;
    }

    private function _searchForm(array $input): string
    {
        $actions = [];
        foreach (AuditLog::ACTIONS as $action) $actions[$action] = $this->auditDiff->actionLabel($action);
        $types = [];
        foreach ($this->auditLog->objectTypes() as $type) $types[$type] = $this->_typeLabel($type);
        asort($types);

        return $this->backend->template('audit.search_form', [
            '{from}' => htmlspecialchars($input['from']),
            '{to}' => htmlspecialchars($input['to']),
            '{object_id}' => htmlspecialchars($input['object_id']),
            '{search}' => htmlspecialchars($input['search']),
            '{field}' => htmlspecialchars($input['field']),
            '{admin_options}' => $this->_options($this->auditLog->actors(), $input['admin_id']),
            '{action_options}' => $this->_options($actions, $input['action']),
            '{type_options}' => $this->_options($types, $input['object_type']),
        ]);
    }

    /**
     * Table of entries, each row opens audit_info.php - also for the
     * "Activity" tab of the administrator info (AdminBackend::audit()).
     */
    public function table(array $rows): string
    {
        $headColumns = '';
        foreach (['column_time', 'column_action', 'column_object', 'column_actor', 'column_fields'] as $key) {
            $headColumns .= $this->backend->template('table_head_column', [
                '{text}' => htmlspecialchars($this->cms->snippet('audit.' . $key)),
            ]);
        }
        $tplRow = $this->backend->template('audit.table_row');
        $tableRows = '';
        foreach ($rows as $row) {
            $action = (string)$row['action'];
            $fields = [];
            foreach ((array)$row['changes'] as $change) {
                $fields[] = $this->cms->textOrSnippet((string)($change['l'] ?? $change['k'] ?? ''))
                    . ((string)($change['g'] ?? '') !== '' ? ' (' . $change['g'] . ')' : '');
            }
            $fields = array_values(array_unique($fields));
            $fieldText = implode(', ', array_slice($fields, 0, 6)) . (count($fields) > 6 ? ' …' : '');
            $tableRows .= strtr($tplRow, [
                '{id}' => (int)$row['audit_id'],
                '{time}' => htmlspecialchars($this->auditDiff->localTime((string)$row['created_at'])),
                '{level}' => $this->_level($action),
                '{action}' => htmlspecialchars($this->auditDiff->actionLabel($action)),
                '{type}' => htmlspecialchars($this->_typeLabel((string)$row['object_type'])),
                '{object_id}' => (int)$row['object_id'],
                '{label}' => htmlspecialchars((string)$row['label']),
                '{actor}' => htmlspecialchars($this->auditDiff->actorLabel($row)),
                '{origin}' => htmlspecialchars((string)$row['origin'] . ((string)$row['ip'] !== '' ? ', ' . $this->auditDiff->displayIp((string)$row['ip']) : '')),
                '{fields}' => htmlspecialchars($fieldText),
            ]) . "\n";
        }
        return $this->backend->template('table_with_head', [
            '{head_columns}' => $headColumns,
            '{table_rows}' => $tableRows,
        ]);
    }

    private function _typeLabel(string $type): string
    {
        return isset(self::TYPE_LABELS[$type]) ? $this->cms->snippet(self::TYPE_LABELS[$type]) : $type;
    }

    /**
     * Select list with an empty entry "all".
     */
    private function _options(array $options, string $selected): string
    {
        $html = '<option value="">' . htmlspecialchars($this->cms->snippet('audit.option_all')) . '</option>' . "\n";
        foreach ($options as $value => $label) {
            $html .= '<option value="' . htmlspecialchars((string)$value) . '"'
                . ((string)$value === $selected ? ' selected' : '') . '>'
                . htmlspecialchars((string)$label) . "</option>\n";
        }
        return $html;
    }
}
