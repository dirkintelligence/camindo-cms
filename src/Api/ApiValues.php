<?php
/**
 * Field values in the neutral format of the API - independent of the locale and time zone of the
 * input mask.
 *
 *     integer, id         int (empty: null)
 *     float, decimal      float - decimal point
 *     currency            String with two decimal places ("1999.00"), stored in cents
 *     bit                 0 | 1
 *     timestampl          ISO 8601 with offset in the time zone of the admin (stored in UTC;
 *                         output() expects the value as Record::load() supplies it)
 *     datetime            "Y-m-d H:i:s" without offset (wall time)
 *     time                "H:i:s"
 *     json, reltable      array
 *     text, chars …       text as stored (HTML fields: sanitized HTML)
 *     password            never (null)
 *     file                metadata: filename, mime_type, extension, size, width,
 *                         height, language (code for multilingual files)
 *
 * "No date" (Cms::DATE_MIN) and "no end" (Cms::DATE_MAX) are null. The input
 * (input()) takes the same formats, for times with or without offset.
 * Link fields are prepared by the respective API class (they need queries).
 *
 * @see Camindo\Core\Api\ApiContext::isoTime()
 */
namespace Camindo\Core\Api;

use Camindo\Core\Cms;
use Camindo\Core\Crypt;

class ApiValues
{
    public function __construct(
        private Cms $cms,
        private Crypt $crypt,
        private ApiContext $context,
    )
    {
    }

    /**
     * Bring a value from Record::load() resp. a prepared cache entry (fromCache())
     * into the neutral format.
     *
     * @param mixed $value   value
     * @param array $colDef  field definition (type, display_type)
     */
    public function output(mixed $value, array $colDef): mixed
    {
        $type = (string)($colDef['type'] ?? 'text');
        if ($type === 'password' || ($colDef['display_type'] ?? '') === 'password') return null;
        if ($value === null) return null;

        switch ($type) {
            case 'integer':
            case 'id':
                return ($value === '' ? null : (int)$value);
            case 'float':
            case 'decimal':
                return ($value === '' ? null : (float)$value);
            case 'currency':
                return ($value === '' ? null : number_format((int)$value / 100, 2, '.', ''));
            case 'bit':
                return (is_string($value) && strlen($value) === 1 && ord($value) < 2) ? ord($value) : (int)(bool)$value;
            case 'timestampl':
                // Record::load() / Cms::fetch() supply the time zone of the admin
                return $this->isDate((string)$value) ? $this->context->isoLocal((string)$value) : null;
            case 'datetime':
                return $this->isDate((string)$value) ? substr((string)$value, 0, 19) : null;
            case 'json':
            case 'reltable':
                if (is_string($value)) return json_decode($value, true) ?: [];
                return (array)$value;
            case 'file':
                return $this->file($value);
        }
        return is_scalar($value) ? (string)$value : $value;
    }

