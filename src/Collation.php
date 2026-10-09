<?php
/**
 * Collation for text comparisons and sorting per content language.
 *
 * NEUTRAL AND PER LANGUAGE
 * --------------------
 * The text columns use a neutral Unicode collation: utf8mb4_0900_ai_ci,
 * independent of accents and upper/lower case, plus ß = ss, æ = ae. Languages
 * whose rules differ from this are compared and sorted by Cms::select() with their
 * own collation (COLLATE in the expression) - searching always happens in the language
 * version of the call, i.e. according to its rules:
 *
 *     de   ä = ae, ö = oe, ü = ue (phonebook rule)       utf8mb4_de_pb_0900_ai_ci
 *     sv   å, ä, ö are separate letters (o ≠ ö)          utf8mb4_sv_0900_ai_ci
 *     da   aa = å                                        utf8mb4_da_0900_ai_ci
 *     tr   i and ı, İ and I are different                utf8mb4_tr_0900_ai_ci
 *
 * Derived from the language code (`language`.`code`, de_AT -> de); nothing has to
 * be entered. Only accent-insensitive variants (ai_ci) - MySQL only knows
 * accent-sensitive ones for Chinese and Japanese, there the neutral one is kept.
 *
 * SERVER
 * --------------------
 * Which collations exist depends on the server: MySQL 8 knows the *_0900_ai_ci,
 * MariaDB from 10.10 the uca1400 variants, older MariaDB only the UCA 4.0 names
 * (utf8mb4_german2_ci …). forLocale() takes the first one available, neutral() likewise.
 * schema.sql names utf8mb4_0900_ai_ci; if the server does not know it, SqlFile inserts
 * the server's neutral one when importing (portable()).
 *
 * InstanceCache stores the result per language (collation()), so the queries only
 * cost something when the cache is rebuilt.
 *
 * FULL TEXT
 * --------------------
 * MATCH() has no COLLATE per query, it compares with the collation of the column.
 * Therefore `data_ftx`.`data` uses an accent-sensitive collation (0900_as_ci), and
 * fold() first brings text and search term into the same form according to the rules
 * of the language - with ICU, which knows the same rules (CLDR) as MySQL: in German
 * „Müller“ becomes „mueller“, neutral „muller“, in Swedish „förvaring“ stays.
 *
 * @see Camindo\Core\Cms::textCollation()
 * @see Camindo\Core\InstanceCache::collation()
 */
namespace Camindo\Core;

final class Collation {

    /** Neutral collation of the text columns, by priority: MySQL 8, MariaDB ≥ 10.10, older */
    public const NEUTRAL = ['utf8mb4_0900_ai_ci', 'utf8mb4_uca1400_ai_ci', 'utf8mb4_unicode_520_ci', 'utf8mb4_unicode_ci'];

    /** Accent-sensitive collation of the full-text column, by priority (see fold()) */
    public const FULLTEXT = ['utf8mb4_0900_as_ci', 'utf8mb4_uca1400_as_ci', 'utf8mb4_bin'];

    /**
     * Languages with their own rules: language (ISO 639-1) => [MySQL code, UCA name].
     * From these, in this order, utf8mb4_{code}_0900_ai_ci (MySQL 8),
     * utf8mb4_uca1400_{name}_ai_ci (MariaDB ≥ 10.10) and utf8mb4_{name}_ci are tried.
     */
    private const TAILORINGS = [
        'bg' => ['bg', ''],
        'bs' => ['bs', 'croatian'],
        'cs' => ['cs', 'czech'],
        'da' => ['da', 'danish'],
        'de' => ['de_pb', 'german2'],
        'eo' => ['eo', 'esperanto'],
        'es' => ['es', 'spanish'],
        'et' => ['et', 'estonian'],
        'fa' => ['', 'persian'],
        'gl' => ['gl', ''],
        'hr' => ['hr', 'croatian'],
        'hu' => ['hu', 'hungarian'],
        'is' => ['is', 'icelandic'],
        'la' => ['la', 'roman'],
        'lt' => ['lt', 'lithuanian'],
        'lv' => ['lv', 'latvian'],
        'mn' => ['mn_cyrl', ''],
        'nb' => ['nb', 'danish'],
        'nn' => ['nn', 'danish'],
        'no' => ['nb', 'danish'],
        'pl' => ['pl', 'polish'],
        'ro' => ['ro', 'romanian'],
        'ru' => ['ru', ''],
        'si' => ['', 'sinhala'],
        'sk' => ['sk', 'slovak'],
        'sl' => ['sl', 'slovenian'],
        'sr' => ['sr_latn', ''],
        'sv' => ['sv', 'swedish'],
        'tr' => ['tr', 'turkish'],
        'vi' => ['vi', 'vietnamese'],
    ];

