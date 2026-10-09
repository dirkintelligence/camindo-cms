<?php
/**
 * Dataset-Kindklasse für die Tabelle `status` - die Redaktions-Zustände eines Datensatzes.
 *
 * Ein Status ist reine Anzeige-Information: Beschriftung, Material-Icon und
 * Bootstrap-Farbklasse. Er steuert (noch) nicht, ob ein Datensatz im Frontend
 * erscheint - dafür sind record.online_at / offline_at zuständig.
 *
 *     icon          Ligatur der Material-Icons-Schrift, z. B. 'check', 'block'
 *     color_class   CSS-Klasse für die Einfärbung, z. B. 'text-success', 'c9-text-yellow'
 *     position      Reihenfolge in Auswahllisten
 *     group_ids     Benutzergruppen, die den Status setzen dürfen (`rel_group_status`,
 *                   'reltable') - dieselben Zeilen wie im Formular der Gruppe
 *                   (Group, Feld statuses). Die Superuser-Gruppe darf immer alles
 *                   und wird nicht geführt. Ändern darf die Zuordnung nur ein
 *                   Superuser (wie die Rechte der Gruppen); ein neuer Status ohne
 *                   Angabe ist für alle Gruppen setzbar.
 *
 * Verwendet wird der Status über record.status_id.
 * Records mit stauts_id > 0 sind im Frontend nicht sichtbar.
 *
 * @see Camindo\Core\Access::statusWrite()            setzen = Recht, lesen = alle
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
     * Ersatz-Status, auf den Datensätze beim Löschen dieses Status umgestellt werden.
     * Vorbelegt mit 1 weil dieser Status nicht gelöscht werden kann und alle löschbaren
     * Status die selbe Auswirkung haben: Datensatz wird nicht im Frontend angezeigt
     */
    protected int $replacementId = 1; // Ersatz für gelöschten Status in Tabelle `record`

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
                'extension' => 'Snippets',   // Core-Extension: Snippet auswählen (#prefix.key)
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
            // Tabelle `rel_group_status` (ohne die Superuser-Gruppe)
            'group_ids' => [
                'type'  => 'reltable',
                'display_type' => 'multiselect',
                'label' => '#status.field_group_ids',
                'tooltip' => '#status.tooltip_group_ids',
            ],
        ];
    }

    /**
     * Vor dem Erstellen: neuen Status ans Ende der Sortierung stellen.
     */
    protected function hookOnCreate(array &$data): void
    {
        $sql = "SELECT COALESCE(MAX(`position`), 0) + 1 FROM `status`";
        $this->db->query($sql);
        $data['position'] = $this->db->fetchNum()[0] ?? 0;
        // Voreinstellung: alle Gruppen dürfen den neuen Status setzen
        $data['group_ids'] = $this->groupIds();
    }

    /**
     * Vor dem Speichern: group_ids nur von einem Superuser, nur vorhandene Gruppen
     * (ohne die Superuser-Gruppe).
     */
    protected function hookOnSave(array &$fields, array &$data, array &$previousData): void
    {
        if (!in_array('group_ids', $fields, true)) return;
        $access = $this->container->get(Access::class);
        if ($access->isLoaded() && !$access->isSuperuser()) {
            // Rechte vergibt nur ein Superuser - die Angabe bleibt unbeachtet
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
     * Nach dem Speichern: die Gruppenzuordnung schreiben (writeGroups()). Ein neuer
     * Status ohne Angabe wird für alle Gruppen setzbar.
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
     * Gruppenzuordnung eines Status ohne save() ändern - für die beiden Grundstatus:
     * status_id 0 hielte Dataset::save() für einen neuen Datensatz. Nur Superuser.
     *
     * @param int[] $groupIds  Gruppen, die den Status setzen dürfen
     * @return bool            false: Meldung in Cms::errors
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
     * `rel_group_status` eines Status schreiben (signiert, Integrity), dann
     * Gruppendateien und Sessions der betroffenen Gruppen erneuern.
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
        // Gruppendateien neu, Sessions der Admins dieser Gruppen nachziehen
        $group = $this->container->get(Group::class);
        foreach ($changed as $groupId) {
            $group->afterChange($groupId);
        }
    }

    /**
     * Nach dem Laden: die Gruppen, die den Status setzen dürfen.
     */
    protected function hookOnLoaded(array &$data): void
    {
        $this->db->pushResult();
        $this->db->query("SELECT `group_id` FROM `rel_group_status` WHERE `status_id` = ? ORDER BY `group_id`", 'i', [(int)($data['status_id'] ?? 0)]);
        $data['group_ids'] = array_map('intval', $this->db->fetchColumn());
        $this->db->popResult();
    }

    /**
     * Nach dem Löschen: die Zeilen in `rel_group_status` räumt der Fremdschlüssel ab,
     * die Gruppendateien werden neu geschrieben.
     */
    protected function hookOnDeleted(array $data): void
    {
        $this->container->get(InstanceCache::class)->rebuild();
    }

    /**
     * Gruppen, denen Status zugeordnet werden können: alle außer der
     * Superuser-Gruppe (sie darf ohnehin alles), sortiert nach group_id.
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
     * Vor dem Löschen... (Rollback bei Fehlern)
     *
     * Hängt alle Datensätze mit dem gelöschten Status auf $replacementId um. Ohne das
     * zeigte record.status_id auf einen nicht mehr existierenden Status (es gibt für
     * dieses Feld keinen Fremdschlüssel, der das verhindern würde).
     *
     * Läuft in der Transaktion von Dataset::delete(): wirft das UPDATE, wird auch die
     * Löschung zurückgerollt.
     */
    protected function hookOnDelete(array $data): void
    {
        // Stelle Records auf Ersatz-Status um
        $query = "UPDATE `record` SET `status_id` = ? WHERE `status_id` = ?";
        $this->db->query($query, 'ii', [$this->replacementId, $data['status_id']]);
    }

    // Sonderfunktionen für Status
    // --------------------

    /**
     * Ersatz-Status für den nächsten delete()-Aufruf festlegen.
     * Muss VOR delete() gesetzt werden, danach ist es wirkungslos.
     *
     * @param int $replacementId  status_id, auf die betroffene Datensätze umgestellt werden
     */
    public function setReplacementId(int $replacementId): void
    {
        $this->replacementId = $replacementId;
    }

}