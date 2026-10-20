<?php
/**
 * File cache of the instances and their languages: var/cache/instances.php and
 * var/cache/instance-{id}.php.
 *
 * WHY A FILE CACHE
 * --------------------
 * Every frontend call must first know which website (instance) it belongs to
 * and which languages apply there - even before the first content is loaded. Until now
 * this cost four queries per request (Page::resolveInstance()). The data hardly ever
 * changes, so it now lives as PHP arrays in the file system
 * and is loaded via include; the opcache then keeps it in memory.
 *
 * THE FILES
 * --------------------
 *     instances.php        domain assignments of ALL instances (primary domains as
 *                          a lookup table, additional domains as an ordered list,
 *                          because regular expressions can appear there too), as well as
 *                          the primary language of each instance, ALL content areas
 *                          (area_id => name/type/instance_id, see below), the
 *                          ids of the user groups, code and collation per language
 *                          (languageCode(), collation(), see Camindo\Core\Collation)
 *                          and the input fields in the trash (deletedFieldIds())
 *     instance-{id}.php    all data of ONE instance: the columns of the table
 *                          `instance`, the languages in the order primary language
 *                          first (with code/label/endonym from `language`), the
 *                          readable instances, the additional domains and their
 *                          content areas (area_id => name/type/instance_id)
 *     group-{id}.php       the view of ONE user group on the backend: the instances
 *                          assigned to it, its readable areas and all further
 *                          rights (modules, area flags, languages) -
 *                          see "group files" below and Camindo\Core\Access
 *
 * The files are rewritten completely as soon as something changes in the modules Instance,
 * Language, Area or in the user groups (rebuild(), called from their hooks). If a
 * file is missing - e.g. because the cache was emptied - the first access creates it
 * immediately. So that several concurrent requests do not do the same thing, the rebuild
 * runs under a lock file (flock); whoever gets the lock checks afterwards once more
 * whether someone else has meanwhile written the file.
 *
 * Writing always goes first to a temporary file, which then comes to its
 * place via rename(): an include thus never sees a half-written file.
 *
 * LANGUAGES OF AN INSTANCE
 * --------------------
 * languages() returns the languages of an instance as an array language_id => row, with
 * the primary language first. This order is authoritative everywhere:
 * for the data_* rows per language, for `record_cache`, for the tabs of the
 * input mask and for the language switcher in the frontend.
 *
 * CONTENT AREAS
 * --------------------
 * Templates address an area by its name (CMS:SELECT with
 * filter[area]=artikel or sort=area), the database only knows `record`.`area_id`.
 * Instead of a subquery on `area` for each query, the areas are kept in the
 * cache: instance-{id}.php holds those of the instance in tree order (lft),
 * instances.php those of all instances - for queries without an instance restriction
 * (backend). Each area has `name`, `type` and `instance_id` in it:
 *
 *     'areas' => [ 131 => ['name' => 'article', 'type' => 'record', 'instance_id' => 1], ... ]
 *
 * If an area has trigger templates (`rel_area_trigger`), their ids are additionally
 * stored in their order ('trigger_template_ids') - Record queries them on every
 * load and save (TriggerTemplates), the cache saves that query.
 * Likewise the slug prefix of a content area (`details`.slug_prefix), resolved
 * per language of the instance: 'slug_prefix' => [language_id => 'farben', ...] - CMS:URL
 * and Page need it for every link or call (slugPrefix()).
 *
 * Cms::select() thus translates the name into the id, or builds an ORDER BY FIELD() from the
 * ids sorted by name; the dataset classes get the instance and type of an
 * area from here instead of from `area` (areaInstanceId(), areaType()).
 * areas()/allAreas()/areasOf() continue to return only area_id => name.
 *
 * Areas marked as "deleted" are also included: their records carry
 * the same marking and do not appear in select() anyway, and the hooks before the
 * soft delete or restore would still see the old state. Only the final
 * deletion (Area::hookOnDeleted()) removes an area from the cache. Whoever needs `deleted`
 * or other columns (label, lft, details ...) still queries `area`.
 *
 * GROUP FILES (group-{group_id}.php)
 * --------------------
 * The backend shows an admin only the instances and areas to which their
 * user group has access (developer docs, chapter 11 (Permissions)). So that this works without
 * queries per request, each group has a file with its view:
 *
 *     'group_id'           group_id
 *     'superuser'          true for group 1 - it may do everything; the file then
 *                          contains all instances, areas, modules and languages,
 *                          so that the loading path is the same everywhere
 *     'label'              `group`.`label`
 *     'instance_ids'       assigned instances (rel_group_instance), order
 *                          as in instances.php
 *     'primary_languages'  instance_id => primary_language_id of these instances
 *     'areas'              readable areas (rel_group_area) in these instances,
 *                          format as above; folders have no rights and are missing
 *     'modules'            module name => granted additional rights (`group`.`details`)
 *     'interfaces'         interfaces via which admins of the group may sign in
 *                          (`group`.`details`.`interfaces`, Access::INTERFACES)
 *     'rights' => [
 *         'areas'     => [area_id => [group_write, group_delete, other_write,
 *                                     other_delete, set_owner] (bool)]
 *         'languages' => [language_id => can_write (bool)]   row = read right
 *         'statuses'  => [status_id, ...]   statuses the group may set
 *                        (rel_group_status); every group may read all
 *     ]
 *
 * Session loads the file of the signed-in group into Camindo\Core\Access; all
 * permission checks are then pure array accesses. The group files are rewritten
 * together with all others (writeAll()) - also from the hooks of Group.
 *
 * @see Camindo\Core\Page                          domain -> instance -> language
 * @see Camindo\Core\Modules\Instance\Instance     calls rebuild() from the hooks
 * @see Camindo\Core\Modules\Language\Language     dito
 * @see Camindo\Core\Modules\Area\Area             dito (areas)
 * @see Camindo\Core\Modules\Admin\Group           dito (group files)
 * @see Camindo\Core\Access                        reads the group file
 * @see Camindo\Core\Cms::areaMap()                area names in the access area
 */
namespace Camindo\Core;

class InstanceCache {

    /** Name of the collective file */
    private const FILE_ALL = 'instances.php';
    /** Lock file for the rebuild */
    private const FILE_LOCK = 'instances.lock';

