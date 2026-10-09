<?php
/**
 * Anonymize a deleted user (module Privacy, tab "Deleted users",
 * additional right admin_anonymize). Instance name in the code: $adminAnonymizer.
 *
 * A deleted user remains as a row in `admin` (soft delete) - records,
 * templates and the audit log refer to their admin_id. Anonymizing removes
 * everything that leads to the person and keeps the id:
 *
 *     admin            name -> admin_{id} (reserved, Admin::hookOnSave()), first/
 *                      display/sort name, email, phone, mobile, settings,
 *                      password and second factor emptied; re-signed
 *     credentials      sessions, devices, passkeys, recovery codes, history
 *                      (Admin::removeCredentials() - already done on deletion,
 *                      here for accounts deleted earlier)
 *     audit_log        label of the entries for its account; older failed logins
 *                      under object 0 with its name (actor and label)
 *     var/audit        the history of its account (label, before/after of the
 *                      personal fields) and the older failed logins under object 0
 *                      (AuditStore::rewriteObject(), checkpoint 'privacy')
 *
 * Where it acted and where other objects refer to it (owner_id …), the audit log
 * holds only the id (AuditLog, class comment) - the display shows admin_{id} by itself
 * after the renaming. The process therefore stays fast, regardless of
 * how much the user has edited. The process is recorded in the
 * audit log of the account (action 'privacy') and in the log 'security'.
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

    /** Personal fields of the account - their values are emptied in its own history */
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

    /** Name of an anonymized account */
    public static function anonymousName(int $adminId): string
    {
        return 'admin_' . $adminId;
    }

    /** Is the account (row from `admin`) already anonymized? */
    public static function isAnonymized(array $row): bool
    {
        return (string)($row['name'] ?? '') === self::anonymousName((int)($row['admin_id'] ?? 0));
    }

    /**
     * Anonymize a deleted user - fast: at most two audit files, the
     * rest are a few UPDATEs. First the audit log, the row last: if the
     * process aborts, the old name is still there, and a second run finds everything
     * again (every step is repeatable). The completion is marked by the name
     * admin_{id} (isAnonymized()).
     *
     * @return array|null  ['entries' => changed entries in the files,
     *                      'objects' => rewritten files]; null: error in Cms::errors
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

        // Files: only the history of the account itself (label, personal fields,
        // its failed logins) and - for older entries - the failed logins under object 0
        // with its name. Where it acted, it is only stored as an id (AuditLog,
        // class comment): these files stay untouched, the display shows admin_{id} by itself
        // after renaming.
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

        // Meta rows, account, audit entry - one transaction
        $this->db->beginTransaction();
        try {
            // (entries from the time before "only ids" - see audit strip-names)
            $this->db->query("UPDATE `audit_log` SET `actor_name` = '' WHERE `actor_type` = 'admin' AND `admin_id` = ? AND `actor_name` <> ''",
                'i', [$adminId]);
            $this->db->query("UPDATE `audit_log` SET `actor_name` = ? WHERE `action` = 'login_failed' AND `actor_name` = ?",
                'ss', [$newName, $oldName]);
            $this->db->query("UPDATE `audit_log` SET `label` = ? WHERE `action` = 'login_failed' AND `label` = ?",
                'ss', [$newName, $oldName]);
            $this->db->query("UPDATE `audit_log` SET `label` = ? WHERE `object_type` = 'admin' AND `object_id` = ?",
                'si', [$newName, $adminId]);

            // Account: without Admin::save() - the name admin_* is locked there for exactly
            // this purpose. An empty password allows no login.
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
     * Anonymize an entry of an audit file: actor resp. entered
     * name of a failed login; in the history of the account itself ($own) additionally the label
     * and the values of the personal fields.
     */
    private function anonymizeEntry(array $body, int $adminId, string $oldName, string $newName, bool $own): array
    {
        $actor = (array)($body['actor'] ?? []);
        if ((string)($actor['type'] ?? '') === 'admin' && (int)($actor['admin_id'] ?? 0) === $adminId) {
            // Actor only as id (entries from the time before still carry the name)
            unset($body['actor']['name']);
        }
        elseif ((string)($body['action'] ?? '') === 'login_failed') {
            // Failed login: the entered name stands as actor and as label
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
