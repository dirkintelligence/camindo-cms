<?php
/**
 * Fehler eines API-Aufrufs.
 *
 * Eine API-Methode wirft sie; Api::call() macht daraus die Fehlerantwort
 * (['ok' => false, 'error' => Code, 'message' => …, 'errors' => […]]). Wer eine
 * API-Klasse direkt aus PHP aufruft, fängt sie selbst.
 *
 * Der Code ist maschinenlesbar und stabil (Konstanten unten), die Meldung ist Text
 * in der Sprache des Admins. 'errors' führt die Einzelfehler, z. B. je Feld
 * (ApiContext::errorsFromCms()).
 *
 * @see Camindo\Core\Api\Api
 */
namespace Camindo\Core\Api;

class ApiException extends \RuntimeException
{
    /** keine (gültige) Session, oder die Gruppe darf die Oberfläche nicht benutzen */
    public const NOT_LOGGED_IN   = 'not_logged_in';
    /** Anmeldung braucht noch den zweiten Faktor (ApiSession::loginCode()) */
    public const SECOND_FACTOR   = 'second_factor';
    /** Rechte der Gruppe reichen nicht */
    public const ACCESS_DENIED   = 'access_denied';
    public const NOT_FOUND       = 'not_found';
    /** ein anderer Admin bearbeitet den Datensatz */
    public const LOCKED          = 'locked';
    /** der Admin hat den Datensatz selbst noch in einer Eingabemaske offen */
    public const OPEN_IN_BACKEND = 'open_in_backend';
    /** Eingabeprüfung gescheitert - Einzelheiten in errors */
    public const VALIDATION      = 'validation';
    /** unbekannte Methode, unbekannter/fehlender/falsch typisierter Parameter */
    public const INVALID_PARAM   = 'invalid_param';
    public const INTERNAL        = 'internal';

    /**
     * @param string $errorCode  eine der Konstanten
     * @param string $message    Meldung (Text); leer = der Code
     * @param array  $errors     Einzelfehler: Liste aus ['field', 'language', 'label', 'message']
     */
    public function __construct(
        private string $errorCode,
        string $message = '',
        private array $errors = [],
    )
    {
        parent::__construct($message !== '' ? $message : $errorCode);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
