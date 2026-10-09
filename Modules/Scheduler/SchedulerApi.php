<?php
/**
 * API-Methoden des Zeitplaners: auflisten, lesen, anlegen, ändern, jetzt ausführen.
 *
 * Welche Einstellungen unter details gelten, hängt vom Typ ab (url, php, dbbackup,
 * logcleanup, cachecleanup, template, fulltext; die Wartungsaufgaben
 * Scheduler::MAINTENANCE_TYPES haben keine). Das Passwort einer URL-Aufgabe
 * (details.password) schreibt die API nicht - Zugangsdaten gehören nicht in einen
 * Chat; es bleibt, wie es ist, und wird in der Oberfläche gesetzt.
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
    /** Scheduler::editableFields() ohne type, details einzeln ohne Passwort */
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
     * Alle Aufgaben mit Zeitplan und letztem Lauf.
     *
     * @param bool $withSchema  dazu die Beschreibung der Felder (für scheduler.create)
     */
    #[ApiMethod('scheduler.list', '#scheduler.api_list')]
    public function list(bool $withSchema = false): array
    {
        return $this->apiList($withSchema);
    }

    /**
     * Eine Aufgabe mit allen Einstellungen.
     *
     * @param int  $id          scheduler_id
     * @param bool $withSchema  dazu die Beschreibung der Felder
     */
    #[ApiMethod('scheduler.info', '#scheduler.api_info')]
    public function info(int $id, bool $withSchema = false): array
    {
        return $this->apiInfo($id, $withSchema);
    }

    /**
     * Eine Aufgabe anlegen.
     *
     * @param string $type      einer aus Scheduler::TYPES
     * @param array  $data      Feld => Wert: label, Zeitplan (cron_*), details je nach Typ
     * @param bool   $simulate  nur prüfen, nichts anlegen
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
     * Eine Aufgabe ändern.
     *
     * @param int   $id        scheduler_id
     * @param array $data      Feld => Wert, nur die zu ändernden
     * @param bool  $simulate  nur prüfen, nichts ändern
     */
    #[ApiMethod('scheduler.update', '#scheduler.api_update', write: true, level: ApiMethod::LEVEL_ADMIN)]
    public function update(int $id, array $data, bool $simulate = false): array
    {
        return $this->apiUpdate($id, $data, $simulate);
    }

    /**
     * Eine Aufgabe jetzt als Hintergrundprozess ausführen (wie "Jetzt ausführen").
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
