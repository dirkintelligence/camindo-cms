<?php
/**
 * Quick search of the backend (Ctrl+G, macOS Cmd+G) - the server side of
 * public_master/backend/assets/js/backend-quicksearch.js.
 *
 * The user types a prefix and a term, e.g. "content:sch". Each prefix stands for one
 * module and one main field:
 *
 *     prefix     module      main field                     opens
 *     area       Area        name, label                    Area/area_info.php
 *     content    Record      default field (data_dft) in    Record/record_info.php
 *                            the preferred language version
 *     instance   Instance    label, domains                 Instance/instance_info.php
 *     template   Template    name, label                    Template/template_info.php
 *     converter  Converter   label                          Converter/converter_info.php
 *     tool       Tools       label of the tool / tab        the tab (ToolsBackend::QUICK_SEARCH)
 *     snippet    Snippets    key                            Snippets/snippet_edit.php
 *     user       Admin       full text (admin.ft)           Admin/admin_info.php
 *     group      Admin       label (superusers)             Admin/group_info.php
 *     trash      Trash       default field / name, label    Trash/index.php
 *     task       Scheduler   label                          Scheduler/scheduler_info.php
 *
 * Only prefixes whose module the user group may use are offered (Access::module(),
 * further rights per prefix as in the modules themselves: readable areas and
 * languages, instances, template areas, additional rights of the tools tabs).
 *
 * HITS
 * --------------------
 * First up to LIMIT hits whose main field STARTS with the term; if there are fewer,
 * up to LIMIT more that CONTAIN it. The comparison ignores case and accents:
 * in MySQL through the collation of the columns, in PHP (labels that may be snippet
 * references, snippet keys) through Collation::fold() in the backend language.
 *
 * Records (content, trash) are searched in the preferred language version of the user
 * (personal setting, Cms::languageExpr()) - the language the record lists show.
 *
 * Each hit: title, detail (small, next to the title), sub (line below: area of records
 * and templates, the instance if the user sees more than one), icon and the layer
 * path that a selection opens. Enter in the input field ("submit") opens the search
 * result of the module if it has a full-text search (content, user), otherwise its
 * overview - as a list of layer paths that are added one after the other.
 *
 * The endpoint (backend/quicksearch.php) only reads: GET, JSON.
 *
 * @see Camindo\Core\Modules\Tools\ToolsBackend::QUICK_SEARCH  tools of the prefix "tool"
 * @see Camindo\Core\Modules\Trash\Trash::searchRecords()       deleted records
 */
namespace Camindo\Core\Modules\Start;

use Camindo\Core\Access;
use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\Collation;
use Camindo\Core\ContainerInterface;
use Camindo\Core\Database;
use Camindo\Core\InstanceCache;
use Camindo\Core\Session;
use Camindo\Core\Modules\Area\Area;
use Camindo\Core\Modules\Snippets\Snippets;
use Camindo\Core\Modules\Tools\ToolsBackend;
use Camindo\Core\Modules\Trash\Trash;

class StartQuickSearch {

    /** Hits per group (starts with / contains) */
    public const LIMIT = 8;

    /** Longest term that is searched */
    public const MAX_TERM = 100;

    /**
     * The prefixes in the order of the selection: prefix => [module, icon, label snippet].
     * The label is the module label - the same word as on the start page.
     */
    public const PREFIXES = [
        'area'      => ['Area', 'transition_dissolve', 'area.module_label'],
        'content'   => ['Record', 'draft', 'record.module_label'],
        'instance'  => ['Instance', 'language', 'instance.module_label'],
        'template'  => ['Template', 'extension', 'template.module_label'],
        'converter' => ['Converter', 'manufacturing', 'converter.module_label'],
        'tool'      => ['Tools', 'build', 'tools.module_label'],
        'snippet'   => ['Snippets', 'translate', 'snippets.module_label'],
        'user'      => ['Admin', 'person', 'admin.module_label'],
        'group'     => ['Admin', 'group', 'admin.headline_group_list'],
        'trash'     => ['Trash', 'delete', 'trash.module_label'],
        'task'      => ['Scheduler', 'schedule', 'scheduler.module_label'],
    ];

    /** Instance labels the user may see (instance_id => text), see instanceLabels() */
    private ?array $instanceLabels = null;

