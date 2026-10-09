<?php
/**
 * API-Methoden des Audit-Logs: Einträge suchen und den Verlauf eines Objekts lesen -
 * auch von Datensätzen, die im Papierkorb liegen oder endgültig gelöscht sind (der
 * Verlauf wird nicht über den Datensatz geladen, sondern aus dem Protokoll).
 *
 * RECHTE
 * --------------------
 * Superuser (Modul Audit, Access::script()) sehen alles wie in der Oberfläche,
 * samt IP-Adressen. Alle anderen sehen - wie im Reiter "Änderungen" der
 * Datensatz-Info - nur Einträge zu Datensätzen in Bereichen, die sie lesen dürfen;
 * maßgeblich ist der Bereich aus der Audit-Zeile, damit die Prüfung auch nach dem
 * Löschen greift. Anmeldungen, Admins, Gruppen & Co. bleiben dem Superuser.
 *
 * ZUSTAND
 * --------------------
 * Zu Datensätzen und Bereichen nennt jede Antwort den heutigen Zustand: active,
 * trash (im Papierkorb, mit restorable und expires_at) oder purged (endgültig
 * gelöscht - es bleibt nur der Verlauf). area_in_trash: Der Bereich eines
 * Datensatzes liegt selbst im Papierkorb - dann kommt der Datensatz nur mit ihm
 * zurück (Modul Papierkorb, Superuser).
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
    /** Höchstzahl Einträge je Seite */
    private const LIMIT_MAX = 100;

    /** Objekttypen mit Zustand (Tabelle mit `deleted`) */
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
     * Einträge suchen, neueste zuerst - knapp, ohne Werte (die liefert audit.history).
     *
     * @param string $action      create | update | delete | restore | purge (Superuser auch login, login_failed, logout, export)
     * @param string $objectType  record, area, field, template, admin … ('' = alle; ohne Superuser nur record)
     * @param int    $objectId    Id des Objekts (0 = alle)
     * @param int    $areaId      Bereich (0 = alle)
     * @param int    $adminId     wer gehandelt hat (0 = alle)
     * @param string $origin      Herkunft: backend, frontend, api, llm, cli, cron … ('' = alle)
     * @param string $from        ab (Y-m-d oder Y-m-d H:i, Zeitzone des Admins)
     * @param string $to          bis einschließlich (Y-m-d = ganzer Tag, oder Y-m-d H:i)
     * @param string $search      Teilwort in Bezeichnung oder Name des Handelnden
     * @param string $field       Feldname oder -beschriftung unter den geänderten Feldern
     * @param bool   $oldestFirst älteste zuerst
     * @param int    $skip        überspringen (Blättern: next_skip)
     * @param int    $limit       Höchstzahl (1-100)
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
     * Der Verlauf eines Objekts mit Vorher/Nachher je Feld - von der Erstellung an
     * (älteste zuerst) oder die neuesten zuerst; auch für gelöschte Datensätze.
     *
     * @param int    $objectId     Id (z. B. record_id)
     * @param string $objectType   record (Standard), area, field, template …
     * @param bool   $oldestFirst  älteste zuerst (Standard) - false: neueste zuerst
     * @param int    $skip         überspringen (Blättern: next_skip)
     * @param int    $limit        Höchstzahl (1-100)
     * @param int    $truncate     Werte ab so vielen Zeichen kürzen (0 = nie)
     * @param bool   $verify       dazu die Prüfung der Hash-Kette des Verlaufs (nur Superuser)
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

        // ohne Superuser: nur Datensätze eines lesbaren Bereichs (heutiger bzw. letzter bekannter)
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
                'changes'  => $this->auditDiff->plainChanges((array)$row['changes'], max(0, $truncate)),
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

    /** Filter-Einschränkung ohne Superuser: nur Datensätze lesbarer Bereiche */
    private function restriction(): array
    {
        if ($this->superuser()) return [];
        $areaIds = $this->access->areaFilter();
        return ['object_types' => ['record']] + ($areaIds !== null ? ['area_ids' => $areaIds] : []);
    }

    /** Kopf eines Eintrags (ohne Werte) */
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
     * Heutiger Zustand der Datensätze und Bereiche in den Zeilen:
     * "type:id" => state (active | trash | purged), area_id, im Papierkorb dazu
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
                    // mit dem Bereich: liegt er selbst im Papierkorb, kommt der Datensatz nur mit ihm zurück
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

    /** Wann der Papierkorb-Cronjob endgültig löscht (wie Trash::expiresAt(), als ISO 8601) */
    private function expiresAt(string $changedAt): ?string
    {
        $days = max(0, (int)($this->config->get('admin.trash_retention_days') ?? 7));
        $date = (new \DateTimeImmutable($changedAt, new \DateTimeZone('UTC')))->modify("+{$days} days");
        return $this->context->isoTime($date->format('Y-m-d H:i:s'));
    }

    /**
     * Zeitangabe des Admins (Y-m-d oder Y-m-d H:i[:s], seine Zeitzone) nach UTC;
     * $end: ein reines Datum meint das Ende des Tages (exklusiv: nächster Tag 0 Uhr).
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
        // "bis" schließt den Tag bzw. die Minute/Sekunde ein
        if ($end) $date = $date->modify(empty($match[1]) ? '+1 day' : (empty($match[2]) ? '+1 minute' : '+1 second'));
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
