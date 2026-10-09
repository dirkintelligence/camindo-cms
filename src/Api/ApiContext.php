<?php
/**
 * State of the API in the running process: under which session, via which
 * interface and through which channel work is being done right now - and the translation
 * between the conventions of the stock (Cms::errors, language_id) and those of the
 * API (error list, language codes).
 *
 * INTERFACE AND CHANNEL
 * --------------------
 *     Interface   that of the session (`admin_session`.`interface`): backend, cli, php.
 *                 The group must be allowed to use it (Access::interface()).
 *     Channel     who works via the session - appears in the audit log as
 *                 origin (AuditLog::setContext()); the admin remains the acting party:
 *                     llm        an LLM in the chat of the backend (attach)
 *                     tool       a custom tool from a backend request (attach)
 *                     scheduler  a task of the scheduler (runAs)
 *                     cli, php   the interface itself
 *
 * "Attached" (attach) means: the API works along in the backend session of a
 * logged-in admin. Only then are there preview links (they hang on a
 * backend session, FrontendHandover), and logout() does not end the session.
 *
 * @see Camindo\Core\Api\ApiSession   establishes the state
 * @see Camindo\Core\Api\Api          checks it before each call (require())
 */
namespace Camindo\Core\Api;

use Camindo\Core\Access;
use Camindo\Core\AuditLog;
use Camindo\Core\Cms;
use Camindo\Core\InstanceCache;
use Camindo\Core\Session;

class ApiContext
{
    public const CHANNELS = ['llm', 'tool', 'scheduler', 'cli', 'php'];

    private string $channel = '';
    private bool $attached = false;
    private array $warnings = [];

    public function __construct(
        private Cms $cms,
        private Session $session,
        private Access $access,
        private AuditLog $auditLog,
        private InstanceCache $instanceCache,
    )
    {
    }

    // ====================
    // State

