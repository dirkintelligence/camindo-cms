<?php
/**
 * Dataset child class for the table `status` - the editorial states of a record.
 *
 * A status is pure display information: label, Material icon and
 * Bootstrap color class. It does not (yet) control whether a record appears in the frontend -
 * record.online_at / offline_at are responsible for that.
 *
 *     icon          ligature of the Material Icons font, e.g. 'check', 'block'
 *     color_class   CSS class for the coloring, e.g. 'text-success', 'c9-text-yellow'
 *     position      order in select lists
 *     group_ids     user groups that may set the status (`rel_group_status`,
 *                   'reltable') - the same rows as in the form of the group
 *                   (Group, field statuses). The superuser group may always do everything
 *                   and is not listed. Only a superuser may change the assignment
 *                   (like the permissions of the groups); a new status without
 *                   specification can be set by all groups.
 *
 * The status is used via record.status_id.
 * Records with status_id > 0 are not visible in the frontend.
 *
 * @see Camindo\Core\Access::statusWrite()            set = permission, read = everyone
 * @see Camindo\Core\Modules\Status\StatusBackend
 * @see Camindo\Core\Dataset
 */
namespace Camindo\Core\Modules\Status;

use Camindo\Core\Access;
use Camindo\Core\Dataset;
use Camindo\Core\InstanceCache;
use Camindo\Core\Integrity;
use Camindo\Core\Modules\Admin\Group;

class Status extends Dataset {
    
    /**
     * Replacement status to which records are switched when this status is deleted.
     * Preset to 1 because this status cannot be deleted and all deletable
     * statuses have the same effect: record is not shown in the frontend
     */
    protected int $replacementId = 1; // Replacement for a deleted status in table `record`

    protected function hookDefinition(): void
    {
        $this->tableName  = 'status';
        $this->moduleName = 'Status';
        $this->defaultSortOrder = 'position ASC';
        $this->schema = [
            'status_id' => [
                'type'  => 'integer',
                'label' => '#status.field_status_id',
            ],
            'icon' => [
                'type'  => 'chars',
                'label' => '#status.field_icon',
                'max'   => 64,
                'flags' => ['trim', 'not_empty'],
            ],
            'label' => [
                'type'  => 'chars',
                'label' => '#status.field_label',
                'max'   => 64,
                'extension' => 'Snippets',   // Core extension: select a snippet (#prefix.key)
                'flags' => ['trim', 'not_empty'],
            ],
            'color_class' => [
                'type'  => 'chars',
                'label' => '#status.field_color_class',
                'max'   => 64,
                'flags' => ['trim'],
                'default' => 'text-danger'
            ],
            'position' => [
                'type'  => 'integer',
                'label' => '#status.field_position',
                'display_type' => 'select',
            ],
            // Table `rel_group_status` (without the superuser group)
            'group_ids' => [
                'type'  => 'reltable',
                'display_type' => 'multiselect',
                'label' => '#status.field_group_ids',
                'tooltip' => '#status.tooltip_group_ids',
            ],
        ];
    }

    /**
     * Before creating: put the new status at the end of the sort order.
     */
    protected function hookOnCreate(array &$data): void
    {
        $sql = "SELECT COALESCE(MAX(`position`), 0) + 1 FROM `status`";
        $this->db->query($sql);
        $data['position'] = $this->db->fetchNum()[0] ?? 0;
        // Default: all groups may set the new status
        $data['group_ids'] = $this->groupIds();
    }

    /**
     * Before saving: group_ids only from a superuser, only existing groups
     * (without the superuser group).
     */
    protected function hookOnSave(array &$fields, array &$data, array &$previousData): void
    {
        if (!in_array('group_ids', $fields, true)) return;
        $access = $this->container->get(Access::class);
        if ($access->isLoaded() && !$access->isSuperuser()) {
            // Only a superuser grants permissions - the specification is ignored
            $fields = array_values(array_diff($fields, ['group_ids']));
            unset($data['group_ids']);
            return;
        }
        $data['group_ids'] = array_values(array_intersect(
            $this->groupIds(),
            array_map('intval', (array)($data['group_ids'] ?? []))
        ));
    }

    /**
     * After saving: write the group assignment (writeGroups()). A new
     * status without specification can be set by all groups.
     */
    protected function hookOnSaved(array &$fields, array &$data, array &$previousData): void
    {
        $isNew = empty($previousData);
        if (!in_array('group_ids', $fields, true)) {
            if (!$isNew) return;
            $data['group_ids'] = $this->groupIds();
        }
        $this->writeGroups((int)($data['status_id'] ?? 0), (array)$data['group_ids'], (array)($previousData['group_ids'] ?? []), $isNew);
    }

