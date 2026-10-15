<?php
/**
 * Key rotation: change .camindo.key without losing data
 * (`php Core/cron/camindo.php key rotate|status|retire`, developer docs, chapter 13 (Security: authentication)).
 *
 * WHAT THE KEY IS USED FOR
 * --------------------
 *     reversibly encrypted      data_enc.data, record_cache (values "encrypted"),
 *                               admin.email/mobile/phone, admin.totp_secret,
 *                               user_second_factor.totp_secret,
 *                               message.body/html, scheduler.details.password,
 *                               var/audit (encrypted values of the snapshots)
 *                               -> re-encrypted; the HMAC chain of the audit log
 *                               is recalculated after a check
 *     HMAC over a known text    data_enc.hash (search), signatures of the class Integrity
 *                               (admin, group, rel_group_*, admin_session)
 *                               -> recalculated ("re-sign")
 *     HMAC over a secret text   recovery codes, device identifiers: the plain text
 *                               is unknown -> remain valid via Crypt::hashAll()
 *                               as long as the old key is in the key ring
 *     password pepper           cannot be converted without the plain text -> the hashes keep
 *                               the identifier of the old key and are recalculated at the
 *                               next login
 *     derived                   CSRF tokens (session), replacement hash -> new
 *
 * FLOW OF rotate()
 * --------------------
 *  1. database backup (Backup::beforeChange('key-rotate'))
 *  2. maintenance mode on (unless already on)
 *  3. password hashes without identifier get that of the old key
 *  4. old key -> {key_file}.retired, new one -> key_file (both with the rights
 *     of the previous key_file, without a file permissions.key_file - keyFileMode(); as root
 *     also its owner - SecretFile::copyOwner()); from here on
 *     Crypt decrypts with both, so an abort loses nothing - rotate()
 *     can simply be called again (then with another key)
 *  5. re-encrypt everything reversible, renew data_enc.hash
 *  6. Integrity::signAll() - all signatures with the new key, deletes all
 *     admin sessions; group cache renewed
 *  7. with $invalidatePasswords (passwords publicly exposed): all passwords
 *     emptied (admins: `camindo.php admin password NAME`, users: "forgot password"),
 *     all sessions, devices and recovery codes removed, the old key
 *     is removed immediately
 *  8. maintenance mode off
 *
 * At most one rotation per 24 hours (MIN_INTERVAL): a second one so soon after
 * would be a mistake and would push the just-retired key along too quickly.
 * The time is stored as the line "# rotated {ISO 8601}" in {key_file}.retired - also
 * after -f, when the old key itself has already been removed.
 *
 * If the key comes from the environment (CAMINDO_KEY, Crypt::keySource()), rotate()
 * still writes the new one to key_file - the file takes precedence. The operator
 * updates the variable by hand; only after that may key_file be removed
 * (status(): env_key).
 *
 * The old key stays in the key ring afterwards until retire() removes it -
 * status() shows what still depends on it (passwords that have not logged in since
 * then; recovery codes from before). retire() only removes it
 * if nothing encrypted depends on it any more; remaining passwords become
 * invalid in the process (with $force).
 */
namespace Camindo\Core;

class KeyRotation {

    /** Minimum interval between two rotations in seconds */
    public const MIN_INTERVAL = 86400;

    public function __construct(
        private Config $config,
        private Database $db,
        private Crypt $crypt,
        private Integrity $integrity,
        private InstanceCache $instanceCache,
        private SystemState $systemState,
        private Backup $backup,
        private System $system,
        private AuditStore $auditStore,
        private Cms $cms,
    )
    {
    }

    // ====================
    // rehash

