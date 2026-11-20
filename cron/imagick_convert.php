<?php
/**
 * imagick_convert.php - one conversion with the PHP extension Imagick in a process of its own
 *
 * NOT called by a cron job, but by the processor Magick (engine 'imagick', and the
 * fallback without a program): Magick::workerCommand() writes the job file and starts this
 * script - in the foreground with a time limit (Execute), or in the background, registered
 * in `bgproc` (type 'magick'), followed by TriggerMagick. See "WORKER PROCESS" in Magick.
 *
 * The process does exactly one conversion, so ImageMagick's time limit (-limit time) can be
 * set here as well; PHP's own limit is set to the timeout of the job.
 *
 * Output (stderr; in the background the progress file of TriggerMagick):
 *     Imagick: 42% complete       progress, as "magick -monitor" writes it (job 'monitor')
 *     Imagick: 100% complete      written only after a successful write
 *     error: <message>            on failure; exit code 1
 *
 * Arguments:
 *     --job=/path/var/tmp/imagick-job-{token}.json   job file (required, removed when read)
 */
use Camindo\Core\Config;
use Camindo\Core\Processors\Magick\ImagickCommand;
use Camindo\Core\Processors\Magick\Magick;

// Reachable only on the command line, not via the web server
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}

define('CAMINDO_STARTED', true);
define('CAMINDO_CLI', true);

// Load bootstrap
require_once dirname(__DIR__, 2) . '/load_camindo.php';

$fail = function (string $message): never {
    fwrite(STDERR, 'error: ' . str_replace(["\r", "\n"], ' ', $message) . "\n");
    exit(1);
};

$jobFile = '';
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--job=(.+)$/', $argument, $matches)) $jobFile = $matches[1];
}

// Only job files of the processor from paths.tmp_dir
$tmpDir = realpath((string)$GLOBALS['app']->get(Config::class)->get('paths.tmp_dir'));
$real = $jobFile !== '' ? realpath($jobFile) : false;
if ($tmpDir === false || $real === false || dirname($real) !== $tmpDir
    || !preg_match('/^' . preg_quote(Magick::JOB_PREFIX, '/') . '[A-Za-z0-9_-]+\.json$/', basename($real))
) {
    $fail('job file not found: ' . $jobFile);
}
$job = json_decode((string)file_get_contents($real), true);
@unlink($real);
if (!is_array($job) || empty($job['src_file']) || empty($job['dest_file'])) {
    $fail('job file not readable');
}
if (!ImagickCommand::available()) {
    $fail('PHP extension imagick not loaded');
}

$timeout = (int)($job['timeout'] ?? 0);
if ($timeout > 0) set_time_limit($timeout);

$command = new ImagickCommand(timeLimit: true);
if (!empty($job['monitor'])) {
    fwrite(STDERR, "Imagick: 0% complete\n");
    $command->monitor(function (int $percent): void {
        fwrite(STDERR, "Imagick: {$percent}% complete\n");
    });
}

$destFile = (string)$job['dest_file'];
$error = '';
$ok = $command->convert(
    (string)($job['src_coder'] ?? ''),
    (string)$job['src_file'],
    (bool)($job['first_frame'] ?? true),
    array_map('strval', (array)($job['before'] ?? [])),
    array_map('strval', (array)($job['after'] ?? [])),
    (string)($job['dest_coder'] ?? ''),
    $destFile,
    (array)($job['limits'] ?? []),
    $error
);
if (!$ok) {
    if (is_file($destFile)) @unlink($destFile);
    $fail($error !== '' ? $error : 'conversion failed');
}
fwrite(STDERR, "Imagick: 100% complete\n");
exit(0);
