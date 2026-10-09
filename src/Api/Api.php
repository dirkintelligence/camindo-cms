<?php
/**
 * Einstieg der API: Methoden finden, aufrufen, beschreiben.
 *
 *     $app->get(ApiSession::class)->resume($sessionId);          // oder login(), attach() …
 *     $result = $app->get(Api::class)->call('system.whoami');
 *     // ['ok' => true, 'data' => [...], 'warnings' => []]
 *     // ['ok' => false, 'error' => 'access_denied', 'message' => '…', 'errors' => [...], 'warnings' => []]
 *
 * WOHER DIE METHODEN KOMMEN
 * --------------------
 * Aus den API-Klassen des Cores (CORE_CLASSES) und der aktiven Module: deren
 * install/settings.php nennt sie unter 'api' relativ zum Modul-Namespace
 * ('api' => ['RecordApi']). Aufrufbar ist jede öffentliche Methode mit dem Attribut
 * #[ApiMethod('name')]. Die Klassen kommen aus dem Container (Autowiring).
 *
 * PARAMETER
 * --------------------
 * Die Parameter eines Aufrufs sind die der PHP-Methode, in snake_case benannt
 * ($areaId -> area_id). Typen kommen aus der Signatur und werden geprüft bzw.
 * behutsam umgewandelt ("12" -> 12 bei int, "true" -> true bei bool); unbekannte,
 * fehlende oder unpassende Parameter sind ein Fehler (invalid_param).
 * describe() liefert dazu ein JSON-Schema samt Beschreibungen.
 *
 * BESCHREIBUNGEN
 * --------------------
 * Die Beschreibung im Attribut ist ein Snippet ('#record.api_search') oder Text.
 * Bei einem Snippet stehen auch die Parameter in Snippets - zuerst
 * {schlüssel}__{parameter} ('record.api_search__filter'), dann die für alle
 * Methoden der Komponente gemeinsamen api__{parameter} ('record.api__record_id');
 * fehlen beide, gilt die @param-Zeile des Docblocks. So erscheinen die Texte in der
 * Sprache des Admins bzw. in der, die describe() verlangt (der Assistent des Moduls
 * Ai gibt dem Sprachmodell die englischen).
 *
 * Jeder Aufruf setzt eine Session voraus (ApiContext::require()); die Methoden
 * werfen ApiException, call() macht daraus die Fehlerantwort. Unerwartete Fehler
 * landen in var/log/{Datum}_api-errors.log, die Antwort nennt nur 'internal'.
 *
 * @see Camindo\Core\Api\ApiSession   Anmeldung
 * @see Camindo\Core\Api\ApiMethod    das Attribut
 */
namespace Camindo\Core\Api;

use Camindo\Core\Cms;
use Camindo\Core\ContainerInterface;
use Camindo\Core\Database;
use Camindo\Core\System;

class Api
{
    /** API-Klassen des Cores außerhalb der Module */
    public const CORE_CLASSES = [SystemApi::class];

    /** name => ['class', 'method', 'description', 'write', 'level'] - je Prozess einmal gebaut */
    private ?array $methods = null;

    public function __construct(
        private ContainerInterface $container,
        private Cms $cms,
        private Database $db,
        private System $system,
        private ApiContext $context,
    )
    {
    }

    // ====================
    // Aufrufen

    /**
     * Eine API-Methode aufrufen.
     *
     * @param string $method  Name, z. B. 'record.search'
     * @param array  $params  Parameter (snake_case)
     * @return array          ['ok' => true, 'data' => …, 'warnings' => [...]] bzw.
     *                        ['ok' => false, 'error' => Code, 'message' => Text,
     *                         'errors' => [...], 'warnings' => [...]]
     */
    public function call(string $method, array $params = []): array
    {
        $this->cms->errorsReset();
        $this->context->takeWarnings();
        try {
            $this->context->require();
            $entry = $this->methods()[$method] ?? null;
            if ($entry === null) {
                throw new ApiException(ApiException::INVALID_PARAM, "unknown method \"{$method}\"");
            }
            $arguments = $this->arguments(new \ReflectionMethod($entry['class'], $entry['method']), $params);
            $data = $this->container->get($entry['class'])->{$entry['method']}(...$arguments);
            return ['ok' => true, 'data' => $data, 'warnings' => $this->context->takeWarnings()];
        }
        catch (ApiException $e) {
            return $this->failure($e->errorCode(), $e->getMessage(), $e->errors());
        }
        catch (\Throwable $e) {
            $this->system->log('api-errors', "{$method}: " . get_class($e) . ': ' . $e->getMessage()
                . ' in ' . $e->getFile() . ':' . $e->getLine());
            return $this->failure(ApiException::INTERNAL, 'internal error');
        }
        finally {
            // eine offen gebliebene Transaktion nicht in den nächsten Aufruf tragen
            while ($this->db->inTransaction()) $this->db->rollback();
            $this->cms->errorsReset();
        }
    }