    public function __construct(
        private Access $access,
        private Backend $backend,
        private Cms $cms,
        private ContainerInterface $container,
        private Database $db,
        private InstanceCache $instanceCache,
        private Session $session,
    )
    {}

    /**
     * backend/quicksearch.php: answer as JSON.
     *
     *     ?texts=1        texts of the input window and the offered prefixes
     *     ?q=content:abc  hits: {prefix, term, starts: [...], contains: [...], submit: [...]}
     *     &submit=1       only the layers of Enter (submit), without searching
     */
    public function handle(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (!empty($_GET['texts'])) {
            $this->_json(['texts' => $this->texts(), 'prefixes' => $this->prefixes()]);
            return;
        }
        $this->_json($this->search((string)($_GET['q'] ?? ''), empty($_GET['submit'])));
    }

    /**
     * Texts of the input window in the backend language.
     */
    public function texts(): array
    {
        $texts = [];
        foreach (['label', 'placeholder', 'hint', 'headline_prefixes', 'headline_starts', 'headline_contains',
                  'no_results', 'searching', 'hint_submit', 'hint_overview', 'unknown_prefix'] as $key) {
            $texts[$key] = $this->cms->snippet('start.quicksearch_' . $key);
        }
        $texts['close'] = $this->cms->snippet('button_close');
        return $texts;
    }

    /**
     * Prefixes the user may use: [{prefix, label, icon}].
     */
    public function prefixes(): array
    {
        $list = [];
        foreach (self::PREFIXES as $prefix => [$module, $icon, $label]) {
            if (!$this->allowed($prefix)) continue;
            $list[] = ['prefix' => $prefix, 'label' => $this->cms->snippet($label), 'icon' => $icon];
        }
        return $list;
    }

    /**
     * May the user use this prefix? Module active and allowed for the group, plus the
     * conditions of the module (groups: superusers only, as GroupBackend).
     */
    public function allowed(string $prefix): bool
    {
        $module = self::PREFIXES[$prefix][0] ?? null;
        if ($module === null || !isset($this->cms->modules[$module])) return false;
        if (!$this->access->module($module)) return false;
        return match ($prefix) {
            'group' => $this->backend->isSuperuser(),
            'tool'  => (bool)$this->_toolTabs(),
            default => true,
        };
    }

    /**
     * Search: "prefix:term". Without a known prefix there are no hits.
     *
     * @param bool $hits  false: only prefix, term and submit (Enter in the field)
     * @return array  ['prefix', 'term', 'starts' => item[], 'contains' => item[],
     *                 'submit' => string[] layer paths for Enter]
     */
    public function search(string $query, bool $hits = true): array
    {
        $result = ['prefix' => '', 'term' => '', 'starts' => [], 'contains' => [], 'submit' => []];
        $pos = strpos($query, ':');
        if ($pos === false) return $result;
        $prefix = strtolower(trim(substr($query, 0, $pos)));
        $term = mb_substr(trim(substr($query, $pos + 1)), 0, self::MAX_TERM);
        if (!$this->allowed($prefix)) return $result;

        $result['prefix'] = $prefix;
        $result['term'] = $term;
        $result['submit'] = $this->submitPaths($prefix, $term);
        if ($term === '' || !$hits) return $result;

        [$starts, $contains] = match ($prefix) {
            'area'      => $this->_areas($term),
            'content'   => $this->_contents($term),
            'instance'  => $this->_instances($term),
            'template'  => $this->_templates($term),
            'converter' => $this->_converters($term),
            'tool'      => $this->_tools($term),
            'snippet'   => $this->_snippets($term),
            'user'      => $this->_users($term),
            'group'     => $this->_groups($term),
            'trash'     => $this->_trash($term),
            'task'      => $this->_tasks($term),
        };
        $result['starts'] = $starts;
        $result['contains'] = $contains;
        return $result;
    }

