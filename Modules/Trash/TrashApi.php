<?php
/**
 * API method of the trash: the deleted records (and for superusers the
 * deleted areas) - like the list of the module Trash, i.e. only the root of
 * each deletion operation. Restore: record.restore.
 *
 * As in the UI, each administrator only sees what they deleted themselves, and only
 * from areas they may read; superusers with all = true see the deletions of all.
 *
 * @see Trash
 */
namespace Camindo\Core\Modules\Trash;

use Camindo\Core\Access;
use Camindo\Core\AuditLog;
use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Api\ApiContext;
use Camindo\Core\Api\ApiMethod;

class TrashApi
{
    public function __construct(
        private Trash $trash,
        private Access $access,
        private Backend $backend,
        private Cms $cms,
        private Config $config,
        private AuditLog $auditLog,
        private ApiContext $context,
    )
    {
    }

    /**
     * Deleted records, most recently deleted first: label, area, who and
     * when deleted, deleted descendants, when the trash permanently deletes them.
     * Superusers additionally get the deleted areas.
     *
     * @param bool   $all     deletions of all administrators (superusers only; otherwise only their own)
     * @param string $search  partial word in the label of the record ('' = all)
     * @param int    $areaId  only from this area (0 = all)
     * @param int    $limit   maximum number (1-200)
     * @return array  records (record_id, label, area, deleted_at, deleted_by, children, expires_at), areas (superusers only), more
     */
    #[ApiMethod('trash.list', '#trash.api_list')]
    public function list(bool $all = false, string $search = '', int $areaId = 0, int $limit = 50): array
    {
        $this->context->require();
        $limit = max(1, min(Trash::MAX_ENTRIES, $limit));
        $superuser = $this->access->isSuperuser();
        $needle = mb_strtolower(trim($search));

        $records = [];
        $more = false;
        foreach ($this->trash->records($all && $superuser, Trash::MAX_ENTRIES) as $row) {
            if ($areaId > 0 && (int)$row['area_id'] !== $areaId) continue;
            $label = $this->label((int)$row['record_id']);
            if ($needle !== '' && !str_contains(mb_strtolower($label), $needle)) continue;
            if (count($records) >= $limit) {
                $more = true;
                break;
            }
            $areaData = $this->backend->areaData((int)$row['area_id']) ?? [];
            $records[] = [
                'record_id'  => (int)$row['record_id'],
                'label'      => $label,
                'area'       => ['area_id' => (int)$row['area_id'], 'label' => $this->context->plain($this->cms->textOrSnippet((string)($areaData['label'] ?? '')))],
                'deleted_at' => $this->context->isoTime((string)$row['changed_at']),
                'deleted_by' => $this->teaser('admin', (int)$row['changed_by']),
                'children'   => (int)$row['children'],
                'expires_at' => $this->expiresAt((string)$row['changed_at']),
            ];
        }
        $result = ['records' => $records];
        if ($superuser && $areaId < 1) {
            $result['areas'] = [];
            foreach ($this->trash->areas($limit) as $row) {
                if ($needle !== '' && !str_contains(mb_strtolower($this->cms->textOrSnippet((string)$row['label'])), $needle)) continue;
                $result['areas'][] = [
                    'area_id'    => (int)$row['area_id'],
                    'label'      => $this->context->plain($this->cms->textOrSnippet((string)$row['label'])),
                    'name'       => (string)$row['name'],
                    'deleted_at' => $this->context->isoTime((string)$row['changed_at']),
                    'deleted_by' => $this->teaser('admin', (int)$row['changed_by']),
                    'subareas'   => (int)$row['subareas'],
                    'records'    => (int)$row['records'],
                    'expires_at' => $this->expiresAt((string)$row['changed_at']),
                ];
            }
        }
        return $result + ['more' => $more];
    }

    // ====================

    /** Label of a deleted record: teaser, otherwise the one from the audit log (state at deletion) */
    private function label(int $recordId): string
    {
        $label = $this->teaser('record', $recordId);
        if ($label === '' || $label === $this->cms->snippet('trash.label_record', ['id' => $recordId])) {
            $logged = (string)($this->auditLog->search(['object_type' => 'record', 'object_id' => $recordId], 1, 0)[0]['label'] ?? '');
            if ($logged !== '' && $logged !== '#' . $recordId) $label = $logged;
        }
        return $label !== '' ? $label : $this->cms->snippet('trash.label_record', ['id' => $recordId]);
    }

    private function teaser(string $type, int $id): string
    {
        return $id > 0 ? $this->context->plain($this->backend->teaser($type, $id, linked: false, icon: false)) : '';
    }

    /** When the trash cron job permanently deletes (as Trash::expiresAt(), as ISO 8601) */
    private function expiresAt(string $changedAt): ?string
    {
        $days = max(0, (int)($this->config->get('admin.trash_retention_days') ?? 7));
        $date = (new \DateTimeImmutable($changedAt, new \DateTimeZone('UTC')))->modify("+{$days} days");
        return $this->context->isoTime($date->format('Y-m-d H:i:s'));
    }
}
