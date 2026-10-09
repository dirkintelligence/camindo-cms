<?php
/**
 * Signatures over security-relevant rows of the database.
 *
 * AGAINST WHAT
 * --------------------
 * Against someone who can write to the database but not to the server
 * (no access to .camindo.key): an open phpMyAdmin instance, a leaked
 * database password, an SQL injection in foreign code on the same server. Without
 * signatures a single UPDATE is enough there to make oneself a superuser:
 *
 *     admin.group_id       set to 1
 *     admin.name/password  swap with another row
 *     admin.status_id      reopen a locked account
 *     admin_session        insert a session for a foreign admin
 *     rel_group_*          give one's own group rights
 *     group.details        unlock modules for one's own group
 *
 * HOW
 * --------------------
 * Every protected row carries an HMAC in `sig` (Crypt::hash(), key from
 * .camindo.key) over its decisive columns. Whoever does not have the file cannot
 * produce a valid signature; a row without or with a wrong signature is treated
 * as if it did not exist. It is verified wherever the row grants rights:
 *
 *     admin          sign-in (Admin::loginByName/ByCode) and every request
 *                    (Session::load): name, password hash, group, status and the
 *                    second factor (second_factor - otherwise TOTP could be switched
 *                    down to the weaker email by a plain UPDATE)
 *     admin_session  every request (Session::load): session id, admin_id
 *     group          when building the group files (InstanceCache::readGroups): details
 *     rel_group_*    there too, per row: group, target, flags
 *
 * The signature of the group rights is per ROW, not per group: areas, instances
 * and languages disappear from the relation tables via ON DELETE CASCADE without
 * a group being saved. A signature over the whole set of rights would be
 * invalid afterwards. An attacker can delete rows that way - but that only removes
 * rights, it grants none.
 *
 * `admin`.`deleted` is not signed (Dataset::markDeleted() runs without hooks):
 * a revived account is useless without its password.
 *
 * WHO SIGNS
 * --------------------
 * Every write path the core knows - Admin::hookOnSaved(), Group::hookOnSaved(),
 * Group::grantInstance()/saveAreaRights(), Area::saveAccess()/grantGroups()/
 * copyAccess(), Session::create(). New write paths must write the signature along,
 * otherwise their rows count as tampered with. A direct UPDATE via SQL on a
 * signed column invalidates the row - deliberately.
 *
 * A failure ends up in the log 'security' (System::log()).
 *
 * @see Camindo\Core\Crypt   hash()
 * @see developer docs, chapter 13 (Security: authentication)
 */
namespace Camindo\Core;

class Integrity {

    public function __construct(
        private Database $db,
        private Crypt $crypt,
        private System $system,
    )
    {
    }

    // ====================
    // admin

    /**
     * Signature of an admin row.
     *
     * second_factor is only included if it is set: rows without their own factor
     * thus keep the signature they had before it was introduced.
     *
     * @param array $row  admin_id, name, password (hash), group_id, status_id, second_factor
     */
    public function adminSignature(array $row): string
    {
        $parts = [
            'admin',
            (int)($row['admin_id'] ?? 0),
            (string)($row['name'] ?? ''),
            (string)($row['password'] ?? ''),
            (int)($row['group_id'] ?? 0),
            (int)($row['status_id'] ?? 0),
        ];
        $factor = (string)($row['second_factor'] ?? '');
        if ($factor !== '') $parts[] = $factor;
        return $this->crypt->hash(implode('|', $parts));
    }

