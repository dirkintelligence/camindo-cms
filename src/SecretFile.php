<?php
/**
 * Owner of the secret files (.camindo.key, .camindo.key.retired, .camindo.php, .sqlpwd).
 *
 * The files are written with mode 0600 - only their owner can read them. That is
 * right where the CLI and the web server run as the same user (PHP-FPM pool per
 * customer, suEXEC, mpm-itk - the usual case on shared servers). It breaks
 * where the CLI runs as root (Docker entrypoint, `sudo`): the file then belongs to
 * root and the web server cannot read it - Crypt aborts with "exists but is not
 * readable".
 *
 * Only root may change the owner of a file, and from the CLI it is not known which
 * user the web server runs as. Therefore no guessing (www-data, apache …): with mod_php on
 * a server with several customers, a key readable by the shared Apache user
 * would be readable by every customer. The target user is
 *
 *     1  given explicitly: install --web-user=NAME (CAMINDO_WEB_USER)
 *     2  otherwise the owner of the project directory or of public/, if that is not root
 *
 * Without root nothing is changed (the files belong to the user who runs the CLI);
 * without a target user only ownershipWarnings() reports the problem.
 *
 * @see developer docs, chapter 09 (Installation and update)
 */
namespace Camindo\Core;

class SecretFile {

    /** Does this process run as root? (without posix: no) */
    public static function isRoot(): bool
    {
        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }

    /**
     * User by name.
     *
     * @return array|null  ['name' => …, 'uid' => …, 'gid' => …], null = unknown or no posix
     */
    public static function user(string $name): ?array
    {
        if (!function_exists('posix_getpwnam')) return null;
        $info = posix_getpwnam($name);
        return is_array($info) ? ['name' => (string)$info['name'], 'uid' => (int)$info['uid'], 'gid' => (int)$info['gid']] : null;
    }

    /** Owner of a file or directory - same format as user() */
    public static function ownerOf(string $path): ?array
    {
        if (!file_exists($path) || ($uid = @fileowner($path)) === false) return null;
        $gid = (int)@filegroup($path);
        $info = function_exists('posix_getpwuid') ? posix_getpwuid($uid) : false;
        return ['name' => is_array($info) ? (string)$info['name'] : (string)$uid, 'uid' => $uid, 'gid' => $gid];
    }

    /**
     * Probable web server user: owner of the project directory or of public/,
     * if not root (whoever uploaded the files is the customer user). null = unknown.
     */
    public static function guessWebUser(string $projectDir): ?array
    {
        foreach ([$projectDir, $projectDir . '/public'] as $dir) {
            $owner = self::ownerOf($dir);
            if ($owner !== null && $owner['uid'] !== 0) return $owner;
        }
        return null;
    }

    /**
     * Hand a file to a user (owner and primary group). Only as root; never throws.
     *
     * @return bool  true = owner set (or already right)
     */
    public static function chown(string $file, array $user): bool
    {
        if (!is_file($file)) return false;
        if (@fileowner($file) === $user['uid'] && @filegroup($file) === $user['gid']) return true;
        if (!self::isRoot()) return false;
        return @chown($file, $user['uid']) && @chgrp($file, $user['gid']);
    }

    /**
     * Give $file the owner of $from - for files replaced by rename (key rotation),
     * which would otherwise belong to whoever runs the CLI. Only as root; never throws.
     */
    public static function copyOwner(string $file, string $from): void
    {
        $owner = self::ownerOf($from);
        if ($owner !== null && self::isRoot()) self::chown($file, $owner);
    }

    /** Can $user read the file according to owner, group and mode? */
    public static function readableBy(string $file, array $user): bool
    {
        if ($user['uid'] === 0) return true;
        $perms = (int)@fileperms($file);
        if (@fileowner($file) === $user['uid']) return (bool)($perms & 0400);
        if (self::inGroup($user, (int)@filegroup($file))) return (bool)($perms & 0040);
        return (bool)($perms & 0004);
    }

    private static function inGroup(array $user, int $gid): bool
    {
        if ($user['gid'] === $gid) return true;
        $group = function_exists('posix_getgrgid') ? posix_getgrgid($gid) : false;
        return is_array($group) && in_array($user['name'], (array)($group['members'] ?? []), true);
    }

    /**
     * Notes on secret files the web server probably cannot read.
     *
     *     not readable by this process         always an error (Crypt aborts)
     *     as root, target user known           file not readable by it
     *     as root, target user unknown         file belongs to root and is readable only by root
     *
     * @param string[]   $files    existing ones are checked
     * @param array|null $webUser  user() / guessWebUser()
     * @return string[]
     */
    public static function ownershipWarnings(array $files, ?array $webUser): array
    {
        $warnings = [];
        foreach ($files as $file) {
            if (!is_file($file)) continue;
            $name = basename($file);
            if (!is_readable($file)) {
                $owner = self::ownerOf($file);
                $warnings[] = "$name is not readable by " . Crypt::processUser() . ' (owner ' . ($owner['name'] ?? '?')
                    . ') - the CLI and the web server must run as the owner, or chown the file';
                continue;
            }
            if (!self::isRoot()) continue;
            if ($webUser !== null) {
                if (!self::readableBy($file, $webUser)) {
                    $warnings[] = "$name is not readable by {$webUser['name']} -> chown {$webUser['name']}: $name"
                        . ' (or install --step=key --web-user=' . $webUser['name'] . ')';
                }
                continue;
            }
            if (@fileowner($file) === 0 && !((int)@fileperms($file) & 0044)) {
                $warnings[] = "$name belongs to root - unless the web server runs as root it cannot read it"
                    . ' -> chown WEBUSER: ' . $name . ' (or install --step=key --web-user=WEBUSER)';
            }
        }
        return $warnings;
    }
}
