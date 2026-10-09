<?php
/**
 * Rights of the logged-in user group in the backend.
 *
 * WHERE THE RIGHTS COME FROM
 * --------------------
 * Session::create()/load() calls load($groupId); the class then reads the
 * group file var/cache/group-{group_id}.php (InstanceCache::group()) and
 * answers all questions from this array - without further database queries. Only
 * groupOf() queries `admin` (once per owner and request) to learn the group of
 * the owner of a record.
 *
 * What the group file contains is described in InstanceCache ("group files"); the
 * permission model itself in developer docs, chapter 11 (Permissions):
 *
 *     Module       module(), moduleRight()     access yes/no, additional rights from
 *                                              {module}/install/settings.php 'access'
 *     Instance     instance(), instanceIds()   assigned instances
 *     Area         areaRead()                  row in rel_group_area = read right
 *                  areaWrite()/areaDelete()    depending on whether the owner of the
 *                                              record belongs to the own group
 *                                              (group_*) or not (other_*)
 *                  areaCreate()                creating = writing with oneself
 *                                              as owner
 *                  areaSetOwner()              additional right "adjust owner"
 *                  templateRead()/-Write()     template areas (group_write only)
 *     Language     languageRead()/-Write()     for the contents in the Record module
 *     Status       statusWrite()               which statuses the group may give
 *                                              a record (reading: all);
 *                                              a record in a status that cannot be
 *                                              set is not changeable for it
 *                                              (recordWrite())
 *     Interface    interface()                 via which interface admins of the
 *                                              group may sign in (INTERFACES)
 *
 * INTERFACES
 * --------------------
 * The same rights apply on every interface - the backend, the command line
 * (`camindo.php api`) and the PHP API (Camindo\Core\Api). Which of these a group
 * may use is stored in `group`.`details`.`interfaces`; without an entry only the
 * backend (DEFAULT_INTERFACES). It is checked at sign-in
 * (Admin::loginByName(), ApiSession) and on every load of a session
 * (Session::load()) - a revoked right ends running sessions.
 *
 * NOT LOADED = NO CHECK
 * --------------------
 * Without a session (frontend, cron) the class stays unloaded and denies nothing:
 * the frontend has its own permission model (RecordUser), background processes
 * run as the system (Session::setCliAdmin() deliberately loads no rights - see
 * there). The superuser group (1) gets a yes to every question.
 *
 * Write permission requires read permission; an area without a row is therefore
 * not writable either, regardless of the flags.
 *
 * @see Camindo\Core\InstanceCache::group()   structure of the group file
 * @see Camindo\Core\Session                  loads the rights per request
 * @see Camindo\Core\Backend::isSuperuser()
 */
namespace Camindo\Core;

class Access {

    /** Interfaces a group can be enabled for */
    public const INTERFACES = ['backend', 'cli', 'php'];

    /** Interfaces of a group without an entry in details.interfaces */
    public const DEFAULT_INTERFACES = ['backend'];

    /** Content of the group file; null = not loaded */
    private ?array $group = null;
    /** admin_id => group_id, remembered per request (groupOf()) */
    private array $groupOfAdmin = [];
    /** admin_id of the session (for areaCreate()) */
    private int $adminId = 0;

    public function __construct(
        private Database $db,
        private InstanceCache $instanceCache,
        private ContainerInterface $container,
    ) {}

    // ====================
    // Laden

    /**
     * Load the rights of a group (session).
     *
     * @param int $groupId  group_id of the signed-in group
     * @param int $adminId  admin_id of the session (owner of new records)
     * @return bool         false if there is no group file
     */
    public function load(int $groupId, int $adminId = 0): bool
    {
        $this->group = $this->instanceCache->group($groupId);
        $this->adminId = $adminId;
        $this->groupOfAdmin = [];
        if ($adminId > 0 && $groupId > 0) $this->groupOfAdmin[$adminId] = $groupId;
        return $this->group !== null;
    }

    /**
     * Discard the rights (sign-out, change of session).
     */
    public function unload(): void
    {
        $this->group = null;
        $this->adminId = 0;
        $this->groupOfAdmin = [];
    }

    public function isLoaded(): bool
    {
        return $this->group !== null;
    }

    /**
     * Superuser group? Not loaded: false (there is no group that may do everything -
     * nevertheless nothing is denied, see class comment).
     */
    public function isSuperuser(): bool
    {
        return !empty($this->group['superuser']);
    }

    public function groupId(): int
    {
        return (int)($this->group['group_id'] ?? 0);
    }

    // ====================
    // Interfaces

