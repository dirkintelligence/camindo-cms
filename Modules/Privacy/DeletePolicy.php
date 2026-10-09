<?php
/**
 * Dataset child class for the table `privacy_policy` - deletion policies (module Privacy).
 *
 * Each content area (record, profile, ugc) has exactly one policy
 * (area.privacy_policy_id). It determines what happens when a record of the
 * area is deleted - executed by PrivacyJobs.
 *
 * STRUCTURE
 * --------------------
 *     label        label (text or snippet #prefix.key)
 *     instance_id  instance (tenant) for which the policy can be selected;
 *                  ALL_INSTANCES (-1) = for all (the three default policies). Not
 *                  NULL: instance 0 is the default instance, the type 'id' would turn 0
 *                  into NULL. No foreign key - the policy of a deleted
 *                  instance remains until someone deletes it.
 *     standard     '' or record | profile | ugc: default policy of this
 *                  area type - not deletable, but changeable; new areas
 *                  are prefilled with them (Area::hookOnSave())
 *     blocks       JSON list of the blocks, per block:
 *         sources  triggers, at least one of SOURCES:
 *                      record   the record is deleted - no matter by which way
 *                               (mask, batch processing, API, CMS:USER_DELETE),
 *                               also as a sub-record (child in the tree, firmly
 *                               linked content) of a deleted record
 *                      profile  the profile of the user (record.user_id) was deleted -
 *                               always applies to all its records, without its own
 *                               action in the policy of the profile
 *                      area     the area was deleted - its records already lie
 *                               in the trash, therefore neither soft_delete nor
 *                               set_fields (AREA_ACTIONS)
 *         delay    point in time from triggering, 0 = immediately
 *         unit     days | years
 *         action   one of ACTIONS (groups in the selection: ACTION_GROUPS):
 *                      soft_delete       to the trash - finally deleted after
 *                                        admin.trash_retention_days (scheduler, type trash)
 *                      set_fields        overwrite fields of the record
 *                      record_trigger    call onPrivacyAction() of the trigger template with
 *                                        the record; what it changes in $data
 *                                        is saved
 *                      audit_set_fields  overwrite fields in the audit log
 *                      audit_trigger     onPrivacyAction() for each entry in the
 *                                        audit log of the record; what it changes
 *                                        in the entry is written back
 *                      audit_delete      delete the audit log of the record
 *                      purge             finally delete, including the audit log
 *                  An audit log entry names actors only as an id (AuditLog). The
 *                  link to the user therefore always remains; it becomes anonymous
 *                  as soon as the profile record no longer contains personal data
 *                  resp. is finally deleted.
 *         category category of the block (CATEGORIES), only for classification - without
 *                  function when running; '' = none
 *         trigger  for record_trigger / audit_trigger: identifier (TRIGGER_PATTERN) that
 *                  the template receives as $action - this is how it distinguishes the
 *                  blocks
 *         fields   for set_fields / audit_set_fields: field => value. Field is the
 *                  field name (status_id, username …) - for multilingual fields all
 *                  languages; an upload field only accepts "" (remove the file including
 *                  thumbnails immediately). In the audit log additionally _label (label of the
 *                  entry) and * (all other fields - same wherever it stands). In the value: {record_id}, {user_id}.
 * Whether blocks contradict each other or are duplicated is checked by nobody - that is the
 * responsibility of the one who sets up the policy.
 *
 * WHO MAY DO WHAT
 * --------------------
 * Whoever has the additional right policy_edit of the module Privacy
 * and may edit the instance maintains the policies of an instance; policies for all instances (including the
 * default policies) only the superuser (canEdit()). A policy can only be deleted
 * if it is not a default policy and no area uses it.
 *
 * @see Camindo\Core\Modules\Privacy\PrivacyJobs     Execution
 * @see Camindo\Core\Modules\Privacy\PrivacyBackend  Interface
 */
namespace Camindo\Core\Modules\Privacy;

use Camindo\Core\Access;
use Camindo\Core\Database;
use Camindo\Core\Dataset;

class DeletePolicy extends Dataset {

    public const SOURCES = ['record', 'profile', 'area'];

    /** Actions in the audit log */
    public const AUDIT_ACTIONS = ['audit_trigger', 'audit_set_fields', 'audit_delete'];

    /** Actions in the order in which they run at the same point in time */
    public const ACTIONS = ['record_trigger', 'set_fields', 'soft_delete', 'purge',
        'audit_trigger', 'audit_set_fields', 'audit_delete'];

