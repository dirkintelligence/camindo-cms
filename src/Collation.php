<?php
/**
 * Kollation (Collation) für Textvergleiche und Sortierung je Inhaltssprache.
 *
 * NEUTRAL UND JE SPRACHE
 * --------------------
 * Die Textspalten stehen auf einer neutralen Unicode-Kollation: utf8mb4_0900_ai_ci,
 * unabhängig von Akzenten und Groß-/Kleinschreibung, dazu ß = ss, æ = ae. Sprachen,
 * deren Regeln davon abweichen, vergleicht und sortiert Cms::select() mit ihrer
 * eigenen Kollation (COLLATE im Ausdruck) - gesucht wird immer in der Sprachversion
 * des Aufrufs, also nach deren Regeln:
 *
 *     de   ä = ae, ö = oe, ü = ue (Telefonbuch-Regel)    utf8mb4_de_pb_0900_ai_ci
 *     sv   å, ä, ö sind eigene Buchstaben (o ≠ ö)        utf8mb4_sv_0900_ai_ci
 *     da   aa = å                                        utf8mb4_da_0900_ai_ci
 *     tr   i und ı, İ und I sind verschieden             utf8mb4_tr_0900_ai_ci
 *
 * Abgeleitet wird aus dem Sprachkürzel (`language`.`code`, de_AT -> de); einzutragen
 * ist nichts. Nur akzent-unabhängige Varianten (ai_ci) - für Chinesisch und Japanisch
 * kennt MySQL nur akzentgenaue, dort bleibt es bei der neutralen.
 *
 * SERVER
 * --------------------
 * Welche Kollationen es gibt, hängt vom Server ab: MySQL 8 kennt die *_0900_ai_ci,
 * MariaDB ab 10.10 die uca1400-Varianten, ältere MariaDB nur die UCA-4.0-Namen
 * (utf8mb4_german2_ci …). forLocale() nimmt die erste vorhandene, neutral() ebenso.
 * schema.sql nennt utf8mb4_0900_ai_ci; kennt der Server sie nicht, setzt SqlFile beim
 * Einspielen die neutrale des Servers ein (portable()).
 *
 * InstanceCache legt das Ergebnis je Sprache ab (collation()), die Abfragen kosten
 * also nur beim Neuaufbau des Caches etwas.
 *
 * VOLLTEXT
 * --------------------
 * MATCH() kennt kein COLLATE je Abfrage, es vergleicht mit der Kollation der Spalte.
 * Deshalb steht `data_ftx`.`data` auf einer akzentgenauen Kollation (0900_as_ci), und
 * fold() bringt Text und Suchbegriff vorher nach den Regeln der Sprache in dieselbe
 * Form - mit ICU, das dieselben Regeln (CLDR) kennt wie MySQL: im Deutschen wird
 * „Müller“ zu „mueller“, neutral zu „muller“, im Schwedischen bleibt „förvaring“.
 *
 * @see Camindo\Core\Cms::textCollation()
 * @see Camindo\Core\InstanceCache::collation()
 */
namespace Camindo\Core;

final class Collation {

    /** Neutrale Kollation der Textspalten, nach Vorrang: MySQL 8, MariaDB ≥ 10.10, ältere */
    public const NEUTRAL = ['utf8mb4_0900_ai_ci', 'utf8mb4_uca1400_ai_ci', 'utf8mb4_unicode_520_ci', 'utf8mb4_unicode_ci'];

    /** Akzentgenaue Kollation der Volltextspalte, nach Vorrang (siehe fold()) */
    public const FULLTEXT = ['utf8mb4_0900_as_ci', 'utf8mb4_uca1400_as_ci', 'utf8mb4_bin'];

    /**
     * Sprachen mit eigenen Regeln: Sprache (ISO 639-1) => [MySQL-Kürzel, UCA-Name].
     * Daraus werden, in dieser Reihenfolge, utf8mb4_{kürzel}_0900_ai_ci (MySQL 8),
     * utf8mb4_uca1400_{name}_ai_ci (MariaDB ≥ 10.10) und utf8mb4_{name}_ci versucht.
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
     * Kollationen des Servers für utf8mb4.
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

    /** Neutrale Kollation aus der Liste des Servers ('' wenn keine bekannt ist) */
    public static function neutral(array $available): string
    {
        foreach (self::NEUTRAL as $name) {
            if (in_array($name, $available, true)) return $name;
        }
        return '';
    }