    /** Superuser group: may do everything, its file contains everything */
    public const SUPERUSER_GROUP_ID = 1;

    /** Files already loaded per request: instance_id => data */
    private array $instances = [];
    /** Group files already loaded per request: group_id => data */
    private array $groups = [];
    /** Content of instances.php, if already loaded */
    private ?array $all = null;

    public function __construct(
        private Config $config,
        private Database $db,
        private System $system,
        private Integrity $integrity,
        private HtmlSanitizer $htmlSanitizer
    ) {}

    // ====================
    // Lesen
    // ====================

    /**
     * Content of instances.php: domain assignments and primary languages of all instances.
     *
     * If the file is missing, it is recreated.
     *
     * @return array  see buildAll()
     */
    public function all(): array
    {
        if ($this->all !== null) return $this->all;

        $file = $this->cacheDir() . '/' . self::FILE_ALL;
        $data = $this->includeFile($file);
        if ($data === null) {
            $this->rebuildMissing();
            $data = $this->includeFile($file) ?? [];
        }
        $this->all = $data;
        return $data;
    }

    /**
     * Data of an instance from instance-{id}.php.
     *
     * If the file is missing, the cache is recreated. If the instance does not exist
     * even then, the method returns null.
     *
     * @param int $instanceId
     * @return array|null  see buildInstance()
     */
    public function instance(int $instanceId): ?array
    {
        if ($instanceId < 1) return null;
        if (array_key_exists($instanceId, $this->instances)) {
            return $this->instances[$instanceId];
        }

        $file = $this->instanceFile($instanceId);
        $data = $this->includeFile($file);
        if ($data === null) {
            $this->rebuildMissing();
            $data = $this->includeFile($file);
        }
        $this->instances[$instanceId] = $data;
        return $data;
    }

    /**
     * View of a user group from group-{id}.php (see class comment).
     *
     * If the file is missing, the cache is recreated. If the group does not exist
     * even then, the method returns null.
     *
     * @param int $groupId
     * @return array|null  see buildGroups()
     */
    public function group(int $groupId): ?array
    {
        if ($groupId < 1) return null;
        if (array_key_exists($groupId, $this->groups)) {
            return $this->groups[$groupId];
        }

        $file = $this->groupFile($groupId);
        $data = $this->includeFile($file);
        if ($data === null) {
            $this->rebuildMissing();
            $data = $this->includeFile($file);
        }
        $this->groups[$groupId] = $data;
        return $data;
    }

    /**
     * Languages of an instance, primary language first.
     *
     * @param int $instanceId
     * @return array  language_id => ['language_id', 'code', 'backend_code', 'label',
     *                'endonym', 'timezone']. Empty if the instance is unknown.
     */
    public function languages(int $instanceId): array
    {
        return (array)($this->instance($instanceId)['languages'] ?? []);
    }

    /**
     * Languages of several interconnected instances (own instance + readable ones):
     * those of the first instance in its order, then those of the others, insofar as
     * not already included. This is the language list of a frontend request -
     * each of these languages is reachable via /{lang}/ and in the language switcher.
     *
     * @param int[] $instanceIds  own instance first
     * @return array  language_id => ['language_id', 'code', 'label', 'endonym']
     */
    public function languagesOf(array $instanceIds): array
    {
        $result = [];
        foreach ($instanceIds as $instanceId) {
            foreach ($this->languages((int)$instanceId) as $languageId => $language) {
                $result[(int)$languageId] ??= $language;
            }
        }
        return $result;
    }

    /**
     * Ids of the languages of an instance, primary language first.
     *
     * @param int $instanceId
     * @return int[]
     */
    public function languageIds(int $instanceId): array
    {
        return array_map('intval', array_keys($this->languages($instanceId)));
    }

    /**
     * Primary language of an instance.
     *
     * @param int $instanceId
     * @return int  language_id; 0 if the instance is unknown
     */
    public function primaryLanguageId(int $instanceId): int
    {
        return (int)($this->instance($instanceId)['primary_language_id'] ?? 0);
    }

    /**
     * Primary languages of ALL instances - for queries without a language context (backend).
     *
     * @return array  instance_id => primary_language_id
     */
    public function primaryLanguages(): array
    {
        return array_map('intval', (array)($this->all()['primary_languages'] ?? []));
    }

    /**
     * Collation for text comparison and sorting in a language (Collation).
     *
     * @param int $languageId
     * @return string  name of the collation; '' = that of the text columns (neutral), then
     *                 a query needs no COLLATE
     */
    public function collation(int $languageId): string
    {
        return (string)($this->all()['collations'][$languageId] ?? '');
    }

    /**
     * Input fields in the trash (`field`.`deleted` = 1), grouped by name: their data_*
     * rows stay until the final deletion, but Cms::select() must neither find nor
     * sort them. Rewritten by Field on soft deletion, restore and final deletion -
     * normally empty, and then select() needs no condition at all.
     *
     * @return array<int, int[]>  fieldname_id => field_ids
     */
    public function deletedFieldIds(): array
    {
        return (array)($this->all()['deleted_fields'] ?? []);
    }

    /**
     * Code of a language (`language`.`code`) - also for languages that are not
     * assigned to any instance.
     */
    public function languageCode(int $languageId): string
    {
        return (string)($this->all()['language_codes'][$languageId] ?? '');
    }

    /**
     * All instances as instance_id => label, in the order of the backend
     * (`position`, the default instance at the end - Instance::orderSql()). For the client level of the
     * area trees (RecordBackendFolderList, AreaBackend::list()).
     *
     * @return array  instance_id => label (snippet references unresolved)
     */
    public function instanceLabels(): array
    {
        $labels = [];
        foreach ((array)($this->all()['instance_ids'] ?? []) as $instanceId) {
            $labels[(int)$instanceId] = (string)($this->instance((int)$instanceId)['label'] ?? '');
        }
        return $labels;
    }

    /**
     * Are multiple clients set up - is there another instance besides the default one?
     * Only then do the area trees get a main folder per instance.
     */
    public function hasMultipleInstances(): bool
    {
        return count((array)($this->all()['instance_ids'] ?? [])) > 1;
    }

