<?php
/**
 * Dataset child class for the table `instance` - an "Instance" is a website
 * or a standalone frontend presence.
 *
 * The table itself is narrow; most of the details are in three
 * relation tables, which the schema represents via fields of type 'reltable'
 * (see Cms::$dataSuffix):
 *
 *     additional_languages   `rel_instance_language`   languages besides the main language
 *     additional_domains     `rel_instance_domain`     domains besides the main domain
 *     readable_instances     `rel_instance_instance`   foreign instances whose content is
 *                                                      readable in the frontend
 *
 * FIELDS WITHOUT THEIR OWN COLUMN ('reltable')
 * --------------------
 * The type is in the schema only so that Dataset::save() applies its input check
 * (min/max, required field) to these fields as well. Dataset::save() skips
 * them when writing the table columns - they are read and written here:
 *
 *     hookOnLoaded()   relation table -> $data
 *     hookOnSaved()    $data -> relation table   (inside the transaction)
 *
 * OWNERSHIP OF THE CONTENT
 * --------------------
 * A content area (`area`, all three types) belongs via `area`.`instance_id` to
 * exactly one instance. Its records and templates inherit the assignment:
 * `record`.`instance_id` is taken from the area by Record::hookOnSave(),
 * templates hang on the area via fk_template_area_id.
 *
 * FILE CACHE AND LANGUAGES
 * --------------------
 * The frontend does not read the instances from the database but from
 * var/cache/instances.php and instance-{id}.php (Camindo\Core\InstanceCache).
 * hookOnSaved() and hookOnDeleted() rewrite these files. If the language list
 * of an instance changes, hookOnSaved() also updates the content rows of the
 * records (Camindo\Core\LanguageData): per language of the instance one row
 * per field, see VirtualFieldsTrait::saveVirtualFields().
 *
 * MEDIA DIRECTORY
 * --------------------
 * The media files are stored per instance under media/{instance_id}/{field_id}/. Each
 * instance directory carries an .htaccess that restricts direct access to the
 * domains of the instance and its reading instances. Both hooks
 * rewrite these files for ALL instances (Camindo\Core\MediaAccess) -
 * the lock of B depends on whether A may read it.
 *
 * THE DEFAULT INSTANCE
 * --------------------
 * `instance_id` 1 (Instance::DEFAULT_ID) is the catch-all for everything that matches
 * no configured domain. The additional domains are always ".*" (PCRE).
 * Everything else can be changed, however.
 * It is always listed last wherever instances are enumerated, regardless of its
 * `position` (orderSql(), InstanceCache), and it cannot be deleted: without it
 * every call whose domain is not registered anywhere would go nowhere.
 *
 * @see Camindo\Core\Modules\Instance\InstanceBackend
 * @see Camindo\Core\Modules\Area\Area   assignment of the content areas
 * @see Camindo\Core\Dataset
 */
namespace Camindo\Core\Modules\Instance;

use Camindo\Core\Dataset;
use Camindo\Core\InstanceCache;
use Camindo\Core\LanguageData;
use Camindo\Core\MediaAccess;
use Camindo\Core\SecurityHeaders;
use Camindo\Core\Modules\Area\Area;
use Camindo\Core\Modules\Snippets\Snippets;

class Instance extends Dataset {

    /**
     * The default instance - see class comment.
     * Also the database default of `area`.`instance_id` and `record`.`instance_id`.
     */
    public const DEFAULT_ID = 1;

    /**
     * ORDER BY expression for the order of the instances: by `position`, the
     * default instance always last (class comment). System-wide the same
     * order - queries that sort by instance use this expression.
     *
     * @param string $alias  table alias of `instance` ('' = none)
     */
    public static function orderSql(string $alias = ''): string
    {
        $prefix = $alias === '' ? '' : "`$alias`.";
        return "{$prefix}`instance_id` = " . self::DEFAULT_ID . " ASC, {$prefix}`position` ASC";
    }

    /** Maximum number of domains per instance (main domain not counted) */
    public const MAX_DOMAINS = 32;

