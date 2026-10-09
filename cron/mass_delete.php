<?php
/**
 * mass_delete.php - Massen-Löschung / -Wiederherstellung im Hintergrund
 *
 * Wird NICHT per Cronjob aufgerufen, sondern von Camindo\Core\MassDeletion gestartet,
 * wenn ein Vorgang im Backend mehr Datensätze umfasst als admin.mass_delete_threshold.
 *
 * Das Skript ruft exakt dieselbe Dataset-Methode auf, die auch der Web-Request
 * verwenden würde:
 *
 *     mode=delete   ->  $dataset->delete($id, $withChilds)     Soft-Delete
 *     mode=restore  ->  $dataset->restore($id, $withChilds)    Wiederherstellen
 *     mode=purge    ->  $dataset->purge($id, $withChilds)      endgültig löschen
 *
 * Es gibt also keinen zweiten Löschpfad - der Hintergrundprozess verlagert die Arbeit
 * nur aus dem Request heraus. Jeder betroffene Datensatz läuft einzeln durch seine
 * Hooks, genau wie im Vordergrund.
 *
 * Argumente (alle Pflicht außer --token):
 *     --table=area|record     Tabelle des angeklickten Datensatzes
 *     --id=123                dessen Id
 *     --mode=delete|restore|purge
 *     --withchilds=0|1        Kind-Datensätze einbeziehen
 *     --admin=10              Id des auslösenden Administrators (landet in changed_by)
 *     --token=...             Token des bgproc-Eintrags, falls vorhanden
 *
 * Rückmeldung an das Backend läuft über die Tabelle `bgproc` (Fortschrittsanzeige im
 * Header). Fehler landen zusätzlich in var/log/{Y-m-d}_massdelete-errors.log.
 */
use Camindo\Core\BackgroundProcess;
use Camindo\Core\Session;
use Camindo\Core\System;
use Camindo\Core\Cms;
use Camindo\Core\Modules\Area\Area;
use Camindo\Core\Modules\Record\Record;

// Nur auf der Kommandozeile erreichbar, nicht per Webserver
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}

// --------------------

set_time_limit(0);
ignore_user_abort(true);

define ('CAMINDO_STARTED', true);
define ('CAMINDO_CLI', true);

// Bootstrap laden
require_once dirname(__DIR__, 2) . '/load_camindo.php';

// --------------------
// Argumente einlesen (--name=wert)

$args = [];
foreach (array_slice($argv, 1) as $argument) {
    if (!preg_match('/^--([a-z_]+)=(.*)$/', $argument, $matches)) continue;
    $args[$matches[1]] = $matches[2];
}

$table      = (string)($args['table'] ?? '');
$id         = (int)($args['id'] ?? 0);
$mode       = (string)($args['mode'] ?? '');
$withChilds = (bool)(int)($args['withchilds'] ?? 0);
$adminId    = (int)($args['admin'] ?? 0);
$token      = (string)($args['token'] ?? '');

if (!in_array($table, ['area', 'record'], true)
    || !in_array($mode, ['delete', 'restore', 'purge'], true)
    || $id < 1
) {
    exit("usage: mass_delete.php --table=area|record --id=N --mode=delete|restore|purge --withchilds=0|1 --admin=N\n");
}

// Container bereitstellen
$app = $GLOBALS['app'];

$system = $app->get(System::class);
$cms    = $app->get(Cms::class);
$bgproc = $app->get(BackgroundProcess::class);

// Auslösenden Administrator hinterlegen, damit changed_by stimmt
$app->get(Session::class)->setCliAdmin($adminId, withAccess: true);   // Rechte des auslösenden Admins

// Passendes Dataset holen
$dataset = ($table === 'area')
    ? $app->get(Area::class)
    : $app->get(Record::class);

$success = false;
try {
    $success = match ($mode) {
        'delete'  => $dataset->delete($id, $withChilds),
        'restore' => $dataset->restore($id, $withChilds),
        'purge'   => $dataset->purge($id, $withChilds),
    };

    if (!$success) {
        $message = "{$mode} {$table} {$id}: " . $cms->errorMsg(' | ');
        $system->log('massdelete-errors', $message);
        echo "error: {$message}\n";
    }
} catch (\Throwable $e) {
    $system->log('massdelete-errors', "{$mode} {$table} {$id}: " . $e->getMessage());
    echo "exception: " . $e->getMessage() . "\n";
} finally {
    // Eigenen bgproc-Eintrag abschließen, damit die Anzeige im Backend aufräumt
    if ($token !== '') {
        $bgproc->setStatusByToken(
            $token,
            $success ? BackgroundProcess::STATUS_FINISHED : BackgroundProcess::STATUS_FAILED
        );
    }
}

echo $success ? "done.\n" : "failed.\n";
exit($success ? 0 : 1);
