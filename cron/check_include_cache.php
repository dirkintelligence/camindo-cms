<?php
/**
 * check_include_cache.php - self-test for the output cache of CMS:INCLUDE
 *
 *     php Core/cron/check_include_cache.php
 *
 * Checks without database contents:
 *   - IncludeCache::ttl() (caching=300, 5m, 1h, 1d, infinite, invalid values)
 *   - IncludeCache::paramString() (cacheparams null/bool/int/string/array)
 *   - the compiler: cacheparams=$var stays a PHP expression ('php_value'),
 *     other parameters and quoted values stay strings
 *   - start()/finish(): recording, hit, nonce placeholder, no storing when
 *     $store = false, invalidity after a change of the template file
 * The hit test writes under var/cache/include/0/zz_selftest and cleans up afterwards.
 * Exit code 1 on deviations.
 */
use Camindo\Core\IncludeCache;
use Camindo\Core\Modules\Template\Template;

// Reachable only on the command line, not via the web server
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}

define('CAMINDO_STARTED', true);
define('CAMINDO_CLI', true);

// Load bootstrap
require_once dirname(__DIR__, 2) . '/load_camindo.php';

$failed = 0;
$count = 0;
$check = function (string $label, mixed $got, mixed $expected) use (&$failed, &$count): void {
    $count++;
    $ok = ($got === $expected);
    if (!$ok) $failed++;
    printf("%s  %-44s %s\n", $ok ? 'ok  ' : 'FAIL', $label,
        $ok ? '' : 'erwartet ' . var_export($expected, true) . ', erhalten ' . var_export($got, true));
};
$ttl = function (mixed $value): int|string|null {
    try {
        return IncludeCache::ttl($value);
    }
    catch (\InvalidArgumentException) {
        return 'invalid';
    }
};

// caching
foreach ([
    ['300', 300], ['300s', 300], ['5m', 300], ['1h', 3600], ['1d', 86400], [' 2H ', 7200],
    ['infinite', 0], ['', null], ['0', null], [null, null], [90, 90],
    ['5x', 'invalid'], ['-5', 'invalid'], ['1.5h', 'invalid'], ['forever', 'invalid'],
] as [$value, $expected]) {
    $check('caching=' . var_export($value, true), $ttl($value), $expected);
}

// cacheparams
$check('cacheparams null', IncludeCache::paramString(null), '');
$check('cacheparams true', IncludeCache::paramString(true), '1');
$check('cacheparams 42', IncludeCache::paramString(42), '42');
$check('cacheparams "abc"', IncludeCache::paramString('abc'), 'abc');
$check('cacheparams Array', IncludeCache::paramString(['id' => 5, 'tags' => ['a', 'ä']]), '{"id":5,"tags":["a","ä"]}');
$check('Reihenfolge zählt', IncludeCache::key(1, 1, IncludeCache::paramString(['a' => 1, 'b' => 2]))
    === IncludeCache::key(1, 1, IncludeCache::paramString(['b' => 2, 'a' => 1])), false);
$check('Sprache zählt', IncludeCache::key(1, 1, 'x') === IncludeCache::key(1, 2, 'x'), false);

// Compiler: php_value
$parsed = $app->get(Template::class)->parse(
    '(CMS:INCLUDE teaser caching=5m cacheparams=$params)'
    . '(CMS:INCLUDE teaser caching=1h cacheparams=$cms[\'record_id\'])'
    . '(CMS:INCLUDE teaser caching=1h cacheparams={$set["page"]})'
    . '(CMS:INCLUDE teaser caching=1h cacheparams="id-$id")'
    . '(CMS:INCLUDE teaser caching=$ttl)',
    0
);
$php = (string)($parsed['phpCode'] ?? '');
$check('Parser ohne Fehler', $parsed['errors'] ?? null, []);
$check('cacheparams=$params', str_contains($php, '$_a["cacheparams"] = $params;'), true);
$check('cacheparams=$cms[\'record_id\']', str_contains($php, '$_a["cacheparams"] = $cms[\'record_id\'];'), true);
$check('cacheparams={$set["page"]}', str_contains($php, '$_a["cacheparams"] = $set["page"];'), true);
$check('cacheparams="id-$id" bleibt String', str_contains($php, '$_a["cacheparams"] = "id-$id";'), true);
$check('caching=$ttl bleibt String', str_contains($php, '$_a["caching"] = "$ttl";'), true);
$check('Abschluss includeEnd()', substr_count($php, '$this->cms->includeEnd();'), 5);

// start() / finish()
$cache = $app->get(IncludeCache::class);
$config = $app->get(\Camindo\Core\Config::class);
$templateFile = '0/zz_selftest';
$source = rtrim((string)$config->get('paths.tmp_dir'), '/') . '/zz_selftest_source.php';
file_put_contents($source, '');
touch($source, time() - 10);
$cache->clear($templateFile);
$key = IncludeCache::key(0, 0, 'selftest');
$nonceA = str_repeat('a', 32);
$nonceB = str_repeat('b', 32);

$first = $cache->start($templateFile, $key, 60, $source, $nonceA);
echo 'Inhalt <script nonce="' . $nonceA . '"></script>';
$output = $cache->finish(true, $nonceA);
$check('erster Aufruf: kein Treffer', $first, null);
$check('finish() gibt Ausgabe zurück', $output, 'Inhalt <script nonce="' . $nonceA . '"></script>');
$check('Treffer mit neuer Nonce', $cache->start($templateFile, $key, 60, $source, $nonceB), 'Inhalt <script nonce="' . $nonceB . '"></script>');

$key2 = IncludeCache::key(0, 0, 'selftest-error');
$cache->start($templateFile, $key2, 60, $source, $nonceA);
echo 'mit Fehler';
$cache->finish(false, $nonceA);
$again = $cache->start($templateFile, $key2, 60, $source, $nonceA);
$cache->finish(false, $nonceA);
$check('$store = false legt nichts ab', $again, null);

touch($source, time() + 5);
clearstatcache();
$changed = $cache->start($templateFile, $key, 60, $source, $nonceA);
$cache->finish(false, $nonceA);
$check('geänderte Template-Datei: kein Treffer', $changed, null);

$check('clear()', $cache->clear($templateFile), 1);
@unlink($source);
$check('Verzeichnis entfernt', is_dir($cache->dir() . '/0'), false);

echo $failed ? "\n$failed Abweichung(en)\n" : "\nalle $count Fälle ok\n";
exit($failed ? 1 : 0);
