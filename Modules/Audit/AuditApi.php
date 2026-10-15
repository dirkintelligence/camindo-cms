<?php
/**
 * API methods of the audit log: search entries and read the history of an object -
 * also for records that are in the trash or permanently deleted (the
 * history is not loaded via the record, but from the log).
 *
 * PERMISSIONS
 * --------------------
 * Superusers (module Audit, Access::script()) see everything as in the UI,
 * including IP addresses. Everyone else sees - as in the "Changes" tab of the
 * record info - only entries for records in areas they may read;
 * the area from the audit line is authoritative, so the check still applies after
 * deletion. Logins, administrators, groups & co. remain reserved for superusers.
 *
 * STATE
 * --------------------
 * For records and areas, every response gives the current state: active,
 * trash (in the trash, with restorable and expires_at) or purged (permanently
 * deleted - only the history remains). area_in_trash: the area of a
 * record is itself in the trash - then the record only comes back with it
 * (module Trash, superuser).
 *
 * @see Camindo\Core\AuditLog
 * @see Camindo\Core\AuditDiff::plainChanges()
 */
namespace Camindo\Core\Modules\Audit;

use Camindo\Core\Access;
use Camindo\Core\AuditDiff;
use Camindo\Core\AuditLog;
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Database;
use Camindo\Core\Api\ApiContext;
use Camindo\Core\Api\ApiException;
use Camindo\Core\Api\ApiMethod;
use Camindo\Core\Modules\Trash\Trash;

class AuditApi
{
    /** Maximum number of entries per page */
    private const LIMIT_MAX = 100;

    /** Object types with state (table with `deleted`) */
    private const STATE_TYPES = ['record', 'area'];

    public function __construct(
        private AuditLog $auditLog,
        private AuditDiff $auditDiff,
        private Access $access,
        private Cms $cms,
        private Config $config,
        private Database $db,
        private Trash $trash,
        private ApiContext $context,
    )
    {
    }

    /**
     * Search entries, newest first - compact, without values (those are returned by audit.history).
     *
     * @param string $action      create | update | delete | restore | purge (superusers also login, login_failed, logout, export)
     * @param string $objectType  record, area, field, template, admin … ('' = all; without superuser only record)
     * @param int    $objectId    Id of the object (0 = all)
     * @param int    $areaId      area (0 = all)
     * @param int    $adminId     who acted (0 = all)
     * @param string $origin      origin: backend, frontend, api, llm, cli, cron … ('' = all)
     * @param string $from        from (Y-m-d or Y-m-d H:i, time zone of the administrator)
     * @param string $to          up to and including (Y-m-d = whole day, or Y-m-d H:i)
     * @param string $search      partial word in the label or name of the actor
     * @param string $field       field name or label among the changed fields
     * @param bool   $oldestFirst oldest first
     * @param int    $skip        skip (paging: next_skip)
     * @param int    $limit       maximum number (1-100)
     * @return array  total, items (audit_id, time, action, object_type, object_id, area_id, label, actor, origin, fields, state), next_skip
     */
    #[ApiMethod('audit.search', '#audit.api_search')]
    public function search(string $action = '', string $objectType = '', int $objectId = 0, int $areaId = 0, int $adminId = 0,
        string $origin = '', string $from = '', string $to = '', string $search = '', string $field = '',
        bool $oldestFirst = false, int $skip = 0, int $limit = 20): array
    {
        $this->context->require();
        if ($action !== '' && !in_array($action, AuditLog::ACTIONS, true)) {
            throw new ApiException(ApiException::INVALID_PARAM, 'action must be one of ' . implode(', ', AuditLog::ACTIONS));
        }
        if ($objectType !== '' && !preg_match('/^[a-z_]{1,32}$/', $objectType)) {
            throw new ApiException(ApiException::INVALID_PARAM, "invalid object_type \"{$objectType}\"");
        }
        $filter = array_filter([
            'action' => $action, 'object_type' => $objectType, 'object_id' => $objectId, 'area_id' => $areaId,
            'admin_id' => $adminId, 'origin' => $origin, 'search' => $search, 'field' => $field,
            'from' => $this->utc($from, false), 'to' => $this->utc($to, true),
        ], fn($value) => $value !== '' && $value !== 0 && $value !== null);
        $filter += $this->restriction();

        $limit = max(1, min(self::LIMIT_MAX, $limit));
        $skip = max(0, $skip);
        $total = $this->auditLog->count($filter);
        $rows = $this->auditLog->search($filter, $limit, $skip, $oldestFirst);
        $states = $this->states($rows);
        $items = [];
        foreach ($rows as $row) {
            $item = $this->head($row);
            $item['fields'] = array_values(array_unique(array_filter(array_map(fn($change) => (string)($change['k'] ?? ''), (array)$row['changes']))));
            $state = $states[$row['object_type'] . ':' . (int)$row['object_id']] ?? null;
            if ($state !== null) $item['state'] = $state['state'];
            $items[] = $item;
        }
        return ['total' => $total, 'items' => $items] + ($skip + count($rows) < $total ? ['next_skip' => $skip + count($rows)] : []);
    }

