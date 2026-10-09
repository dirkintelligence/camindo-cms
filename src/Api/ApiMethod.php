<?php
/**
 * Marks a public method as an API method.
 *
 *     #[ApiMethod('record.search', '#record.api_search')]
 *     public function search(int $areaId = 0, array $filter = [], int $limit = 20): array
 *
 * Only methods marked this way can be reached via Api::call(). The name is
 * "{area}.{action}" in lowercase. The parameters of the method are the
 * parameters of the call (named, types from the signature) - Api::describe() turns
 * them into a JSON schema, e.g. for the tool definitions of an LLM.
 *
 * The description is a snippet (translatable); the parameters then describe
 * the snippets {key}__{parameter} resp. api__{parameter} of the component, otherwise
 * the @param lines of the docblock (see Api).
 *
 * @see Camindo\Core\Api\Api
 */
namespace Camindo\Core\Api;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class ApiMethod
{
    // Severity of a method - how easily its effect can be undone.
    // The assistant (module Ai) asks before running from LEVEL_CHANGE on.
    /** Read, create, copy, export - changes nothing existing */
    public const LEVEL_READ   = 0;
    /** Change (also send a message, restore from the trash) */
    public const LEVEL_CHANGE = 1;
    /** Delete - restorable via the trash */
    public const LEVEL_DELETE = 2;
    /** Move - only reversible by a detour */
    public const LEVEL_MOVE   = 3;
    /** Mass operation (batch processing) */
    public const LEVEL_MASS   = 4;
    /** Administrative changes (areas, fields, status, rights …) */
    public const LEVEL_ADMIN  = 5;

    /**
     * @param string $name         call name, e.g. 'record.search'
     * @param string $description  description for describe() (text or #snippet)
     * @param bool   $write        changes data (for the description and later
     *                             restrictions, e.g. read-only sessions)
     * @param int    $level        severity (LEVEL_*)
     */
    public function __construct(
        public string $name,
        public string $description = '',
        public bool $write = false,
        public int $level = self::LEVEL_READ,
    )
    {
    }
}