    /** Actions in the selection of the form, grouped (snippet privacy.action_group_{group}) */
    public const ACTION_GROUPS = [
        'record' => ['soft_delete', 'set_fields', 'record_trigger'],
        'audit'  => ['audit_set_fields', 'audit_trigger', 'audit_delete'],
        'all'    => ['purge'],
    ];

    /** Actions that remove the record itself - for sub-records the main record takes them over */
    public const REMOVE_ACTIONS = ['soft_delete', 'purge'];

    /** Actions for the trigger 'area': the records already lie in the trash */
    public const AREA_ACTIONS = ['record_trigger', 'purge', 'audit_trigger', 'audit_set_fields', 'audit_delete'];

    /**
     * Categories of a block (classification without function): to the trash, remove
     * no longer needed data (data minimization), fully anonymize,
     * finally delete
     */
    public const CATEGORIES = ['trash', 'minimize', 'anonymize', 'delete'];

    /** Actions that affect the database (note in the delete dialog) */
    public const DB_ACTIONS = ['record_trigger', 'set_fields', 'soft_delete', 'purge'];

    /** Actions that call the method onPrivacyAction() of the trigger template */
    public const TRIGGER_ACTIONS = ['record_trigger', 'audit_trigger'];

    /** Identifier that a trigger action passes to the template ($action) */
    public const TRIGGER_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    public const UNITS = ['days', 'years'];

    /** instance_id of a policy for all instances */
    public const ALL_INSTANCES = -1;

    /** Area types with default policy */
    public const TYPES = ['record', 'profile', 'ugc'];

    /**
     * The three default policies - created at installation (seed.sql) and with the
     * migration 9.1.0 (ensureStandard()).
     */
    public const STANDARD = [
        'record' => [
            'label' => '#privacy.policy_record',
            'blocks' => [
                ['sources' => ['record', 'profile'], 'delay' => 0, 'unit' => 'days', 'action' => 'soft_delete', 'category' => 'trash', 'fields' => []],
                ['sources' => ['record', 'profile', 'area'], 'delay' => 1, 'unit' => 'years', 'action' => 'audit_delete', 'category' => 'delete', 'fields' => []],
            ],
        ],
        'profile' => [
            'label' => '#privacy.policy_profile',
            'blocks' => [
                ['sources' => ['record'], 'delay' => 0, 'unit' => 'days', 'action' => 'soft_delete', 'category' => 'trash', 'fields' => []],
                ['sources' => ['record', 'area'], 'delay' => 7, 'unit' => 'days', 'action' => 'audit_set_fields', 'category' => 'anonymize',
                    'fields' => ['_label' => 'user_{record_id}', '*' => '']],
                ['sources' => ['record', 'area'], 'delay' => 1, 'unit' => 'years', 'action' => 'audit_delete', 'category' => 'delete', 'fields' => []],
            ],
        ],
        'ugc' => [
            'label' => '#privacy.policy_ugc',
            'blocks' => [
                ['sources' => ['record', 'profile'], 'delay' => 0, 'unit' => 'days', 'action' => 'soft_delete', 'category' => 'trash', 'fields' => []],
                ['sources' => ['record', 'profile', 'area'], 'delay' => 1, 'unit' => 'years', 'action' => 'audit_delete', 'category' => 'delete', 'fields' => []],
            ],
        ],
    ];

    /**
     * Fields of a block in the form (Backend::formField() with varName 'blocks',
     * name '{i}.{field}' -> blocks[{i}][{field}]). Options are set by PrivacyBackend.
     */
    public const BLOCK_SCHEMA = [
        'sources' => [
            'type' => 'json', 'display_type' => 'checkbox',
            'label' => '#privacy.label_sources',
            'tooltip' => '#privacy.tooltip_sources',
        ],
        'delay' => [
            'type' => 'integer', 'display_type' => 'text',
            'label' => '#privacy.label_when',
            'note' => '#privacy.note_delay',
            'min' => 0, 'max' => 1000,
        ],
        'unit' => [
            'type' => 'chars', 'display_type' => 'select',
            'label' => '#privacy.label_unit',
        ],
        'category' => [
            'type' => 'chars', 'display_type' => 'select',
            'label' => '#privacy.label_category',
            'tooltip' => '#privacy.tooltip_category',
        ],
        'action' => [
            'type' => 'chars', 'display_type' => 'select',
            'label' => '#privacy.label_action',
            'flags' => ['not_empty'],
        ],
        'fields' => [
            'type' => 'text', 'display_type' => 'textarea',
            'label' => '#privacy.label_fields',
            'note' => '#privacy.note_fields_short',
            'tooltip' => '#privacy.note_fields',
            'height' => 3,
        ],
        'trigger' => [
            'type' => 'chars', 'display_type' => 'text',
            'label' => '#privacy.label_trigger',
            'note' => 'a..z 0..9 _',
            'tooltip' => '#privacy.note_trigger',
            'pattern' => '[a-z][a-z0-9_]*',
            'max' => 64,
        ],
    ];