    /**
     * Recalculate search values of encrypted fields (data_enc.hash) - without a new
     * key: from the decrypted plain text with the current normalization
     * (Cms::encryptedHash()). Needed for data that was stored before the normalization
     * (`php Core/cron/camindo.php key rehash`); afterwards the search finds it
     * in other upper/lower case as well. Rows that cannot be decrypted
     * stay unchanged.
     *
     * @return int  number of changed rows
     */
    public function rehashEncrypted(): int
    {
        $n = 0;
        foreach ($this->rows("SELECT `language_id`, `record_id`, `fieldname_id`, `data`, `hash` FROM `data_enc`") as $row) {
            $plain = $this->crypt->decrypt((string)$row['data'], '', true);
            if ($plain === '' && (string)$row['data'] !== '') continue;
            $hash = $this->cms->encryptedHash($plain);
            if ($hash === (string)$row['hash']) continue;
            $this->db->query(
                "UPDATE `data_enc` SET `hash` = ? WHERE `language_id` = ? AND `record_id` = ? AND `fieldname_id` = ?",
                'siii', [$hash, (int)$row['language_id'], (int)$row['record_id'], (int)$row['fieldname_id']]
            );
            $n++;
        }
        return $n;
    }

    // ====================
    // rotate

    /**
     * Introduce a new key (see class comment).
     *
     * @param bool   $invalidatePasswords  all passwords invalid immediately
     * @param bool   $backup               back up beforehand (false only for tests)
     * @param callable|null $log           fn(string $line) for progress messages
     * @return array  old, new (key ids), counts per area
     */
    public function rotate(bool $invalidatePasswords = false, bool $backup = true, ?callable $log = null): array
    {
        $log ??= fn(string $line) => null;
        $keyFile = $this->keyFile();
        $last = $this->lastRotation();
        if ($last !== null && time() - $last < self::MIN_INTERVAL) {
            throw new \RuntimeException('key was rotated on ' . gmdate('Y-m-d H:i', $last) . ' UTC - next rotation possible from '
                . gmdate('Y-m-d H:i', $last + self::MIN_INTERVAL) . ' UTC');
        }
        if (!is_writable(dirname($keyFile))) {
            throw new \RuntimeException('directory of ' . $keyFile . ' is not writable');
        }
        if ($backup) {
            $log('backup: ' . $this->backup->beforeChange('key-rotate'));
        }
        $wasMaintenance = $this->systemState->isMaintenance();
        if (!$wasMaintenance) $this->systemState->lock('key rotation');

        $counts = [];
        try {
            $oldId = $this->crypt->currentKeyId();

            // 3. Password hashes without identifier: peppered with the old key
            $counts['passwords tagged'] = $this->tagPasswords($oldId);

            // 4. Key ring: old -> .retired, new -> key_file
            // (the current one may come from CAMINDO_KEY - the new one always ends up
            // in key_file and thus takes precedence until the variable is updated)
            $oldKey = $this->crypt->currentKeyForRotation();
            $newKey = base64_encode(random_bytes(32));
            $mode = $this->keyFileMode();
            $this->appendRetired($oldKey);
            $this->writeSecret($keyFile . '.new', $newKey, $mode);
            // as root (sudo, docker exec) the new file would belong to root - keep the owner
            // (key from CAMINDO_KEY: owner of the project directory, SecretFile::guessWebUser())
            if (is_file($keyFile)) SecretFile::copyOwner($keyFile . '.new', $keyFile);
            elseif (SecretFile::isRoot() && ($user = SecretFile::guessWebUser(dirname($keyFile))) !== null) {
                SecretFile::chown($keyFile . '.new', $user);
            }
            if (!@rename($keyFile . '.new', $keyFile)) throw new \RuntimeException("cannot replace $keyFile");
            $this->crypt->reloadKeys();
            $newId = $this->crypt->currentKeyId();
            $log("key: $oldId -> $newId");

            // 5. re-encrypt
            $counts += $this->reencryptAll($log);

            // 7. Passwords publicly exposed: remove everything secret
            if ($invalidatePasswords) {
                $counts += $this->invalidatePasswords();
                $this->removeRetired($oldId);
                $this->crypt->reloadKeys();
                $log("old key $oldId removed");
            }

            // 6. Signatures (deletes all admin sessions), caches
            foreach ($this->integrity->signAll() as $table => $count) {
                $counts['signed ' . $table] = $count;
            }
            $this->instanceCache->rebuild();
            @unlink(rtrim((string)$this->config->get('paths.var_dir'), '/') . '/cache/password_dummy.txt');
        }
        finally {
            if (!$wasMaintenance) $this->systemState->unlock();
        }
        $this->system->log('security', "key rotated: {$oldId} -> {$newId}" . ($invalidatePasswords ? ' (passwords invalidated)' : ''));
        return ['old' => $oldId, 'new' => $newId, 'counts' => $counts];
    }

