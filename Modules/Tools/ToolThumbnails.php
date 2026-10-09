<?php
/**
 * Tool "Regenerate thumbnails" (Tools -> Data).
 *
 * Regenerates the thumbnails for all live records of an area - after changed converter
 * settings, a newly created thumbnail field, or lost files. For each record
 * Record::regenerateThumbnails() runs: the same generation as after saving
 * (VirtualFieldsTrait::writeDeferredThumbnails()):
 * images immediately, videos in the background via the trigger as configured.
 *
 * Only record areas that have a thumbnail field can be selected
 * (areaOptions()). The run can take place in the request or - by default - as a background process
 * (start() -> Core/cron/thumbnails.php, feedback as an internal message,
 * as with the export).
 *
 *     backend/index_data.php        -> ToolsBackend::dataTools()   the form
 *     backend/tool_thumbnails.php   -> ToolsBackend::thumbnails()  run / start
 *
 * @see Camindo\Core\VirtualFieldsTrait::regenerateThumbnails()
 * @see Camindo\Core\Modules\Tools\ToolsBackend
 */
namespace Camindo\Core\Modules\Tools;

use Camindo\Core\BackgroundProcess;
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Crypt;
use Camindo\Core\Database;
use Camindo\Core\InstanceCache;
use Camindo\Core\Session;
use Camindo\Core\System;
use Camindo\Core\Modules\Messaging\Message;
use Camindo\Core\Modules\Record\Record;

class ToolThumbnails {

    /** Type identifier of the bgproc entry */
    public const BGPROC_TYPE = 'thumbnails';

    public function __construct(
        private Config $config,
        private Database $db,
        private Cms $cms,
        private Crypt $crypt,
        private System $system,
        private Session $session,
        private BackgroundProcess $bgproc,
        private InstanceCache $instanceCache,
        private Record $record,
        private Message $message,
    )
    {}

    /**
     * Record areas with at least one thumbnail field: area_id => label
     * (label, with multiple tenants the name of the instance), in tree order.
     */
    public function areaOptions(): array
    {
        $this->db->query(
            "SELECT `a`.`area_id`, `a`.`label`, `a`.`name`, `a`.`instance_id` FROM `area` `a`"
            . " WHERE `a`.`type` IN ('record','profile','ugc') AND `a`.`deleted` = 0"
            . " AND EXISTS (SELECT 1 FROM `field` `f` WHERE `f`.`area_id` = `a`.`area_id`"
            . "   AND `f`.`type` = 'file' AND `f`.`display_type` = 'thumbnail')"
            . " ORDER BY `a`.`lft` ASC"
        );
        $multi = $this->instanceCache->hasMultipleInstances();
        $instances = $multi ? $this->instanceCache->instanceLabels() : [];
        $options = [];
        foreach ($this->db->fetchAll() as $row) {
            $label = $this->cms->_strip_tags($this->cms->textOrSnippet((string)$row['label'])) . ' (' . $row['name'] . ')';
            if ($multi) {
                $label = $this->cms->_strip_tags($this->cms->textOrSnippet((string)($instances[(int)$row['instance_id']] ?? ''))) . ' / ' . $label;
            }
            $options[(int)$row['area_id']] = $label;
        }
        return $options;
    }

    /**
     * Number of live records of an area.
     */
    public function count(int $areaId): int
    {
        $this->db->query("SELECT COUNT(*) FROM `record` WHERE `area_id` = ? AND `tmp` = 0 AND `deleted` = 0", 'i', [$areaId]);
        return (int)($this->db->fetchNum()[0] ?? 0);
    }

    /**
     * Regenerate the thumbnails of all live records of an area - in the
     * request and in the background exactly the same method.
     *
     * @param int           $areaId
     * @param callable|null $progress  fn(int $done, int $total) - progress
     * @return array  ['records' => n, 'files' => n (source files), 'failed' => n,
     *                 'error' => '' | message (area unknown)]
     */
    public function run(int $areaId, ?callable $progress = null): array
    {
        $result = ['records' => 0, 'files' => 0, 'failed' => 0, 'error' => ''];
        if (!isset($this->areaOptions()[$areaId])) {
            $result['error'] = $this->cms->snippet('area.error_area_not_found');
            return $result;
        }
        $schemaVirtual = $this->record->virtualSchema($areaId, false);

        $this->db->query("SELECT `record_id` FROM `record` WHERE `area_id` = ? AND `tmp` = 0 AND `deleted` = 0 ORDER BY `record_id` ASC", 'i', [$areaId]);
        $ids = array_map('intval', $this->db->fetchAll());
        $total = count($ids);

        foreach ($ids as $i => $recordId) {
            try {
                $this->record->clearRecordCache();
                $result['files'] += $this->record->regenerateThumbnails($recordId, $schemaVirtual);
                $result['records']++;
            }
            catch (\Throwable $e) {
                $result['failed']++;
                $this->system->log('thumbnails-errors', "record {$recordId}: " . $e->getMessage());
            }
            if ($progress !== null && (($i + 1) % 10 === 0 || $i + 1 === $total)) {
                $progress($i + 1, $total);
            }
        }
        return $result;
    }

    /**
     * Start the run as a background process (Core/cron/thumbnails.php).
     *
     * @return array|null  details of the bgproc entry (BackgroundProcess::start()),
     *                     null on error (text in $error)
     */
    public function start(int $areaId, ?string &$error = null): ?array
    {
        $error = '';
        $phpExec = (string)$this->config->get('paths.php');
        if ($phpExec === '' || !is_executable($phpExec)) {
            $error = "Error: 'php' is not executable (paths.php)";
            $this->system->log('thumbnails-errors', $error);
            return null;
        }
        $token = $this->crypt->randomKey(16);
        $command = [
            $phpExec,
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . 'thumbnails.php',
            '--area=' . $areaId,
            // no session context in the background: recipient of the message
            '--admin=' . (int)$this->session->get('admin_id'),
            '--token=' . $token,
        ];
        $result = $this->bgproc->start($command, self::BGPROC_TYPE, $token, null, null, $error);
        if ($result === null) {
            $this->system->log('thumbnails-errors', "start failed: {$error}");
        }
        return $result;
    }

    /**
     * Result as text: "n records, m files; k failed".
     */
    public function summaryText(array $result): string
    {
        $text = $this->cms->snippet('tools.thumbnails_summary', [
            'records' => (int)$result['records'],
            'files' => (int)$result['files'],
        ]);
        if ((int)$result['failed'] > 0) {
            $text .= ' ' . $this->cms->snippet('tools.thumbnails_summary_failed', ['count' => (int)$result['failed']]);
        }
        return $text;
    }

    /**
     * Background: result as internal message to the triggering admin.
     *
     * @return string  subject
     */
    public function report(int $areaId, int $adminId, array $result): string
    {
        $this->db->query("SELECT `label` FROM `area` WHERE `area_id` = ?", 'i', [$areaId]);
        $areaLabel = $this->cms->_strip_tags($this->cms->textOrSnippet((string)($this->db->fetchNum()[0] ?? '')));
        $subject = str_replace('{area}', $areaLabel, $this->cms->snippet('tools.thumbnails_report_subject'));
        if ($adminId < 1) return $subject;
        $body = ($result['error'] !== '') ? (string)$result['error'] : $this->summaryText($result);
        $this->message->sendSystem($adminId, $subject, $body);
        return $subject;
    }
}
