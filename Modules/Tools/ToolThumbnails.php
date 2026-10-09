<?php
/**
 * Werkzeug "Thumbnails neu erzeugen" (Tools -> Daten).
 *
 * Erzeugt für alle produktiven Datensätze eines Bereichs die Thumbnails neu - nach
 * geänderten Konverter-Einstellungen, einem neu angelegten Thumbnail-Feld oder
 * verlorenen Dateien. Je Datensatz läuft Record::regenerateThumbnails(): dieselbe
 * Erzeugung wie nach dem Speichern (VirtualFieldsTrait::writeDeferredThumbnails()),
 * Bilder sofort, Videos wie konfiguriert über den Trigger im Hintergrund.
 *
 * Zur Wahl stehen nur Datensatzbereiche, die ein Thumbnail-Feld führen
 * (areaOptions()). Der Lauf kann im Request oder - Vorgabe - als Hintergrundprozess
 * laufen (start() -> Core/cron/thumbnails.php, Rückmeldung als interne Nachricht,
 * wie beim Export).
 *
 *     backend/index_data.php        -> ToolsBackend::dataTools()   das Formular
 *     backend/tool_thumbnails.php   -> ToolsBackend::thumbnails()  ausführen / starten
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

    /** Typkennung des bgproc-Eintrags */
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
     * Datensatzbereiche mit mindestens einem Thumbnail-Feld: area_id => Beschriftung
     * (Label, bei mehreren Mandanten mit dem Namen der Instanz), in Baumreihenfolge.
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
     * Anzahl der produktiven Datensätze eines Bereichs.
     */
    public function count(int $areaId): int
    {
        $this->db->query("SELECT COUNT(*) FROM `record` WHERE `area_id` = ? AND `tmp` = 0 AND `deleted` = 0", 'i', [$areaId]);
        return (int)($this->db->fetchNum()[0] ?? 0);
    }

    /**
     * Thumbnails aller produktiven Datensätze eines Bereichs neu erzeugen - im
     * Request und im Hintergrund exakt dieselbe Methode.
     *
     * @param int           $areaId
     * @param callable|null $progress  fn(int $done, int $total) - Fortschritt
     * @return array  ['records' => n, 'files' => n (Quelldateien), 'failed' => n,
     *                 'error' => '' | Meldung (Bereich unbekannt)]
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
     * Den Lauf als Hintergrundprozess starten (Core/cron/thumbnails.php).
     *
     * @return array|null  Angaben des bgproc-Eintrags (BackgroundProcess::start()),
     *                     null bei Fehler (Text in $error)
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
            // kein Session-Kontext im Hintergrund: Empfänger der Nachricht
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
     * Ergebnis als Text: "n Datensätze, m Dateien; k fehlgeschlagen".
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
     * Hintergrund: Ergebnis als interne Nachricht an den auslösenden Admin.
     *
     * @return string  Betreff
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