    /**
     * Layers that Enter in the input field opens: the full-text search of the module
     * (content, user) - otherwise its overview.
     *
     * @return string[]  paths for cApp.layers.add(), in this order
     */
    public function submitPaths(string $prefix, string $term): array
    {
        if ($term !== '' && $prefix === 'content') {
            // as the cross-area search of the module: empty intermediate layer, result above it
            return ['Record/record_global_search.php', 'Record/record_list.php?' . http_build_query(['filter' => ['fulltext' => $this->fulltextTerm($term)]])];
        }
        if ($term !== '' && $prefix === 'user') {
            return ['Admin/admin_list.php?' . http_build_query(['filter' => ['ft' => $this->fulltextTerm($term)]])];
        }
        return match ($prefix) {
            'area'      => ['Area/area_list.php'],
            'content'   => ['Record/index.php'],
            'instance'  => ['Instance/instance_list.php'],
            'template'  => ['Template/template_areas.php'],
            'converter' => ['Converter/converter_list.php'],
            'tool'      => [(string)(array_values($this->_toolTabs())[0] ?? 'Tools/index.php')],
            'snippet'   => ['Snippets/index.php'],
            'user'      => ['Admin/admin_list.php'],
            'group'     => ['Admin/group_list.php'],
            'trash'     => ['Trash/index.php'],
            'task'      => ['Scheduler/scheduler_list.php'],
            default     => [],
        };
    }

    /**
     * Term for a full-text field (MySQL boolean mode, developer docs, chapter 04): without
     * own operators every word gets a '*' - "sch" finds "Schlüssel" as the hit list does.
     */
    public function fulltextTerm(string $term): string
    {
        if (preg_match('/[+\-"()~<>*@]/', $term)) return $term;
        $words = preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return implode(' ', array_map(fn(string $w): string => $w . '*', $words));
    }

    // ====================
    // Providers - each returns [starts, contains]

    /** area: name and label, all area types; instances of the group */
    private function _areas(string $term): array
    {
        $rows = $this->_rows("SELECT `area_id`, `instance_id`, `type`, `name`, `label`, `icon` FROM `area` WHERE `deleted` = 0 ORDER BY `lft`");
        $items = [];
        foreach ($rows as $row) {
            if (!$this->access->instance((int)$row['instance_id'])) continue;
            $label = $this->cms->textOrSnippet((string)$row['label']);
            $items[] = [
                'fields' => [(string)$row['name'], $label],
                'item'   => $this->_item(
                    $label !== '' ? $label : (string)$row['name'],
                    $label !== '' ? (string)$row['name'] : '',
                    $this->_sub([(string)$row['type']], (int)$row['instance_id']),
                    (string)$row['icon'] ?: self::PREFIXES['area'][1],
                    'Area/area_info.php?id=' . (int)$row['area_id']
                ),
            ];
        }
        return $this->_match($term, $items);
    }

    /**
     * content: default field of the records (data_dft) in the preferred language version
     * of the user (Cms::languageExpr(): per instance the preferred language if the
     * instance has it, otherwise its main language - as in the record lists),
     * readable areas and languages.
     */
    private function _contents(string $term): array
    {
        $where = "`r`.`tmp` = 0 AND `r`.`deleted` = 0";
        $areaIds = $this->access->areaFilter(Area::CONTENT_TYPES);
        if ($areaIds !== null) {
            $areaIds = array_values(array_filter(array_map('intval', $areaIds), fn(int $id): bool => $id > 0));
            $where .= $areaIds ? " AND `r`.`area_id` IN (" . implode(',', $areaIds) . ")" : " AND 0";
        }
        $instanceIds = $this->access->instanceFilter();
        if ($instanceIds !== null) {
            $instanceIds = array_values(array_filter(array_map('intval', $instanceIds), fn(int $id): bool => $id > 0));
            $where .= $instanceIds ? " AND `r`.`instance_id` IN (" . implode(',', $instanceIds) . ")" : " AND 0";
        }
        $languageWhere = '';
        if (!$this->backend->isSuperuser()) {
            $languageIds = array_values(array_filter(array_map('intval', $this->access->readableLanguageIds()), fn(int $id): bool => $id > 0));
            $languageWhere = $languageIds ? " AND `d`.`language_id` IN (" . implode(',', $languageIds) . ")" : " AND 0";
        }
        $types = "'" . implode("','", Area::CONTENT_TYPES) . "'";
        $language = $this->cms->languageExpr('r');

        $query = function (string $like, array $excludeIds) use ($where, $languageWhere, $types, $language): array {
            $exclude = $excludeIds ? " AND `r`.`record_id` NOT IN (" . implode(',', array_map('intval', $excludeIds)) . ")" : '';
            return $this->_rows(
                "SELECT `r`.`record_id`, `r`.`area_id`, `r`.`instance_id`, `d`.`data` AS `title`"
                . " FROM `record` `r`"
                . " JOIN `area` `a` ON `a`.`area_id` = `r`.`area_id` AND `a`.`deleted` = 0 AND `a`.`type` IN ({$types})"
                . " JOIN `data_dft` `d` ON `d`.`record_id` = `r`.`record_id` AND `d`.`language_id` = {$language}"
                . " WHERE `d`.`data` LIKE ? AND {$where}{$languageWhere}{$exclude}"
                . " ORDER BY `title`, `r`.`record_id`"
                . " LIMIT " . self::LIMIT,
                's', [$like]
            );
        };
        $toItem = fn(array $row): array => $this->_item(
            (string)$row['title'], '',
            $this->_sub([$this->_areaLabel((int)$row['area_id'])], (int)$row['instance_id']),
            $this->_areaIcon((int)$row['area_id']) ?: self::PREFIXES['content'][1],
            'Record/record_info.php?area_id=' . (int)$row['area_id'] . '&id=' . (int)$row['record_id']
        );

        $like = $this->_like($term);
        $starts = $query($like . '%', []);
        $contains = count($starts) < self::LIMIT
            ? $query('%' . $like . '%', array_map(fn(array $r): int => (int)$r['record_id'], $starts))
            : [];
        return [array_map($toItem, $starts), array_map($toItem, $contains)];
    }