    protected function hookDefinition(): void
    {
        $this->tableName  = 'privacy_policy';
        $this->moduleName = 'Privacy';
        $this->defaultSortOrder = 'label ASC';
        $this->schema = [
            'privacy_policy_id' => [
                'type'  => 'integer',
                'label' => 'privacy_policy_id',
            ],
            'instance_id' => [
                'type'  => 'integer',
                'display_type' => 'select',
                'label' => '#privacy.field_instance_id',
                'tooltip' => '#privacy.tooltip_field_instance_id',
                'min'   => self::ALL_INSTANCES,
                'default' => self::ALL_INSTANCES,
            ],
            'label' => [
                'type'  => 'chars',
                'label' => '#privacy.field_label',
                'max'   => 128,
                'extension' => 'Snippets',   // Core extension: select snippet (#prefix.key)
                'flags' => ['trim', 'not_empty'],
            ],
            'standard' => [
                'type'  => 'chars',
                'label' => '#privacy.field_standard',
                'max'   => 8,
            ],
            'blocks' => [
                'type'  => 'json',
                'label' => '#privacy.field_blocks',
            ],
        ];
    }

    /**
     * Bring blocks into the fixed form before saving (normalizeBlocks()); a
     * default policy stays a default policy and applies to all instances.
     */
    protected function hookOnSave(array &$fields, array &$data, array &$previousData): void
    {
        if (in_array('blocks', $fields, true)) {
            $data['blocks'] = self::normalizeBlocks((array)($data['blocks'] ?? []));
        }
        if (!empty($previousData['standard'])) {
            $data['standard'] = (string)$previousData['standard'];
            $data['instance_id'] = self::ALL_INSTANCES;
            foreach (['standard', 'instance_id'] as $name) {
                if (!in_array($name, $fields, true)) $fields[] = $name;
            }
        }
    }

    /**
     * Delete only if unused and not for default policies.
     */
    protected function hookOnDelete(array $data): void
    {
        if ((string)($data['standard'] ?? '') !== '') {
            throw new \Exception($this->cms->snippet('privacy.error_delete_standard'));
        }
        if ($this->usageCount((int)$data['privacy_policy_id']) > 0) {
            throw new \Exception($this->cms->snippet('privacy.error_delete_used'));
        }
    }

