<?php
/**
 * API-Methode des Papierkorbs: die gelöschten Datensätze (und für Superuser die
 * gelöschten Bereiche) - wie die Liste des Moduls Papierkorb, also nur die Wurzel
 * jedes Löschvorgangs. Wiederherstellen: record.restore.
 *
 * Wie in der Oberfläche sieht jeder Admin nur, was er selbst gelöscht hat, und nur
 * aus Bereichen, die er lesen darf; Superuser mit all = true die Löschungen aller.
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
     * Gelöschte Datensätze, zuletzt gelöschte zuerst: Bezeichnung, Bereich, wer und
     * wann gelöscht hat, mitgelöschte Nachkommen, wann der Papierkorb sie endgültig
     * löscht. Superuser zusätzlich die gelöschten Bereiche.
     *
     * @param bool   $all     Löschungen aller Admins (nur Superuser; sonst nur die eigenen)
     * @param string $search  Teilwort in der Bezeichnung des Datensatzes ('' = alle)
     * @param int    $areaId  nur aus diesem Bereich (0 = alle)
     * @param int    $limit   Höchstzahl (1-200)
     * @return array  records (record_id, label, area, deleted_at, deleted_by, children, expires_at), areas (nur Superuser), more
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

    /** Bezeichnung eines gelöschten Datensatzes: Teaser, sonst die aus dem Audit-Log (Stand beim Löschen) */
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

    /** Wann der Papierkorb-Cronjob endgültig löscht (wie Trash::expiresAt(), als ISO 8601) */
    private function expiresAt(string $changedAt): ?string
    {
        $days = max(0, (int)($this->config->get('admin.trash_retention_days') ?? 7));
        $date = (new \DateTimeImmutable($changedAt, new \DateTimeZone('UTC')))->modify("+{$days} days");
        return $this->context->isoTime($date->format('Y-m-d H:i:s'));
    }
}
