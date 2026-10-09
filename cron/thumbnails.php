<?php
/**
 * thumbnails.php - Thumbnails eines Bereichs im Hintergrund neu erzeugen
 *
 * Wird NICHT per Cronjob aufgerufen, sondern von ToolThumbnails::start() (Tools ->
 * Daten -> "Thumbnails neu erzeugen", Schalter "Hintergrundprozess"). Läuft exakt
 * dieselbe Methode wie im Request: ToolThumbnails::run(); der Fortschritt geht über
 * das Token an `bgproc`, das Ergebnis als interne Nachricht an den Admin.
 *
 * Argumente:
 *     --area=123     Bereich
 *     --admin=10     auslösender Administrator (Empfänger der Nachricht)
 *     --token=...    Token des bgproc-Eintrags
 */
use Camindo\Core\BackgroundProcess;
use Camindo\Core\Cms;
use Camindo\Core\Session;
use Camindo\Core\System;
use Camindo\Core\Modules\Admin\Admin;
use Camindo\Core\Modules\Tools\ToolThumbnails;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}

set_time_limit(0);
ignore_user_abort(true);

define ('CAMINDO_STARTED', true);
define ('CAMINDO_CLI', true);

// Bootstrap laden
require_once dirname(__DIR__, 2) . '/load_camindo.php';

$args = [];
foreach (array_slice($argv, 1) as $argument) {
    if (!preg_match('/^--([a-z_]+)=(.*)$/', $argument, $matches)) continue;
    $args[$matches[1]] = $matches[2];
}
$areaId  = (int)($args['area'] ?? 0);
$adminId = (int)($args['admin'] ?? 0);
$token   = (string)($args['token'] ?? '');
if ($areaId < 1) {
    exit("usage: thumbnails.php --area=N --admin=N --token=...\n");
}

$app = $GLOBALS['app'];
$system = $app->get(System::class);
$bgproc = $app->get(BackgroundProcess::class);
$tool   = $app->get(ToolThumbnails::class);

$success = false;
try {
    // Auslösender Admin: changed_by, Empfänger der Nachricht, Sprache der Texte
    $app->get(Session::class)->setCliAdmin($adminId);   // (ohne Gruppenrechte: Werkzeug, das Recht "data" wurde beim Anstoßen geprüft)
    if ($adminId > 0 && ($adminData = $app->get(Admin::class)->load($adminId))) {
        $app->get(Cms::class)->applyAdminSettings((array)($adminData['settings'] ?? []));
    }

    $result = $tool->run($areaId, function (int $done, int $total) use ($bgproc, $token): void {
        if ($token === '') return;
        $bgproc->setProgressByToken($token, $total > 0 ? (int)floor($done * 100 / $total) : 100, "{$done}/{$total}");
    });
    $subject = $tool->report($areaId, $adminId, $result);
    if ($result['error'] !== '') {
        throw new \RuntimeException((string)$result['error']);
    }
    echo $subject . "\n" . $tool->summaryText($result) . "\n";
    $success = ((int)$result['failed'] === 0);
} catch (\Throwable $e) {
    $system->log('thumbnails-errors', "thumbnails {$token}: " . $e->getMessage());
    echo "exception: " . $e->getMessage() . "\n";
} finally {
    if ($token !== '') {
        $bgproc->setStatusByToken(
            $token,
            $success ? BackgroundProcess::STATUS_FINISHED : BackgroundProcess::STATUS_FAILED
        );
    }
}

echo $success ? "done.\n" : "failed.\n";
exit($success ? 0 : 1);