    /**
     * Collations of the server for utf8mb4.
     *
     * @return string[]
     */
    public static function available(Database $db): array
    {
        $db->pushResult();
        try {
            $db->query("SHOW COLLATION WHERE `Charset` = 'utf8mb4'");
            $names = [];
            while ($row = $db->fetchNum()) $names[] = (string)$row[0];
            return $names;
        } finally {
            $db->popResult();
        }
    }

    /** Neutral collation from the server's list ('' if none is known) */
    public static function neutral(array $available): string
    {
        foreach (self::NEUTRAL as $name) {
            if (in_array($name, $available, true)) return $name;
        }
        return '';
    }

    /**
     * Collation for a language code: the language-specific one, otherwise the neutral one.
     *
     * @param string   $code       language code (de, de_AT, sr_Latn_RS …)
     * @param string[] $available  available()
     */
    public static function forLocale(string $code, array $available): string
    {
        $locale   = str_replace('-', '_', trim($code));
        $language = strtolower((string)\Locale::getPrimaryLanguage($locale));
        $script   = (string)\Locale::getScript($locale);
        [$mysql, $uca] = self::TAILORINGS[$language] ?? ['', ''];
        // Serbian only in Latin script, Mongolian only in Cyrillic script
        if ($language === 'sr' && $script !== 'Latn') $mysql = '';
        if ($language === 'mn' && $script !== '' && $script !== 'Cyrl') $mysql = '';

        $candidates = [];
        if ($mysql !== '') $candidates[] = "utf8mb4_{$mysql}_0900_ai_ci";
        if ($uca !== '') {
            $candidates[] = "utf8mb4_uca1400_{$uca}_ai_ci";
            $candidates[] = "utf8mb4_{$uca}_ci";
        }
        foreach ($candidates as $name) {
            if (in_array($name, $available, true)) return $name;
        }
        return self::neutral($available);
    }

    /**
     * Spelling variants of a search value for LIKE.
     *
     * LIKE compares character by character: one character can never match two
     * there, `weiß` LIKE 'weiss%' is false in every collation, in German
     * (ä = ae) also `Müller` LIKE 'Mue%'. Therefore the search value is extended by the variants
     * that the collation treats as equal - `weiss*` additionally searches `weiß*`,
     * in German `Mue*` additionally `Mü*` (and vice versa). LIKE itself handles accents and
     * upper/lower case.
     *
     *     all languages            ß = ss, æ = ae, œ = oe
     *     German (de_pb, german2)  ä = ae, ö = oe, ü = ue
     *     Danish, Norwegian        å = aa
     *
     * @param string $value      search value (with * as wildcard)
     * @param string $collation  collation of the comparison ('' = neutral)
     * @param int    $max        at most this many variants (the search value itself first)
     * @return string[]
     */
    public static function likeVariants(string $value, string $collation, int $max = 16): array
    {
        $classes = [['ß', 'ss'], ['æ', 'ae'], ['œ', 'oe']];
        if (preg_match('/_de_pb_|german2/', $collation)) {
            $classes = [['ß', 'ss'], ['æ', 'ä', 'ae'], ['œ', 'ö', 'oe'], ['ü', 'ue']];
        } elseif (preg_match('/_(da|nb|nn)_|danish/', $collation)) {
            $classes[] = ['å', 'aa'];
        }
        // Spelling (lowercase) => its class
        $map = [];
        foreach ($classes as $class) {
            foreach ($class as $spelling) $map[$spelling] = $class;
        }

        // Split the search value: fixed parts and interchangeable positions (one class each)
        $chars = mb_str_split($value);
        $lower = mb_str_split(mb_strtolower($value));
        $pieces = [];
        $text = '';
        for ($i = 0, $n = count($chars); $i < $n; $i++) {
            $two = $lower[$i] . ($lower[$i + 1] ?? '');
            $key = isset($map[$two]) ? $two : (isset($map[$lower[$i]]) ? $lower[$i] : null);
            if ($key === null) {
                $text .= $chars[$i];
                continue;
            }
            if ($text !== '') $pieces[] = $text;
            $text = '';
            $length = mb_strlen($key);
            // the spelling of the search value first, then the other ones of the class
            $own = implode('', array_slice($chars, $i, $length));
            $pieces[] = array_merge([$own], array_values(array_diff($map[$key], [$key])));
            $i += $length - 1;
        }
        if ($text !== '') $pieces[] = $text;

        $variants = [''];
        foreach ($pieces as $piece) {
            $next = [];
            foreach ($variants as $variant) {
                foreach ((array)$piece as $spelling) {
                    $next[] = $variant . $spelling;
                    if (count($next) >= $max) break 2;
                }
            }
            $variants = $next;
        }
        return array_values(array_unique($variants));
    }