    private function failure(string $code, string $message, array $errors = []): array
    {
        return [
            'ok'       => false,
            'error'    => $code,
            'message'  => $message,
            'errors'   => $errors,
            'warnings' => $this->context->takeWarnings(),
        ];
    }

    // ====================
    // Verzeichnis

    /**
     * Alle Methoden: name => ['class', 'method', 'description', 'write'], nach Namen
     * sortiert.
     */
    public function methods(): array
    {
        if ($this->methods !== null) return $this->methods;
        $classes = self::CORE_CLASSES;
        foreach ($this->cms->modules as $settings) {
            $namespace = (string)($settings['namespace'] ?? '');
            if ($namespace === '') continue;
            foreach ((array)($settings['api'] ?? []) as $class) {
                $classes[] = $namespace . '\\' . trim((string)$class, '\\');
            }
        }

        $methods = [];
        foreach ($classes as $class) {
            if (!class_exists($class)) {
                $this->system->log('api-errors', "api class {$class} not found");
                continue;
            }
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $reflection) {
                foreach ($reflection->getAttributes(ApiMethod::class) as $attribute) {
                    /** @var ApiMethod $info */
                    $info = $attribute->newInstance();
                    if (isset($methods[$info->name])) {
                        $this->system->log('api-errors', "api method {$info->name} defined twice ({$class})");
                        continue;
                    }
                    $methods[$info->name] = [
                        'class'       => $class,
                        'method'      => $reflection->getName(),
                        'description' => $info->description,
                        'write'       => $info->write,
                        'level'       => $info->level,
                    ];
                }
            }
        }
        ksort($methods);
        return $this->methods = $methods;
    }

    /**
     * Beschreibung einer Methode mit JSON-Schema der Parameter.
     *
     * @param string $lang  Sprache der Texte (Backend-Sprache, z. B. 'en_US'); leer = die des Admins
     * @return array  name, description, write, level (ApiMethod::LEVEL_*), parameters (JSON-Schema 'object')
     */
    public function describe(string $method, string $lang = ''): array
    {
        $entry = $this->methods()[$method] ?? null;
        if ($entry === null) {
            throw new ApiException(ApiException::INVALID_PARAM, "unknown method \"{$method}\"");
        }
        $reflection = new \ReflectionMethod($entry['class'], $entry['method']);
        $docs = $this->paramDocs((string)$reflection->getDocComment());
        $properties = [];
        $required = [];
        foreach ($reflection->getParameters() as $parameter) {
            $name = self::snake($parameter->getName());
            $schema = $this->typeSchema($parameter->getType());
            $description = $this->paramText($entry['description'], $name, $lang) ?? $docs[$parameter->getName()] ?? '';
            if ($description !== '') $schema['description'] = $description;
            if ($parameter->isDefaultValueAvailable()) {
                $schema['default'] = $parameter->getDefaultValue();
            }
            else {
                $required[] = $name;
            }
            $properties[$name] = $schema;
        }
        $parameters = ['type' => 'object', 'properties' => (object)$properties];
        if ($required) $parameters['required'] = $required;
        return [
            'name'        => $method,
            'description' => $this->context->plain($this->text($entry['description'], $lang)),
            'write'       => $entry['write'],
            'level'       => $entry['level'],
            'parameters'  => $parameters,
        ];
    }

    /** Text oder #snippet in der Sprache $lang ('' = die des Admins) */
    private function text(string $text, string $lang): string
    {
        return str_starts_with($text, '#') ? $this->cms->snippet(substr($text, 1), [], $lang) : $text;
    }

    /**
     * Beschreibung eines Parameters aus den Snippets (siehe Klassenkommentar) -
     * null, wenn die Methode keine Snippets nutzt oder keins passt.
     *
     * @param string $description  Beschreibung der Methode aus dem Attribut
     * @param string $param        Parametername (snake_case)
     */
    private function paramText(string $description, string $param, string $lang): ?string
    {
        if (!str_starts_with($description, '#')) return null;
        $key = substr($description, 1);
        $pos = strrpos($key, '.');
        $shared = ($pos === false ? '' : substr($key, 0, $pos + 1)) . 'api__' . $param;
        foreach ([$key . '__' . $param, $shared] as $index) {
            $text = $this->cms->snippet($index, [], $lang);
            if ($text !== $index) return $this->context->plain($text);
        }
        return null;
    }

    // ====================
    // Parameter

    /**
     * Aufruf-Parameter auf die Parameter der Methode abbilden (siehe Klassenkommentar).
     *
     * @return array  Argumente in der Reihenfolge der Signatur
     */
    private function arguments(\ReflectionMethod $reflection, array $params): array
    {
        $arguments = [];
        $known = [];
        foreach ($reflection->getParameters() as $parameter) {
            $name = self::snake($parameter->getName());
            $known[] = $name;
            if (!array_key_exists($name, $params)) {
                if (!$parameter->isDefaultValueAvailable()) {
                    throw new ApiException(ApiException::INVALID_PARAM, "missing parameter \"{$name}\"");
                }
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }
            $arguments[] = $this->coerce($name, $params[$name], $parameter->getType());
        }
        $unknown = array_diff(array_map('strval', array_keys($params)), $known);
        if ($unknown) {
            throw new ApiException(ApiException::INVALID_PARAM, 'unknown parameter "' . implode('", "', $unknown) . '"');
        }
        return $arguments;
    }

    /**
     * Wert auf den Typ des Parameters bringen - nur verlustfreie Umwandlungen.
     */
    private function coerce(string $name, mixed $value, ?\ReflectionType $type): mixed
    {
        if ($type === null) return $value;
        if ($value === null && $type->allowsNull()) return null;
        $types = $type instanceof \ReflectionUnionType
            ? array_map(fn($t) => $t->getName(), $type->getTypes())
            : [$type instanceof \ReflectionNamedType ? $type->getName() : 'mixed'];
        if (in_array('mixed', $types, true)) return $value;

        foreach ($types as $typeName) {
            switch ($typeName) {
                case 'int':
                    if (is_int($value)) return $value;
                    if (is_string($value) && preg_match('/^-?\d+$/', $value)) return (int)$value;
                    if (is_float($value) && floor($value) === $value) return (int)$value;
                    break;
                case 'float':
                    if (is_int($value) || is_float($value)) return (float)$value;
                    if (is_string($value) && is_numeric($value)) return (float)$value;
                    break;
                case 'string':
                    if (is_string($value)) return $value;
                    if (is_int($value) || is_float($value)) return (string)$value;
                    break;
                case 'bool':
                    if (is_bool($value)) return $value;
                    $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($bool !== null && !is_array($value)) return $bool;
                    break;
                case 'array':
                    if (is_array($value)) return $value;
                    break;
            }
        }
        throw new ApiException(ApiException::INVALID_PARAM, "parameter \"{$name}\" must be of type " . implode('|', $types));
    }

    /** JSON-Schema zu einem PHP-Typ */
    private function typeSchema(?\ReflectionType $type): array
    {
        if ($type === null) return [];
        $names = $type instanceof \ReflectionUnionType
            ? array_map(fn($t) => $t->getName(), $type->getTypes())
            : [$type instanceof \ReflectionNamedType ? $type->getName() : 'mixed'];
        $map = ['int' => 'integer', 'float' => 'number', 'string' => 'string', 'bool' => 'boolean', 'array' => ['array', 'object'], 'null' => 'null'];
        $jsonTypes = [];
        foreach ($names as $name) {
            if ($name === 'mixed') return [];
            foreach ((array)($map[$name] ?? []) as $jsonType) $jsonTypes[] = $jsonType;
        }
        if ($type->allowsNull() && !in_array('null', $jsonTypes, true)) $jsonTypes[] = 'null';
        $jsonTypes = array_values(array_unique($jsonTypes));
        return ['type' => count($jsonTypes) === 1 ? $jsonTypes[0] : $jsonTypes];
    }

    /**
     * Beschreibungen aus den @param-Zeilen eines Docblocks (Folgezeilen gehören
     * dazu, bis zur nächsten @-Zeile oder einer Leerzeile).
     *
     * @return array  Parametername (ohne $) => Text
     */
    private function paramDocs(string $doc): array
    {
        $docs = [];
        $current = null;
        foreach (preg_split('/\R/', $doc) as $line) {
            $line = trim((string)preg_replace('~^\s*/?\*+/?~', '', $line));
            if (preg_match('/^@param\s+\S+\s+\$(\w+)\s*(.*)$/', $line, $m)) {
                $current = $m[1];
                $docs[$current] = trim($m[2]);
                continue;
            }
            if ($line === '' || str_starts_with($line, '@')) {
                $current = null;
                continue;
            }
            if ($current !== null) $docs[$current] = trim($docs[$current] . ' ' . $line);
        }
        return $docs;
    }

    /** areaId -> area_id */
    public static function snake(string $name): string
    {
        return strtolower((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }
}
