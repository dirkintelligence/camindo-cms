<?php
/**
 * Base class of the media triggers.
 *
 * A media processor can start its conversion as a background process
 * (converter setting background_process, or forced if the source file is already
 * being created in the background). The web request has long since ended when the command
 * runs through. A trigger fetches the result: it follows the progress, intervenes on
 * timeout and writes in the final file parameters.
 *
 * THREE KINDS OF KNOWLEDGE
 * --------------------
 * This class separates them deliberately, because they grow at different rates:
 *
 *   1. PROGRESS PROTOCOL  -> child class of this base class
 *      How the running conversion reports its state. This depends on the command-line
 *      tool, not on the processor: all three FFmpeg processors write the same
 *      "key=value" blocks, magick writes "50% complete". Therefore there is
 *      exactly one trigger per BGPROC_TYPE, not one per processor.
 *
 *   2. FILE METADATA          -> MediaProcessor::rectifyParams()
 *      What is in the finished file (dimensions, size, MIME type). Only the processor
 *      that created it knows this - an audio track has no image width.
 *
 *   3. PERSISTENCE            -> VirtualFieldsTrait::promoteBackgroundThumbnails()
 *      How metadata becomes `data_fil` rows and how the thumbnail chain
 *      continues. That is the business of the record and the same for all media.
 *
 * WHICH PROCESSOR WAS IT?
 * --------------------
 * From `bgproc`.`type` alone this cannot be derived - FFmpegVideo, FFmpegAudio
 * and FFmpegImage share 'ffmpeg'. MediaProcessor::startBackgroundProcess() therefore stores
 * its name as 'proc' in `bgproc`.`message`; here it is read back again and
 * turned into the instance via the ProcessorFactory. Older entries without 'proc'
 * fall back to DEFAULT_PROCESSOR.
 *
 * MULTI-STAGE CHAINS
 * --------------------
 * A thumbnail can itself be the source of further thumbnails. As long as the source file
 * is still being created, the follow-up thumbnail cannot be created - it is only created here,
 * again as a background process. A chain thus works its way forward cron run by cron run,
 * one pass per stage.
 *
 * CREATING YOUR OWN TRIGGER
 * --------------------
 * Only necessary if a tool reports its progress in a NEW format:
 *   - child class with BGPROC_TYPE, PROGRESS_PREFIX and DEFAULT_PROCESSOR
 *   - implement readProgress()
 *   - start script following the pattern of cron/trigger_ff.php, call in TriggerCron
 * A new processor that uses an existing format needs nothing here at all:
 * it sets BGPROC_TYPE to the matching value and implements rectifyParams().
 *
 * @see Camindo\Core\MediaProcessor                                  convert(), rectifyParams()
 * @see Camindo\Core\VirtualFieldsTrait::promoteBackgroundThumbnails  continuation of the chain
 * @see Camindo\Core\BackgroundProcess                               state in `bgproc`
 */
namespace Camindo\Core\cron;

use Camindo\Core\Config;
use Camindo\Core\Cms;
use Camindo\Core\Database;
use Camindo\Core\Execute;
use Camindo\Core\BackgroundProcess;
use Camindo\Core\MediaProcessor;
use Camindo\Core\ProcessorFactory;
use Camindo\Core\Modules\Record\Record;

abstract class TriggerMedia {

    /** Background process type, column `bgproc`.`type` */
    protected const BGPROC_TYPE = '';

    /** Processor for entries without 'proc' in `message` (short name, e.g. 'FFmpegVideo') */
    protected const DEFAULT_PROCESSOR = '';

    /** Prefix and extension of the progress file; the file name contains the token */
    protected const PROGRESS_PREFIX    = '';
    protected const PROGRESS_EXTENSION = '.progress';

    /** How long orphaned progress files may remain (hours) */
    protected const ORPHAN_HOURS = 24;

    /** How many bytes are read from the end of the progress file */
    protected const TAIL_BYTES = 8192;

    public function __construct(
        protected Config $config,
        protected Database $db,
        protected BackgroundProcess $bgproc,
        protected Execute $execute,
        protected Record $record,
        protected ProcessorFactory $factory,
        protected Cms $cms,
    )
    {
    }

