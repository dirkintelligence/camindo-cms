<?php
/**
 * Backend UI of the trash.
 *
 * Entry points (scripts in backend/):
 *
 *     index.php   -> list()      own deleted records; superusers additionally
 *                                deleted areas and input fields and, on request
 *                                (?all=1), the records of all administrators
 *                 -> restore()   function=restore, table=record|area|field, id - POST only
 *
 * Also the start page widget widget() (settings.php 'widget'): the number of
 * entries and a button to the list.
 *
 * FOR EVERY ADMINISTRATOR
 * --------------------
 * The module is not 'listed' and therefore not part of the permission management
 * (Access::module() says yes to every group) - every administrator has their own trash.
 * Trash decides what is in it and what they may restore by
 * `changed_by`, area permissions and superuser status; Dataset::restore() checks
 * the delete permission per record once more.
 *
 * @see Camindo\Core\Modules\Trash\Trash  queries and restoring
 * @see Camindo\Core\Widgets              start page widgets of the modules
 */
namespace Camindo\Core\Modules\Trash;

use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Database;

class TrashBackend {

    /** admin_id => display name, remembered per request */
    private array $adminNames = [];

    public function __construct(
        private Backend $backend,
        private Cms $cms,
        private Config $config,
        private Database $db,
        private Trash $trash,
    )
    {}

    /**
     * List (backend/index.php), beforehand the restore if applicable.
     *
     * Request: all=1 (superusers only) - records of all administrators instead of own.
     */
    public function list(): void
    {
        $this->backend->setNaviLabel('#trash.module_label');
        echo $this->backend->template('container_header');

        $isSuperuser = $this->backend->isSuperuser();
        $all = $isSuperuser && !empty($_REQUEST['all']);

        if ((string)($_REQUEST['function'] ?? '') === 'restore') {
            $this->_restore();
        }

        echo $this->backend->headline('#trash.headline_list');

        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_refresh', "cApp.layers.refresh();");
        if ($isSuperuser) {
            // Toggle own / all records - replaces the layer (GET)
            $this->backend->buttonAdd(
                'button_secondary',
                $all ? "cApp.layers.replace('Trash/index.php');" : "cApp.layers.replace('Trash/index.php?all=1');",
                $this->backend->template('icon', ['{name}' => $all ? 'person' : 'group']) . ' '
                    . htmlspecialchars($this->cms->snippet($all ? 'trash.button_own' : 'trash.button_all'))
            );
        }
        echo $this->backend->buttonBar();

        echo $this->backend->template('remark_block', [
            '{text}' => htmlspecialchars($this->cms->snippet('trash.note_retention', [
                'days' => max(0, (int)($this->config->get('admin.trash_retention_days') ?? 7)),
            ])),
        ]);

        // Records
        echo $this->backend->template('headline4_mt', [
            '{text}' => htmlspecialchars($this->cms->snippet($all ? 'trash.headline_records_all' : 'trash.headline_records')),
        ]);
        echo $this->_recordTable($this->trash->records($all, Trash::MAX_ENTRIES + 1), $all);

        // Areas (superusers)
        if ($isSuperuser) {
            echo $this->backend->template('headline4_mt', [
                '{text}' => htmlspecialchars($this->cms->snippet('trash.headline_areas')),
            ]);
            echo $this->_areaTable($this->trash->areas(Trash::MAX_ENTRIES + 1), $all);

            // Input fields
            echo $this->backend->template('headline4_mt', [
                '{text}' => htmlspecialchars($this->cms->snippet('trash.headline_fields')),
            ]);
            echo $this->_fieldTable($this->trash->fields(Trash::MAX_ENTRIES + 1), $all);
        }

        echo $this->backend->template('container_footer');
    }

