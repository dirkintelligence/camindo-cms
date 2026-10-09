<?php
/**
 * Passwort-Richtlinie: Mindestlänge und Zeichenklassen (16-sicherheit.md §12).
 *
 * Die Regeln stehen in security.password_policy; ein Passwort-Feld mit der Extension
 * "Password" kann einzelne Werte in details.extension_options überschreiben. Geprüft
 * wird auf dem Server (Dataset::validateValue(), wenn das Feld die Extension trägt,
 * und immer für admin.password); die Extension zeigt dieselben Regeln im Browser an
 * (Core/Extensions/Password) - die Texte dafür liefert clientOptions().
 *
 * REGELN
 * --------------------
 *     min_length       Mindestlänge in Zeichen (mb_strlen)
 *     min_special      Sonderzeichen = alles außer Buchstaben und Ziffern,
 *                      Zeichenklasse [^\p{L}\p{N}] - auch Leerzeichen und Umlaute
 *                      anderer Schriften zählen damit richtig
 *     min_upper        Großbuchstaben (\p{Lu})
 *     upper_not_first  ein Großbuchstabe am Anfang zählt nicht mit (das "Passwort1!"-
 *                      Muster erfüllt die Regel sonst mit dem ersten Zeichen)
 *     min_lower        Kleinbuchstaben (\p{Ll})
 *     min_digit        Ziffern (\p{N})
 * 0 bzw. false = keine Anforderung.
 *
 * Der JavaScript-Teil (password.ext.js, C9Password.check()) zählt identisch; wer eine
 * Regel ergänzt, ändert beide.
 */
namespace Camindo\Core;

class PasswordPolicy {

    /** Name der Extension, deren Optionen die Regeln eines Feldes überschreiben */
    public const EXTENSION = 'Password';

    /** Vorgaben von security.password_policy (Config, Installer) */
    public const DEFAULTS = [
        'min_length'      => 12,
        'min_special'     => 1,
        'min_upper'       => 1,
        'upper_not_first' => true,
        'min_lower'       => 1,
        'min_digit'       => 0,
    ];

    /** Regeln mit Typ - Reihenfolge = Reihenfolge der Anzeige */
    public const RULES = [
        'min_length'      => 'int',
        'min_special'     => 'int',
        'min_upper'       => 'int',
        'upper_not_first' => 'bool',
        'min_lower'       => 'int',
        'min_digit'       => 'int',
    ];

    public function __construct(
        private Config $config,
        private Cms $cms,
    )
    {
    }

    /**
     * Wirksame Regeln: security.password_policy, überschrieben durch $overrides.
     * Leere Werte in $overrides ('' aus dem Feldformular) zählen nicht.
     */
    public function rules(array $overrides = []): array
    {
        $base = (array)$this->config->get('security.password_policy');
        $rules = [];
        foreach (self::RULES as $key => $type) {
            $value = $base[$key] ?? 0;
            if (array_key_exists($key, $overrides) && $overrides[$key] !== '' && $overrides[$key] !== null) {
                $value = $overrides[$key];
            }
            $rules[$key] = $type === 'bool' ? (bool)(int)$value : max(0, min(128, (int)$value));
        }
        return $rules;
    }

    /**
     * Regeln für eine Spalte bzw. ein Feld, wenn dort geprüft werden soll - sonst null.
     * Meta-Felder aus dem PHP-Schema: 'extension' => 'Password' (admin.password);
     * Felder der Bereiche: details.extension (JSON-String oder Array).
     */
    public function rulesFor(array $colDef): ?array
    {
        $name = (string)($colDef['extension'] ?? '');
        $options = (array)($colDef['extension_options'] ?? []);
        if ($name === '' && isset($colDef['details'])) {
            $details = is_array($colDef['details']) ? $colDef['details'] : (json_decode((string)$colDef['details'], true) ?: []);
            $name = (string)($details['extension'] ?? '');
            $options = (array)($details['extension_options'] ?? []);
        }
        if (strcasecmp($name, self::EXTENSION) !== 0) return null;
        return $this->rules($options);
    }

    /**
     * Welche Regeln erfüllt ein Passwort nicht?
     *
     * @return string[]  Schlüssel aus RULES (upper_not_first erscheint als min_upper)
     */
    public static function failed(string $password, array $rules): array
    {
        $count = self::count($password);
        $failed = [];
        if ($rules['min_length'] > 0 && $count['length'] < $rules['min_length']) $failed[] = 'min_length';
        if ($rules['min_special'] > 0 && $count['special'] < $rules['min_special']) $failed[] = 'min_special';
        $upper = $rules['upper_not_first'] ? $count['upper_inner'] : $count['upper'];
        if ($rules['min_upper'] > 0 && $upper < $rules['min_upper']) $failed[] = 'min_upper';
        if ($rules['min_lower'] > 0 && $count['lower'] < $rules['min_lower']) $failed[] = 'min_lower';
        if ($rules['min_digit'] > 0 && $count['digit'] < $rules['min_digit']) $failed[] = 'min_digit';
        return $failed;
    }

    /**
     * Zeichen je Klasse zählen.
     *
     * @return array{length:int, special:int, upper:int, upper_inner:int, lower:int, digit:int}
     */
    public static function count(string $password): array
    {
        $first = mb_substr($password, 0, 1);
        $upper = preg_match_all('/\p{Lu}/u', $password);
        return [
            'length'      => mb_strlen($password),
            'special'     => preg_match_all('/[^\p{L}\p{N}]/u', $password),
            'upper'       => $upper,
            'upper_inner' => $upper - (preg_match('/^\p{Lu}$/u', $first) ? 1 : 0),
            'lower'       => preg_match_all('/\p{Ll}/u', $password),
            'digit'       => preg_match_all('/\p{N}/u', $password),
        ];
    }

    /**
     * Beschriftung je aktiver Regel in der Sprache des Requests, z. B.
     * ['min_length' => 'mindestens 12 Zeichen', …].
     */
    public function describe(array $rules, string $lang = ''): array
    {
        $texts = [];
        foreach (['min_length', 'min_special', 'min_upper', 'min_lower', 'min_digit'] as $key) {
            if ($rules[$key] < 1) continue;
            $snippet = 'password_rule_' . substr($key, 4);
            if ($key === 'min_upper' && $rules['upper_not_first']) $snippet .= '_inner';
            $texts[$key] = $this->cms->snippet($snippet, ['n' => $rules[$key]], $lang);
        }
        return $texts;
    }

    /**
     * Fehlermeldung zu nicht erfüllten Regeln: "mindestens 2 Sonderzeichen, …".
     */
    public function message(array $failed, array $rules): string
    {
        $texts = $this->describe($rules);
        return implode(', ', array_map(fn($key) => $texts[$key] ?? $key, $failed));
    }

    /**
     * Optionen für den Browser (data-c9-ext-options der Extension, Backend::fieldExtension()):
     * wirksame Regeln und fertige Texte, damit Server und Browser dasselbe verlangen.
     */
    public function clientOptions(array $options): array
    {
        $rules = $this->rules($options);
        return [
            'rules' => $rules,
            'texts' => [
                'rules'    => $this->describe($rules),
                'title'    => $this->cms->snippet('password_requirements'),
                'strength' => [
                    $this->cms->snippet('password_strength_weak'),
                    $this->cms->snippet('password_strength_medium'),
                    $this->cms->snippet('password_strength_strong'),
                ],
            ],
        ];
    }
}
