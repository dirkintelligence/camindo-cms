<?php
/**
 * Prepare records for lists - the same display in the backend
 * (RecordBackendList, lookup preview, "Last changed") and in the API
 * (RecordApi::search(), there without HTML).
 *
 * WHICH COLUMNS
 * --------------------
 * field.visibility_list decides (listFields()); status_id is always first.
 * Without a visible field, the record_id remains.
 *
 * WHAT A VALUE LOOKS LIKE
 * --------------------
 * listValue(): status_id as a symbol (with a read-back hint), template_id,
 * parent_id and position (= the preceding record) as plain text, everything else
 * via Backend::backendValue() in the short form of the list. The result is HTML.
 *
 * In addition, each area can have a frontend template supply the last column
 * (`area`.`list_template_id`, listTemplateHtml()).
 *
 * The caller brackets each row with Database::pushResult()/popResult():
 * teaser(), previousId() and backendValue() run queries themselves.
 *
 * @see Camindo\Core\Backend::backendValue()
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\Backend;
use Camindo\Core\Database;
use Camindo\Core\Page;

class RecordPresenter
{
    public function __construct(
        private Database $db,
        private Backend $backend,
    )
    {
    }

    /**
     * Fields of an area that appear in the record list (visibility_list = 1) -
     * status_id always first (quick filter in the column header). Without a visible
     * field, the record_id remains; it is added to the schema for this purpose.
     *
     * @param array &$schemaVirtual  Schema of the area (Record::virtualSchema())
     * @return string[]              Field names in list order
     */
    public function listFields(array &$schemaVirtual): array
    {
        $fields = [];
        foreach ($schemaVirtual as $name => $colDef) {
            if ((int)($colDef['visibility_list'] ?? 0) !== 1) continue;
            if ($name === 'status_id') array_unshift($fields, $name);
            else $fields[] = $name;
        }
        if (empty($fields)) {
            $fields = ['record_id'];
            $schemaVirtual['record_id'] = [
                'type' => 'integer',
                'label' => 'record_id'
            ];
        }
        return $fields;
    }

    /**
     * Prepare the value of a field for the record list (see class comment).
     *
     * @param string $name           Field name
     * @param array  $row            Record (Cms::fetch())
     * @param array  $schemaVirtual  Schema of the area
     * @return string                HTML
     */
    public function listValue(string $name, array $row, array $schemaVirtual): string
    {
        if ($name === 'status_id') {
            $value = $this->backend->teaser('status', (int)($row['status_id'] ?? 0), icon:true, label:false);
            if (!empty($row['needs_review'])) {
                $value .= $this->backend->template('user.icon_unreviewed');
            }
            return $value;
        }
        if ($name === 'needs_review') {
            return !empty($row['needs_review'])
                ? $this->backend->template('user.icon_unreviewed')
                : $this->backend->emptyPlaceholder;
        }
        if ($name === 'template_id') {
            return $this->backend->teaser('template', (int)($row['template_id'] ?? 0), linked:false, icon:false);
        }
        if ($name === 'parent_id') {
            return $this->backend->teaser('record', (int)($row['parent_id'] ?? 0), linked:false, icon:false);
        }
        if ($name === 'position') {
            // list the preceding record
            if ($previousId = $this->previousId($row, isset($schemaVirtual['parent_id']))) {
                return $this->backend->teaser('record', $previousId, linked:false, icon:false);
            }
            return '';
        }
        return $this->backend->backendValue($row[$name] ?? null, $schemaVirtual[$name] ?? [], id: (int)$row['record_id']);
    }

    /**
     * Run the list template of the area (`area`.`list_template_id`) for a record -
     * '' if the area has not selected one. The template may run its own queries
     * (CMS:SELECT) - the caller brackets them.
     *
     * @param int   $areaId  Area of the record
     * @param array $row     Record (Cms::fetch())
     */
    public function listTemplateHtml(int $areaId, array $row): string
    {
        $templateId = (int)($this->backend->areaData($areaId)['list_template_id'] ?? 0);
        if ($templateId < 1) return '';
        return $GLOBALS['app']->get(Page::class)->renderRecord($templateId, $row);
    }

    /**
     * Id of the preceding record - in the tree by `lft` under the same
     * parent record, otherwise by `position` in the area.
     *
     * @param array $data  Record (Record::load() / Cms::fetch())
     * @param bool  $tree  Area with input field parent_id (tree)
     * @return int|null    record_id or null (first)
     */
    public function previousId(array $data, bool $tree): ?int
    {
        if ($tree) {
            if ($parentId = (int)$data['parent_id']) {
                $sql = "SELECT `record_id` FROM `record` WHERE `parent_id` = ? AND `lft` < ? AND `area_id`= ?"
                    ." ORDER BY `lft` DESC LIMIT 1";
                $this->db->query($sql, 'iii', [$parentId, (int)$data['lft'], (int)$data['area_id']]);
            }
            else {
                $sql = "SELECT `record_id` FROM `record` WHERE `parent_id` IS NULL AND `lft` < ? AND `area_id`= ?"
                    ." ORDER BY `lft` DESC LIMIT 1";
                $this->db->query($sql, 'ii', [(int)$data['lft'], (int)$data['area_id']]);
            }
        }
        else {
            $sql = "SELECT `record_id` FROM `record` WHERE `position` < ? AND `area_id`= ?"
                ." ORDER BY `position` DESC LIMIT 1";
            $this->db->query($sql, 'ii', [(int)$data['position'], (int)$data['area_id']]);
        }
        if ($previousRecord = $this->db->fetch()) {
            return (int)$previousRecord['record_id'];
        }
        return null;
    }
}