    /**
     * Widget for the start page (settings.php 'widget'): number of entries.
     */
    public function widget(): string
    {
        $counts = $this->trash->counts();
        $total = $counts['records'] + $counts['areas'] + $counts['fields'];
        if ($total === 0) {
            return $this->backend->template('alert_success', [
                '{text}' => htmlspecialchars($this->cms->snippet('trash.widget_empty')),
            ]) . $this->backend->template('trash.widget_footer');
        }

        $text = $this->cms->snippet('trash.widget_records', ['count' => $counts['records']]);
        if ($counts['areas'] > 0) {
            $text .= ' ' . $this->cms->snippet('trash.widget_areas', ['count' => $counts['areas']]);
        }
        if ($counts['fields'] > 0) {
            $text .= ' ' . $this->cms->snippet('trash.widget_fields', ['count' => $counts['fields']]);
        }
        return $this->backend->template('trash.widget', [
            '{number}' => (string)$total,
            '{text}' => htmlspecialchars($text),
        ]);
    }

    // ====================

    /**
     * function=restore: restore the entry, result as balloon or red
     * box above the list. POST only (developer docs, chapter 14 (Security: web)) - a GET only shows the list.
     */
    private function _restore(): void
    {
        if (!$this->backend->requirePost()) return;
        $table = (string)($_REQUEST['table'] ?? '');
        $id = (int)($_REQUEST['id'] ?? 0);

        $result = $this->trash->restore($table, $id);
        switch ($result) {
            case 'restored':
                $this->backend->balloon('success', $this->cms->snippet(match ($table) {
                    'area'  => 'trash.message_area_restored',
                    'field' => 'trash.message_field_restored',
                    default => 'trash.message_record_restored',
                }));
                break;
            case 'background':
                $this->backend->balloon('info', $this->cms->snippet('message_background_started'));
                break;
            case 'denied':
                echo $this->backend->template('alert_danger', [
                    '{text}' => htmlspecialchars($this->cms->snippet('trash.error_not_restorable')),
                ]);
                break;
            default:
                echo $this->backend->template('alert_danger', [
                    '{text}' => $this->cms->errorMsg() ?: htmlspecialchars($this->cms->snippet('trash.error_restore_failed')),
                ]);
        }
    }

    /**
     * Table of the deleted records.
     *
     * @param array $rows  Trash::records() - one row more than MAX_ENTRIES means
     *                     "there are more"
     * @param bool  $all   show column "Deleted by"
     */
    private function _recordTable(array $rows, bool $all): string
    {
        if (!$rows) {
            return $this->backend->template('alert_success', [
                '{text}' => htmlspecialchars($this->cms->snippet('trash.note_no_records')),
            ]);
        }
        $more = count($rows) > Trash::MAX_ENTRIES;
        $rows = array_slice($rows, 0, Trash::MAX_ENTRIES);

        $columns = ['column_deleted_at', 'column_record', 'column_area'];
        if ($all) $columns[] = 'column_deleted_by';
        $columns[] = 'column_expires';
        $columns[] = '';   // "Restore" button

        $access = $this->backend->access();
        $tableRows = '';
        foreach ($rows as $row) {
            $areaId = (int)$row['area_id'];
            $areaData = $this->backend->areaData($areaId) ?? [];
            $ownerId = $row['owner_id'] !== null ? (int)$row['owner_id'] : null;
            $children = (int)$row['children'];

            $label = $this->_recordLabel((int)$row['record_id'], $areaData);
            if ($children > 0) {
                $label .= ' ' . $this->backend->template('small-nowrap', [
                    '{text}' => htmlspecialchars($this->cms->snippet('trash.note_children', ['count' => $children])),
                ]);
            }

            $cells = [
                $this->_date((string)$row['changed_at']),
                $label,
                $this->backend->template('icon', ['{name}' => htmlspecialchars((string)($areaData['icon'] ?? ''))])
                    . ' ' . htmlspecialchars($this->cms->textOrSnippet((string)($areaData['label'] ?? ''))),
            ];
            if ($all) $cells[] = htmlspecialchars($this->_adminName((int)$row['changed_by']));
            $cells[] = htmlspecialchars($this->cms->_formatDate($this->trash->expiresAt((string)$row['changed_at'])));
            $cells[] = $access->areaDelete($areaId, $ownerId)
                ? $this->_restoreForm('record', (int)$row['record_id'], $all, $children > 0 ? 'trash.confirm_restore_branch' : 'trash.confirm_restore_record')
                : $this->backend->template('small-nowrap', ['{text}' => htmlspecialchars($this->cms->snippet('trash.note_no_right'))]);

            $tableRows .= $this->_row($cells);
        }
        return $this->_table($columns, $tableRows, $more);
    }