    /**
     * Sign the admin row (again) - after every save (Admin::hookOnSaved()).
     */
    public function signAdmin(int $adminId): void
    {
        if ($adminId < 1) return;
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT `admin_id`, `name`, `password`, `group_id`, `status_id`, `second_factor` FROM `admin` WHERE `admin_id` = ?",
                'i', [$adminId]
            );
            $row = $this->db->num_rows > 0 ? $this->db->fetch() : null;
            if ($row === null) return;
            $this->db->query(
                "UPDATE `admin` SET `sig` = ? WHERE `admin_id` = ?",
                'si', [$this->adminSignature($row), $adminId]
            );
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Read the admin row and check its signature.
     *
     * @return array|null  admin_id, name, group_id, status_id, second_factor, deleted - or null
     *                     if the row does not exist or the signature does not match
     */
    public function verifyAdmin(int $adminId): ?array
    {
        if ($adminId < 1) return null;
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT `admin_id`, `name`, `password`, `group_id`, `status_id`, `second_factor`, `deleted`, `sig` FROM `admin` WHERE `admin_id` = ?",
                'i', [$adminId]
            );
            $row = $this->db->num_rows > 0 ? $this->db->fetch() : null;
        }
        finally {
            $this->db->popResult();
        }
        if ($row === null) return null;
        if (!hash_equals($this->adminSignature($row), (string)$row['sig'])) {
            $this->system->log('security', "admin {$adminId} ({$row['name']}): signature mismatch - row ignored");
            return null;
        }
        unset($row['password'], $row['sig']);
        $row['admin_id']  = (int)$row['admin_id'];
        $row['group_id']  = (int)$row['group_id'];
        $row['status_id'] = (int)$row['status_id'];
        $row['deleted']   = (int)$row['deleted'];
        return $row;
    }

    // ====================
    // admin_session

    /**
     * Signature of a session row: id, admin and interface (backend, cli, php) -
     * an interface changed via SQL invalidates the session.
     */
    public function sessionSignature(string $sessionId, int $adminId, string $interface = 'backend'): string
    {
        return $this->crypt->hash('session|' . $sessionId . '|' . $adminId . '|' . $interface);
    }

    public function verifySession(string $sessionId, int $adminId, string $sig, string $interface = 'backend'): bool
    {
        if (hash_equals($this->sessionSignature($sessionId, $adminId, $interface), $sig)) return true;
        $this->system->log('security', "admin_session for admin {$adminId}: signature mismatch - session ignored");
        return false;
    }

    /**
     * CSRF token of a session (developer docs, chapter 14 (Security: web)).
     *
     * Derived, not stored: the same HMAC key as for the signatures
     * (Crypt::hash(), .camindo.key). Whoever does not know the session id cannot
     * produce the token - and whoever knows it has the session anyway. The cookie is
     * HttpOnly, the token is in the DOM for this (cConfig): it does not protect against
     * XSS, but it does against foreign origins (they do not get to cConfig).
     *
     * Deliberately separated from sessionSignature() by its own prefix - the same
     * input must not have two meanings.
     */
    public function csrfToken(string $sessionId): string
    {
        if ($sessionId === '') return '';
        return $this->crypt->hash('csrf|' . $sessionId);
    }

    public function verifyCsrf(string $sessionId, string $token): bool
    {
        if ($sessionId === '' || $token === '') return false;
        return hash_equals($this->csrfToken($sessionId), $token);
    }

    // ====================
    // group

    /**
     * @param string $details  `group`.`details` wie gespeichert (JSON)
     */
    public function groupSignature(int $groupId, string $details): string
    {
        return $this->crypt->hash('group|' . $groupId . '|' . $details);
    }

    /**
     * Sign the group row (again) - after every save (Group::hookOnSaved()).
     */
    public function signGroup(int $groupId): void
    {
        if ($groupId < 1) return;
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `details` FROM `group` WHERE `group_id` = ?", 'i', [$groupId]);
            $row = $this->db->num_rows > 0 ? $this->db->fetch() : null;
            if ($row === null) return;
            $this->db->query(
                "UPDATE `group` SET `sig` = ? WHERE `group_id` = ?",
                'si', [$this->groupSignature($groupId, (string)$row['details']), $groupId]
            );
        }
        finally {
            $this->db->popResult();
        }
    }

    public function verifyGroup(int $groupId, string $details, string $sig): bool
    {
        if (hash_equals($this->groupSignature($groupId, $details), $sig)) return true;
        $this->system->log('security', "group {$groupId}: signature mismatch - rights ignored");
        return false;
    }

    // ====================
    // Alles neu signieren

    /**
     * Re-sign all signed rows - `camindo.php admin sign`.
     *
     * For changing .camindo.key and for development data (dev.sql) that was signed
     * with a different key. What is in the database is signed - the call is an act of trust
     * by whoever has the command line, and does not belong in a web request. Sessions are
     * deleted (new sign-in), not re-signed.
     *
     * @return array  table => number of signed rows
     */
    public function signAll(): array
    {
        $counts = [];
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `admin_id` FROM `admin`");
            $ids = array_map('intval', $this->db->fetchColumn());
            foreach ($ids as $id) $this->signAdmin($id);
            $counts['admin'] = count($ids);

            // Superuser group without a signature: its rights are not in the database
            // (InstanceCache::buildGroups()), and seed.sql stays key-independent
            $this->db->query("SELECT `group_id` FROM `group` WHERE `group_id` <> ?", 'i', [InstanceCache::SUPERUSER_GROUP_ID]);
            $ids = array_map('intval', $this->db->fetchColumn());
            foreach ($ids as $id) $this->signGroup($id);
            $counts['group'] = count($ids);

            $this->db->query("SELECT * FROM `rel_group_area`");
            $rows = $this->db->fetchAll();
            foreach ($rows as $row) {
                $this->db->query(
                    "UPDATE `rel_group_area` SET `sig` = ? WHERE `group_id` = ? AND `area_id` = ?",
                    'sii', [$this->areaRightSignature((int)$row['group_id'], (int)$row['area_id'], $row), (int)$row['group_id'], (int)$row['area_id']]
                );
            }
            $counts['rel_group_area'] = count($rows);

            $this->db->query("SELECT `group_id`, `instance_id` FROM `rel_group_instance`");
            $rows = $this->db->fetchAll();
            foreach ($rows as $row) {
                $this->db->query(
                    "UPDATE `rel_group_instance` SET `sig` = ? WHERE `group_id` = ? AND `instance_id` = ?",
                    'sii', [$this->instanceRightSignature((int)$row['group_id'], (int)$row['instance_id']), (int)$row['group_id'], (int)$row['instance_id']]
                );
            }
            $counts['rel_group_instance'] = count($rows);

            $this->db->query("SELECT `group_id`, `language_id`, `can_write` FROM `rel_group_language`");
            $rows = $this->db->fetchAll();
            foreach ($rows as $row) {
                $this->db->query(
                    "UPDATE `rel_group_language` SET `sig` = ? WHERE `group_id` = ? AND `language_id` = ?",
                    'sii', [$this->languageRightSignature((int)$row['group_id'], (int)$row['language_id'], !empty($row['can_write'])), (int)$row['group_id'], (int)$row['language_id']]
                );
            }
            $counts['rel_group_language'] = count($rows);

            $this->db->query("SELECT `group_id`, `status_id` FROM `rel_group_status`");
            $rows = $this->db->fetchAll();
            foreach ($rows as $row) {
                $this->db->query(
                    "UPDATE `rel_group_status` SET `sig` = ? WHERE `group_id` = ? AND `status_id` = ?",
                    'sii', [$this->statusRightSignature((int)$row['group_id'], (int)$row['status_id']), (int)$row['group_id'], (int)$row['status_id']]
                );
            }
            $counts['rel_group_status'] = count($rows);

            $this->db->query("SELECT `passkey_id`, `admin_id`, `credential_hash`, `public_key`, `alg` FROM `admin_passkey`");
            $rows = $this->db->fetchAll();
            foreach ($rows as $row) {
                $this->db->query(
                    "UPDATE `admin_passkey` SET `sig` = ? WHERE `passkey_id` = ?",
                    'si', [$this->passkeySignature($row), (int)$row['passkey_id']]
                );
            }
            $counts['admin_passkey'] = count($rows);

            $this->db->query("DELETE FROM `admin_session`");
        }
        finally {
            $this->db->popResult();
        }
        return $counts;
    }

    // ====================
    // admin_passkey (developer docs, chapter 13 (Security: authentication))

    /**
     * Signature of a passkey row: account, credential (hash of the id), public
     * key and algorithm. Whoever writes the database cannot store their own
     * key for a foreign account that way. The signature counter is not part of it -
     * it changes at every sign-in.
     *
     * @param array $row  admin_id, credential_hash (binary), public_key, alg
     */
    public function passkeySignature(array $row): string
    {
        return $this->crypt->hash(implode('|', [
            'passkey',
            (int)($row['admin_id'] ?? 0),
            bin2hex((string)($row['credential_hash'] ?? '')),
            hash('sha256', (string)($row['public_key'] ?? '')),
            (int)($row['alg'] ?? 0),
        ]));
    }

    /** Does the stored signature of a passkey row match? */
    public function verifyPasskey(array $row): bool
    {
        $sig = (string)($row['sig'] ?? '');
        return $sig !== '' && hash_equals($this->passkeySignature($row), $sig);
    }

    // ====================
    // rel_group_area, rel_group_instance, rel_group_language, rel_group_status

    /**
     * Signature of a row from rel_group_area: group, area and the five flags.
     *
     * @param array $flags  group_write, group_delete, other_write, other_delete,
     *                      set_owner (0|1; missing keys = column default)
     */
    public function areaRightSignature(int $groupId, int $areaId, array $flags): string
    {
        return $this->crypt->hash(implode('|', [
            'rel_group_area', $groupId, $areaId,
            (int)!empty($flags['group_write'] ?? 1),
            (int)!empty($flags['group_delete'] ?? 1),
            (int)!empty($flags['other_write'] ?? 1),
            (int)!empty($flags['other_delete'] ?? 1),
            (int)!empty($flags['set_owner'] ?? 0),
        ]));
    }

    public function instanceRightSignature(int $groupId, int $instanceId): string
    {
        return $this->crypt->hash('rel_group_instance|' . $groupId . '|' . $instanceId);
    }

    public function languageRightSignature(int $groupId, int $languageId, bool $canWrite): string
    {
        return $this->crypt->hash('rel_group_language|' . $groupId . '|' . $languageId . '|' . (int)$canWrite);
    }

    /** Row in rel_group_status: the group may set this status */
    public function statusRightSignature(int $groupId, int $statusId): string
    {
        return $this->crypt->hash('rel_group_status|' . $groupId . '|' . $statusId);
    }

    /**
     * Check a row of a relation table (InstanceCache::readGroups()).
     *
     * @param string $table  rel_group_area | rel_group_instance | rel_group_language |
     *                       rel_group_status
     * @param array  $row    the row as read (with `sig`)
     */
    public function verifyRight(string $table, array $row): bool
    {
        $groupId = (int)($row['group_id'] ?? 0);
        $expected = match ($table) {
            'rel_group_area'     => $this->areaRightSignature($groupId, (int)($row['area_id'] ?? 0), $row),
            'rel_group_instance' => $this->instanceRightSignature($groupId, (int)($row['instance_id'] ?? 0)),
            'rel_group_language' => $this->languageRightSignature($groupId, (int)($row['language_id'] ?? 0), !empty($row['can_write'])),
            'rel_group_status'   => $this->statusRightSignature($groupId, (int)($row['status_id'] ?? 0)),
            default => '',
        };
        if ($expected !== '' && hash_equals($expected, (string)($row['sig'] ?? ''))) return true;
        $this->system->log('security', "{$table} group {$groupId}: signature mismatch - row ignored");
        return false;
    }
}