    /**
     * Tag password hashes without key identifier with $kid (admin, data_pwd).
     * admin.password is part of the signature - signAll() follows in rotate().
     */
    private function tagPasswords(string $kid): int
    {
        $count = 0;
        foreach ($this->passwordRows() as $row) {
            [$tag, $hash] = Crypt::splitPasswordHash($row['value']);
            if ($tag !== '') continue;
            $this->setPassword($row, $kid . ':' . $hash);
            $count++;
        }
        return $count;
    }

    /**
     * All stored password hashes (admin.password, data_pwd.data) with their
     * key. Individually instead of via SQL: the columns are latin1, a concatenation with
     * utf8mb4 parameters fails on the collation.
     *
     * @return array  list of table, value, key (columns => primary key values)
     */
    private function passwordRows(): array
    {
        $result = [];
        foreach ($this->rows("SELECT `admin_id`, `password` FROM `admin` WHERE `password` <> ''") as $row) {
            $result[] = ['table' => 'admin', 'value' => (string)$row['password'], 'key' => ['admin_id' => (int)$row['admin_id']]];
        }
        foreach ($this->rows("SELECT `language_id`, `record_id`, `fieldname_id`, `data` FROM `data_pwd` WHERE `data` <> ''") as $row) {
            $result[] = ['table' => 'data_pwd', 'value' => (string)$row['data'], 'key' => [
                'language_id' => (int)$row['language_id'], 'record_id' => (int)$row['record_id'], 'fieldname_id' => (int)$row['fieldname_id']]];
        }
        return $result;
    }

    /** Write the password hash of a row from passwordRows() */
    private function setPassword(array $row, string $value): void
    {
        $column = $row['table'] === 'admin' ? 'password' : 'data';
        $where = implode(' AND ', array_map(fn($c) => "`$c` = ?", array_keys($row['key'])));
        $this->db->query("UPDATE `{$row['table']}` SET `$column` = ? WHERE $where",
            's' . str_repeat('i', count($row['key'])), array_merge([$value], array_values($row['key'])));
    }