    /**
     * Content areas of an instance in tree order.
     *
     * @param int $instanceId
     * @return array  area_id => name. Empty if the instance is unknown.
     */
    public function areas(int $instanceId): array
    {
        return $this->names((array)($this->instance($instanceId)['areas'] ?? []));
    }

    /**
     * Content areas of ALL instances - for queries without an instance restriction
     * (backend). Order: instances by position, within them tree order.
     *
     * @return array  area_id => name
     */
    public function allAreas(): array
    {
        return $this->names((array)($this->all()['areas'] ?? []));
    }

    /**
     * Data for ONE area: name, type, instance_id - or null if it does not exist.
     * Replaces the query "SELECT instance_id, type FROM area" in the
     * dataset classes; `deleted` and the other columns are not here.
     *
     * @param int $areaId
     * @return array|null  ['name' => string, 'type' => string, 'instance_id' => int,
     *                      'trigger_template_ids' => int[] (order of execution),
     *                      'overview_template_id' => int (0 = none)]
     */
    public function areaInfo(int $areaId): ?array
    {
        if ($areaId < 1) return null;
        $info = $this->all()['areas'][$areaId] ?? null;
        if (!is_array($info)) return null;
        return [
            'name'        => (string)($info['name'] ?? ''),
            'type'        => (string)($info['type'] ?? ''),
            'instance_id' => (int)($info['instance_id'] ?? 0),
            'trigger_template_ids' => array_map('intval', (array)($info['trigger_template_ids'] ?? [])),
            'overview_template_id' => (int)($info['overview_template_id'] ?? 0),
        ];
    }

    /**
     * Type of an area (record, profile, ugc, template, folder); '' = unknown.
     */
    public function areaType(int $areaId): string
    {
        return (string)($this->areaInfo($areaId)['type'] ?? '');
    }

    /**
     * Instance of an area; 0 = unknown.
     */
    public function areaInstanceId(int $areaId): int
    {
        return (int)($this->areaInfo($areaId)['instance_id'] ?? 0);
    }

    /**
     * Areas of certain types, optionally only of certain instances (empty = all),
     * in the order of areasOf().
     *
     * @param string[] $types        e.g. Area::CONTENT_TYPES; empty = all types
     * @param int[]    $instanceIds  empty = all instances
     * @return array   area_id => ['name', 'type', 'instance_id']
     */
    public function areasOfType(array $types, array $instanceIds = []): array
    {
        $result = [];
        $source = empty($instanceIds) ? [(array)($this->all()['areas'] ?? [])] : [];
        foreach ($instanceIds as $instanceId) {
            $source[] = (array)($this->instance((int)$instanceId)['areas'] ?? []);
        }
        foreach ($source as $areas) {
            foreach ($areas as $areaId => $info) {
                if (!is_array($info)) continue;
                if (!empty($types) && !in_array((string)($info['type'] ?? ''), $types, true)) continue;
                $result[(int)$areaId] = [
                    'name'        => (string)($info['name'] ?? ''),
                    'type'        => (string)($info['type'] ?? ''),
                    'instance_id' => (int)($info['instance_id'] ?? 0),
                ];
            }
        }
        return $result;
    }

    /**
     * Slug prefix of an area in a language (`area`.`details`.slug_prefix).
     *
     * A content area can prepend a prefix to its records: from the slug "blau" in the area
     * with the prefix "farben" the URL becomes /farben/blau
     * (Cms::recordUrl(), Cms::recordIdsBySlugPath()). The prefix is multilingual
     * with a fallback to the primary language of the instance of the area; in the cache it is
     * already resolved for each of its languages. A language that the instance does not
     * carry reads - like the records themselves - the primary language.
     *
     * @return string  prefix without slash, '' = none (also folders, template areas)
     */
    public function slugPrefix(int $areaId, int $languageId): string
    {
        $prefixes = $this->slugPrefixes($areaId);
        if (empty($prefixes)) return '';
        if (isset($prefixes[$languageId])) return $prefixes[$languageId];
        $instanceId = $this->areaInstanceId($areaId);
        if (in_array($languageId, $this->languageIds($instanceId), true)) return '';
        return $prefixes[$this->primaryLanguageId($instanceId)] ?? '';
    }

    /**
     * All slug prefixes of an area: language_id => prefix (only languages with
     * a prefix, fallback already resolved - see slugPrefix()).
     */
    public function slugPrefixes(int $areaId): array
    {
        return (array)($this->all()['areas'][$areaId]['slug_prefix'] ?? []);
    }

    /**
     * Areas with a slug prefix in the given instances (empty = all).
     *
     * @param int[] $instanceIds
     * @return array  area_id => [language_id => prefix]
     */
    public function slugPrefixAreas(array $instanceIds = []): array
    {
        $result = [];
        foreach ((array)($this->all()['areas'] ?? []) as $areaId => $info) {
            if (!is_array($info) || empty($info['slug_prefix'])) continue;
            if (!empty($instanceIds) && !in_array((int)($info['instance_id'] ?? 0), $instanceIds, true)) continue;
            $result[(int)$areaId] = (array)$info['slug_prefix'];
        }
        return $result;
    }

    /**
     * Resolve the raw values of a slug prefix (language_id => input) for the languages of an
     * instance: empty languages get the prefix of the primary language.
     *
     * @param array $raw          language_id => prefix (as in details.slug_prefix)
     * @param int[] $languageIds  languages of the instance
     * @param int   $primaryId    its primary language
     * @return array  language_id => prefix, only non-empty ones
     */
    public static function resolveSlugPrefixes(array $raw, array $languageIds, int $primaryId): array
    {
        $primary = trim((string)($raw[$primaryId] ?? ''));
        $resolved = [];
        foreach ($languageIds as $languageId) {
            $languageId = (int)$languageId;
            $prefix = trim((string)($raw[$languageId] ?? ''));
            if ($prefix === '') $prefix = $primary;
            if ($prefix !== '') $resolved[$languageId] = $prefix;
        }
        return $resolved;
    }

    /**
     * Ids of all user groups (order: group_id), from instances.php.
     *
     * @return int[]
     */
    public function groupIds(): array
    {
        return array_map('intval', (array)($this->all()['group_ids'] ?? []));
    }

