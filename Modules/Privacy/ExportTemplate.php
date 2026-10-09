<?php
/**
 * Dataset-Kindklasse für die Tabelle `privacy_template` - Vorlagen der Datenauskunft
 * (Modul Privacy, Reiter "Vorlagen"). Aus ihr entsteht die Seite auskunft.html im Zip
 * des Datenexports (Camindo\Core\PrivacyTemplate::render()).
 *
 * AUFBAU
 * --------------------
 *     kind         user (Auskunft zu einem Website-User) oder admin (zu einem
 *                  Benutzer) - zwei getrennte Vorlagen, KINDS
 *     instance_id  Instanz, für deren Website-User bzw. Benutzer die Vorlage gilt
 *     scope_id     wofür genau: bei user ein Profil-Bereich der Instanz (area_id),
 *                  bei admin eine Benutzergruppe (group_id); 0 = alle. Beim Export
 *                  gewinnen die Vorlagen für den Bereich bzw. die Gruppe der Person,
 *                  sonst gelten die für alle (PrivacyBackend::_exportTemplates())
 *     language_id  Sprache - eine der Instanz; die Auskunft entsteht in dieser
 *                  Sprache (Snippets {#…} und Beschriftungen der Blöcke über
 *                  language.backend_code)
 *     content      die Vorlage: eine vollständige HTML-Seite mit Platzhaltern
 * Je Art, Instanz, Geltung und Sprache höchstens eine Vorlage
 * (uniq_kind_instance_scope_language).
 *
 * Ohne passende Vorlage gibt es keinen Datenexport (PrivacyBackend::export()). Eine
 * neue Vorlage ist mit der des Core vorbelegt
 * (Core/install/privacy/privacy_{art}_{sprache}.html, sonst …_en_US.html).
 *
 * WER DARF WAS
 * --------------------
 * Ansehen darf jeder mit Zugang zum Modul (Vorlagen der lesbaren Instanzen); anlegen,
 * ändern und löschen, wer das Zusatzrecht template_edit hat und die Instanz
 * bearbeiten darf - der Superuser immer (canEdit()). Die Vorlage ist vertrauenswürdig
 * wie ein Template: kein HTML-Filter beim Speichern ($sanitizeHtml = false); Werte
 * aus der Datenbank setzt PrivacyTemplate nur maskiert ein.
 *
 * @see Camindo\Core\PrivacyTemplate                  Platzhalter und Bedingungen
 * @see Camindo\Core\Modules\Privacy\PrivacyBackend   Oberfläche
 */
namespace Camindo\Core\Modules\Privacy;

use Camindo\Core\Access;
use Camindo\Core\Dataset;
use Camindo\Core\InstanceCache;

class ExportTemplate extends Dataset {

    /** Arten: Auskunft zu einem Website-User bzw. zu einem Benutzer */
    public const KINDS = ['user', 'admin'];

    /** Vollständige HTML-Seite - der HTML-Filter würde sie zerlegen */
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
     * Sprache muss zur Instanz gehören; je Instanz und Sprache nur eine Vorlage.
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
     * Sprachen einer Instanz zur Auswahl.
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
     * Wofür eine Vorlage gelten kann: bei user die Profil-Bereiche der Instanz, bei
     * admin die Benutzergruppen - jeweils mit 0 = alle zuerst.
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
     * Backend-Sprache, in der die Auskunft entsteht (language.backend_code,
     * Cms::resolveBackendLang()).
     */
    public function backendLang(int $instanceId, int $languageId): string
    {
        $language = $this->container->get(InstanceCache::class)->instance($instanceId)['languages'][$languageId] ?? [];
        return $this->cms->resolveBackendLang((string)(($language['backend_code'] ?? '') ?: ($language['code'] ?? '')));
    }

    /**
     * Vorlagen einer Art und Instanz (ohne Inhalt), nach Sprachposition.
     *
     * @return array  Liste aus privacy_template_id, kind, instance_id, scope_id, language_id
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
     * Darf der angemeldete Admin Vorlagen dieser Instanz anlegen, ändern, löschen?
     */
    public function canEdit(int $instanceId): bool
    {
        $access = $this->container->get(Access::class);
        if (!$access->isLoaded() || $access->isSuperuser()) return true;
        return $access->moduleRight('Privacy', 'template_edit') && $access->instance($instanceId);
    }

    /**
     * Vorbelegung einer neuen Vorlage: die des Core für diese Art in der Sprache,
     * sonst englisch.
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