    /** instance: label and domains (main domain, further domains without regex) */
    private function _instances(string $term): array
    {
        $domains = [];
        foreach ($this->_rows("SELECT `instance_id`, `domain` FROM `rel_instance_domain` WHERE `regex` = 0 ORDER BY `position`") as $row) {
            $domains[(int)$row['instance_id']][] = (string)$row['domain'];
        }
        $items = [];
        foreach ($this->_rows("SELECT `instance_id`, `label`, `primary_domain` FROM `instance` ORDER BY `position`, `instance_id`") as $row) {
            $id = (int)$row['instance_id'];
            if (!$this->access->instance($id)) continue;
            $label = $this->cms->textOrSnippet((string)$row['label']);
            $hosts = array_values(array_unique(array_filter(array_merge([(string)$row['primary_domain']], $domains[$id] ?? []))));
            $items[] = [
                'fields' => array_merge([$label], $hosts),
                'item'   => $this->_item($label !== '' ? $label : '#' . $id, '', implode(', ', $hosts),
                    self::PREFIXES['instance'][1], 'Instance/instance_info.php?id=' . $id),
            ];
        }
        return $this->_match($term, $items);
    }

    /** template: name and label; readable template areas */
    private function _templates(string $term): array
    {
        $items = [];
        $rows = $this->_rows("SELECT `t`.`template_id`, `t`.`area_id`, `t`.`instance_id`, `t`.`name`, `t`.`label`"
            . " FROM `template` `t` JOIN `area` `a` ON `a`.`area_id` = `t`.`area_id` AND `a`.`deleted` = 0"
            . " ORDER BY `t`.`name`");
        foreach ($rows as $row) {
            $areaId = (int)$row['area_id'];
            if (!$this->access->templateRead($areaId) || !$this->access->instance((int)$row['instance_id'])) continue;
            $label = $this->cms->textOrSnippet((string)$row['label']);
            $items[] = [
                'fields' => [(string)$row['name'], $label],
                'item'   => $this->_item(
                    $label !== '' ? $label : (string)$row['name'],
                    $label !== '' ? (string)$row['name'] : '',
                    $this->_sub([$this->_areaLabel($areaId)], (int)$row['instance_id']),
                    self::PREFIXES['template'][1],
                    'Template/template_info.php?area_id=' . $areaId . '&id=' . (int)$row['template_id']
                ),
            ];
        }
        return $this->_match($term, $items);
    }

