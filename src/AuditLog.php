<?php
/**
 * Audit log: who changed what, and when. Instance name in the code: $auditLog.
 *
 * TWO STORES (since 2026-09-26, developer docs, chapter 13 (Security: authentication))
 * --------------------
 *     table audit_log    metadata for lists and search: time, actor,
 *                        origin, action, object, label and `fields` - the
 *                        changed fields WITHOUT values (key, label,
 *                        language), plus `page` (page of the file)
 *     var/audit/…        the complete entries including before/after, one
 *                        file per object with an HMAC chain (Camindo\Core\AuditStore)
 * write() inserts the meta line within the transaction of the caller and appends
 * the content to the file after the commit (Database::afterCommit()). search() reads only
 * the database; forObject() and load() fetch the contents from the file.
 *
 * WHAT GOES IN
 * --------------------
 * Every change that runs through a dataset - records, areas, fields,
 * templates, admins, groups, instances, languages, status, converters, tasks,
 * components - regardless of source: input mask, frontend (CMS:USER_INPUT),
 * import, batch processing, move, trash cron. Plus the logins
 * to the backend (login, login_failed, logout) and every data access report for a website
 * user (export, RecordPersonalData). Dataset calls it at four points:
 *
 *     save()            create / update  - with before/after of the changed fields
 *     softDeleteOne()   delete           - trash, content is kept
 *     restoreOneCore()  restore
 *     finalDeleteOne()  purge            - for good; the last state is written along
 *
 * A child class switches itself off with $audit = false (messaging: private
 * messages do not belong in the log) and names columns in $auditIgnore that
 * say nothing or are secret (login code, signature, full text …).
 *
 * BEFORE / AFTER
 * --------------------
 * Snapshots are compared (Dataset::auditSnapshot()): a flat list
 * key => ['l' => label (raw, '#snippet' possible), 't' => field type,
 * 'g' => language code or '', 'v' => value as text, 'c' => comparison value (optional)].
 * A record delivers ALL fields in all languages including meta fields
 * and links (Record::auditSnapshot()). diff() takes only what differs;
 * a save without change creates no entry.
 *
 * SECRETS STAY SECRET
 * --------------------
 *     password    never a value, only "changed" (comparison via a hash of the hash)
 *     encrypted   the ciphertext (base64) is stored, compared via an HMAC of the plain text;
 *                 decrypted only when displayed
 *
 * RETENTION (config audit.*)
 * --------------------
 *     retention_days_changes  entries for objects that still exist, and
 *                             logins. 0 = unlimited: a record remains traceable
 *                             back to its origin.
 *     log_logins              log successful logins and logouts to the backend
 *                             (default false - they reveal working
 *                             hours, but nothing about changes);
 *                             failed attempts are always included
 *     retention_days_logins   logins/logouts and failed attempts, in days (default 90,
 *                             0 = as retention_days_changes)
 *     retention_days_deleted  entries for objects deleted for good, counted from
 *                             the deletion (entry 'purge'). Afterwards their
 *                             entire history disappears. Not for records: their
 *                             history is governed by the deletion policy of the area (module
 *                             Privacy - deleteObject(), overwriteObject()).
 * purge() runs from the scheduler task "apply retention periods" (retention).
 *
 * IP ADDRESSES
 * --------------------
 * The column `ip` holds the full address until IpAnonymizer truncates it after
 * privacy.ip_anonymize_days (default 7 days). The file under var/audit
 * receives it truncated from the start - only appended to the HMAC chain.
 *
 * No foreign keys: an entry outlives the admin, record and area it tells about.
 *
 * ACTORS ONLY AS ID
 * --------------------
 * Who acted is stored as admin_id resp. user_id - without a name, neither in
 * `audit_log.actor_name` nor in the file. A user's name is only added by the
 * display (AuditDiff::actorLabel(), from `admin` - also for deleted ones). So renaming
 * the row of a user is enough to anonymize them (module Privacy,
 * AdminAnonymizer); the files of the objects they worked on remain
 * untouched. Only a failed attempt with a name that does not exist carries a name
 * (login(): for an existing account the name is stored under its admin_id). Older entries
 * with names are cleaned up by `camindo.php audit strip-names` (stripActorNames()).
 *
 * @see Camindo\Core\Dataset::auditSnapshot()
 * @see Camindo\Core\AuditDiff                       grafischer Vergleich
 * @see Camindo\Core\Modules\Audit\AuditBackend      Recherche (Superuser)
 * @see Camindo\Core\Modules\Record\RecordBackendInfo  tab "Changes"
 */
namespace Camindo\Core;

class AuditLog {

    public const ACTIONS = ['create', 'update', 'delete', 'restore', 'purge', 'login', 'login_failed', 'logout', 'export', 'privacy'];

    /** Result of the check (scheduler task auditverify) under var/cache */
    public const VERIFY_FILE = 'audit-verify.json';

    /** Upper limit of a single value (characters) - longer values are truncated */
    public const MAX_VALUE_LENGTH = 200000;

    /** Marker at the end of a truncated value */
    public const TRUNCATED = ' […]';

    /** Logins and logouts belong to the admin */
    private const LOGIN_ACTIONS = ['login', 'login_failed', 'logout'];

    /** Origin that the caller sets for an operation (setContext()) */
    private string $context = '';

    /** admin_id => name, per request */
    private array $adminNames = [];

    public function __construct(
        private Config $config,
        private Database $db,
        private Session $session,
        private Crypt $crypt,
        private ContainerInterface $container,
        private AuditStore $store,
    )
    {}

    /**
     * Audit log switched on (audit.enabled, default true)?
     */
    public function enabled(): bool
    {
        return $this->config->get('audit.enabled') !== false;
    }

    /**
     * Set the origin of the following entries - 'import', 'batch', 'trash' …
     * (empty = from the runtime environment: backend, frontend, cli).
     */
    public function setContext(string $context): void
    {
        $this->context = preg_replace('/[^a-z0-9_-]/', '', strtolower($context)) ?? '';
    }

    /** The origin that was set ('' = from the runtime environment) */
    public function context(): string
    {
        return $this->context;
    }

    // ==== Schreiben ====

