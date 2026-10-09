<?php
/**
 * Dataset-Kindklasse für die Tabelle `message` - CMS-interne Nachrichten zwischen
 * Backend-Benutzern (Admins).
 *
 * Eine Nachricht hat genau einen Empfänger (to_admin_id) und einen Absender: das ist
 * created_by, das Dataset::save() beim Anlegen aus der Session füllt - ein eigenes
 * Absenderfeld gibt es deshalb nicht. Das Datum der Nachricht ist created_at.
 *
 * INHALT
 * --------------------
 *     subject   Betreff, Klartext
 *     body      Nachrichtentext, Klartext (Plaintext - wird bei der Anzeige mit
 *               htmlspecialchars/nl2br aufbereitet, nie als HTML ausgegeben)
 *     html      vom CMS erzeugter HTML-Code, der UNTER dem Text erscheint (optional;
 *               ein Redakteur kann ihn nicht eingeben, nur weiterleiten)
 *     record_id Bezug auf einen Datensatz: die Nachricht wurde aus dessen Info-Seite
 *               heraus geschrieben (RecordBackendInfo). Gespeichert wird nur die Id;
 *               der Teaser (Backend::teaser('record')) entsteht bei jeder Anzeige neu
 *               und zeigt damit immer den aktuellen Titel. FK ON DELETE SET NULL.
 *
 * body und html sind vom Typ 'encrypted' (Crypt::encrypt(), binär in mediumblob-
 * Spalten): Dataset ver- und entschlüsselt beim Speichern und Laden. Sie lassen sich
 * dadurch nicht durchsuchen - die Liste zeigt und sortiert nur Absender, Datum
 * und Betreff.
 *
 * GELESEN
 * --------------------
 * is_read wird nicht über save() gepflegt, sondern über markRead(): das Öffnen einer
 * Nachricht ist keine Änderung ihres Inhalts und soll changed_at/changed_by nicht
 * anfassen. Ein Wechsel des Empfängers (hookOnSave) setzt is_read zurück - für den
 * neuen Empfänger ist die Nachricht neu. unreadCount() liefert die Zahl für das
 * Abzeichen im Backend-Kopf.
 *
 * WICHTIG
 * --------------------
 * is_important setzt der Empfänger für sich (markImportant()) - ein Warn-Symbol neben
 * dem Briefumschlag in Liste und Detailansicht.
 *
 * ZWEI SICHTEN, EINE ZEILE
 * --------------------
 * Posteingang (to_admin_id) und Postausgang (created_by) zeigen dieselbe Zeile.
 * Löscht eine Seite, soll die andere ihre Nachricht behalten (der Absender will
 * recherchieren können, was er geschickt hat): deleteFor() setzt deshalb nur
 * recipient_deleted bzw. sender_deleted; erst wenn beide gesetzt sind, wird die
 * Zeile endgültig gelöscht - es gibt keinen Papierkorb (kein `deleted`-Feld).
 * Eine Nachricht an sich selbst hat beide Rollen - welche gemeint ist, sagt die
 * Liste (box).
 *
 * Nachrichten, die das CMS selbst verschickt (sendSystem(): Ergebnis einer
 * Stapelverarbeitung), haben nur die eine Sicht - den Posteingang des Adressaten:
 * sender_deleted ist von Anfang an gesetzt, im Postausgang erscheinen sie nie.
 *
 * AUFBEWAHRUNG (DSGVO)
 * --------------------
 * purgeExpired() löscht Nachrichten privacy.message_days Tage (Vorgabe 60) nach dem
 * Versand endgültig - aus der Zeitplaner-Aufgabe retention. deleteAllOf() räumt die Nachrichten
 * eines Benutzers auf einmal ab (Superuser, Einstellungen eines anderen Benutzers):
 * empfangene ganz, gesendete aus seinem Postausgang - die Empfänger behalten ihre.
 *
 * ZUGRIFF
 * --------------------
 * Sehen, ändern, löschen darf, wer Empfänger oder Absender ist und die Nachricht in
 * seiner Sicht nicht gelöscht hat (canAccess()).
 *
 * @see Camindo\Core\Modules\Messaging\MessagingBackend  Backend-Oberfläche
 * @see Camindo\Core\Dataset
 */