    /**
     * The history of an object with before/after per field - from creation on
     * (oldest first) or newest first; also for deleted records.
     *
     * @param int    $objectId     Id (e.g. record_id)
     * @param string $objectType   record (default), area, field, template …
     * @param bool   $oldestFirst  oldest first (default) - false: newest first
     * @param int    $skip         skip (paging: next_skip)
     * @param int    $limit        maximum number (1-100)
     * @param int    $truncate     truncate values after this many characters (0 = never)
     * @param bool   $verify       also verify the hash chain of the history (superusers only)
     * @return array  object (type, id, label, area_id, state …), total, entries (audit_id, time, action, actor, origin, changes), next_skip, verify
     */
    #[ApiMethod('audit.history', '#audit.api_history')]
    public function history(int $objectId, string $objectType = 'record', bool $oldestFirst = true, int $skip = 0,
        int $limit = 20, int $truncate = 300, bool $verify = false): array
    {
        $this->context->require();
        if (!preg_match('/^[a-z_]{1,32}$/', $objectType) || $objectId < 1) {
            throw new ApiException(ApiException::INVALID_PARAM, 'object_type and object_id are required');
        }
        $superuser = $this->superuser();
        $filter = ['object_type' => $objectType, 'object_id' => $objectId];
        $total = $this->auditLog->count($filter);
        $latest = $total > 0 ? ($this->auditLog->search($filter, 1, 0)[0] ?? null) : null;
        $state = $this->states([['object_type' => $objectType, 'object_id' => $objectId]])[$objectType . ':' . $objectId] ?? null;

        // without superuser: only records of a readable area (current or last known)
        if (!$superuser) {
            $areaId = (int)($state['area_id'] ?? $latest['area_id'] ?? 0);
            if ($objectType !== 'record' || $areaId < 1 || !$this->access->areaRead($areaId)) {
                throw new ApiException(ApiException::ACCESS_DENIED, $this->cms->snippet('error_access_denied'));
            }
        }
        if ($latest === null) {
            throw new ApiException(ApiException::NOT_FOUND, "no audit entries for {$objectType} {$objectId}");
        }

        $limit = max(1, min(self::LIMIT_MAX, $limit));
        $skip = max(0, $skip);
        $entries = [];
        $rows = $this->auditLog->historyPage($objectType, $objectId, $limit, $skip, $oldestFirst);
        foreach ($rows as $row) {
            $entry = [
                'audit_id' => (int)$row['audit_id'],
                'time'     => $this->context->isoTime(substr((string)$row['created_at'], 0, 19)),
                'action'   => (string)$row['action'],
                'actor'    => $this->auditDiff->actorLabel($row),
                'origin'   => (string)$row['origin'],
                'changes'  => $this->auditDiff->plainChanges((array)$row['changes'], max(0, $truncate), $this->context->masksEncrypted()),
            ];
            if ($superuser) $entry['ip'] = (string)$row['ip'];
            if (!empty($row['content_missing'])) $entry['content_missing'] = true;
            $entries[] = $entry;
        }

        $object = ['type' => $objectType, 'id' => $objectId, 'label' => (string)$latest['label'],
            'area_id' => (int)($state['area_id'] ?? $latest['area_id'] ?? 0) ?: null];
        if ($state !== null) $object += array_diff_key($state, ['area_id' => true]);
        $result = ['object' => $object, 'total' => $total, 'entries' => $entries]
            + ($skip + count($rows) < $total ? ['next_skip' => $skip + count($rows)] : []);
        if ($verify && $superuser) {
            $check = $this->auditLog->verifyObject($objectType, $objectId);
            $result['verify'] = ['ok' => (bool)$check['ok'], 'errors' => array_values((array)$check['errors'])];
        }
        return $result;
    }

    // ====================

    private function superuser(): bool
    {
        return $this->access->script('Audit', 'index.php');
    }