    protected function hookDefinition(): void
    {
        $this->tableName  = 'instance';
        $this->moduleName = 'Instance';
        // Pseudo field 'instance' (Cms::select()): order of the instance cache,
        // the default instance last
        $this->defaultSortOrder = 'instance ASC';
        $this->schema = [
            'instance_id' => [
                'type'  => 'integer',
                'label' => '#instance.field_instance_id',
            ],
            'label' => [
                'type'  => 'chars',
                'label' => '#instance.field_label',
                'max'   => 64,
                'extension' => 'Snippets',   // Core extension: select snippet (#prefix.key)
                'flags' => ['trim', 'not_empty'],
            ],
            'position' => [
                'type'  => 'integer',
                'label' => '#instance.field_position',
                'display_type' => 'select',
            ],
            // `instance`.`primary_language_id` is NOT NULL (no foreign key),
            // therefore 'integer' and not 'id' - 'id' would write a NULL for 0.
            'primary_language_id' => [
                'type'  => 'integer',
                'label' => '#instance.field_primary_language_id',
                'display_type' => 'select',
                'min'   => 0,
            ],
            // Table `rel_instance_language`
            // 'sortable': the order of the checked languages is the value
            // (it ends up as `position` in the relation table)
            'additional_languages' => [
                'type'  => 'reltable',
                'display_type' => 'sortable',
                'label' => '#instance.field_additional_languages',
                'tooltip' => '#instance.tooltip_additional_languages',
            ],
            'primary_domain' => [
                'type'  => 'chars',
                'label' => '#instance.field_primary_domain',
                'note'  => '#instance.note_field_domain',
                'max'   => 64,
                'flags' => ['trim'],
            ],
            'protocol' => [
                'type'  => 'chars',
                'label' => '#instance.field_protocol',
                'display_type' => 'select',
                'max'   => 5,
                'flags' => ['not_empty'],
                'default' => 'https',
            ],
            'canonical' => [
                'type'  => 'bit',
                'label' => '#instance.field_canonical',
                'tooltip' => '#instance.tooltip_canonical',
                'display_type' => 'switch',
                'default' => 1,
            ],
            // Table `rel_instance_domain`
            // The field has no input field of its own: the domains are in the form as
            // pairs of rows data[domain][] / data[regex][] (see
            // InstanceBackend::_editForm()). It must be in the schema, though, so that
            // hookOnSaved() finds it again in $fields.
            'additional_domains' => [
                'type'  => 'reltable',
                'display_type' => 'multiselect',
                'label' => '#instance.field_additional_domains',
                'max'   => self::MAX_DOMAINS,
            ],
            // Table `rel_instance_instance`
            'readable_instances' => [
                'type'  => 'reltable',
                'display_type' => 'multiselect',
                'label' => '#instance.field_readable_instances',
                'tooltip' => '#instance.tooltip_readable_instances',
                'height' => 'auto',
            ],
            // Start page - both columns are NULLable (foreign key with
            // ON DELETE SET NULL), therefore 'id': the type writes a NULL for 0.
            'record_id' => [
                'type'  => 'id',
                'label' => '#instance.field_record_id',
                'tooltip' => '#instance.tooltip_record_id',
                'display_type' => 'lookup',
                'table' => 'record',
                'max'   => 1,
            ],
            'template_id' => [
                'type'  => 'id',
                'label' => '#instance.field_template_id',
                'tooltip' => '#instance.tooltip_template_id',
                'display_type' => 'lookup',
                'table' => 'template',
                'max'   => 1,
            ],
            // Additional info (JSON): script policy of the frontend
            'details' => [
                'type'  => 'json',
                'label' => '#instance.field_details',
            ],
            // Script policy of the frontend (SecurityHeaders::frontendPolicy(),
            // see developer docs, chapter 14 (Security: web): '' = default security.frontend_csp
            'details.csp' => [
                'type'  => 'chars',
                'display_type' => 'select',
                'label' => '#instance.field_csp',
                'tooltip' => '#instance.tooltip_csp',
            ],
            // further script sources, one per line (https://js.example.com)
            'details.csp_script_sources' => [
                'type'  => 'text',
                'display_type' => 'textarea',
                'label' => '#instance.field_csp_script_sources',
                'tooltip' => '#instance.tooltip_csp_script_sources',
                'max'   => 2000,
            ],
            // 'strict-dynamic': whatever a trusted script loads also runs
            'details.csp_strict_dynamic' => [
                'type'  => 'bit',
                'display_type' => 'switch',
                'label' => '#instance.field_csp_strict_dynamic',
                'tooltip' => '#instance.tooltip_csp_strict_dynamic',
            ],
            // form-action: forms only to the own website (+ sources)
            'details.csp_form_action' => [
                'type'  => 'bit',
                'display_type' => 'switch',
                'label' => '#instance.field_csp_form_action',
                'tooltip' => '#instance.tooltip_csp_form_action',
            ],
            'details.csp_form_sources' => [
                'type'  => 'text',
                'display_type' => 'textarea',
                'label' => '#instance.field_csp_form_sources',
                'tooltip' => '#instance.tooltip_csp_form_sources',
                'max'   => 2000,
            ],
        ];
    }