    /**
     * Table of the deleted areas (superusers only).
     */
    private function _areaTable(array $rows, bool $all): string
    {
        if (!$rows) {
            return $this->backend->template('alert_success', [
                '{text}' => htmlspecialchars($this->cms->snippet('trash.note_no_areas')),
            ]);
        }
        $more = count($rows) > Trash::MAX_ENTRIES;
        $rows = array_slice($rows, 0, Trash::MAX_ENTRIES);

        $columns = ['column_deleted_at', 'column_area', 'column_instance', 'column_contents', 'column_deleted_by', 'column_expires', ''];
        $instanceLabels = $this->_instanceLabels();

        $tableRows = '';
        foreach ($rows as $row) {
            $contents = [];
            $contents[] = $this->cms->snippet('trash.note_records', ['count' => (int)$row['records']]);
            if ((int)$row['subareas'] > 0) {
                $contents[] = $this->cms->snippet('trash.note_subareas', ['count' => (int)$row['subareas']]);
            }
            $label = $this->backend->template('icon', ['{name}' => htmlspecialchars((string)$row['icon'])])
                . ' ' . htmlspecialchars($this->cms->textOrSnippet((string)$row['label']))
                . ' ' . $this->backend->template('small-nowrap', [
                    '{text}' => htmlspecialchars((string)$row['name'] . ' · ' . (string)$row['type']),
                ]);

            $tableRows .= $this->_row([
                $this->_date((string)$row['changed_at']),
                $label,
                htmlspecialchars($instanceLabels[(int)$row['instance_id']] ?? (string)$row['instance_id']),
                htmlspecialchars(implode(', ', $contents)),
                htmlspecialchars($this->_adminName((int)$row['changed_by'])),
                htmlspecialchars($this->cms->_formatDate($this->trash->expiresAt((string)$row['changed_at']))),
                $this->_restoreForm('area', (int)$row['area_id'], $all, 'trash.confirm_restore_area'),
            ]);
        }
        return $this->_table($columns, $tableRows, $more);
    }

    /**
     * Table of the deleted input fields (superusers only).
     */
    private function _fieldTable(array $rows, bool $all): string
    {
        if (!$rows) {
            return $this->backend->template('alert_success', [
                '{text}' => htmlspecialchars($this->cms->snippet('trash.note_no_fields')),
            ]);
        }
        $more = count($rows) > Trash::MAX_ENTRIES;
        $rows = array_slice($rows, 0, Trash::MAX_ENTRIES);

        $columns = ['column_deleted_at', 'column_field', 'column_area', 'column_contents', 'column_deleted_by', 'column_expires', ''];

        $tableRows = '';
        foreach ($rows as $row) {
            $areaData = $this->backend->areaData((int)$row['area_id']) ?? [];
            $contents = [];
            if ((string)$row['counter_field'] !== '') {
                $contents[] = $this->cms->snippet('trash.note_counter_field', ['name' => (string)$row['counter_field']]);
            }
            if ((int)$row['thumbnails'] > 0) {
                $contents[] = $this->cms->snippet('trash.note_thumbnails', ['count' => (int)$row['thumbnails']]);
            }
            $label = htmlspecialchars($this->cms->textOrSnippet((string)$row['label']))
                . ' ' . $this->backend->template('small-nowrap', [
                    '{text}' => htmlspecialchars((string)$row['name'] . ' · ' . (string)$row['type']),
                ]);

            $tableRows .= $this->_row([
                $this->_date((string)$row['changed_at']),
                $label,
                $this->backend->template('icon', ['{name}' => htmlspecialchars((string)($areaData['icon'] ?? ''))])
                    . ' ' . htmlspecialchars($this->cms->textOrSnippet((string)($areaData['label'] ?? ''))),
                htmlspecialchars(implode(', ', $contents)),
                htmlspecialchars($this->_adminName((int)$row['changed_by'])),
                htmlspecialchars($this->cms->_formatDate($this->trash->expiresAt((string)$row['changed_at']))),
                $this->_restoreForm('field', (int)$row['field_id'], $all, 'trash.confirm_restore_field'),
            ]);
        }
        return $this->_table($columns, $tableRows, $more);
    }

