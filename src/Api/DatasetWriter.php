<?php
/**
 * Konfigurations-Datasets (Sprache, Status, Instanz, Template …) über die API lesen
 * und schreiben - der gemeinsame Weg der API-Klassen, damit jede nur noch sagt,
 * welche Felder sie zeigt und welche man schreiben darf.
 *
 *     $this->writer->requireScript('Language', 'language_edit.php');
 *     $result = $this->writer->write($this->language, $id, $data, self::WRITABLE, $simulate);
 *
 * RECHTE
 * --------------------
 * Wie im Backend: requireScript() prüft das Script, das dieselbe Aufgabe in der
 * Oberfläche erledigt (Access::script(): Modulzugriff, Zusatzrechte, Superuser-
 * Module). Rechte je Bereich prüft Dataset::save() selbst.
 *
 * WERTE
 * --------------------
 * Neutrales Format wie ApiValues. Unterfelder (Schema 'details.width') gehen als
 * {"details": {"width": 10}} oder {"details.width": 10}; sie werden mit dem
 * gespeicherten JSON zusammengeführt - nicht genannte Unterfelder bleiben.
 *
 * Nur lesbare Felder (gezeigt, aber nicht schreibbar) weist write() ab - außer mit
 * dem Wert, den sie ohnehin haben: So darf man die Werte aus x.info unverändert
 * zurückschicken. Das Schema kennzeichnet sie mit readonly bzw. create_only.
 *
 * PROBELAUF
 * --------------------
 * simulate: Dataset::check() - dieselbe Prüfung wie save(), geschrieben wird nichts
 * (auch keine Cache-Dateien).
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
    /** Spalten, die weder gezeigt noch geschrieben werden (Baum, Arbeitskopien, Technik) */
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
     * Zugriff wie das Script der Oberfläche - sonst access_denied.
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
     * Einen Datensatz laden - sonst not_found.
     *
     * @throws ApiException
     */
    public function load(Dataset $dataset, int $id): array
    {
        $this->cms->errorsReset();
        $data = $id > 0 ? $dataset->load($id) : null;
        if (!$data) {
            $this->cms->errorsReset();
            throw new ApiException(ApiException::NOT_FOUND, $this->cms->snippet('error_record_not_found'));
        }
        return $data;
    }

    /**
     * Alle Datensätze einer kleinen Konfigurationstabelle in der Standardsortierung -
     * je Eintrag die Id und values() der genannten Felder. Nur für Tabellen mit
     * überschaubar vielen Zeilen (Sprachen, Status, Konverter …).
     *
     * @param array  $only    Felder je Eintrag
     * @param string $where   SQL-Bedingung (Platzhalter ?)
     * @param string $types   Bind-Typen zu $params
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
     * Werte im neutralen Format: Schema-Felder (auch Unterfelder), ohne INTERNAL und
     * Passwörter.
     *
     * @param array  $data  Dataset::load() / init()
     * @param ?array $only  nur diese Felder (null = alle)
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
     * Beschreibung der Felder für ein Werkzeug: name => label, type, required, min, max,
     * options (feste Auswahl), default, note.
     *
     * @param ?array $only     nur diese Felder (null = alle sichtbaren)
     * @param array  $options  Auswahlwerte, die erst zur Laufzeit feststehen (Feld => Liste)
     * @param ?array $writable   schreibbare Felder wie bei write() (null = nicht kennzeichnen);
     *                           die übrigen tragen readonly bzw. create_only
     * @param array  $createOnly  Felder, die nur das Anlegen schreibt
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
     * Anlegen ($id = 0) oder ändern.
     *
     * @param array $input     Feld => Wert (neutral; Unterfelder als Objekt oder mit Punkt)
     * @param array $writable  Felder, die geschrieben werden dürfen (Unterfelder einzeln
     *                         oder das JSON-Feld für alle seine Unterfelder)
     * @param array $options   defaults  Anlegen: Vorgaben für init() (z. B. area_id, type)
     *                         extra     Pseudo-Felder, die ein Hook liest (Feld: source_fields)
     *                         also      beim Ändern immer mitschreiben (Template: styled_code)
     *                         all       beim Ändern alle Felder schreiben wie die Maske (Feld)
     *                         hints     Feld => Hinweis, wenn es nicht geschrieben werden darf
     *                                   (z. B. 'derived from the processor')
     * @return array           id (Probelauf beim Anlegen: 0), simulated, fields (geschrieben)
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
     * Die Daten für save()/check() aus der Eingabe - für API-Klassen, die zwischen
     * Prüfen und Speichern eigene Schritte brauchen (Bereich mit Bauplan).
     *
     * @return array  [data, Feldliste für save(), geschriebene Felder]
     * @throws ApiException
     */
    public function prepare(Dataset $dataset, int $id, array $input, array $writable, array $options = []): array
    {
        $idField = $dataset->getTableName() . '_id';
        $data = $id > 0 ? $this->load($dataset, $id) : $dataset->init((array)($options['defaults'] ?? []));
        if ($data === null) throw $this->context->exceptionFromCms(ApiException::VALIDATION);
        foreach ((array)($options['extra'] ?? []) as $name => $value) $data[$name] = $value;
        // erst jetzt: manche Datasets ergänzen ihr Schema beim Laden (Converter: Prozessor)
        $schema = $dataset->getSchema();

        $this->cms->errorsReset();
        $fields = [];
        foreach ($this->flatten($input, $schema) as $name => $value) {
            $top = explode('.', $name)[0];
            $known = isset($schema[$name]) && !in_array($name, self::INTERNAL, true) && $name !== $idField;
            if (!$known || !$this->listed($name, $writable)) {
                // unverändert zurückgeschickt (Werte aus x.info): nichts zu tun
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

        // Anlegen: alle Felder (Vorgaben aus init() werden mit geprüft)
        $saveFields = ($id > 0 && empty($options['all']))
            ? array_values(array_unique(array_merge($fields, (array)($options['also'] ?? []))))
            : [];
        return [$data, $saveFields, $fields];
    }

    /**
     * Dataset::check() - bei Fehlern validation.
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

    /** Feld (oder sein JSON-Feld) in der Liste? */
    private function listed(string $name, array $list): bool
    {
        return in_array($name, $list, true) || in_array(explode('.', $name)[0], $list, true);
    }

    /** Meldung für ein Feld, das nicht geschrieben werden darf */
    private function notWritable(string $name, bool $known, array $writable, array $hints): string
    {
        $hint = (string)($hints[$name] ?? $hints[explode('.', $name)[0]] ?? '');
        $text = $known
            ? "field \"{$name}\" is read-only here" . ($hint !== '' ? " ({$hint})" : '') . ' - omit it'
            : "unknown field \"{$name}\"";
        return $text . ' (writable: ' . implode(', ', $writable) . ')';
    }

    /** Neutraler Wert gleich? (Zahlen und Texte, Wahrheitswerte als 0/1, Listen als JSON) */
    private function sameValue(mixed $current, mixed $input): bool
    {
        $normalize = function (mixed $value): mixed {
            if (is_bool($value)) return $value ? '1' : '0';
            if ($value === null) return '';
            return is_scalar($value) ? (string)$value : json_encode($value);
        };
        return $normalize($current) === $normalize($input);
    }

    /** Eingabe flach machen: {"details": {"a": 1}} -> details.a, wenn das Schema Unterfelder kennt */
    private function flatten(array $input, array $schema): array
    {
        $flat = [];
        foreach ($input as $name => $value) {
            $name = (string)$name;
            // JSON-Feld mit Unterfeldern im Schema: immer einzeln schreiben - ein
            // unbekannter Schlüssel wird so abgewiesen, statt das ganze JSON zu ersetzen
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

    /** Wert aus $data, Unterfelder über den Punkt */
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
            // JSON-Feld, das noch als Text vorliegt
            if (is_string($target[$part] ?? null)) $target[$part] = json_decode($target[$part], true) ?: [];
            if (!is_array($target[$part] ?? null)) $target[$part] = [];
            $target = &$target[$part];
        }
        $target[$last] = $value;
    }

    /** Feste Auswahl aus dem Schema ('options' oder mehrzeiliges 'default' bei Auswahlfeldern) */
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