    /**
     * Adapt SQL text (schema.sql, migrations) for the server: if it does not know
     * utf8mb4_0900_ai_ci or utf8mb4_0900_as_ci (MariaDB), the closest available one
     * (NEUTRAL, FULLTEXT) is put there.
     */
    public static function portable(string $sql, array $available): string
    {
        foreach ([self::NEUTRAL, self::FULLTEXT] as $choices) {
            $name = $choices[0];
            if (!str_contains($sql, $name) || in_array($name, $available, true)) continue;
            foreach ($choices as $choice) {
                if (in_array($choice, $available, true)) {
                    $sql = str_replace($name, $choice, $sql);
                    break;
                }
            }
        }
        return $sql;
    }

    /**
     * Fold text for the full-text index according to the rules of a language: lowercased,
     * each character replaced by what the language treats as equal to it
     * (primary level: without accents and upper/lower case).
     *
     *     neutral    Müller -> muller    Café -> cafe    Straße -> strasse   Ørsted -> orsted
     *     German     Müller -> mueller   Café -> cafe    Straße -> strasse
     *     Swedish    Förvaring -> förvaring (ö is a separate letter)
     *     Danish     Ålesund -> aalesund, Ørsted -> ørsted
     *
     * For each non-ASCII character, candidates are checked - the base form without
     * accents or its Latin transliteration, the same with an appended e and doubled
     * (ä: a, ae, aa) - and the first one that the language treats as equal is taken;
     * otherwise the character stays. Text and search term must be folded with
     * the same language.
     *
     * @param string $text  text (already split into words, Cms::indexText())
     * @param string $code  language code (`language`.`code`)
     */
    public static function fold(string $text, string $code): string
    {
        $locale = self::icuLocale($code);
        $text = \Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text;
        // Turkish: I -> ı, İ -> i
        static $turkish = null;
        if ($locale === 'tr') $turkish ??= \Transliterator::create('tr-Lower');
        $text = ($locale === 'tr' && $turkish) ? $turkish->transliterate($text) : mb_strtolower($text);
        return preg_replace_callback('/[^\x00-\x7F]/u', fn(array $m) => self::foldChar($m[0], $locale), $text) ?? $text;
    }

    /**
     * Do two languages fold the same (fold())? If not, the full text of one does not
     * match the search in the other.
     */
    public static function sameRules(string $codeA, string $codeB): bool
    {
        return self::icuLocale($codeA) === self::icuLocale($codeB);
    }

    /** ICU locale with the rules that forLocale() chooses in MySQL ('root' = neutral) */
    private static function icuLocale(string $code): string
    {
        $locale   = str_replace('-', '_', trim($code));
        $language = strtolower((string)\Locale::getPrimaryLanguage($locale));
        $script   = (string)\Locale::getScript($locale);
        if (!isset(self::TAILORINGS[$language])) return 'root';
        return match ($language) {
            'de'    => 'de@collation=phonebook',
            'no'    => 'nb',
            'sr'    => $script === 'Latn' ? 'sr_Latn' : 'root',
            'mn'    => ($script === '' || $script === 'Cyrl') ? 'mn' : 'root',
            default => $language,
        };
    }

    /** Fold one character (fold()), cached per locale */
    private static function foldChar(string $char, string $locale): string
    {
        static $cache = [], $collators = [], $latin = null;
        if (isset($cache[$locale][$char])) return $cache[$locale][$char];

        if (!isset($collators[$locale])) {
            $collators[$locale] = new \Collator($locale);
            $collators[$locale]->setStrength(\Collator::PRIMARY);
        }
        $latin ??= \Transliterator::create('Latin-ASCII');
        $bases = [
            preg_replace('/\p{Mn}+/u', '', \Normalizer::normalize($char, \Normalizer::FORM_D) ?: $char) ?? '',
            $latin ? mb_strtolower((string)$latin->transliterate($char)) : '',
        ];
        // meaningless for the language (e.g. the dot from İ -> i̇): dropped
        if ($collators[$locale]->compare($char, '') === 0) return $cache[$locale][$char] = '';
        $result = $char;
        foreach (array_unique($bases) as $base) {
            if ($base === '' || $base === $char) continue;
            foreach ([$base, $base . 'e', $base . $base] as $candidate) {
                if ($collators[$locale]->compare($char, $candidate) === 0) {
                    $result = $candidate;
                    break 2;
                }
            }
        }
        return $cache[$locale][$char] = $result;
    }
}