    /**
     * Kollation für ein Sprachkürzel: die sprachspezifische, sonst die neutrale.
     *
     * @param string   $code       Sprachkürzel (de, de_AT, sr_Latn_RS …)
     * @param string[] $available  available()
     */
    public static function forLocale(string $code, array $available): string
    {
        $locale   = str_replace('-', '_', trim($code));
        $language = strtolower((string)\Locale::getPrimaryLanguage($locale));
        $script   = (string)\Locale::getScript($locale);
        [$mysql, $uca] = self::TAILORINGS[$language] ?? ['', ''];
        // Serbisch nur in lateinischer, Mongolisch nur in kyrillischer Schrift
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
     * Schreibvarianten eines Suchwerts für LIKE.
     *
     * LIKE vergleicht Zeichen für Zeichen: ein Zeichen kann dort nie zweien
     * entsprechen, `weiß` LIKE 'weiss%' ist in jeder Kollation falsch, im Deutschen
     * (ä = ae) auch `Müller` LIKE 'Mue%'. Deshalb wird der Suchwert um die Varianten
     * erweitert, die die Kollation gleichsetzt - `weiss*` sucht zusätzlich `weiß*`,
     * im Deutschen `Mue*` zusätzlich `Mü*` (und umgekehrt). Akzente und Groß-/
     * Kleinschreibung erledigt LIKE selbst.
     *
     *     alle Sprachen            ß = ss, æ = ae, œ = oe
     *     Deutsch (de_pb, german2) ä = ae, ö = oe, ü = ue
     *     Dänisch, Norwegisch      å = aa
     *
     * @param string $value      Suchwert (mit * als Platzhalter)
     * @param string $collation  Kollation des Vergleichs ('' = neutral)
     * @param int    $max        höchstens so viele Varianten (der Suchwert selbst zuerst)
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
        // Schreibweise (klein) => ihre Klasse
        $map = [];
        foreach ($classes as $class) {
            foreach ($class as $spelling) $map[$spelling] = $class;
        }

        // Suchwert zerlegen: feste Teile und austauschbare Stellen (je eine Klasse)
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
            // die Schreibweise des Suchwerts zuerst, dann die übrigen der Klasse
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
     * SQL-Text (schema.sql, Migrationen) für den Server anpassen: kennt er
     * utf8mb4_0900_ai_ci bzw. utf8mb4_0900_as_ci nicht (MariaDB), steht dort die
     * nächstbeste (NEUTRAL, FULLTEXT).
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
     * Text für den Volltextindex nach den Regeln einer Sprache falten: klein
     * geschrieben, jedes Zeichen durch das ersetzt, was die Sprache ihm gleichsetzt
     * (Stufe primär: ohne Akzente und Groß-/Kleinschreibung).
     *
     *     neutral    Müller -> muller    Café -> cafe    Straße -> strasse   Ørsted -> orsted
     *     Deutsch    Müller -> mueller   Café -> cafe    Straße -> strasse
     *     Schwedisch Förvaring -> förvaring (ö ist ein eigener Buchstabe)
     *     Dänisch    Ålesund -> aalesund, Ørsted -> ørsted
     *
     * Je Zeichen außerhalb von ASCII werden Kandidaten geprüft - die Grundform ohne
     * Akzente bzw. ihre lateinische Umschrift, dieselbe mit angehängtem e und doppelt
     * (ä: a, ae, aa) - und der erste genommen, den die Sprache gleichsetzt; sonst
     * bleibt das Zeichen. Text und Suchbegriff müssen mit derselben Sprache gefaltet
     * werden.
     *
     * @param string $text  Text (bereits in Wörter zerlegt, Cms::indexText())
     * @param string $code  Sprachkürzel (`language`.`code`)
     */
    public static function fold(string $text, string $code): string
    {
        $locale = self::icuLocale($code);
        $text = \Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text;
        // Türkisch: I -> ı, İ -> i
        static $turkish = null;
        if ($locale === 'tr') $turkish ??= \Transliterator::create('tr-Lower');
        $text = ($locale === 'tr' && $turkish) ? $turkish->transliterate($text) : mb_strtolower($text);
        return preg_replace_callback('/[^\x00-\x7F]/u', fn(array $m) => self::foldChar($m[0], $locale), $text) ?? $text;
    }

    /**
     * Falten zwei Sprachen gleich (fold())? Wenn nicht, passt der Volltext der einen
     * nicht zur Suche in der anderen.
     */
    public static function sameRules(string $codeA, string $codeB): bool
    {
        return self::icuLocale($codeA) === self::icuLocale($codeB);
    }

    /** ICU-Locale mit den Regeln, die forLocale() in MySQL wählt ('root' = neutral) */
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

    /** Ein Zeichen falten (fold()), je Locale zwischengespeichert */
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
        // für die Sprache bedeutungslos (etwa der Punkt aus İ -> i̇): entfällt
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
