<?php
/**
 * Backend-Oberfläche des Papierkorbs.
 *
 * Einstiegspunkte (Scripte in backend/):
 *
 *     index.php   -> list()      eigene gelöschte Datensätze; Superuser zusätzlich
 *                                gelöschte Bereiche und auf Wunsch (?all=1) die
 *                                Datensätze aller Admins
 *                 -> restore()   function=restore, table=record|area, id - nur POST
 *
 * Dazu das Startseiten-Widget widget() (settings.php 'widget'): die Anzahl der
 * Einträge und ein Knopf in die Liste.
 *
 * FÜR JEDEN ADMIN
 * --------------------
 * Das Modul ist nicht 'listed' und damit nicht Teil der Rechteverwaltung
 * (Access::module() sagt jeder Gruppe ja) - jeder Admin hat seinen Papierkorb.
 * Was darin steht und was er wiederherstellen darf, entscheidet Trash nach
 * `changed_by`, Bereichsrechten und Superuser-Status; das Löschrecht je Datensatz
 * prüft Dataset::restore() noch einmal.
 *
 * @see Camindo\Core\Modules\Trash\Trash  Abfragen und Wiederherstellen
 * @see Camindo\Core\Widgets              Startseiten-Widgets der Module
 */
namespace Camindo\Core\Modules\Trash;

use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Database;

class TrashBackend {

    /** admin_id => Anzeigename, je Request gemerkt */
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
     * Liste (backend/index.php), vorher ggf. die Wiederherstellung.
     *
     * Request: all=1 (nur Superuser) - Datensätze aller Admins statt der eigenen.
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
            // Umschalter eigene / alle Datensätze - ersetzt den Layer (GET)
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

        // Datensätze
        echo $this->backend->template('headline4_mt', [
            '{text}' => htmlspecialchars($this->cms->snippet($all ? 'trash.headline_records_all' : 'trash.headline_records')),
        ]);
        echo $this->_recordTable($this->trash->records($all, Trash::MAX_ENTRIES + 1), $all);

        // Bereiche (Superuser)
        if ($isSuperuser) {
            echo $this->backend->template('headline4_mt', [
                '{text}' => htmlspecialchars($this->cms->snippet('trash.headline_areas')),
            ]);
            echo $this->_areaTable($this->trash->areas(Trash::MAX_ENTRIES + 1), $all);
        }

        echo $this->backend->template('container_footer');
    }

    /**
     * Widget für die Startseite (settings.php 'widget'): Anzahl der Einträge.
     */
    public function widget(): string
    {
        $counts = $this->trash->counts();
        $total = $counts['records'] + $counts['areas'];
        if ($total === 0) {
            return $this->backend->template('alert_success', [
                '{text}' => htmlspecialchars($this->cms->snippet('trash.widget_empty')),
            ]) . $this->backend->template('trash.widget_footer');
        }

        $text = $this->cms->snippet('trash.widget_records', ['count' => $counts['records']]);
        if ($counts['areas'] > 0) {
            $text .= ' ' . $this->cms->snippet('trash.widget_areas', ['count' => $counts['areas']]);
        }
        return $this->backend->template('trash.widget', [
            '{number}' => (string)$total,
            '{text}' => htmlspecialchars($text),
        ]);
    }

    // ====================

    /**
     * function=restore: Eintrag wiederherstellen, Ergebnis als Balloon bzw. roter
     * Kasten über der Liste. Nur per POST (17-csrf.md) - ein GET zeigt nur die Liste.
     */
    private function _restore(): void
    {
        if (!$this->backend->requirePost()) return;
        $table = (string)($_REQUEST['table'] ?? '');
        $id = (int)($_REQUEST['id'] ?? 0);

        $result = $this->trash->restore($table, $id);
        switch ($result) {
            case 'restored':
                $this->backend->balloon('success', $this->cms->snippet(
                    $table === 'area' ? 'trash.message_area_restored' : 'trash.message_record_restored'
                ));
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
     * Tabelle der gelöschten Datensätze.
     *
     * @param array $rows  Trash::records() - eine Zeile mehr als MAX_ENTRIES heißt
     *                     "es gibt mehr"
     * @param bool  $all   Spalte "Gelöscht von" zeigen
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
        $columns[] = '';   // Knopf "Wiederherstellen"

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
     * Tabelle der gelöschten Bereiche (nur Superuser).
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
     * Knopf "Wiederherstellen" als eigenes kleines POST-Formular: der Layer bleibt
     * dabei auf Trash/index.php (die Formularantwort ersetzt nur den Inhalt), ein
     * späteres "Aktualisieren" löst also nichts noch einmal aus.
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
     * Bezeichnung eines gelöschten Datensatzes: Inhalt des Default-Feldes seines
     * Bereichs (wie Backend::teaser(), das gelöschte Datensätze nicht liefert).
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
     * Löschzeitpunkt in Zeitzone und Locale des Admins.
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
     * instance_id => Beschriftung
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
     * Tabellenzeile aus fertigem HTML je Zelle.
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
     * Tabelle mit Kopfzeile; bei $more der Hinweis auf die Obergrenze.
     *
     * @param string[] $columns  Snippet-Schlüssel (trash.{key}) der Spalten, '' = leer
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
