<?php
/**
 * Dataset subclass for the table `group` - the user groups of the backend admins
 * and their rights (developer docs, chapter 11 (Permissions)).
 *
 * WHAT A GROUP DEFINES
 * --------------------
 *     details.modules      module name => granted additional rights (JSON in `details`):
 *                          {"Record": ["import"], "Tools": []} - key present =
 *                          access to the module; additional rights are the names from
 *                          {Modul}/install/settings.php 'access'
 *     details.interfaces   interfaces through which admins of the group may log in:
 *                          backend, cli, php (Access::INTERFACES); without
 *                          specification only the backend
 *     instance_ids         `rel_group_instance` - instances whose areas the group
 *                          sees in the backend (field of type 'reltable')
 *     languages            `rel_group_language` - language_id => 1 (lesen) | 2 (lesen
 *                          and write); languages without an entry are invisible
 *                          ('reltable', across instances)
 *     statuses             `rel_group_status` - status_ids the group may give to a
 *                          record (flat workflow); every group may read all statuses.
 *                          A new group may set all of them.
 *                          Also maintained in module Status (field group_ids)
 *     (area rights)        `rel_group_area` - per area: read and the flags; maintained
 *                          via saveAreaRights() (GroupBackend::access(): all
 *                          areas of a group) and in module Area (tab "Access":
 *                          all groups of an area); pre-filled and cleared on instance
 *                          assignment, see below.
 *
 * ASSIGNING AN INSTANCE = RELEASE ALL AREAS
 * --------------------
 * When a group is newly given an instance, hookOnSaved() adds for each area of the
 * instance (except folders) a row in `rel_group_area` with all rights except
 * set_owner, and for each language of the instance - as far as no row exists yet -
 * the read right. The superuser then removes in the area what the group may not
 * do. If an instance is withdrawn, the area rows of the instance are dropped;
 * language rows remain (they apply across instances).
 *
 * THE SUPERUSER GROUP
 * --------------------
 * group_id 1 (InstanceCache::SUPERUSER_GROUP_ID) may do everything, without any rows
 * being kept for it (Camindo\Core\Access). It can neither be changed nor deleted;
 * hookOnSave()/hookOnDelete() reject that. Furthermore, no group can be deleted that
 * still has admins (the foreign key admin.group_id is RESTRICT;
 * the message here is the readable version of it).
 *
 * SIGNATUREN (Integrity)
 * --------------------
 * `group`.`sig` covers the details (modules); each row in rel_group_instance,
 * rel_group_area, rel_group_language and rel_group_status carries its own signature. All write paths
 * here and in Area (saveAccess(), grantGroups(), copyAccess()) write it along;
 * InstanceCache::readGroups() leaves out rows without a valid signature - a permission
 * entered via SQL therefore never ends up in the group files.
 *
 * CACHE AND SESSIONS
 * --------------------
 * Every change rewrites the group files var/cache/group-{id}.php
 * (InstanceCache::rebuild()) and updates the session details of the admins of the group
 * (Session::refreshDetails()) - a logged-in admin works with the new rights from their next
 * request.
 *
 * @see Camindo\Core\Modules\Admin\GroupBackend
 * @see Camindo\Core\Access
 * @see Camindo\Core\InstanceCache   Gruppendateien
 * @see Camindo\Core\Dataset
 */
namespace Camindo\Core\Modules\Admin;

use Camindo\Core\Access;
use Camindo\Core\AuditLog;
use Camindo\Core\Dataset;
use Camindo\Core\InstanceCache;
use Camindo\Core\Integrity;

class Group extends Dataset {

    /** Values of the field 'languages' per language */
    public const LANGUAGE_NONE  = 0;
    public const LANGUAGE_READ  = 1;
    public const LANGUAGE_WRITE = 2;

