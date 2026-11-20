<?php
/**
 * Trigger for background processes of type 'magick'.
 *
 * Progress format: ImageMagick reports with "-monitor" to stderr, therefore the
 * progress file is passed along at start as stderrFile. The chunks look like this:
 *
 *     Resize image [out.jpg]: 1500 of 3000, 50% complete
 *
 * The same format comes from the Imagick worker process (cron/imagick_convert.php),
 * which writes "error: ..." on failure.
 *
 * A completion marker is missing, and every operation (read, resize, write ...) runs up to
 * 100 % on its own - therefore the end of the PROCESS counts as finished (FINISH_ON_EXIT),
 * unless an error line stands in the file ("error: ..." of the worker, "... @ error/..." or "@ fatal/" of
 * magick).
 *
 * @see Camindo\Core\cron\TriggerMedia  common flow of all media triggers
 */
namespace Camindo\Core\cron;

class TriggerMagick extends TriggerMedia {

    protected const BGPROC_TYPE       = 'magick';
    protected const DEFAULT_PROCESSOR = 'Magick';

    protected const PROGRESS_PREFIX    = 'magick-';
    protected const PROGRESS_EXTENSION = '.progress';

    /** A progress line is short; enough for the last lines including an error message */
    protected const TAIL_BYTES = 1024;

    protected const FINISH_ON_EXIT = true;

    /**
     * Read progress from the magick progress file.
     *
     * magick appends its messages continuously; therefore the LAST percentage
     * in the buffer read is searched for.
     */
    protected function readProgress(string $file, array $parts, int $previous): ?array
    {
        $tail = $this->readTail($file);
        if (trim($tail) === '') return null;

        $error = null;
        if (preg_match_all('/^error: (.+)$/m', $tail, $errors)) {
            $error = trim((string)end($errors[1]));
        }
        elseif (preg_match_all('/^.*@ (?:error|fatal)\/.*$/m', $tail, $errors)) {
            $error = trim((string)end($errors[0]));
        }

        if (!preg_match_all('/([0-9]{1,3})%\s+complete/i', $tail, $matches)) {
            return $error === null ? null : ['percent' => $previous, 'finished' => false, 'error' => $error];
        }

        $percent = (int)end($matches[1]);

        return [
            'percent'  => $percent,
            'finished' => ($percent >= 100),
            'error'    => $error,
        ];
    }
}