    /**
     * May the loaded group use this interface? Unlike the other questions,
     * a no when not loaded: without a group there is no sign-in.
     *
     * @param string $interface  one of INTERFACES
     */
    public function interface(string $interface): bool
    {
        if ($this->group === null) return false;
        return in_array($interface, (array)($this->group['interfaces'] ?? []), true);
    }

    /**
     * Interfaces of the loaded group. Not loaded: empty.
     *
     * @return string[]
     */
    public function interfaces(): array
    {
        return array_values((array)($this->group['interfaces'] ?? []));
    }

    /**
     * May a group use this interface - without loading it (sign-in,
     * before there is a session).
     */
    public function groupInterface(int $groupId, string $interface): bool
    {
        $group = $this->instanceCache->group($groupId);
        return $group !== null && in_array($interface, (array)($group['interfaces'] ?? []), true);
    }

    /**
     * Clean up the interfaces from `group`.`details`: only known ones, in the
     * order of INTERFACES; if the entry is missing, DEFAULT_INTERFACES.
     *
     * @param array|null $interfaces  details.interfaces (null = not set)
     * @return string[]
     */
    public static function normalizeInterfaces(?array $interfaces): array
    {
        if ($interfaces === null) return self::DEFAULT_INTERFACES;
        $interfaces = array_map('strval', $interfaces);
        return array_values(array_filter(self::INTERFACES, fn($name) => in_array($name, $interfaces, true)));
    }

    // ====================
    // Module

    /**
     * Access to a module?
     *
     * Modules that are not in Cms::$modules or are not 'listed' there
     * (Start, Messaging) are open to every group - they are part of the interface,
     * not of the rights management.
     *
     * @param string $module  module name as in Cms::$modules ('Record', 'Tools' ...)
     */
    public function module(string $module): bool
    {
        // Superuser module: reserved for group 1, even if another group
        // has been assigned it (settings.php 'superuser', e.g. Logfiles).
        // Not loaded it stays "yes" as everywhere - the module itself additionally
        // checks with Backend::isSuperuser(), which also answers in the CLI.
        if ($this->isSuperuserModule($module) && !$this->allowsAll()) return false;

        if ($this->allowsAll()) return true;
        if (!$this->isManagedModule($module)) return true;
        return array_key_exists($module, (array)($this->group['modules'] ?? []));
    }

    /**
     * Is the module subject to rights management (in Cms::$modules and 'listed')?
     */
    public function isManagedModule(string $module): bool
    {
        $settings = $this->container->get(Cms::class)->modules[$module] ?? null;
        return $settings !== null && !empty($settings['listed']);
    }

    /**
     * Is the module marked as a superuser module in its settings.php?
     */
    public function isSuperuserModule(string $module): bool
    {
        $settings = $this->container->get(Cms::class)->modules[$module] ?? null;
        return $settings !== null && !empty($settings['superuser']);
    }

    /**
     * Additional right in a module (settings.php 'access')?
     *
     * @param string $module  module name
     * @param string $right   internal name of the right, e.g. 'developer'
     */
    public function moduleRight(string $module, string $right): bool
    {
        if ($this->allowsAll()) return true;
        $rights = $this->group['modules'][$module] ?? null;
        if ($rights === null) return false;
        return in_array($right, (array)$rights, true);
    }

    /**
     * Modules the group has access to: name => granted additional rights.
     * Not loaded: empty (the caller must decide for itself whether "everything" applies).
     *
     * @return array  Modulname => string[]
     */
    public function modules(): array
    {
        return (array)($this->group['modules'] ?? []);
    }

    // ====================
    // Instanzen

    /**
     * Assigned instances in backend order. Not loaded: empty.
     *
     * @return int[]
     */
    public function instanceIds(): array
    {
        return array_map('intval', (array)($this->group['instance_ids'] ?? []));
    }

    public function instance(int $instanceId): bool
    {
        if ($this->allowsAll()) return true;
        return in_array($instanceId, $this->instanceIds(), true);
    }

    // ====================
    // Bereiche

    /**
     * Read right on an area (every type except folders)?
     */
    public function areaRead(int $areaId): bool
    {
        if ($this->allowsAll()) return true;
        return isset($this->group['areas'][$areaId]);
    }

    /**
     * Readable areas, optionally only of certain types, in tree order.
     * Not loaded: empty.
     *
     * @param string[] $types  e.g. Area::CONTENT_TYPES; empty = all
     * @return int[]
     */
    public function readableAreaIds(array $types = []): array
    {
        $ids = [];
        foreach ((array)($this->group['areas'] ?? []) as $areaId => $info) {
            if (!empty($types) && !in_array((string)($info['type'] ?? ''), $types, true)) continue;
            $ids[] = (int)$areaId;
        }
        return $ids;
    }

