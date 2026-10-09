<?php
/**
 * Display of the background processes in the header area of the backend.
 *
 * Entry points (scripts in backend/):
 *
 *     bgproc_list.php   -> bgprocList()   update list and counter
 *     bgproc_abort.php  -> bgprocAbort()  abort a process
 *
 * Long-running work - video conversions, mass deletions - moves into its own
 * operating system process (BackgroundProcess, table `bgproc`) and keeps running there
 * while the editor continues working in the backend. This class is the view
 * onto it.
 *
 * TWO RESPONSES FROM ONE METHOD
 * --------------------
 * bgprocList() is called in two situations and returns more or
 * less depending on them:
 *
 *     without details=1   only the JavaScript for the header area: counter in the badge and
 *                         the spinning symbol when something is running. The timer calls this
 *                         every second, so it should stay small.
 *     with  details=1    additionally the expanded list of the individual processes.
 *
 * Only the processes of the logged-in user are shown - the admin_id comes from
 * the session, not from the request.
 *
 * STATUS VALUES in `bgproc`
 * --------------------
 *     0    finished
 *     1    running
 *     > 1  failed (details in the JSON field `message`)
 *
 * @see Camindo\Core\BackgroundProcess  start, progress, abort
 * @see Camindo\Core\MassDeletion       one of the callers
 * @see Core/cron/cron.php               advances the progress of running processes
 */
namespace Camindo\Core\Modules\Start;

use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\Session;
use Camindo\Core\BackgroundProcess;
use Camindo\Core\Execute;

class StartBgproc {
    public function __construct(
        private Backend $backend,
        private Cms $cms,
        private Execute $execute,
        private BackgroundProcess $bgproc,
        private Session $session,
    )
    {}

    /**
     * List and counter of the background processes (backend/bgproc_list.php).
     *
     * Without details=1 only the JavaScript for counter and symbol, with details=1 also the
     * list (see class comment).
     *
     * The time shown per process is graded: what started in the last 24 hours shows only
     * the time of day, older items show the date. The timestamps are stored in UTC in the
     * database; for display, utcToUserTZ() converts them to the user's time zone.
     *
     *
     * Request: details = 0|1
     */
    public function bgprocList(): void
    {
        // Load processes of the session admin - clear finished ones beforehand: the
        // list only shows what is running or failed (list() first reconciles the
        // state, the rest is deleted afterwards)
        $adminId = (int)$this->session->get('admin_id');
        $processes = $this->bgproc->list($adminId);
        if ($this->bgproc->purgeFinishedOfAdmin($adminId) > 0) {
            $processes = array_values(array_filter(
                $processes,
                fn(array $p) => (int)$p['status'] !== BackgroundProcess::STATUS_FINISHED
                    || in_array((string)$p['type'], BackgroundProcess::TRIGGER_TYPES, true)
            ));
        }

        // ---- Output list ----
        $details = (int)($_REQUEST['details'] ?? 0);
        if ($details) {
            // Threshold for "older than 24 hours".
            // Note: gmdate() returns UTC, but the comparison below is with
            // $createdAt, which is already converted to the user's time zone.
            // With a deviation from UTC the limit shifts by
            // exactly this offset. Harmless - it is only about the choice between
            // time of day and date in the display.
            $before24h = gmdate('Y-m-d H:i:s', time() - 24 * 3600);
            $items = [];
            foreach ($processes as $process) {
                // Date is in UTC time in the database
                $createdAt = $this->cms->utcToUserTZ($process['created_at']);
                // Older than 24 hours? Then show with date
                if ($createdAt <= $before24h) {
                    $date = $this->cms->_formatDate(substr($createdAt, 0, 16), false);
                }
                else {
                    $date = $this->cms->_formatTime(substr($createdAt, 11, 5));
                }
                // Progress in %
                $progress = (int)($process['progress'] ?? 0);
                // Error?
                $status = (int)($process['status'] ?? 0);
                $class = 'success';
                $tooltip = '';
                // status
                //     0: finished
                //     1: running
                //     > 1: failed
                if ($status > 1) {
                    $class = 'danger';
                    $message = (array)(json_decode($process['message'], true) ?? []);
                    $tooltip = (string)($message['error'] ?? '');
                }
                $tplItem = $tooltip !== '' ? 'bgproc_list_item_tt' : 'bgproc_list_item';
                $items[] = $this->backend->template($tplItem, [
                '{date}' => $date,
                '{type}' => $process['type'],
                '{token}' => $process['token'],
                '{progress}' => $progress,
                '{class}' => $class,
                '{tooltip}' => htmlspecialchars($tooltip),
            ]);
            }
            if (empty($items)) $items = [$this->backend->template('bgproc_list_none')];
            $itemsHtml = implode("\n", $items);
            echo $this->backend->template('bgproc_list', [
                '{items}' => $itemsHtml
            ]);
        }

        // ---- Update icon ----
        // The badge counts only running processes and disappears at 0;
        // failed and not yet cleared finished ones (TRIGGER_TYPES)
        // stand only in the list
        $runningCount = 0;
        $failed = 0;
        foreach ($processes as $process) {
            if ((int)$process['status'] === BackgroundProcess::STATUS_RUNNING) $runningCount++;
            if ((int)$process['status'] > BackgroundProcess::STATUS_RUNNING) $failed++;
        }
        $running = $runningCount > 0;
        $count = min($runningCount, 99);
        if ($running) {
            $script = ["\$('#c9-bgproc-btn .icon').addClass('c9-spinning');"];
        }
        else {
            $script = ["\$('#c9-bgproc-btn .icon').removeClass('c9-spinning');"];
        }
        $script[] = "\$('#c9-bgproc-btn .c9-badge small').text({$count});";
        $script[] = "\$('#c9-bgproc-btn .c9-badge').toggle(" . ($running ? 'true' : 'false') . ");";
        // Monitoring in the browser: continue as long as something runs; the message
        // "completed" comes when nothing runs any more (cApp.polling)
        $script[] = "cApp.polling.bgprocState(" . ($running ? 'true' : 'false') . ", {$failed});";
        echo '<script' . $this->backend->nonceAttr() . '>' . implode("\n", $script) . "</script>";
    }

    /**
     * Abort a background process (backend/bgproc_abort.php).
     *
     * The token identifies the process and is strictly filtered to [a-z0-9_-] -
     * it goes into an operating system command.
     *
     * BackgroundProcess::abort() returns only once the process has really
     * ended, at most after three seconds. The response is only a
     * message bubble.
     *
     * Request: token
     */
    public function bgprocAbort(): void
    {
        // Aborting is a side effect - only via POST (developer docs, chapter 14 (Security: web))
        if (!$this->backend->requirePost()) {
            echo $this->backend->access()->deniedHtml();
            return;
        }

        $msgSuccess = $this->cms->snippet('start.message_bgproc_aborted');
        $msgError = $this->cms->snippet('start.message_bgproc_error');

        $rawToken = (string)($_REQUEST['token'] ?? '');
        $token = preg_replace('/[^a-z0-9_\\-]/i', '', $rawToken ?? '');
        // stopProcess waits until the process has ended or 3 sec timeout has elapsed
        if ($this->bgproc->abort($token)) {
            // Process was stopped
            $this->backend->balloon('danger', $msgSuccess);
        }
        else {
            // Process could not be stopped
            $this->backend->balloon('danger', $msgError);
        }
    }
}