    /**
     * "Restore" button as its own small POST form: the layer stays
     * on Trash/index.php (the form response only replaces the content), so a
     * later "refresh" does not trigger anything again.
     */
    private function _restoreForm(string $table, int $id, bool $all, string $confirmSnippet): string
    {
        return $this->backend->template('trash.restore_form', [
            '{action}' => $all ? 'Trash/index.php?all=1' : 'Trash/index.php',
            '{table}' => $table,
            '{id}' => (string)$id,
            '{confirm}' => htmlspecialchars($this->cms->snippet($confirmSnippet)),
        ]);
    }

    /**
     * Label of a deleted record: content of the default field of its
     * area (like Backend::teaser(), which does not return deleted records).
     */
    private function _recordLabel(int $recordId, array $areaData): string
    {
        $fieldName = (string)($areaData['default_field_name'] ?? '');
        $fieldType = (string)($areaData['default_field_type'] ?? 'text');
        $value = '';
        if ($fieldName !== '' && !in_array($fieldType, $this->cms->relationTypes, true)) {
            $this->db->pushResult();
            $this->db->query("SELECT * FROM `record` WHERE `record_id` = ?", 'i', [$recordId]);
            $data = $this->cms->fetch();
            $this->db->popResult();
            if ($data !== null) {
                $value = $this->backend->backendValue($data[$fieldName] ?? '', ['type' => $fieldType]);
            }
        }
        if (trim(strip_tags((string)$value)) === '') {
            $value = htmlspecialchars($this->cms->snippet('trash.label_record', ['id' => $recordId]));
        }
        return $value . ' ' . $this->backend->template('small-nowrap', ['{text}' => 'ID ' . $recordId]);
    }

    /**
     * Deletion time in the time zone and locale of the administrator.
     */
    private function _date(string $changedAt): string
    {
        return $this->backend->template('small-nowrap', [
            '{text}' => htmlspecialchars($this->cms->_formatDate($this->trash->localTime($changedAt))),
        ]);
    }

    private function _adminName(int $adminId): string
    {
        if ($adminId < 1) return '–';
        if (!array_key_exists($adminId, $this->adminNames)) {
            $this->db->pushResult();
            $this->db->query("SELECT `name`, `displayname` FROM `admin` WHERE `admin_id` = ?", 'i', [$adminId]);
            $row = $this->db->fetch();
            $this->db->popResult();
            $this->adminNames[$adminId] = $row
                ? ((string)($row['displayname'] ?? '') ?: (string)$row['name'])
                : '#' . $adminId;
        }
        return $this->adminNames[$adminId];
    }

    /**
     * instance_id => label
     */
    private function _instanceLabels(): array
    {
        $labels = [];
        $this->db->pushResult();
        $this->db->query("SELECT `instance_id`, `label` FROM `instance`");
        while ($row = $this->db->fetch()) {
            $labels[(int)$row['instance_id']] = $this->cms->textOrSnippet((string)$row['label']);
        }
        $this->db->popResult();
        return $labels;
    }

    /**
     * Table row from finished HTML per cell.
     */
    private function _row(array $cells): string
    {
        $html = '';
        foreach ($cells as $cell) {
            $html .= '<td>' . $cell . '</td>';
        }
        return '<tr>' . $html . "</tr>\n";
    }

    /**
     * Table with header row; with $more the note about the upper limit.
     *
     * @param string[] $columns  snippet keys (trash.{key}) of the columns, '' = empty
     */
    private function _table(array $columns, string $rows, bool $more): string
    {
        $headColumns = '';
        foreach ($columns as $key) {
            $headColumns .= $this->backend->template('table_head_column', [
                '{text}' => $key === '' ? '' : htmlspecialchars($this->cms->snippet('trash.' . $key)),
            ]);
        }
        $html = $this->backend->template('table_with_head', [
            '{head_columns}' => $headColumns,
            '{table_rows}' => $rows,
        ]);
        if ($more) {
            $html .= $this->backend->template('remark_block', [
                '{text}' => htmlspecialchars($this->cms->snippet('trash.note_limit', ['limit' => Trash::MAX_ENTRIES])),
            ]);
        }
        return $html;
    }
}
