<?php
/**
 * Gelöschten Benutzer anonymisieren (Modul Privacy, Reiter "Gelöschte Benutzer",
 * Zusatzrecht admin_anonymize). Instanzname im Code: $adminAnonymizer.
 *
 * Ein gelöschter Benutzer bleibt als Zeile in `admin` stehen (Soft-Delete) - Datensätze,
 * Templates und das Audit-Log verweisen auf seine admin_id. Anonymisieren entfernt
 * alles, was zur Person führt, und lässt die Id stehen:
 *
 *     admin            name -> admin_{id} (reserviert, Admin::hookOnSave()), Vor-/
 *                      Anzeige-/Sortiername, E-Mail, Telefon, Mobil, Einstellungen,
 *                      Passwort und zweiter Faktor geleert; neu signiert
 *     Anmeldedaten     Sessions, Geräte, Passkeys, Wiederherstellungscodes, Verlauf
 *                      (Admin::removeCredentials() - beim Löschen schon geschehen,
 *                      hier für früher gelöschte Konten)
 *     audit_log        Bezeichnung der Einträge zu seinem Konto; ältere Fehlversuche
 *                      unter Objekt 0 mit seinem Namen (Handelnder und Bezeichnung)
 *     var/audit        der Verlauf seines Kontos (Bezeichnung, Vorher/Nachher der
 *                      persönlichen Felder) und die älteren Fehlversuche unter Objekt 0
 *                      (AuditStore::rewriteObject(), Kontrollpunkt 'privacy')
 *
 * Wo er gehandelt hat und wo andere Objekte auf ihn verweisen (owner_id …), steht er im
 * Audit-Log nur als Id (AuditLog, Klassenkommentar) - die Anzeige zeigt nach dem
 * Umbenennen von selbst admin_{id}. Deshalb bleibt der Vorgang schnell, unabhängig
 * davon, wie viel der Benutzer bearbeitet hat. Der Vorgang steht im
 * Audit-Log des Kontos (Aktion 'privacy') und im Log 'security'.
 *
 * @see Camindo\Core\Modules\Privacy\PrivacyBackend::adminAnonymize()
 */
namespace Camindo\Core\Modules\Privacy;

use Camindo\Core\AuditLog;
use Camindo\Core\AuditStore;
use Camindo\Core\Cms;
use Camindo\Core\Crypt;
use Camindo\Core\Database;
use Camindo\Core\Integrity;
use Camindo\Core\System;
use Camindo\Core\Modules\Admin\Admin;

class AdminAnonymizer {

    /** Persönliche Felder des Kontos - im eigenen Verlauf werden ihre Werte geleert */
    public const PERSONAL_FIELDS = ['name', 'primename', 'displayname', 'sortname', 'email', 'phone', 'mobile'];

    public function __construct(
        private Database $db,
        private Cms $cms,
        private Crypt $crypt,
        private System $system,
        private AuditLog $auditLog,
        private AuditStore $auditStore,
        private Integrity $integrity,
        private Admin $admin,
    )
    {}

    /** Name eines anonymisierten Kontos */
    public static function anonymousName(int $adminId): string
    {
        return 'admin_' . $adminId;
    }

    /** Ist das Konto (Zeile aus `admin`) schon anonymisiert? */
    public static function isAnonymized(array $row): bool
    {
        return (string)($row['name'] ?? '') === self::anonymousName((int)($row['admin_id'] ?? 0));
    }

