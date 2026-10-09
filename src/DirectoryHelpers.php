<?php
/**
 * Static helper functions for file and directory operations
 */

namespace Camindo\Core;

class DirectoryHelpers {

    /**
     * Normalizes a path structure and checks for invalid patterns.
     *
     * Deliberately does NOT change any characters of the name, so that the returned
     * path matches byte for byte what is on the file system.
     *
     * @param string $path  Path + file name
     * @throws \Exception on traversal, invalid characters or a missing file name
     */
    static public function checkPath(string $path): string
    {
        // Structural normalization
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');
        $path = preg_replace('!//+!', '/', $path);
        $path = preg_replace('!(^|/)(\./)+!', '$1', $path);
        $path = preg_replace('!/\.$!', '', $path);

        // Traversal: start and end included
        if (preg_match('!(^|/)\.\.(/|$)!', $path)) {
            throw new \Exception('Path of uploaded file contains forbidden pattern.');
        }

        // Allowlist. \p{M} is needed because nothing is normalized here and
        // names from macOS can come decomposed (a + U+0308 instead of ä).
        // Delimiter ! instead of /, so the slash stays free in the class.
        if (!preg_match('!^[\p{L}\p{M}\p{N}._ ()\[\]{}@+,\'/-]+$!u', $path)) {
            throw new \Exception('Path of uploaded file contains forbidden pattern.');
        }

        if ($path === '' || preg_match('!/$!', $path)) {
            throw new \Exception('Path of uploaded file contains no file name.');
        }

        foreach (explode('/', $path) as $segment) {
            // Windows silently strips a trailing dot/space
            if (rtrim($segment, ' .') !== $segment || strlen($segment) > 255) {
                throw new \Exception('Path of uploaded file contains forbidden pattern.');
            }
        }

        return $path;
    }

    /**
     * Converts any (client) file name into a storable name.
     * (max 255 characters)
     *
     * @param string $filename  File name from an upload, converted to a storable format
     * @param string $fallback  default: 'file'
     */
    static public function sanitizeFilename(string $filename, string $fallback = 'file'): string
    {
        // Use only the base name. Some clients send full paths (IE),
        // and basename() on Linux is blind to backslashes.
        $filename = str_replace('\\', '/', $filename);
        $filename = substr(strrchr('/' . $filename, '/'), 1);

        // Compose Unicode so that \p{L} matches and the name is in exactly one
        // form in both the DB and the file system
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($filename, \Normalizer::FORM_C);
            if ($normalized !== false) {
                $filename = $normalized;
            }
        }

        // Discard invalid UTF-8, otherwise the /u patterns below fail
        if (!mb_check_encoding($filename, 'UTF-8')) {
            $filename = preg_replace('/[^\x20-\x7E]/', '_', $filename);
        }

        // Replace everything outside the allowlist. A slash is not allowed here,
        // the return value is a single segment.
        $filename = preg_replace('![^\p{L}\p{M}\p{N}._ ()\[\]{}@+,\'-]!u', '_', $filename);

        // Collapse repeated replacements and dots
        $filename = preg_replace('!_{2,}!', '_', $filename);
        $filename = preg_replace('!\.{2,}!', '.', $filename);

        // Windows: trailing dot/space, leading spaces
        $filename = trim($filename, " \t\n\r\0\x0B.");

        // Defuse reserved device names on Windows
        if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(\.|$)/i', $filename)) {
            $filename = '_' . $filename;
        }

        if ($filename === '') {
            $filename = $fallback;
        }

        return self::truncateFilename($filename);
    }

    /**
     * Shortens a file name while keeping the extension
     * @param string $filename  File name
     * @param int $maxBytes     Target length incl. extension
     */
    static public function truncateFilename(string $filename, int $maxBytes = 255): string
    {
        if (strlen($filename) <= $maxBytes) return $filename;

        $extension = '';
        $dot = strrpos($filename, '.');
        // Keep only plausible extensions, no cut-off parts of the name
        if ($dot !== false && $dot > 0 && strlen($filename) - $dot <= 16) {
            $extension = substr($filename, $dot);
            $filename  = substr($filename, 0, $dot);
        }

        // mb_strcut cuts at character boundaries, substr() would break UTF-8
        $filename = mb_strcut($filename, 0, $maxBytes - strlen($extension), 'UTF-8');
        $filename = rtrim($filename, ' .');

        return ($filename === '' ? 'file' : $filename) . $extension;
    }
}