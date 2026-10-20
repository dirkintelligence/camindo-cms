<?php
/**
 * Configuration datasets (language, status, instance, template …) read and write via the API -
 * the common path of the API classes, so that each one only says
 * which fields it shows and which may be written.
 *
 *     $this->writer->requireScript('Language', 'language_edit.php');
 *     $result = $this->writer->write($this->language, $id, $data, self::WRITABLE, $simulate);
 *
 * RIGHTS
 * --------------------
 * As in the backend: requireScript() checks the script that performs the same task in the
 * interface (Access::script(): module access, additional rights, superuser-
 * modules). Rights per area are checked by Dataset::save() itself.
 *
 * VALUES
 * --------------------
 * Neutral format as ApiValues. Subfields (schema 'details.width') go as
 * {"details": {"width": 10}} or {"details.width": 10}; they are merged with the
 * stored JSON - subfields not named remain.
 *
 * Only readable fields (shown, but not writable) write() rejects - except with
 * the value they have anyway: so one may send back the values from x.info unchanged.
 * The schema marks them with readonly resp. create_only.
 *
 * DRY RUN
 * --------------------
 * simulate: Dataset::check() - the same check as save(); nothing is written
 * (not even cache files).
 *
 * @see Camindo\Core\Dataset::check()
 */
namespace Camindo\Core\Api;

use Camindo\Core\Access;
use Camindo\Core\Cms;
use Camindo\Core\Database;
use Camindo\Core\Dataset;

class DatasetWriter
{
    /** Columns that are neither shown nor written (tree, working copies, technical) */
    public const INTERNAL = ['lft', 'rgt', 'depth', 'parent_ids', 'tmp', 'deleted', 'origin_id', 'php_code', 'styled_code'];

    public function __construct(
        private Cms $cms,
        private Access $access,
        private ApiContext $context,
        private ApiValues $values,
        private Database $db,
    )
    {
    }

    /**
     * Access like the script of the interface - otherwise access_denied.
     *
     * @throws ApiException
     */
    public function requireScript(string $module, string $script): void
    {
        $this->context->require();
        if (!$this->access->script($module, $script)) {
            throw new ApiException(ApiException::ACCESS_DENIED, $this->cms->snippet('error_access_denied'));
        }
    }

    /**
     * Load a record - otherwise not_found.
     *
     * @throws ApiException
     */
    public function load(Dataset $dataset, int $id): array
    {
        $this->cms->errorsReset();
        $data = $id > 0 ? $dataset->load($id) : null;
        // rows in the trash (`deleted` = 1) count as not found
        if (!$data || !empty($data['deleted'])) {
            $this->cms->errorsReset();
            throw new ApiException(ApiException::NOT_FOUND, $this->cms->snippet('error_record_not_found'));
        }
        return $data;
    }

    /**
     * All records of a small configuration table in the default sort order -
     * per entry the id and values() of the named fields. Only for tables with a
     * manageable number of rows (languages, status, converters …).
     *
     * @param array  $only    fields per entry
     * @param string $where   SQL condition (placeholder ?)
     * @param string $types   bind types for $params
     */
    public function list(Dataset $dataset, array $only, string $where = '1', string $types = '', array $params = []): array
    {
        $table = $dataset->getTableName();
        $order = $dataset->getDefaultSortOrder();
        $order = preg_match('/^[a-z_]+( (ASC|DESC))?(, ?[a-z_]+( (ASC|DESC))?)*$/i', $order) ? $order : "`{$table}_id`";
        $db = $this->db;
        $db->pushResult();
        try {
            $db->query("SELECT `{$table}_id` FROM `{$table}` WHERE {$where}"
                . (isset($dataset->getSchema()['deleted']) ? " AND `deleted` = 0" : '') . " ORDER BY {$order}", $types, $params);
            $ids = array_map('intval', $db->fetchAll());
        }
        finally {
            $db->popResult();
        }
        $list = [];
        foreach ($ids as $id) {
            $data = $dataset->load($id);
            if ($data) $list[] = ['id' => $id] + $this->values($dataset, $data, $only);
        }
        $this->cms->errorsReset();
        return $list;
    }

    /**
     * Values in the neutral format: schema fields (also subfields), without INTERNAL and
     * passwords.
     *
     * @param array  $data  Dataset::load() / init()
     * @param ?array $only  only these fields (null = all)
     */
    public function values(Dataset $dataset, array $data, ?array $only = null): array
    {
        $values = [];
        foreach ($dataset->getSchema() as $name => $colDef) {
            if (!$this->visible($name, $colDef, $only)) continue;
            if (($colDef['type'] ?? '') === 'json' && $this->hasSubfields($dataset, $name)) continue;
            $values[$name] = $this->values->output($this->get($data, $name), $colDef);
        }
        return $values;
    }