    /**
     * Clean up blocks: only known triggers, units and actions ('area' only
     * with AREA_ACTIONS); point in time
     * integer from 0; fields only for the actions that need them. Blocks without
     * trigger or action are dropped, likewise trigger actions without a valid identifier
     * (the form reports this beforehand - PrivacyBackend::edit()).
     *
     * @param array $blocks  list as in the table or from the form
     * @return array
     */
    public static function normalizeBlocks(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) continue;
            $sources = array_values(array_intersect(self::SOURCES, array_map('strval', (array)($block['sources'] ?? []))));
            $action = (string)($block['action'] ?? '');
            // Area deleted: the records already lie in the trash
            if (!in_array($action, self::AREA_ACTIONS, true)) $sources = array_values(array_diff($sources, ['area']));
            if (!$sources || !in_array($action, self::ACTIONS, true)) continue;
            $unit = in_array($block['unit'] ?? '', self::UNITS, true) ? (string)$block['unit'] : 'days';
            $fields = [];
            $trigger = '';
            if (in_array($action, self::TRIGGER_ACTIONS, true)) {
                $trigger = strtolower(trim((string)($block['trigger'] ?? '')));
                if (!preg_match(self::TRIGGER_PATTERN, $trigger)) continue;
            }
            if (in_array($action, ['set_fields', 'audit_set_fields'], true)) {
                $fields = is_array($block['fields'] ?? null) ? $block['fields'] : self::parseFields((string)($block['fields'] ?? ''));
                $clean = [];
                foreach ($fields as $name => $value) {
                    $name = trim((string)$name);
                    if ($name === '' || !preg_match('/^(\*|_label|[a-z][a-z0-9_]*)$/', $name)) continue;
                    $clean[$name] = is_scalar($value) ? (string)$value : '';
                }
                $fields = $clean;
            }
            $category = (string)($block['category'] ?? '');
            $out[] = [
                'sources' => $sources,
                'category' => in_array($category, self::CATEGORIES, true) ? $category : '',
                'delay'   => max(0, min(1000, (int)($block['delay'] ?? 0))),
                'unit'    => $unit,
                'action'  => $action,
                'fields'  => $fields,
                'trigger' => $trigger,
            ];
        }
        return $out;
    }

    /**
     * Fields from the input "field = value" (one line per field; value without or in
     * quotation marks) - form of the policy.
     */
    public static function parseFields(string $text): array
    {
        $fields = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (!str_contains($line, '=')) continue;
            [$name, $value] = array_map('trim', explode('=', $line, 2));
            if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) $value = substr($value, 1, -1);
            $fields[$name] = $value;
        }
        return $fields;
    }

    /** Counterpart to parseFields(): fields as text for the form */
    public static function fieldsText(array $fields): string
    {
        $lines = [];
        foreach ($fields as $name => $value) $lines[] = $name . ' = "' . $value . '"';
        return implode("\n", $lines);
    }

    /** Label of an action - "To the trash" with the period of the trash */
    public function actionLabel(string $action): string
    {
        return strtr($this->cms->snippet('privacy.action_' . $action),
            ['{days}' => (string)max(0, (int)($this->config->get('admin.trash_retention_days') ?? 7))]);
    }

    /** How many areas (also in the trash) use the policy? */
    public function usageCount(int $policyId): int
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT COUNT(*) FROM `area` WHERE `privacy_policy_id` = ?", 'i', [$policyId]);
            return (int)($this->db->fetchNum()[0] ?? 0);
        }
        finally {
            $this->db->popResult();
        }
    }

    /** Default policy of an area type (privacy_policy_id, 0 = none) */
    public function standardId(string $type): int
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `privacy_policy_id` FROM `privacy_policy` WHERE `standard` = ? ORDER BY `privacy_policy_id` LIMIT 1", 's', [$type]);
            return (int)($this->db->fetchNum()[0] ?? 0);
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Policies an area of the instance can select: those for all instances and
     * the instance's own ones.
     *
     * @return array  privacy_policy_id => label (snippets resolved)
     */
    public function optionsFor(int $instanceId): array
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `privacy_policy_id`, `label` FROM `privacy_policy` WHERE `instance_id` IN (?, ?)"
                . " ORDER BY `standard` = '', `label`", 'ii', [self::ALL_INSTANCES, $instanceId]);
            $options = [];
            while ($row = $this->db->fetch()) {
                $options[(int)$row['privacy_policy_id']] = $this->cms->textOrSnippet((string)$row['label']);
            }
            return $options;
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * May the logged-in admin change the policy (or create one for this instance
     * )? See class comment "Who may do what".
     *
     * @param int $instanceId  instance of the policy, ALL_INSTANCES = all
     */
    public function canEdit(int $instanceId): bool
    {
        $access = $this->container->get(Access::class);
        if (!$access->isLoaded() || $access->isSuperuser()) return true;
        if ($instanceId === self::ALL_INSTANCES) return false;
        return $access->moduleRight('Privacy', 'policy_edit') && $access->instance($instanceId);
    }

    /**
     * Create the default policies where they are missing (migration 9.1.0). Directly via
     * SQL: also runs without the backend and before the runtime lists are built.
     *
     * @return array  Bereichstyp => privacy_policy_id
     */
    public static function ensureStandard(Database $db): array
    {
        $ids = [];
        foreach (self::STANDARD as $type => $definition) {
            $db->query("SELECT `privacy_policy_id` FROM `privacy_policy` WHERE `standard` = ? ORDER BY `privacy_policy_id` LIMIT 1", 's', [$type]);
            $id = (int)($db->fetchNum()[0] ?? 0);
            if ($id < 1) {
                $db->query(
                    "INSERT INTO `privacy_policy` (`instance_id`, `label`, `standard`, `blocks`) VALUES (?, ?, ?, ?)",
                    'isss', [self::ALL_INSTANCES, $definition['label'], $type, (string)json_encode($definition['blocks'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
                );
                $id = (int)$db->insert_id;
            }
            $ids[$type] = $id;
        }
        return $ids;
    }
}
