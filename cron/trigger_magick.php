<?php
/**
 * trigger_magick.php - update the progress of the ImageMagick background processes
 *
 * magick writes blocks continuously with "-monitor" to stderr -> redirected
 * into a temporary file under paths.tmp_dir. The file name results from the token
 * of the background process, see TriggerMedia::progressFileName().
 *
 * This script
 *   - looks up all entries in `bgproc` with `type` = 'magick',
 *   - opens the associated progress file and takes the progress in %
 *   - enters the progress in `progress`,
 *   - aborts overdue processes and removes finished progress files.
 *
 * Call via cron job, sensibly every minute:
 *     * * * * * /usr/bin/php ...trigger_magick.php >/dev/null 2>&1
 *
 * The script is deliberately frugal: it only reads the end of each file.
 * It keeps itself from overtaking itself during long runs with a lock file.
 * It only starts further background processes if dependent thumbnails exist for the
 * finished files.
 */
use Camindo\Core\cron\TriggerMagick;

// --------------------

// Reachable only on the command line, not via the web server
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}

// --------------------

define ('CAMINDO_STARTED', true);
define ('CAMINDO_CLI', true);

// Load bootstrap
require_once dirname(__DIR__, 2) . '/load_camindo.php';

// Provide the container
$app = $GLOBALS['app'];
$triggerMagick = $app->get(TriggerMagick::class);
$triggerMagick->check();