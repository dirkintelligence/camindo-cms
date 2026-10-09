<?php
/**
 * check_nested_select.php - self-test for nested result loops
 *
 *     php Core/cron/check_nested_select.php
 *
 * A CMS:SELECT or CMS:SELECT_LANGVERSION inside a CMS:RESULT_START loop,
 * whose resulttemplate in turn contains a loop, must restore - besides the database result -
 * the row counter Cms::$row_no and the template variable $cms
 * (php/push_result.php, php/pop_result.php -> Cms::pushRow()/popRow()). Otherwise
 * the outer loop skips rows or runs endlessly, and CMS:TEXT reads the last inner row
 * instead of the outer record after the SELECT.
 *
 * The templates are compiled and run with the real parser; only the
 * queries themselves are replaced by fixed rows (Database::setArrayResult()) - the
 * test thus needs no records in the database. Exit code 1 on deviations.
 */
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Database;
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

$cms = null;
$set = [];
$realCms  = $app->get(Cms::class);
$db       = $app->get(Database::class);
$template = $app->get(Template::class);

// Rows of the queries per resulttemplate; 'cache' = '{}' keeps Cms::fetch() from
// reloading the content cache
$row = fn(int $id) => ['record_id' => $id, 'area_id' => 0, 'cache' => '{}'];
$results = [
    't_outer' => [$row(1), $row(2), $row(3), $row(4)],
    't_inner' => [$row(11), $row(12)],
    't_empty' => [],
];
$languages = [['language_id' => 1, 'code' => 'en'], ['language_id' => 2, 'code' => 'de']];

// Source texts. t_page stands for a detail template (e.g. a navigation in the header),
// t_outer for a list template that itself contains CMS:SELECT again. The counter
// $GLOBALS['checkGuard'] aborts an endlessly running loop.
$sources = [
    't_page'  => '<?php $cms = ["record_id" => 99]; ?>'
        . '(CMS:SELECT t_outer filter[area]=x)'
        . '|end:(CMS:TEXT record_id):<?php echo $this->cms->row_no; ?>',
    't_outer' => '(CMS:RESULT_START)'
        . '[(CMS:TEXT record_id)@<?php echo $this->cms->row_no; ?>:'
        . '(CMS:SELECT t_inner filter[area]=x)'
        . '(CMS:SELECT t_empty filter[area]=x noresult="-")'
        . '(CMS:SELECT_LANGVERSION t_lang)'
        . '=(CMS:TEXT record_id)@<?php echo $this->cms->row_no; ?>]'
        . '<?php if (++$GLOBALS["checkGuard"] > 20) break; ?>'
        . '(CMS:RESULT_END)',
    't_inner' => '(CMS:RESULT_START)i(CMS:TEXT record_id)(CMS:RESULT_END)',
    't_lang'  => '(CMS:RESULT_START)l<?php echo $cms["code"]; ?>(CMS:RESULT_END)',
];
$expected = '';
foreach ([1, 2, 3, 4] as $i => $id) $expected .= "[{$id}@{$i}:i11i12-lenlde={$id}@{$i}]";
$expected .= '|end:99:0';

// Compile
$dir = $app->get(Config::class)->get('paths.var_dir') . '/tmp/check_nested_select_' . bin2hex(random_bytes(4));
mkdir($dir, 0775, true);
$failed = 0;
foreach ($sources as $name => $code) {
    $result = $template->parse($code, 0);
    foreach ($result['errors'] as $error) {
        $failed++;
        echo "FAIL compile $name: $error\n";
    }
    file_put_contents("$dir/$name.php", $result['phpCode']);
}

// Stand-in for Cms: everything goes to the real object, only the two queries
// return the fixed rows and the template directory is the temporary one
$proxy = new class($realCms, $db, $dir, $results, $languages) {
    public function __construct(
        private Cms $cms, private Database $db, public string $templatePath,
        private array $results, private array $languages
    ) {}
    public function __get(string $name): mixed { return $this->cms->$name; }
    public function __set(string $name, mixed $value): void { $this->cms->$name = $value; }
    public function __call(string $name, array $args): mixed { return $this->cms->$name(...$args); }
    public function cmsSelect(array $p): string
    {
        $name = (string)$p['resulttemplate'];
        $this->db->setArrayResult($this->results[$name]);
        if ($this->db->num_rows > 0) return $name;
        echo $p['noresult'] ?? '';
        return '';
    }
    public function cmsSelectLanguages(array $p): string
    {
        $this->db->setArrayResult($this->languages);
        return (string)$p['resulttemplate'];
    }
};

// Run like Page: $this->cms / $this->db, $cms and $set global
$page = new class($proxy, $db) {
    public function __construct(public object $cms, public Database $db) {}
    public function render(string $file): string
    {
        global $cms, $set;
        ob_start();
        try {
            require $file;
        }
        finally {
            $output = (string)ob_get_clean();
        }
        return $output;
    }
};

$stackDepth = fn() => count($this->stack);
$rowDepth   = fn() => count($this->rowStack);
$dbDepthBefore = $stackDepth->call($db);
$GLOBALS['checkGuard'] = 0;
try {
    $output = preg_replace('/\s+/', '', $page->render("$dir/t_page.php"));
}
catch (\Throwable $e) {
    $output = 'exception: ' . $e->getMessage();
}
finally {
    array_map('unlink', glob("$dir/*.php"));
    rmdir($dir);
}

$checks = [
    'output'           => [$output, $expected],
    'Database stack'   => [$stackDepth->call($db), $dbDepthBefore],
    'Cms row stack'    => [$rowDepth->call($realCms), 0],
    '$cms after page'  => [$GLOBALS['cms']['record_id'] ?? null, 99],
];
foreach ($checks as $label => [$actual, $want]) {
    $ok = $actual === $want;
    if (!$ok) $failed++;
    printf("%s %-16s %s\n", $ok ? 'ok  ' : 'FAIL', $label,
        var_export($actual, true) . ($ok ? '' : "\n     expected         " . var_export($want, true)));
}
echo $failed ? "$failed checks failed\n" : "all " . count($checks) . " checks passed\n";
exit($failed ? 1 : 0);
