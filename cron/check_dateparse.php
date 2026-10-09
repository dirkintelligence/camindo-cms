<?php
/**
 * check_dateparse.php - Selbsttest für Dataset::dateInputToIso() / timeInputToIso()
 *
 *     php Core/cron/check_dateparse.php
 *
 * Prüft die locale-abhängige Erkennung (IntlDateFormatter) für en_US, de_AT,
 * en_GB, ar_SA und bn_BD mit und ohne Uhrzeit - bei ar_SA/bn_BD mit eigenen und mit
 * lateinischen Ziffern. Insbesondere: ein Datum mit Uhrzeit darf nie über ein
 * reines Datumsmuster (Präfix-Treffer) zu 00:00:00 werden, und das getippte normale
 * Leerzeichen vor "PM" muss wie das geschützte (U+202F, ICU 72) akzeptiert werden.
 * Exit-Code 1 bei Abweichungen.
 */
use Camindo\Core\Dataset;
use Camindo\Core\Modules\Record\Record;

// Nur auf der Kommandozeile erreichbar, nicht per Webserver
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}

define('CAMINDO_STARTED', true);
define('CAMINDO_CLI', true);

// Bootstrap laden
require_once dirname(__DIR__, 2) . '/load_camindo.php';

// Die Parser sind protected - über eine an Dataset gebundene Closure aufrufen
$call = \Closure::bind(
    fn(string $method, string $value) => $this->$method($value),
    $app->get(Record::class),
    Dataset::class
);

// [Locale, Methode, Eingabe, erwartetes Ergebnis]
$cases = [
    ['en_US', 'dateInputToIso', "Sep 24, 2026, 2:30 PM",          '2026-09-24 14:30:00'],
    ['en_US', 'dateInputToIso', "Sep 24, 2026, 2:30\u{202F}PM",   '2026-09-24 14:30:00'],
    ['en_US', 'dateInputToIso', "Sep 24, 2026 2:30 PM",           '2026-09-24 14:30:00'],
    ['en_US', 'dateInputToIso', "Sep 24, 2026",                   '2026-09-24 00:00:00'],
    ['en_US', 'dateInputToIso', "9/24/26, 2:30 PM",               '2026-09-24 14:30:00'],
    ['en_US', 'dateInputToIso', "9/24/26",                        '2026-09-24 00:00:00'],
    ['en_US', 'dateInputToIso', "September 24, 2026 at 2:30 PM",  '2026-09-24 14:30:00'],
    ['en_US', 'timeInputToIso', "2:30 PM",                        '14:30:00'],
    ['en_US', 'timeInputToIso', "2:30\u{202F}PM",                 '14:30:00'],
    ['en_US', 'timeInputToIso', "2:30:15 PM",                     '14:30:15'],
    ['en_US', 'timeInputToIso', "2:30 PM extra",                  null],

    ['de_AT', 'dateInputToIso', "24.09.2026, 14:30",              '2026-09-24 14:30:00'],
    ['de_AT', 'dateInputToIso', "24.09.2026 14:30",               '2026-09-24 14:30:00'],
    ['de_AT', 'dateInputToIso', "24.09.2026 14:30:15",            '2026-09-24 14:30:15'],
    ['de_AT', 'dateInputToIso', "24.09.2026",                     '2026-09-24 00:00:00'],
    ['de_AT', 'dateInputToIso', "24.09.26",                       '2026-09-24 00:00:00'],
    ['de_AT', 'dateInputToIso', "24. März 2026",                  '2026-03-24 00:00:00'],
    ['de_AT', 'dateInputToIso', "24. März 2026 um 14:30",         '2026-03-24 14:30:00'],
    ['de_AT', 'dateInputToIso', "24.09.2026 foo",                 null],
    ['de_AT', 'timeInputToIso', "14:30",                          '14:30:00'],
    ['de_AT', 'timeInputToIso', "14:30:15",                       '14:30:15'],
    ['de_AT', 'timeInputToIso', "14:30 Uhr",                      null],

    ['en_GB', 'dateInputToIso', "24/09/2026, 14:30",              '2026-09-24 14:30:00'],
    ['en_GB', 'dateInputToIso', "24/09/2026 14:30",               '2026-09-24 14:30:00'],
    ['en_GB', 'dateInputToIso', "24/09/2026",                     '2026-09-24 00:00:00'],
    ['en_GB', 'dateInputToIso', "24 Sept 2026, 14:30",            '2026-09-24 14:30:00'],
    ['en_GB', 'dateInputToIso', "24 Sept 2026 14:30",             '2026-09-24 14:30:00'],
    ['en_GB', 'dateInputToIso', "24 Sept 2026",                   '2026-09-24 00:00:00'],
    ['en_GB', 'timeInputToIso', "14:30",                          '14:30:00'],
    ['en_GB', 'timeInputToIso', "2:30 pm",                        '14:30:00'],
    // 24-Stunden-Zeit in einer Locale mit 12-Stunden-Format: Datum und Zeit getrennt
    ['en_US', 'dateInputToIso', "9/24/2026 14:30",                '2026-09-24 14:30:00'],
    // Eigene Ziffern (so zeigt das Backend an) und lateinische Ziffern, ohne Ära/RLM
    ['ar_SA', 'dateInputToIso', "٢٤ سبتمبر ٢٠٢٦ م",                '2026-09-24 00:00:00'],
    ['ar_SA', 'dateInputToIso', "24/09/2026",                     '2026-09-24 00:00:00'],
    ['ar_SA', 'dateInputToIso', "24/09/2026 2:30 م",               '2026-09-24 14:30:00'],
    ['ar_SA', 'dateInputToIso', "٢٠٢٦-٠٩-٢٤",                      '2026-09-24 00:00:00'],
    ['ar_SA', 'timeInputToIso', "٢:٣٠ م",                          '14:30:00'],
    ['ar_SA', 'timeInputToIso', "14:30",                          '14:30:00'],
    ['bn_BD', 'dateInputToIso', "২৪ সেপ, ২০২৬",                     '2026-09-24 00:00:00'],
    ['bn_BD', 'dateInputToIso', "24/9/2026",                      '2026-09-24 00:00:00'],
];

$failed = 0;
foreach ($cases as [$locale, $method, $input, $expected]) {
    \Locale::setDefault($locale);
    $result = $call($method, $input);
    $ok = $result === $expected;
    if (!$ok) $failed++;
    printf("%s %s %-4s %-36s -> %s\n",
        $ok ? 'ok  ' : 'FAIL', $locale, substr($method, 0, 4), json_encode($input),
        var_export($result, true) . ($ok ? '' : "  (expected " . var_export($expected, true) . ")"));
}
echo $failed ? "$failed of " . count($cases) . " checks failed\n" : "all " . count($cases) . " checks passed\n";
exit($failed ? 1 : 0);