    /**
     * Readable areas as a filter for queries and trees: null if nothing
     * is to be restricted (superuser, not loaded), otherwise the ids (possibly empty).
     *
     * @param string[] $types  as readableAreaIds()
     * @return int[]|null
     */
    public function areaFilter(array $types = []): ?array
    {
        if ($this->allowsAll()) return null;
        return $this->readableAreaIds($types);
    }

    /**
     * Areas in which the group may create records (read right and
     * group_write) - for target area lists (moving, import); null = no
     * restriction.
     *
     * @param string[] $types  as readableAreaIds()
     * @return int[]|null
     */
    public function creatableAreaFilter(array $types = []): ?array
    {
        if ($this->allowsAll()) return null;
        $ids = [];
        foreach ($this->readableAreaIds($types) as $areaId) {
            if (!empty($this->group['rights']['areas'][$areaId]['group_write'])) $ids[] = $areaId;
        }
        return $ids;
    }

    /**
     * Assigned instances as a filter: null = no restriction.
     *
     * @return int[]|null
     */
    public function instanceFilter(): ?array
    {
        if ($this->allowsAll()) return null;
        return $this->instanceIds();
    }

    /**
     * Write right on a record in an area.
     *
     * @param int      $areaId
     * @param int|null $ownerId  owner_id of the record; null/0 = no owner
     *                           (counts as foreign)
     */
    public function areaWrite(int $areaId, ?int $ownerId): bool
    {
        return $this->areaFlag($areaId, $ownerId, 'write');
    }

    /**
     * Delete right on a record in an area (parameters as areaWrite()).
     */
    public function areaDelete(int $areaId, ?int $ownerId): bool
    {
        return $this->areaFlag($areaId, $ownerId, 'delete');
    }

    /**
     * May the group create records in the area? The owner of a new
     * record is the session admin, so the right is group_write.
     */
    public function areaCreate(int $areaId): bool
    {
        if ($this->allowsAll()) return true;
        return $this->areaRead($areaId) && !empty($this->group['rights']['areas'][$areaId]['group_write']);
    }

    /**
     * Additional right "adjust owner" in the area?
     */
    public function areaSetOwner(int $areaId): bool
    {
        if ($this->allowsAll()) return true;
        return $this->areaRead($areaId) && !empty($this->group['rights']['areas'][$areaId]['set_owner']);
    }

    /**
     * Read right on a template area.
     */
    public function templateRead(int $areaId): bool
    {
        return $this->areaRead($areaId);
    }

    /**
     * Write right on a template area (flag group_write; templates have
     * no owner).
     */
    public function templateWrite(int $areaId): bool
    {
        if ($this->allowsAll()) return true;
        return $this->areaRead($areaId) && !empty($this->group['rights']['areas'][$areaId]['group_write']);
    }

    /**
     * Right flags of an area as they appear in the group file; empty if
     * there is no read right. For display (group info, tab "Access").
     *
     * @return array  group_write, group_delete, other_write, other_delete, set_owner (bool)
     */
    public function areaFlags(int $areaId): array
    {
        return (array)($this->group['rights']['areas'][$areaId] ?? []);
    }

    /**
     * write/delete depending on the group of the owner.
     */
    private function areaFlag(int $areaId, ?int $ownerId, string $action): bool
    {
        if ($this->allowsAll()) return true;
        if (!$this->areaRead($areaId)) return false;
        $flags = (array)($this->group['rights']['areas'][$areaId] ?? []);
        $ownerId = (int)$ownerId;
        $isOwnGroup = $ownerId > 0 && $this->groupOf($ownerId) === $this->groupId();
        $key = ($isOwnGroup ? 'group_' : 'other_') . $action;
        return !empty($flags[$key]);
    }

    /**
     * Group of an admin (owner of a record), remembered per request.
     *
     * @return int  group_id; 0 if the admin does not exist
     */
    public function groupOf(int $adminId): int
    {
        if ($adminId < 1) return 0;
        if (array_key_exists($adminId, $this->groupOfAdmin)) return $this->groupOfAdmin[$adminId];
        $this->db->pushResult();
        $this->db->query("SELECT `group_id` FROM `admin` WHERE `admin_id` = ?", 'i', [$adminId]);
        $groupId = (int)($this->db->fetchNum()[0] ?? 0);
        $this->db->popResult();
        return $this->groupOfAdmin[$adminId] = $groupId;
    }