    /**
     * area_id => name from an area list of the cache.
     */
    private function names(array $areas): array
    {
        $names = [];
        foreach ($areas as $areaId => $info) {
            $names[(int)$areaId] = is_array($info) ? (string)($info['name'] ?? '') : (string)$info;
        }
        return $names;
    }

    /**
     * Content areas of several instances (own instance + readable ones), in the
     * order of the passed instances; empty list = all instances.
     *
     * @param int[] $instanceIds
     * @return array  area_id => name
     */
    public function areasOf(array $instanceIds): array
    {
        if (empty($instanceIds)) return $this->allAreas();
        $result = [];
        foreach ($instanceIds as $instanceId) {
            foreach ($this->areas((int)$instanceId) as $areaId => $name) {
                $result[(int)$areaId] = $name;
            }
        }
        return $result;
    }

    /**
     * Instances within which a `unique` value must be unique.
     *
     * A frontend call sees its own instance and its readable instances
     * (`readable_instances`, see Page::resolveInstance()). A value that occurred twice
     * there would no longer be unambiguous for the call - e.g. a slug.
     * Therefore for the uniqueness of a record of instance X the following count:
     *
     *     X itself
     *     the instances that X may read          (visible from X)
     *     the instances that may read X          (X is visible from them)
     *
     * Not transitive: if A reads instance B and B reads instance C, A sees nothing
     * of C - so C and A do not have to exclude each other.
     *
     * @param int $instanceId
     * @return int[]  instance_ids, own one first; [$instanceId] for an unknown instance
     * @see Camindo\Core\Dataset::validateValue()   meta fields with 'unique'
     * @see Camindo\Core\Dataset::uniqueVirtualOk() virtual fields with 'unique'
     */
    public function uniqueScope(int $instanceId): array
    {
        if ($instanceId < 1) return [];
        $scope = [$instanceId];
        foreach ((array)($this->instance($instanceId)['readable_instances'] ?? []) as $readableId) {
            $readableId = (int)$readableId;
            if ($readableId > 0 && !in_array($readableId, $scope, true)) $scope[] = $readableId;
        }
        // Umgekehrte Richtung: wer darf $instanceId lesen?
        foreach ((array)($this->all()['instance_ids'] ?? []) as $otherId) {
            $otherId = (int)$otherId;
            if (in_array($otherId, $scope, true)) continue;
            $readable = array_map('intval', (array)($this->instance($otherId)['readable_instances'] ?? []));
            if (in_array($instanceId, $readable, true)) $scope[] = $otherId;
        }
        return $scope;
    }

    /**
     * Language for a language code within an instance.
     *
     * The comparison ignores case and treats '-' and '_' as equivalent
     * separators ("de-AT" finds "de_at").
     *
     * @param int    $instanceId
     * @param string $code       language code from the URL
     * @return array|null        language row, or null if the instance does not carry it
     */
    public function languageByCode(int $instanceId, string $code): ?array
    {
        return $this->findByCode($this->languages($instanceId), $code);
    }

    /**
     * Language for a code in a given language list (languagesOf()).
     *
     * @param array  $languages  language_id => language row
     * @param string $code
     * @return array|null
     */
    public function findByCode(array $languages, string $code): ?array
    {
        $needle = $this->normalizeCode($code);
        if ($needle === '') return null;
        // 1. exact match ("de-at" = "de_AT")
        foreach ($languages as $language) {
            if ($this->normalizeCode((string)($language['code'] ?? '')) === $needle) {
                return $language;
            }
        }
        // 2. base language: "/de/" finds "de_DE", "/de-ch/" finds "de" - the first
        //    matching language in the order of the presence. With canonical = 1
        //    Page then redirects to the exact code.
        $needleBase = explode('-', $needle)[0];
        foreach ($languages as $language) {
            $langCode = $this->normalizeCode((string)($language['code'] ?? ''));
            if ($langCode === $needleBase || explode('-', $langCode)[0] === $needle) {
                return $language;
            }
        }
        return null;
    }

    /**
     * Best matching language of an instance for the browser's Accept-Language header.
     *
     * The header is processed by weight (q). For each entry an exact match counts first
     * ("de-at" = "de_AT"), then a match of the base language ("de-at" hits "de" or "de_DE"). If nothing
     * matches, it stays with the primary language.
     *
     * @param int    $instanceId
     * @param string $acceptLanguage  content of $_SERVER['HTTP_ACCEPT_LANGUAGE']
     * @return int                    language_id
     */
    public function negotiateLanguage(int $instanceId, string $acceptLanguage): int
    {
        return $this->negotiate($this->languages($instanceId), $this->primaryLanguageId($instanceId), $acceptLanguage);
    }

