<?php
/**
 * Dataset child class for the table `privacy_template` - templates of the data disclosure
 * (module Privacy, tab "Templates"). The page auskunft.html in the zip
 * of the data export is created from it (Camindo\Core\PrivacyTemplate::render()).
 *
 * STRUCTURE
 * --------------------
 *     kind         user (disclosure about a website user) or admin (about a
 *                  user) - two separate templates, KINDS
 *     instance_id  instance for whose website users resp. users the template applies
 *     scope_id     for exactly what: for user a profile area of the instance (area_id),
 *                  for admin a user group (group_id); 0 = all. On export
 *                  the templates for the area resp. the group of the person win,
 *                  otherwise those for all apply (PrivacyBackend::_exportTemplates())
 *     language_id  language - one of the instance; the disclosure is created in this
 *                  language (snippets {#…} and labels of the blocks via
 *                  language.backend_code)
 *     content      the template: a complete HTML page with placeholders
 * At most one template per kind, instance, scope and language
 * (uniq_kind_instance_scope_language).
 *
 * Without a matching template there is no data export (PrivacyBackend::export()). A
 * new template is prefilled with the one of the core
 * (Core/install/privacy/privacy_{art}_{sprache}.html, otherwise …_en_US.html).
 *
 * WHO MAY DO WHAT
 * --------------------
 * Everyone with access to the module may view (templates of the readable instances); whoever has the
 * additional right template_edit and may edit the instance may create, change and
 * delete - the superuser always (canEdit()). The template is trusted
 * like a template: no HTML filter on saving ($sanitizeHtml = false); values
 * from the database are only inserted masked by PrivacyTemplate.
 *
 * @see Camindo\Core\PrivacyTemplate                  Placeholders and conditions
 * @see Camindo\Core\Modules\Privacy\PrivacyBackend   Interface
 */
namespace Camindo\Core\Modules\Privacy;

use Camindo\Core\Access;
use Camindo\Core\Dataset;
use Camindo\Core\InstanceCache;

class ExportTemplate extends Dataset {

    /** Kinds: disclosure about a website user resp. about a user */
    public const KINDS = ['user', 'admin'];

    /** Complete HTML page - the HTML filter would break it apart */
    protected bool $sanitizeHtml = false;

    protected function hookDefinition(): void
    {
        $this->tableName  = 'privacy_template';
        $this->moduleName = 'Privacy';
        $this->defaultSortOrder = 'instance_id ASC, language_id ASC';
        $this->schema = [
            'privacy_template_id' => [
                'type'  => 'integer',
                'label' => 'privacy_template_id',
            ],
            'kind' => [
                'type'  => 'chars',
                'display_type' => 'select',
                'label' => '#privacy.field_kind',
                'tooltip' => '#privacy.tooltip_template_kind',
                'max'   => 8,
                'flags' => ['not_empty'],
            ],
            'instance_id' => [
                'type'  => 'integer',
                'display_type' => 'select',
                'label' => '#privacy.field_instance_id',
                'tooltip' => '#privacy.tooltip_template_instance',
                'min'   => 0,
                'flags' => ['not_empty'],
            ],
            'scope_id' => [
                'type'  => 'integer',
                'display_type' => 'select',
                'label' => '#privacy.field_scope_id',
                'tooltip' => '#privacy.tooltip_template_scope',
                'min'   => 0,
            ],
            'language_id' => [
                'type'  => 'integer',
                'display_type' => 'select',
                'label' => '#privacy.field_language_id',
                'tooltip' => '#privacy.tooltip_template_language',
                'min'   => 1,
                'flags' => ['not_empty'],
            ],
            'content' => [
                'type'  => 'text',
                'display_type' => 'textarea',
                'label' => '#privacy.field_content',
                'note'  => '#privacy.note_template_content',
                'tooltip' => '#privacy.tooltip_template_content',
                'height' => 30,
                'flags' => ['not_empty'],
            ],
        ];
    }

