<?php
/**
 * Trigger for background processes of type 'ffmpeg'.
 *
 * Serves all processors that call ffmpeg - FFmpegVideo, FFmpegAudio and
 * FFmpegImage. Which of them may read a finished file is decided not by this
 * trigger but by the 'proc' entry in `bgproc`.`message`, see TriggerMedia.
 *
 * Progress format: ffmpeg writes continuously with "-progress FILE" blocks of
 * "key=value" lines. A block ends with "progress=continue", the last with
 * "progress=end".
 *
 * @see Camindo\Core\cron\TriggerMedia  common flow of all media triggers
 */
namespace Camindo\Core\cron;

class TriggerFFmpeg extends TriggerMedia {

    protected const BGPROC_TYPE       = 'ffmpeg';
    protected const DEFAULT_PROCESSOR = 'FFmpegVideo';

    protected const PROGRESS_PREFIX    = 'ffmpeg-';
    protected const PROGRESS_EXTENSION = '.progress';

    /** A progress block is a few hundred bytes in size */
    protected const TAIL_BYTES = 8192;

    /**
     * Read progress from the ffmpeg progress file.
     *
     * Preferably via the frame count, because it is exact. Streams without nb_frames and
     * without a usable frame rate (and audio tracks that have no frames at all) fall
     * back to the time axis.
     */
    protected function readProgress(string $file, array $parts, int $previous): ?array
    {
        $state = $this->readProgressBlock($file);
        if ($state === null) return null;

        $percent     = $previous;
        $totalFrames = (int)($parts['frames'] ?? 0); // was determined using ffprobe
        $frame       = (int)($state['frame'] ?? 0);  // from the progress file

        if ($totalFrames > 0 && $frame > 0) {
            $percent = (int)floor($frame * 100 / $totalFrames);
        } else {
            // fallback: time axis instead of frames
            $totalSeconds = (float)($parts['dur'] ?? 0);
            $doneSeconds  = $this->elapsedSeconds($state);

            if ($totalSeconds > 0 && $doneSeconds > 0) {
                $percent = (int)floor($doneSeconds * 100 / $totalSeconds);
            }
            // else: $percent stays as it is
        }

        return [
            'percent'  => $percent,
            'finished' => ($state['progress'] ?? '') === 'end',
        ];
    }

    /**
     * Reads the last lines of an ffmpeg progress file and returns the values of the
     * most recent block.
     *
     * It is enough to read the end of the file and keep the last value per key.
     *
     * @return array|null ['frame' => '4200', 'speed' => '1.5x', 'progress' => 'continue', ...]
     *                    or null if the file is missing or still empty
     */
    private function readProgressBlock(string $file): ?array
    {
        $tail = $this->readTail($file);
        if ($tail === '') return null;

        $values = [];
        $lines = preg_split('/\r?\n/', $tail) ?: [];
        if (count($lines) > 1 && (int)@filesize($file) > self::TAIL_BYTES) {
            // the first line could be incomplete because of the jump to the end of the file
            array_shift($lines);
        }
        foreach ($lines as $line) {
            if (!str_contains($line, '=')) continue;
            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);
            if ($key === '') continue;
            // The last value per key wins; that is the most recent block
            $values[$key] = $value;
        }

        return $values === [] ? null : $values;
    }

    /**
     * Read the already processed run time in seconds from a progress block.
     *
     * out_time_us only exists from ffmpeg 5; out_time_ms is, despite its name,
     * also given in microseconds; out_time is the plain text version.
     */
    private function elapsedSeconds(array $state): float
    {
        if (isset($state['out_time_us']) && is_numeric($state['out_time_us'])) {
            return ((float)$state['out_time_us']) / 1000000.0;
        }
        if (isset($state['out_time_ms']) && is_numeric($state['out_time_ms'])) {
            return ((float)$state['out_time_ms']) / 1000000.0;
        }
        if (isset($state['out_time']) && preg_match('/^(\d+):(\d{2}):(\d{2}(\.\d+)?)$/', (string)$state['out_time'], $match)) {
            return ((int)$match[1]) * 3600 + ((int)$match[2]) * 60 + (float)$match[3];
        }

        return 0.0;
    }
}
