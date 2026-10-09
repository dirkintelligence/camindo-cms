<?php
/**
 * check_nested_select.php - Selbsttest für verschachtelte Ergebnisschleifen
 *
 *     php Core/cron/check_nested_select.php
 *
 * Ein CMS:SELECT oder CMS:SELECT_LANGVERSION innerhalb einer CMS:RESULT_START-Schleife,
 * dessen resulttemplate wieder eine Schleife enthält, muss neben dem Datenbankergebnis
 * auch den Zeilenzähler Cms::$row_no und die Template-Variable $cms wiederherstellen
 * (php/push_result.php, php/pop_result.php -> Cms::pushRow()/popRow()). Sonst
 * überspringt die äußere Schleife Zeilen oder läuft endlos, und CMS:TEXT liest nach
 * dem SELECT die letzte innere Zeile statt des äußeren Datensatzes.
 *
 * Die Templates werden mit dem echten Parser kompiliert und ausgeführt; nur die
 * Abfragen selbst sind durch feste Zeilen (Database::setArrayResult()) ersetzt - der
 * Test braucht also keine Datensätze in der Datenbank. Exit-Code 1 bei Abweichungen.
 */
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Database;
use Camindo\Core\Modules\Template\Template;

// Nur auf der Kommandozeile erreichbar, nicht per Webserver
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}

define('CAMINDO_STARTED', true);
define('CAMINDO_CLI', true);

// Bootstrap laden
require_once dirname(__DIR__, 2) . '/load_camindo.php';

$cms = null;
$set = [];
$realCms  = $app->get(Cms::class);
$db       = $app->get(Database::class);
$template = $app->get(Template::class);

// Zeilen der Abfragen je resulttemplate; 'cache' = '{}' hält Cms::fetch() davon ab,
// den Inhalts-Cache nachzuladen
$row = fn(int $id) => ['record_id' => $id, 'area_id' => 0, 'cache' => '{}'];
$results = [
    't_outer' => [$row(1), $row(2), $row(3), $row(4)],
    't_inner' => [$row(11), $row(12)],
    't_empty' => [],
];
$languages = [['language_id' => 1, 'code' => 'en'], ['language_id' => 2, 'code' => 'de']];

// Quelltexte. t_page steht für ein Detailtemplate (etwa eine Navigation im Kopf),
// t_outer für ein Listentemplate, das selbst wieder CMS:SELECT enthält. Der Zähler
// $GLOBALS['checkGuard'] bricht eine endlos laufende Schleife ab.
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

// Kompilieren
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

// Stellvertreter für Cms: alles geht an das echte Objekt, nur die beiden Abfragen
// liefern die festen Zeilen und das Template-Verzeichnis ist das temporäre
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

// Ausführen wie Page: $this->cms / $this->db, $cms und $set global
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