    /** Filter restriction without superuser: only records of readable areas */
    private function restriction(): array
    {
        if ($this->superuser()) return [];
        $areaIds = $this->access->areaFilter();
        return ['object_types' => ['record']] + ($areaIds !== null ? ['area_ids' => $areaIds] : []);
    }

    /** Header of an entry (without values) */
    private function head(array $row): array
    {
        $item = [
            'audit_id'    => (int)$row['audit_id'],
            'time'        => $this->context->isoTime(substr((string)$row['created_at'], 0, 19)),
            'action'      => (string)$row['action'],
            'object_type' => (string)$row['object_type'],
            'object_id'   => (int)$row['object_id'],
            'area_id'     => $row['area_id'] !== null ? (int)$row['area_id'] : null,
            'label'       => (string)$row['label'],
            'actor'       => $this->auditDiff->actorLabel($row),
            'origin'      => (string)$row['origin'],
        ];
        if ($this->superuser()) $item['ip'] = (string)$row['ip'];
        return $item;
    }

    /**
     * Current state of the records and areas in the rows:
     * "type:id" => state (active | trash | purged), area_id, plus for the trash
     * deleted_at, expires_at, restorable.
     */
    private function states(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (in_array($row['object_type'], self::STATE_TYPES, true)) $ids[$row['object_type']][(int)$row['object_id']] = true;
        }
        $states = [];
        foreach ($ids as $type => $list) {
            $list = array_keys($list);
            $found = [];
            $this->db->pushResult();
            try {
                $in = implode(',', array_map('intval', $list));
                $this->db->query($type === 'record'
                    // with the area: if the area itself is in the trash, the record only comes back with it
                    ? "SELECT `r`.`record_id` AS `id`, `r`.`deleted`, `r`.`tmp`, `r`.`changed_at`, `r`.`area_id`, `a`.`deleted` AS `area_deleted`"
                        . " FROM `record` `r` LEFT JOIN `area` `a` ON `a`.`area_id` = `r`.`area_id` WHERE `r`.`record_id` IN ({$in})"
                    : "SELECT `area_id` AS `id`, `deleted`, 0 AS `tmp`, `changed_at` FROM `area` WHERE `area_id` IN ({$in})");
                while ($row = $this->db->fetch()) $found[(int)$row['id']] = $row;
            }
            finally {
                $this->db->popResult();
            }
            foreach ($list as $id) {
                $row = $found[$id] ?? null;
                if ($row === null || !empty($row['tmp'])) {
                    $state = ['state' => 'purged'];
                }
                elseif (!empty($row['deleted'])) {
                    $state = ['state' => 'trash',
                        'deleted_at' => $this->context->isoTime((string)$row['changed_at']),
                        'expires_at' => $this->expiresAt((string)$row['changed_at']),
                        'restorable' => $this->trash->restorable($type, $id)];
                }
                else {
                    $state = ['state' => 'active'];
                }
                if ($row !== null && $type === 'record') {
                    $state['area_id'] = (int)$row['area_id'];
                    if (!empty($row['area_deleted'])) $state['area_in_trash'] = true;
                }
                $states[$type . ':' . $id] = $state;
            }
        }
        return $states;
    }

    /** When the trash cron job permanently deletes (as Trash::expiresAt(), as ISO 8601) */
    private function expiresAt(string $changedAt): ?string
    {
        $days = max(0, (int)($this->config->get('admin.trash_retention_days') ?? 7));
        $date = (new \DateTimeImmutable($changedAt, new \DateTimeZone('UTC')))->modify("+{$days} days");
        return $this->context->isoTime($date->format('Y-m-d H:i:s'));
    }

    /**
     * Time given by the administrator (Y-m-d or Y-m-d H:i[:s], their time zone) converted to UTC;
     * $end: a plain date means the end of the day (exclusive: next day 00:00).
     *
     * @throws ApiException
     */
    private function utc(string $value, bool $end): ?string
    {
        $value = trim($value);
        if ($value === '') return null;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', $value, $match)) {
            throw new ApiException(ApiException::INVALID_PARAM, "invalid date \"{$value}\" (Y-m-d or Y-m-d H:i)");
        }
        $timezone = new \DateTimeZone($this->cms->userTZ !== '' ? $this->cms->userTZ : date_default_timezone_get());
        $date = new \DateTimeImmutable(str_replace('T', ' ', $value), $timezone);
        // "to" includes the whole day, or the minute/second
        if ($end) $date = $date->modify(empty($match[1]) ? '+1 day' : (empty($match[2]) ? '+1 minute' : '+1 second'));
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
