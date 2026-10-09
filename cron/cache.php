<?php
/**
 * cache.php - short form for: php Core/cron/camindo.php cache …
 *
 *     php Core/cron/cache.php snippets [--prefix=record | --remove=record]
 *     php Core/cron/cache.php list
 *
 * @see Camindo\Core\Cli\CacheCommand
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}
array_splice($argv, 1, 0, 'cache');
require __DIR__ . '/camindo.php';