    /**
     * Before creating: put the new instance at the end of the sorting.
     * The position can be changed manually; without a preset every new instance
     * would stand at the front with position = 0.
     */
    protected function hookOnCreate(array &$data): void
    {
        $sql = "SELECT COALESCE(MAX(`position`), 0) + 1 FROM `instance`";
        $this->db->query($sql);
        $data['position'] = (int)($this->db->fetchNum()[0] ?? 0);

        // Field 'additional_domains': in the table `rel_instance_domain` each
        // row holds TWO values (domain + regex). The form therefore carries them as two
        // parallel arrays, not as one value in the field itself.
        $data['domain'] = [];
        $data['regex']  = [];
    }

    /**
     * Before saving: check and normalize.
     *
     * The contents of the three 'reltable' fields are brought here into the form that
     * hookOnSaved() expects - Dataset::save() only writes the real table columns between
     * both hooks.
     */
    protected function hookOnSave(array &$fields, array &$data, array &$previousData): void
    {
        $instanceId = (int)($data['instance_id'] ?? 0);

        // Languages
        // --------------------
        if (in_array('additional_languages', $fields, true)) {
            $primaryLanguageId = (int)($data['primary_language_id'] ?? 0);
            // "No main language" is not tied to 0 - decisive is
            // whether the language exists in `language` at all.
            $additionalLanguages = $this->system->sanitizeIds(
                (array)($data['additional_languages'] ?? [])
            );

            // Further languages without a valid main language yield no language sequence
            if (!empty($additionalLanguages)
                && empty($this->existingIds('language', [$primaryLanguageId]))
            ) {
                $errorMsg = $this->cms->snippet('instance.error_primary_language');
                $this->cms->error(
                    $errorMsg,
                    'primary_language_id',
                    $this->cms->textOrSnippet($this->schema['primary_language_id']['label'] ?? '')
                );
                throw new \Exception('#' . $errorMsg);
            }

            // The main language does not appear again in the further languages
            $additionalLanguages = array_values(
                array_diff($additionalLanguages, [$primaryLanguageId])
            );

            // Only languages that really exist (otherwise the foreign key would strike
            // only in hookOnSaved() - there without a usable message)
            $additionalLanguages = $this->existingIds('language', $additionalLanguages);

            $data['additional_languages'] = $additionalLanguages;
        }

        // Domains
        // --------------------
        // Build a cleaned list from the two parallel arrays of the form:
        // empty rows are dropped, domains are lowercased, duplicates
        // are removed (`rel_instance_domain` has the primary key instance_id+domain,
        // a duplicate row would make the INSERT fail).
        // Domains are not case-sensitive - store them in lowercase,
        // otherwise the same domain would appear in two spellings in the list
        $primaryDomain = mb_strtolower(trim((string)($data['primary_domain'] ?? '')));
        if (in_array('primary_domain', $fields, true)) {
            $data['primary_domain'] = $primaryDomain;
        }

        if (in_array('additional_domains', $fields, true)) {
            $domains = (array)($data['domain'] ?? []);
            $regex   = (array)($data['regex'] ?? []);

            $cleanDomains = [];
            $cleanRegex   = [];
            foreach ($domains as $key => $domain) {
                $domain = mb_strtolower(trim((string)$domain));
                if ($domain === '') continue;
                if (mb_strlen($domain) > 64) {
                    $this->createFieldError(
                        'error_max_le_length',
                        'additional_domains',
                        $this->schema['additional_domains']['label'] ?? '',
                        '64'
                    );
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
                // The main domain must not appear in the list again
                if ($domain === $primaryDomain) continue;
                if (in_array($domain, $cleanDomains, true)) continue;
                $cleanDomains[] = $domain;
                $cleanRegex[]   = !empty($regex[$key]) ? 1 : 0;
            }
            $data['domain'] = $cleanDomains;
            $data['regex']  = $cleanRegex;
            // The field value itself carries the domains - so 'max' and the
            // display on the info page take effect (Backend::backendValue treats 'reltable'
            // like 'json': list of entries).
            $data['additional_domains'] = $cleanDomains;
        }

        // Additional info
        // --------------------
        // Script policy: only known modes, sources only as host names
        // (SecurityHeaders::cleanSources()).
        if (in_array('details', $fields, true) || array_key_exists('details', $data)) {
            $details = is_array($data['details'] ?? null) ? $data['details'] : [];
            $csp = (string)($details['csp'] ?? '');
            if (!in_array($csp, SecurityHeaders::FRONTEND_MODES, true)) unset($details['csp']);
            foreach (['csp_script_sources', 'csp_form_sources'] as $key) {
                if (!array_key_exists($key, $details)) continue;
                $details[$key] = implode("\n", SecurityHeaders::cleanSources((string)$details[$key]));
                if ($details[$key] === '') unset($details[$key]);
            }
            foreach (['csp_strict_dynamic', 'csp_form_action'] as $key) {
                if (empty($details[$key])) unset($details[$key]);
                else $details[$key] = 1;
            }
            $data['details'] = $details;
        }

        // Readable instances
        // --------------------
        if (in_array('readable_instances', $fields, true)) {
            $readable = $this->system->sanitizeIds(
                (array)($data['readable_instances'] ?? [])
            );
            // Reading oneself makes no sense - and the primary key
            // instance_id+readable_id would allow it, but the evaluation in the frontend
            // would then run in circles.
            $readable = array_values(array_diff($readable, [$instanceId]));
            $readable = $this->existingIds('instance', $readable);
            $data['readable_instances'] = $readable;
        }
    }

    /**
     * After saving: rewrite the three relation tables.
     *
     * Runs inside the transaction of Dataset::save() - if one of the queries throws,
     * the instance row itself is rolled back too.
     *
     * Always DELETE first, then INSERT: the tables carry no own ID, a
     * row-by-row comparison would be more work than rewriting them.
     */
    protected function hookOnSaved(array &$fields, array &$data, array &$previousData): void
    {
        $instanceId = (int)($data['instance_id'] ?? 0);

        // 'additional_domains' -> `rel_instance_domain`
        // --------------------
        // Two values per row (domain + regex) and an own sort order.
        if (in_array('additional_domains', $fields, true)) {
            $this->db->query("DELETE FROM `rel_instance_domain` WHERE `instance_id` = ?", 'i', [$instanceId]);

            $domains = (array)($data['domain'] ?? []);
            $regex   = (array)($data['regex'] ?? []);
            $rows    = [];
            $types   = '';
            $values  = [];
            foreach (array_values($domains) as $i => $domain) {
                $rows[]   = '(?, ?, ?, ?)';
                $types   .= 'isii';
                $values[] = $instanceId;
                $values[] = $domain;
                $values[] = (int)($regex[$i] ?? 0);
                $values[] = $i;
            }
            if (!empty($rows)) {
                $sql = 'INSERT INTO `rel_instance_domain` (`instance_id`, `domain`, `regex`, `position`)'
                    . ' VALUES ' . implode(', ', $rows);
                $this->db->query($sql, $types, $values);
            }
        }

        // 'additional_languages' -> `rel_instance_language`
        // --------------------
        // `position` keeps the order in which the editor sorted the languages
        // (display_type 'sortable').
        if (in_array('additional_languages', $fields, true)) {
            $this->db->query("DELETE FROM `rel_instance_language` WHERE `instance_id` = ?", 'i', [$instanceId]);

            $languageIds = (array)($data['additional_languages'] ?? []);
            $rows   = [];
            $types  = '';
            $values = [];
            foreach (array_values($languageIds) as $i => $languageId) {
                $rows[]   = '(?, ?, ?)';
                $types   .= 'iii';
                $values[] = $instanceId;
                $values[] = (int)$languageId;
                $values[] = $i;
            }
            if (!empty($rows)) {
                $sql = 'INSERT INTO `rel_instance_language` (`instance_id`, `language_id`, `position`)'
                    . ' VALUES ' . implode(', ', $rows);
                $this->db->query($sql, $types, $values);
            }
        }

        // 'readable_instances' -> `rel_instance_instance`
        // --------------------
        // Without an own sort order: reading happens in the order of the instances
        // themselves (`instance`.`position`, see hookOnLoaded()).
        if (in_array('readable_instances', $fields, true)) {
            $this->db->query("DELETE FROM `rel_instance_instance` WHERE `instance_id` = ?", 'i', [$instanceId]);

            $readableIds = (array)($data['readable_instances'] ?? []);
            $rows   = [];
            $types  = '';
            $values = [];
            foreach ($readableIds as $readableId) {
                $rows[]   = '(?, ?)';
                $types   .= 'ii';
                $values[] = $instanceId;
                $values[] = (int)$readableId;
            }
            if (!empty($rows)) {
                $sql = 'INSERT INTO `rel_instance_instance` (`instance_id`, `readable_id`)'
                    . ' VALUES ' . implode(', ', $rows);
                $this->db->query($sql, $types, $values);
            }
        }

        // Language list changed? -> update the content rows
        // --------------------
        // Each record of the instance keeps one row per language and field (see
        // LanguageData). If a language is added, it gets the values of the
        // main language as fallback; if one is removed, its rows and
        // own files disappear (see LanguageData::removeLanguage()).
        // If the main language changes, the fallback values of the other
        // languages no longer match - the stock stays (it is correct again at the next
        // saving of the record); only `record_cache` is discarded.
        // The full text is folded according to the rules of the language: if a new
        // language folds differently than the main language, it is marked as stale.
        $refreshFulltext = false;
        if (!empty($previousData)
            && (in_array('additional_languages', $fields, true) || in_array('primary_language_id', $fields, true))
        ) {
            $oldPrimary = (int)($previousData['primary_language_id'] ?? 0);
            $newPrimary = (int)($data['primary_language_id'] ?? $oldPrimary);
            $oldLanguages = array_values(array_unique(array_merge(
                [$oldPrimary],
                array_map('intval', (array)($previousData['additional_languages'] ?? []))
            )));
            $newLanguages = array_values(array_unique(array_merge(
                [$newPrimary],
                array_map('intval', (array)($data['additional_languages'] ?? $previousData['additional_languages'] ?? []))
            )));

            $languageData = $this->languageData();
            foreach (array_diff($newLanguages, $oldLanguages) as $languageId) {
                $languageData->addLanguage($instanceId, $languageId, $oldPrimary);
                if (!$languageData->sameRules($languageId, $oldPrimary)) $refreshFulltext = true;
            }
            $removed = array_diff($oldLanguages, $newLanguages);
            foreach ($removed as $languageId) {
                $languageData->removeLanguage($instanceId, $languageId);
            }
            // if a removed language was the main language, file fallbacks point nowhere
            if (!empty($removed)) {
                $languageData->repairFileFallbacks($instanceId);
            }
            if ($newPrimary !== $oldPrimary) {
                $languageData->clearRecordCache($instanceId);
            }
        }

        // Renew cache files (var/cache/instances.php, instance-{id}.php)
        // --------------------
        // Still runs in the transaction: the queries of the rebuild see the
        // state just written. If the transaction rolls back afterwards, the
        // cache is wrong until the next change - in the backend this is visible
        // and resolves with the next saving.
        $this->instanceCache()->rebuild();

        // Re-fold the full text: only mark it - "Update now" or the scheduler
        // rebuilds it (FulltextState)
        if ($refreshFulltext) $this->languageData()->markFulltextStale($instanceId);

        // Rewrite the domain locks of the media directories (from the cache)
        // --------------------
        $this->mediaAccess()->rebuildAll();
    }

    /**
     * After final deletion: cache files, media locks and the
     * snippet set of the instance.
     * The file instance-{id}.php of the deleted instance is removed; its
     * media directory remains (the areas were cleared individually in hookOnDelete(),
     * their files are gone) and gets a full lock.
     */
    protected function hookOnDeleted(array $data): void
    {
        $this->instanceCache()->rebuild();
        $this->mediaAccess()->rebuildAll();
        // Snippet set of the instance (module Snippets, var/custom/snippets/{id})
        $this->container->get(Snippets::class)->delete((int)($data['instance_id'] ?? 0));
    }

    /**
     * After loading: supply the contents of the three relation tables.
     *
     * The 'reltable' fields are not in the result row - they have no
     * column. Without this hook they would arrive as NULL in the form.
     */
    protected function hookOnLoaded(array &$data): void
    {
        $instanceId = (int)($data['instance_id'] ?? 0);

        // `rel_instance_domain` -> 'additional_domains' + 'domain'/'regex'
        // --------------------
        // The form needs the two parallel arrays (one row per domain),
        // the field itself the pure domain list. Sort order preserved.
        $data['domain'] = [];
        $data['regex']  = [];
        $sql = "SELECT `domain`, `regex` FROM `rel_instance_domain`"
            . " WHERE `instance_id` = ? ORDER BY `position` ASC";
        $this->db->query($sql, 'i', [$instanceId]);
        while ($row = $this->db->fetch()) {
            $data['domain'][] = (string)$row['domain'];
            $data['regex'][]  = (int)$row['regex'];
        }
        $data['additional_domains'] = $data['domain'];

        // `rel_instance_language` -> 'additional_languages'
        // --------------------
        // Own sort order per instance (see hookOnSaved())
        $sql = "SELECT `language_id` FROM `rel_instance_language`"
            . " WHERE `instance_id` = ? ORDER BY `position` ASC";
        $this->db->query($sql, 'i', [$instanceId]);
        $data['additional_languages'] = array_map('intval', $this->db->fetchColumn());

        // `rel_instance_instance` -> 'readable_instances'
        // --------------------
        // Order of the instances themselves - the edge table keeps none of its own.
        $sql = "SELECT `r`.`readable_id` FROM `rel_instance_instance` `r`"
            . " LEFT JOIN `instance` `i` ON `i`.`instance_id` = `r`.`readable_id`"
            . " WHERE `r`.`instance_id` = ?"
            . " ORDER BY " . self::orderSql('i');
        $this->db->query($sql, 'i', [$instanceId]);
        $data['readable_instances'] = array_map('intval', $this->db->fetchColumn());
    }

    /**
     * Before deleting: remove all the contents of this website.
     *
     * WHAT HAPPENS HERE CANNOT BE UNDONE. `instance` has no `deleted` field, so
     * there is no trash for instances - Dataset::delete() deletes immediately and
     * permanently. The backend warns beforehand with the counted quantities
     * (InstanceBackend::_editDeleteForm()).
     *
     * WHY HERE AND NOT IN hookOnDeleted()
     * --------------------
     * As soon as the `instance` row is gone, the foreign keys clean up: `area` and
     * `record` hang on it with ON DELETE CASCADE, `field` and `template` in turn
     * on the area. The database then deletes only rows - the media files in the
     * file system would remain as orphans, and no hook of any module would run.
     * Therefore the content is cleaned up properly here, BEFORE the DELETE;
     * the foreign keys are then only the safety net.
     *
     * Deletion goes via the content areas: Area::hookOnDelete() takes the
     * records of the area individually (including media files), `field` and `template`
     * drop with the area by foreign key. Leaves first (cascadeIds with
     * 'purge'), so that no area vanishes via ON DELETE CASCADE over area.parent_id past
     * its own hook.
     *
     * Runs in the transaction of Dataset::purge(): if one step throws, everything
     * stays.
     */
    protected function hookOnDelete(array $data): void
    {
        $instanceId = (int)($data['instance_id'] ?? 0);

        // The default instance catches all calls that match no configured domain -
        // without it every such call would go nowhere. It stays.
        if ($instanceId === self::DEFAULT_ID) {
            $errorMsg = $this->cms->snippet('instance.error_default_instance');
            $this->cms->error($errorMsg);
            throw new \Exception('#' . $errorMsg);
        }

        $area = $this->areaDataset();
        foreach ($area->cascadeIds('instance_id', $instanceId, 'purge') as $areaId) {
            if (!$area->finalDelete($areaId)) {
                // Error message already in Cms::errors -> discard the transaction
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
        }

        // `rel_instance_domain`, `rel_instance_language`, `rel_instance_instance`
        // are cleaned up by the foreign keys (ON DELETE CASCADE on instance_id resp.
        // readable_id) - nothing to do here.
    }

    // Special functions for Instance
    // --------------------

    /**
     * What disappears with this instance?
     *
     * Basis of the warning in the delete form. Counted are the content areas
     * of the instance - exactly what hookOnDelete() clears away.
     *
     * @param int $instanceId
     * @return array  ['areas' => int, 'fields' => int, 'records' => int, 'templates' => int]
     */
    public function contentCount(int $instanceId): array
    {
        $count = ['areas' => 0, 'fields' => 0, 'records' => 0, 'templates' => 0];
        if ($instanceId < 1) return $count;

        $queries = [
            'areas'     => "SELECT COUNT(*) FROM `area` WHERE `instance_id` = ?",
            'fields'    => "SELECT COUNT(*) FROM `field` `f`"
                         . " INNER JOIN `area` `a` ON `a`.`area_id` = `f`.`area_id`"
                         . " WHERE `a`.`instance_id` = ?",
            'records'   => "SELECT COUNT(*) FROM `record` WHERE `instance_id` = ? AND `tmp` = 0",
            'templates' => "SELECT COUNT(*) FROM `template` `t`"
                         . " INNER JOIN `area` `a` ON `a`.`area_id` = `t`.`area_id`"
                         . " WHERE `a`.`instance_id` = ?",
        ];
        $this->db->pushResult();
        foreach ($queries as $key => $sql) {
            $this->db->query($sql, 'i', [$instanceId]);
            $count[$key] = (int)($this->db->fetchNum()[0] ?? 0);
        }
        $this->db->popResult();

        return $count;
    }

    /**
     * From a list of IDs, pick out the IDs that really exist in $tableName.
     *
     * The relation tables carry foreign keys; an ID from an outdated
     * form would make the INSERT in hookOnSaved() fail with a raw MySQL message.
     * It is dropped here beforehand.
     *
     * @param string $tableName  'language' or 'instance' - fixed in the code, not a request value
     * @param array  $ids        already passed through System::sanitizeIds()
     * @return array             the existing IDs, order as passed
     */
    private function existingIds(string $tableName, array $ids): array
    {
        if (empty($ids)) return [];
        $idField = $tableName . '_id';
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $sql = "SELECT `{$idField}` FROM `{$tableName}` WHERE `{$idField}` IN ($placeholders)";

        $this->db->pushResult();
        $this->db->query($sql, str_repeat('i', count($ids)), $ids);
        $found = array_map('intval', $this->db->fetchColumn());
        $this->db->popResult();

        // Order of the submission preserved (for the languages it is the sort order)
        return array_values(array_filter($ids, fn($id) => in_array((int)$id, $found, true)));
    }

    /**
     * Provide the area instance.
     *
     * Deliberately NOT injected via the constructor: dataset child classes inherit the
     * constructor of the base class and declare none of their own (see
     * Area::recordDataset(), same reasoning).
     */
    private function areaDataset(): Area
    {
        return $GLOBALS['app']->get(Area::class);
    }

    /**
     * File cache of the instances (see Camindo\Core\InstanceCache).
     */
    private function instanceCache(): InstanceCache
    {
        return $this->container->get(InstanceCache::class);
    }

    /**
     * Maintenance of the language-dependent content rows (see Camindo\Core\LanguageData).
     */
    private function languageData(): LanguageData
    {
        return $this->container->get(LanguageData::class);
    }

    /**
     * .htaccess files of the media directory (see Camindo\Core\MediaAccess).
     */
    private function mediaAccess(): MediaAccess
    {
        return $this->container->get(MediaAccess::class);
    }
}