    /**
     * Re-encrypt everything reversible with the current key. Values that
     * cannot be decrypted with any key stay unchanged (and
     * appear in status()).
     *
     * @return array  area => number of changed values
     */
    public function reencryptAll(?callable $log = null): array
    {
        $log ??= fn(string $line) => null;
        $counts = [];

        // data_enc: ciphertext + search hash of the plain text
        $n = 0;
        foreach ($this->rows("SELECT `language_id`, `record_id`, `fieldname_id`, `data` FROM `data_enc`") as $row) {
            $plain = $this->crypt->decrypt((string)$row['data'], '', true);
            if ($plain === '' && (string)$row['data'] !== '') continue;
            $this->db->query(
                "UPDATE `data_enc` SET `data` = ?, `hash` = ? WHERE `language_id` = ? AND `record_id` = ? AND `fieldname_id` = ?",
                'ssiii', [$this->crypt->encrypt($plain, '', true), $this->cms->encryptedHash($plain),
                    (int)$row['language_id'], (int)$row['record_id'], (int)$row['fieldname_id']]
            );
            $n++;
        }
        $counts['data_enc'] = $n;

        // record_cache: {"field": ["encrypted", base64(ciphertext)], …}
        $n = 0;
        foreach ($this->rows("SELECT `record_id`, `language_id`, `cache` FROM `record_cache` WHERE `cache` LIKE '%\"encrypted\"%'") as $row) {
            $cache = json_decode((string)$row['cache'], true);
            if (!is_array($cache)) continue;
            $changed = false;
            foreach ($cache as $name => $entry) {
                if (!is_array($entry) || ($entry[0] ?? '') !== 'encrypted' || !is_string($entry[1] ?? null) || $entry[1] === '') continue;
                $cipher = base64_decode($entry[1], true);
                $plain = $cipher === false ? '' : $this->crypt->decrypt($cipher, '', true);
                if ($plain === '') continue;
                $cache[$name][1] = base64_encode($this->crypt->encrypt($plain, '', true));
                $changed = true;
            }
            if (!$changed) continue;
            $this->db->query("UPDATE `record_cache` SET `cache` = ? WHERE `record_id` = ? AND `language_id` = ?",
                'sii', [json_encode($cache, JSON_UNESCAPED_UNICODE), (int)$row['record_id'], (int)$row['language_id']]);
            $n++;
        }
        $counts['record_cache'] = $n;

        // admin: contact data (without AAD), TOTP secret (AAD totp|{id}, TotpFactor)
        $n = 0;
        foreach ($this->rows("SELECT `admin_id`, `email`, `mobile`, `phone`, `totp_secret` FROM `admin`") as $row) {
            $id = (int)$row['admin_id'];
            $values = [];
            foreach (['email', 'mobile', 'phone'] as $column) {
                $values[$column] = $this->recrypt((string)$row[$column], '', true);
            }
            $values['totp_secret'] = $this->recrypt((string)$row['totp_secret'], 'totp|' . $id, true);
            $this->db->query("UPDATE `admin` SET `email` = ?, `mobile` = ?, `phone` = ?, `totp_secret` = ? WHERE `admin_id` = ?",
                'ssssi', [$values['email'], $values['mobile'], $values['phone'], $values['totp_secret'], $id]);
            $n++;
        }
        $counts['admin'] = $n;

        // user_second_factor: TOTP secret of the frontend users (AAD utotp|{record_id},
        // UserSecondFactor)
        $n = 0;
        foreach ($this->rows("SELECT `record_id`, `totp_secret` FROM `user_second_factor` WHERE `totp_secret` <> ''") as $row) {
            $id = (int)$row['record_id'];
            $this->db->query("UPDATE `user_second_factor` SET `totp_secret` = ? WHERE `record_id` = ?",
                'si', [$this->recrypt((string)$row['totp_secret'], 'utotp|' . $id, true), $id]);
            $n++;
        }
        $counts['user_second_factor'] = $n;

        // message: text and HTML of the internal messages
        $n = 0;
        foreach ($this->rows("SELECT `message_id`, `body`, `html` FROM `message`") as $row) {
            $this->db->query("UPDATE `message` SET `body` = ?, `html` = ? WHERE `message_id` = ?",
                'ssi', [$this->recrypt((string)$row['body'], '', true), $this->recrypt((string)$row['html'], '', true), (int)$row['message_id']]);
            $n++;
        }
        $counts['message'] = $n;

        // scheduler: HTTP auth password in details (base64)
        $n = 0;
        foreach ($this->rows("SELECT `scheduler_id`, `details` FROM `scheduler` WHERE `details` LIKE '%\"password\"%'") as $row) {
            $details = json_decode((string)$row['details'], true);
            if (!is_array($details) || !is_string($details['password'] ?? null) || $details['password'] === '') continue;
            $details['password'] = $this->recrypt($details['password'], '', false);
            $this->db->query("UPDATE `scheduler` SET `details` = ? WHERE `scheduler_id` = ?",
                'si', [json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int)$row['scheduler_id']]);
            $n++;
        }
        $counts['scheduler'] = $n;

