<?php
/**
 * Dataset subclass for the table `record` - the content records.
 *
 * The central class of the CMS and at the same time the shortest of the module datasets. This is
 * no accident: almost everything that makes up a record lives in the three traits
 * and in the base class. Here only what distinguishes `record` from all other tables
 * remains - and that is the media files.
 *
 * THE SCHEMA COMES FROM OUTSIDE
 * --------------------
 * $this->schema = $this->cms->schema. The meta fields of a record are defined in
 * Cms::$schema, not here:
 *
 *     record_id area_id                 belonging
 *     online_at offline_at status_id    visibility in the frontend
 *     parent_id parent_ids position
 *     depth lft rgt                     tree structure (two procedures in parallel)
 *     template_id                       display
 *     created_by changed_by
 *     created_at changed_at             origin and state of change
 *     needs_review                      review task (see UserBackend)
 *     tmp origin_id deleted             working copy and trash
 *
 * The reason for the separation: Cms::select() must know the meta fields in order to tell them apart
 * from the user-defined fields - and Cms can do without this
 * class, but not the other way round.
 *
 * EVERYTHING ELSE IS USER-DEFINED
 * --------------------
 * What a record otherwise contains is defined by the editor per content area
 * (table `field`). This content is stored type-separated in the `data_*` tables and
 * is read and written by VirtualFieldsTrait - for the caller they look
 * like normal fields.
 *
 * FOUR TRAITS
 * --------------------
 *     TreeManagerTrait     tree structure. Nested set (lft/rgt) and adjacency list
 *                          (parent_id/position/depth/parent_ids) are maintained in parallel:
 *                          the one for subtree queries in one go,
 *                          the other for cheap re-sorting.
 *     VirtualFieldsTrait   the user-defined fields from the data_* tables,
 *                          including file storage and thumbnail generation.
 *     TemporaryTrait       working copies. Whoever edits a record does not work
 *                          on the original but on a copy with tmp=1 and
 *                          origin_id; only saving (checkIn) transfers it.
 *                          This way inputs survive a failed check,
 *                          and the frontend never sees a half-finished state.
 *     MoveTrait            area change. A record is saved again via a working
 *                          copy with a mapping of its fields onto those of the target area
 *                          (moveToArea()).
 *     LinkTrait            firmly linked contents (fields of type 'link'): the
 *                          target records belong to the source record and are checked out,
 *                          checked in, discarded, deleted,
 *                          restored and copied together with it.
 *
 * THE HOOKS HERE TAKE CARE OF THE FILES
 * --------------------
 * Media files lie in the file system (Core/public_master/media/{field_id}/{record_id}.{ext})
 * and not in the database - a DELETE therefore does not remove them. That is why
 * three of the hooks are occupied, mirroring the three-stage deletion:
 *
 *     hookOnSoftDelete   move files to var/deleted  (trash)
 *     hookOnRestore      fetch files back
 *     hookOnDelete       remove files permanently   (only system tool/cron)
 *
 * In addition, each hook passes its event on to the trigger template of the area
 * (`rel_area_trigger`, see TriggerTemplates).
 *
 * The data_fil entries deliberately remain on soft delete: without them the field,
 * file extension and version could not be determined when restoring.
 *
 * @see Camindo\Core\Cms                            $schema, select(), fetch()
 * @see Camindo\Core\TreeManagerTrait               tree management
 * @see Camindo\Core\VirtualFieldsTrait             data_* fields, files, thumbnails
 * @see Camindo\Core\TemporaryTrait                 working copies (checkOut / checkIn)
 * @see Camindo\Core\Modules\Record\RecordBackend   backend user interface
 * @see Camindo\Core\Modules\Area\Area              content area with the field definition
 * @see developer docs, chapter 02 (Data model)
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\Dataset;
use Camindo\Core\DirectoryHelpers;
use Camindo\Core\InstanceCache;
use Camindo\Core\TreeManagerTrait;
use Camindo\Core\VirtualFieldsTrait;
use Camindo\Core\TemporaryTrait;
use Camindo\Core\MoveTrait;
use Camindo\Core\LinkTrait;
use Camindo\Core\TriggerTemplates;

class Record extends Dataset {

    use TreeManagerTrait, VirtualFieldsTrait, MoveTrait, LinkTrait;
    use TemporaryTrait {
        cancel as protected tmpCancel;
    }

    /**
     * Table definition.
     *
     * The schema is not written here but taken over from Cms (see class
     * comment).
     *
     * Default sorting 'changed_at DESC' - most recently edited first; this is the
     * order in which an editor searches for their records. For the display in the
     * area, the sorting set there usually applies instead
     * (area.sort_field_id / sort_order).
     *
     * $hasTmp activates the working copies: Dataset then treats records with
     * tmp=1 as such and resolves them to their original when loading.
     */
    protected function hookDefinition(): void
    {
        $this->tableName = 'record';
        $this->moduleName = 'Record';
        $this->defaultSortOrder = 'changed_at DESC';
        $this->schema = $this->cms->schema;
        $this->hasTmp = true; // field "tmp" requires the fields "origin_id", "deleted", "changed_by", "changed_at"
    }

    /**
     * Before creating: ensure belonging.
     *
     * A record without a content area makes no sense - only the area says
     * which fields it has. That is why area_id is mandatory here, and init() cannot
     * be called for `record` without a default.
     *
     * The actual preset of the user-defined fields is then taken care of by
     * VirtualFieldsTrait from the defaults of the field definitions.
     */
    protected function hookOnCreate(array &$data): void
    {
        // create default values
        $areaId = (int) $data['area_id'];
        if (empty($areaId)) {
            throw new \Exception('Error: Record::init needs parameter data[area_id]!');
        }
        $this->trigger($areaId, 'onCreate', $data);
    }

    // hookOnLoaded() is deliberately empty: loading and saving the user-defined
    // fields is done by VirtualFieldsTrait, switching between working copy and
    // original by TemporaryTrait - both within Dataset, without anything to
    // add here. hookOnSave() and hookOnSaved() each have one task, see there.

    /**
     * After loading...
     */
    protected function hookOnLoaded(array &$data): void
    {
        $this->trigger((int)($data['area_id'] ?? 0), 'onLoaded', $data);
    }

    /**
     * Before saving: fill an empty slug, defuse pure numbers.
     *
     * 'slug' is an optional virtual field - it only exists if the content area has
     * created it. The reason for a virtual rather than a meta field: virtual fields can later be
     * maintained multilingually, the columns of `record` cannot.
     *
     * If the editor leaves the field empty, the slug is created from the default field of the
     * area (the "title", area.default_field_id) via Cms::textToUri(): only
     * word characters and slash remain, shortened to system.link_length.
     *
     * If the area has no default field or its content is empty, the slug
     * remains empty. This is not a defect: CMS:URL then falls back to the record_id
     * ("/42"), and Page::resolveRequest() resolves a purely numeric value
     * directly via the id in any case.
     *
     * NO PURE NUMBERS
     * --------------------
     * That is exactly why a slug must never be a pure sequence of digits: otherwise "/42" would be
     * ambiguous - once the record_id 42, once the record with the slug "42".
     * Such a slug therefore gets a minus sign prepended: from the title "2024"
     * "-2024" is made. This applies to the generated slug as well as to one entered by hand.
     *
     * A slug entered by hand is normalized in exactly the same way as the generated one
     * (Cms::textToUri(): lowercase, special characters to "-", shortened). If
     * nothing remains of it, it is treated like an empty field.
     *
     * The check is for pure digits, not with is_numeric() - that would also consider the
     * result "-2024" to be a number and the fallback rule would fail.
     * Page::resolveRequest() distinguishes id and slug by the same rule.
     *
     * Multilingual slug field: the primary language as above; a further language gets
     * its own slug only from an own translation of the title in that language,
     * otherwise its row carries the slug of the primary language as fallback.
     *
     * A slug once set is NOT updated when the title changes later -
     * a moving slug would invalidate every link already shared. Anyone who wants to change it
     * empties the field and lets it be filled again.
     */
    protected function hookOnSave(array &$fields, array &$data, array &$previousData): void
    {
        // trigger template first: what it sets in $data runs through everything else
        // (slug, check, writing) like an input
        $this->trigger((int)($data['area_id'] ?? $previousData['area_id'] ?? 0), 'onSave', $fields, $data, $previousData);

        // sign-in name of a profile: user_* is reserved for anonymized profiles
        // (module Privacy, deletion policy) - only user_{record_id} of this
        // record itself is allowed, i.e. not at registration
        $this->checkReservedLoginName($fields, $data, $previousData);

        // instance_id
        // --------------------
        // Not an input field: the record belongs to the website to which its
        // content area belongs. The value is fetched from there on EVERY save -
        // so a record that changes area moves along with it.
        // (Area::hookOnSaved() covers the reverse case: the area itself
        //  changes the instance, its records remain.)
        $areaId = (int)($data['area_id'] ?? $previousData['area_id'] ?? 0);
        if ($areaId > 0) {
            $data['instance_id'] = $this->areaInstanceId($areaId);
            if (!in_array('instance_id', $fields, true)) $fields[] = 'instance_id';
        }

        // owner_id
        // --------------------
        // Change only with the additional right "adjust owner" of the user group in the
        // area (lookup field at the end of the mask, RecordBackendEdit). Without the
        // right the saved owner remains - when creating, Dataset::save() sets
        // the session admin (developer docs, chapter 11 (Permissions)).
        if (in_array('owner_id', $fields, true) && $areaId > 0) {
            $access = $this->container->get(\Camindo\Core\Access::class);
            // (an emptied lookup field does not take the owner from the record; a non-superuser
            //  may only make admins of their own group the owner)
            $newOwnerId = (int)($data['owner_id'] ?? 0);
            $keep = $newOwnerId < 1;
            if (!$keep && $access->isLoaded() && !$access->isSuperuser()) {
                $keep = !$access->areaSetOwner($areaId) || $access->groupOf($newOwnerId) !== $access->groupId();
            }
            if ($keep) {
                if (!empty($previousData) && array_key_exists('owner_id', $previousData)) {
                    $data['owner_id'] = (int)$previousData['owner_id'];
                }
                else {
                    unset($data['owner_id']);
                }
            }
        }

        // area change (MoveTrait::moveToArea())
        // --------------------
        // The contents in the fields of the previous area must go BEFORE
        // saveVirtualFields() writes the fields of the new one - see
        // MoveTrait::removeAreaData(). The files follow in hookOnSaved().
        // Only on the final save: a single step (tab switch in the input mask
        // of a moved working copy, simulation) only writes the working state of the
        // copy - the original remains until check-in, as it is, and can
        // remain unchanged by cancelling.
        $recordId  = (int)($data['record_id'] ?? 0);
        $oldAreaId = (int)($previousData['area_id'] ?? 0);
        if (!$this->savingTemporary && $recordId > 0 && $oldAreaId > 0 && $areaId > 0 && $oldAreaId !== $areaId) {
            $this->removeAreaData($recordId, $oldAreaId);
        }

        // slug
        // --------------------
        // field not involved at all? (area without slug field, or not transmitted)
        if (!in_array('slug', $fields, true)) return;

        // multilingual slug field: one slug per language (array language_id => value,
        // see Dataset::validateFieldValue()). The rules apply per language; the
        // source is the default field in the same language if it is multilingual,
        // otherwise its only value. Page::resolveRequest() resolves a slug via
        // Cms::select() in the requested language - so each language finds
        // its own.
        $areaId = (int)($data['area_id'] ?? $previousData['area_id'] ?? 0);
        $isMultilingual = is_array($data['slug'] ?? null);

        // find the default field of the area (area.default_field_id is mirrored in
        // field.default_field, see Area::hookOnSaved())
        $sourceName = '';
        if ($areaId > 0) {
            foreach ($this->virtualSchema($areaId, false) as $name => $colDef) {
                if ((int)($colDef['default_field'] ?? 0) !== 1) continue;
                $sourceName = $name;
                break;
            }
        }
        $langCtx = $isMultilingual ? $this->languageContext($areaId) : [];
        $primaryId = (int)($langCtx['primary_id'] ?? 0);

        $slugs = $isMultilingual ? $data['slug'] : [null => $data['slug'] ?? ''];
        foreach ($slugs as $languageId => $slug) {
            // A slug entered by hand goes through the same normalization as the one
            // generated from the default field; if nothing remains of it ("###"),
            // it counts as empty
            $slug = trim((string)$slug);
            if ($slug !== '') $slug = $this->cms->textToUri($slug);

            if ($slug === '') {
                $source = ($sourceName !== '') ? ($data[$sourceName] ?? '') : '';
                if ($isMultilingual && (int)$languageId !== $primaryId) {
                    // A further language gets its own slug only from an own translation
                    // of the title in the same language - otherwise it remains empty and
                    // the fallback from the primary language applies (saveVirtualFields())
                    $source = is_array($source) ? ($source[$languageId] ?? '') : '';
                    if (is_array($source) || trim((string)$source) === '') continue;
                }
                elseif (is_array($source)) {
                    // multilingual default field: value of the same language, otherwise primary language
                    $source = $source[$languageId] ?? $source[$primaryId] ?? (reset($source) ?: '');
                    if (is_array($source)) $source = '';
                }
                $source = trim((string)$source);

                // no default field, empty title or a title without any usable
                // characters ("###") -> slug remains empty, the URL runs via the id
                if ($source === '') continue;
                $slug = $this->cms->textToUri($source);
                if ($slug === '') continue;
            }

            // pure digit sequence -> prepend a minus sign, see comment above
            if (preg_match('/^\d+$/', $slug)) $slug = '-' . $slug;

            if ($isMultilingual) $data['slug'][$languageId] = $slug;
            else $data['slug'] = $slug;
        }
    }

    /**
     * Uniqueness of a slug (flag 'unique', which Field::hookOnSave() always gives the field
     * `slug`) - called from Dataset::validateValue().
     *
     * What must be unique is not the value but the URL path: slug prefix of the
     * area + slug (Cms::recordUrl(), Cms::recordIdsBySlugPath()). Within the
     * instance and the instances linked with it (InstanceCache::
     * uniqueScope()) the following applies per language:
     *
     *   1. If the area has no prefix in this language, the first path part of the
     *      slug must not be the prefix of another area ("farben/gelb"
     *      in an area without a prefix, while the area "Farben" has the prefix "farben") -
     *      otherwise the call /farben/gelb would land in the area Farben.
     *   2. No other record of an area with the same prefix (or likewise without one)
     *      carries the same slug. Identical slugs in areas with different
     *      prefixes are allowed: /farben/rot and /rot.
     *
     * Languages: for a multilingual field those of the value, otherwise all languages of the
     * instance (the prefix can differ per language). An empty slug is never
     * a conflict - the URL then runs via the record_id.
     *
     * @param int $excludeId  the record itself (productive); a working copy
     *                        drops out via tmp = 0 anyway
     * @return bool  false: message is in Cms::errors (under $errName)
     */
    protected function slugPathOk(string $slug, array $colDef, string $errName, string $label, int $excludeId, int $areaId, ?int $languageId, int $instanceId): bool
    {
        $slug = trim($slug);
        if ($slug === '' || $areaId < 1) return true;
        $cache = $this->container->get(InstanceCache::class);
        if ($instanceId < 1) $instanceId = $cache->areaInstanceId($areaId);
        $scope = $cache->uniqueScope($instanceId) ?: [$instanceId];
        $languageIds = ($languageId !== null) ? [$languageId] : $cache->languageIds($instanceId);
        $lower = fn(string $text): string => mb_strtolower($text, 'UTF-8');

        // 1. reserved prefix
        // --------------------
        $first = $lower(explode('/', $slug, 2)[0]);
        $prefixAreas = $cache->slugPrefixAreas($scope);
        foreach ($languageIds as $lang) {
            if ($cache->slugPrefix($areaId, (int)$lang) !== '') continue;
            foreach (array_keys($prefixAreas) as $otherId) {
                $prefix = $cache->slugPrefix($otherId, (int)$lang);
                if ($prefix === '' || $lower($prefix) !== $first) continue;
                $this->cms->error(strtr($this->cms->snippet('record.error_slug_prefix_reserved'), [
                    '{prefix}' => htmlspecialchars($prefix . '/'),
                    '{area}'   => htmlspecialchars($this->areaLabel($otherId)),
                ]), $errName, $this->cms->textOrSnippet($label));
                return false;
            }
        }

        // 2. same path
        // --------------------
        $vf = $this->cms->resolveVirtualField('slug');
        $suffix = $this->cms->dataSuffix[(string)($colDef['type'] ?? 'chars')] ?? '';
        if ($vf === null || $suffix === '') return true;
        $sql = "SELECT `d`.`language_id`, `r`.`area_id` FROM `data_{$suffix}` `d`"
             . " INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`record_id` AND `r`.`tmp` = 0 AND `r`.`deleted` = 0"
             . " WHERE `d`.`fieldname_id` = ? AND `d`.`data` = ? AND `d`.`record_id` != ?"
             . " AND `r`.`instance_id` IN (" . implode(',', array_map('intval', $scope)) . ")";
        $types = 'isi';
        $values = [(int)$vf['fieldname_id'], $slug, $excludeId];
        if ($languageId !== null) {
            $sql .= " AND `d`.`language_id` = ?";
            $types .= 'i';
            $values[] = $languageId;
        }
        $this->db->pushResult();
        $this->db->query($sql . " LIMIT 200", $types, $values);
        $rows = $this->db->fetchAll();
        $this->db->popResult();
        foreach ($rows as $row) {
            $lang = (int)$row['language_id'];
            if ($lower($cache->slugPrefix((int)$row['area_id'], $lang)) !== $lower($cache->slugPrefix($areaId, $lang))) continue;
            $this->createFieldError('error_unique_value', $errName, $label);
            return false;
        }
        return true;
    }

    /**
     * New slug field (Field::hookOnSaved()): give every record of the area whose
     * slug is still empty a slug from the default field of the area - the same rule
     * as hookOnSave() for a single record (Cms::textToUri(), no pure digits).
     *
     * Languages: a multilingual slug field gets one slug per language from the
     * title in the same language. First the primary language (its slug also goes
     * into the fallback rows, `empty` = 1, of the further languages), then every
     * further language in which the title has its own translation (`empty` = 0) -
     * without one, the further language keeps the fallback. A single-language slug
     * field gets the slug of the title in the primary language.
     *
     * Each slug is checked with slugPathOk() before it is written. The records are
     * processed in the order of their record_id - the older record keeps the slug,
     * a later one with the same path stays empty (resp. keeps the fallback) and
     * lands in the returned list. Not touched: records in the trash, working copies
     * (their working state is in `record_cache`), records without usable title.
     * An encrypted default field is not used as source (the slug would make its
     * content public).
     *
     * @param int $areaId       area of the field
     * @param int $slugFieldId  field_id of the slug field
     * @return array  ['written' => int, 'source' => bool (default field present),
     *                 'conflicts' => [['record_id', 'language_id', 'title', 'slug', 'message'], …]]
     */
    public function fillSlugs(int $areaId, int $slugFieldId): array
    {
        $result = ['written' => 0, 'source' => false, 'conflicts' => []];
        if ($areaId < 1 || $slugFieldId < 1) return $result;
        $this->cms->clearFieldCaches();

        // slug field and default field of the area
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT `f`.`field_id`, `f`.`type`, `f`.`multilingual`, `f`.`label`, `f`.`deleted`, `f`.`area_id`"
                . " FROM `field` `f` WHERE `f`.`field_id` = ?"
                . " UNION ALL SELECT `f`.`field_id`, `f`.`type`, `f`.`multilingual`, `f`.`label`, `f`.`deleted`, `f`.`area_id`"
                . " FROM `area` `a` JOIN `field` `f` ON `f`.`field_id` = `a`.`default_field_id` WHERE `a`.`area_id` = ?",
                'ii', [$slugFieldId, $areaId]
            );
            $rows = $this->db->fetchAll();
        }
        finally {
            $this->db->popResult();
        }
        $slugField = null;
        $sourceField = null;
        foreach ($rows as $row) {
            if ((int)$row['field_id'] === $slugFieldId && $slugField === null) $slugField = $row;
            else $sourceField = $row;
        }
        $slugType = (string)($slugField['type'] ?? '');
        $slugSuffix = $this->cms->dataSuffix[$slugType] ?? '';
        if ($slugField === null || !in_array($slugType, ['chars', 'text'], true) || $slugSuffix === '') return $result;
        $sourceType = (string)($sourceField['type'] ?? '');
        $sourceSuffix = $this->cms->dataSuffix[$sourceType] ?? '';
        if ($sourceField === null || (int)$sourceField['deleted'] === 1 || (int)$sourceField['area_id'] !== $areaId
            || !in_array($sourceType, ['chars', 'text'], true) || $sourceSuffix === '') {
            return $result;
        }
        $result['source'] = true;

        $langCtx = $this->languageContext($areaId);
        $primaryId = (int)($langCtx['primary_id'] ?? 0);
        $instanceId = (int)($langCtx['instance_id'] ?? 0);
        $isMultilingual = !empty($slugField['multilingual']) && $this->cms->hasEmptyFlag($slugType);
        $sourceMultilingual = !empty($sourceField['multilingual']) && $this->cms->hasEmptyFlag($sourceType);
        $label = (string)$slugField['label'];

        // slug rows still to fill and the title in the same language:
        // primary language: slug ''; further languages (multilingual slug only):
        // slug is a fallback (`empty` = 1) and the title has an own translation
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT `r`.`record_id`, `s`.`language_id`, `t`.`data` FROM `record` `r`"
                . " JOIN `data_{$slugSuffix}` `s` ON `s`.`record_id` = `r`.`record_id` AND `s`.`field_id` = ?"
                . " JOIN `data_{$sourceSuffix}` `t` ON `t`.`record_id` = `r`.`record_id` AND `t`.`field_id` = ?"
                . " AND `t`.`language_id` = `s`.`language_id`"
                . " WHERE `r`.`area_id` = ? AND `r`.`tmp` = 0 AND `r`.`deleted` = 0"
                . " AND ((`s`.`language_id` = ? AND `s`.`data` = '')"
                . ($isMultilingual && $sourceMultilingual ? " OR (`s`.`language_id` <> ? AND `s`.`empty` = 1 AND `t`.`empty` = 0)" : '')
                . ") ORDER BY (`s`.`language_id` <> ?) ASC, `r`.`record_id` ASC, `s`.`language_id` ASC",
                $isMultilingual && $sourceMultilingual ? 'iiiiii' : 'iiiii',
                $isMultilingual && $sourceMultilingual
                    ? [$slugFieldId, (int)$sourceField['field_id'], $areaId, $primaryId, $primaryId, $primaryId]
                    : [$slugFieldId, (int)$sourceField['field_id'], $areaId, $primaryId, $primaryId]
            );
            $rows = $this->db->fetchAll();
        }
        finally {
            $this->db->popResult();
        }

        $colDef = ['type' => $slugType];
        $savedErrors = [$this->cms->errors, $this->cms->hasErrors];
        try {
            foreach ($rows as $row) {
                $recordId = (int)$row['record_id'];
                $languageId = (int)$row['language_id'];
                $isPrimary = ($languageId === $primaryId);
                $title = trim((string)$row['data']);
                if ($title === '') continue;
                $slug = $this->cms->textToUri($title);
                if ($slug === '') continue;
                if (preg_match('/^\d+$/', $slug)) $slug = '-' . $slug;

                // the primary slug also lands in the fallback rows - checked in all languages
                $checkLanguage = ($isMultilingual && !$isPrimary) ? $languageId : null;
                $this->cms->errors = [];
                if (!$this->slugPathOk($slug, $colDef, 'slug', $label, $recordId, $areaId, $checkLanguage, $instanceId)) {
                    $error = end($this->cms->errors);
                    $result['conflicts'][] = [
                        'record_id'   => $recordId,
                        'language_id' => $isMultilingual ? $languageId : 0,
                        'title'       => $title,
                        'slug'        => $slug,
                        'message'     => (string)($error['text'] ?? ''),
                    ];
                    continue;
                }
                if (!$isMultilingual) {
                    $this->db->query(
                        "UPDATE `data_{$slugSuffix}` SET `data` = ? WHERE `record_id` = ? AND `field_id` = ? AND `data` = ''",
                        'sii', [$slug, $recordId, $slugFieldId]
                    );
                }
                elseif ($isPrimary) {
                    $this->db->query(
                        "UPDATE `data_{$slugSuffix}` SET `data` = ? WHERE `record_id` = ? AND `field_id` = ?"
                        . " AND (`language_id` = ? OR `empty` = 1)",
                        'siii', [$slug, $recordId, $slugFieldId, $primaryId]
                    );
                }
                else {
                    $this->db->query(
                        "UPDATE `data_{$slugSuffix}` SET `data` = ?, `empty` = 0 WHERE `record_id` = ? AND `field_id` = ? AND `language_id` = ?",
                        'siii', [$slug, $recordId, $slugFieldId, $languageId]
                    );
                }
                $result['written']++;
            }
        }
        finally {
            [$this->cms->errors, $this->cms->hasErrors] = $savedErrors;
        }

        if ($result['written'] > 0) {
            $this->db->pushResult();
            try {
                $this->container->get(\Camindo\Core\RecordCache::class)->clear(0, [$areaId]);
            }
            finally {
                $this->db->popResult();
            }
        }
        return $result;
    }

    /**
     * Records that a restore() of $recordId brings back: the record and the
     * descendants of the same deletion operation (same changed_at, see
     * Dataset::restore()). Without side effects - unlike collectIds(), which moves
     * children up for $withChilds = false.
     *
     * @return int[]  record_ids, parents first; [] if the record is not in the trash
     */
    public function restoreIds(int $recordId): array
    {
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT `record_id`, `area_id`, `lft`, `rgt`, `deleted`, `tmp` FROM `record` WHERE `record_id` = ?",
                'i', [$recordId]
            );
            $data = $this->db->fetch();
        }
        finally {
            $this->db->popResult();
        }
        if (!$data || empty($data['deleted']) || !empty($data['tmp'])) return [];
        if (!$this->isTreeArea((int)$data['area_id'])) return [$recordId];
        $previous = $this->restoreTimestamp;
        $this->restoreTimestamp = $this->rawChangedAt($recordId);
        try {
            return $this->subtreeIds($data, 'restore') ?: [$recordId];
        }
        finally {
            $this->restoreTimestamp = $previous;
        }
    }

    /**
     * Conflicts that restoring the records $recordIds (from the trash) would cause:
     * values of fields with the flag 'unique' / 'unique_in_area' and the slug
     * (URL path, slugPathOk()) that a live record has taken in the meantime - the
     * uniqueness check of save() only sees live records, so a value of a record in
     * the trash is free for others. Also checked: duplicates among the records of
     * the same restore operation itself.
     *
     * Per value as when saving: multilingual fields per language (own values only,
     * fallbacks do not count - except for the slug, whose fallback row is the
     * address of the language), single-language fields once. Encrypted
     * fields are compared via their plain text (uniqueVirtualOk() hashes it).
     * Cms::errors stays untouched.
     *
     * @param int[] $recordIds  records in the trash (restoreIds(), Area::hookOnRestore())
     * @return array  [['record_id', 'area_id', 'title', 'name', 'error_name',
     *                  'label', 'error_label', 'language_id', 'value', 'message'], …]
     */
    public function restoreConflicts(array $recordIds): array
    {
        $conflicts = [];
        $seen = [];   // values of the operation itself: key => record_id
        $lower = fn(string $text): string => mb_strtolower($text, 'UTF-8');
        $cache = $this->container->get(InstanceCache::class);
        $savedErrors = [$this->cms->errors, $this->cms->hasErrors];
        try {
            foreach ($recordIds as $recordId) {
                $recordId = (int)$recordId;
                $this->db->pushResult();
                try {
                    $this->db->query("SELECT `area_id`, `instance_id` FROM `record` WHERE `record_id` = ?", 'i', [$recordId]);
                    $row = $this->db->fetch();
                }
                finally {
                    $this->db->popResult();
                }
                if (!$row) continue;
                $areaId = (int)$row['area_id'];
                $instanceId = (int)$row['instance_id'] ?: $cache->areaInstanceId($areaId);
                $langCtx = $this->languageContext($areaId);
                $primaryId = (int)($langCtx['primary_id'] ?? 0);
                $title = null;

                foreach ($this->virtualSchema($areaId, false) as $name => $colDef) {
                    $flags = (array)($colDef['flags'] ?? []);
                    $isSlug = ($name === 'slug');
                    $inArea = in_array('unique_in_area', $flags, true) && !in_array('unique', $flags, true);
                    if (!$isSlug && !in_array('unique', $flags, true) && !$inArea) continue;
                    $type = (string)($colDef['type'] ?? '');
                    $suffix = $this->cms->dataSuffix[$type] ?? '';
                    $fieldId = (int)($colDef['field_id'] ?? 0);
                    if ($suffix === '' || $fieldId < 1 || in_array($type, $this->cms->relationTypes, true)
                        || in_array($type, ['file', 'password', 'fulltext'], true)) continue;
                    $isMultilingual = !empty($colDef['multilingual']) && $this->cms->hasEmptyFlag($type);

                    $this->db->pushResult();
                    try {
                        $this->db->query(
                            "SELECT `language_id`, `data`" . ($this->cms->hasEmptyFlag($type) ? ', `empty`' : ', 0 AS `empty`')
                            . " FROM `data_{$suffix}` WHERE `record_id` = ? AND `field_id` = ?"
                            . " ORDER BY (`language_id` <> ?) ASC, `language_id` ASC",
                            'iii', [$recordId, $fieldId, $primaryId]
                        );
                        $rows = $this->db->fetchAll();
                    }
                    finally {
                        $this->db->popResult();
                    }
                    foreach ($rows as $valueRow) {
                        $languageId = (int)$valueRow['language_id'];
                        // fallback rows do not count - except for the slug: the address
                        // /{lang}/{slug} of a further language resolves via its row
                        if ($isMultilingual && !$isSlug && !empty($valueRow['empty'])) continue;
                        $value = (string)($valueRow['data'] ?? '');
                        if ($type === 'encrypted') $value = $this->crypt->decrypt($value, '', true);
                        if (trim($value) === '') continue;
                        // language of the check: per language for multilingual fields
                        $checkLanguage = $isMultilingual ? $languageId : null;
                        $errorName = $isMultilingual ? $name . '.' . $languageId : $name;
                        $label = (string)($colDef['label'] ?? $name);

                        $this->cms->errors = [];
                        $key = $name . '|' . ($checkLanguage ?? '*') . '|' . ($inArea ? $areaId : 'i' . $instanceId)
                            . ($isSlug ? '|' . $lower($cache->slugPrefix($areaId, $checkLanguage ?? $primaryId)) : '')
                            . '|' . $lower($value);
                        if (isset($seen[$key])) {
                            $this->createFieldError('error_unique_value', $errorName, $label);
                            $ok = false;
                        }
                        elseif ($isSlug) {
                            $ok = $this->slugPathOk($value, $colDef, $errorName, $label, $recordId, $areaId, $checkLanguage, $instanceId);
                        }
                        else {
                            $ok = $this->uniqueVirtualOk($name, $colDef, $type, $value, $inArea, $recordId, $areaId, $checkLanguage, $instanceId);
                            if (!$ok) $this->createFieldError('error_unique_value', $errorName, $label);
                        }
                        $seen[$key] = $recordId;
                        if (!$ok) {
                            $error = end($this->cms->errors);
                            $title ??= $this->restoreTitle($recordId, $areaId);
                            $conflicts[] = [
                                'record_id'   => $recordId,
                                'area_id'     => $areaId,
                                'title'       => $title,
                                'name'        => $name,
                                'error_name'  => $errorName,
                                'label'       => $this->cms->textOrSnippet($label),
                                // as Dataset::validateFieldValue() names it ("Slug | de_DE")
                                'error_label' => $this->cms->textOrSnippet($label) . ($isMultilingual
                                    ? ' | ' . (string)($langCtx['codes'][$languageId] ?? $languageId) : ''),
                                'language_id' => $isMultilingual ? $languageId : 0,
                                'value'       => $type === 'encrypted' ? '' : $value,
                                'message'     => (string)($error['text'] ?? ''),
                            ];
                        }
                        // single-language field: the same value in every language row
                        if (!$isMultilingual) break;
                    }
                }
            }
        }
        finally {
            [$this->cms->errors, $this->cms->hasErrors] = $savedErrors;
        }
        return $conflicts;
    }

    /**
     * Title of a record for messages: the default field in the primary language
     * (plain text, without encrypted fields), otherwise ''.
     */
    private function restoreTitle(int $recordId, int $areaId): string
    {
        foreach ($this->virtualSchema($areaId, false) as $colDef) {
            if ((int)($colDef['default_field'] ?? 0) !== 1) continue;
            $type = (string)($colDef['type'] ?? '');
            if (!in_array($type, ['chars', 'text'], true)) return '';
            $suffix = $this->cms->dataSuffix[$type];
            $primaryId = (int)($this->languageContext($areaId)['primary_id'] ?? 0);
            $this->db->pushResult();
            try {
                $this->db->query(
                    "SELECT `data` FROM `data_{$suffix}` WHERE `record_id` = ? AND `field_id` = ?"
                    . " ORDER BY (`language_id` <> ?) ASC LIMIT 1",
                    'iii', [$recordId, (int)$colDef['field_id'], $primaryId]
                );
                $row = $this->db->fetchNum();
            }
            finally {
                $this->db->popResult();
            }
            return trim(strip_tags((string)($row[0] ?? '')));
        }
        return '';
    }

    /**
     * Restore a single record in the trash together with the inputs of its working
     * copy - the save of the input mask that RecordBackendEdit opens instead of the
     * restore when restoreConflicts() finds something (module Trash). In ONE
     * transaction: Record::save() of the working copy (validation like every save,
     * also the uniqueness - the record itself is excluded), then restore() of the
     * original - with changed_at set back to its deletion operation beforehand (save()
     * stamps it, restore() finds the record and its firmly linked contents by it).
     * If saving fails, nothing is restored and no file is moved.
     *
     * @param int   $tmpId   working copy of the record in the trash (checkOutDeleted())
     * @param array $fields  as save()
     * @param array $data    as save()
     * @return int|null      record_id, null on errors (Cms::errors)
     */
    public function saveAndRestore(int $tmpId, array $fields, array &$data): ?int
    {
        $prodData = $this->getProdData($tmpId);
        $originId = (int)($prodData[0] ?? 0);
        if ($originId < 1) {
            $this->cms->error($this->cms->snippet('error_tmp_record_access_denied'));
            return null;
        }
        $timestamp = $this->rawChangedAt($originId);
        $this->db->beginTransaction();
        try {
            $newId = $this->save($fields, $data);
            if (!$newId) throw new \Exception('#' . $this->cms->errorMsgLast());
            // save() has stamped the original - back to the time of its deletion
            // operation, by which restore() finds it and its firmly linked contents
            $this->db->query("UPDATE `record` SET `changed_at` = ? WHERE `record_id` = ?", 'si', [$timestamp, $originId]);
            if (!$this->restore($originId, true)) throw new \Exception('#' . $this->cms->errorMsgLast());
            $this->db->commit();
            return (int)$newId;
        }
        catch (\Exception $e) {
            $msg = $e->getMessage();
            if (!str_starts_with($msg, '#')) $this->cms->error($msg);
            $this->db->rollback();
            return null;
        }
    }

    /**
     * Label of an area for messages: "Label (name)".
     */
    private function areaLabel(int $areaId): string
    {
        $this->db->pushResult();
        $this->db->query("SELECT `label`, `name` FROM `area` WHERE `area_id` = ?", 'i', [$areaId]);
        $row = $this->db->fetch();
        $this->db->popResult();
        if (!$row) return (string)$areaId;
        return $this->cms->_strip_tags($this->cms->textOrSnippet((string)$row['label'])) . ' (' . $row['name'] . ')';
    }

    /**
     * After saving: area change - delete the media files of the old fields
     * (counterpart to hookOnSave(), see MoveTrait::removeAreaFiles()).
     */
    protected function hookOnSaved(array &$fields, array &$data, array &$previousData): void
    {
        $this->removeAreaFiles();
        $this->trigger((int)($data['area_id'] ?? $previousData['area_id'] ?? 0), 'onSaved', $fields, $data, $previousData);
    }

    /**
     * Pass the event on to the trigger templates of the area
     * (`rel_area_trigger`, see TriggerTemplates).
     */
    private function trigger(int $areaId, string $event, mixed &...$args): void
    {
        $this->container->get(TriggerTemplates::class)->run($this, $areaId, $event, $this->savingTemporary, ...$args);
    }

    // ====================
    // Rights of the user group (developer docs, chapter 11 (Permissions))

    /**
     * Restrict the language list to the readable languages of the user group
     * (tabs of the detail view and input mask). Unchanged without loaded rights /
     * superuser; order remains that of the instance.
     *
     * @param array $languages  language_id => language row (InstanceCache::languages())
     */
    public function readableLanguages(array $languages): array
    {
        $access = $this->container->get(\Camindo\Core\Access::class);
        if (!$access->isLoaded() || $access->isSuperuser()) return $languages;
        return array_filter($languages, fn($languageId) => $access->languageRead((int)$languageId), ARRAY_FILTER_USE_KEY);
    }

    /**
     * May the group read the target areas of ALL link fields of an area?
     * Otherwise its records cannot be moved (the links could not be taken along).
     */
    public function relationTargetsReadable(int $areaId): bool
    {
        foreach ($this->virtualSchema($areaId, false) as $colDef) {
            if (!$this->relationTargetReadable($colDef)) return false;
        }
        return true;
    }

    /**
     * Working state of a working copy - for the input mask, which fetches
     * the values of all languages from it (RecordBackendEdit::_editLanguageValues()).
     *
     * @param int $tmpId  id of the working copy
     * @return array      working-state format, see VirtualFieldsTrait::_loadTmpData()
     */
    public function loadTmpData(int $tmpId): array
    {
        return $this->_loadTmpData($tmpId);
    }

    /**
     * Secure file and password inputs on a working copy without saving -
     * what Dataset::save() does before the input check (secureUploads(),
     * securePasswords()). For the TEMPLATE of the batch processing (RecordBackendBatch):
     * it is never saved itself; its files (physically under its id, in the
     * working state with 'temporary') and password hashes move into
     * every record of the scope when running (RecordBatch::editJob()).
     *
     * @param int   $tmpId  working copy of the session admin
     * @param array $data   inputs (data[...] of the mask; uploads from $_FILES)
     * @return bool         false: file rejected or foreign working copy (Cms::errors)
     */
    public function secureInputs(int $tmpId, array $data): bool
    {
        if (!$prodData = $this->getProdData($tmpId)) {
            $this->cms->error($this->cms->snippet('error_tmp_record_access_denied'));
            return false;
        }
        $schemaVirtual = $this->virtualSchema((int)$prodData[1], false);
        if (!$this->secureUploads($tmpId, $schemaVirtual, $data)) return false;
        $this->securePasswords($tmpId, $schemaVirtual, $data);
        return true;
    }

    /**
     * Languages of the instance of a record (primary language first) - also for
     * working copies, which carry the instance_id of their area.
     *
     * @return int[]
     */
    public function languageIdsOf(int $recordId): array
    {
        return $this->recordLanguageIds($recordId);
    }

    /**
     * Before marking as "deleted" (soft delete)
     *
     * Moves the media files of the record into the trash var/deleted
     * ({fieldId}_{recordId}_{version}.{ext}). The `data_fil` entries remain -
     * without them the field, extension and version could not be determined when
     * restoring.
     *
     * Runs exactly once per record, also with mass deletions: Dataset::delete()
     * passes each record individually through softDeleteOne().
     */
    protected function hookOnSoftDelete(array $data): void
    {
        $recordId = (int)($data['record_id'] ?? 0);
        if ($recordId < 1) return;
        $this->trigger((int)($data['area_id'] ?? 0), 'onSoftDelete', $data);
        $this->trashRecordFiles($recordId);
        // firmly linked contents (link) go into the trash with it
        $this->cascadeDependents($recordId, 'delete');
    }

    /**
     * Check the sign-in name of a profile record (hookOnSave()): user_* is reserved for
     * profiles that a deletion policy has anonymized - exactly user_{record_id} of the
     * record itself is allowed (working copy: of the original).
     */
    private function checkReservedLoginName(array $fields, array $data, array $previousData): void
    {
        $areaId = (int)($data['area_id'] ?? $previousData['area_id'] ?? 0);
        if ($areaId < 1) return;
        $this->db->pushResult();
        try {
            $this->db->query("SELECT f.`name`, f.`label` FROM `area` a JOIN `field` f ON f.`field_id` = a.`login_name_field_id`"
                . " WHERE a.`area_id` = ? AND a.`type` = 'profile'", 'i', [$areaId]);
            $field = $this->db->fetch();
        }
        finally {
            $this->db->popResult();
        }
        if (!$field) return;
        $name = (string)$field['name'];
        if ((!empty($fields) && !in_array($name, $fields, true)) || !array_key_exists($name, $data)) return;
        $values = is_array($data[$name]) ? $data[$name] : [$data[$name]];
        $previous = array_map('strval', (array)($previousData[$name] ?? []));
        $prodId = (int)($data['origin_id'] ?? 0) ?: (int)($previousData['origin_id'] ?? 0);
        foreach ($values as $value) {
            $value = trim((string)$value);
            if (!preg_match('/^user_/i', $value)) continue;
            // unchanged (already anonymized) or exactly one's own name
            if (in_array($value, $previous, true) || ($prodId > 0 && $value === 'user_' . $prodId)) continue;
            $this->createFieldError('privacy.error_login_reserved', $name, (string)$field['label']);
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }
    }

    /**
     * Before restoring
     *
     * Counterpart to hookOnSoftDelete(): fetches the media files back from the trash
     * into the media directory.
     */
    protected function hookOnRestore(array $data): void
    {
        $recordId = (int)($data['record_id'] ?? 0);
        if ($recordId < 1) return;
        $this->trigger((int)($data['area_id'] ?? 0), 'onRestore', $data);
        $this->untrashRecordFiles($recordId);
        // open jobs of the deletion policy (module Privacy) lapse - the record lives on
        $this->container->get(\Camindo\Core\Modules\Privacy\PrivacyJobs::class)->cancel($recordId);
        // firmly linked contents (link) of the same deletion come back with it
        $this->cascadeDependents($recordId, 'restore');
    }

    /**
     * Before final deletion...
     * Runs only on final deletion (system tool: deleted=1, older than
     * admin.trash_retention_days). On soft delete hookOnSoftDelete() applies instead -
     * there files are only moved, not deleted.
     *
     * It must happen here in the hook, because Dataset::finalDeleteOne() calls hookOnDelete() BEFORE
     * the DELETE of the record row: afterwards the data_fil entries are removed by
     * FK cascade and extension and version could no longer be determined.
     * Tree children are not taken into account - Dataset::purge() passes each record
     * of the subtree individually through here, leaves first.
     */
    protected function hookOnDelete(array $data): void
    {
        // Dataset::finalDeleteOne() -> loadProductive() liefert hier immer den
        // productive record (temporary id has already been resolved).
        $recordId = (int)($data['record_id'] ?? 0);
        if ($recordId < 1) return;
        $this->trigger((int)($data['area_id'] ?? 0), 'onDelete', $data);

        // permanently remove media files - in the trash (normal case, the record
        // was soft-deleted) AND in the media directory (if moving during the
        // soft delete failed). Also removes the files of any working copies still
        // present: Dataset::finalDeleteOne() removes their rows in the
        // same process via SQL, without its own hookOnDelete().
        $this->purgeRecordFiles($recordId);
        // If the record never had jobs of the deletion policy (e.g. deleted before its
        // introduction), the policy of the area at least handles its history
        // in the audit log (blocks of the trigger 'area')
        $this->container->get(\Camindo\Core\Modules\Privacy\PrivacyJobs::class)->scheduleWithoutPolicy($recordId);
        // firmly linked contents (link): delete them permanently too - BEFORE the DELETE of the
        // row, afterwards their edges would be gone via FK cascade
        $this->cascadeDependents($recordId, 'purge');
    }

    /**
     * After final deletion: only the trigger template.
     */
    protected function hookOnDeleted(array $data): void
    {
        $this->trigger((int)($data['area_id'] ?? 0), 'onDeleted', $data);
    }

    /**
     * Cancel editing (TemporaryTrait::cancel()) - together with the working copies of the
     * firmly linked contents (LinkTrait::cancelDependents()), before the edges
     * disappear with the working copy.
     */
    public function cancel(int $tmpId): void
    {
        if ($tmpId < 1) return;
        if ($this->getProdData($tmpId)) {
            $this->cancelDependents($tmpId);
        }
        $this->tmpCancel($tmpId);
    }

    /**
     * Discard all open working copies of the session admin - on sign-out
     * (StartBackend::_logout()). This releases his edit locks immediately instead
     * of only after admin.edit_lock_sec (TemporaryTrait::getLock()).
     *
     * The working copy of a new record that has never been saved (without origin_id) is also discarded:
     * after signing out no mask is open any more that could still save it. Copies that have already
     * been made invalid (`deleted` = 1) no longer lock anything and are left to the cleanup
     * in the module Tools.
     *
     * The copies of firmly linked contents belong to the same admin and fall with
     * their source (cancel() -> cancelDependents()) or, if that is already gone,
     * individually - cancel() skips an id that has already been discarded.
     *
     * @return int  number of working copies found
     */
    public function cancelAll(): int
    {
        return $this->_cancelWorkingCopies(null);
    }

    /**
     * Discard the working copies of a session (Session::registerWorkingCopy()) -
     * on sign-out of this browser only. Masks that the same admin has open on another
     * device remain.
     *
     * @return int  number of working copies found
     */
    public function cancelSession(string $sessionId): int
    {
        if ($sessionId === '') return 0;
        return $this->_cancelWorkingCopies($sessionId);
    }

    /**
     * Discard the working copies of all OTHER sessions of the session admin - for
     * "End all other sessions" (AdminBackend::devices()), like signing out
     * on all devices, only without the own session: its open masks remain.
     * The copies that are assigned to another session are captured
     * (Session::registerWorkingCopy()); copies of firmly linked contents fall with
     * their source. Call before Session::closeAllOf() - that deletes the assignments.
     *
     * @param string $keepSessionId  the running session
     * @return int  number of working copies found
     */
    public function cancelOtherSessions(string $keepSessionId): int
    {
        if ($keepSessionId === '') return 0;
        return $this->_cancelWorkingCopies($keepSessionId, except: true);
    }

    /**
     * Discard working copies of the session admin: all ($sessionId = null), those
     * of a session or ($except) those of all other sessions.
     */
    private function _cancelWorkingCopies(?string $sessionId, bool $except = false): int
    {
        $adminId = (int)$this->session->get('admin_id');
        if ($adminId < 1) return 0;

        $this->db->pushResult();
        if ($sessionId === null) {
            $this->db->query(
                "SELECT `record_id` FROM `record` WHERE `tmp` = 1 AND `deleted` = 0 AND `changed_by` = ? ORDER BY `record_id`",
                'i',
                [$adminId]
            );
        }
        else {
            $this->db->query(
                "SELECT `record`.`record_id` FROM `record`"
                . " JOIN `admin_session_record` ON `admin_session_record`.`record_id` = `record`.`record_id`"
                . " WHERE `admin_session_record`.`session_id` " . ($except ? '<>' : '=') . " ? AND `tmp` = 1 AND `deleted` = 0 AND `changed_by` = ?"
                . " ORDER BY `record`.`record_id`",
                'si',
                [$sessionId, $adminId]
            );
        }
        $tmpIds = array_map('intval', $this->db->fetchColumn());
        $this->db->popResult();

        foreach ($tmpIds as $tmpId) {
            $this->cancel($tmpId);
        }
        return count($tmpIds);
    }
       

    // ====================
    // Import (RecordImport)

    /**
     * Save for the import: like save(), except that values from an export file
     * may already be in database format ($storedFields, see
     * Dataset::$storedFormatFields) and passwords as a finished hash ($prehashed,
     * see Dataset::$prehashedFields). Both lists apply to this one call.
     *
     * @param string[] $fields         as save()
     * @param array    $data           as save()
     * @param string[] $storedFields   fields in database format
     * @param string[] $prehashed      password fields whose value is the hash
     * @param bool     $stayTemporary  simulation: check, no checkIn
     * @param bool     $deferRelations first pass: skip the count check of the
     *                                 link fields (Dataset::$deferRelationChecks)
     */
    public function importSave(array $fields, array $data, array $storedFields = [], array $prehashed = [], bool $stayTemporary = false, bool $deferRelations = false): ?int
    {
        $this->storedFormatFields = $storedFields;
        $this->prehashedFields = $prehashed;
        $this->deferRelationChecks = $deferRelations;
        try {
            return $this->save($fields, $data, $stayTemporary);
        }
        finally {
            $this->storedFormatFields = [];
            $this->prehashedFields = [];
            $this->deferRelationChecks = false;
        }
    }

    /**
     * Free a record_id for the import: if it is taken by a working copy or a
     * deleted record (trash), this is removed permanently - including
     * files. A productive record remains untouched.
     *
     * @return string  'free' (id is free or was made free),
     *                 'productive' (taken), 'tmp'/'deleted' if the removal failed
     */
    public function freeRecordId(int $id): string
    {
        if ($id < 1) return 'productive';
        $this->db->pushResult();
        $this->db->query("SELECT `tmp`, `deleted` FROM `record` WHERE `record_id` = ?", 'i', [$id]);
        $row = $this->db->fetch();
        $this->db->popResult();
        if (!$row) return 'free';
        if (!empty($row['tmp'])) {
            // working copy: files gone, row gone (record_cache and data_rel via FK)
            $this->deleteRecordFiles($id);
            $this->db->pushResult();
            $this->db->query("DELETE FROM `record` WHERE `record_id` = ? AND `tmp` = 1", 'i', [$id]);
            $this->db->popResult();
            return $this->_recordExists($id) ? 'tmp' : 'free';
        }
        if (!empty($row['deleted'])) {
            // trash: delete permanently like the scheduler task trash (with hooks)
            $this->finalDelete($id);
            return $this->_recordExists($id) ? 'deleted' : 'free';
        }
        return 'productive';
    }

    private function _recordExists(int $id): bool
    {
        $this->db->pushResult();
        $this->db->query("SELECT 1 FROM `record` WHERE `record_id` = ?", 'i', [$id]);
        $exists = $this->db->num_rows > 0;
        $this->db->popResult();
        return $exists;
    }

    /**
     * Change the original file name of a file (info page, pencil behind
     * "Original filename") - without a working copy, directly on the productive record.
     *
     * Only for upload fields - the thumbnails derived from it (also over several
     * levels) take over the new name, as they do when they are generated.
     *
     * Only the name before the extension can be chosen; the extension remains that of the file
     * (`data_fil`.extension). All rows that describe the same physical
     * file are changed: for single-language fields the rows of all languages
     * (file_language_id NULL), for multilingual ones the language's own row
     * together with the fallback rows that point to it. `version` remains - the
     * file itself is unchanged, and the name in the link changes anyway
     * (Cms::cmsMedia()). The content cache is discarded and rebuilt on the
     * next access.
     *
     * Rejected as long as a working copy exists (including one's own): its
     * check-in would write the old name back.
     *
     * @param int      $recordId        productive record
     * @param int      $fieldId         field_id of the file field
     * @param ?int     $fileLanguageId  language of the file (null: single-language field)
     * @param string   $baseName        new name without extension
     * @return ?string                  the stored file name, null on error (cms->error)
     */
    public function renameFile(int $recordId, int $fieldId, ?int $fileLanguageId, string $baseName): ?string
    {
        $this->db->pushResult();
        try {
            $this->db->query(
                'SELECT `d`.`extension` FROM `data_fil` `d`'
                . ' INNER JOIN `field` `f` ON `f`.`field_id` = `d`.`field_id` AND `f`.`display_type` != \'thumbnail\''
                . ' WHERE `d`.`record_id` = ? AND `d`.`field_id` = ?'
                . ' AND `d`.`file_language_id` <=> ? AND `d`.`size` > 0 LIMIT 1',
                'iii', [$recordId, $fieldId, $fileLanguageId]
            );
            $row = $this->db->fetch();
            if (!$row) {
                $this->cms->error($this->cms->snippet('record.error_file_rename_nofile'));
                return null;
            }
            $extension = (string)$row['extension'];

            // working copy present (not orphaned, see getLock())?
            if ($this->hasActiveWorkingCopy($recordId)) {
                $this->cms->error($this->cms->snippet('record.error_file_rename_locked'));
                return null;
            }

            // cut off the extension if entered as well; then clean up as with an upload
            $baseName = trim($baseName);
            if ($extension !== '' && str_ends_with(strtolower($baseName), '.' . strtolower($extension))) {
                $baseName = substr($baseName, 0, -strlen($extension) - 1);
            }
            $baseName = rtrim($baseName, '. ');
            if ($baseName === '') {
                $this->cms->error($this->cms->snippet('error_value_mandatory'));
                return null;
            }
            $filename = DirectoryHelpers::sanitizeFilename($baseName . ($extension !== '' ? '.' . $extension : ''));

            // the upload field and its thumbnails (field.parent_field_id, level by level)
            $fieldIds = [$fieldId];
            $parents = [$fieldId];
            while ($parents) {
                $this->db->query(
                    "SELECT `field_id` FROM `field` WHERE `display_type` = 'thumbnail' AND `deleted` = 0"
                    . ' AND `parent_field_id` IN (' . implode(',', array_map('intval', $parents)) . ')'
                );
                $parents = array_values(array_diff(array_map('intval', $this->db->fetchAll()), $fieldIds));
                $fieldIds = array_merge($fieldIds, $parents);
            }
            $this->db->query(
                'UPDATE `data_fil` SET `filename` = ?'
                . ' WHERE `record_id` = ? AND `field_id` IN (' . implode(',', $fieldIds) . ') AND `file_language_id` <=> ?'
                . " AND `extension` != ''",
                'sii', [$filename, $recordId, $fileLanguageId]
            );
            // discard the content cache: Cms::fetch() rebuilds it from the data_* rows
            $this->db->query('DELETE FROM `record_cache` WHERE `record_id` = ?', 'i', [$recordId]);
            // full text: the file name is the source (upload fields)
            $this->affectedTextFieldIds = $fieldIds;
            $this->refreshAffectedFulltext($recordId);
            return $filename;
        } finally {
            $this->db->popResult();
        }
    }

    /**
     * Is a working copy of the record in work (not orphaned, see getLock())? Changes
     * made directly on the productive record (file name, image editing) would be
     * overwritten by its check-in.
     */
    public function hasActiveWorkingCopy(int $recordId): bool
    {
        $outdatedAt = gmdate('Y-m-d H:i:s', time() - (int)$this->config->get('admin.edit_lock_sec'));
        $this->db->pushResult();
        $this->db->query(
            'SELECT 1 FROM `record` WHERE `tmp` = 1 AND `deleted` = 0 AND `origin_id` = ? AND `changed_at` >= ?',
            'is', [$recordId, $outdatedAt]
        );
        $found = $this->db->num_rows > 0;
        $this->db->popResult();
        return $found;
    }

    /**
     * Metadata (`data_fil`) of a file: filename, extension, mime_type, size ...
     *
     * @param ?int $fileLanguageId  language of the file (null: single-language field)
     */
    public function fileRow(int $recordId, int $fieldId, ?int $fileLanguageId): ?array
    {
        $this->db->pushResult();
        $this->db->query(
            'SELECT * FROM `data_fil` WHERE `record_id` = ? AND `field_id` = ?'
            . ' AND `file_language_id` <=> ? LIMIT 1',
            'iii', [$recordId, $fieldId, $fileLanguageId]
        );
        $row = $this->db->fetch();
        $this->db->popResult();
        return $row ?: null;
    }

    // ====================
    // Audit-Log

    /**
     * Snapshot of a record for the audit log (see Dataset::auditSnapshot()):
     * the meta fields from `record`, each field of the area from the content cache -
     * multilingual fields per language (key "{name}@{code}", a value not
     * translated counts as empty) - and the links as an id list.
     *
     *     password    comparison via the hash from data_pwd, never a value
     *     encrypted   ciphertext from the cache, comparison via the HMAC of the exact
     *                 plain text (AuditLog::encryptedEntry()) - not via
     *                 data_enc.hash, which is normalized (Cms::encryptedHash()) and
     *                 would not see a changed upper/lower case
     *     file        "file name (size, W×H)", comparison via name, size, version
     */
    public function auditSnapshot(int $id): array
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT * FROM `record` WHERE `record_id` = ?", 'i', [$id]);
            $row = $this->db->fetch();
            if (!$row) return [];
            $snapshot = $this->auditColumns($row, $this->schema);
            $areaId = (int)$row['area_id'];
            if ($areaId < 1) return $snapshot;

            $schemaVirtual = $this->virtualSchema($areaId, false);
            $langCtx = $this->languageContext($areaId);
            $languageIds = (array)($langCtx['language_ids'] ?? []);
            $primaryId = (int)($langCtx['primary_id'] ?? 0);
            $codes = (array)($langCtx['codes'] ?? []);
            $cache = $this->_loadRecordCacheRows($id);
            // rebuild missing language rows: after new thumbnails
            // writeDeferredThumbnails() discards the cache (otherwise it is only built on the next
            // read) - without it the snapshot would see nothing but empty fields
            $missing = array_diff(array_map('intval', $languageIds), array_keys($cache));
            if ($missing) {
                // writes all language rows at once
                $this->cms->createRecordCache($id, $areaId, (int)$row['instance_id'], (int)reset($missing));
                $cache = $this->_loadRecordCacheRows($id);
            }

            // password hashes
            $secrets = [];
            $this->db->query("SELECT `field_id`, `language_id`, `data` FROM `data_pwd` WHERE `record_id` = ?", 'i', [$id]);
            while ($r = $this->db->fetch()) $secrets[(int)$r['field_id']][(int)$r['language_id']] = hash('sha256', (string)$r['data']);
            // links per field in their order
            $relations = [];
            $this->db->query("SELECT `field_id`, `dest_id` FROM `data_rel` WHERE `src_id` = ? ORDER BY `field_id`, `position`, `dest_id`", 'i', [$id]);
            while ($r = $this->db->fetch()) $relations[(int)$r['field_id']][] = (int)$r['dest_id'];
        }
        finally {
            $this->db->popResult();
        }

        $auditLog = $this->container->get(\Camindo\Core\AuditLog::class);
        foreach ($schemaVirtual as $name => $colDef) {
            $name = (string)$name;
            if (isset($this->cms->schema[$name])) continue;   // meta field, already above
            $type = (string)($colDef['type'] ?? 'text');
            if ($type === 'fulltext') continue;   // derived from the source fields
            $label = (string)(($colDef['label'] ?? '') ?: $name);
            $fieldId = (int)($colDef['field_id'] ?? 0);

            if (in_array($type, $this->cms->relationTypes, true)) {
                $ids = $relations[$fieldId] ?? [];
                $snapshot[$name] = ['l' => $label, 't' => $type, 'g' => '',
                    'v' => implode(', ', array_map(fn(int $d) => '#' . $d, $ids))];
                continue;
            }

            $multilingual = $this->cms->isMultilingualField($colDef) && count($languageIds) > 1;
            foreach ($multilingual ? $languageIds : [$primaryId] as $lang) {
                $lang = (int)$lang;
                $code = $multilingual ? (string)($codes[$lang] ?? $lang) : '';
                $key = $multilingual ? $name . '@' . $code : $name;
                $entry = $cache[$lang][$name] ?? null;
                $fallback = !empty($entry[2]);
                $value = $fallback ? '' : ($entry[1] ?? '');

                if ($type === 'password' || ($colDef['display_type'] ?? '') === 'password') {
                    $snapshot[$key] = ['l' => $label, 't' => 'password', 'g' => $code, 'v' => '',
                        'c' => (string)($secrets[$fieldId][$lang] ?? $secrets[$fieldId][$primaryId] ?? '')];
                }
                elseif ($type === 'encrypted') {
                    $cipher = is_string($value) && $value !== '' ? (string)base64_decode($value, true) : '';
                    $snapshot[$key] = $auditLog->encryptedEntry($label, $cipher, $code);
                }
                elseif ($type === 'file') {
                    $file = is_array($value) ? $value : [];
                    $filename = (string)($file['filename'] ?? '');
                    $size = (int)($file['size'] ?? 0);
                    $text = '';
                    if ($filename !== '' || $size > 0) {
                        $text = ($filename !== '' ? $filename : '.' . (string)($file['extension'] ?? ''))
                            . ' (' . $this->system->convertSize((float)$size)
                            . (!empty($file['width']) ? ', ' . (int)$file['width'] . '×' . (int)$file['height'] : '') . ')';
                    }
                    $snapshot[$key] = ['l' => $label, 't' => 'file', 'g' => $code, 'v' => $text,
                        'c' => $text === '' ? '' : $text . '|' . (int)($file['version'] ?? 0)];
                }
                else {
                    $snapshot[$key] = ['l' => $label, 't' => $type, 'g' => $code,
                        'v' => is_array($value) ? (string)json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value];
                }
            }
        }
        return $snapshot;
    }

    /**
     * Label for the audit log: content of the default field of the area
     * (area.default_field_id) in the primary language.
     */
    protected function auditLabel(int $id, array $snapshot): string
    {
        $label = $this->auditLabelOf($id, $snapshot);
        return $label !== '' ? $label : '#' . $id;
    }

    /**
     * Label from a snapshot - '' without default field or content
     * (also for RecordPersonalData).
     */
    public function auditLabelOf(int $id, array $snapshot): string
    {
        $areaId = (int)($snapshot['area_id']['v'] ?? 0);
        if ($areaId > 0) {
            $this->db->pushResult();
            try {
                $this->db->query(
                    "SELECT f.`name` FROM `area` a JOIN `field` f ON f.`field_id` = a.`default_field_id` WHERE a.`area_id` = ?",
                    'i', [$areaId]
                );
                $name = (string)($this->db->fetchNum()[0] ?? '');
            }
            finally {
                $this->db->popResult();
            }
            if ($name !== '') {
                foreach ($snapshot as $key => $entry) {
                    if (($key === $name || str_starts_with((string)$key, $name . '@')) && (string)($entry['v'] ?? '') !== ''
                        && !in_array($entry['t'] ?? '', ['password', 'encrypted'], true)) {
                        return mb_substr(strip_tags((string)$entry['v']), 0, 200);
                    }
                }
            }
        }
        return '';
    }
}