    /**
     * Read the state of a running process from its progress file.
     *
     * Only part a child class has to supply.
     *
     * @param string $file      path to the progress file
     * @param array  $parts     decoded `bgproc`.`message` (e.g. 'frames', 'dur')
     * @param int    $previous  last saved progress, fallback value
     * @return array|null       ['percent' => int (0-100), 'finished' => bool] or null,
     *                          if the file is missing or contains nothing usable yet
     */
    abstract protected function readProgress(string $file, array $parts, int $previous): ?array;

    /**
     * Work through all background processes of this type.
     */
    public function check(): void
    {
        // Do not allow parallel runs.
        // The lock covers the ENTIRE run and is released only in the finally -
        // not inside the loop: after a process ends, the actual work follows
        // with rectifyParams() and promoteBackgroundThumbnails(), including the start
        // of further background processes. If the lock dropped beforehand, the cron
        // running every minute could finalize the same tokens in parallel and
        // start processes twice.
        // The lock applies per type: trigger_ff and trigger_magick do not block each other.
        if (!$this->bgproc->lock(static::BGPROC_TYPE)) {
            echo "Backgroundprocess of type=" . static::BGPROC_TYPE . " is already running. exit.\n";
            exit(0); // already running
        }

        try {
            $now        = time(); // UTC
            $knownFiles = [];
            $tmpDir     = $this->config->get('paths.tmp_dir');

            // list() also reconciles the stored state with the actual process state,
            // so the `status` column is current afterwards.
            $processes = $this->bgproc->list();

            foreach ($processes as $row) {
                if (($row['type'] ?? '') !== static::BGPROC_TYPE) continue;

                $token = (string)($row['token'] ?? '');
                // Without a token neither the progress file nor the
                // `data_fil` entry can be assigned
                if ($token === '') continue;

                // Also examine finished processes:
                // as long as they are registered in `bgproc`, they have not been finalized yet
                $status   = (int)($row['status'] ?? 0);
                $pid      = (int)($row['pid'] ?? 0);
                $progress = (int)($row['progress'] ?? 0);
                $parts    = json_decode((string)($row['message'] ?? ''), true) ?? [];

                // ---- Determine progress ----
                $progressFile = $tmpDir . DIRECTORY_SEPARATOR . $this->progressFileName($token);
                $knownFiles[basename($progressFile)] = true;

                $state = $this->readProgress($progressFile, $parts, $progress);
                if ($state === null) {
                    // no output yet. But if the process is already gone, no more comes either
                    // (e.g. ffmpeg failed right at the start) - otherwise the entry would
                    // stay forever as "finished, 0 %"
                    if ($status != Execute::STATE_RUNNING) {
                        $this->fail($token, 'error_bgproc_aborted', $progress);
                        @unlink($progressFile);
                        echo "failed (no output): $token\n";
                    }
                    continue;
                }

                $finished = (bool)($state['finished'] ?? false);
                // While the process runs, never report 100: that is reserved for the end
                $percent  = max(0, min(99, (int)($state['percent'] ?? $progress)));

                if (!$finished) {
                    $this->trackRunning($token, $pid, $percent, $status, $parts, $row, $progressFile, $now);
                    continue;
                }

                // ---- Background process completed successfully ----
                @unlink($progressFile);
                $this->finalize($token, $parts);
            }

            $this->cleanupOrphans($tmpDir, $knownFiles, $now);

        } catch (\Throwable $e) {
            // Catch errors and log them if necessary, or rethrow them.
            // It is important that the catch block leads to the finally.
            throw $e;
        } finally {
            // Always executed (except for fatal errors or exit() in the try block).
            // The only place where the lock from lock() is released.
            $this->bgproc->unlock();
        }

        echo "done.\n";
        exit(0);
    }

