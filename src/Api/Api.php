<?php
/**
 * Entry point of the API: find methods, call them, describe them.
 *
 *     $app->get(ApiSession::class)->resume($sessionId);          // or login(), attach() …
 *     $result = $app->get(Api::class)->call('system.whoami');
 *     // ['ok' => true, 'data' => [...], 'warnings' => []]
 *     // ['ok' => false, 'error' => 'access_denied', 'message' => '…', 'errors' => [...], 'warnings' => []]
 *
 * WHERE THE METHODS COME FROM
 * --------------------
 * From the API classes of the core (CORE_CLASSES) and the active modules: their
 * install/settings.php names them under 'api' relative to the module namespace
 * ('api' => ['RecordApi']). Any public method with the attribute
 * #[ApiMethod('name')] can be called. The classes come from the container (autowiring).
 *
 * PARAMETERS
 * --------------------
 * The parameters of a call are those of the PHP method, named in snake_case
 * ($areaId -> area_id). Types come from the signature and are checked or
 * gently converted ("12" -> 12 for int, "true" -> true for bool); unknown,
 * missing or unsuitable parameters are an error (invalid_param).
 * describe() returns a JSON schema with descriptions for this.
 *
 * DESCRIPTIONS
 * --------------------
 * The description in the attribute is a snippet ('#record.api_search') or text.
 * For a snippet, the parameters also come from snippets - first
 * {key}__{parameter} ('record.api_search__filter'), then the api__{parameter}
 * shared by all methods of the component ('record.api__record_id');
 * if both are missing, the @param line of the docblock applies. The texts thus appear in the
 * language of the admin, or in the one describe() asks for (the assistant of the module
 * Ai gives the language model the English ones).
 *
 * Every call requires a session (ApiContext::require()); the methods
 * throw ApiException, call() turns it into the error response. Unexpected errors
 * end up in var/log/{date}_api-errors.log, the response names only 'internal'.
 *
 * @see Camindo\Core\Api\ApiSession   login
 * @see Camindo\Core\Api\ApiMethod    the attribute
 */
namespace Camindo\Core\Api;

use Camindo\Core\Cms;
use Camindo\Core\ContainerInterface;
use Camindo\Core\Database;
use Camindo\Core\System;

class Api
{
    /** API classes of the core outside the modules */
    public const CORE_CLASSES = [SystemApi::class];

    /** name => ['class', 'method', 'description', 'write', 'level'] - built once per process */
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
    // Calling

    /**
     * Call an API method.
     *
     * @param string $method  name, e.g. 'record.search'
     * @param array  $params  parameters (snake_case)
     * @return array          ['ok' => true, 'data' => …, 'warnings' => [...]] or
     *                        ['ok' => false, 'error' => code, 'message' => text,
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
            // do not carry an open transaction over into the next call
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
    // Directory

    /**
     * All methods: name => ['class', 'method', 'description', 'write'], by name
     * sorted.
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
     * Description of a method with the JSON schema of its parameters.
     *
     * @param string $lang  language of the texts (backend language, e.g. 'en_US'); empty = that of the admin
     * @return array  name, description, write, level (ApiMethod::LEVEL_*), parameters (JSON schema 'object')
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

    /** Text or #snippet in the language $lang ('' = that of the admin) */
    private function text(string $text, string $lang): string
    {
        return str_starts_with($text, '#') ? $this->cms->snippet(substr($text, 1), [], $lang) : $text;
    }

    /**
     * Description of a parameter from the snippets (see class comment) -
     * null if the method uses no snippets or none fits.
     *
     * @param string $description  description of the method from the attribute
     * @param string $param        parameter name (snake_case)
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
    // Parameters

    /**
     * Map call parameters to the parameters of the method (see class comment).
     *
     * @return array  arguments in the order of the signature
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
     * Bring a value to the type of the parameter - only lossless conversions.
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

    /** JSON schema for a PHP type */
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
     * Descriptions from the @param lines of a docblock (continuation lines belong
     * to it, up to the next @ line or an empty line).
     *
     * @return array  parameter name (without $) => text
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
