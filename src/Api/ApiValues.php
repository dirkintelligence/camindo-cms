<?php
/**
 * Feldwerte im neutralen Format der API - unabhängig von Locale und Zeitzone der
 * Eingabemaske.
 *
 *     integer, id         int (leer: null)
 *     float, decimal      float - Dezimalpunkt
 *     currency            String mit zwei Nachkommastellen ("1999.00"), gespeichert in Cent
 *     bit                 0 | 1
 *     timestampl          ISO 8601 mit Offset in der Zeitzone des Admins (in UTC gespeichert;
 *                         output() erwartet den Wert wie Record::load() ihn liefert)
 *     datetime            "Y-m-d H:i:s" ohne Offset (Wandzeit)
 *     time                "H:i:s"
 *     json, reltable      Array
 *     text, chars …       Text wie gespeichert (HTML-Felder: bereinigtes HTML)
 *     password            nie (null)
 *     file                Metadaten: filename, mime_type, extension, size, width,
 *                         height, language (Code bei mehrsprachigen Dateien)
 *
 * "Kein Datum" (Cms::DATE_MIN) und "kein Ende" (Cms::DATE_MAX) sind null. Die Eingabe
 * (input()) nimmt dieselben Formate, bei Zeitpunkten mit oder ohne Offset.
 * Verknüpfungsfelder bereitet die jeweilige API-Klasse auf (sie brauchen Abfragen).
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
     * Wert aus Record::load() bzw. einem aufbereiteten Cache-Eintrag (fromCache())
     * in das neutrale Format bringen.
     *
     * @param mixed $value   Wert
     * @param array $colDef  Felddefinition (type, display_type)
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
                // Record::load() / Cms::fetch() liefern die Zeitzone des Admins
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
     * Eingabe im neutralen Format in das Datenbankformat bringen - für
     * Record::importSave() mit $storedFields: dann entfällt die Umrechnung aus dem
     * Eingabeformat der Maske (Locale, Zeitzone), geprüft wird nach den Regeln des
     * Feldes wie sonst auch.
     *
     *     integer, id      Ganzzahl bzw. Ziffernfolge
     *     float, decimal   Zahl bzw. Zeichenkette mit Dezimalpunkt (kein Komma)
     *     currency         Zahl bzw. "12.50" (höchstens zwei Nachkommastellen) -> Cent
     *     bit              true/false, 1/0, "true"/"false"
     *     timestampl       "2026-09-29 14:30", "2026-09-29T14:30:00+02:00",
     *                      "2026-09-29 14:30 UTC+02:00", "…Z"; ohne Offset gilt die
     *                      Zeitzone des Admins -> UTC
     *     datetime         wie oben, aber ohne Offset (Wandzeit)
     *     time             "14:30" bzw. "14:30:15"
     *     json, reltable   Array (oder JSON-Text)
     *     sonst            Text
     *
     * Leer ('' oder null) bleibt leer und geht den gewöhnlichen Weg.
     *
     * @return array  [Wert, bool Datenbankformat]
     * @throws \InvalidArgumentException  Wert passt nicht zum Typ (Meldung für den Nutzer)
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
     * Datum/Zeit im ISO-Format lesen - siehe input().
     *
     * @param bool $utc  timestampl: in UTC umrechnen (Offset erlaubt); datetime: Wandzeit
     */
    private function dateInput(string $value, bool $utc): string
    {
        $pattern = '/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2})(:\d{2})?(?:\.\d+)?)?\s*(Z|UTC|(?:UTC\s*)?[+-]\d{2}:?\d{2})?$/i';
        if (!preg_match($pattern, trim($value), $m)) {
            throw new \InvalidArgumentException($this->cms->snippet('error_invalid_format'));
        }
        $offset = strtoupper(trim((string)($m[4] ?? '')));
        if ($offset !== '' && !$utc) {
            // Wandzeit: ein Offset würde den Wert stillschweigend verschieben
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
     * Eintrag des Inhalts-Caches einer Sprache (`record_cache`: [typ, wert, fallback])
     * lesen - json dekodiert, verschlüsselte Werte entschlüsselt.
     *
     * @return array  [Wert wie Record::load(), bool Fallback aus der Hauptsprache]
     */
    public function fromCache(?array $entry, array $colDef): array
    {
        $value = $entry[1] ?? null;
        $type = (string)($colDef['type'] ?? '');
        if ($type === 'encrypted' && is_string($value) && $value !== '') {
            $value = $this->crypt->decrypt($value, '', false);
        }
        elseif ($type === 'timestampl' && is_string($value) && $this->isDate($value)) {
            // im Cache UTC - wie Cms::fetch() in die Zeitzone des Admins
            $value = $this->cms->utcToUserTZ(substr($value, 0, 19));
        }
        return [$value, !empty($entry[2])];
    }

    /**
     * Metadaten einer Datei (ohne interne Angaben wie token, version, data).
     * Mehrsprachige Upload-Felder: je Sprache (Code => Metadaten).
     */
    public function file(mixed $value): ?array
    {
        if (!is_array($value) || $value === []) return null;
        if (!isset($value['filename']) && !isset($value['extension'])) {
            // mehrsprachig: language_id => Datei
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

    /** Ein Datum, nicht nur der Platzhalter für "kein Datum" / "kein Ende" */
    private function isDate(string $value): bool
    {
        $value = substr(trim($value), 0, 19);
        return $value !== '' && $value !== Cms::DATE_MIN && $value !== Cms::DATE_MAX && !str_starts_with($value, '0000');
    }
}