        // Audit log (var/audit, AuditStore): encrypted values (t = encrypted) re-encrypted in
        // v/b/a, HMAC in c recalculated - and the chain recalculated with the new key, after
        // checking the old one (checkpoint 'rekey' with "verified")
        $recryptNode = function (&$node) use (&$recryptNode) {
            if (!is_array($node)) return;
            if (($node['t'] ?? '') === 'encrypted') {
                foreach (['v', 'b', 'a'] as $key) {
                    if (!is_string($node[$key] ?? null) || $node[$key] === '') continue;
                    $cipher = base64_decode($node[$key], true);
                    $plain = $cipher === false ? '' : $this->crypt->decrypt($cipher, '', true);
                    if ($plain === '') continue;
                    $node[$key] = base64_encode($this->crypt->encrypt($plain, '', true));
                    if ($key === 'v' && isset($node['c'])) $node['c'] = bin2hex($this->crypt->hash($plain, true));
                }
                return;
            }
            foreach ($node as &$child) $recryptNode($child);
        };
        $audit = $this->auditStore->rewriteAll(function (array $body) use ($recryptNode) {
            $recryptNode($body['changes']);
            return $body;
        });
        $counts['audit files'] = $audit['files'];
        $counts['audit entries'] = $audit['entries'];
        if ($audit['broken']) {
            $counts['audit files with broken chain before rotation'] = count($audit['broken']);
            $this->system->log('security', 'key rotation: audit chain broken before rotation: ' . implode(', ', $audit['broken']));
        }

