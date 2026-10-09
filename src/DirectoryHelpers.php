<?php
/**
 * Statische Hilfsfunktionen für Datei und Verzeichnis-Operationen
 */

namespace Camindo\Core;

class DirectoryHelpers {

    /**
     * Normalisiert eine Pfadstruktur und prüft auf ungültige Pattern.
     *
     * Verändert ausdrücklich KEINE Zeichen des Namens, damit der zurückgegebene
     * Pfad byteweise dem entspricht, was im Dateisystem liegt.
     *
     * @param string $path  Pfad + Dateiname
     * @throws \Exception bei Traversal, ungültigen Zeichen oder fehlendem Dateinamen
     */
    static public function checkPath(string $path): string
    {
        // Strukturelle Normalisierung
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');
        $path = preg_replace('!//+!', '/', $path);
        $path = preg_replace('!(^|/)(\./)+!', '$1', $path);
        $path = preg_replace('!/\.$!', '', $path);

        // Traversal: Anfang und Ende mit erfasst
        if (preg_match('!(^|/)\.\.(/|$)!', $path)) {
            throw new \Exception('Path of uploaded file contains forbidden pattern.');
        }

        // Whitelist. \p{M} ist nötig, weil hier NICHT normalisiert wird und
        // Namen von macOS zerlegt vorliegen können (a + U+0308 statt ä).
        // Delimiter ! statt /, damit der Slash in der Klasse frei bleibt.
        if (!preg_match('!^[\p{L}\p{M}\p{N}._ ()\[\]{}@+,\'/-]+$!u', $path)) {
            throw new \Exception('Path of uploaded file contains forbidden pattern.');
        }

        if ($path === '' || preg_match('!/$!', $path)) {
            throw new \Exception('Path of uploaded file contains no file name.');
        }

        foreach (explode('/', $path) as $segment) {
            // Windows schneidet Punkt/Leerzeichen am Ende still ab
            if (rtrim($segment, ' .') !== $segment || strlen($segment) > 255) {
                throw new \Exception('Path of uploaded file contains forbidden pattern.');
            }
        }

        return $path;
    }

    /**
     * Wandelt einen beliebigen (Client-)Dateinamen in einen speicherbaren Namen um.
     * (max 255 Zeichen)
     *
     * @param string $filename  Dateiname aus Upload in speicherbares Format umwandeln
     * @param string $fallback  default: 'file'
     */
    static public function sanitizeFilename(string $filename, string $fallback = 'file'): string
    {
        // Nur den Basisnamen verwenden. Clients schicken teils vollständige Pfade
        // (IE) und basename() ist unter Linux blind für Backslashes.
        $filename = str_replace('\\', '/', $filename);
        $filename = substr(strrchr('/' . $filename, '/'), 1);

        // Unicode zusammensetzen, damit \p{L} greift und der Name in DB und
        // Dateisystem in genau einer Form vorliegt
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($filename, \Normalizer::FORM_C);
            if ($normalized !== false) {
                $filename = $normalized;
            }
        }

        // Ungültiges UTF-8 verwerfen, sonst schlagen die /u-Pattern unten fehl
        if (!mb_check_encoding($filename, 'UTF-8')) {
            $filename = preg_replace('/[^\x20-\x7E]/', '_', $filename);
        }

        // Alles außerhalb der Whitelist ersetzen. Slash ist hier nicht erlaubt,
        // der Rückgabewert ist ein einzelnes Segment.
        $filename = preg_replace('![^\p{L}\p{M}\p{N}._ ()\[\]{}@+,\'-]!u', '_', $filename);

        // Mehrfache Ersetzungen und Punkte zusammenfassen
        $filename = preg_replace('!_{2,}!', '_', $filename);
        $filename = preg_replace('!\.{2,}!', '.', $filename);

        // Windows: Punkt/Leerzeichen am Ende, führende Leerzeichen
        $filename = trim($filename, " \t\n\r\0\x0B.");

        // Reservierte Gerätenamen unter Windows entschärfen
        if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(\.|$)/i', $filename)) {
            $filename = '_' . $filename;
        }

        if ($filename === '') {
            $filename = $fallback;
        }

        return self::truncateFilename($filename);
    }

    /**
     * Kürzt Dateiname erhält dabei die Endung
     * @param string $filename  Dateiname
     * @param int $maxBytes     Ziellänge inkl. Extension
     */
    static public function truncateFilename(string $filename, int $maxBytes = 255): string
    {
        if (strlen($filename) <= $maxBytes) return $filename;

        $extension = '';
        $dot = strrpos($filename, '.');
        // Nur plausible Endungen erhalten, keine abgeschnittenen Namensteile
        if ($dot !== false && $dot > 0 && strlen($filename) - $dot <= 16) {
            $extension = substr($filename, $dot);
            $filename  = substr($filename, 0, $dot);
        }

        // mb_strcut schneidet an Zeichengrenzen, substr() würde UTF-8 zerreißen
        $filename = mb_strcut($filename, 0, $maxBytes - strlen($extension), 'UTF-8');
        $filename = rtrim($filename, ' .');

        return ($filename === '' ? 'file' : $filename) . $extension;
    }
}