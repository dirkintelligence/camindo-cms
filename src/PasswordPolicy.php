<?php
/**
 * Password policy: minimum length and character classes (developer docs, chapter 13 (Security: authentication)):
 * Security: authentication).
 *
 * The rules are in security.password_policy; a password field with the extension
 * "Password" can override single values in details.extension_options. The check
 * runs on the server (Dataset::validateValue() if the field carries the extension,
 * and always for admin.password); the extension shows the same rules in the browser
 * (Core/Extensions/Password) - the texts for that come from clientOptions().
 *
 * RULES
 * --------------------
 *     min_length       Minimum length in characters (mb_strlen)
 *     min_special      Special characters = everything except letters and digits,
 *                      character class [^\p{L}\p{N}] - spaces and umlauts or
 *                      letters of other scripts also count correctly this way
 *     min_upper        Uppercase letters (\p{Lu})
 *     upper_not_first  an uppercase letter at the start does not count (otherwise the
 *                      "Password1!" pattern meets the rule with the first character)
 *     min_lower        Lowercase letters (\p{Ll})
 *     min_digit        Digits (\p{N})
 * 0 or false = no requirement.
 *
 * The JavaScript part (password.ext.js, C9Password.check()) counts identically; whoever
 * adds a rule changes both.
 */
namespace Camindo\Core;

class PasswordPolicy {

    /** Name of the extension whose options override a field's rules */
    public const EXTENSION = 'Password';

    /** Defaults of security.password_policy (config, installer) */
    public const DEFAULTS = [
        'min_length'      => 12,
        'min_special'     => 1,
        'min_upper'       => 1,
        'upper_not_first' => true,
        'min_lower'       => 1,
        'min_digit'       => 0,
    ];

    /** Rules with type - order = display order */
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
     * Effective rules: security.password_policy, overridden by $overrides.
     * Empty values in $overrides ('' from the field form) do not count.
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
     * Rules for a column or field if it is to be checked there - otherwise null.
     * Meta fields from the PHP schema: 'extension' => 'Password' (admin.password);
     * fields of areas: details.extension (JSON string or array).
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
     * Which rules does a password not meet?
     *
     * @return string[]  Keys from RULES (upper_not_first appears as min_upper)
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
     * Count characters per class.
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
     * Label per active rule in the language of the request, e.g.
     * ['min_length' => 'at least 12 characters', …].
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
     * Error message for unmet rules: "at least 2 special characters, …".
     */
    public function message(array $failed, array $rules): string
    {
        $texts = $this->describe($rules);
        return implode(', ', array_map(fn($key) => $texts[$key] ?? $key, $failed));
    }

    /**
     * Options for the browser (data-c9-ext-options of the extension, Backend::fieldExtension()):
     * effective rules and ready-made texts, so that server and browser require the same.
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