namespace Camindo\Core\Modules\Messaging;

use Camindo\Core\Dataset;

class Message extends Dataset {

    // Audit-Log: private Nachrichten zwischen Admins werden nicht protokolliert
    protected bool $audit = false;

    protected function hookDefinition(): void
    {
        $this->tableName  = 'message';
        $this->moduleName = 'Messaging';
        $this->defaultSortOrder = 'created_at DESC';
        $this->defaultFieldName = 'subject';
        $this->schema = [
            'message_id' => [
                'type'  => 'integer',
                'label' => 'message_id',
                'min' => 1
            ],
            'to_admin_id' => [
                'type'  => 'id',
                'display_type' => 'lookup',
                'label' => '#messaging.field_message_to_admin_id',
                'table' => 'admin'
            ],
            'subject' => [
                'type'  => 'chars',
                'label' => '#messaging.field_message_subject',
                'max'   => 255,
                'flags' => ['trim', 'not_empty']
            ],
            'body' => [
                'type'  => 'encrypted',
                'display_type' => 'textarea',
                'label' => '#messaging.field_message_body',
                'flags' => ['trim', 'not_empty']
            ],
            'html' => [
                'type'  => 'encrypted',
                'display_type' => 'textarea',
                'label' => '#messaging.field_message_html',
            ],
            'record_id' => [
                'type'  => 'id',
                'label' => '#messaging.field_message_record_id',
                'table' => 'record'
            ],
            'is_read' => [
                'type'  => 'bit',
                'label' => '#messaging.field_message_is_read',
            ],
            'is_important' => [
                'type'  => 'bit',
                'label' => '#messaging.field_message_is_important',
            ],
            'recipient_deleted' => [
                'type'  => 'bit',
                'label' => '#messaging.field_message_recipient_deleted',
            ],
            'sender_deleted' => [
                'type'  => 'bit',
                'label' => '#messaging.field_message_sender_deleted',
            ],
            'created_by' => [
                'type'  => 'id',
                'label' => '#messaging.field_message_created_by',
                'table' => 'admin'
            ],
            'created_at' => [
                'type'  => 'timestampl',
                'label' => '#messaging.field_message_created_at',
                'default' => 'now'
            ],
            'changed_by' => [
                'type'  => 'id',
                'label' => '#messaging.field_message_changed_by',
                'table' => 'admin'
            ],
            'changed_at' => [
                'type'  => 'timestampl',
                'label' => '#messaging.field_message_changed_at',
                'default' => 'now'
            ],
        ];
    }

    /**
     * Vor dem Speichern: der Empfänger muss ein vorhandener, nicht gelöschter Admin
     * sein. Ein Feld vom Typ 'id' läuft nicht in die not_empty-Prüfung (0 ist dort
     * ein gültiger Wert und wird zu NULL) - deshalb hier.
     *
     * Wechselt der Empfänger, ist die Nachricht für ihn ungelesen.
     */
    protected function hookOnSave(array &$fields, array &$data, array &$previousData): void
    {
        if (!empty($fields) && !in_array('to_admin_id', $fields, true)) return;

        $toAdminId = (int)($data['to_admin_id'] ?? 0);
        $label = $this->schema['to_admin_id']['label'];
        if ($toAdminId < 1) {
            $this->createFieldError('error_value_mandatory', 'to_admin_id', $label);
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }
        $this->db->pushResult();
        $this->db->query("SELECT 1 FROM `admin` WHERE `admin_id` = ? AND `deleted` = 0", 'i', [$toAdminId]);
        $found = $this->db->num_rows > 0;
        $this->db->popResult();
        if (!$found) {
            $this->createFieldError('messaging.error_recipient_unknown', 'to_admin_id', $label);
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }

        if (!empty($previousData) && (int)($previousData['to_admin_id'] ?? 0) !== $toAdminId) {
            if (!in_array('is_read', $fields, true)) $fields[] = 'is_read';
            $data['is_read'] = 0;
        }
    }

