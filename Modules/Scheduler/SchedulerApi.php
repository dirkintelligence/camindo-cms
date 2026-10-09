<?php
/**
 * API methods of the scheduler: list, read, create, change, run now.
 *
 * Which settings apply under details depends on the type (url, php, dbbackup,
 * logcleanup, cachecleanup, template, fulltext; the maintenance tasks
 * Scheduler::MAINTENANCE_TYPES have none). The password of a URL task
 * (details.password) is not written by the API - credentials do not belong in a
 * chat; it stays as it is and is set in the UI.
 *
 * @see Camindo\Core\Api\DatasetApi
 */
namespace Camindo\Core\Modules\Scheduler;

use Camindo\Core\Api\ApiException;
use Camindo\Core\Api\ApiMethod;
use Camindo\Core\Api\DatasetApi;
use Camindo\Core\Api\DatasetWriter;

class SchedulerApi extends DatasetApi
{
    protected const MODULE = 'Scheduler';
    protected const SCRIPTS = ['list' => 'scheduler_list.php', 'info' => 'scheduler_info.php', 'edit' => 'scheduler_edit.php'];
    protected const FIELDS = [
        'label', 'type', 'admin_id', 'enabled', 'max_minutes', 'log',
        'cron_minute', 'cron_hour', 'cron_day', 'cron_month', 'cron_year', 'cron_weekday',
        'details', 'next_run', 'last_run', 'last_status', 'last_message',
    ];
    /** Scheduler::editableFields() without type, details individually without password */
    protected const WRITABLE = [
        'label', 'admin_id', 'enabled', 'max_minutes', 'log',
        'cron_minute', 'cron_hour', 'cron_day', 'cron_month', 'cron_year', 'cron_weekday',
        'details.url', 'details.auth', 'details.user', 'details.template_id', 'details.language_id', 'details.output',
        'details.output_file', 'details.script', 'details.backups', 'details.log_days', 'details.security_days', 'details.attempt_days',
        'details.area_ids', 'details.only_stale',
    ];
    protected const HINTS = ['type' => 'parameter type of scheduler.create', 'next_run' => 'set by the server',
        'last_run' => 'set by the server', 'last_status' => 'set by the server', 'last_message' => 'set by the server'];

    public function __construct(Scheduler $scheduler, DatasetWriter $writer, private SchedulerRunner $runner)
    {
        parent::__construct($scheduler, $writer);
    }

    protected function options(): array
    {
        return ['type' => Scheduler::TYPES];
    }

    /**
     * All tasks with schedule and last run.
     *
     * @param bool $withSchema  plus the description of the fields (for scheduler.create)
     */
    #[ApiMethod('scheduler.list', '#scheduler.api_list')]
    public function list(bool $withSchema = false): array
    {
        return $this->apiList($withSchema);
    }

    /**
     * A task with all settings.
     *
     * @param int  $id          scheduler_id
     * @param bool $withSchema  plus the description of the fields
     */
    #[ApiMethod('scheduler.info', '#scheduler.api_info')]
    public function info(int $id, bool $withSchema = false): array
    {
        return $this->apiInfo($id, $withSchema);
    }

    /**
     * Create a task.
     *
     * @param string $type      one of Scheduler::TYPES
     * @param array  $data      Field => value: label, schedule (cron_*), details depending on type
     * @param bool   $simulate  only check, create nothing
     */
    #[ApiMethod('scheduler.create', '#scheduler.api_create', write: true, level: ApiMethod::LEVEL_ADMIN)]
    public function create(string $type, array $data, bool $simulate = false): array
    {
        if (!in_array($type, Scheduler::TYPES, true)) {
            throw new ApiException(ApiException::INVALID_PARAM, 'type must be one of ' . implode(', ', Scheduler::TYPES));
        }
        return $this->apiCreate($data, $simulate, ['defaults' => ['type' => $type]]);
    }

    /**
     * Change a task.
     *
     * @param int   $id        scheduler_id
     * @param array $data      Field => value, only the ones to change
     * @param bool  $simulate  only check, change nothing
     */
    #[ApiMethod('scheduler.update', '#scheduler.api_update', write: true, level: ApiMethod::LEVEL_ADMIN)]
    public function update(int $id, array $data, bool $simulate = false): array
    {
        return $this->apiUpdate($id, $data, $simulate);
    }

    /**
     * Run a task now as a background process (like "Run now").
     *
     * @param int $id  scheduler_id
     * @return array  token (system.process), started
     */
    #[ApiMethod('scheduler.run', '#scheduler.api_run', write: true, level: ApiMethod::LEVEL_CHANGE)]
    public function run(int $id): array
    {
        $this->writer->requireScript(self::MODULE, 'scheduler_run.php');
        $this->writer->load($this->dataset, $id);
        $error = '';
        $token = $this->runner->startNow($id, $error);
        if ($token === null) throw new ApiException(ApiException::INTERNAL, 'the task could not be started: ' . $error);
        return ['id' => $id, 'started' => true, 'token' => $token];
    }
}