    /**
     * Running process: update progress, handle abort and
     * timeout.
     */
    private function trackRunning(
        string $token,
        int $pid,
        int $percent,
        int $status,
        array $parts,
        array $row,
        string $progressFile,
        int $now
    ): void
    {
        // Check whether the process is still working
        if ($status != Execute::STATE_RUNNING) {
            // Process is gone but reported no end: aborted or
            // crashed. The progress remains.
            $this->fail($token, 'error_bgproc_aborted', $percent);
            @unlink($progressFile);
            echo "failed: $token\n";
            return;
        }

        // Check for timeout
        $timeout   = (int)($parts['timeout'] ?? 0); // from the JSON field
        $createdAt = strtotime(((string)($row['created_at'] ?? '')) . ' UTC');
        $overdue   = $timeout > 0 && $createdAt > 0 && ($now - $createdAt) > $timeout;
        if ($overdue) {
            // First SIGTERM, so that the tool can still close the file, then SIGKILL
            $this->execute->stopProcess($pid, 3);
            $msg = json_encode(['error' => 'error_bgproc_timeout'], JSON_UNESCAPED_UNICODE) ?? '{}';
            $this->bgproc->setProgressByToken($token, $percent, $msg);
            $this->bgproc->setStatusByToken($token, BackgroundProcess::STATUS_TIMEOUT);
            @unlink($progressFile);
            echo "timeout: $token\n";
            return;
        }

        $this->bgproc->setProgressByToken($token, $percent);
    }

    /**
     * Complete a finished process: add final file parameters in `data_fil`,
     * trigger thumbnails building on them, remove the process.
     */
    private function finalize(string $token, array $parts): void
    {
        // Determine final parameters of the created file
        $sql = "SELECT `d`.*, `r`.`area_id` FROM `data_fil` `d` LEFT JOIN `record` `r`"
            . " ON `d`.`record_id` = `r`.`record_id`"
            . " WHERE `d`.`token` = ?";
        $this->db->query($sql, 's', [$token]);

        if (!$fileData = $this->db->fetch()) {
            // File entry not found
            $this->fail($token, 'error_file_not_found');
            return;
        }

        $areaId = (int)($fileData['area_id'] ?? 0);
        if (empty($areaId)) {
            // Record no longer exists
            $this->fail($token, 'error_record_not_found');
            return;
        }

        // Processor that created the file (see class comment)
        $processor = $this->processor($parts);
        if ($processor === null) {
            $this->fail($token, 'error_processor_not_found');
            return;
        }

        $recordId    = (int)$fileData['record_id'];
        $fieldId     = (int)$fileData['field_id'];
        $fieldNameId = (int)$fileData['fieldname_id'];
        // Language of the file (multilingual field), NULL for single-language fields
        $fileLanguageId = $this->cms->fileLanguageId($fileData);
        // relative path to the file from the media directory
        $srcPath = $this->cms->mediaPath($fieldId, $recordId, $fileLanguageId, (string)$fileData['extension']);

        // Read out properties via processor method
        $srcInfo = $processor->rectifyParams($srcPath);
        if (empty($srcInfo['size'])) {
            // File properties could not be determined
            $this->fail($token, (string)($srcInfo['error'] ?? 'error_file_conversion'));
            return;
        }

        // Enter file parameters in table `data_fil`.
        // extension and mime_type stay: the converter already knew them at the
        // start, and not every processor can read a MIME type from the file.
        $width  = (int)($srcInfo['width'] ?? 0);
        $height = (int)($srcInfo['height'] ?? 0);
        $size   = (int)($srcInfo['size'] ?? 0);
        // data contains all info the processor could determine
        $srcData = json_encode($srcInfo, JSON_UNESCAPED_UNICODE) ?: '{}';
        // All rows that describe this file: for single-language fields one per language,
        // for multilingual ones the own row plus the fallback rows of the
        // languages without an own file (same file_language_id)
        $sql = "UPDATE `data_fil` SET `token` = '', `width` = ?, `height` = ?, `size` = ?,"
            . " `data` = ? WHERE `record_id` = ? AND `fieldname_id` = ? AND `file_language_id` <=> ?";
        $this->db->query($sql, 'iiisiii', [$width, $height, $size, $srcData, $recordId, $fieldNameId, $fileLanguageId]);

        // Discard the content cache: Cms::fetch() rebuilds it on the next access
        $this->db->query("DELETE FROM `record_cache` WHERE `record_id` = ?", 'i', [$recordId]);

        // Mark this process as successfully completed
        $this->bgproc->setProgressByToken($token, 100, '{}');
        $this->bgproc->setStatusByToken($token, BackgroundProcess::STATUS_FINISHED);

        // Create thumbnails that build on this file, and their `data_fil`
        // entries. The freshly determined dimensions win; everything else
        // (mime_type, extension, filename) stays in the existing entry.
        $schemaVirtual = $this->record->virtualSchema($areaId, false);
        $parentData = array_merge($fileData, [
            'width'  => $width,
            'height' => $height,
            'size'   => $size,
        ]);
        $written = $this->record->promoteBackgroundThumbnails(
            $recordId, $fieldId, $srcPath, $parentData, $schemaVirtual
        );

        // Delete bgproc entry from the database
        $this->bgproc->remove($token);

        echo "finished: $token" . ($written ? ' -> ' . implode(', ', $written) : '') . "\n";
    }

