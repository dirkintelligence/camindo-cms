<?php
/**
 * Dataset child class for the table `language` - the languages in which the frontend
 * is delivered.
 *
 * Not to be confused with the backend language snippets: those are stored as
 * PHP files in the lang/ directories and fetched via Cms::snippet().
 * This table describes only the languages of the website content.
 *
 * The `code` becomes the first URL segment in the frontend (/de/..., /en/...); the
 * rewrite rule in public/.htaccess (docroot) splits it off and passes it as its own
 * parameter to Page::resolveRequest(). `endonym` is the native name of the
 * language ("Deutsch" instead of "German") - it belongs in a language switcher.
 *
 * TWO SETTINGS FOR RUNTIME
 * --------------------
 *     backend_code   backend language (snippets) for this content language, e.g.
 *                    de_AT -> de_DE. The messages of the CMS commands come from
 *                    Cms::snippet() - so they appear in the frontend in the language
 *                    of the page. Choice from Cms::backendLanguages(), required.
 *     timezone       time zone in which date and time values of this language version
 *                    are output; empty = system.timezone from the configuration.
 * Cms::setLanguage() sets both as soon as Page knows the language of the request;
 * the values are kept for this in the instance file cache (InstanceCache).
 *
 * @see Camindo\Core\Modules\Language\LanguageBackend
 * @see Camindo\Core\Dataset
 */
namespace Camindo\Core\Modules\Language;

use Camindo\Core\Cms;
use Camindo\Core\Collation;
use Camindo\Core\Dataset;
use Camindo\Core\InstanceCache;
use Camindo\Core\LanguageData;

class Language extends Dataset {
    
    protected function hookDefinition(): void
    {
        $this->tableName  = 'language';
        $this->moduleName = 'Language';
        $this->defaultSortOrder = 'position ASC';
        $this->schema = [
            'language_id' => [
                'type'  => 'integer',
                'label' => '#language.field_language_id',
            ],
            'code' => [
                'type'  => 'chars',
                'label' => '#language.field_code',
                'note' => '#language.note_field_code',
                'max'   => 5,
                'flags' => ['trim', 'not_empty'],
            ],
            'backend_code' => [
                'type'  => 'chars',
                'display_type' => 'select',
                'label' => '#language.field_backend_code',
                'tooltip' => '#language.tooltip_field_backend_code',
                'max'   => 8,
                'flags' => ['trim', 'not_empty'],
                'default' => Cms::FALLBACK_LANG,
            ],
            'label' => [
                'type'  => 'chars',
                'label' => '#language.field_label',
                'max'   => 64,
                'extension' => 'Snippets',   // Core extension: select a snippet (#prefix.key)
                'flags' => ['trim', 'not_empty'],
            ],
            'endonym' => [
                'type'  => 'chars',
                'label' => '#language.field_endonym',
                'max'   => 64,
                'flags' => ['trim', 'not_empty'],
            ],
            'timezone' => [
                'type'  => 'chars',
                'display_type' => 'select',
                'label' => '#language.field_timezone',
                'tooltip' => '#language.tooltip_field_timezone',
                'max'   => 64,
                'flags' => ['trim'],
            ],
            'position' => [
                'type'  => 'integer',
                'label' => '#language.field_position',
                'display_type' => 'select',
            ],
        ];
    }

    /**
     * Before creating: put the new language at the end of the sort order.
     * The position determines, among other things, the order in the language switcher.
     */
    protected function hookOnCreate(array &$data): void
    {
        $sql = "SELECT COALESCE(MAX(`position`), 0) + 1 FROM `language`";
        $this->db->query($sql);
        $data['position'] = $this->db->fetchNum()[0] ?? 0;
    }