    /**
     * Bring input in the neutral format into the database format - for
     * Record::importSave() with $storedFields: then the conversion from the input format
     * of the mask (locale, time zone) is omitted; checking takes place by the rules of the
     * field as usual.
     *
     *     integer, id      integer resp. digit sequence
     *     float, decimal   number resp. string with decimal point (no comma)
     *     currency         number resp. "12.50" (at most two decimal places) -> cents
     *     bit              true/false, 1/0, "true"/"false"
     *     timestampl       "2026-09-29 14:30", "2026-09-29T14:30:00+02:00",
     *                      "2026-09-29 14:30 UTC+02:00", "…Z"; without offset the
     *                      time zone of the admin applies -> UTC
     *     datetime         as above, but without offset (wall time)
     *     time             "14:30" resp. "14:30:15"
     *     json, reltable   array (or JSON text)
     *     else             text
     *
     * Empty ('' or null) stays empty and takes the usual path.
     *
     * @return array  [value, bool database format]
     * @throws \InvalidArgumentException  value does not match the type (message for the user)
     */
    public function input(mixed $value, array $colDef): array
    {
        $type = (string)($colDef['type'] ?? 'text');
        if ($value === null || $value === '') return ['', false];

        switch ($type) {
            case 'integer':
            case 'id':
                if (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', trim($value)))) return [(string)(int)$value, true];
                throw new \InvalidArgumentException($this->cms->snippet('error_invalid_format'));
            case 'float':
            case 'decimal':
                if (is_int($value) || is_float($value)) return [(string)$value, true];
                if (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', trim($value))) return [trim($value), true];
                throw new \InvalidArgumentException($this->cms->snippet('error_invalid_format'));
            case 'currency':
                $text = is_string($value) ? trim($value) : (is_int($value) || is_float($value) ? (string)$value : '');
                if (!preg_match('/^-?\d+(\.\d{1,2})?$/', $text)) {
                    throw new \InvalidArgumentException($this->cms->snippet('error_invalid_format'));
                }
                return [(int)round((float)$text * 100), true];
            case 'bit':
                $bool = is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($bool === null || is_array($value)) throw new \InvalidArgumentException($this->cms->snippet('error_invalid_format'));
                return [$bool ? '1' : '0', false];
            case 'timestampl':
            case 'datetime':
                return [$this->dateInput(is_scalar($value) ? (string)$value : '', $type === 'timestampl'), true];
            case 'time':
                if (is_string($value) && preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', trim($value), $m)
                    && (int)$m[1] < 24 && (int)$m[2] < 60 && (int)($m[3] ?? 0) < 60) {
                    return [sprintf('%02d:%02d:%02d', $m[1], $m[2], $m[3] ?? 0), true];
                }
                throw new \InvalidArgumentException($this->cms->snippet('error_invalid_format'));
            case 'json':
            case 'reltable':
                if (is_array($value)) return [$value, false];
                $decoded = is_string($value) ? json_decode($value, true) : null;
                if (!is_array($decoded)) throw new \InvalidArgumentException($this->cms->snippet('error_invalid_format'));
                return [$decoded, false];
        }
        if (!is_scalar($value)) throw new \InvalidArgumentException($this->cms->snippet('error_invalid_format'));
        return [is_bool($value) ? ($value ? '1' : '0') : (string)$value, false];
    }

    /**
     * Read date/time in ISO format - see input().
     *
     * @param bool $utc  timestampl: convert to UTC (offset allowed); datetime: wall time
     */
    private function dateInput(string $value, bool $utc): string
    {
        $pattern = '/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2})(:\d{2})?(?:\.\d+)?)?\s*(Z|UTC|(?:UTC\s*)?[+-]\d{2}:?\d{2})?$/i';
        if (!preg_match($pattern, trim($value), $m)) {
            throw new \InvalidArgumentException($this->cms->snippet('error_invalid_format'));
        }
        $offset = strtoupper(trim((string)($m[4] ?? '')));
        if ($offset !== '' && !$utc) {
            // wall time: an offset would silently shift the value
            throw new \InvalidArgumentException($this->cms->snippet('error_invalid_format'));
        }
        $text = $m[1] . ' ' . (($m[2] ?? '') !== '' ? $m[2] . (($m[3] ?? '') !== '' ? $m[3] : ':00') : '00:00:00');
        if (!$utc) {
            $date = \DateTime::createFromFormat('!Y-m-d H:i:s', $text);
            if (!$date || $date->format('Y-m-d H:i:s') !== $text) throw new \InvalidArgumentException($this->cms->snippet('error_invalid_format'));
            return $text;
        }
        if ($offset === 'Z' || $offset === 'UTC') $zone = new \DateTimeZone('UTC');
        elseif ($offset !== '') $zone = new \DateTimeZone(preg_replace('/^UTC\s*/', '', $offset));
        else $zone = new \DateTimeZone($this->cms->userTZ !== '' ? $this->cms->userTZ : date_default_timezone_get());
        $date = \DateTime::createFromFormat('!Y-m-d H:i:s', $text, $zone);
        if (!$date || $date->format('Y-m-d H:i:s') !== $text) throw new \InvalidArgumentException($this->cms->snippet('error_invalid_format'));
        $date->setTimezone(new \DateTimeZone('UTC'));
        return $date->format('Y-m-d H:i:s');
    }

    /**
     * Read an entry of the content cache of a language (`record_cache`: [type, value, fallback])
     * - decoded JSON, encrypted values decrypted.
     *
     * @return array  [value as Record::load() gives it, bool fallback from the main language]
     */
    public function fromCache(?array $entry, array $colDef): array
    {
        $value = $entry[1] ?? null;
        $type = (string)($colDef['type'] ?? '');
        if ($type === 'encrypted' && is_string($value) && $value !== '') {
            $value = $this->crypt->decrypt($value, '', false);
        }
        elseif ($type === 'timestampl' && is_string($value) && $this->isDate($value)) {
            // in the cache UTC - converted like Cms::fetch() to the time zone of the admin
            $value = $this->cms->utcToUserTZ(substr($value, 0, 19));
        }
        return [$value, !empty($entry[2])];
    }

    /**
     * Metadata of a file (without internal details such as token, version, data).
     * Multilingual upload fields: per language (code => metadata).
     */
    public function file(mixed $value): ?array
    {
        if (!is_array($value) || $value === []) return null;
        if (!isset($value['filename']) && !isset($value['extension'])) {
            // multilingual: language_id => file
            $files = [];
            foreach ($value as $languageId => $fileData) {
                $file = $this->file($fileData);
                if ($file === null) continue;
                $code = is_int($languageId) || ctype_digit((string)$languageId)
                    ? ($this->context->languageCode((int)$languageId) ?: (string)$languageId)
                    : (string)$languageId;
                $files[$code] = $file;
            }
            return $files ?: null;
        }
        if (!empty($value['deleted']) || (int)($value['size'] ?? 0) < 1) return null;
        $languageId = $value['file_language_id'] ?? null;
        return [
            'filename'  => (string)($value['filename'] ?? ''),
            'mime_type' => (string)($value['mime_type'] ?? ''),
            'extension' => (string)($value['extension'] ?? ''),
            'size'      => (int)($value['size'] ?? 0),
            'width'     => (int)($value['width'] ?? 0) ?: null,
            'height'    => (int)($value['height'] ?? 0) ?: null,
            'language'  => ($languageId === null || $languageId === '') ? null : ($this->context->languageCode((int)$languageId) ?: null),
        ];
    }

    /** A date, not just the placeholder for "no date" / "no end" */
    private function isDate(string $value): bool
    {
        $value = substr(trim($value), 0, 19);
        return $value !== '' && $value !== Cms::DATE_MIN && $value !== Cms::DATE_MAX && !str_starts_with($value, '0000');
    }
}