    /**
     * Write an entry. Runs in the transaction of the caller - a rollback
     * takes the entry along.
     *
     * @param string   $action      create|update|delete|restore|purge|login|login_failed|logout|export
     * @param string   $objectType  table name ('record', 'area', 'admin' …)
     * @param int      $objectId    id of the object
     * @param string   $label       label of the object at the time of the change
     * @param array    $changes     result of diff() resp. fullChanges()
     * @param int|null $areaId      area (for records)
     * @param int|null $instanceId  instance (where known)
     * @param string   $actorName   only for login_failed: the entered name
     */
    public function write(string $action, string $objectType, int $objectId, string $label = '',
        array $changes = [], ?int $areaId = null, ?int $instanceId = null, string $actorName = ''): void
    {
        if (!$this->enabled() || !in_array($action, self::ACTIONS, true)) return;
        $actor = $this->actor();
        if ($actorName !== '') $actor['name'] = $actorName;

        $changes = array_values($changes);
        $label = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($label)) ?? ''), 0, 255);
        // Record whose audit log a deletion policy has already overwritten: every later
        // entry as well (e.g. 'purge' with the last state) is written this way
        if ($objectType === 'record' && ($override = $this->policyOverride($objectId)) !== null) {
            $entry = self::applyOverride(['label' => $label, 'actor' => $actor, 'changes' => $changes], ...$override);
            [$label, $actor, $changes] = [$entry['label'], $entry['actor'], $entry['changes']];
        }
        $actor['name'] = mb_substr($actor['name'], 0, 128);
        // -1 = all instances (e.g. a deletion policy) - the column is unsigned
        if ($instanceId !== null && $instanceId < 0) $instanceId = 0;
        $time = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
        $this->db->pushResult();
        try {
            $this->db->query(
                "INSERT INTO `audit_log` (`created_at`, `actor_type`, `admin_id`, `user_id`, `actor_name`, `ip`,"
                . " `origin`, `action`, `object_type`, `object_id`, `area_id`, `instance_id`, `label`, `fields`, `page`)"
                . " VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)",
                'ssiisssssiiiss',
                [
                    $time, $actor['type'], $actor['admin_id'], $actor['user_id'],
                    $actor['name'], $actor['ip'], $actor['origin'],
                    $action, $objectType, $objectId, $areaId, $instanceId,
                    $label, $this->fieldsJson($changes),
                ]
            );
            $auditId = (int)$this->db->insert_id;
        }
        finally {
            $this->db->popResult();
        }

        // Content goes into the file only after the commit - a rollback takes the meta line
        // along, otherwise the file would keep an entry without counterpart
        // Only the truncated IP goes into the file: only appended to the HMAC chain,
        // it could not be truncated there later (IpAnonymizer)
        $fileActor = ['ip' => ClientIp::anonymize((string)$actor['ip'], ClientIp::v4Octets($this->config))] + $actor;
        if ((string)($fileActor['name'] ?? '') === '') unset($fileActor['name']);
        $entry = [
            'id' => $auditId, 't' => $time, 'action' => $action, 'type' => $objectType, 'oid' => $objectId,
            'actor' => $fileActor, 'label' => $label, 'area' => $areaId, 'instance' => $instanceId,
            'changes' => $changes,
        ];
        $this->db->afterCommit(function () use ($entry) {
            try {
                $page = $this->store->append($entry);
                $this->db->pushResult();
                try {
                    $this->db->query("UPDATE `audit_log` SET `page` = ? WHERE `audit_id` = ?", 'ii', [$page, $entry['id']]);
                }
                finally {
                    $this->db->popResult();
                }
            }
            catch (\Throwable $e) {
                // The operation itself is saved - the missing content shows up in
                // `camindo.php audit verify` (page = 0)
                $this->container->get(System::class)->log('security', "audit file write failed for #{$entry['id']}: " . $e->getMessage());
            }
        });
    }

    /**
     * The changed fields without values for the meta line (lists, filter "field").
     */
    private function fieldsJson(array $changes): string
    {
        $fields = [];
        foreach ($changes as $change) {
            $fields[] = ['k' => (string)($change['k'] ?? ''), 'l' => (string)($change['l'] ?? ''), 'g' => (string)($change['g'] ?? '')];
        }
        return $fields ? (string)json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : '';
    }

    /**
     * Log a backend login (StartBackend).
     *
     * A failed attempt with the name of an existing account appears in the history of this
     * account (object_id = admin_id) and without the entered name - which follows
     * from the id. Only a name that does not exist is recorded (object 0).
     *
     * @param string $action  login|login_failed|logout
     * @param int    $adminId 0 for login_failed
     * @param string $name    login_failed: the entered name; otherwise empty = from `admin`
     * @param string $origin  interface (Access::INTERFACES), where the context does not
     *                        already name it - e.g. a failed login via the API
     */
    public function login(string $action, int $adminId, string $name = '', string $origin = ''): void
    {
        if (!in_array($action, self::LOGIN_ACTIONS, true)) return;
        // Successful logins and logouts without activity say nothing about changes,
        // but they do say something about working hours - only on explicit request (audit.log_logins)
        if ($action !== 'login_failed' && !$this->logLogins()) return;
        $actorName = '';
        if ($action === 'login_failed' && $adminId < 1 && $name !== '') {
            $adminId = $this->adminIdByName($name);
            if ($adminId < 1) $actorName = $name;
        }
        if ($adminId > 0) $name = $this->adminName($adminId);
        $context = $this->context;
        if ($origin !== '') $this->context = $origin;
        try {
            $this->write($action, 'admin', $adminId, $name, [], null, null, $actorName);
        }
        finally {
            $this->context = $context;
        }
    }

    /** admin_id for a user name (collation of the column), 0 = none */
    private function adminIdByName(string $name): int
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `admin_id` FROM `admin` WHERE `name` = ?", 's', [$name]);
            return (int)($this->db->fetchNum()[0] ?? 0);
        }
        finally {
            $this->db->popResult();
        }
    }

    /** Log successful logins and logouts? (audit.log_logins, default false) */
    public function logLogins(): bool
    {
        return (bool)($this->config->get('audit.log_logins') ?? false);
    }

    /** Days after which logins and logouts are deleted (audit.retention_days_logins, 0 = as changes) */
    public function loginRetentionDays(): int
    {
        return max(0, (int)($this->config->get('audit.retention_days_logins') ?? 90));
    }

    // ==== Vergleich ====

    /**
     * The differences of two snapshots.
     *
     * @param array $before  snapshot before ([] for create)
     * @param array $after   snapshot after ([] for purge)
     * @return array         list of ['k' => key, 'l' => label, 't' => type,
     *                       'g' => language code, 'b' => before, 'a' => after]
     */
    public function diff(array $before, array $after): array
    {
        $changes = [];
        foreach (array_keys($before + $after) as $key) {
            $b = $before[$key] ?? null;
            $a = $after[$key] ?? null;
            $bCmp = $b === null ? '' : (string)($b['c'] ?? $b['v'] ?? '');
            $aCmp = $a === null ? '' : (string)($a['c'] ?? $a['v'] ?? '');
            if ($bCmp === $aCmp) continue;
            $ref = $a ?? $b;
            $type = (string)($ref['t'] ?? 'text');
            $changes[] = [
                'k' => (string)$key,
                'l' => (string)($ref['l'] ?? $key),
                't' => $type,
                'g' => (string)($ref['g'] ?? ''),
                // Passwords: only the fact of the change
                'b' => $type === 'password' ? ($bCmp === '' ? '' : '***') : $this->limit((string)($b['v'] ?? '')),
                'a' => $type === 'password' ? ($aCmp === '' ? '' : '***') : $this->limit((string)($a['v'] ?? '')),
            ];
        }
        return $changes;
    }

    // ==== Lesen ====

    /**
     * Entries for an object with complete changes, newest first.
     */
    public function forObject(string $objectType, int $objectId, int $limit = 200): array
    {
        $rows = $this->search(['object_type' => $objectType, 'object_id' => $objectId], $limit, 0);
        $entries = $rows ? $this->store->entries($objectType, $objectId) : [];
        foreach ($rows as &$row) {
            $row['changes'] = (array)($entries[(int)$row['audit_id']]['changes'] ?? []);
            $row['content_missing'] = !isset($entries[(int)$row['audit_id']]);
        }
        return $rows;
    }

    /**
     * One page of the history of an object including before/after - reads only the pages
     * of the file on which the entries stand (for paging queries such as
     * audit.history).
     *
     * @return array  rows like forObject()
     */
    public function historyPage(string $objectType, int $objectId, int $limit, int $offset, bool $oldestFirst): array
    {
        $rows = $this->search(['object_type' => $objectType, 'object_id' => $objectId], $limit, $offset, $oldestFirst);
        $pages = array_unique(array_map(fn($row) => (int)$row['page'], $rows));
        $entries = [];
        if (in_array(0, $pages, true)) {
            $entries = $rows ? $this->store->entries($objectType, $objectId) : [];
        }
        else {
            foreach ($pages as $page) $entries += $this->store->entries($objectType, $objectId, $page);
        }
        foreach ($rows as &$row) {
            $row['changes'] = (array)($entries[(int)$row['audit_id']]['changes'] ?? []);
            $row['content_missing'] = !isset($entries[(int)$row['audit_id']]);
        }
        return $rows;
    }

    /**
     * Check the file of an object (AuditStore::verify()) and reconcile it with the
     * database: are contents missing for meta lines or meta lines for contents, and do
     * the meta columns match the signed entry of the file (metaMismatch())?
     * Lists and search show the meta columns - if changed via SQL, this would
     * otherwise go unnoticed.
     *
     * @return array  ok, errors (texts), lines
     */
    public function verifyObject(string $objectType, int $objectId): array
    {
        $check = $this->store->verify($objectType, $objectId);
        $errors = array_map(fn($e) => "page {$e['page']} line {$e['line']}: {$e['reason']}", $check['errors']);
        $rows = [];
        $this->db->pushResult();
        try {
            $this->db->query("SELECT * FROM `audit_log` WHERE `object_type` = ? AND `object_id` = ?",
                'si', [$objectType, $objectId]);
            while ($row = $this->db->fetch()) $rows[(int)$row['audit_id']] = $row;
        }
        finally {
            $this->db->popResult();
        }
        $dbIds = array_keys($rows);
        $missingContent = array_diff($dbIds, $check['ids']);
        $missingMeta = array_diff($check['ids'], $dbIds);
        if ($missingContent) $errors[] = 'content missing for #' . implode(', #', $missingContent);
        if ($missingMeta) $errors[] = 'database entries missing for #' . implode(', #', $missingMeta);
        foreach ($rows as $auditId => $row) {
            if (!isset($check['entries'][$auditId])) continue;
            $columns = $this->metaMismatch($row, $check['entries'][$auditId]);
            if ($columns) $errors[] = "database entry #$auditId differs from file: " . implode(', ', $columns);
        }
        return ['ok' => !$errors, 'errors' => $errors, 'lines' => $check['lines']];
    }

    /**
     * Meta columns of a row from `audit_log` that do not match the entry of the file.
     * Compared is what write() writes to both stores, in the same truncation;
     * the IP only truncated to two octets resp. 48 bits - in the file it stands truncated
     * from the start, in the column only after privacy.ip_anonymize_days, and
     * privacy.ip_v4_octets may have changed in between.
     *
     * @return array  names of the differing columns
     */
    private function metaMismatch(array $row, array $entry): array
    {
        $actor = (array)($entry['actor'] ?? []);
        $int = fn($v) => $v === null || $v === '' ? null : (int)$v;
        $expected = [
            'created_at'  => (string)($entry['t'] ?? ''),
            'action'      => (string)($entry['action'] ?? ''),
            'actor_type'  => (string)($actor['type'] ?? ''),
            'admin_id'    => $int($actor['admin_id'] ?? null),
            'user_id'     => $int($actor['user_id'] ?? null),
            'actor_name'  => mb_substr((string)($actor['name'] ?? ''), 0, 128),
            'origin'      => (string)($actor['origin'] ?? ''),
            'area_id'     => $int($entry['area'] ?? null),
            // -1 (all instances) is stored as 0 in the column, see write()
            'instance_id' => ($instance = $int($entry['instance'] ?? null)) !== null ? max(0, $instance) : null,
            'label'       => mb_substr((string)($entry['label'] ?? ''), 0, 255),
            'fields'      => $this->fieldsJson((array)($entry['changes'] ?? [])),
            'ip'          => ClientIp::anonymize((string)($actor['ip'] ?? ''), 2),
        ];
        $actual = [
            'created_at'  => (string)$row['created_at'],
            'action'      => (string)$row['action'],
            'actor_type'  => (string)$row['actor_type'],
            'admin_id'    => $int($row['admin_id']),
            'user_id'     => $int($row['user_id']),
            'actor_name'  => (string)$row['actor_name'],
            'origin'      => (string)$row['origin'],
            'area_id'     => $int($row['area_id']),
            'instance_id' => $int($row['instance_id']),
            'label'       => (string)$row['label'],
            'fields'      => (string)$row['fields'],
            'ip'          => ClientIp::anonymize((string)$row['ip'], 2),
        ];
        return array_keys(array_filter($expected, fn($value, $column) => $value !== $actual[$column], ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Check all files and meta lines (`camindo.php audit verify`).
     *
     * @return array  checked (objects), failed ("type:id" => error texts)
     */
    public function verifyAll(): array
    {
        $objects = [];
        foreach ($this->store->objects() as [$type, $id]) $objects["$type:$id"] = [$type, $id];
        $this->db->pushResult();
        try {
            $this->db->query("SELECT DISTINCT `object_type`, `object_id` FROM `audit_log`");
            while ($row = $this->db->fetch()) {
                $objects[$row['object_type'] . ':' . $row['object_id']] = [(string)$row['object_type'], (int)$row['object_id']];
            }
        }
        finally {
            $this->db->popResult();
        }
        $failed = [];
        foreach ($objects as $key => [$type, $id]) {
            $check = $this->verifyObject($type, $id);
            if (!$check['ok']) $failed[$key] = $check['errors'];
        }
        return ['checked' => count($objects), 'failed' => $failed];
    }

    /**
     * Record the result of a full check (var/cache/audit-verify.json) - from this
     * the start page shows the operational notice (SystemHealth, check 'audit').
     * Written by the scheduler task "check audit log" (auditverify) and by
     * `camindo.php audit verify|restore`.
     *
     * @param array $result  verifyAll()
     */
    public function storeVerifyResult(array $result): void
    {
        $file = rtrim((string)$this->config->get('paths.var_dir'), '/') . '/cache/' . self::VERIFY_FILE;
        @file_put_contents($file, json_encode(['checked_at' => gmdate('Y-m-d H:i:s')] + $result,
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * Update the recorded finding of ONE object (after reseal or a single
     * check) - without checking all objects again. Without a stored
     * full check it stays that way.
     *
     * @param array $check  verifyObject()
     */
    public function updateVerifyResult(string $objectType, int $objectId, array $check): void
    {
        $file = rtrim((string)$this->config->get('paths.var_dir'), '/') . '/cache/' . self::VERIFY_FILE;
        if (!is_file($file)) return;
        $result = json_decode((string)file_get_contents($file), true);
        if (!is_array($result)) return;
        $key = "{$objectType}:{$objectId}";
        $failed = (array)($result['failed'] ?? []);
        if ($check['ok']) unset($failed[$key]);
        else $failed[$key] = $check['errors'];
        $result['failed'] = $failed;
        @file_put_contents($file, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * Reset the files to the state of the database - after restoring a
     * database backup (`camindo.php audit restore`).
     *
     * The files under var/audit are not part of the dump: after the restore they
     * contain entries whose meta lines no longer exist, and the database
     * assigns their audit_ids anew. Every entry with an audit_id above the highest
     * in the database is removed (AuditStore::cutAfter()); the affected files
     * are beforehand copied unchanged to var/audit-restore-{time}/. Must run before
     * new entries are created.
     *
     * @param bool $dryRun  only count
     * @return array        max_id, objects (affected objects), removed (entries),
     *                      archive (directory of the copies, '' without change)
     */
    public function restoreToDatabase(bool $dryRun = false): array
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT MAX(`audit_id`) FROM `audit_log`");
            $maxId = (int)($this->db->fetchNum()[0] ?? 0);
        }
        finally {
            $this->db->popResult();
        }
        $archive = rtrim((string)$this->config->get('paths.var_dir'), '/') . '/audit-restore-' . gmdate('Ymd-His');
        $result = ['max_id' => $maxId, 'objects' => 0, 'removed' => 0, 'archive' => ''];
        foreach ($this->store->objects() as [$type, $objectId]) {
            if ($dryRun) {
                $count = count(array_filter(array_keys($this->store->entries($type, $objectId)), fn($id) => $id > $maxId));
            }
            else {
                $count = $this->store->cutAfter($type, $objectId, $maxId, $archive);
            }
            if ($count > 0) {
                $result['objects']++;
                $result['removed'] += $count;
            }
        }
        if (!$dryRun && $result['removed'] > 0) $result['archive'] = $archive;
        return $result;
    }

    /**
     * Re-chain a broken chain of an object (`camindo.php audit reseal`,
     * AuditStore::reseal()). The pages are beforehand copied unchanged to
     * var/audit-reseal-{time}/; the operation is recorded in the log 'security'.
     *
     * @return array  as AuditStore::reseal(), plus archive ('' without change) and
     *                check (verifyObject() afterwards)
     */
    public function resealObject(string $objectType, int $objectId, string $reason = '', bool $force = false, bool $dryRun = false): array
    {
        $archive = rtrim((string)$this->config->get('paths.var_dir'), '/') . '/audit-reseal-' . gmdate('Ymd-His');
        $result = $this->store->reseal($objectType, $objectId, $reason, $force, $dryRun, $dryRun ? null : $archive);
        $result['archive'] = $result['status'] === 'resealed' ? $archive : '';
        if ($result['status'] === 'resealed') {
            $this->container->get(System::class)->log('security', "audit reseal {$objectType} {$objectId}: "
                . "{$result['relinked']} entries relinked, {$result['unverified']} unverified"
                . ($reason !== '' ? ", reason: {$reason}" : ''));
        }
        $result['check'] = $this->verifyObject($objectType, $objectId);
        if (!$dryRun) $this->updateVerifyResult($objectType, $objectId, $result['check']);
        return $result;
    }

    /**
     * Truncate IP addresses in the files that were written with the full address
     * before the update (`camindo.php audit anonymize-ip`, AuditStore::anonymizeIps()).
     * Recorded in the log 'security'.
     *
     * @return array  files, entries, skipped (objects with a broken chain)
     */
    public function anonymizeFileIps(bool $dryRun = false): array
    {
        $octets = ClientIp::v4Octets($this->config);
        $result = $this->store->anonymizeIps(fn(string $ip) => ClientIp::anonymize($ip, $octets), $dryRun);
        if (!$dryRun && $result['files'] > 0) {
            $this->container->get(System::class)->log('security', "audit anonymize-ip: {$result['entries']} IP addresses"
                . " in {$result['files']} files shortened" . ($result['skipped'] ? ', skipped (chain broken): ' . implode(', ', $result['skipped']) : ''));
        }
        return $result;
    }

    /**
     * Search.
     *
     * @param array $filter  from, to (Y-m-d, UTC), admin_id, action, object_type,
     *                       object_id, area_id, origin, search (label/name, substring),
     *                       field (field key or label in the changes);
     *                       restrictions: object_types, area_ids (lists - empty = nothing)
     * @param bool  $oldestFirst  oldest entries first (otherwise newest)
     * @return array         rows, `changes` as array
     */
    public function search(array $filter, int $limit = 100, int $offset = 0, bool $oldestFirst = false): array
    {
        [$where, $types, $values] = $this->where($filter);
        $sql = "SELECT * FROM `audit_log`" . $where
            . " ORDER BY `audit_id` " . ($oldestFirst ? 'ASC' : 'DESC') . " LIMIT " . max(1, $limit) . " OFFSET " . max(0, $offset);
        $this->db->pushResult();
        try {
            $this->db->query($sql, $types, $values);
            $rows = [];
            while ($row = $this->db->fetch()) {
                // Only the fields without values - the values are in the file (forObject(), load())
                $row['changes'] = json_decode((string)$row['fields'], true) ?: [];
                $rows[] = $row;
            }
        }
        finally {
            $this->db->popResult();
        }
        return $rows;
    }

    /**
     * Objects that an admin last changed - one row per object, the most recently
     * changed first (tab "Activities" of the admin info).
     *
     * @param int    $adminId
     * @param string $objectType  e.g. 'record'
     * @return array  ['object_id', 'area_id', 'label', 'action', 'created_at' (UTC),
     *                 'changes' (number of its entries for the object)] per object
     */
    public function recentObjects(int $adminId, string $objectType, int $limit = 20): array
    {
        $this->db->pushResult();
        try {
            // last entry per object via idx_admin (admin_id, audit_id)
            $this->db->query(
                "SELECT `a`.`object_id`, `a`.`area_id`, `a`.`label`, `a`.`action`, `a`.`created_at`, `m`.`changes`"
                . " FROM (SELECT `object_id`, MAX(`audit_id`) AS `last_id`, COUNT(*) AS `changes` FROM `audit_log`"
                . "       WHERE `admin_id` = ? AND `object_type` = ? GROUP BY `object_id`"
                . "       ORDER BY `last_id` DESC LIMIT " . max(1, $limit) . ") `m`"
                . " INNER JOIN `audit_log` `a` ON `a`.`audit_id` = `m`.`last_id`"
                . " ORDER BY `a`.`audit_id` DESC",
                'is', [$adminId, $objectType]
            );
            $rows = [];
            while ($row = $this->db->fetch()) $rows[] = $row;
            return $rows;
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Number of hits of a search.
     */
    public function count(array $filter): int
    {
        [$where, $types, $values] = $this->where($filter);
        $this->db->pushResult();
        try {
            $this->db->query("SELECT COUNT(*) FROM `audit_log`" . $where, $types, $values);
            return (int)($this->db->fetchNum()[0] ?? 0);
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * One entry.
     */
    public function load(int $auditId): ?array
    {
        $rows = $this->search(['audit_id' => $auditId], 1, 0);
        $row = $rows[0] ?? null;
        if ($row === null) return null;
        $page = (int)$row['page'];
        $entries = $this->store->entries((string)$row['object_type'], (int)$row['object_id'], $page > 0 ? $page : null);
        $row['changes'] = (array)($entries[$auditId]['changes'] ?? []);
        $row['content_missing'] = !isset($entries[$auditId]);
        return $row;
    }

    /**
     * Object types that occur (for the selection list of the search).
     *
     * @return string[]
     */
    public function objectTypes(): array
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT DISTINCT `object_type` FROM `audit_log` ORDER BY `object_type`");
            return array_map('strval', $this->db->fetchColumn());
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Admins that occur in the log: admin_id => name (for the selection list).
     */
    public function actors(): array
    {
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT `l`.`admin_id`, COALESCE(MAX(`a`.`name`), CONCAT('#', `l`.`admin_id`)) FROM `audit_log` `l`"
                . " LEFT JOIN `admin` `a` ON `a`.`admin_id` = `l`.`admin_id`"
                . " WHERE `l`.`admin_id` IS NOT NULL AND `l`.`actor_type` = 'admin' GROUP BY `l`.`admin_id` ORDER BY 2"
            );
            $list = [];
            foreach ($this->db->fetchPairs() as $id => $name) $list[(int)$id] = (string)$name;
            return $list;
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Remove the names of the actors from older entries (`camindo.php audit
     * strip-names`): users and website users are only kept as ids now (see
     * class comment). Files with checkpoint 'strip-names', plus
     * `audit_log.actor_name`. Repeatable - a second run finds nothing more.
     *
     * @return array  ['entries' => changed entries in files, 'files' => files,
     *                 'rows' => changed meta lines]
     */
    public function stripActorNames(): array
    {
        $entries = 0;
        $files = 0;
        foreach ($this->store->objects() as [$type, $id]) {
            $changed = $this->store->rewriteObject($type, (int)$id, function (array $body): array {
                if (in_array((string)($body['actor']['type'] ?? ''), ['admin', 'user'], true)) unset($body['actor']['name']);
                return $body;
            }, 'strip-names');
            $entries += $changed;
            if ($changed > 0) $files++;
        }
        $this->db->pushResult();
        try {
            $this->db->query("UPDATE `audit_log` SET `actor_name` = '' WHERE `actor_type` IN ('admin', 'user') AND `actor_name` <> ''");
            $rows = max(0, (int)$this->db->affected_rows);
        }
        finally {
            $this->db->popResult();
        }
        return ['entries' => $entries, 'files' => $files, 'rows' => $rows];
    }

    // ==== Takeover from the database ====

    /**
     * Take over entries from the former column audit_log.changes into the files
     * (`camindo.php audit migrate`) - per object beginning with checkpoint 'migrate',
     * in the order of audit_id. The column is removed afterwards.
     *
     * @return int  entries taken over (0: nothing to do)
     */
    public function migrateToFiles(): int
    {
        $this->db->query("SHOW COLUMNS FROM `audit_log` LIKE 'changes'");
        if ($this->db->num_rows < 1) return 0;
        $this->db->query("SELECT * FROM `audit_log` ORDER BY `audit_id`");
        $rows = [];
        while ($row = $this->db->fetch()) $rows[] = $row;
        $count = 0;
        $seen = [];
        foreach ($rows as $row) {
            $type = (string)$row['object_type'];
            $objectId = (int)$row['object_id'];
            if (!isset($seen["$type:$objectId"])) {
                $this->store->appendCheckpoint($type, $objectId, 'migrate');
                $seen["$type:$objectId"] = true;
            }
            $changes = json_decode((string)$row['changes'], true) ?: [];
            $page = $this->store->append([
                'id' => (int)$row['audit_id'], 't' => (string)$row['created_at'], 'action' => (string)$row['action'],
                'type' => $type, 'oid' => $objectId,
                'actor' => ['type' => (string)$row['actor_type'], 'admin_id' => $row['admin_id'] === null ? null : (int)$row['admin_id'],
                    'user_id' => $row['user_id'] === null ? null : (int)$row['user_id'], 'name' => (string)$row['actor_name'],
                    'ip' => ClientIp::anonymize((string)$row['ip'], ClientIp::v4Octets($this->config)), 'origin' => (string)$row['origin']],
                'label' => (string)$row['label'],
                'area' => $row['area_id'] === null ? null : (int)$row['area_id'],
                'instance' => $row['instance_id'] === null ? null : (int)$row['instance_id'],
                'changes' => array_values($changes),
            ]);
            $this->db->query("UPDATE `audit_log` SET `fields` = ?, `page` = ? WHERE `audit_id` = ?",
                'sii', [$this->fieldsJson($changes), $page, (int)$row['audit_id']]);
            $count++;
        }
        $this->db->query("ALTER TABLE `audit_log` DROP COLUMN `changes`");
        return $count;
    }

    // ==== Deletion policies (module Privacy) ====

    /**
     * Delete the entire history of an object - database and file (action
     * audit_delete resp. purge of a deletion policy).
     *
     * @return int  removed entries of the database
     */
    public function deleteObject(string $objectType, int $objectId): int
    {
        $this->db->pushResult();
        try {
            $this->db->query("DELETE FROM `audit_log` WHERE `object_type` = ? AND `object_id` = ?", 'si', [$objectType, $objectId]);
            $count = max(0, (int)$this->db->affected_rows);
        }
        finally {
            $this->db->popResult();
        }
        $this->store->delete($objectType, $objectId);
        return $count;
    }

    /**
     * Delete the history of several objects by hand (`camindo.php audit delete`) - like
     * deleteObject(), plus the recorded check finding and an entry in the log
     * 'security' (only type, count and $scope, no contents).
     *
     * @param int[]  $objectIds
     * @param string $scope   what for, for the log, e.g. "area 141"
     * @return array          objects (objects with history), entries (rows of the
     *                        database), files (pages under var/audit)
     */
    public function deleteObjects(string $objectType, array $objectIds, bool $dryRun = false, string $scope = ''): array
    {
        $result = ['objects' => 0, 'entries' => 0, 'files' => 0];
        foreach (array_unique(array_map('intval', $objectIds)) as $objectId) {
            $files = count($this->store->pages($objectType, $objectId));
            $this->db->pushResult();
            try {
                $this->db->query("SELECT COUNT(*) FROM `audit_log` WHERE `object_type` = ? AND `object_id` = ?", 'si', [$objectType, $objectId]);
                $entries = (int)($this->db->fetchNum()[0] ?? 0);
            }
            finally {
                $this->db->popResult();
            }
            if ($files === 0 && $entries === 0) continue;
            $result['objects']++;
            $result['files'] += $files;
            if ($dryRun) {
                $result['entries'] += $entries;
                continue;
            }
            $result['entries'] += $this->deleteObject($objectType, $objectId);
            $this->updateVerifyResult($objectType, $objectId, ['ok' => true, 'errors' => []]);
        }
        if (!$dryRun && $result['objects'] > 0) {
            $this->container->get(System::class)->log('security', "audit delete {$objectType}"
                . ($scope !== '' ? " ({$scope})" : '') . ": {$result['objects']} objects, {$result['entries']} entries, {$result['files']} files");
        }
        return $result;
    }

    /**
     * Records whose history belongs to an area: those in the area
     * (also in the trash) or, according to the audit log, were there - also those deleted for good.
     *
     * @return int[]
     */
    public function recordIdsOfArea(int $areaId): array
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT DISTINCT `object_id` FROM `audit_log` WHERE `object_type` = 'record' AND `area_id` = ?"
                . " UNION SELECT `record_id` FROM `record` WHERE `area_id` = ? AND `tmp` = 0", 'ii', [$areaId, $areaId]);
            $ids = array_map('intval', $this->db->fetchColumn());
        }
        finally {
            $this->db->popResult();
        }
        sort($ids);
        return $ids;
    }

    /**
     * Overwrite fields in the history of an object (action audit_set_fields of a
     * deletion policy) - file (AuditStore::rewriteObject(), new checkpoint) and
     * label in the database.
     *
     * @param array $fields    field => value, plus _label, * (DeletePolicy)
     * @param array $vars      placeholders: record_id, user_id
     * @return int  changed entries of the file
     */
    public function overwriteObject(string $objectType, int $objectId, array $fields, array $vars): int
    {
        $changed = $this->store->rewriteObject($objectType, $objectId,
            fn(array $body) => self::applyOverride($body, $fields, $vars));
        if (array_key_exists('_label', $fields)) {
            $this->db->pushResult();
            try {
                $this->db->query("UPDATE `audit_log` SET `label` = ? WHERE `object_type` = ? AND `object_id` = ?",
                    'ssi', [mb_substr(self::renderValue((string)$fields['_label'], $vars), 0, 255), $objectType, $objectId]);
            }
            finally {
                $this->db->popResult();
            }
        }
        return $changed;
    }

    /**
     * Send every entry in the history of an object through a function (action
     * audit_trigger of a deletion policy: onPrivacyAction() of the trigger template) and
     * write back what changed - file (AuditStore::rewriteObject(), new
     * checkpoint) and label/name in the database (audit_log.label,
     * actor_name per entry).
     *
     * @param callable $transform  function(array $entry): array - entry as in the
     *                             file: id, t, action, actor {type, name, user_id …},
     *                             label, changes [{k, l, t, g, b, a}, …]
     * @return int  changed entries
     */
    public function transformObject(string $objectType, int $objectId, callable $transform): int
    {
        $rows = [];
        $changed = $this->store->rewriteObject($objectType, $objectId, function (array $body) use ($transform, &$rows): array {
            $new = $transform($body);
            if (!is_array($new)) return $body;
            // Identifier, time and object stay - otherwise the entry would be a different one
            foreach (['id', 't', 'type', 'oid'] as $key) {
                if (array_key_exists($key, $body)) $new[$key] = $body[$key];
            }
            if ((int)($body['id'] ?? 0) > 0 && (($new['label'] ?? null) !== ($body['label'] ?? null)
                || ($new['actor']['name'] ?? null) !== ($body['actor']['name'] ?? null))) {
                $rows[(int)$body['id']] = [(string)($new['label'] ?? ''), (string)($new['actor']['name'] ?? '')];
            }
            return $new;
        });
        if ($rows) {
            $this->db->pushResult();
            try {
                foreach ($rows as $auditId => [$label, $actorName]) {
                    $this->db->query("UPDATE `audit_log` SET `label` = ?, `actor_name` = ? WHERE `audit_id` = ? AND `object_type` = ? AND `object_id` = ?",
                        'ssisi', [mb_substr($label, 0, 255), mb_substr($actorName, 0, 128), $auditId, $objectType, $objectId]);
                }
            }
            finally {
                $this->db->popResult();
            }
        }
        return $changed;
    }

    /**
     * Rewrite an entry (label, changes) according to the fields of a deletion policy:
     * field => value replaces before and after of this field, * all others, _label
     * the label. Empty values stay empty (reveal nothing that was not there).
     * Whoever acted is stored only as an id in the entry and stays.
     */
    public static function applyOverride(array $entry, array $fields, array $vars): array
    {
        $render = fn(string $v) => self::renderValue($v, $vars);
        if (array_key_exists('_label', $fields)) $entry['label'] = $render((string)$fields['_label']);
        foreach ((array)($entry['changes'] ?? []) as $i => $change) {
            $key = (string)($change['k'] ?? '');
            // multilingual fields appear as field@de_DE in the entry - field means all languages
            $base = str_contains($key, '@') ? strstr($key, '@', true) : $key;
            if (array_key_exists($key, $fields)) $value = $render((string)$fields[$key]);
            elseif (array_key_exists($base, $fields)) $value = $render((string)$fields[$base]);
            elseif (array_key_exists('*', $fields)) $value = $render((string)$fields['*']);
            else continue;
            foreach (['b', 'a'] as $side) {
                if ((string)($change[$side] ?? '') !== '') $entry['changes'][$i][$side] = $value;
            }
        }
        return $entry;
    }

    /** Replace {record_id}, {user_id} in a value of a deletion policy */
    public static function renderValue(string $value, array $vars): string
    {
        return strtr($value, ['{record_id}' => (string)(int)($vars['record_id'] ?? 0), '{user_id}' => (string)(int)($vars['user_id'] ?? 0)]);
    }

    /**
     * Overwrites of the audit log of a record that have already run (module
     * Privacy) - [fields, vars] or null. Cached per request.
     */
    private function policyOverride(int $recordId): ?array
    {
        if (!array_key_exists($recordId, $this->policyOverrides)) {
            $class = \Camindo\Core\Modules\Privacy\PrivacyJobs::class;
            $this->policyOverrides[$recordId] = class_exists($class) ? $this->container->get($class)->auditOverride($recordId) : null;
        }
        return $this->policyOverrides[$recordId];
    }

    /** Cache for policyOverride() */
    private array $policyOverrides = [];

    /** After an action audit_set_fields: re-read policyOverride() */
    public function forgetPolicyOverride(int $recordId): void
    {
        unset($this->policyOverrides[$recordId]);
    }

    // ==== Aufbewahrung ====

    /**
     * Delete expired entries (see class comment).
     *
     * @return array  ['deleted' => n, 'changes' => n] deleted rows per rule
     */
    public function purge(): array
    {
        $result = ['deleted' => 0, 'changes' => 0, 'logins' => 0];
        $deletedDays = (int)($this->config->get('audit.retention_days_deleted') ?? 365);
        $changesDays = (int)($this->config->get('audit.retention_days_changes') ?? 0);
        $loginDays = $this->loginRetentionDays();

        $this->db->pushResult();
        try {
            if ($loginDays > 0) {
                // Logins/logouts, failed attempts: their own, shorter period
                $cutoff = gmdate('Y-m-d H:i:s', time() - $loginDays * 86400);
                $actions = "'" . implode("','", self::LOGIN_ACTIONS) . "'";
                $this->db->query("SELECT DISTINCT `object_id` FROM `audit_log` WHERE `object_type` = 'admin'"
                    . " AND `action` IN ($actions) AND `created_at` < ?", 's', [$cutoff]);
                $ids = array_map('intval', $this->db->fetchColumn());
                foreach ($ids as $adminId) {
                    // from the middle of the account's history - trim() would only take the beginning
                    $this->store->rewriteObject('admin', $adminId, fn(array $b) => in_array((string)($b['action'] ?? ''), self::LOGIN_ACTIONS, true)
                        && (string)$b['t'] < $cutoff ? null : $b, 'retention');
                }
                $this->db->query("DELETE FROM `audit_log` WHERE `object_type` = 'admin' AND `action` IN ($actions) AND `created_at` < ?", 's', [$cutoff]);
                $result['logins'] = max(0, $this->db->affected_rows);
            }
            if ($deletedDays > 0) {
                // Objects whose final deletion lies further back - except
                // records: their history is governed by the deletion policy of their area
                // (module Privacy, action audit_delete)
                $this->db->query(
                    "SELECT DISTINCT `object_type`, `object_id` FROM `audit_log`"
                    . " WHERE `action` = 'purge' AND `object_type` <> 'record' AND `created_at` < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)",
                    'i', [$deletedDays]
                );
                $objects = [];
                while ($row = $this->db->fetch()) $objects[] = $row;
                foreach ($objects as $object) {
                    // Created again (import with the same id)? Then only up to the deletion
                    $this->db->query(
                        "SELECT MAX(`audit_id`) FROM `audit_log` WHERE `object_type` = ? AND `object_id` = ? AND `action` = 'purge'",
                        'si', [$object['object_type'], (int)$object['object_id']]
                    );
                    $maxId = (int)($this->db->fetchNum()[0] ?? 0);
                    $this->store->trim((string)$object['object_type'], (int)$object['object_id'], fn(array $b) => (int)$b['id'] <= $maxId);
                    $this->db->query(
                        "DELETE FROM `audit_log` WHERE `object_type` = ? AND `object_id` = ? AND `audit_id` <= ("
                        . "SELECT `max_id` FROM (SELECT MAX(`audit_id`) AS `max_id` FROM `audit_log`"
                        . " WHERE `object_type` = ? AND `object_id` = ? AND `action` = 'purge') AS `p`)",
                        'sisi', [$object['object_type'], (int)$object['object_id'], $object['object_type'], (int)$object['object_id']]
                    );
                    $result['deleted'] += max(0, $this->db->affected_rows);
                }
            }
            if ($changesDays > 0) {
                $cutoff = gmdate('Y-m-d H:i:s', time() - $changesDays * 86400);
                $this->db->query("SELECT DISTINCT `object_type`, `object_id` FROM `audit_log` WHERE `created_at` < ?", 's', [$cutoff]);
                $objects = [];
                while ($row = $this->db->fetch()) $objects[] = $row;
                foreach ($objects as $object) {
                    $this->store->trim((string)$object['object_type'], (int)$object['object_id'], fn(array $b) => (string)$b['t'] < $cutoff);
                }
                $this->db->query("DELETE FROM `audit_log` WHERE `created_at` < ?", 's', [$cutoff]);
                $result['changes'] = max(0, $this->db->affected_rows);
            }
        }
        finally {
            $this->db->popResult();
        }
        return $result;
    }

    // ==== Helpers for the snapshots (Dataset / Record) ====

    /**
     * Snapshot entry of an encrypted value: ciphertext as base64 for later
     * decryption, compared via the HMAC of the plain text.
     *
     * @param string $cipher  ciphertext, binary
     */
    public function encryptedEntry(string $label, string $cipher, string $lang = ''): array
    {
        $plain = $cipher === '' ? '' : $this->crypt->decrypt($cipher, '', true);
        return [
            'l' => $label, 't' => 'encrypted', 'g' => $lang,
            'v' => $cipher === '' ? '' : base64_encode($cipher),
            'c' => $plain === '' ? '' : bin2hex($this->crypt->hash($plain, true)),
        ];
    }

    /**
     * Decrypt a stored encrypted value (base64) for display.
     */
    public function decryptValue(string $value): string
    {
        if ($value === '') return '';
        $cipher = base64_decode($value, true);
        if ($cipher === false) return '';
        try {
            return $this->crypt->decrypt($cipher, '', true);
        }
        catch (\Throwable) {
            return '';
        }
    }

    // ====================

    /**
     * Who is acting right now? Admin (session, also CLI with setCliAdmin()), logged-in
     * frontend user or the system (cron, CLI).
     *
     * @return array  type, admin_id, user_id, name, ip, origin
     */
    private function actor(): array
    {
        $origin = $this->context !== '' ? $this->context
            : (PHP_SAPI === 'cli' ? 'cli' : (defined('CAMINDO_BACKEND') ? 'backend' : 'frontend'));
        // Via ClientIp: behind a proxy the IP of the visitor, not that of the proxy
        $ip = PHP_SAPI === 'cli' ? '' : mb_substr($this->container->get(ClientIp::class)->ip(), 0, 45);
        $actor = ['type' => 'system', 'admin_id' => null, 'user_id' => null, 'name' => '', 'ip' => $ip, 'origin' => $origin];

        // Users and website users only as id (see class comment)
        $adminId = (int)$this->session->get('admin_id');
        if ($adminId > 0) {
            $actor['type'] = 'admin';
            $actor['admin_id'] = $adminId;
            return $actor;
        }
        if (PHP_SAPI === 'cli') return $actor;
        // Web request without admin: logged-in frontend user or visitor (also the
        // failed login at the backend)
        $actor['type'] = 'visitor';
        if (!defined('CAMINDO_BACKEND')) {
            $userSession = $this->container->get(UserSession::class);
            if ($userSession->isLoggedIn()) {
                $actor['type'] = 'user';
                $actor['user_id'] = $userSession->recordId();
            }
        }
        return $actor;
    }

    private function adminName(int $adminId): string
    {
        if (!isset($this->adminNames[$adminId])) {
            $this->db->pushResult();
            try {
                $this->db->query("SELECT `name` FROM `admin` WHERE `admin_id` = ?", 'i', [$adminId]);
                $this->adminNames[$adminId] = (string)($this->db->fetchNum()[0] ?? '');
            }
            finally {
                $this->db->popResult();
            }
        }
        return $this->adminNames[$adminId];
    }

    private function limit(string $value): string
    {
        if (mb_strlen($value) <= self::MAX_VALUE_LENGTH) return $value;
        return mb_substr($value, 0, self::MAX_VALUE_LENGTH) . self::TRUNCATED;
    }

    /**
     * WHERE clause of the search.
     *
     * @return array  [sql, types, values]
     */
    private function where(array $filter): array
    {
        $parts = [];
        $types = '';
        $values = [];
        $add = function (string $sql, string $type, mixed $value) use (&$parts, &$types, &$values) {
            $parts[] = $sql;
            $types .= $type;
            $values[] = $value;
        };
        if (!empty($filter['audit_id'])) $add('`audit_id` = ?', 'i', (int)$filter['audit_id']);
        if (!empty($filter['object_type'])) $add('`object_type` = ?', 's', (string)$filter['object_type']);
        if (!empty($filter['object_id'])) $add('`object_id` = ?', 'i', (int)$filter['object_id']);
        if (!empty($filter['area_id'])) $add('`area_id` = ?', 'i', (int)$filter['area_id']);
        if (!empty($filter['origin'])) $add('`origin` = ?', 's', (string)$filter['origin']);
        // Restrictions (rights): lists, empty = no hits
        if (isset($filter['object_types'])) {
            $list = array_values(array_filter(array_map('strval', (array)$filter['object_types']), fn($t) => preg_match('/^[a-z_]{1,32}$/', $t)));
            $parts[] = $list ? "`object_type` IN ('" . implode("','", $list) . "')" : '0';
        }
        if (isset($filter['area_ids'])) {
            $list = array_values(array_filter(array_map('intval', (array)$filter['area_ids']), fn($id) => $id > 0));
            $parts[] = $list ? '`area_id` IN (' . implode(',', $list) . ')' : '0';
        }
        if (!empty($filter['admin_id'])) $add('`admin_id` = ?', 'i', (int)$filter['admin_id']);
        if (!empty($filter['action']) && in_array($filter['action'], self::ACTIONS, true)) {
            $add('`action` = ?', 's', (string)$filter['action']);
        }
        if (!empty($filter['from'])) $add('`created_at` >= ?', 's', (string)$filter['from']);
        if (!empty($filter['to'])) $add('`created_at` < ?', 's', (string)$filter['to']);
        if (($search = trim((string)($filter['search'] ?? ''))) !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            // Actors are only stored as id - their name from `admin`
            $parts[] = '(`label` LIKE ? OR `actor_name` LIKE ? OR `ip` LIKE ?'
                . ' OR `admin_id` IN (SELECT `admin_id` FROM `admin` WHERE `name` LIKE ?))';
            $types .= 'ssss';
            array_push($values, $like, $like, $like, $like);
        }
        if (($field = trim((string)($filter['field'] ?? ''))) !== '') {
            $add('`fields` LIKE ?', 's', '%' . addcslashes(json_encode($field, JSON_UNESCAPED_UNICODE) ?: '', '%_\\') . '%');
        }
        return [$parts ? ' WHERE ' . implode(' AND ', $parts) : '', $types, $values];
    }
}