    /**
     * Change the group assignment of a status without save() - for the two basic statuses:
     * status_id 0 would be treated by Dataset::save() as a new record. Superusers only.
     *
     * @param int[] $groupIds  groups that may set the status
     * @return bool            false: message in Cms::errors
     */
    public function saveGroups(int $statusId, array $groupIds): bool
    {
        $access = $this->container->get(Access::class);
        if ($access->isLoaded() && !$access->isSuperuser()) {
            $this->cms->error($this->cms->snippet('error_access_denied'));
            return false;
        }
        if (!$previous = $this->load($statusId)) return false;
        $groupIds = array_values(array_intersect($this->groupIds(), array_map('intval', $groupIds)));
        return (bool)$this->transactional(function () use ($statusId, $groupIds, $previous) {
            $this->writeGroups($statusId, $groupIds, (array)($previous['group_ids'] ?? []));
            return true;
        }, false);
    }

    /**
     * Write `rel_group_status` of a status (signed, Integrity), then
     * renew group files and sessions of the affected groups.
     */
    private function writeGroups(int $statusId, array $groupIds, array $oldIds, bool $isNew = false): void
    {
        $newIds = array_map('intval', $groupIds);
        $oldIds = array_map('intval', $oldIds);
        $this->db->query("DELETE FROM `rel_group_status` WHERE `status_id` = ?", 'i', [$statusId]);
        $integrity = $this->container->get(Integrity::class);
        foreach ($newIds as $groupId) {
            $this->db->query(
                "INSERT INTO `rel_group_status` (`group_id`, `status_id`, `sig`) VALUES (?, ?, ?)",
                'iis', [$groupId, $statusId, $integrity->statusRightSignature($groupId, $statusId)]
            );
        }
        $changed = array_values(array_unique(array_merge(array_diff($newIds, $oldIds), array_diff($oldIds, $newIds))));
        if (empty($changed)) {
            if ($isNew) $this->container->get(InstanceCache::class)->rebuild();
            return;
        }
        // Renew group files, update the sessions of the administrators of these groups
        $group = $this->container->get(Group::class);
        foreach ($changed as $groupId) {
            $group->afterChange($groupId);
        }
    }

    /**
     * After loading: the groups that may set the status.
     */
    protected function hookOnLoaded(array &$data): void
    {
        $this->db->pushResult();
        $this->db->query("SELECT `group_id` FROM `rel_group_status` WHERE `status_id` = ? ORDER BY `group_id`", 'i', [(int)($data['status_id'] ?? 0)]);
        $data['group_ids'] = array_map('intval', $this->db->fetchColumn());
        $this->db->popResult();
    }

    /**
     * After deleting: the foreign key removes the rows in `rel_group_status`,
     * the group files are rewritten.
     */
    protected function hookOnDeleted(array $data): void
    {
        $this->container->get(InstanceCache::class)->rebuild();
    }

    /**
     * Groups to which statuses can be assigned: all except the
     * superuser group (it may do everything anyway), sorted by group_id.
     *
     * @return int[]
     */
    public function groupIds(): array
    {
        $this->db->pushResult();
        $this->db->query("SELECT `group_id` FROM `group` WHERE `group_id` <> ? ORDER BY `group_id`", 'i', [InstanceCache::SUPERUSER_GROUP_ID]);
        $ids = array_map('intval', $this->db->fetchColumn());
        $this->db->popResult();
        return $ids;
    }

    /**
     * Before deleting... (rollback on errors)
     *
     * Moves all records with the deleted status to $replacementId. Without this,
     * record.status_id would point to a status that no longer exists (there is no
     * foreign key for this field that would prevent it).
     *
     * Runs in the transaction of Dataset::delete(): if the UPDATE throws, the
     * deletion is rolled back too.
     */
    protected function hookOnDelete(array $data): void
    {
        // Switch records to the replacement status
        $query = "UPDATE `record` SET `status_id` = ? WHERE `status_id` = ?";
        $this->db->query($query, 'ii', [$this->replacementId, $data['status_id']]);
    }

    // Special functions for Status
    // --------------------

    /**
     * Set the replacement status for the next delete() call.
     * Must be set BEFORE delete(); afterwards it has no effect.
     *
     * @param int $replacementId  status_id to which affected records are switched
     */
    public function setReplacementId(int $replacementId): void
    {
        $this->replacementId = $replacementId;
    }

}