<?php
/**
 * trigger_ff.php - update the progress of the ffmpeg background processes
 *
 * ffmpeg writes continuously with "-progress FILE" blocks of "key=value" lines
 * into a temporary file under paths.tmp_dir. The file name results from the token
 * of the background process, see TriggerMedia::progressFileName().
 *
 * This script
 *   - looks up all entries in `bgproc` with `type` = 'ffmpeg',
 *   - opens the associated progress file and reads its last lines,
 *   - determines the current frame from it,
 *   - compares it with the total number of frames from `message`,
 *   - enters the progress in percent in `progress`,
 *   - aborts overdue processes and removes finished progress files.
 *
 * Call via cron job, sensibly every minute:
 *     * * * * * /usr/bin/php ...trigger_ff.php >/dev/null 2>&1
 *  
 * The script is deliberately frugal: it only reads the end of each file.
 * It keeps itself from overtaking itself during long runs with a lock file.
 * It only starts further background processes if dependent thumbnails exist for the
 * finished files.
 */
use Camindo\Core\cron\TriggerFFmpeg;

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
$triggerFFmpeg = $app->get(TriggerFFmpeg::class);
$triggerFFmpeg->check();