    /**
     * Start working via the API (ApiSession).
     *
     * @param string $channel   CHANNELS
     * @param bool   $attached  work along in a backend session
     */
    public function begin(string $channel, bool $attached = false): void
    {
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new ApiException(ApiException::INVALID_PARAM, "invalid channel \"{$channel}\"");
        }
        $this->channel = $channel;
        $this->attached = $attached;
        $this->auditLog->setContext($channel);
    }

    /**
     * End working via the API (ApiSession::logout()).
     */
    public function end(): void
    {
        $this->channel = '';
        $this->attached = false;
        $this->auditLog->setContext('');
    }

    public function active(): bool
    {
        return $this->channel !== '';
    }

    public function channel(): string
    {
        return $this->channel;
    }

    public function attached(): bool
    {
        return $this->attached;
    }

    /** Interface of the session ('' without session) */
    public function interface(): string
    {
        return (string)($this->session->get('interface') ?? '');
    }

    /**
     * Before each call: started, session loaded, rights loaded, interface allowed.
     * Without that Access would be unloaded - and unloaded Access denies nothing.
     *
     * @throws ApiException  NOT_LOGGED_IN
     */
    public function require(): void
    {
        $interface = $this->interface();
        if (!$this->active()
            || (int)$this->session->get('admin_id') < 1
            || !$this->access->isLoaded()
            || $interface === ''
            || !$this->access->interface($interface)
        ) {
            throw new ApiException(ApiException::NOT_LOGGED_IN, $this->cms->snippet('error_access_denied'));
        }
    }

    // ====================
    // Notes

    /** Note on the response of the running call (Api::call(): 'warnings') */
    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    /** Fetch notes and reset them */
    public function takeWarnings(): array
    {
        $warnings = $this->warnings;
        $this->warnings = [];
        return $warnings;
    }

    // ====================
    // Errors

    /**
     * Cms::errors as the error list of the API. Key "field" resp. "field.{language_id}"
     * (multilingual fields) become field + language (code); messages without HTML.
     *
     * @return array  list of ['field' => ?string, 'language' => ?string,
     *                'label' => ?string, 'message' => string]
     */
    public function errorsFromCms(): array
    {
        $list = [];
        foreach ($this->cms->errors as $key => $error) {
            $field = null;
            $language = null;
            if (!is_int($key)) {
                [$field, $languageId] = array_pad(explode('.', (string)$key, 2), 2, null);
                if ($languageId !== null && ctype_digit($languageId)) {
                    $language = $this->languageCode((int)$languageId) ?: $languageId;
                }
            }
            $list[] = [
                'field'    => $field,
                'language' => $language,
                'label'    => isset($error['label']) ? $this->plain($this->cms->textOrSnippet((string)$error['label'])) : null,
                'message'  => $this->plain((string)($error['text'] ?? '')),
            ];
        }
        return $list;
    }

    /**
     * Exception from the collected errors (Cms::errors) - and empty the collection.
     *
     * @param string $errorCode  ApiException code
     */
    public function exceptionFromCms(string $errorCode = ApiException::VALIDATION): ApiException
    {
        $errors = $this->errorsFromCms();
        $message = implode(' | ', array_map(
            fn($e) => ($e['label'] !== null ? $e['label'] . ': ' : '') . $e['message'],
            $errors
        ));
        $this->cms->errorsReset();
        return new ApiException($errorCode, $message, $errors);
    }

    /** Text without HTML and without superfluous whitespace */
    public function plain(string $text): string
    {
        $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string)preg_replace('/[ \t]+/', ' ', $text));
    }

    // ====================
    // Time

    /**
     * UTC time taken directly from the database as ISO 8601 with offset in the time zone
     * of the admin ('2026-09-29T16:03:00+02:00') - the output format of the API for values
     * stored in UTC (values from Record::load() see isoLocal()).
     * null resp. '' stay null.
     */
    public function isoTime(?string $utc): ?string
    {
        if ($utc === null || trim($utc) === '') return null;
        $timezone = $this->cms->userTZ !== '' ? $this->cms->userTZ : date_default_timezone_get();
        $date = new \DateTime($utc, new \DateTimeZone('UTC'));
        $date->setTimezone(new \DateTimeZone($timezone));
        return $date->format('Y-m-d\\TH:i:sP');
    }

    /**
     * Time already present in the time zone of the admin (Record::load() and
     * Cms::fetch() convert `timestampl` from UTC), as ISO 8601 with offset.
     */
    public function isoLocal(?string $local): ?string
    {
        if ($local === null || trim($local) === '') return null;
        $timezone = $this->cms->userTZ !== '' ? $this->cms->userTZ : date_default_timezone_get();
        $date = new \DateTime(substr($local, 0, 19), new \DateTimeZone($timezone));
        return $date->format('Y-m-d\\TH:i:sP');
    }

    // ====================
    // Languages

    /**
     * language_id for a language code ('de_DE') or an ID - null if there is no
     * such language. With $instanceId only among the languages of this instance.
     */
    public function languageId(string|int $codeOrId, int $instanceId = 0): ?int
    {
        $languages = $this->languages($instanceId);
        if (is_int($codeOrId) || ctype_digit((string)$codeOrId)) {
            return isset($languages[(int)$codeOrId]) ? (int)$codeOrId : null;
        }
        $code = strtolower(str_replace('-', '_', (string)$codeOrId));
        foreach ($languages as $languageId => $language) {
            if (strtolower((string)$language['code']) === $code) return (int)$languageId;
        }
        return null;
    }

    /** Language code for a language_id ('' if unknown) */
    public function languageCode(int $languageId): string
    {
        return (string)($this->languages()[$languageId]['code'] ?? '');
    }

    /**
     * Languages of an instance resp. of all instances: language_id => language row
     */
    private function languages(int $instanceId = 0): array
    {
        if ($instanceId > 0) return $this->instanceCache->languages($instanceId);
        return $this->instanceCache->languagesOf(array_map('intval', (array)($this->instanceCache->all()['instance_ids'] ?? [])));
    }
}