    /** converter: label */
    private function _converters(string $term): array
    {
        $items = [];
        foreach ($this->_rows("SELECT `converter_id`, `processor`, `type`, `label` FROM `converter` ORDER BY `label`") as $row) {
            $label = $this->cms->textOrSnippet((string)$row['label']);
            $items[] = [
                'fields' => [$label],
                'item'   => $this->_item($label !== '' ? $label : (string)$row['processor'], '',
                    trim((string)$row['processor'] . ' · ' . (string)$row['type'], ' ·'),
                    self::PREFIXES['converter'][1], 'Converter/converter_info.php?id=' . (int)$row['converter_id']),
            ];
        }
        return $this->_match($term, $items);
    }

    /** tool: tabs and the tools on them (ToolsBackend::QUICK_SEARCH), with the right of the tab */
    private function _tools(string $term): array
    {
        $items = [];
        foreach ($this->_toolTabs() as $tab => $tabScript) {
            $tabLabel = $this->cms->snippet('tools.tab_label_' . $tab);
            $items[] = [
                'fields' => [$tabLabel],
                'item'   => $this->_item($tabLabel, '', $this->cms->snippet('tools.module_label'), self::PREFIXES['tool'][1], $tabScript),
            ];
            foreach (ToolsBackend::QUICK_SEARCH[$tab] ?? [] as $snippet => $script) {
                $label = $this->cms->snippet($snippet);
                $items[] = [
                    'fields' => [$label],
                    'item'   => $this->_item($label, '', $tabLabel, self::PREFIXES['tool'][1], $script),
                ];
            }
        }
        return $this->_match($term, $items);
    }

