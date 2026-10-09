<?php
/**
 * Trigger for background processes of type 'magick'.
 *
 * Progress format: ImageMagick reports with "-monitor" to stderr, therefore the
 * progress file is passed along at start as stderrFile. The chunks look like this:
 *
 *     Resize image [out.jpg]: 1500 of 3000, 50% complete
 *
 * A completion marker is missing; "100% complete" counts as finished.
 *
 * @see Camindo\Core\cron\TriggerMedia  common flow of all media triggers
 */
namespace Camindo\Core\cron;

class TriggerMagick extends TriggerMedia {

    protected const BGPROC_TYPE       = 'magick';
    protected const DEFAULT_PROCESSOR = 'Magick';

    protected const PROGRESS_PREFIX    = 'magick-';
    protected const PROGRESS_EXTENSION = '.progress';

    /** A progress line is short, the outermost end is sufficient */
    protected const TAIL_BYTES = 200;

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

        if (!preg_match_all('/([0-9]{1,3})%\s+complete/i', $tail, $matches)) return null;

        $percent = (int)end($matches[1]);

        return [
            'percent'  => $percent,
            'finished' => ($percent >= 100),
        ];
    }
}