    /**
     * Like negotiateLanguage(), but via a given language list (languagesOf()).
     *
     * @param array  $languages       language_id => language row, primary language first
     * @param int    $primaryId       fallback if nothing matches
     * @param string $acceptLanguage  content of $_SERVER['HTTP_ACCEPT_LANGUAGE']
     * @return int                    language_id
     */
    public function negotiate(array $languages, int $primaryId, string $acceptLanguage): int
    {
        if (count($languages) < 2 || trim($acceptLanguage) === '') return $primaryId;

        // Split the header: "de-AT,de;q=0.9,en;q=0.8" -> [['de-at', 1.0], ['de', 0.9], ...]
        $wanted = [];
        foreach (explode(',', $acceptLanguage) as $i => $part) {
            $pieces = explode(';', trim($part));
            $tag = $this->normalizeCode($pieces[0] ?? '');
            if ($tag === '' || $tag === '*') continue;
            $q = 1.0;
            foreach (array_slice($pieces, 1) as $param) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)/i', $param, $m)) $q = (float)$m[1];
            }
            if ($q <= 0) continue;
            // Order in the header as a tie-breaker, so that the sorting stays stable
            $wanted[] = [$tag, $q, $i];
        }
        usort($wanted, fn($a, $b) => ($b[1] <=> $a[1]) ?: ($a[2] <=> $b[2]));

        // Prepare the codes of the instance languages: exact and base language
        $exact = [];
        $base  = [];
        foreach ($languages as $languageId => $language) {
            $code = $this->normalizeCode((string)($language['code'] ?? ''));
            if ($code === '') continue;
            $exact[$code] = (int)$languageId;
            $baseCode = explode('-', $code)[0];
            // First language per base language wins (order of the instance)
            if (!isset($base[$baseCode])) $base[$baseCode] = (int)$languageId;
        }

        foreach ($wanted as [$tag]) {
            if (isset($exact[$tag])) return $exact[$tag];
            $baseTag = explode('-', $tag)[0];
            if (isset($base[$baseTag])) return $base[$baseTag];
        }
        return $primaryId;
    }

    /**
     * Determine the instance for a domain.
     *
     * Order - the first match wins:
     *     1. `instance`.`primary_domain`
     *     2. `rel_instance_domain` - literal, or as a regular expression if there
     *        `regex` = 1; in the order instance.position, rel.position
     *     3. the default instance
     *
     * @param string $host  host name without port, lower case
     * @return int          instance_id
     */
    public function resolveDomain(string $host): int
    {
        $all = $this->all();
        $id = $this->matchDomain($host);
        // 3. Standard-Instance
        return $id > 0 ? $id : (int)($all['default_id'] ?? 1);
    }

    /**
     * Like resolveDomain(), but without a fallback to the default instance:
     * 0 = the domain belongs to no instance (e.g. the fixed backend domain).
     */
    public function matchDomain(string $host): int
    {
        $all = $this->all();
        $host = mb_strtolower(trim($host));
        if ($host === '') return 0;

        // 1. Primary domain
        $primaryId = (int)($all['primary_domains'][$host] ?? 0);
        if ($primaryId > 0) return $primaryId;

        // 2. Additional domains
        foreach ((array)($all['additional_domains'] ?? []) as $row) {
            $domain = (string)($row['domain'] ?? '');
            if (empty($row['regex'])) {
                if ($domain === $host) return (int)$row['instance_id'];
                continue;
            }
            // Regular expression from the backend: an invalid pattern must not
            // abort the request, it simply does not count as a match.
            $pattern = '~^(?:' . str_replace('~', '\\~', $domain) . ')$~ui';
            if (@preg_match($pattern, $host) === 1) return (int)$row['instance_id'];
        }
        return 0;
    }

    // ====================
    // Schreiben
    // ====================

    /**
     * Rewrite all cache files.
     *
     * To be called when instances, their relation tables, languages or
     * content areas have changed (hooks of the modules Instance, Language and Area).
     * Runs under the lock, so that
     * two writers are not at work at the same time.
     */
    public function rebuild(): void
    {
        $this->withLock(function () {
            $this->writeAll();
        });
        // Im Speicher gehaltene Kopien verwerfen
        $this->all = null;
        $this->instances = [];
        $this->groups = [];
    }

    /**
     * Recreate the cache only if the collective file is missing (or an individual file
     * for an instance that exists according to the collective file).
     *
     * Called by the read methods. Under the lock it is checked once more:
     * another request may have written the files in the meantime.
     */
    private function rebuildMissing(): void
    {
        $this->withLock(function () {
            if ($this->isComplete()) return;
            $this->writeAll();
        });
        $this->all = null;
        $this->instances = [];
        $this->groups = [];
    }

    /**
     * Are all files present that must exist according to the collective file?
     * (A collective file without 'group_ids', 'collations', 'language_codes' or
     * 'deleted_fields' stems from an earlier version and counts as incomplete.)
     */
    private function isComplete(): bool
    {
        $all = $this->includeFile($this->cacheDir() . '/' . self::FILE_ALL);
        if ($all === null || !isset($all['group_ids'], $all['collations'], $all['language_codes'], $all['deleted_fields'])) return false;
        foreach ((array)($all['instance_ids'] ?? []) as $instanceId) {
            if (!is_file($this->instanceFile((int)$instanceId))) return false;
        }
        foreach ((array)$all['group_ids'] as $groupId) {
            if (!is_file($this->groupFile((int)$groupId))) return false;
        }
        return true;
    }

    /**
     * Collation per language (Collation::forLocale()); '' where it matches the collation
     * of the text columns - there an index on `data` remains usable.
     *
     * @param array $languages  language_id => ['code' => …]
     * @return array            language_id => collation or ''
     */
    private function languageCollations(array $languages): array
    {
        $available = Collation::available($this->db);
        $this->db->query("SELECT `COLLATION_NAME` FROM `information_schema`.`COLUMNS`"
            . " WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'data_txt' AND `COLUMN_NAME` = 'data'");
        $column = (string)($this->db->fetchNum()[0] ?? '');
        $result = [];
        foreach ($languages as $languageId => $language) {
            $collation = Collation::forLocale((string)$language['code'], $available);
            $result[(int)$languageId] = $collation === $column ? '' : $collation;
        }
        return $result;
    }

    /**
     * Build the files from the database and write them.
     * Call only inside withLock().
     */
    private function writeAll(): void
    {
        $this->db->pushResult();
        try {
            // Languages
            $languages = [];
            // (backend_code and timezone: snippet language and time zone that
            //  Cms::setLanguage() sets in the frontend for this content language)
            $this->db->query("SELECT `language_id`, `code`, `backend_code`, `label`, `endonym`, `timezone`, `position` FROM `language` ORDER BY `position` ASC");
            while ($row = $this->db->fetch()) {
                $languages[(int)$row['language_id']] = [
                    'language_id'  => (int)$row['language_id'],
                    'code'         => (string)$row['code'],
                    'backend_code' => (string)$row['backend_code'],
                    'label'        => $this->htmlSanitizer->clean((string)$row['label']),
                    'endonym'      => $this->htmlSanitizer->clean((string)$row['endonym']),
                    'timezone'     => (string)$row['timezone'],
                ];
            }
            $collations = $this->languageCollations($languages);

            // Instanzen
            $instances = [];
            $this->db->query("SELECT * FROM `instance` ORDER BY " . \Camindo\Core\Modules\Instance\Instance::orderSql());
            while ($row = $this->db->fetch()) {
                $id = (int)$row['instance_id'];
                $instances[$id] = [
                    'instance_id'          => $id,
                    'label'                => $this->htmlSanitizer->clean((string)$row['label']),
                    'position'             => (int)$row['position'],
                    'primary_language_id'  => (int)$row['primary_language_id'],
                    'primary_domain'       => mb_strtolower((string)$row['primary_domain']),
                    'protocol'             => (string)$row['protocol'],
                    'canonical'            => (int)$row['canonical'],
                    'record_id'            => (int)($row['record_id'] ?? 0),
                    'template_id'          => (int)($row['template_id'] ?? 0),
                    // Additional data: script policy (SecurityHeaders)
                    'details'              => json_decode((string)($row['details'] ?? ''), true) ?: [],
                    'additional_languages' => [],
                    'languages'            => [],
                    'readable_instances'   => [],
                    'additional_domains'   => [],
                    'areas'                => [],
                ];
            }

            // Content areas (all types, also those marked as deleted - see
            // class comment). Tree order per instance; for the collective file
            // additionally by instance position, hence the JOIN.
            $allAreas = [];
            $rawSlugPrefixes = []; // area_id => details.slug_prefix (raw, per language_id)
            // Trigger templates per area in their order (TriggerTemplates)
            $triggers = [];
            $this->db->query("SELECT `area_id`, `template_id` FROM `rel_area_trigger` ORDER BY `area_id`, `position`, `template_id`");
            while ($row = $this->db->fetchNum()) {
                $triggers[(int)$row[0]][] = (int)$row[1];
            }

            $sql = "SELECT `a`.`area_id`, `a`.`instance_id`, `a`.`name`, `a`.`type`, `a`.`overview_template_id`, `a`.`details`, `a`.`deleted` FROM `area` `a`"
                 . " INNER JOIN `instance` `i` ON `i`.`instance_id` = `a`.`instance_id`"
                 . " ORDER BY " . \Camindo\Core\Modules\Instance\Instance::orderSql('i') . ", `a`.`lft` ASC";
            $this->db->query($sql);
            while ($row = $this->db->fetch()) {
                $id = (int)$row['instance_id'];
                if (!isset($instances[$id])) continue;
                $areaId = (int)$row['area_id'];
                $info = [
                    'name'        => (string)$row['name'],
                    'type'        => (string)$row['type'],
                    'instance_id' => $id,
                ];
                // only if set - keeps the cache files small (TriggerTemplates)
                if (isset($triggers[$areaId])) {
                    $info['trigger_template_ids'] = $triggers[$areaId];
                }
                // Overview template of the prefix address (Page::resolveRequest())
                if (!empty($row['overview_template_id'])) {
                    $info['overview_template_id'] = (int)$row['overview_template_id'];
                }
                // Slug prefix (only content areas, not deleted ones) - resolved
                // below, once the languages of the instance are fixed
                if (empty($row['deleted']) && in_array($info['type'], ['record', 'profile', 'ugc'], true)) {
                    $details = json_decode((string)($row['details'] ?? ''), true);
                    if (is_array($details) && !empty($details['slug_prefix']) && is_array($details['slug_prefix'])) {
                        $rawSlugPrefixes[$areaId] = $details['slug_prefix'];
                    }
                }
                $instances[$id]['areas'][$areaId] = $info;
                $allAreas[$areaId] = $info;
            }

            // Additional languages (own order per instance)
            $this->db->query("SELECT `instance_id`, `language_id` FROM `rel_instance_language` ORDER BY `instance_id` ASC, `position` ASC");
            while ($row = $this->db->fetch()) {
                $id = (int)$row['instance_id'];
                if (!isset($instances[$id])) continue;
                $instances[$id]['additional_languages'][] = (int)$row['language_id'];
            }

            // Additional domains
            $additionalDomains = [];
            $sql = "SELECT `r`.`instance_id`, `r`.`domain`, `r`.`regex`"
                 . " FROM `rel_instance_domain` `r`"
                 . " INNER JOIN `instance` `i` ON `i`.`instance_id` = `r`.`instance_id`"
                 . " ORDER BY " . \Camindo\Core\Modules\Instance\Instance::orderSql('i') . ", `r`.`position` ASC";
            $this->db->query($sql);
            while ($row = $this->db->fetch()) {
                $id = (int)$row['instance_id'];
                if (!isset($instances[$id])) continue;
                $entry = [
                    'instance_id' => $id,
                    'domain'      => mb_strtolower((string)$row['domain']),
                    'regex'       => (int)$row['regex'],
                ];
                $additionalDomains[] = $entry;
                $instances[$id]['additional_domains'][] = ['domain' => $entry['domain'], 'regex' => $entry['regex']];
            }

            // Readable instances (order of the instances themselves)
            $sql = "SELECT `r`.`instance_id`, `r`.`readable_id` FROM `rel_instance_instance` `r`"
                 . " LEFT JOIN `instance` `i` ON `i`.`instance_id` = `r`.`readable_id`"
                 . " ORDER BY " . \Camindo\Core\Modules\Instance\Instance::orderSql('i');
            $this->db->query($sql);
            while ($row = $this->db->fetch()) {
                $id = (int)$row['instance_id'];
                if (!isset($instances[$id])) continue;
                $readableId = (int)$row['readable_id'];
                if ($readableId > 0 && $readableId !== $id) {
                    $instances[$id]['readable_instances'][] = $readableId;
                }
            }
            // User groups and their rights (see buildGroups())
            $groupRows = $this->readGroups();
            $this->db->query("SELECT `status_id` FROM `status` ORDER BY `position` ASC");
            $statusIds = array_map('intval', $this->db->fetchColumn());
        } finally {
            $this->db->popResult();
        }

        // Language list per instance: primary language first, then the others in their
        // order. Languages that no longer exist drop out.
        $primaryLanguages = [];
        $primaryDomains   = [];
        foreach ($instances as $id => &$instance) {
            $list = [];
            $primaryId = $instance['primary_language_id'];
            if (isset($languages[$primaryId])) {
                $list[$primaryId] = $languages[$primaryId];
            }
            foreach ($instance['additional_languages'] as $languageId) {
                if ($languageId === $primaryId) continue;
                if (!isset($languages[$languageId])) continue;
                $list[$languageId] = $languages[$languageId];
            }
            $instance['languages'] = $list;
            $instance['additional_languages'] = array_values(array_diff(array_keys($list), [$primaryId]));
            $primaryLanguages[$id] = $primaryId;
            if ($instance['primary_domain'] !== '') {
                // First instance wins if the same domain is entered twice
                $primaryDomains[$instance['primary_domain']] ??= $id;
            }
        }
        unset($instance);

        // Slug prefixes per language of the instance, with fallback to the primary language
        // (see slugPrefix()); areas without a prefix get no entry
        foreach ($rawSlugPrefixes as $areaId => $raw) {
            $id = (int)$allAreas[$areaId]['instance_id'];
            $resolved = self::resolveSlugPrefixes(
                $raw,
                array_keys($instances[$id]['languages']),
                (int)$instances[$id]['primary_language_id']
            );
            if (empty($resolved)) continue;
            $allAreas[$areaId]['slug_prefix'] = $resolved;
            $instances[$id]['areas'][$areaId]['slug_prefix'] = $resolved;
        }

        $groups = $this->buildGroups($groupRows, array_keys($instances), $primaryLanguages, $allAreas, array_keys($languages), $statusIds);

        // Input fields in the trash: fieldname_id => field_ids (Cms::liveFieldSql())
        $deletedFields = [];
        $this->db->query(
            "SELECT `n`.`fieldname_id`, `f`.`field_id` FROM `field` `f`"
            . " INNER JOIN `fieldname` `n` ON `n`.`name` = `f`.`name`"
            . " WHERE `f`.`deleted` = 1 ORDER BY `f`.`field_id`"
        );
        while ($row = $this->db->fetchNum()) {
            $deletedFields[(int)$row[0]][] = (int)$row[1];
        }

        $all = [
            'default_id'         => \Camindo\Core\Modules\Instance\Instance::DEFAULT_ID,
            'instance_ids'       => array_keys($instances),
            'primary_domains'    => $primaryDomains,
            'additional_domains' => $additionalDomains,
            'primary_languages'  => $primaryLanguages,
            'collations'         => $collations,
            'language_codes'     => array_map(fn(array $language) => $language['code'], $languages),
            'areas'              => $allAreas,
            'group_ids'          => array_keys($groups),
            'deleted_fields'     => $deletedFields,
        ];

        $dir = $this->cacheDir();
        $this->system->createDir($this->config->get('paths.var_dir'), 'cache');

        // Individual files
        foreach ($instances as $id => $instance) {
            $this->writeFile($this->instanceFile($id), $instance);
        }
        foreach ($groups as $id => $group) {
            $this->writeFile($this->groupFile($id), $group);
        }
        // Collective file last: it is the signal that the stock is complete
        $this->writeFile($dir . '/' . self::FILE_ALL, $all);

        // Remove files of deleted instances and groups
        foreach ((array)glob($dir . '/instance-*.php') as $file) {
            if (!preg_match('/instance-(\d+)\.php$/', $file, $m)) continue;
            if (isset($instances[(int)$m[1]])) continue;
            @unlink($file);
        }
        foreach ((array)glob($dir . '/group-*.php') as $file) {
            if (!preg_match('/group-(\d+)\.php$/', $file, $m)) continue;
            if (isset($groups[(int)$m[1]])) continue;
            @unlink($file);
        }
    }

    /**
     * Read the groups and their rights tables. Call only inside writeAll()
     * (under pushResult()).
     *
     * @return array  group_id => ['label', 'details' (array), 'instance_ids',
     *                'areas' (area_id => flags), 'languages' (language_id => can_write),
     *                'statuses' (status_ids)]
     */
    private function readGroups(): array
    {
        // Signatures (Integrity): a row without a valid signature does not count -
        // for `group` the details (modules) are dropped, for the relation tables the
        // row. The superuser group needs none: its rights are not in the
        // database (buildGroups()).
        $groups = [];
        $this->db->query("SELECT `group_id`, `label`, `details`, `sig` FROM `group` ORDER BY `group_id` ASC");
        while ($row = $this->db->fetch()) {
            $id = (int)$row['group_id'];
            $detailsOk = $id === self::SUPERUSER_GROUP_ID
                || $this->integrity->verifyGroup($id, (string)($row['details'] ?? ''), (string)($row['sig'] ?? ''));
            $groups[$id] = [
                'label'        => (string)$row['label'],
                'details'      => $detailsOk ? (json_decode((string)($row['details'] ?? ''), true) ?: []) : [],
                'instance_ids' => [],
                'areas'        => [],
                'languages'    => [],
                'statuses'     => [],
            ];
        }
        $this->db->query("SELECT `group_id`, `instance_id`, `sig` FROM `rel_group_instance`");
        while ($row = $this->db->fetch()) {
            $id = (int)$row['group_id'];
            if (!isset($groups[$id]) || !$this->integrity->verifyRight('rel_group_instance', $row)) continue;
            $groups[$id]['instance_ids'][] = (int)$row['instance_id'];
        }
        $this->db->query("SELECT * FROM `rel_group_area`");
        while ($row = $this->db->fetch()) {
            $id = (int)$row['group_id'];
            if (!isset($groups[$id]) || !$this->integrity->verifyRight('rel_group_area', $row)) continue;
            $groups[$id]['areas'][(int)$row['area_id']] = [
                'group_write'  => !empty($row['group_write']),
                'group_delete' => !empty($row['group_delete']),
                'other_write'  => !empty($row['other_write']),
                'other_delete' => !empty($row['other_delete']),
                'set_owner'    => !empty($row['set_owner']),
            ];
        }
        $this->db->query("SELECT `group_id`, `language_id`, `can_write`, `sig` FROM `rel_group_language`");
        while ($row = $this->db->fetch()) {
            $id = (int)$row['group_id'];
            if (!isset($groups[$id]) || !$this->integrity->verifyRight('rel_group_language', $row)) continue;
            $groups[$id]['languages'][(int)$row['language_id']] = !empty($row['can_write']);
        }
        $this->db->query("SELECT `group_id`, `status_id`, `sig` FROM `rel_group_status`");
        while ($row = $this->db->fetch()) {
            $id = (int)$row['group_id'];
            if (!isset($groups[$id]) || !$this->integrity->verifyRight('rel_group_status', $row)) continue;
            $groups[$id]['statuses'][] = (int)$row['status_id'];
        }
        return $groups;
    }

    /**
     * Assemble the content of the group files (format see class comment).
     *
     * The superuser group gets everything: all instances, all areas (including
     * folders), all modules with all additional rights, all languages with write right,
     * all statuses.
     * For the other groups only areas of assigned instances count; rights
     * on areas whose instance is no longer assigned to the group are dropped.
     *
     * @param array $groupRows          readGroups()
     * @param int[] $instanceIds        all instances in backend order
     * @param array $primaryLanguages   instance_id => primary_language_id
     * @param array $allAreas           area_id => ['name', 'type', 'instance_id']
     * @param int[] $languageIds        all languages
     * @param int[] $statusIds          all statuses (order as `position`)
     * @return array  group_id => file content
     */
    private function buildGroups(array $groupRows, array $instanceIds, array $primaryLanguages, array $allAreas, array $languageIds, array $statusIds = []): array
    {
        // Modules from var/config/components/modules.php - Cms holds them; the container
        // returns the same instance, no circular reference arises (Cms only needs
        // InstanceCache at runtime)
        $modules = [];
        $app = $GLOBALS['app'] ?? null;
        if ($app instanceof ContainerInterface) {
            foreach ((array)$app->get(Cms::class)->modules as $name => $settings) {
                $modules[(string)$name] = array_map('strval', array_keys((array)($settings['access'] ?? [])));
            }
        }

        $groups = [];
        foreach ($groupRows as $groupId => $row) {
            $isSuperuser = ($groupId === self::SUPERUSER_GROUP_ID);
            $file = [
                'group_id'          => $groupId,
                'superuser'         => $isSuperuser,
                'label'             => $row['label'],
                'instance_ids'      => [],
                'primary_languages' => [],
                'areas'             => [],
                'modules'           => [],
                'interfaces'        => $isSuperuser
                    ? Access::INTERFACES
                    : Access::normalizeInterfaces(isset($row['details']['interfaces']) ? (array)$row['details']['interfaces'] : null),
                'rights'            => ['areas' => [], 'languages' => [], 'statuses' => []],
            ];
            if ($isSuperuser) {
                $file['instance_ids'] = $instanceIds;
                $file['primary_languages'] = $primaryLanguages;
                $file['areas'] = $allAreas;
                $file['modules'] = $modules;
                foreach ($allAreas as $areaId => $info) {
                    if ($info['type'] === 'folder') continue;
                    $file['rights']['areas'][$areaId] = [
                        'group_write' => true, 'group_delete' => true,
                        'other_write' => true, 'other_delete' => true, 'set_owner' => true,
                    ];
                }
                foreach ($languageIds as $languageId) {
                    $file['rights']['languages'][$languageId] = true;
                }
                $file['rights']['statuses'] = $statusIds;
                $groups[$groupId] = $file;
                continue;
            }

            // Instances in backend order
            $assigned = array_values(array_intersect($instanceIds, $row['instance_ids']));
            $file['instance_ids'] = $assigned;
            foreach ($assigned as $instanceId) {
                $file['primary_languages'][$instanceId] = (int)($primaryLanguages[$instanceId] ?? 0);
            }
            // Readable areas (order as allAreas) and their flags
            foreach ($allAreas as $areaId => $info) {
                if (!isset($row['areas'][$areaId])) continue;
                if (!in_array($info['instance_id'], $assigned, true)) continue;
                if ($info['type'] === 'folder') continue;
                $file['areas'][$areaId] = $info;
                $file['rights']['areas'][$areaId] = $row['areas'][$areaId];
            }
            // Modules: only known ones, additional rights only defined ones
            foreach ((array)($row['details']['modules'] ?? []) as $name => $rights) {
                if (!isset($modules[$name])) continue;
                $file['modules'][$name] = array_values(array_intersect($modules[$name], array_map('strval', (array)$rights)));
            }
            // Languages: only existing ones
            foreach ($row['languages'] as $languageId => $canWrite) {
                if (!in_array($languageId, $languageIds, true)) continue;
                $file['rights']['languages'][$languageId] = (bool)$canWrite;
            }
            // Settable statuses: only existing ones, order as `position`
            $file['rights']['statuses'] = array_values(array_intersect($statusIds, $row['statuses']));
            $groups[$groupId] = $file;
        }
        return $groups;
    }

    // ====================
    // Hilfsfunktionen
    // ====================

    private function cacheDir(): string
    {
        return $this->config->get('paths.var_dir') . '/cache';
    }

    private function instanceFile(int $instanceId): string
    {
        return $this->cacheDir() . '/instance-' . $instanceId . '.php';
    }

    private function groupFile(int $groupId): string
    {
        return $this->cacheDir() . '/group-' . $groupId . '.php';
    }

    /**
     * Include a cache file.
     *
     * @return array|null  null if the file is missing or does not return an array
     */
    private function includeFile(string $file): ?array
    {
        if (!is_file($file)) return null;
        $data = @include $file;
        return is_array($data) ? $data : null;
    }

    /**
     * Write an array as a PHP file - first temporarily, then to its place via rename().
     */
    private function writeFile(string $file, array $data): void
    {
        $content = "<?php\n// Generiert von " . self::class . " am " . gmdate('Y-m-d H:i:s') . " UTC - nicht von Hand ändern.\n"
                 . "return " . var_export($data, true) . ";\n";
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new \Exception("InstanceCache: could not write \"{$tmp}\".");
        }
        @chmod($tmp, $this->config->get('permissions.file') ?? 0664);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \Exception("InstanceCache: could not rename \"{$tmp}\" to \"{$file}\".");
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }

    /**
     * Run a task under the lock file (blocking).
     */
    private function withLock(callable $task): void
    {
        $this->system->createDir($this->config->get('paths.var_dir'), 'cache');
        $lockFile = $this->cacheDir() . '/' . self::FILE_LOCK;
        $handle = @fopen($lockFile, 'c');
        if ($handle === false) {
            // Work anyway without a lock file - a double build is better than none
            $task();
            return;
        }
        try {
            flock($handle, LOCK_EX);
            $task();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Make language codes comparable: lower case, '_' -> '-'.
     */
    private function normalizeCode(string $code): string
    {
        return str_replace('_', '-', mb_strtolower(trim($code)));
    }
}