    /**
     * Mark the process as failed and store the cause in `message`.
     * The entry remains in `bgproc` - the backend shows it, and the
     * error does not get lost silently.
     */
    private function fail(string $token, string $errorCode, ?int $percent = null): void
    {
        $msg = json_encode(['error' => $errorCode], JSON_UNESCAPED_UNICODE) ?? '{}';
        if ($percent === null) {
            $this->bgproc->setMessageByToken($token, $msg);
        } else {
            $this->bgproc->setProgressByToken($token, $percent, $msg);
        }
        $this->bgproc->setStatusByToken($token, BackgroundProcess::STATUS_FAILED);
        echo "error code: $errorCode\n";
    }

    /**
     * Determine the processor instance for a `bgproc` entry.
     *
     * @param array $parts  decoded `bgproc`.`message`
     */
    private function processor(array $parts): ?MediaProcessor
    {
        $name = (string)($parts['proc'] ?? '');
        // Entries from the time before 'proc', or from processors that do not pass on the name:
        // for the type the usual suspects remain
        if ($name === '') $name = static::DEFAULT_PROCESSOR;
        if ($name === '') return null;

        try {
            $processor = $this->factory->create($name);
        } catch (\RuntimeException $e) {
            // component uninstalled while its process was running
            echo "processor not found: $name\n";
            return null;
        }

        return ($processor instanceof MediaProcessor) ? $processor : null;
    }

    /**
     * Remove progress files for which there is no entry in `bgproc` any more.
     * The age limit protects files whose process is only just starting and is
     * not yet registered.
     */
    private function cleanupOrphans(string $tmpDir, array $knownFiles, int $now): void
    {
        $pattern = $tmpDir . DIRECTORY_SEPARATOR . static::PROGRESS_PREFIX . '*' . static::PROGRESS_EXTENSION;
        foreach (glob($pattern) ?: [] as $file) {
            if (isset($knownFiles[basename($file)])) continue;
            if (($now - (int)@filemtime($file)) < static::ORPHAN_HOURS * 3600) continue;
            @unlink($file);
        }
    }

    /**
     * Read the end of a progress file.
     *
     * Progress files grow continuously; for the current state the end is sufficient.
     * Child classes build their readProgress() evaluation on it.
     *
     * @return string  content, or '' if the file is missing or empty
     */
    protected function readTail(string $file): string
    {
        if (!is_file($file) || !is_readable($file)) return '';

        $size = (int)@filesize($file);
        if ($size <= 0) return '';

        $handle = @fopen($file, 'rb');
        if ($handle === false) return '';

        if ($size > static::TAIL_BYTES) fseek($handle, -static::TAIL_BYTES, SEEK_END);
        $tail = (string)stream_get_contents($handle);
        fclose($handle);

        return $tail;
    }

    /**
     * File name of the temporary progress file for a token.
     */
    protected function progressFileName(string $token): string
    {
        // Token comes from the database and is therefore reduced - in accordance with Crypt::randomKey() -
        // to the necessary characters.
        $token = (string)preg_replace('/[^A-Za-z0-9_-]/', '', $token);
        return static::PROGRESS_PREFIX . $token . static::PROGRESS_EXTENSION;
    }
}