    protected function hookDefinition(): void
    {
        $this->tableName  = 'group';
        $this->moduleName = 'Admin';
        $this->defaultSortOrder = 'label ASC';
        $this->schema = [
            'group_id' => [
                'type'  => 'integer',
                'label' => 'group_id',
                'min' => 1
            ],
            'label' => [
                'type'  => 'chars',
                'label' => '#admin.field_group_label',
                'max'   => 64,
                'flags' => ['trim', 'not_empty', 'unique'],
                'extension' => 'Snippets',   // Core extension: select snippet (#prefix.key)
            ],
            // Module rights and interfaces - see class comment. The form
            // sends them as data[modules][{module}][access],
            // data[modules][{module}][rights][] and data[interfaces][]; hookOnSave()
            // turns them into details.modules and details.interfaces.
            'details' => [
                'type'  => 'json',
                'label' => '#admin.field_group_details',
            ],
            // Tabelle `rel_group_instance`
            'instance_ids' => [
                'type'  => 'reltable',
                'display_type' => 'checkbox',
                'label' => '#admin.field_group_instance_ids',
                'tooltip' => '#admin.tooltip_group_instance_ids',
            ],
            // Tabelle `rel_group_language`: language_id => LANGUAGE_*
            'languages' => [
                'type'  => 'reltable',
                'display_type' => 'checkbox',
                'label' => '#admin.field_group_languages',
                'tooltip' => '#admin.tooltip_group_languages',
            ],
            // Tabelle `rel_group_status`: setzbare status_ids
            'statuses' => [
                'type'  => 'reltable',
                'display_type' => 'checkbox',
                'label' => '#admin.field_group_statuses',
                'tooltip' => '#admin.tooltip_group_statuses',
            ],
        ];
    }

    protected function hookOnCreate(array &$data): void
    {
        $data['details'] = ['modules' => [], 'interfaces' => Access::DEFAULT_INTERFACES];
        $data['instance_ids'] = [];
        $data['languages'] = [];
        // Default: all statuses settable
        $this->db->query("SELECT `status_id` FROM `status` ORDER BY `position` ASC");
        $data['statuses'] = array_map('intval', $this->db->fetchColumn());
    }

    /**
     * After loading: fetch the relation tables.
     */
    protected function hookOnLoaded(array &$data): void
    {
        $groupId = (int)($data['group_id'] ?? 0);
        if (!is_array($data['details'] ?? null)) $data['details'] = [];
        $data['details']['modules'] = (array)($data['details']['modules'] ?? []);
        $data['details']['interfaces'] = Access::normalizeInterfaces(
            isset($data['details']['interfaces']) ? (array)$data['details']['interfaces'] : null
        );

        $this->db->query("SELECT `instance_id` FROM `rel_group_instance` WHERE `group_id` = ?", 'i', [$groupId]);
        $data['instance_ids'] = array_map('intval', $this->db->fetchColumn());

        $data['languages'] = [];
        $this->db->query("SELECT `language_id`, `can_write` FROM `rel_group_language` WHERE `group_id` = ?", 'i', [$groupId]);
        while ($row = $this->db->fetch()) {
            $data['languages'][(int)$row['language_id']] = !empty($row['can_write']) ? self::LANGUAGE_WRITE : self::LANGUAGE_READ;
        }

        $this->db->query("SELECT `status_id` FROM `rel_group_status` WHERE `group_id` = ?", 'i', [$groupId]);
        $data['statuses'] = array_map('intval', $this->db->fetchColumn());
    }

    /**
     * Before saving: reject the superuser group, normalize the input.
     *
     *     modules       from the form (data[modules]) or already as
     *                   details.modules - only known modules, only defined rights
     *     instance_ids  only existing instances
     *     languages     language_id => LANGUAGE_*, only existing languages
     *     statuses      only existing statuses
     */
    protected function hookOnSave(array &$fields, array &$data, array &$previousData): void
    {
        $groupId = (int)($data['group_id'] ?? 0);
        if ($groupId === InstanceCache::SUPERUSER_GROUP_ID) {
            $errorMsg = $this->cms->snippet('admin.error_group_superuser');
            $this->cms->error($errorMsg);
            throw new \Exception('#' . $errorMsg);
        }

        // Modules and interfaces
        // --------------------
        if (in_array('details', $fields, true) || array_key_exists('modules', $data) || array_key_exists('interfaces', $data)) {
            $details = is_array($data['details'] ?? null) ? $data['details'] : [];
            if (array_key_exists('modules', $data)) {
                $details['modules'] = $this->modulesFromForm((array)$data['modules']);
            }
            $details['modules'] = $this->normalizeModules((array)($details['modules'] ?? []));
            if (array_key_exists('interfaces', $data)) {
                $details['interfaces'] = (array)$data['interfaces'];
            }
            // on a save without specification: the previous ones (otherwise DEFAULT_INTERFACES)
            if (!isset($details['interfaces']) && isset($previousData['details']['interfaces'])) {
                $details['interfaces'] = (array)$previousData['details']['interfaces'];
            }
            $details['interfaces'] = Access::normalizeInterfaces(isset($details['interfaces']) ? (array)$details['interfaces'] : null);
            $data['details'] = $details;
            if (!in_array('details', $fields, true)) $fields[] = 'details';
        }

        // Instanzen
        // --------------------
        if (in_array('instance_ids', $fields, true)) {
            $ids = $this->system->sanitizeIds((array)($data['instance_ids'] ?? []), true);
            $data['instance_ids'] = $this->existingIds('instance', $ids);
        }

        // Sprachen
        // --------------------
        // LANGUAGE_NONE stays in the array: hookOnSaved() then knows that the
        // language was explicitly deselected, and does not pre-fill it with a read right on a newly
        // assigned instance.
        if (in_array('languages', $fields, true)) {
            $languages = [];
            // Per language one level or - from the form (hidden field 0 + checkboxes
            // "read" 1 / "write" 2) - a list of them: the highest applies
            foreach ((array)($data['languages'] ?? []) as $languageId => $level) {
                $level = is_array($level) ? max(array_map('intval', $level ?: [0])) : (int)$level;
                $languages[(int)$languageId] = max(self::LANGUAGE_NONE, min($level, self::LANGUAGE_WRITE));
            }
            // language_id 0 is a valid language - therefore no sanitizeIds()
            $existing = $this->existingIds('language', array_keys($languages));
            $data['languages'] = array_intersect_key($languages, array_flip($existing));
        }

        // Status
        // --------------------
        // status_id 0 is a valid status - therefore no sanitizeIds()
        if (in_array('statuses', $fields, true)) {
            $data['statuses'] = $this->existingIds('status', array_map('intval', (array)($data['statuses'] ?? [])));
        }
    }

