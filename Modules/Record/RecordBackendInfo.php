<?php
/**
 * Detail view of a record (extension for RecordBackend).
 *
 * Entry points (scripts in backend/):
 *
 *     record_info.php          -> info()          detail view with tabs
 *     ajax_field_details.php   -> fieldDetails()  load a single row afterwards
 *                                                 (GDPR, RecordPersonalData)
 *     record_second_factor.php -> secondFactor()  show and reset the second factor
 *                                                 of a profile
 *
 * The button "Move" leads to RecordBackendMove (record_move.php); it is missing
 * if the view was opened from a lookup window (src_id > 0).
 *
 * TABS
 * --------------------
 *     Contents    the fields that the editor has defined in the area
 *     Meta fields the fixed columns of the table `record` - id, times, status,
 *                 template, creator, tree position
 *     Changes     the history from the audit log (Camindo\Core\AuditLog), the
 *                 last ten entries, for each entry before/after of all changed
 *                 fields (Backend::auditHistory() -> AuditDiff); longer histories in the module Audit.
 *                 Visible to everyone with read right on the area - the IP address
 *                 only for superusers. Not in the lookup window (src_field).
 *
 * If the instance of the record has several languages and the area splits them into
 * tabs (details.tabs), it stays - unlike in the input mask
 * (RecordBackendEdit) - with the one tab "Contents"; a drop-down below it selects the language
 * (one entry per language, last "All languages"). A language loads only
 * the content cache of this language (`record_cache`) and shows one row per field;
 * "All languages" loads each language and shows multilingual fields per language.
 * Untranslated values (fallback from the primary language) appear grayed out.
 * The first thing opened is the admin's preferred language version (personal
 * setting), otherwise the primary language - see _infoCurrentTab().
 *
 * It is switched via the request parameter tab ('meta', 'all', a language_id
 * or empty); all tabs and drop-down entries are the same entry point, only
 * with a different body (_infoContents() / _infoMeta()).
 *
 * The separation is more than cosmetic: template_id deliberately does NOT appear in the contents tab,
 * although it is in the schema - it belongs to the meta fields and
 * would otherwise appear twice there. status_id appears there only if the area has
 * created an input field for it (it then works with the workflow) - highlighted
 * ('primary').
 *
 * FLAT WORKFLOW
 * --------------------
 * If the record carries a status that the group may not set
 * (Access::recordWrite()), the edit button is grayed out - even with
 * write right in the area.
 *
 * TREE FIELDS ONLY FOR TREE AREAS
 * --------------------
 * position, depth, lft and parent_id are shown on the meta fields tab only if
 * the area has a hierarchy at all (i.e. an input field for parent_id is defined).
 * Without that the values are present but meaningless.
 *
 * LONG CONTENTS ARE LOADED LATER
 * --------------------
 * The rows of the contents tab get the flag 'expandable': they first show
 * only a preview and fetch the full content via fieldDetails() when needed.
 * Otherwise the detail view would have to prepare all text fields,
 * links and preview images of a record at once.
 *
 * @see Camindo\Core\Modules\Record\RecordBackend      shared base
 * @see Camindo\Core\Modules\Record\RecordBackendEdit  the input mask for it
 * @see Camindo\Core\Backend::infoAdd()                  builds the rows
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\AuditLog;
use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Crypt;
use Camindo\Core\Database;
use Camindo\Core\InstanceCache;
use Camindo\Core\MassDeletion;
use Camindo\Core\Session;
use Camindo\Core\System;
use Camindo\Core\Modules\Area\Area;

class RecordBackendInfo extends RecordBackend {

    public function __construct(
        Config $config,
        Backend $backend,
        Crypt $crypt,
        Session $session,
        Database $db,
        System $system,
        Cms $cms,
        MassDeletion $massDeletion,
        Area $area,
        Record $record,
        InstanceCache $instanceCache,
        RecordService $service,
        RecordPresenter $presenter,
        private AuditLog $auditLog,
        private RecordPersonalData $personalData,
        private RecordExport $export,
        private UserSecondFactor $userSecondFactor,
    )
    {
        parent::__construct($config, $backend, $crypt, $session, $db, $system, $cms, $massDeletion, $area, $record, $instanceCache, $service, $presenter);
    }

    /**
    /**
     * Output the detail view (backend/record_info.php).
     *
     * $this->function is set to 'info' in advance - _resolveParams() switches to
     * 'errors' on an error, the comparison afterwards catches exactly that. The same
     * place also controls headline(), which only writes the record
     * itself above the page for 'info' and 'delete'.
     *
     * The status symbol appears in the headline only if the area has created
     * its own input field for status_id - this shows that the editor
     * works with the status at all. This is recognizable by 'field_id' in
     * the column definition: only virtual fields have one.
     *
     * Request: id, area_id, tab ('meta', 'all', language_id or empty)
     */
    /** Maximum number of linked records per field in the detail view (_infoRelated()) */
    private const RELATED_MAX = 20;

    /** read contents per language from record_cache (multilingual instance + fields) */
    private bool $useLanguageCaches = false;

    public function info()
    {
        echo $this->backend->template('container_header');

        $this->function = 'info';

        $this->_resolveParams();

        if ($this->function !== 'info') {
            $this->errors();
            echo $this->backend->template('container_footer');
            return;
        }

        // Seitenelemente
        // --------------------

        // Bread crum navi
        $this->backend->setNaviLabel('#button_info');

        // Load data
        if (!$this->data = $this->record->load($this->id)) {
            // Fehlermeldungen in $this->cms->errors
            $this->errors();
            echo $this->backend->template('container_footer');
            return;
        }
        $this->areaId = (int)$this->data['area_id'];

        // rights of the user group: read right on the area (developer docs, chapter 11 (Permissions))
        $access = $this->backend->access();
        if (!$access->areaRead($this->areaId)) {
            echo $access->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }
        $ownerId = isset($this->data['owner_id']) ? (int)$this->data['owner_id'] : null;
        // pencil behind "Original filename" only with write right (fileRename())
        if ($access->areaWrite($this->areaId, $ownerId)) {
            $this->backend->fileRenameIds[$this->id] = true;
        }

        // load virtual fields - link fields without a readable target area are left out
        $schemaVirtual = array_filter(
            $this->record->virtualSchema($this->areaId, false),
            fn(array $colDef) => $this->record->relationTargetReadable($colDef)
        );
        $this->hasParentId = isset($schemaVirtual['parent_id']);

        // H1
        // if the virtual field is based on status_id (field_id contained in the column definition)
        // then show the status icon before the headline
        $statusCol = (array)($schemaVirtual['status_id'] ?? []);
        if (!empty($statusCol['field_id'])) {
            $displayIcon = true;
        }
        else {
            $displayIcon = false;
        }
        echo $this->headline($displayIcon);
        
        // Buttons
        // --------------------
        $this->backend->buttonBarReset();
        if ($access->recordWrite($this->areaId, $ownerId, (int)($this->data['status_id'] ?? 0))) {
            $this->backend->buttonAdd('button_edit_primary', "cApp.layers.replace('Record/record_edit.php?{$this->params}&id={$this->id}');");
        }
        elseif ($access->areaWrite($this->areaId, $ownerId)) {
            // write right, but the status is not settable for the group: the
            // record is closed for them (flat workflow)
            $this->backend->buttonAdd('record.button_edit_status_denied', '');
        }
        if ($access->areaCreate($this->areaId)) {
            $this->backend->buttonAdd('button_copy', "cApp.layers.replace('Record/record_edit.php?{$this->params}&id={$this->id}&function=copy');");
        }
        if ($access->areaDelete($this->areaId, $ownerId)) {
            $this->backend->buttonAdd('button_delete', "cApp.layers.replace('Record/record_edit.php?{$this->params}&id={$this->id}&function=delete');");
        }
        /*
          If a default template is defined in the area, or a template was explicitly assigned
          to the record, then show the preview link
        */
        if (
            !empty($this->areaData['default_template_id'])
            || !empty($this->data['template_id'])
        ) {
            $this->backend->buttonAddPreview($this->createPreviewLink(), (int)($this->data['instance_id'] ?? 0));
        }
        if (!empty($_REQUEST['back'])) {
            $this->backend->buttonAdd('button_back', "history.back()");
        }
        else {
            $this->backend->buttonAdd('button_close', "cApp.layers.close()");
        }
        if ($this->srcId < 1) {
            // menu on the right: move (write + delete in the source area, no
            // link fields without target access), write message to Record
            $canMove = $access->areaWrite($this->areaId, $ownerId) && $access->areaDelete($this->areaId, $ownerId)
                && $this->record->relationTargetsReadable($this->areaId);
            $this->backend->buttonAdd($canMove ? 'record.record_info_more_dropdown' : 'record.record_info_more_dropdown_nomove', '', '', alignRight:true);
            // profile records: data export (module Privacy) - before {record_id}/{area_id},
            // the template of the entry carries these placeholders
            $this->backend->buttonBarReplace('{extra_items}', ($this->_personalDataAllowed()
                ? $this->backend->template('record.record_info_item_personal_data') : '')
                . ($this->_areaType() === 'profile'
                ? $this->backend->template('record.record_info_item_second_factor') : ''));
            $this->backend->buttonBarReplace('{record_id}', (string)$this->id);
            $this->backend->buttonBarReplace('{area_id}', (string)$this->areaId);
        }
        echo $this->backend->buttonBar();

        // Tabs
        // ----------
        // Contents | Meta fields
        // if activated in the content area, then with tabs per language
        $languages = $this->instanceCache->languages((int)($this->data['instance_id'] ?? 0));
        // Multilingual: contents per language from the content cache (even if the
        // group may only read one language - Record::load() returns the
        // primary language, and they may not be allowed to see it at the moment)
        // A single-language slug with a slug prefix that differs per language counts like
        // a multilingual field (_infoSlug())
        $this->useLanguageCaches = count($languages) > 1
            && ($this->_hasMultilingualField($schemaVirtual) || $this->_slugPrefixMultilingual($schemaVirtual, $languages));
        if (!$this->useLanguageCaches) {
            $languages = array_slice($languages, 0, 1, true);
        }
        else {
            // only readable languages of the user group (tabs and rows)
            $languages = $this->record->readableLanguages($languages);
        }
        // Active tab: request value, otherwise the admin's preferred language version,
        // otherwise the primary language (see _infoCurrentTab()) - NOT the raw
        // request parameter, otherwise on the first call (no tab= in the URL)
        // no tab would be active and _infoContents() would read (int)'' = language 0 instead of the
        // preferred/primary language.
        $tab = $this->_infoCurrentTab($languages);
        $url = "Record/record_info.php?{$this->params}&id={$this->id}";
        $this->backend->tabBarReset();
        // tab "Contents" - even with languages split into tabs only one tab,
        // the language is selected by the drop-down below it (_infoLanguageDropdown())
        $isContents = ($tab !== 'meta' && $tab !== 'audit');
        $this->backend->tabAdd('#record.tab_data_fields', $isContents ? '' : "cApp.layers.replace('{$url}')", $isContents);
        // tab "Meta fields"
        $this->backend->tabAdd('#record.tab_meta_fields', $tab === 'meta' ? '' : "cApp.layers.replace('{$url}&tab=meta')", $tab === 'meta');
        // tab "Changes" (audit log) - not in the lookup window
        if ($this->auditLog->enabled() && $this->srcField < 1) {
            $this->backend->tabAdd('#tab_audit', $tab === 'audit' ? '' : "cApp.layers.replace('{$url}&tab=audit')", $tab === 'audit');
        }
        echo $this->backend->tabBar();

        if ($tab === 'meta') {
            // panel "Meta fields"
            $this->_infoMeta($schemaVirtual);
        }
        elseif ($tab === 'audit') {
            // panel "Changes"
            $this->_infoAudit();
        }
        else {
            // panel "Contents": one language or all
            $this->_infoContents($schemaVirtual, $languages, $tab);
        }
    
        echo $this->backend->template('container_footer');
    }

    /**
     * Does the area have at least one multilingual field (field.multilingual)?
     */
    private function _hasMultilingualField(array $schemaVirtual): bool
    {
        foreach ($schemaVirtual as $colDef) {
            if ($this->cms->isMultilingualField((array)$colDef)) return true;
        }
        return false;
    }

    /**
     * Does the area have a single-language slug field, but a slug prefix that
     * differs per language? Then the address is also different per language,
     * and the info page shows the slug like a multilingual field (_infoSlug()).
     *
     * @param array $languages  language_id => language row of the instance
     */
    private function _slugPrefixMultilingual(array $schemaVirtual, array $languages): bool
    {
        if (!isset($schemaVirtual['slug']) || $this->cms->isMultilingualField((array)$schemaVirtual['slug'])) return false;
        $prefixes = [];
        foreach (array_keys($languages) as $languageId) {
            $prefixes[$this->instanceCache->slugPrefix($this->areaId, (int)$languageId)] = true;
        }
        return count($prefixes) > 1;
    }

    /**
     * Row(s) of the slug: "{prefix}/" in gray before the slug - as it appears in the address
     * (Cms::slugWithPrefix()). Prefix and slug apply per language: a
     * multilingual slug comes from the content cache, a single-language one applies to
     * all languages. For "All languages" one row per language, otherwise the selected
     * one or (without language selection) the primary language.
     *
     * @param array      $languageCaches  language_id => content cache (_infoRows())
     * @param array      $languages       languages (see _infoContents())
     * @param int|string $tab             'all' or language_id
     */
    private function _infoSlug(array $colDef, array $schemaVirtual, array $languageCaches, array $languages, int|string $tab, array $flags): void
    {
        $flags = array_values(array_diff($flags, ['expandable']));
        $flags[] = 'html';
        $multilingual = !empty($languageCaches) && $this->cms->isMultilingualField($colDef);

        $row = function (int $languageId, string $langSuffix = '') use ($colDef, $schemaVirtual, $languageCaches, $multilingual, $flags): void {
            $isFallback = false;
            if ($multilingual) {
                [$slug, $isFallback] = $this->_infoCacheValue($languageCaches[$languageId]['slug'] ?? null, $colDef);
            }
            else {
                $slug = $this->data['slug'] ?? '';
            }
            $slug = (string)$slug;
            if ($slug === '') {
                $value = $this->backend->emptyPlaceholder;
            }
            else {
                $prefix = $this->instanceCache->slugPrefix($this->areaId, $languageId);
                $value = ($prefix !== '' ? '<span class="c9-grey-50">' . htmlspecialchars($prefix) . '/</span>' : '')
                    . htmlspecialchars($slug);
            }
            if ($isFallback) $flags[] = 'grey';
            if (empty($this->areaData['default_template_id'])) {
                $flags[] = 'grey';
            }
            $this->backend->infoAdd($this->record, 'slug', $value, $flags, $schemaVirtual, id: $this->id, langSuffix: $langSuffix);
        };

        if (!empty($languageCaches) && $tab === 'all') {
            foreach ($languages as $languageId => $language) {
                $row((int)$languageId, (string)($language['code'] ?? $languageId));
            }
        }
        else {
            $row(is_int($tab) ? $tab : (int)array_key_first($languages));
        }
    }

    /**
     * Active tab: 'meta', 'all' or a language_id.
     *
     * Without a specification (first call) the admin's preferred language version
     * (personal setting settings.language_id), provided the instance maintains it,
     * otherwise the primary language. If the instance has only one language, there is no
     * language selection - then 'all' is the contents tab.
     *
     * @param array $languages  language_id => language row (InstanceCache::languages())
     * @return int|string
     */
    private function _infoCurrentTab(array $languages): int|string
    {
        $tab = (string)($_REQUEST['tab'] ?? '');
        if ($tab === 'meta') return 'meta';
        if ($tab === 'audit') return $this->srcField < 1 ? 'audit' : 'meta';

        $useTabs = $this->useLanguageCaches && $this->_languageTabs();
        if (!$useTabs) return 'all';

        $languageIds = array_map('intval', array_keys($languages));
        if ($tab === 'all') return 'all';
        if ($tab === '') {
            $preferred = (string)($this->backend->adminSettings()['language_id'] ?? '');
            if ($preferred !== '' && in_array((int)$preferred, $languageIds, true)) {
                return (int)$preferred;
            }
            // otherwise the first tab (primary language or first readable language)
            return (int)($languageIds[0] ?? 0);
        }
        if (!in_array((int)$tab, $languageIds, true)) return (int)($languageIds[0] ?? 0);
        return (int)$tab;
    }

    /**
     * Read a field value from the content cache of a language (record_cache):
     * entry [type, value, (1 = fallback from the primary language)].
     *
     * @return array  [value in the format of Record::load(), bool fallback]
     */
    private function _infoCacheValue(?array $entry, array $colDef): array
    {
        $value = $entry[1] ?? '';
        $type = (string)($colDef['type'] ?? '');
        if ($type === 'json') {
            $value = json_decode((string)$value, true) ?: [];
        }
        elseif ($type === 'encrypted') {
            $value = $this->crypt->decrypt((string)$value, '', false);
        }
        return [$value, !empty($entry[2])];
    }

    /**
     * Tab "Contents": the fields defined by the editor.
     *
     * Runs over the schema of the area, skips the fields that belong on the
     * meta fields tab, and lets Backend::infoAdd() do the preparation - the column definition
     * tells it how a value is to be displayed.
     *
     * Only three cases are handled by hand, which have to turn a number into something readable:
     * needs_review (symbol and text), parent_id (link to the parent record).
     *
     * 'expandable' on each row: long contents are only loaded on click
     * (see fieldDetails()).
     *
     * infoReset() first, because another info table may already have been
     * collected in this request.
     *
     * @param array      $schemaVirtual  schema of the area
     * @param array      $languages      languages of the instance (primary language first)
     * @param int|string $tab            'all' or the selected language_id
     */
    private function _infoContents(array $schemaVirtual, array $languages = [], int|string $tab = 'all'): void
    {
        // no infoReset - the tab has already gone ahead
        // language selection (only with languages split into tabs)
        if ($this->useLanguageCaches && $this->_languageTabs()) {
            $this->backend->infoStatic('', $this->_infoLanguageDropdown($languages, $tab), ['fullsize']);
        }
        $this->_infoRows($schemaVirtual, $languages, $tab);
        echo $this->backend->infoTable();
    }

    /**
     * Drop-down of the language selection in the "Contents" tab: one entry per readable language
     * "Label (code)", last "All languages" - built like the drop-down of the
     * preview (Backend::buttonAddPreview()); the button shows the selected language.
     *
     * @param array      $languages  languages (see _infoContents())
     * @param int|string $tab        'all' or language_id
     */
    private function _infoLanguageDropdown(array $languages, int|string $tab): string
    {
        $url = "Record/record_info.php?{$this->params}&id={$this->id}&tab=";
        $options = [];
        foreach ($languages as $languageId => $language) {
            $code = (string)($language['code'] ?? $languageId);
            $label = $this->cms->textOrSnippet((string)($language['label'] ?? $code)) . ' (' . $code . ')';
            $options[(string)$languageId] = $label;
        }
        $options['all'] = $this->cms->snippet('record.tab_all_languages');

        $items = '';
        foreach ($options as $key => $label) {
            $isActive = ((string)$tab === (string)$key);
            $items .= $this->backend->template('record.record_info_language_dropdown_item', [
                '{url}' => htmlspecialchars($url . $key),
                '{label}' => htmlspecialchars($label),
                '{class}' => $isActive ? ' active' : '',
                '{current}' => $isActive ? ' aria-current="true"' : '',
            ]) . "\n";
        }
        return $this->backend->template('record.record_info_language_dropdown', [
            '{label}' => htmlspecialchars($options[(string)$tab] ?? ''),
            '{items}' => $items,
        ]);
    }

    /**
     * Collect the rows of the "Contents" tab for the record $this->id / $this->data
     * (Backend::infoAdd()) - for the record of the page and, with $depth > 0,
     * for the firmly linked contents of a 'link' field (_infoLinkBlocks()).
     *
     * A 'link' field shows on the page itself its contents as blocks
     * one below the other, each with the input fields (visibility_input = 1) of its
     * area; in such a block (depth > 0) 'link' fields appear, as everywhere else,
     * only with the default fields of their contents (Backend::backendValue()).
     * 'expandable' applies only to the page itself - the reload parameter knows
     * only one field name.
     *
     * @param array      $schemaVirtual  schema of the area of $this->id
     * @param array      $languages      languages (see _infoContents())
     * @param int|string $tab            'all' or language_id
     * @param int        $depth          0 = page, 1 = content of a 'link' field
     */
    private function _infoRows(array $schemaVirtual, array $languages, int|string $tab, int $depth = 0): void
    {
        // content cache per language (`record_cache`): with a selected language only
        // this language, with "All languages" each language of the instance. A
        // single-language instance does not need one - Record::load() has already
        // delivered the primary language. Fallback values (untranslated, marked in the cache)
        // are marked as such.
        $languageCaches = [];
        if ($this->useLanguageCaches) {
            $cacheLanguages = ($tab === 'all') ? array_keys($languages) : [(int)$tab];
            foreach ($cacheLanguages as $languageId) {
                $languageCaches[(int)$languageId] = $this->cms->loadRecordCache($this->id, (int)$languageId) ?? [];
            }
        }

        foreach ($schemaVirtual as $name => $colDef) {
            // status: as an input field of the area (field_id) also here, highlighted -
            // it is the state of the workflow (Access::statusWrite())
            if ($name === 'status_id') {
                if ($depth === 0 && (int)($colDef['field_id'] ?? 0) > 0) {
                    $this->backend->infoAdd($this->record, $name, $this->backend->teaser('status', (int)($this->data['status_id'] ?? 0)),
                        ['html', 'primary'], $schemaVirtual, id: $this->id);
                }
                continue;
            }
            // some fields only on the meta fields tab
            if ($name === 'template_id') continue;
            // full text: derived, own page (meta fields -> "Show full text")
            if ((string)($colDef['type'] ?? '') === 'fulltext') continue;
            // content of a 'link' field: only its input fields
            if ($depth > 0 && (int)($colDef['visibility_input'] ?? 0) !== 1) continue;
            $value = $this->data[$name] ?? null;
            $flags = $depth === 0 ? ['expandable'] : [];
            if ((int)($colDef['field_id'] ?? 0) > 0 && (int)$colDef['field_id'] === (int)($this->areaData['default_field_id'] ?? 0)) {
                $flags[] = 'primary';
            }

            // firmly linked contents: one block per content with its fields
            if ($depth === 0 && in_array((string)($colDef['type'] ?? ''), $this->cms->dependentTypes, true)) {
                $flags[] = 'html';
                $flags[] = 'fullsize';
                $value = $this->_infoLinkBlocks($colDef, $languages, $tab);
                $this->backend->infoAdd($this->record, $name, $value, $flags, $schemaVirtual, id: $this->id);
                continue;
            }

            // linked records: one below the other, each with symbol and link
            if (in_array((string)($colDef['type'] ?? ''), $this->cms->relationTypes, true)) {
                $flags = array_values(array_diff($flags, ['expandable']));
                $flags[] = 'html';
                $this->backend->infoAdd($this->record, $name, $this->_infoRelated($colDef), $flags, $schemaVirtual, id: $this->id);
                continue;
            }

            // slug with the slug prefix of the area in front of it
            if ($name === 'slug') {
                $this->_infoSlug($colDef, $schemaVirtual, $languageCaches, $languages, $tab, $flags);
                continue;
            }

            if (!empty($languageCaches) && $this->cms->isMultilingualField($colDef)) {
                if ($tab === 'all') {
                    // one row per language, with language code
                    foreach ($languages as $languageId => $language) {
                        [$langValue, $isFallback] = $this->_infoCacheValue($languageCaches[(int)$languageId][$name] ?? null, $colDef);
                        $langFlags = $flags;
                        if ($isFallback) $langFlags[] = 'grey';
                        $this->backend->infoAdd($this->record, $name, $langValue, $langFlags, $schemaVirtual, id: $this->id, langSuffix: (string)($language['code'] ?? $languageId));
                    }
                }
                else {
                    // selected language: only the value of this language
                    [$value, $isFallback] = $this->_infoCacheValue($languageCaches[(int)$tab][$name] ?? null, $colDef);
                    if ($isFallback) $flags[] = 'grey';
                    $this->backend->infoAdd($this->record, $name, $value, $flags, $schemaVirtual, id: $this->id);
                }
                continue;
            }
            if  ($name === 'needs_review') {
                $value = $value ? $this->backend->template('user.icon_unreviewed') .' ' . $this->cms->snippet('user.status_unreviewed')
                    : $this->backend->emptyPlaceholder;
                $flags[] = 'html';
            }
            elseif ($name === 'parent_id') {
                $value = $this->backend->teaser('record', (int)$value);
                $flags[] = 'html';
            }
            elseif ($name === 'position') {
                // list the preceding record
                if ($previousId = $this->presenter->previousId($this->data, $this->hasParentId)) {
                    $value = $this->backend->teaser('record', $previousId);
                    $flags[] = 'html';
                }
                else {
                    $value = '';
                }                
            }
            $this->backend->infoAdd($this->record, $name, $value, $flags, $schemaVirtual, id: $this->id);
        }
    }

    /**
     * The firmly linked contents of a 'link' field (LinkTrait) as blocks
     * one below the other, in their order (data_rel.position): header row with the
     * record teaser (link to its info page), below it its input fields
     * in the display of this page (_infoRows() with depth 1, languages as here).
     * A special case of the info page - in lists and previews
     * Backend::backendValue() shows, as with any link field, only the default fields.
     *
     * _infoRows() works on $this->id / $this->data / $this->areaId /
     * $this->areaData - the context is swapped per content; the rows
     * are created in their own collection (Backend::infoPush()/infoPop()).
     *
     * @param array      $colDef     the 'link' field
     * @param array      $languages  languages (see _infoContents())
     * @param int|string $tab        'all' or language_id
     * @return string  HTML of the blocks, '' without contents
     */
    private function _infoLinkBlocks(array $colDef, array $languages, int|string $tab): string
    {
        $this->db->pushResult();
        $this->cms->select([
            'src_id' => $this->id,
            'relation' => (string)$colDef['name'],
            'sort' => '_custom',
            'order' => 'ASC',
            'limit' => (int)($this->config->get('admin.list_max_datasets') ?? 200),
        ]);
        $destIds = [];
        while ($row = $this->db->fetch()) $destIds[] = (int)$row['record_id'];
        $this->db->popResult();
        if (empty($destIds)) return '';

        $keep = [$this->id, $this->data, $this->areaId, $this->areaData];
        $access = $this->backend->access();
        $blocks = [];
        try {
            foreach ($destIds as $destId) {
                if (!$data = $this->record->load($destId)) continue;
                $areaId = (int)$data['area_id'];
                if (!$areaData = $this->backend->areaData($areaId)) continue;
                $this->id = $destId;
                $this->data = $data;
                $this->areaId = $areaId;
                $this->areaData = $areaData;
                $schemaVirtual = $this->record->virtualSchema($areaId, false);

                // write right on the linked record: edit button and pencil
                // behind "Original filename" (fileRename())
                $canWrite = $access->areaWrite($areaId, isset($data['owner_id']) ? (int)$data['owner_id'] : null);
                if ($canWrite) $this->backend->fileRenameIds[$destId] = true;

                $this->backend->infoPush();
                $this->_infoRows($schemaVirtual, $languages, $tab, 1);
                $rows = $this->backend->infoPop();

                // edit button at the top right of the block: opens the input mask of the
                // linked record as its own layer (saving/cancelling
                // close it and update this page)
                $button = '';
                if ($canWrite && $access->statusWrite((int)($data['status_id'] ?? 0))) {
                    $button = $this->backend->template('record.button_link_item_edit', [
                        '{area_id}' => $areaId,
                        '{id}' => $destId,
                    ]);
                }

                $blocks[] = $this->backend->template('record.info_link_block', [
                    '{button}' => $button,
                    '{rows}' => $rows
                ]);
            }
        } finally {
            [$this->id, $this->data, $this->areaId, $this->areaData] = $keep;
        }
        return implode("\n", $blocks);
    }

    /**
     * Linked records of a link field as a list of teasers
     * (Backend::teaser(): symbol of the area, default field, link to the
     * detail view), at most RELATED_MAX. If there are more, "more" does not lead to
     * a reload, but to the result list of all linked records
     * (RecordBackendList with src_id + relation) in a new layer.
     * Order as in the lists (Backend::relatedIds()).
     */
    private function _infoRelated(array $colDef): string
    {
        $name = (string)($colDef['name'] ?? '');
        [$ids, $more] = $this->backend->relatedIds($this->id, $colDef, self::RELATED_MAX);
        $items = [];
        foreach ($ids as $relatedId) {
            $teaser = $this->backend->teaser('record', $relatedId);
            if ($teaser !== '') $items[] = '<li>' . $teaser . '</li>';
        }
        if (!$items) return '';
        if ($more && preg_match('/^[a-z0-9_]+$/', $name)) {
            $items[] = '<li>' . $this->backend->template('link_add', [
                '{href}' => "Record/record_list.php?src_id={$this->id}&relation={$name}",
                '{text}' => '{#more}',
            ]) . '</li>';
        }
        return $this->backend->template('ul_unstyled', ['{items}' => implode("\n", $items)]);
    }

    /**
     * Meta fields tab: the fixed columns of the table `record`.
     *
     * The field list is stated here explicitly and in a fixed order - it should not
     * change when someone works on the schema, and the order is a content-based one
     * (belonging, visibility, tree, origin).
     *
     * Four fields are skipped if the area has no hierarchy:
     * position, parent_id, lft and depth (see class comment).
     *
     * Ids become links: status_id, created_by/changed_by/owner_id and
     * parent_id go through Backend::teaser(), template_id through _infoTemplate().
     * At the end "Used by": button to the list of all records that point
     * to this one.
     */
    private function _infoMeta(array $schemaVirtual): void
    {
        // Metafelder
        $this->backend->infoReset();
        $metaFields = [
            'record_id',
            'area_id',
            'instance_id',
            'online_at',
            'offline_at',
            'status_id',
            'depth',
            'template_id',
            'created_by',
            'changed_by',
            'owner_id',
            'user_id',
            'created_at',
            'changed_at',
        ];
        foreach ($metaFields as $name) {
            $flags = [];
            $value = $this->data[$name] ?? '';
            if ($name === 'area_id') {
                $value = $this->backend->teaser('area', $this->areaId)
                    . ' &middot; area_id : ' . $this->areaId;
                $flags = ['html'];
            }
            elseif ($name === 'instance_id') {
                $instanceId = (int)($this->data['instance_id'] ?? 0);
                $value = $this->backend->teaser('instance', $instanceId)
                    . ' &middot; instance_id : ' . $instanceId;
                $flags = ['html'];
            }
            elseif ($name === 'status_id') {
                $statusId = (int)($this->data[$name] ?? 0); 
                $value = $this->backend->teaser('status', $statusId);
                $flags = ['html'];
            }
            elseif (in_array($name, ['created_by', 'changed_by', 'owner_id'], true)) {
                $adminId = (int)($this->data[$name] ?? 0);
                if (!empty($adminId)) {
                    $value = $this->backend->teaser('admin', $adminId);
                    $flags = ['html'];
                }
            }
            elseif ($name === 'user_id') {
                // owner in the frontend (profile record), only if set
                $userId = (int)($this->data[$name] ?? 0);
                if (empty($userId)) continue;
                $value = $this->backend->teaser('record', $userId);
                $flags = ['html'];
            }
            elseif ($name === 'parent_id') {
                if (!isset($schemaVirtual['parent_id'])) continue;
                $recordId = (int)($this->data[$name] ?? 0);
                if (!empty($recordId)) {
                    $value = $this->backend->teaser('record', $recordId);
                    $flags = ['html'];
                }
            }
            elseif ($name === 'depth') {
                if (!isset($schemaVirtual['parent_id'])) continue;
            }
            elseif ($name === 'template_id') {
                $templateId = (int)($this->data['template_id'] ?? 0);
                $value = $this->_infoTemplate($templateId);
                $flags = ['html'];
            }
            $this->backend->infoAdd($this->record, $name, $value, $flags);
        }

        // "Used by": all records that point to this one via any link field -
        // global result list with relation=any + dest_id
        // (RecordBackendList::list(), Cms::filter()); not in lookup mode
        if ($this->srcField < 1) {
            $this->backend->infoStatic('#record.label_used_by', $this->backend->template('button_secondary', [
                '{onclick}' => "cApp.layers.add('Record/record_list.php?relation=any&dest_id={$this->id}')",
                '{label}' => htmlspecialchars($this->cms->snippet('record.button_references')),
            ]));
            // full text (standard field and full-text fields of the area): own page
            $this->backend->infoStatic('#area.label_field_fulltext', $this->backend->template('button_secondary', [
                '{onclick}' => "cApp.layers.add('Record/record_fulltext.php?id={$this->id}')",
                '{label}' => htmlspecialchars($this->cms->snippet('record.button_show_fulltext')),
            ]));
        }
        echo $this->backend->infoTable();
    }

    /**
     * "Show full text" (backend/record_fulltext.php, from the "Meta fields" tab):
     * what is in `data_ftx` for the record - first the standard full-text field,
     * then the full-text fields of the area, per readable language. Read only; the text
     * is created on saving (VirtualFieldsTrait::refreshStandardFulltext(),
     * refreshFulltext()). Right: read right on the area.
     *
     * Request: id
     */
    public function fulltext(): void
    {
        $this->backend->setNaviLabel('#area.label_field_fulltext');
        echo $this->backend->template('container_header');
        $this->id = (int)($_REQUEST['id'] ?? 0);
        if (!$this->data = $this->record->load($this->id)) {
            $this->errors();
            echo $this->backend->template('container_footer');
            return;
        }
        $this->areaId = (int)$this->data['area_id'];
        $access = $this->backend->access();
        if (!$access->areaRead($this->areaId)) {
            echo $access->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }

        echo $this->backend->headline('#area.label_field_fulltext');
        echo '<p>' . $this->backend->teaser('record', $this->id) . '</p>';
        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_close', "cApp.layers.close()");
        echo $this->backend->buttonBar();

        // standard field first, then the full-text fields of the area: fieldname_id => label
        $sources = [$this->record->standardFulltextFieldnameId() => '#area.label_field_fulltext'];
        foreach ($this->record->virtualSchema($this->areaId, false) as $name => $colDef) {
            if ((string)($colDef['type'] ?? '') !== 'fulltext') continue;
            $resolved = $this->cms->resolveVirtualField((string)$name);
            if ($resolved) $sources[(int)$resolved['fieldname_id']] = (string)(($colDef['label'] ?? '') ?: $name) . ' (' . $name . ')';
        }
        $languages = $this->record->readableLanguages($this->instanceCache->languages((int)($this->data['instance_id'] ?? 0)));
        $multilingual = count($languages) > 1;

        foreach ($sources as $fieldnameId => $label) {
            echo $this->backend->template('headline2_mt', ['{text}' => htmlspecialchars($this->cms->textOrSnippet($label))]);
            $this->db->query("SELECT `language_id`, `data` FROM `data_ftx` WHERE `record_id` = ? AND `fieldname_id` = ?", 'ii', [$this->id, $fieldnameId]);
            $texts = $this->db->fetchPairs();
            $this->backend->infoReset();
            $shown = 0;
            foreach ($languages as $languageId => $language) {
                $text = trim((string)($texts[$languageId] ?? ''));
                $code = (string)($language['code'] ?? $languageId);
                $this->backend->infoStatic(
                    $multilingual ? $this->cms->textOrSnippet((string)($language['label'] ?? $code)) . " ($code)" : '',
                    $text === '' ? $this->backend->emptyPlaceholder : '<small>' . htmlspecialchars($text) . '</small>',
                    $multilingual ? ['html'] : ['html', 'fullsize']
                );
                $shown++;
            }
            if ($shown > 0) echo $this->backend->infoTable();
        }
        echo $this->backend->template('alert_success', ['{text}' => '{#record.note_fulltext}']);
        echo $this->backend->template('container_footer');
    }

    /**
     * Template of the record as a linked label.
     *
     * A record does not have to have a template set - then that of the area applies.
     * This is shown here too: the area template with the addition
     * "(default)", so that it remains recognizable that it does not hang on the record. If the
     * area has none either, only the note remains.
     */
    private function _infoTemplate(int $templateId): string
    {
        if (!empty($templateId)) {
            $value = $this->backend->teaser('template', $templateId);
        }
        else {
            // determine the default template of the area
            $defaultTemplateId = (int) $this->areaData['default_template_id'];
            if (!empty($defaultTemplateId)) {
                $value = $this->backend->teaser('template', $defaultTemplateId)
                 . ' ' . $this->cms->snippet('record.default_template_label');
            }
            else {
                $value = $this->cms->snippet('record.default_template_none');
            }
        }
        return $value;
    }

    /**
     * Ajax: load a single row of the detail view afterwards.
     *
     * Counterpart to the flag 'expandable' in _infoContents(): the editor expands
     * a field, the JavaScript fetches the full content here and thereby replaces
     * the preview. Therefore only the row is output (template 'info_table_part'),
     * not the whole table.
     *
     * For the access check the record is fetched regularly via Record::load() - the
     * field name alone must not suffice to get at a content. It is additionally
     * filtered strictly to [a-z0-9_-].
     *
     * Request: id, name
     */
    public function fieldDetails() {
        $id = (int)($_REQUEST['id'] ?? 0);
        $name = preg_replace('/[^a-z0-9_]/', '', (string)($_REQUEST['name'] ?? ''));
        
        if (empty($id)) die('error');
        if (empty($name)) die('error');

        // access check via record->load()
        if (!$data = $this->record->load($id)) die('error');
        $areaId = (int)$data['area_id'];
        $schemaVirtual = $this->record->virtualSchema($areaId, false);
        $ownerId = isset($data['owner_id']) ? (int)$data['owner_id'] : null;
        if ($this->backend->access()->areaWrite($areaId, $ownerId)) {
            $this->backend->fileRenameIds[$id] = true;
        }

        // render the row
        $this->backend->infoAdd($this->record, $name, $data[$name], ['expandable'], $schemaVirtual, id: $id);

        // output without the table frame
        echo $this->backend->infoTable('info_table_part');
    }

    /**
     * Ajax (POST): change the original file name of a file - the pencil behind
     * "Original filename" on the info page (Backend::fileNameCell(),
     * cApp.ui.fileRename()). The extension remains, see Record::renameFile().
     *
     * Response: the content of the table cell - new name with pencil, on error
     * the message before it and the previous name.
     *
     * Request: id (productive record), field_id, lang (language of the file, ''
     * for single-language fields), name (new name without extension)
     */
    public function fileRename(): void
    {
        if (!$this->backend->requirePost()) {
            echo $this->backend->access()->deniedHtml();
            return;
        }
        $id = (int)($_REQUEST['id'] ?? 0);
        $fieldId = (int)($_REQUEST['field_id'] ?? 0);
        $lang = trim((string)($_REQUEST['lang'] ?? ''));
        $fileLanguageId = ($lang === '' || !ctype_digit($lang)) ? null : (int)$lang;
        if ($id < 1 || $fieldId < 1) die('error');

        // access check via record->load(), then write right on the area
        if (!$data = $this->record->load($id)) {
            $this->errors();
            return;
        }
        $areaId = (int)$data['area_id'];
        $ownerId = isset($data['owner_id']) ? (int)$data['owner_id'] : null;
        $access = $this->backend->access();
        if (!$access->areaWrite($areaId, $ownerId)) {
            echo $access->deniedHtml();
            return;
        }
        // the field must be a file field of this area
        $colDef = null;
        foreach ($this->record->virtualSchema($areaId, false) as $name => $def) {
            if ((int)($def['field_id'] ?? 0) === $fieldId && ($def['type'] ?? '') === 'file') {
                $colDef = $def;
                break;
            }
        }
        if ($colDef === null) die('error');

        $filename = $this->record->renameFile($id, $fieldId, $fileLanguageId, (string)($_REQUEST['name'] ?? ''));
        if ($filename === null) {
            $this->errors();
        }

        // output the cell anew with the current state
        $this->backend->fileRenameIds[$id] = true;
        echo $this->backend->fileNameCell(
            $this->record->fileRow($id, $fieldId, $fileLanguageId) ?? [], $id, $fieldId, $fileLanguageId
        );
    }

    /**
     * Creates the frontend path (without leading slash) from the record data
     * ($this->data) - Backend::buttonAddPreview() turns it into a preview
     * address per language (public/backend/preview.php: with admin session, also for
     * records that are not or no longer published).
     *
     * Always the record_id: the same path applies to all languages, but slug and
     * slug prefix can differ per language (the preview does not redirect
     * to the canonical URL).
     */
    private function createPreviewLink(): string
    {
        return (string)(int)$this->data['record_id'];
    }

    /**
     * Tab "Changes": the history of the record from the audit log.
     */
    private function _infoAudit(): void
    {
        echo $this->backend->auditHistory('record', $this->id);
    }

    /**
     * Second factor of a profile record (backend/record_second_factor.php,
     * UserSecondFactor): show the state and - with write right on the area,
     * via POST - reset it. For users who have lost their phone and recovery codes.
     * The reset is recorded in the audit log (acting party = admin).
     *
     * Request: id, area_id, function ('' | 'reset')
     */
    public function secondFactor(): void
    {
        echo $this->backend->template('container_header');
        $this->backend->setNaviLabel('#record.button_second_factor');
        $this->function = 'info';
        $this->_resolveParams();
        if ($this->function !== 'info' || !$this->data = $this->record->load($this->id)) {
            $this->errors();
            echo $this->backend->template('container_footer');
            return;
        }
        $this->areaId = (int)$this->data['area_id'];
        $access = $this->backend->access();
        if (!$access->areaRead($this->areaId)) {
            echo $access->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }
        echo $this->backend->template('headline1', [
            '{text}' => $this->backend->template('icon', ['{name}' => 'phonelink_lock']) . ' ' . $this->backend->escape($this->cms->snippet('record.button_second_factor')),
        ]);
        if ($this->_areaType() !== 'profile') {
            echo $this->backend->template('alert_danger', ['{text}' => '{#record.error_personal_data_no_profile}']);
            echo $this->backend->template('container_footer');
            return;
        }
        $ownerId = isset($this->data['owner_id']) ? (int)$this->data['owner_id'] : null;
        $canWrite = $access->areaWrite($this->areaId, $ownerId);

        if ((string)($_REQUEST['function'] ?? '') === 'reset') {
            if (!$canWrite || !$this->backend->requirePost()) {
                echo $access->deniedHtml();
                echo $this->backend->template('container_footer');
                return;
            }
            $this->userSecondFactor->disable($this->data, true);
            $this->backend->balloon('success', $this->cms->snippet('record.message_second_factor_reset'));
        }

        $state = $this->userSecondFactor->state($this->id);
        $this->db->query("SELECT `details` FROM `area` WHERE `area_id` = ?", 'i', [$this->areaId]);
        $policy = $this->userSecondFactor->policy(['details' => (string)($this->db->fetchNum()[0] ?? '')]);
        $since = $state['enabled_at'] !== '' ? $this->cms->_formatDate($state['enabled_at']) : '';
        // insert the placeholders ourselves (snippets only interpolate ICU patterns)
        $text = strtr(match ($state['method']) {
            'totp'  => $this->cms->snippet('record.second_factor_state_totp'),
            'email' => $this->cms->snippet('record.second_factor_state_email'),
            default => $this->cms->snippet('record.second_factor_state_none'),
        }, ['{date}' => $since, '{count}' => (string)(int)$state['recovery_left']]);
        echo $this->backend->template('record.second_factor_info', [
            '{state}'  => htmlspecialchars($text),
            '{policy}' => htmlspecialchars(str_replace('{policy}', $this->cms->snippet('area.option_second_factor_' . $policy),
                $this->cms->snippet('record.second_factor_policy'))),
        ]);
        if ($canWrite && $state['method'] !== '') {
            echo $this->backend->template('record.second_factor_reset_form', [
                '{id}' => (int)$this->id,
                '{area_id}' => (int)$this->areaId,
            ]);
        }
        echo $this->backend->template('container_footer');
    }

    /**
     * Menu item "Export of personal data" (module Privacy): profile area,
     * module access and additional right 'export' (superuser always).
     */
    private function _personalDataAllowed(): bool
    {
        $access = $this->backend->access();
        return $this->_areaType() === 'profile' && $access->module('Privacy')
            && ($access->isSuperuser() || $access->moduleRight('Privacy', 'export'));
    }

    /**
     * Type of the current area ('record', 'profile', 'ugc' …).
     */
    private function _areaType(): string
    {
        return (string)($this->instanceCache->areaInfo($this->areaId)['type'] ?? '');
    }
}
