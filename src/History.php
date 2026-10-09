<?php
/**
 * Zuletzt geänderte Datensätze und Templates je Admin (Tabelle `history`).
 * Instanzname im Code: $history.
 *
 * WAS HINEINKOMMT
 * --------------------
 * Nur was ein Admin in einer Eingabemaske gespeichert hat - RecordBackendEdit und
 * TemplateBackend rufen touch() nach einem erfolgreichen Speichern auf. Import,
 * Stapelverarbeitung, Verschieben und der Abgleich der Template-Dateien bleiben
 * bewusst draußen: ein Import über tausend Datensätze würde die Liste sofort mit
 * Einträgen füllen, die niemand von Hand angefasst hat.
 *
 * JE ADMIN UND ART HÖCHSTENS LIMIT EINTRÄGE
 * --------------------
 * Eine Zeile trägt entweder `record_id` oder `template_id`. Ein erneutes Speichern
 * desselben Datensatzes schreibt keine zweite Zeile, sondern setzt nur `changed_at`
 * neu (UNIQUE admin_id + record_id bzw. + template_id); danach werden alles über
 * dem zehnten Eintrag dieser Art gelöscht.
 *
 * AUFRÄUMEN ÜBERNIMMT DIE DATENBANK
 * --------------------
 * Alle drei Spalten sind Fremdschlüssel mit ON DELETE CASCADE: ein endgültig
 * gelöschter Datensatz, ein gelöschtes Template oder ein gelöschter Admin nimmt
 * seine Zeilen mit. Im Papierkorb liegende Datensätze bleiben stehen - die Anzeige
 * holt sie über Cms::select(), das sie ebenso ausfiltert wie Datensätze, die die
 * Benutzergruppe inzwischen nicht mehr lesen darf.
 *
 * @see Camindo\Core\Modules\Record\RecordBackendFolderList::_lastChangedRecords()
 * @see Camindo\Core\Modules\Template\TemplateBackend::_lastChangedTemplates()
 */
namespace Camindo\Core;

class History {

    /** Einträge je Admin und Art */
    public const LIMIT = 10;

    /** Art => Spalte in `history` */
    private const COLUMNS = [
        'record'   => 'record_id',
        'template' => 'template_id',
    ];

    public function __construct(
        private Database $db,
        private Session $session,
    )
    {}

    /**
     * Einen gespeicherten Datensatz bzw. ein Template für den Session-Admin
     * vermerken und die Liste auf LIMIT Einträge kürzen.
     *
     * Ohne Session-Admin (Cron, Frontend) passiert nichts.
     *
     * @param string $type  'record' | 'template'
     * @param int    $id    record_id bzw. template_id (produktiv, keine Arbeitskopie)
     */
    public function touch(string $type, int $id): void
    {
        $column = self::COLUMNS[$type] ?? null;
        $adminId = (int)$this->session->get('admin_id');
        if ($column === null || $id < 1 || $adminId < 1) return;

        $this->db->pushResult();
        $this->db->query(
            "INSERT INTO `history` (`admin_id`, `$column`, `changed_at`) VALUES (?, ?, UTC_TIMESTAMP(3))"
            . " ON DUPLICATE KEY UPDATE `changed_at` = VALUES(`changed_at`)",
            'ii',
            [$adminId, $id]
        );
        // Alles jenseits der LIMIT jüngsten Einträge dieser Art entfernen
        // (MySQL erlaubt LIMIT nicht direkt in einer IN-Unterabfrage - daher die
        // abgeleitete Tabelle)
        $this->db->query(
            "DELETE FROM `history` WHERE `admin_id` = ? AND `$column` IS NOT NULL"
            . " AND `history_id` NOT IN (SELECT `history_id` FROM ("
            . "SELECT `history_id` FROM `history` WHERE `admin_id` = ? AND `$column` IS NOT NULL"
            . " ORDER BY `changed_at` DESC, `history_id` DESC LIMIT " . self::LIMIT
            . ") AS `keep`)",
            'ii',
            [$adminId, $adminId]
        );
        $this->db->popResult();
    }

    /**
     * Die vermerkten Ids des Session-Admins, jüngste zuerst.
     *
     * @param string $type  'record' | 'template'
     * @return array        id => changed_at (UTC, 'Y-m-d H:i:s.v')
     */
    public function entries(string $type): array
    {
        $column = self::COLUMNS[$type] ?? null;
        $adminId = (int)$this->session->get('admin_id');
        if ($column === null || $adminId < 1) return [];

        $this->db->pushResult();
        $this->db->query(
            "SELECT `$column`, `changed_at` FROM `history` WHERE `admin_id` = ? AND `$column` IS NOT NULL"
            . " ORDER BY `changed_at` DESC, `history_id` DESC LIMIT " . self::LIMIT,
            'i',
            [$adminId]
        );
        $entries = [];
        foreach ($this->db->fetchPairs() as $id => $changedAt) {
            $entries[(int)$id] = (string)$changedAt;
        }
        $this->db->popResult();
        return $entries;
    }
}