    /**
     * After saving: write the relation tables, pre-fill area and language rights
     * for newly assigned instances, refresh cache and sessions.
     * Runs in the transaction of Dataset::save().
     */
    protected function hookOnSaved(array &$fields, array &$data, array &$previousData): void
    {
        $groupId = (int)($data['group_id'] ?? 0);

        // Languages from the form (before pre-filling new instances)
        // --------------------
        $submittedLanguageIds = [];
        if (in_array('languages', $fields, true)) {
            $submittedLanguageIds = array_map('intval', array_keys((array)($data['languages'] ?? [])));
            $this->db->query("DELETE FROM `rel_group_language` WHERE `group_id` = ?", 'i', [$groupId]);
            foreach ((array)($data['languages'] ?? []) as $languageId => $level) {
                if ((int)$level < self::LANGUAGE_READ) continue;
                $canWrite = ((int)$level >= self::LANGUAGE_WRITE);
                $this->db->query(
                    "INSERT INTO `rel_group_language` (`group_id`, `language_id`, `can_write`, `sig`) VALUES (?, ?, ?, ?)",
                    'iiis', [$groupId, (int)$languageId, (int)$canWrite, $this->integrity()->languageRightSignature($groupId, (int)$languageId, $canWrite)]
                );
            }
        }

        // Status
        // --------------------
        if (in_array('statuses', $fields, true)) {
            $this->db->query("DELETE FROM `rel_group_status` WHERE `group_id` = ?", 'i', [$groupId]);
            foreach ((array)($data['statuses'] ?? []) as $statusId) {
                $this->db->query(
                    "INSERT INTO `rel_group_status` (`group_id`, `status_id`, `sig`) VALUES (?, ?, ?)",
                    'iis', [$groupId, (int)$statusId, $this->integrity()->statusRightSignature($groupId, (int)$statusId)]
                );
            }
        }

        // Instanzen
        // --------------------
        if (in_array('instance_ids', $fields, true)) {
            $newIds = array_map('intval', (array)($data['instance_ids'] ?? []));
            $oldIds = array_map('intval', (array)($previousData['instance_ids'] ?? []));

            $this->db->query("DELETE FROM `rel_group_instance` WHERE `group_id` = ?", 'i', [$groupId]);
            foreach ($newIds as $instanceId) {
                $this->db->query(
                    "INSERT INTO `rel_group_instance` (`group_id`, `instance_id`, `sig`) VALUES (?, ?, ?)",
                    'iis', [$groupId, $instanceId, $this->integrity()->instanceRightSignature($groupId, $instanceId)]
                );
            }
            foreach (array_diff($newIds, $oldIds) as $instanceId) {
                $this->grantInstance($groupId, $instanceId, $submittedLanguageIds);
            }
            foreach (array_diff($oldIds, $newIds) as $instanceId) {
                $this->revokeInstance($groupId, $instanceId);
            }
        }

        // Signature of the group row (details = modules) - Integrity
        $this->integrity()->signGroup($groupId);

        $this->afterChange($groupId);
    }

