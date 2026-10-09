<?php
/**
 * Error of an API call.
 *
 * An API method throws it; Api::call() turns it into the error response
 * (['ok' => false, 'error' => code, 'message' => …, 'errors' => […]]). Whoever calls an
 * API class directly from PHP catches it themselves.
 *
 * The code is machine-readable and stable (constants below), the message is text
 * in the language of the admin. 'errors' lists the individual errors, e.g. per field
 * (ApiContext::errorsFromCms()).
 *
 * @see Camindo\Core\Api\Api
 */
namespace Camindo\Core\Api;

class ApiException extends \RuntimeException
{
    /** no (valid) session, or the group may not use the interface */
    public const NOT_LOGGED_IN   = 'not_logged_in';
    /** sign-in still needs the second factor (ApiSession::loginCode()) */
    public const SECOND_FACTOR   = 'second_factor';
    /** the group's rights are not sufficient */
    public const ACCESS_DENIED   = 'access_denied';
    public const NOT_FOUND       = 'not_found';
    /** another admin is editing the record */
    public const LOCKED          = 'locked';
    /** the admin still has the record open in an input mask themselves */
    public const OPEN_IN_BACKEND = 'open_in_backend';
    /** input validation failed - details in errors */
    public const VALIDATION      = 'validation';
    /** unknown method, unknown/missing/wrongly typed parameter */
    public const INVALID_PARAM   = 'invalid_param';
    public const INTERNAL        = 'internal';

    /**
     * @param string $errorCode  one of the constants
     * @param string $message    message (text); empty = the code
     * @param array  $errors     individual errors: list of ['field', 'language', 'label', 'message']
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
