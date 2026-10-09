<?php
/**
 * thumbnails.php - regenerate thumbnails of an area in the background
 *
 * NOT called by a cron job, but by ToolThumbnails::start() (Tools ->
 * Data -> "Regenerate thumbnails", switch "Background process"). Runs exactly
 * the same method as in the request: ToolThumbnails::run(); the progress goes via
 * the token to `bgproc`, the result as an internal message to the admin.
 *
 * Arguments:
 *     --area=123     area
 *     --admin=10     triggering administrator (recipient of the message)
 *     --token=...    token of the bgproc entry
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

// Load bootstrap
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
    // Triggering admin: changed_by, recipient of the message, language of the texts
    $app->get(Session::class)->setCliAdmin($adminId);   // (without group rights: a tool; the right "data" was checked when it was started)
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
