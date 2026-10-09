<?php
/**
 * API-Methoden rund um die API selbst: wer bin ich, was gibt es, wie ruft man es auf.
 *
 * @see Camindo\Core\Api\Api
 */
namespace Camindo\Core\Api;

use Camindo\Core\Access;
use Camindo\Core\BackgroundProcess;
use Camindo\Core\Cms;
use Camindo\Core\Session;

class SystemApi
{
    private const STATUS = [
        BackgroundProcess::STATUS_FINISHED => 'finished',
        BackgroundProcess::STATUS_RUNNING  => 'running',
        BackgroundProcess::STATUS_TIMEOUT  => 'timeout',
        BackgroundProcess::STATUS_FAILED   => 'failed',
    ];

    public function __construct(
        private Api $api,
        private ApiSession $apiSession,
        private ApiContext $context,
        private BackgroundProcess $bgproc,
        private Session $session,
        private Access $access,
        private Cms $cms,
    )
    {
    }

    /**
     * Angemeldeter Admin, Oberfläche, Kanal, Modulrechte, Instanzen und Sprachen.
     */
    #[ApiMethod('system.whoami', '#api_system_whoami')]
    public function whoami(): array
    {
        return $this->apiSession->whoami();
    }

    /**
     * Alle API-Methoden mit Kurzbeschreibung.
     */
    #[ApiMethod('system.methods', '#api_system_methods')]
    public function methods(): array
    {
        $list = [];
        foreach ($this->api->methods() as $name => $entry) {
            $list[] = ['name' => $name, 'description' => $this->api->describe($name)['description'], 'write' => $entry['write']];
        }
        return $list;
    }

    /**
     * Eine Methode mit JSON-Schema ihrer Parameter.
     *
     * @param string $method  Name der Methode, z. B. 'system.whoami'
     */
    #[ApiMethod('system.describe', '#api_system_describe')]
    public function describe(string $method): array
    {
        return $this->api->describe($method);
    }

    /**
     * Die eigenen Hintergrundprozesse (Stapelverarbeitung, Export, Import …),
     * neueste zuerst.
     */
    #[ApiMethod('system.processes', '#api_system_processes')]
    public function processes(): array
    {
        return array_map(fn($row) => $this->processRow($row), $this->bgproc->list((int)$this->session->get('admin_id')));
    }

    /**
     * Stand eines Hintergrundprozesses.
     *
     * @param string $token  aus der Antwort, die den Prozess gestartet hat (background.token)
     * @return array  token, type, status (running | finished | timeout | failed), progress (0-100), message, created_at, finished_at
     */
    #[ApiMethod('system.process', '#api_system_process')]
    public function process(string $token): array
    {
        return $this->processRow($this->ownProcess($token));
    }

    /**
     * Einen laufenden Hintergrundprozess abbrechen.
     *
     * @param string $token  background.token
     */
    #[ApiMethod('system.process_abort', '#api_system_process_abort', write: true, level: ApiMethod::LEVEL_CHANGE)]
    public function processAbort(string $token): array
    {
        $this->ownProcess($token);
        return ['aborted' => $this->bgproc->abort($token)];
    }

    /** Prozess des angemeldeten Admins (Superuser: jeder) - sonst not_found */
    private function ownProcess(string $token): array
    {
        $row = $this->bgproc->getByToken(preg_replace('/[^a-z0-9_\-]/i', '', $token) ?? '');
        if ($row === null || ((int)$row['admin_id'] !== (int)$this->session->get('admin_id') && !$this->access->isSuperuser())) {
            throw new ApiException(ApiException::NOT_FOUND, $this->cms->snippet('error_record_not_found'));
        }
        return $row;
    }

    private function processRow(array $row): array
    {
        return [
            'token'       => (string)$row['token'],
            'type'        => (string)$row['type'],
            'status'      => self::STATUS[(int)$row['status']] ?? (string)$row['status'],
            'progress'    => (int)$row['progress'],
            'message'     => $this->context->plain((string)$row['message']),
            'created_at'  => $this->context->isoTime((string)$row['created_at']),
            'finished_at' => $this->context->isoTime($row['finished_at'] ?? null),
        ];
    }
}