    // ====================
    // Sprachen

    /**
     * Read right on a language (contents in the Record module)?
     */
    public function languageRead(int $languageId): bool
    {
        if ($this->allowsAll()) return true;
        return array_key_exists($languageId, (array)($this->group['rights']['languages'] ?? []));
    }

    /**
     * Write right on a language?
     */
    public function languageWrite(int $languageId): bool
    {
        if ($this->allowsAll()) return true;
        return !empty($this->group['rights']['languages'][$languageId]);
    }

    /**
     * Readable languages (including the writable ones). Not loaded: empty.
     *
     * @return int[]
     */
    public function readableLanguageIds(): array
    {
        return array_map('intval', array_keys((array)($this->group['rights']['languages'] ?? [])));
    }

    /**
     * Writable languages. Not loaded: empty.
     *
     * @return int[]
     */
    public function writableLanguageIds(): array
    {
        $ids = [];
        foreach ((array)($this->group['rights']['languages'] ?? []) as $languageId => $canWrite) {
            if ($canWrite) $ids[] = (int)$languageId;
        }
        return $ids;
    }

    // ====================
    // Status

    /**
     * May the group set this status (rel_group_status)? Every group may read
     * all statuses - only setting them is restricted.
     */
    public function statusWrite(int $statusId): bool
    {
        if ($this->allowsAll()) return true;
        return in_array($statusId, (array)($this->group['rights']['statuses'] ?? []), true);
    }

    /**
     * Settable statuses (order as `status`.`position`). Not loaded: empty.
     *
     * @return int[]
     */
    public function settableStatusIds(): array
    {
        return array_map('intval', (array)($this->group['rights']['statuses'] ?? []));
    }

    /**
     * May the group change a record? Write right in the area (areaWrite())
     * AND the right to set its current status - whoever may not assign a status
     * does not edit a record that already carries it either (flat
     * workflow: an enabled record stays locked for the editorial team).
     *
     * @param int      $areaId
     * @param int|null $ownerId   owner of the record (see areaWrite())
     * @param int      $statusId  record.status_id
     */
    public function recordWrite(int $areaId, ?int $ownerId, int $statusId): bool
    {
        return $this->areaWrite($areaId, $ownerId) && $this->statusWrite($statusId);
    }

    // ====================
    // Verweigern

    /**
     * May a backend script of a module be called? (Backend::backendPath())
     *
     *     module allowed   -> yes, provided the script does not require an additional right
     *                         that is missing (settings.php 'script_access': script => right,
     *                         e.g. the tabs of the Tools module)
     *     module denied    -> yes only for a script from settings.php 'shared' in a
     *                         lookup call: selection lists and preview blocks that
     *                         other modules need in their forms (admin lookup in the
     *                         Messaging module, record/template lookup in the Instance
     *                         module); lookup call = request parameter src_field
     *                      -> yes for a script from settings.php 'personal': the
     *                         personal settings of each admin (Admin/settings.php,
     *                         Admin/totp.php) - the script itself restricts non-superusers
     *                         to their own account
     *
     * @param string $module  module name from the path ('Admin')
     * @param string $script  file name of the script ('admin_list.php')
     */
    public function script(string $module, string $script): bool
    {
        $settings = (array)($this->container->get(Cms::class)->modules[$module] ?? []);
        if ($this->module($module)) {
            $right = (string)($settings['script_access'][$script] ?? '');
            return $right === '' || $this->moduleRight($module, $right);
        }
        if (in_array($script, (array)($settings['personal'] ?? []), true)) return true;
        if (!in_array($script, (array)($settings['shared'] ?? []), true)) return false;
        return trim((string)($_REQUEST['src_field'] ?? '')) !== '';
    }

    /**
     * Backend: output the "access denied" message and abort.
     *
     * For the entry scripts of the modules ({module}/backend/*.php): they run in the
     * layer, so a message in the layer is the right place - not
     * '##accessdenied##', which would open the sign-in.
     */
    public function denied(): never
    {
        echo $this->deniedHtml();
        exit;
    }

    /**
     * The "access denied" message as HTML - for methods that want to end the page
     * normally (container_footer, return).
     */
    public function deniedHtml(): string
    {
        return $this->container->get(Backend::class)->template('alert_danger', [
            '{text}' => $this->container->get(Cms::class)->snippet('error_access_denied'),
        ]);
    }

    // ====================

    /**
     * Not loaded or superuser: every question is answered with yes.
     */
    private function allowsAll(): bool
    {
        return $this->group === null || !empty($this->group['superuser']);
    }
}