    /**
     * Darf dieser Admin die Nachricht sehen und bearbeiten? Empfänger oder Absender.
     *
     * @param array $data     geladene Nachricht
     * @param int   $adminId  admin_id aus der Session
     */
    public function canAccess(array $data, int $adminId): bool
    {
        if ($adminId < 1) return false;
        $isRecipient = (int)($data['to_admin_id'] ?? 0) === $adminId && empty($data['recipient_deleted']);
        $isSender    = (int)($data['created_by'] ?? 0) === $adminId && empty($data['sender_deleted']);
        return $isRecipient || $isSender;
    }

    /**
     * Rolle eines Admins gegenüber der Nachricht - 'inbox' (Empfänger), 'outbox'
     * (Absender) oder ''. Bei einer Nachricht an sich selbst entscheidet $box.
     *
     * @param array  $data     geladene Nachricht
     * @param int    $adminId  admin_id aus der Session
     * @param string $box      gewünschte Sicht ('inbox' | 'outbox' | '')
     */
    public function boxOf(array $data, int $adminId, string $box = ''): string
    {
        $isRecipient = (int)($data['to_admin_id'] ?? 0) === $adminId && empty($data['recipient_deleted']);
        $isSender    = (int)($data['created_by'] ?? 0) === $adminId && empty($data['sender_deleted']);
        if ($box === 'outbox' && $isSender) return 'outbox';
        if ($box === 'inbox' && $isRecipient) return 'inbox';
        if ($isRecipient) return 'inbox';
        if ($isSender) return 'outbox';
        return '';
    }

    /**
     * Nachricht aus EINER Sicht löschen: Kennzeichen der Rolle setzen; ist die
     * Nachricht damit für beide Seiten weg, folgt der Soft-Delete.
     *
     * @param int    $id   message_id
     * @param string $box  'inbox' (als Empfänger) | 'outbox' (als Absender)
     * @return bool        false: unbekannte Sicht
     */
    public function deleteFor(int $id, string $box): bool
    {
        if ($id < 1 || !in_array($box, ['inbox', 'outbox'], true)) return false;
        $column = ($box === 'inbox') ? 'recipient_deleted' : 'sender_deleted';
        $this->db->pushResult();
        $this->db->query("UPDATE `message` SET `{$column}` = 1 WHERE `message_id` = ?", 'i', [$id]);
        $this->db->query(
            "SELECT `recipient_deleted` = 1 AND `sender_deleted` = 1 FROM `message` WHERE `message_id` = ?",
            'i', [$id]
        );
        $gone = (int)(($this->db->fetchNum() ?? [0])[0] ?? 0) === 1;
        $this->db->popResult();
        if ($gone) return $this->delete($id);   // endgültig - kein Papierkorb
        return true;
    }