    /**
     * Description of the fields for a tool: name => label, type, required, min, max,
     * options (fixed choice), default, note.
     *
     * @param ?array $only     only these fields (null = all visible)
     * @param array  $options  choice values that are only fixed at runtime (field => list)
     * @param ?array $writable   writable fields as with write() (null = do not mark);
     *                           the others carry readonly resp. create_only
     * @param array  $createOnly  fields that only creating writes
     */
    public function schema(Dataset $dataset, ?array $only = null, array $options = [], ?array $writable = null, array $createOnly = []): array
    {
        $schema = [];
        foreach ($dataset->getSchema() as $name => $colDef) {
            if (!$this->visible($name, $colDef, $only)) continue;
            if (($colDef['type'] ?? '') === 'json' && $this->hasSubfields($dataset, $name)) continue;
            $entry = [
                'label' => $this->plain((string)($colDef['label'] ?? $name)),
                'type'  => (string)($colDef['type'] ?? 'text'),
            ];
            $flags = (array)($colDef['flags'] ?? []);
            if (in_array('not_empty', $flags, true)) $entry['required'] = true;
            if (in_array('name', $flags, true)) $entry['pattern'] = '^[a-z][a-z0-9_]+$';
            if (in_array('unique', $flags, true) || in_array('unique_in_area', $flags, true)) $entry['unique'] = true;
            foreach (['min', 'max'] as $key) {
                if (isset($colDef[$key]) && $colDef[$key] !== '') $entry[$key] = $colDef[$key];
            }
            $choices = $options[$name] ?? $this->staticOptions($colDef);
            if ($choices) $entry['options'] = $choices;
            if (isset($colDef['default']) && (string)$colDef['default'] !== '' && !$choices) $entry['default'] = $colDef['default'];
            $note = trim((string)($colDef['note'] ?? $colDef['tooltip'] ?? ''));
            if ($note !== '') $entry['note'] = $this->plain($note);
            if ($writable !== null && !$this->listed($name, $writable)) {
                $entry[$this->listed($name, $createOnly) ? 'create_only' : 'readonly'] = true;
            }
            $schema[$name] = $entry;
        }
        return $schema;
    }

    /**
     * Create ($id = 0) or change.
     *
     * @param array $input     field => value (neutral; subfields as object or with dot)
     * @param array $writable  fields that may be written (subfields individually
     *                         or the JSON field for all its subfields)
     * @param array $options   defaults  create: defaults for init() (e.g. area_id, type)
     *                         extra     pseudo fields that a hook reads (field: source_fields)
     *                         also      always write along on change (template: styled_code)
     *                         all       on change write all fields like the mask (field)
     *                         hints     field => note if it may not be written
     *                                   (e.g. 'derived from the processor')
     * @return array           id (trial run when creating: 0), simulated, fields (written)
     * @throws ApiException    invalid_param, not_found, validation, access_denied
     */
    public function write(Dataset $dataset, int $id, array $input, array $writable, bool $simulate, array $options = []): array
    {
        [$data, $saveFields, $fields] = $this->prepare($dataset, $id, $input, $writable, $options);
        if ($simulate) {
            $this->check($dataset, $saveFields, $data);
            return ['id' => $id, 'simulated' => true, 'fields' => $fields];
        }
        $savedId = $dataset->save($saveFields, $data);
        if ($savedId === null) throw $this->context->exceptionFromCms(ApiException::VALIDATION);
        $this->cms->errorsReset();
        return ['id' => (int)$savedId, 'simulated' => false, 'fields' => $fields];
    }

    /**
     * The data for save()/check() from the input - for API classes that need their own
     * steps between checking and saving (area with blueprint).
     *
     * @return array  [data, field list for save(), written fields]
     * @throws ApiException
     */
    public function prepare(Dataset $dataset, int $id, array $input, array $writable, array $options = []): array
    {
        $idField = $dataset->getTableName() . '_id';
        $data = $id > 0 ? $this->load($dataset, $id) : $dataset->init((array)($options['defaults'] ?? []));
        if ($data === null) throw $this->context->exceptionFromCms(ApiException::VALIDATION);
        foreach ((array)($options['extra'] ?? []) as $name => $value) $data[$name] = $value;
        // only now: some datasets extend their schema when loading (converter: processor)
        $schema = $dataset->getSchema();

        $this->cms->errorsReset();
        $fields = [];
        foreach ($this->flatten($input, $schema) as $name => $value) {
            $top = explode('.', $name)[0];
            $known = isset($schema[$name]) && !in_array($name, self::INTERNAL, true) && $name !== $idField;
            if (!$known || !$this->listed($name, $writable)) {
                // returned unchanged (values from x.info): nothing to do
                if ($known && $this->sameValue($this->values->output($this->get($data, $name), $schema[$name]), $value)) continue;
                throw new ApiException(ApiException::INVALID_PARAM, $this->notWritable($name, $known, $writable, (array)($options['hints'] ?? [])));
            }
            try {
                [$converted] = $this->values->input($value, $schema[$name]);
            }
            catch (\InvalidArgumentException $e) {
                $this->cms->error($e->getMessage(), $name, (string)($schema[$name]['label'] ?? $name));
                continue;
            }
            $this->set($data, $name, $converted);
            if (!in_array($top, $fields, true)) $fields[] = $top;
        }
        if ($this->cms->hasErrors) throw $this->context->exceptionFromCms(ApiException::VALIDATION);
        if (!$fields && !($options['extra'] ?? []) && $id > 0) throw new ApiException(ApiException::INVALID_PARAM, 'no fields to write');

        // Creating: all fields (defaults from init() are checked as well)
        $saveFields = ($id > 0 && empty($options['all']))
            ? array_values(array_unique(array_merge($fields, (array)($options['also'] ?? []))))
            : [];
        return [$data, $saveFields, $fields];
    }

