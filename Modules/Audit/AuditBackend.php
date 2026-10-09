<?php
/**
 * Backend-Oberfläche des Moduls Audit: das Audit-Log durchsuchen.
 *
 * Einstiegspunkte (Scripte in backend/):
 *
 *     index.php        -> list()   Filter und Trefferliste, blätterbar (Cms::$list_rows
 *                                   je Seite, Backend::paginationTop/Bottom)
 *     audit_info.php   -> info()   ein Eintrag mit Vorher/Nachher
 *
 * Dazu adminActivity() für den Reiter "Aktivitäten" der Admin-Info
 * (AdminBackend::audit()): die zuletzt bearbeiteten Datensätze und die Einträge
 * EINES Admins.
 *
 * NUR FÜR SUPERUSER
 * --------------------
 * Das Protokoll zeigt Inhalte aus allen Bereichen und Instanzen, dazu Namen und
 * IP-Adressen. Das Modul trägt 'superuser' => true in seiner settings.php, jeder
 * Einstiegspunkt prüft zusätzlich Backend::isSuperuser(). Den Verlauf EINES
 * Datensatzes sieht jeder Leser des Bereichs im Reiter "Änderungen" der
 * Datensatz-Info (RecordBackendInfo).
 *
 * ZEITEN
 * --------------------
 * Gespeichert wird in UTC. Die Filter "von"/"bis" sind Kalendertage in der Zeitzone
 * des Admins und werden vor der Abfrage nach UTC umgerechnet; "bis" schließt den
 * ganzen Tag ein.
 *
 * @see Camindo\Core\AuditLog    Schreiben, Suchen, Aufbewahrung
 * @see Camindo\Core\AuditDiff   Darstellung eines Eintrags
 */
namespace Camindo\Core\Modules\Audit;

use Camindo\Core\AuditDiff;
use Camindo\Core\AuditLog;
use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\Database;

class AuditBackend {

    /** Filterfelder aus dem Request */
    private const FILTERS = ['from', 'to', 'admin_id', 'action', 'object_type', 'object_id', 'search', 'field'];

    /** Objekttyp => Snippet der Beschriftung */
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
     * Recherche (backend/index.php).
     *
     * Request: die Filter aus FILTERS, page (ab 1).
     */
    public function list(): void
    {
        // Suchformular abgeschickt: mit den Filtern in der URL neu laden - wie die
        // Suche der anderen Module (AdminBackend::_search()); danach blättert die
        // Pagination über die URL, ohne die Filter zu verlieren
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

        // Blättern wie in den anderen Listen (Backend::paginationTop/Bottom): sie
        // rechnen mit Cms::$list_rows, $_REQUEST['skip'] und den Trefferzahlen der
        // Datenbank - AuditLog fragt mit pushResult/popResult ab, deshalb hier setzen
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
     * Ein Eintrag (backend/audit_info.php). Request: id
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
        // Datensatz öffnen, solange es ihn gibt
        if ($row['object_type'] === 'record' && !in_array($row['action'], ['purge'], true) && (int)$row['area_id'] > 0) {
            $this->backend->buttonAdd('audit.button_open_record', "cApp.layers.add('Record/record_info.php?area_id=" . (int)$row['area_id'] . "&id=" . (int)$row['object_id'] . "&tab=audit');");
        }
        $this->backend->buttonAdd('audit.button_history', "cApp.layers.replace('Audit/index.php?object_type="
            . rawurlencode((string)$row['object_type']) . "&object_id=" . (int)$row['object_id'] . "');");
        $this->backend->buttonAdd('button_close', "cApp.layers.close()");
        echo $this->backend->buttonBar();

        // Kette der Datei dieses Objekts und Abgleich mit der Datenbank (AuditStore)
        $check = $this->auditLog->verifyObject((string)$row['object_type'], (int)$row['object_id']);
        echo $check['ok']
            ? $this->backend->template('alert_success', ['{text}' => htmlspecialchars($this->cms->snippet('audit.text_chain_ok'))])
            : $this->backend->template('alert_danger', ['{text}' => htmlspecialchars($this->cms->snippet('audit.text_chain_failed'))
                . '<br><small>' . implode('<br>', array_map('htmlspecialchars', $check['errors'])) . '</small>']);

        echo $this->auditDiff->entry($row, true);
        echo $this->backend->template('container_footer');
    }

    /**
     * Aktivitäten eines Admins (Reiter der Admin-Info, nur Superuser - prüft der
     * Aufrufer): die zuletzt von ihm bearbeiteten Datensätze - je Datensatz die
     * letzte Aktion und die Zahl seiner Einträge, verlinkt auf den Verlauf - und
     * darunter alle seine Einträge, blätterbar wie list().
     */
    public function adminActivity(int $adminId): string
    {
        $html = '';
        if (!$this->auditLog->enabled()) {
            $html .= $this->backend->template('alert_warning', ['{text}' => '{#audit.note_disabled}']);
        }

        // Zuletzt bearbeitete Datensätze
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
                // Gibt es den Datensatz noch, verlinkt der Teaser; sonst die Bezeichnung aus dem Protokoll
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

        // Alle Einträge, blätterbar (siehe list())
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

    /** Farbe der Aktion (Badge) */
    private function _level(string $action): string
    {
        return match ($action) {
            'create', 'restore', 'login' => 'success',
            'delete', 'purge', 'login_failed' => 'danger',
            default => 'primary',
        };
    }

    /**
     * Filterwerte aus dem Request, bereinigt.
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
     * Filter für AuditLog::search(): Kalendertage des Admins -> UTC.
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
     * Tabelle der Einträge, jede Zeile öffnet audit_info.php - auch für den Reiter
     * "Aktivitäten" der Admin-Info (AdminBackend::audit()).
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
     * Auswahlliste mit leerem Eintrag "alle".
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
