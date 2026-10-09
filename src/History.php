<?php
/**
 * Recently changed records and templates per admin (table `history`).
 * Instance name in the code: $history.
 *
 * WHAT GETS IN
 * --------------------
 * Only what an admin saved in an input mask - RecordBackendEdit and
 * TemplateBackend call touch() after a successful save. Import, batch processing,
 * moving and the sync of the template files are deliberately left out: an import
 * of a thousand records would fill the list at once with entries that nobody
 * touched by hand.
 *
 * AT MOST LIMIT ENTRIES PER ADMIN AND KIND
 * --------------------
 * A row carries either `record_id` or `template_id`. Saving the same record again
 * does not write a second row, it only updates `changed_at` (UNIQUE admin_id +
 * record_id or + template_id); afterwards everything beyond the tenth entry of
 * this kind is deleted.
 *
 * THE DATABASE DOES THE CLEANUP
 * --------------------
 * All three columns are foreign keys with ON DELETE CASCADE: a record that is
 * permanently deleted, a deleted template or a deleted admin takes its rows with
 * it. Records in the trash remain: the display fetches them via Cms::select(),
 * which filters them out just like records the user group may no longer read.
 *
 * @see Camindo\Core\Modules\Record\RecordBackendFolderList::_lastChangedRecords()
 * @see Camindo\Core\Modules\Template\TemplateBackend::_lastChangedTemplates()
 */
namespace Camindo\Core;

class History {

    /** Entries per admin and kind */
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
     * Note a saved record or template for the session admin and trim the list
     * to LIMIT entries.
     *
     * Without a session admin (cron, frontend) nothing happens.
     *
     * @param string $type  'record' | 'template'
     * @param int    $id    record_id or template_id (live, not a working copy)
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
        // Remove everything beyond the LIMIT newest entries of this kind
        // (MySQL does not allow LIMIT directly in an IN subquery - hence the
        // derived table)
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
     * The noted ids of the session admin, newest first.
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