    /**
     * Dataset::check() - validation on errors.
     *
     * @throws ApiException
     */
    public function check(Dataset $dataset, array $fields, array $data): void
    {
        $this->cms->errorsReset();
        if (!$dataset->check($fields, $data)) throw $this->context->exceptionFromCms(ApiException::VALIDATION);
        $this->cms->errorsReset();
    }

    // ====================

    /** Field (or its JSON field) in the list? */
    private function listed(string $name, array $list): bool
    {
        return in_array($name, $list, true) || in_array(explode('.', $name)[0], $list, true);
    }

    /** Message for a field that may not be written */
    private function notWritable(string $name, bool $known, array $writable, array $hints): string
    {
        $hint = (string)($hints[$name] ?? $hints[explode('.', $name)[0]] ?? '');
        $text = $known
            ? "field \"{$name}\" is read-only here" . ($hint !== '' ? " ({$hint})" : '') . ' - omit it'
            : "unknown field \"{$name}\"";
        return $text . ' (writable: ' . implode(', ', $writable) . ')';
    }

    /** Neutral value the same? (numbers and texts, truth values as 0/1, lists as JSON) */
    private function sameValue(mixed $current, mixed $input): bool
    {
        $normalize = function (mixed $value): mixed {
            if (is_bool($value)) return $value ? '1' : '0';
            if ($value === null) return '';
            return is_scalar($value) ? (string)$value : json_encode($value);
        };
        return $normalize($current) === $normalize($input);
    }

    /** Flatten input: {"details": {"a": 1}} -> details.a, if the schema knows subfields */
    private function flatten(array $input, array $schema): array
    {
        $flat = [];
        foreach ($input as $name => $value) {
            $name = (string)$name;
            // JSON field with subfields in the schema: always write individually - an
            // unknown key is then rejected instead of replacing the whole JSON
            if (is_array($value) && !array_is_list($value) && $this->hasSubfieldsIn($schema, $name)) {
                foreach ($value as $sub => $subValue) $flat[$name . '.' . $sub] = $subValue;
                continue;
            }
            $flat[$name] = $value;
        }
        return $flat;
    }

    private function visible(string $name, array $colDef, ?array $only): bool
    {
        if ($only !== null && !in_array($name, $only, true) && !in_array(explode('.', $name)[0], $only, true)) return false;
        if (in_array($name, self::INTERNAL, true)) return false;
        return ($colDef['type'] ?? '') !== 'password' && ($colDef['display_type'] ?? '') !== 'password';
    }

    private function hasSubfields(Dataset $dataset, string $name): bool
    {
        return $this->hasSubfieldsIn($dataset->getSchema(), $name);
    }

    private function hasSubfieldsIn(array $schema, string $name): bool
    {
        foreach (array_keys($schema) as $key) {
            if (str_starts_with((string)$key, $name . '.')) return true;
        }
        return false;
    }

    /** Value from $data, subfields via the dot */
    private function get(array $data, string $name): mixed
    {
        $value = $data;
        foreach (explode('.', $name) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) return null;
            $value = $value[$part];
        }
        return $value;
    }

    private function set(array &$data, string $name, mixed $value): void
    {
        $parts = explode('.', $name);
        $last = array_pop($parts);
        $target = &$data;
        foreach ($parts as $part) {
            // JSON field still present as text
            if (is_string($target[$part] ?? null)) $target[$part] = json_decode($target[$part], true) ?: [];
            if (!is_array($target[$part] ?? null)) $target[$part] = [];
            $target = &$target[$part];
        }
        $target[$last] = $value;
    }

    /** Fixed choice from the schema ('options' or multi-line 'default' for selection fields) */
    private function staticOptions(array $colDef): array
    {
        if (!empty($colDef['options']) && is_array($colDef['options'])) {
            return array_values(array_map('strval', array_is_list($colDef['options']) ? $colDef['options'] : array_keys($colDef['options'])));
        }
        $default = (string)($colDef['default'] ?? '');
        if (preg_match('/\R/', $default) && in_array($colDef['display_type'] ?? '', ['select', 'radio'], true)) {
            return array_values(array_filter(array_map('trim', preg_split('/\R/', $default))));
        }
        return [];
    }

    private function plain(string $text): string
    {
        return $this->context->plain($this->cms->textOrSnippet($text));
    }
}