        foreach ($counts as $area => $count) $log("re-encrypted $area: $count");
        return $counts;
    }

    /** Re-encrypt a value; not decryptable -> unchanged */
    private function recrypt(string $value, string $aad, bool $binary): string
    {
        if ($value === '') return '';
        $plain = $this->crypt->decrypt($value, $aad, $binary);
        if ($plain === '') return $value;
        return $this->crypt->encrypt($plain, $aad, $binary);
    }

    /**
     * Passwords publicly exposed: all passwords empty, all sessions (admin
     * and user), devices and recovery codes removed.
     */
    private function invalidatePasswords(): array
    {
        $counts = [];
        $this->db->query("UPDATE `admin` SET `password` = '', `password_change` = 0 WHERE `password` <> ''");
        $counts['admin passwords invalidated'] = max(0, $this->db->affected_rows);
        $this->db->query("UPDATE `data_pwd` SET `data` = '' WHERE `data` <> ''");
        $counts['user passwords invalidated'] = max(0, $this->db->affected_rows);
        $this->db->query("DELETE FROM `user_session`");
        $counts['user sessions deleted'] = max(0, $this->db->affected_rows);
        $this->db->query("DELETE FROM `login_device`");
        $counts['devices deleted'] = max(0, $this->db->affected_rows);
        $this->db->query("DELETE FROM `admin_recovery_code`");
        $counts['recovery codes deleted'] = max(0, $this->db->affected_rows);
        $this->db->query("DELETE FROM `user_recovery_code`");
        $counts['user recovery codes deleted'] = max(0, $this->db->affected_rows);
        return $counts;
    }

    // ====================
    // status, retire

    /**
     * Status of the key ring.
     *
     * @return array  current (kid), key_source ('file'|'env'), env_key
     *                (Crypt::envKeyState()), key_file_mode (e.g. '0600', '' without file),
     *                key_file_mode_config (permissions.key_file), retired (list of
     *                kid, retired_at, grace_until, passwords, recovery_codes), not_current
     *                (area => number of values NOT encrypted with the current key),
     *                passwords (kid|'untagged' => count)
     */
    public function status(): array
    {
        $keyFile = $this->keyFile();
        $graceDays = max(0, (int)$this->config->get('security.key_password_grace_days'));
        $passwords = [];
        foreach ($this->passwordRows() as $row) {
            [$kid] = Crypt::splitPasswordHash($row['value']);
            $key = $kid === '' ? 'untagged' : $kid;
            $passwords[$key] = ($passwords[$key] ?? 0) + 1;
        }
        $retired = [];
        foreach ($this->crypt->retiredKeys() as $kid => $retiredAt) {
            $recovery = 0;
            if ($retiredAt !== '') {
                foreach (['admin_recovery_code', 'user_recovery_code'] as $table) {
                    $this->db->query("SELECT COUNT(*) FROM `$table` WHERE `created_at` < ?",
                        's', [gmdate('Y-m-d H:i:s', (int)strtotime($retiredAt))]);
                    $recovery += (int)($this->db->fetchNum()[0] ?? 0);
                }
            }
            $retired[] = [
                'kid' => $kid,
                'retired_at' => $retiredAt,
                'grace_until' => ($retiredAt !== '' && $graceDays > 0) ? gmdate('Y-m-d', (int)strtotime($retiredAt) + $graceDays * 86400) : '',
                'passwords' => $passwords[$kid] ?? 0,
                'recovery_codes' => $recovery,
            ];
        }
        $last = $this->lastRotation();
        return [
            'current' => $this->crypt->currentKeyId(),
            'key_source' => $this->crypt->keySource(),
            'env_key' => $this->crypt->envKeyState(),
            'last_rotation' => $last === null ? '' : gmdate('Y-m-d H:i', $last) . ' UTC',
            'next_rotation' => ($last === null || time() - $last >= self::MIN_INTERVAL) ? '' : gmdate('Y-m-d H:i', $last + self::MIN_INTERVAL) . ' UTC',
            'key_file_mode' => is_file($keyFile) ? sprintf('%04o', fileperms($keyFile) & 0777) : '',
            'key_file_mode_config' => sprintf('%04o', $this->configuredMode()),
            'retired' => $retired,
            'not_current' => $this->countNotCurrent(),
            'passwords' => $passwords,
        ];
    }

    /**
     * Encrypted values that are (still) not encrypted with the current key -
     * after an aborted rotation or for values that can no longer be decrypted
     * with any key.
     */
    private function countNotCurrent(): array
    {
        $counts = ['data_enc' => 0, 'admin' => 0, 'user_second_factor' => 0, 'message' => 0, 'audit' => 0];
        $current = $this->crypt->currentKeyId();
        foreach ($this->auditStore->objects() as [$type, $objectId]) {
            if (array_diff($this->auditStore->verify($type, $objectId)['keys'], [$current])) $counts['audit']++;
        }
        foreach ($this->rows("SELECT `data` FROM `data_enc`") as $row) {
            if (!$this->crypt->decryptsWithCurrent((string)$row['data'], '', true)) $counts['data_enc']++;
        }
        foreach ($this->rows("SELECT `admin_id`, `email`, `mobile`, `phone`, `totp_secret` FROM `admin`") as $row) {
            foreach (['email', 'mobile', 'phone'] as $column) {
                if (!$this->crypt->decryptsWithCurrent((string)$row[$column], '', true)) $counts['admin']++;
            }
            if (!$this->crypt->decryptsWithCurrent((string)$row['totp_secret'], 'totp|' . (int)$row['admin_id'], true)) $counts['admin']++;
        }
        foreach ($this->rows("SELECT `record_id`, `totp_secret` FROM `user_second_factor` WHERE `totp_secret` <> ''") as $row) {
            if (!$this->crypt->decryptsWithCurrent((string)$row['totp_secret'], 'utotp|' . (int)$row['record_id'], true)) $counts['user_second_factor']++;
        }
        foreach ($this->rows("SELECT `body`, `html` FROM `message`") as $row) {
            if (!$this->crypt->decryptsWithCurrent((string)$row['body'], '', true)) $counts['message']++;
            if (!$this->crypt->decryptsWithCurrent((string)$row['html'], '', true)) $counts['message']++;
        }
        return $counts;
    }

    /**
     * Remove the retired key. Refused as long as encrypted data is not readable with the
     * current key (let `key rotate` finish first). Passwords with its identifier
     * (not logged in since the rotation) are only made invalid with $force -
     * admins then need `admin password NAME`, users "forgot password".
     * Recovery codes from before the rotation no longer apply afterwards.
     *
     * @return array  removed (bool), passwords (number invalidated), error ('' = good)
     */
    public function retire(string $kid, bool $force = false): array
    {
        $result = ['removed' => false, 'passwords' => 0, 'error' => ''];
        if (!array_key_exists($kid, $this->crypt->retiredKeys())) {
            $result['error'] = "no retired key $kid";
            return $result;
        }
        if (array_sum($this->countNotCurrent()) > 0) {
            $result['error'] = 'encrypted values still depend on retired keys - run `key rotate` again or check `key status`';
            return $result;
        }
        $affected = array_values(array_filter($this->passwordRows(),
            fn($row) => Crypt::splitPasswordHash($row['value'])[0] === $kid));
        $remaining = count($affected);
        if ($remaining > 0 && !$force) {
            $result['error'] = "$remaining password(s) still use key $kid - they become invalid; repeat with --force";
            return $result;
        }
        if ($remaining > 0) {
            foreach ($affected as $row) $this->setPassword($row, '');
            $this->integrity->signAll();
            $result['passwords'] = $remaining;
        }
        $this->removeRetired($kid);
        $this->crypt->reloadKeys();
        $result['removed'] = true;
        $this->system->log('security', "retired key $kid removed" . ($remaining ? " ($remaining passwords invalidated)" : ''));
        return $result;
    }

    // ====================
    // Dateien

    private function keyFile(): string
    {
        return (string)$this->config->get('paths.key_file');
    }

    /**
     * Rights for key_file and .retired: those of the previous key_file (deliberately set
     * rights are kept, also where 0600 cannot be applied), without a file
     * (key from CAMINDO_KEY) permissions.key_file.
     */
    private function keyFileMode(): int
    {
        $keyFile = $this->keyFile();
        if (is_file($keyFile) && ($perms = @fileperms($keyFile)) !== false) return $perms & 0777;
        return $this->configuredMode();
    }

    private function configuredMode(): int
    {
        return (int)($this->config->get('permissions.key_file') ?: 0600) & 0777;
    }

    /** Time of the last rotation (line "# rotated …" in .retired), null = never */
    public function lastRotation(): ?int
    {
        $file = $this->keyFile() . '.retired';
        if (!is_file($file)) return null;
        $last = null;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (preg_match('/^#\s*rotated\s+(\S+)/', $line, $m) && ($time = strtotime($m[1])) !== false) {
                $last = max($last ?? 0, $time);
            }
        }
        return $last;
    }

    private function appendRetired(string $key): void
    {
        $file = $this->keyFile() . '.retired';
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $line = "# rotated $now\n" . Crypt::keyId($key) . ' ' . trim($key) . ' ' . $now . "\n";
        $content = is_file($file) ? (string)file_get_contents($file) : '';
        $new = !is_file($file);
        $this->writeSecret($file, $content . $line, $this->keyFileMode());
        if ($new && is_file($this->keyFile())) SecretFile::copyOwner($file, $this->keyFile());
        elseif ($new && SecretFile::isRoot() && ($user = SecretFile::guessWebUser(dirname($file))) !== null) {
            SecretFile::chown($file, $user);
        }
    }

    private function removeRetired(string $kid): void
    {
        $file = $this->keyFile() . '.retired';
        if (!is_file($file)) return;
        $lines = array_filter(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
            fn($line) => !str_starts_with(trim($line), $kid . ' '));
        if (!$lines) {
            @unlink($file);
            return;
        }
        $this->writeSecret($file, implode("\n", $lines) . "\n", $this->keyFileMode());
    }

    private function writeSecret(string $file, string $content, int $mode): void
    {
        $old = umask(0077);
        try {
            if (@file_put_contents($file, $content, LOCK_EX) === false) throw new \RuntimeException("cannot write $file");
        }
        finally {
            umask($old);
        }
        @chmod($file, $mode);
    }

    /** All rows of a query (own result stack) */
    private function rows(string $sql): array
    {
        $this->db->pushResult();
        try {
            $this->db->query($sql);
            $rows = [];
            while ($row = $this->db->fetch()) $rows[] = $row;
            return $rows;
        }
        finally {
            $this->db->popResult();
        }
    }
}