    /**
     * Before saving: check backend_code and timezone against the actual
     * options - a selection list is no protection against a
     * manipulated form, and both values later go unchecked to
     * date_default_timezone_set() or to the snippet cache.
     */
    protected function hookOnSave(array &$fields, array &$data, array &$previousData): void
    {
        if (empty($fields) || in_array('backend_code', $fields, true)) {
            $code = trim((string)($data['backend_code'] ?? ''));
            // Raise earlier two-letter codes ("de") to the current one ("de_DE")
            if (preg_match('/^[a-z]{2,3}$/', $code)) {
                $code = Cms::matchLanguage($code, $this->cms->backendLanguages()) ?? $code;
                $data['backend_code'] = $code;
            }
            if ($code !== '' && !in_array($code, $this->cms->backendLanguages(), true)) {
                $this->createFieldError('error_invalid_format', 'backend_code', $this->schema['backend_code']['label']);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
        }
        if (empty($fields) || in_array('timezone', $fields, true)) {
            $timezone = trim((string)($data['timezone'] ?? ''));
            if ($timezone !== '' && !$this->cms->isValidTimezone($timezone)) {
                $this->createFieldError('error_invalid_format', 'timezone', $this->schema['timezone']['label']);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
        }
    }

    /**
     * After saving: renew the cache files of the instances.
     *
     * The files instance-{id}.php list code, label and endonym for each language
     * (see InstanceCache) - a change here must arrive there.
     */
    protected function hookOnSaved(array &$fields, array &$data, array &$previousData): void
    {
        $this->instanceCache()->rebuild();

        // Different code with other rules: the full text of the language is folded by the old
        // rules (Collation::fold()) - mark it as stale in every instance that uses it
        $languageId = (int)($data['language_id'] ?? $previousData['language_id'] ?? 0);
        $oldCode = (string)($previousData['code'] ?? '');
        $newCode = (string)($data['code'] ?? $oldCode);
        if ($languageId > 0 && $oldCode !== '' && !Collation::sameRules($oldCode, $newCode)) {
            foreach ($this->instancesWith($languageId) as $instanceId) {
                $this->container->get(LanguageData::class)->markFulltextStale($instanceId);
            }
        }
    }

    /** Instances that use a language (as main or additional language) */
    private function instancesWith(int $languageId): array
    {
        $this->db->query(
            "SELECT `instance_id` FROM `instance` WHERE `primary_language_id` = ?"
            . " UNION SELECT `instance_id` FROM `rel_instance_language` WHERE `language_id` = ?",
            'ii', [$languageId, $languageId]
        );
        return array_map('intval', $this->db->fetchAll());
    }

    protected function hookOnLoaded(array &$data): void
    {
    }

    /**
     * Instances that use a language as main language or as additional language -
     * for the warning before deleting.
     *
     * @return array  ['primary' => [instance_id => label], 'additional' => [...]]
     */
    public function usage(int $languageId): array
    {
        $usage = ['primary' => [], 'additional' => []];
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT `instance_id`, `label` FROM `instance` WHERE `primary_language_id` = ? ORDER BY `position`",
                'i', [$languageId]
            );
            foreach ($this->db->fetchPairs() as $id => $label) {
                $usage['primary'][(int)$id] = $this->cms->textOrSnippet((string)$label);
            }
            $this->db->query(
                "SELECT `i`.`instance_id`, `i`.`label` FROM `rel_instance_language` `r`"
                . " INNER JOIN `instance` `i` ON `i`.`instance_id` = `r`.`instance_id`"
                . " WHERE `r`.`language_id` = ? ORDER BY `i`.`position`",
                'i', [$languageId]
            );
            foreach ($this->db->fetchPairs() as $id => $label) {
                $usage['additional'][(int)$id] = $this->cms->textOrSnippet((string)$label);
            }
        }
        finally {
            $this->db->popResult();
        }
        return $usage;
    }

    /**
     * Before deleting: delete the contents of the language in all instances.
     *
     * Runs in the transaction of Dataset::delete() - if a step throws, the
     * language remains (already deleted files then stay gone).
     *
     * A language may not be the main language of an instance: `instance`.`primary_language_id`
     * has no foreign key, otherwise the instance would point to nothing - first choose
     * another main language there. Otherwise LanguageData::deleteLanguage(): texts, numbers,
     * files, full text, content cache and privacy templates of the
     * language disappear from all instances.
     */
    protected function hookOnDelete(array $data): void
    {
        $languageId = (int)($data['language_id'] ?? 0);

        $primary = $this->usage($languageId)['primary'];
        if (!empty($primary)) {
            $errorMsg = str_replace(
                '{instances}',
                implode(', ', $primary),
                $this->cms->snippet('language.error_primary_language_in_use')
            );
            $this->cms->error($errorMsg);
            throw new \Exception('#' . $errorMsg);
        }

        $this->container->get(LanguageData::class)->deleteLanguage($languageId);
    }

    /**
     * After deleting: renew the cache files of the instances.
     */
    protected function hookOnDeleted(array $data): void
    {
        $this->instanceCache()->rebuild();
    }

    // Special functions for Language
    // --------------------

    /**
     * File cache of the instances (see Camindo\Core\InstanceCache).
     */
    private function instanceCache(): InstanceCache
    {
        return $this->container->get(InstanceCache::class);
    }

}