    /**
     * Language must belong to the instance; only one template per instance and language.
     */
    protected function hookOnSave(array &$fields, array &$data, array &$previousData): void
    {
        $kind = (string)($data['kind'] ?? $previousData['kind'] ?? '');
        if (!in_array($kind, self::KINDS, true)) throw new \Exception($this->cms->snippet('privacy.error_template_kind'));
        $instanceId = (int)($data['instance_id'] ?? $previousData['instance_id'] ?? 0);
        $languageId = (int)($data['language_id'] ?? $previousData['language_id'] ?? 0);
        if (!isset($this->languageOptions($instanceId)[$languageId])) {
            throw new \Exception($this->cms->snippet('privacy.error_template_language'));
        }
        $scopeId = (int)($data['scope_id'] ?? $previousData['scope_id'] ?? 0);
        if (!isset($this->scopeOptions($kind, $instanceId)[$scopeId])) {
            throw new \Exception($this->cms->snippet('privacy.error_template_scope'));
        }
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `privacy_template_id` FROM `privacy_template` WHERE `kind` = ? AND `instance_id` = ? AND `scope_id` = ? AND `language_id` = ? AND `privacy_template_id` <> ?",
                'siiii', [$kind, $instanceId, $scopeId, $languageId, (int)($data['privacy_template_id'] ?? 0)]);
            $exists = $this->db->num_rows > 0;
        }
        finally {
            $this->db->popResult();
        }
        if ($exists) throw new \Exception($this->cms->snippet('privacy.error_template_exists'));
    }

    /**
     * Languages of an instance to choose from.
     *
     * @return array  language_id => Bezeichnung
     */
    public function languageOptions(int $instanceId): array
    {
        $options = [];
        foreach ($this->container->get(InstanceCache::class)->instance($instanceId)['languages'] ?? [] as $languageId => $language) {
            $options[(int)$languageId] = $this->cms->textOrSnippet((string)($language['label'] ?? '')) ?: (string)($language['code'] ?? $languageId);
        }
        return $options;
    }

    /**
     * What a template may apply to: for user the profile areas of the instance, for
     * admin the user groups - each with 0 = all first.
     *
     * @return array  scope_id => Bezeichnung
     */
    public function scopeOptions(string $kind, int $instanceId): array
    {
        $options = [0 => $this->cms->snippet($kind === 'admin' ? 'privacy.option_scope_all_groups' : 'privacy.option_scope_all_areas')];
        $this->db->pushResult();
        try {
            if ($kind === 'admin') {
                $this->db->query("SELECT `group_id`, `label` FROM `group` ORDER BY `group_id`");
            }
            else {
                $this->db->query("SELECT `area_id`, `label`, `name` FROM `area` WHERE `type` = 'profile' AND `deleted` = 0 AND `instance_id` = ? ORDER BY `lft`",
                    'i', [$instanceId]);
            }
            while ($row = $this->db->fetchNum()) {
                $options[(int)$row[0]] = $this->cms->textOrSnippet((string)(($row[1] ?? '') ?: ($row[2] ?? $row[0])));
            }
        }
        finally {
            $this->db->popResult();
        }
        return $options;
    }

    /**
     * Backend language in which the disclosure is created (language.backend_code,
     * Cms::resolveBackendLang()).
     */
    public function backendLang(int $instanceId, int $languageId): string
    {
        $language = $this->container->get(InstanceCache::class)->instance($instanceId)['languages'][$languageId] ?? [];
        return $this->cms->resolveBackendLang((string)(($language['backend_code'] ?? '') ?: ($language['code'] ?? '')));
    }

    /**
     * Templates of a kind and instance (without content), by language position.
     *
     * @return array  list of privacy_template_id, kind, instance_id, scope_id, language_id
     */
    public function forInstance(string $kind, int $instanceId): array
    {
        $order = array_keys($this->languageOptions($instanceId));
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `privacy_template_id`, `kind`, `instance_id`, `scope_id`, `language_id` FROM `privacy_template` WHERE `kind` = ? AND `instance_id` = ?",
                'si', [$kind, $instanceId]);
            $rows = $this->db->fetchAll();
        }
        finally {
            $this->db->popResult();
        }
        $position = array_flip($order);
        usort($rows, fn($a, $b) => [(int)$a['scope_id'], $position[(int)$a['language_id']] ?? 999] <=> [(int)$b['scope_id'], $position[(int)$b['language_id']] ?? 999]);
        return $rows;
    }

    /**
     * May the logged-in admin create, change and delete templates of this instance?
     */
    public function canEdit(int $instanceId): bool
    {
        $access = $this->container->get(Access::class);
        if (!$access->isLoaded() || $access->isSuperuser()) return true;
        return $access->moduleRight('Privacy', 'template_edit') && $access->instance($instanceId);
    }

    /**
     * Prefill of a new template: the one of the core for this kind in the language,
     * otherwise English.
     */
    public function defaultContent(string $kind, string $backendLang): string
    {
        $dir = rtrim((string)$this->config->get('paths.core_dir'), '/\\') . '/install/privacy/';
        $kind = in_array($kind, self::KINDS, true) ? $kind : 'user';
        foreach ([$backendLang, \Camindo\Core\PrivacyTemplate::FALLBACK] as $lang) {
            $file = $dir . 'privacy_' . $kind . '_' . preg_replace('/[^A-Za-z_]/', '', $lang) . '.html';
            if (is_file($file)) return (string)file_get_contents($file);
        }
        return '';
    }
}