    /** snippet: key, in the sets of the instances the user may see */
    private function _snippets(string $term): array
    {
        $snippets = $this->container->get(Snippets::class);
        $lang = $this->cms->getLang();
        $items = [];
        foreach (array_keys($snippets->instances()) as $instanceId) {
            foreach ($snippets->load((int)$instanceId) as $key => $texts) {
                $text = (string)($texts[$lang] ?? '') !== '' ? (string)$texts[$lang] : (string)(array_values(array_filter($texts, 'strlen'))[0] ?? '');
                $items[] = [
                    'fields' => [(string)$key],
                    'item'   => $this->_item((string)$key, '',
                        $this->_sub([mb_strimwidth(trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? ''), 0, 80, '…')], (int)$instanceId),
                        self::PREFIXES['snippet'][1],
                        'Snippets/snippet_edit.php?' . http_build_query(['instance_id' => (int)$instanceId, 'key' => (string)$key])),
                ];
            }
        }
        return $this->_match($term, $items);
    }

    /**
     * user: full-text column admin.ft (name, display name, first name, sort name -
     * one per line). "Starts with" = one of these lines starts with the term.
     */
    private function _users(string $term): array
    {
        $like = $this->_like($term);
        $select = "SELECT `admin_id`, `group_id`, `name`, `displayname` FROM `admin` WHERE `deleted` = 0";
        $starts = $this->_rows($select . " AND (`ft` LIKE ? OR `ft` LIKE ?) ORDER BY `name` LIMIT " . self::LIMIT,
            'ss', [$like . '%', "%\n" . $like . '%']);
        $contains = [];
        if (count($starts) < self::LIMIT) {
            $ids = array_map(fn(array $r): int => (int)$r['admin_id'], $starts);
            $contains = $this->_rows($select . " AND `ft` LIKE ?"
                . ($ids ? " AND `admin_id` NOT IN (" . implode(',', $ids) . ")" : '')
                . " ORDER BY `name` LIMIT " . self::LIMIT, 's', ['%' . $like . '%']);
        }
        $groups = [];
        foreach ($this->_rows("SELECT `group_id`, `label` FROM `group`") as $row) {
            $groups[(int)$row['group_id']] = $this->cms->textOrSnippet((string)$row['label']);
        }
        $toItem = function (array $row) use ($groups): array {
            $display = trim((string)$row['displayname']);
            $icon = (int)$row['group_id'] === InstanceCache::SUPERUSER_GROUP_ID ? 'shield_person' : self::PREFIXES['user'][1];
            return $this->_item($display !== '' ? $display : (string)$row['name'], $display !== '' ? (string)$row['name'] : '',
                $groups[(int)$row['group_id']] ?? '', $icon, 'Admin/admin_info.php?id=' . (int)$row['admin_id']);
        };
        return [array_map($toItem, $starts), array_map($toItem, $contains)];
    }

    /** group: label (superusers, allowed()) */
    private function _groups(string $term): array
    {
        $items = [];
        foreach ($this->_rows("SELECT `group_id`, `label` FROM `group` ORDER BY `label`") as $row) {
            $label = $this->cms->textOrSnippet((string)$row['label']);
            $items[] = [
                'fields' => [$label],
                'item'   => $this->_item($label, '', '', self::PREFIXES['group'][1], 'Admin/group_info.php?id=' . (int)$row['group_id']),
            ];
        }
        return $this->_match($term, $items);
    }

    /**
     * trash: deleted records by their default field (Trash::searchRecords()), for
     * superusers also deleted areas (name, label) and input fields (name, label).
     * A selection opens the trash - with all=1 if someone else deleted the record.
     */
    private function _trash(string $term): array
    {
        $trash = $this->container->get(Trash::class);
        $adminId = (int)$this->session->get('admin_id');
        $isSuperuser = $this->backend->isSuperuser();
        $recordLabel = $this->cms->snippet('trash.column_record');
        $toItem = fn(array $row): array => $this->_item(
            (string)$row['title'], '',
            $this->_sub([$recordLabel, $this->_areaLabel((int)$row['area_id'])], (int)$row['instance_id']),
            self::PREFIXES['trash'][1],
            ($isSuperuser && (int)$row['changed_by'] !== $adminId) ? 'Trash/index.php?all=1' : 'Trash/index.php'
        );

        $like = $this->_like($term);
        $startRows = $trash->searchRecords($like . '%', self::LIMIT);
        $starts = array_map($toItem, $startRows);
        $contains = [];
        if (count($starts) < self::LIMIT) {
            $found = array_map(fn(array $r): int => (int)$r['record_id'], $startRows);
            $contains = array_map($toItem, $trash->searchRecords('%' . $like . '%', self::LIMIT, $found));
        }
        if (!$isSuperuser) return [$starts, $contains];

        // areas and input fields (superusers): matched in PHP, labels may be snippets
        $items = [];
        $areaLabel = $this->cms->snippet('trash.column_area');
        foreach ($trash->areas() as $row) {
            $label = $this->cms->textOrSnippet((string)$row['label']);
            $items[] = [
                'fields' => [(string)$row['name'], $label],
                'item'   => $this->_item($label !== '' ? $label : (string)$row['name'], $label !== '' ? (string)$row['name'] : '',
                    $this->_sub([$areaLabel], (int)$row['instance_id']), self::PREFIXES['trash'][1], 'Trash/index.php?all=1'),
            ];
        }
        $fieldLabel = $this->cms->snippet('trash.column_field');
        foreach ($trash->fields() as $row) {
            $label = $this->cms->textOrSnippet((string)$row['label']);
            $areaId = (int)$row['area_id'];
            $items[] = [
                'fields' => [(string)$row['name'], $label],
                'item'   => $this->_item($label !== '' ? $label : (string)$row['name'], $label !== '' ? (string)$row['name'] : '',
                    $this->_sub([$fieldLabel, $this->_areaLabel($areaId)], $this->instanceCache->areaInstanceId($areaId)),
                    self::PREFIXES['trash'][1], 'Trash/index.php?all=1'),
            ];
        }
        [$moreStarts, $moreContains] = $this->_match($term, $items);
        $starts = array_slice(array_merge($starts, $moreStarts), 0, self::LIMIT);
        $contains = count($starts) < self::LIMIT ? array_slice(array_merge($contains, $moreContains), 0, self::LIMIT) : [];
        return [$starts, $contains];
    }

    /** task: label of the scheduler tasks */
    private function _tasks(string $term): array
    {
        $items = [];
        foreach ($this->_rows("SELECT `scheduler_id`, `label`, `type` FROM `scheduler` ORDER BY `label`") as $row) {
            $label = $this->cms->textOrSnippet((string)$row['label']);
            $type = $this->_snippetOr('scheduler.label_type_' . (string)$row['type'], (string)$row['type']);
            $items[] = [
                'fields' => [$label],
                'item'   => $this->_item($label, '', $type !== $label ? $type : '',
                    self::PREFIXES['task'][1], 'Scheduler/scheduler_info.php?id=' . (int)$row['scheduler_id']),
            ];
        }
        return $this->_match($term, $items);
    }

    // ====================
    // Helpers

    /**
     * Match in PHP: [starts, contains] with at most LIMIT each; "contains" only if
     * "starts" stays below LIMIT. Order as given.
     *
     * @param array $items  [['fields' => string[] main fields, 'item' => item], ...]
     */
    private function _match(string $term, array $items): array
    {
        $needle = $this->_fold($term);
        if ($needle === '') return [[], []];
        $starts = [];
        $contains = [];
        foreach ($items as $entry) {
            $best = null;
            foreach ($entry['fields'] as $field) {
                $haystack = $this->_fold((string)$field);
                $pos = $haystack === '' ? false : mb_strpos($haystack, $needle);
                if ($pos === 0) { $best = 'starts'; break; }
                if ($pos !== false) $best = 'contains';
            }
            if ($best === 'starts' && count($starts) < self::LIMIT) $starts[] = $entry['item'];
            elseif ($best === 'contains' && count($contains) < self::LIMIT) $contains[] = $entry['item'];
        }
        if (count($starts) >= self::LIMIT) $contains = [];
        return [$starts, $contains];
    }

    /** Fold for the comparison in PHP: case and accents as in the backend language */
    private function _fold(string $text): string
    {
        return Collation::fold(trim($text), $this->cms->getLang());
    }

    /** One hit for the JavaScript */
    private function _item(string $title, string $detail, string $sub, string $icon, string $path): array
    {
        $title = $this->cms->_strip_tags($title);
        return [
            'title'  => $title,
            'detail' => $detail !== $title ? $detail : '',
            'sub'    => $sub,
            'icon'   => preg_replace('/[^a-z0-9_]/', '', strtolower($icon)) ?: 'search',
            'path'   => $path,
        ];
    }

    /**
     * Line below a hit: the given parts, plus the instance if the user sees more
     * than one.
     *
     * @param string[] $parts
     */
    private function _sub(array $parts, int $instanceId): string
    {
        $labels = $this->instanceLabels();
        if (count($labels) > 1 && $instanceId > 0 && isset($labels[$instanceId])) $parts[] = $labels[$instanceId];
        return implode(' · ', array_filter(array_map('trim', $parts), 'strlen'));
    }

    /**
     * Instances the user may see: instance_id => label (snippets resolved).
     */
    public function instanceLabels(): array
    {
        if ($this->instanceLabels !== null) return $this->instanceLabels;
        $this->instanceLabels = [];
        foreach ($this->instanceCache->instanceLabels() as $id => $label) {
            if (!$this->access->instance((int)$id)) continue;
            $this->instanceLabels[(int)$id] = $this->cms->textOrSnippet((string)$label);
        }
        return $this->instanceLabels;
    }

    private function _areaLabel(int $areaId): string
    {
        $data = $this->backend->areaData($areaId);
        if (!$data) return '';
        $label = $this->cms->textOrSnippet((string)$data['label']);
        return $this->cms->_strip_tags($label !== '' ? $label : (string)$data['name']);
    }

    private function _areaIcon(int $areaId): string
    {
        return (string)($this->backend->areaData($areaId)['icon'] ?? '');
    }

    /** Snippet, or the fallback if it does not exist (Cms::snippet() returns the key) */
    private function _snippetOr(string $key, string $fallback): string
    {
        $text = $this->cms->snippet($key);
        return ($text === $key || $text === '') ? $fallback : $text;
    }

    /**
     * Tabs of the Tools module the group may open: tab => entry script.
     */
    private function _toolTabs(): array
    {
        $tabs = [];
        foreach (ToolsBackend::TAB_SCRIPTS as $tab => $script) {
            if ($this->access->moduleRight('Tools', $tab)) $tabs[$tab] = $script;
        }
        return $tabs;
    }

    /** Escape a term for LIKE (\ is the default escape character of MySQL) */
    private function _like(string $term): string
    {
        return addcslashes($term, '\\%_');
    }

    /** All rows of a query */
    private function _rows(string $sql, string $types = '', array $values = []): array
    {
        $this->db->pushResult();
        $this->db->query($sql, $types, $values);
        $rows = [];
        while ($row = $this->db->fetch()) $rows[] = $row;
        $this->db->popResult();
        return $rows;
    }

    private function _json(array $data): void
    {
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
