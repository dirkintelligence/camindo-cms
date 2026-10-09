<?php
/**
 * Zustand der API im laufenden Prozess: unter welcher Session, über welche
 * Oberfläche und über welchen Kanal gerade gearbeitet wird - und die Übersetzung
 * zwischen den Konventionen des Bestands (Cms::errors, language_id) und denen der
 * API (Fehlerliste, Sprachcodes).
 *
 * OBERFLÄCHE UND KANAL
 * --------------------
 *     Oberfläche  die der Session (`admin_session`.`interface`): backend, cli, php.
 *                 Die Gruppe muss sie benutzen dürfen (Access::interface()).
 *     Kanal       wer über die Session arbeitet - erscheint im Audit-Log als
 *                 Herkunft (AuditLog::setContext()), der Admin bleibt der Handelnde:
 *                     llm        ein LLM im Chat des Backends (attach)
 *                     tool       ein Custom Tool aus einem Backend-Request (attach)
 *                     scheduler  eine Aufgabe des Zeitplaners (runAs)
 *                     cli, php   die Oberfläche selbst
 *
 * "Angehängt" (attach) heißt: die API arbeitet in der Backend-Session eines
 * angemeldeten Admins mit. Nur dann gibt es Vorschau-Links (sie hängen an einer
 * Backend-Session, FrontendHandover), und logout() beendet die Session nicht.
 *
 * @see Camindo\Core\Api\ApiSession   stellt den Zustand her
 * @see Camindo\Core\Api\Api          prüft ihn vor jedem Aufruf (require())
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
    // Zustand

    /**
     * Arbeit über die API beginnen (ApiSession).
     *
     * @param string $channel   CHANNELS
     * @param bool   $attached  in einer Backend-Session mitarbeiten
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
     * Arbeit über die API beenden (ApiSession::logout()).
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

    /** Oberfläche der Session ('' ohne Session) */
    public function interface(): string
    {
        return (string)($this->session->get('interface') ?? '');
    }

    /**
     * Vor jedem Aufruf: begonnen, Session geladen, Rechte geladen, Oberfläche erlaubt.
     * Ohne das wäre Access ungeladen - und ungeladen verweigert Access nichts.
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
    // Hinweise

    /** Hinweis zur Antwort des laufenden Aufrufs (Api::call(): 'warnings') */
    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    /** Hinweise abholen und zurücksetzen */
    public function takeWarnings(): array
    {
        $warnings = $this->warnings;
        $this->warnings = [];
        return $warnings;
    }

    // ====================
    // Fehler

    /**
     * Cms::errors als Fehlerliste der API. Schlüssel "feld" bzw. "feld.{language_id}"
     * (mehrsprachige Felder) werden zu field + language (Code); Meldungen ohne HTML.
     *
     * @return array  Liste aus ['field' => ?string, 'language' => ?string,
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
     * Ausnahme aus den gesammelten Fehlern (Cms::errors) - und die Sammlung leeren.
     *
     * @param string $errorCode  ApiException-Code
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

    /** Text ohne HTML und ohne überflüssigen Leerraum */
    public function plain(string $text): string
    {
        $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string)preg_replace('/[ \t]+/', ' ', $text));
    }

    // ====================
    // Zeit

    /**
     * UTC-Zeitpunkt direkt aus der Datenbank als ISO 8601 mit Offset in der Zeitzone
     * des Admins ('2026-09-29T16:03:00+02:00') - das Ausgabeformat der API für Werte,
     * die in UTC gespeichert sind (Werte aus Record::load() siehe isoLocal()).
     * null bzw. '' bleiben null.
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
     * Zeitpunkt, der bereits in der Zeitzone des Admins vorliegt (Record::load() und
     * Cms::fetch() rechnen `timestampl` aus UTC um), als ISO 8601 mit Offset.
     */
    public function isoLocal(?string $local): ?string
    {
        if ($local === null || trim($local) === '') return null;
        $timezone = $this->cms->userTZ !== '' ? $this->cms->userTZ : date_default_timezone_get();
        $date = new \DateTime(substr($local, 0, 19), new \DateTimeZone($timezone));
        return $date->format('Y-m-d\\TH:i:sP');
    }

    // ====================
    // Sprachen

    /**
     * language_id zu einem Sprachcode ('de_DE') oder einer Id - null, wenn es die
     * Sprache nicht gibt. Mit $instanceId nur unter den Sprachen dieser Instanz.
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

    /** Sprachcode zu einer language_id ('' wenn unbekannt) */
    public function languageCode(int $languageId): string
    {
        return (string)($this->languages()[$languageId]['code'] ?? '');
    }

    /**
     * Sprachen einer Instanz bzw. aller Instanzen: language_id => Sprachzeile
     */
    private function languages(int $instanceId = 0): array
    {
        if ($instanceId > 0) return $this->instanceCache->languages($instanceId);
        return $this->instanceCache->languagesOf(array_map('intval', (array)($this->instanceCache->all()['instance_ids'] ?? [])));
    }
}