    /**
     * Nachrichten löschen, die älter sind als privacy.message_days (0 = nie).
     * Direkt per SQL: kein Audit-Log, keine Dateien, kein Papierkorb (siehe oben).
     *
     * @return int  gelöschte Nachrichten
     */
    public function purgeExpired(): int
    {
        $days = (int)($this->config->get('privacy.message_days') ?? 60);
        if ($days < 1) return 0;
        $this->db->pushResult();
        try {
            $this->db->query("DELETE FROM `message` WHERE `created_at` < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)", 'i', [$days]);
            return max(0, (int)$this->db->affected_rows);
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Zahl der Nachrichten eines Benutzers in seinem Posteingang und Postausgang.
     *
     * @return array  inbox, outbox
     */
    public function countOf(int $adminId): array
    {
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT SUM(`to_admin_id` = ? AND `recipient_deleted` = 0), SUM(`created_by` = ? AND `sender_deleted` = 0)"
                . " FROM `message` WHERE `to_admin_id` = ? OR `created_by` = ?",
                'iiii', [$adminId, $adminId, $adminId, $adminId]
            );
            $row = $this->db->fetchNum() ?? [];
            return ['inbox' => (int)($row[0] ?? 0), 'outbox' => (int)($row[1] ?? 0)];
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Alle Nachrichten eines Benutzers löschen (Superuser, etwa für einen gesperrten
     * Mitarbeiter): empfangene endgültig; gesendete verschwinden aus seinem
     * Postausgang, die Empfänger behalten ihre Fassung - wie bei deleteFor('outbox').
     * Hat der Empfänger sie schon gelöscht, ist die Zeile ganz weg.
     *
     * @return array  inbox (gelöschte empfangene), outbox (entfernte gesendete)
     */
    public function deleteAllOf(int $adminId): array
    {
        if ($adminId < 1) return ['inbox' => 0, 'outbox' => 0];
        $this->db->pushResult();
        try {
            $this->db->query("DELETE FROM `message` WHERE `to_admin_id` = ?", 'i', [$adminId]);
            $inbox = max(0, (int)$this->db->affected_rows);
            $this->db->query("UPDATE `message` SET `sender_deleted` = 1 WHERE `created_by` = ? AND `sender_deleted` = 0", 'i', [$adminId]);
            $outbox = max(0, (int)$this->db->affected_rows);
            $this->db->query("DELETE FROM `message` WHERE `created_by` = ? AND `recipient_deleted` = 1", 'i', [$adminId]);
        }
        finally {
            $this->db->popResult();
        }
        return ['inbox' => $inbox, 'outbox' => $outbox];
    }

    /**
     * Nachricht des CMS an einen Admin (z. B. Ergebnis einer Stapelverarbeitung):
     * nur im Posteingang des Adressaten sichtbar (siehe Klassenkommentar). Absender
     * ist der Session-Admin (created_by) - im Hintergrund der Auslöser des Vorgangs.
     *
     * @return int|null  message_id oder null bei Fehler (Meldungen in Cms::errors)
     */
    public function sendSystem(int $toAdminId, string $subject, string $body, string $html = ''): ?int
    {
        // (save() nimmt $data per Referenz - ein Array-Literal ginge nicht)
        $data = [
            'message_id'     => 0,
            'to_admin_id'    => $toAdminId,
            'subject'        => $subject,
            'body'           => $body,
            'html'           => $html,
            'sender_deleted' => 1,
        ];
        $id = $this->save(['to_admin_id', 'subject', 'body', 'html', 'sender_deleted'], $data);
        return $id ?: null;
    }

    /**
     * Wichtig-Kennzeichen setzen (Empfänger) - wie markRead() ohne save().
     */
    public function markImportant(int $id, bool $important): void
    {
        if ($id < 1) return;
        $this->db->pushResult();
        $this->db->query(
            "UPDATE `message` SET `is_important` = ? WHERE `message_id` = ?",
            'ii', [$important ? 1 : 0, $id]
        );
        $this->db->popResult();
    }

    /**
     * Gelesen-Kennzeichen setzen - ohne save(), damit changed_at/changed_by
     * unberührt bleiben (siehe Klassenkommentar).
     *
     * @param int  $id    message_id
     * @param bool $read  true: gelesen, false: ungelesen
     */
    public function markRead(int $id, bool $read): void
    {
        if ($id < 1) return;
        $this->db->pushResult();
        $this->db->query(
            "UPDATE `message` SET `is_read` = ? WHERE `message_id` = ?",
            'ii',
            [$read ? 1 : 0, $id]
        );
        $this->db->popResult();
    }

    /**
     * Anzahl der ungelesenen Nachrichten an einen Admin (Abzeichen im Backend-Kopf).
     *
     * @param int $adminId  admin_id
     */
    public function unreadCount(int $adminId): int
    {
        if ($adminId < 1) return 0;
        $this->db->pushResult();
        $this->db->query(
            "SELECT COUNT(*) FROM `message` WHERE `to_admin_id` = ? AND `is_read` = 0 AND `recipient_deleted` = 0",
            'i',
            [$adminId]
        );
        $count = (int)(($this->db->fetchNum() ?? [0])[0] ?? 0);
        $this->db->popResult();
        return $count;
    }
}