    /**
     * Before deleting: the superuser group and groups with (active) users remain.
     * Deleted users lose the assignment (group_id NULL) - they cannot be
     * restored, their group is only history now, and admin.group_id
     * refers to the group with ON DELETE RESTRICT. Re-signed, with an entry in the
     * audit log of the account. The relation tables clear the foreign keys
     * (ON DELETE CASCADE).
     */
    protected function hookOnDelete(array $data): void
    {
        $groupId = (int)($data['group_id'] ?? 0);
        if ($groupId === InstanceCache::SUPERUSER_GROUP_ID) {
            $errorMsg = $this->cms->snippet('admin.error_group_superuser');
            $this->cms->error($errorMsg);
            throw new \Exception('#' . $errorMsg);
        }
        if ($this->adminCount($groupId) > 0) {
            $errorMsg = $this->cms->snippet('admin.error_group_has_admins');
            $this->cms->error($errorMsg);
            throw new \Exception('#' . $errorMsg);
        }
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `admin_id`, `name` FROM `admin` WHERE `group_id` = ? AND `deleted` = 1", 'i', [$groupId]);
            $deleted = $this->db->fetchAll();
            $auditLog = $this->container->get(AuditLog::class);
            foreach ($deleted as $row) {
                $adminId = (int)$row['admin_id'];
                $this->db->query("UPDATE `admin` SET `group_id` = NULL WHERE `admin_id` = ?", 'i', [$adminId]);
                $this->integrity()->signAdmin($adminId);
                $auditLog->write('update', 'admin', $adminId, (string)$row['name'], [[
                    'k' => 'group_id', 'l' => '#admin.field_admin_group_id', 't' => 'integer', 'g' => '',
                    'b' => (string)$groupId, 'a' => '',
                ]]);
            }
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Delete the group and first assign its (active) users to a replacement group;
     * everything in one transaction. Deleted users lose the assignment
     * (hookOnDelete()). Every assignment runs via
     * Admin::save() (signature, audit log). The superuser group is excluded as a replacement
     * - deleting a group must not make anyone a superuser.
     *
     * @param int $replacementId  replacement group; 0 = none (only possible without users)
     */
    public function deleteReplacing(int $groupId, int $replacementId): bool
    {
        return (bool)$this->transactional(function () use ($groupId, $replacementId) {
            $this->db->query("SELECT `admin_id` FROM `admin` WHERE `group_id` = ? AND `deleted` = 0", 'i', [$groupId]);
            $adminIds = array_map('intval', $this->db->fetchColumn());
            if ($adminIds) {
                if ($replacementId <= InstanceCache::SUPERUSER_GROUP_ID || $replacementId === $groupId || !$this->exists($replacementId)) {
                    $errorMsg = $this->cms->snippet('admin.error_group_replacement');
                    $this->cms->error($errorMsg);
                    throw new \Exception('#' . $errorMsg);
                }
                $admin = $this->container->get(Admin::class);
                foreach ($adminIds as $adminId) {
                    $data = ['admin_id' => $adminId, 'group_id' => $replacementId];
                    if ($admin->save(['group_id'], $data) === null) {
                        throw new \Exception('#' . $this->cms->errorMsgLast());
                    }
                }
            }
            if (!$this->delete($groupId)) {
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            return true;
        }, false);
    }

    /** Does the group exist? */
    private function exists(int $groupId): bool
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT 1 FROM `group` WHERE `group_id` = ?", 'i', [$groupId]);
            return $this->db->num_rows > 0;
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * After deleting: remove the group file (rebuild() clears it).
     */
    protected function hookOnDeleted(array $data): void
    {
        $this->instanceCache()->rebuild();
    }

    // ====================
    // Area rights (rel_group_area) - pre-filling and clearing

    /**
     * Release all areas of an instance (except folders) with full rights (without
     * set_owner) and their languages with read right - as far as no
     * row exists yet. Languages that the form explicitly deselected
     * ($skipLanguageIds) stay out.
     */
    public function grantInstance(int $groupId, int $instanceId, array $skipLanguageIds = []): void
    {
        if ($groupId <= InstanceCache::SUPERUSER_GROUP_ID || $instanceId < 1) return;
        // Row by row: each one needs its signature (Integrity). The flags are
        // the column defaults (everything except set_owner).
        $this->db->pushResult();
        $this->db->query("SELECT `area_id` FROM `area` WHERE `instance_id` = ? AND `type` <> 'folder'", 'i', [$instanceId]);
        $areaIds = array_map('intval', $this->db->fetchColumn());
        $this->db->popResult();
        foreach ($areaIds as $areaId) {
            $this->db->query(
                "INSERT IGNORE INTO `rel_group_area` (`group_id`, `area_id`, `sig`) VALUES (?, ?, ?)",
                'iis', [$groupId, $areaId, $this->integrity()->areaRightSignature($groupId, $areaId, [])]
            );
        }
        foreach ($this->instanceCache()->languageIds($instanceId) as $languageId) {
            if (in_array($languageId, $skipLanguageIds, true)) continue;
            $this->db->query(
                "INSERT IGNORE INTO `rel_group_language` (`group_id`, `language_id`, `can_write`, `sig`) VALUES (?, ?, 0, ?)",
                'iis', [$groupId, $languageId, $this->integrity()->languageRightSignature($groupId, $languageId, false)]
            );
        }
    }

    /**
     * Bereichsrechte einer entzogenen Instanz entfernen.
     */
    public function revokeInstance(int $groupId, int $instanceId): void
    {
        if ($groupId < 1 || $instanceId < 1) return;
        $this->db->query(
            "DELETE `r` FROM `rel_group_area` `r`"
            . " INNER JOIN `area` `a` ON `a`.`area_id` = `r`.`area_id`"
            . " WHERE `r`.`group_id` = ? AND `a`.`instance_id` = ?",
            'ii', [$groupId, $instanceId]
        );
    }

    /**
     * Bereichsrechte einer Gruppe schreiben (GroupBackend::access()).
     *
     * @param int   $groupId
     * @param array $rows  area_id => ['read' => 1, 'group_write' => 1, ...] from the
     *                     form; without 'read' the row is dropped. Only areas
     *                     (no folders) of assigned instances; template areas
     *                     keep only group_write.
     * @return bool  false: Meldung in Cms::errors
     */
    public function saveAreaRights(int $groupId, array $rows): bool
    {
        if ($groupId <= InstanceCache::SUPERUSER_GROUP_ID) {
            $errorMsg = $this->cms->snippet('admin.error_group_superuser');
            $this->cms->error($errorMsg);
            return false;
        }
        $instanceIds = array_map('intval', (array)($this->instanceCache()->group($groupId)['instance_ids'] ?? []));
        $areas = $this->instanceCache()->areasOfType([], $instanceIds);

        return (bool)$this->transactional(function () use ($groupId, $rows, $areas) {
            $this->db->query("DELETE FROM `rel_group_area` WHERE `group_id` = ?", 'i', [$groupId]);
            foreach ($rows as $areaId => $flags) {
                $areaId = (int)$areaId;
                $flags = (array)$flags;
                if (!isset($areas[$areaId]) || $areas[$areaId]['type'] === 'folder') continue;
                if (empty($flags['read'])) continue;
                $isTemplate = ($areas[$areaId]['type'] === 'template');
                $row = [
                    'group_write'  => (int)!empty($flags['group_write']),
                    'group_delete' => $isTemplate ? 0 : (int)!empty($flags['group_delete']),
                    'other_write'  => $isTemplate ? 0 : (int)!empty($flags['other_write']),
                    'other_delete' => $isTemplate ? 0 : (int)!empty($flags['other_delete']),
                    'set_owner'    => $isTemplate ? 0 : (int)!empty($flags['set_owner']),
                ];
                $this->db->query(
                    "INSERT INTO `rel_group_area` (`group_id`, `area_id`, `group_write`, `group_delete`, `other_write`, `other_delete`, `set_owner`, `sig`)"
                    . " VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    'iiiiiiis',
                    array_merge([$groupId, $areaId], array_values($row), [$this->integrity()->areaRightSignature($groupId, $areaId, $row)])
                );
            }
            $this->afterChange($groupId);
            return true;
        }, false);
    }

    /**
     * Bereichsrechte einer Gruppe: area_id => Flags (Zeile = Leserecht).
     * Display and form in GroupBackend; also for the "Access" tab in module Area.
     *
     * @return array  area_id => ['group_write', 'group_delete', 'other_write',
     *                'other_delete', 'set_owner'] (int 0|1)
     */
    public function areaRights(int $groupId): array
    {
        $rights = [];
        if ($groupId < 1) return $rights;
        $this->db->pushResult();
        $this->db->query("SELECT * FROM `rel_group_area` WHERE `group_id` = ?", 'i', [$groupId]);
        while ($row = $this->db->fetch()) {
            $rights[(int)$row['area_id']] = [
                'group_write'  => (int)!empty($row['group_write']),
                'group_delete' => (int)!empty($row['group_delete']),
                'other_write'  => (int)!empty($row['other_write']),
                'other_delete' => (int)!empty($row['other_delete']),
                'set_owner'    => (int)!empty($row['set_owner']),
            ];
        }
        $this->db->popResult();
        return $rights;
    }

    /**
     * Refresh cache and sessions after a change to a group or its rights.
     * Also intended for module Area (tab "Access").
     */
    public function afterChange(int $groupId): void
    {
        $this->instanceCache()->rebuild();
        if ($groupId < 1) return;
        $this->db->pushResult();
        $this->db->query("SELECT `admin_id` FROM `admin` WHERE `group_id` = ?", 'i', [$groupId]);
        $adminIds = array_map('intval', $this->db->fetchColumn());
        $this->db->popResult();
        foreach ($adminIds as $adminId) {
            $this->session->refreshDetails($adminId);
        }
    }

    // ====================
    // Hilfsfunktionen

    /**
     * Number of users of a group - without the deleted ones; with $withDeleted all.
     */
    public function adminCount(int $groupId, bool $withDeleted = false): int
    {
        $this->db->pushResult();
        $this->db->query("SELECT COUNT(*) FROM `admin` WHERE `group_id` = ?" . ($withDeleted ? '' : " AND `deleted` = 0"), 'i', [$groupId]);
        $count = (int)($this->db->fetchNum()[0] ?? 0);
        $this->db->popResult();
        return $count;
    }

    /**
     * Modules a group can select: name => additional rights (name => snippet).
     * Only listed modules (Start and Messaging are open to everyone).
     *
     * @return array  Modulname => ['label' => Snippet-Verweis, 'access' => [recht => Snippet]]
     */
    public function availableModules(): array
    {
        $modules = [];
        foreach ($this->cms->modules as $name => $settings) {
            if (empty($settings['listed'])) continue;
            $access = [];
            foreach ((array)($settings['access'] ?? []) as $right => $snippet) {
                if (!preg_match('/^[a-z0-9_]+$/', (string)$right)) continue;
                $access[(string)$right] = (string)$snippet;
            }
            $modules[(string)$name] = [
                'label'  => (string)($settings['label'] ?? $name),
                'icon'  => (string)($settings['icon'] ?? ''),
                'access' => $access,
            ];
        }
        return $modules;
    }

    /**
     * Formulareingabe data[modules] -> details.modules.
     *
     *     data[modules][{Modul}][access] = 1        Zugriff
     *     data[modules][{Modul}][rights][] = recht  Zusatzrechte
     */
    private function modulesFromForm(array $input): array
    {
        $modules = [];
        foreach ($input as $name => $entry) {
            $entry = (array)$entry;
            if (empty($entry['access'])) continue;
            $modules[(string)$name] = array_map('strval', (array)($entry['rights'] ?? []));
        }
        return $modules;
    }

    /**
     * Keep only known modules and the additional rights defined there.
     */
    private function normalizeModules(array $modules): array
    {
        $available = $this->availableModules();
        $clean = [];
        foreach ($modules as $name => $rights) {
            $name = (string)$name;
            if (!isset($available[$name])) continue;
            $allowed = array_keys($available[$name]['access']);
            $clean[$name] = array_values(array_intersect($allowed, array_map('strval', (array)$rights)));
        }
        return $clean;
    }

    /**
     * From an id list, pick out the ids that exist in $tableName
     * (order as passed). $tableName is fixed in the code.
     */
    private function existingIds(string $tableName, array $ids): array
    {
        if (empty($ids)) return [];
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $idField = $tableName . '_id';
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $this->db->pushResult();
        $this->db->query("SELECT `{$idField}` FROM `{$tableName}` WHERE `{$idField}` IN ($placeholders)", str_repeat('i', count($ids)), $ids);
        $found = array_map('intval', $this->db->fetchColumn());
        $this->db->popResult();
        return array_values(array_filter($ids, fn($id) => in_array($id, $found, true)));
    }

    private function instanceCache(): InstanceCache
    {
        return $this->container->get(InstanceCache::class);
    }

    private function integrity(): Integrity
    {
        return $this->container->get(Integrity::class);
    }
}
