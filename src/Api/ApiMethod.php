<?php
/**
 * Kennzeichnet eine öffentliche Methode als API-Methode.
 *
 *     #[ApiMethod('record.search', '#record.api_search')]
 *     public function search(int $areaId = 0, array $filter = [], int $limit = 20): array
 *
 * Nur so gekennzeichnete Methoden sind über Api::call() erreichbar. Der Name ist
 * "{bereich}.{aktion}" in Kleinbuchstaben. Die Parameter der Methode sind die
 * Parameter des Aufrufs (benannt, Typen aus der Signatur) - Api::describe() macht
 * daraus ein JSON-Schema, z. B. für die Werkzeug-Definitionen eines LLM.
 *
 * Die Beschreibung ist ein Snippet (übersetzbar); die Parameter beschreiben dann
 * die Snippets {schlüssel}__{parameter} bzw. api__{parameter} der Komponente, sonst
 * die @param-Zeilen des Docblocks (siehe Api).
 *
 * @see Camindo\Core\Api\Api
 */
namespace Camindo\Core\Api;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class ApiMethod
{
    // Schweregrad einer Methode - wie leicht sich ihre Wirkung rückgängig machen
    // lässt. Der Assistent (Modul Ai) fragt ab LEVEL_CHANGE vor dem Ausführen nach.
    /** Lesen, Anlegen, Kopieren, Exportieren - ändert nichts Bestehendes */
    public const LEVEL_READ   = 0;
    /** Ändern (auch Nachricht senden, aus dem Papierkorb holen) */
    public const LEVEL_CHANGE = 1;
    /** Löschen - über den Papierkorb wiederherstellbar */
    public const LEVEL_DELETE = 2;
    /** Verschieben - nur über einen Umweg rückgängig zu machen */
    public const LEVEL_MOVE   = 3;
    /** Massenoperation (Stapelverarbeitung) */
    public const LEVEL_MASS   = 4;
    /** Administrative Änderungen (Bereiche, Felder, Status, Rechte …) */
    public const LEVEL_ADMIN  = 5;

    /**
     * @param string $name         Aufrufname, z. B. 'record.search'
     * @param string $description  Beschreibung für describe() (Text oder #snippet)
     * @param bool   $write        ändert Daten (für die Beschreibung und spätere
     *                             Einschränkungen, z. B. Nur-Lese-Sessions)
     * @param int    $level        Schweregrad (LEVEL_*)
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