    /**
     * Gelöschten Benutzer anonymisieren - schnell: höchstens zwei Audit-Dateien, der
     * Rest sind wenige UPDATEs. Erst das Audit-Log, zuletzt die Zeile: bricht der
     * Vorgang ab, steht der alte Name noch da, und ein zweiter Lauf findet alles
     * wieder (jeder Schritt ist wiederholbar). Den Abschluss kennzeichnet der Name
     * admin_{id} (isAnonymized()).
     *
     * @return array|null  ['entries' => geänderte Einträge in den Dateien,
     *                      'objects' => umgeschriebene Dateien]; null: Fehler in Cms::errors
     */
    public function anonymize(int $adminId): ?array
    {
        $this->db->query("SELECT `admin_id`, `name`, `deleted` FROM `admin` WHERE `admin_id` = ?", 'i', [$adminId]);
        $row = $this->db->fetch();
        if (!$row || empty($row['deleted'])) {
            $this->cms->error($this->cms->snippet('privacy.error_anonymize_not_deleted'));
            return null;
        }
        $oldName = (string)$row['name'];
        $newName = self::anonymousName($adminId);

        // Dateien: nur der Verlauf des Kontos selbst (Bezeichnung, persönliche Felder,
        // seine Fehlversuche) und - für ältere Einträge - die Fehlversuche unter Objekt 0
        // mit seinem Namen. Wo er gehandelt hat, steht er nur als Id (AuditLog,
        // Klassenkommentar): diese Dateien bleiben unberührt, die Anzeige zeigt nach dem
        // Umbenennen von selbst admin_{id}.
        $objects = [['admin', $adminId]];
        $this->db->query("SELECT 1 FROM `audit_log` WHERE `object_type` = 'admin' AND `object_id` = 0"
            . " AND `action` = 'login_failed' AND (`actor_name` = ? OR `label` = ?) LIMIT 1", 'ss', [$oldName, $oldName]);
        if ($this->db->num_rows > 0) $objects[] = ['admin', 0];

        $entries = 0;
        $files = 0;
        foreach ($objects as [$type, $objectId]) {
            $own = ($objectId === $adminId);
            $changed = $this->auditStore->rewriteObject($type, $objectId,
                fn(array $body): array => $this->anonymizeEntry($body, $adminId, $oldName, $newName, $own));
            $entries += $changed;
            if ($changed > 0) $files++;
        }

        // Metazeilen, Konto, Audit-Eintrag - eine Transaktion
        $this->db->beginTransaction();
        try {
            // (Einträge aus der Zeit vor "nur Ids" - siehe audit strip-names)
            $this->db->query("UPDATE `audit_log` SET `actor_name` = '' WHERE `actor_type` = 'admin' AND `admin_id` = ? AND `actor_name` <> ''",
                'i', [$adminId]);
            $this->db->query("UPDATE `audit_log` SET `actor_name` = ? WHERE `action` = 'login_failed' AND `actor_name` = ?",
                'ss', [$newName, $oldName]);
            $this->db->query("UPDATE `audit_log` SET `label` = ? WHERE `action` = 'login_failed' AND `label` = ?",
                'ss', [$newName, $oldName]);
            $this->db->query("UPDATE `audit_log` SET `label` = ? WHERE `object_type` = 'admin' AND `object_id` = ?",
                'si', [$newName, $adminId]);

            // Konto: ohne Admin::save() - der Name admin_* ist dort für genau diesen
            // Zweck gesperrt. Ein leeres Passwort lässt keine Anmeldung zu.
            $this->db->query(
                "UPDATE `admin` SET `name` = ?, `primename` = '', `displayname` = '', `sortname` = '',"
                . " `email` = '', `phone` = '', `mobile` = '', `settings` = '', `ft` = ?,"
                . " `password` = '', `password_change` = 0, `uid` = ?, `code` = '',"
                . " `second_factor` = '', `totp_secret` = '', `totp_step` = 0, `totp_enabled_at` = NULL"
                . " WHERE `admin_id` = ?",
                'sssi', [$newName, $newName, $this->crypt->randomKey(32), $adminId]
            );
            $this->integrity->signAdmin($adminId);
            $this->admin->removeCredentials($adminId);

            $this->auditLog->write('privacy', 'admin', $adminId, $newName);
            $this->db->commit();
        }
        catch (\Throwable $e) {
            $this->db->rollback();
            $this->cms->error($e->getMessage());
            return null;
        }

        $this->system->log('security', sprintf('deleted admin %d anonymized: %d audit entries in %d files', $adminId, $entries, $files));
        return ['entries' => $entries, 'objects' => $files];
    }

    /**
     * Einen Eintrag einer Audit-Datei anonymisieren: Handelnder bzw. eingegebener
     * Name eines Fehlversuchs; im Verlauf des Kontos selbst ($own) dazu Bezeichnung
     * und die Werte der persönlichen Felder.
     */
    private function anonymizeEntry(array $body, int $adminId, string $oldName, string $newName, bool $own): array
    {
        $actor = (array)($body['actor'] ?? []);
        if ((string)($actor['type'] ?? '') === 'admin' && (int)($actor['admin_id'] ?? 0) === $adminId) {
            // Handelnde nur als Id (Einträge aus der Zeit davor tragen noch den Namen)
            unset($body['actor']['name']);
        }
        elseif ((string)($body['action'] ?? '') === 'login_failed') {
            // Fehlversuch: der eingegebene Name steht als Handelnder und als Bezeichnung
            if ((string)($actor['name'] ?? '') === $oldName) $body['actor']['name'] = $newName;
            if ((string)($body['label'] ?? '') === $oldName) $body['label'] = $newName;
        }
        if ($own) {
            $body['label'] = $newName;
            foreach ((array)($body['changes'] ?? []) as $i => $change) {
                $key = (string)($change['k'] ?? '');
                if (!in_array($key, self::PERSONAL_FIELDS, true)) continue;
                $value = $key === 'name' ? $newName : '';
                foreach (['b', 'a'] as $side) {
                    if ((string)($change[$side] ?? '') !== '') $body['changes'][$i][$side] = $value;
                }
                unset($body['changes'][$i]['c']);
            }
        }
        return $body;
    }
}
