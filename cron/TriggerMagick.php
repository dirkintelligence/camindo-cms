<?php
/**
 * Trigger für Hintergrundprozesse des Typs 'magick'.
 *
 * Fortschrittsformat: ImageMagick meldet mit "-monitor" nach stderr, deshalb wird die
 * Fortschrittsdatei beim Start als stderrFile mitgegeben. Die Chunks sehen so aus:
 *
 *     Resize image [out.jpg]: 1500 of 3000, 50% complete
 *
 * Ein Abschluss-Marker fehlt, "100% complete" gilt als fertig.
 *
 * @see Camindo\Core\cron\TriggerMedia  gemeinsamer Ablauf aller Medien-Trigger
 */
namespace Camindo\Core\cron;

class TriggerMagick extends TriggerMedia {

    protected const BGPROC_TYPE       = 'magick';
    protected const DEFAULT_PROCESSOR = 'Magick';

    protected const PROGRESS_PREFIX    = 'magick-';
    protected const PROGRESS_EXTENSION = '.progress';

    /** Eine Fortschrittszeile ist kurz, es genügt das äußerste Ende */
    protected const TAIL_BYTES = 200;

    /**
     * Fortschritt aus der magick-Fortschrittsdatei lesen.
     *
     * magick hängt seine Meldungen fortlaufend an; gesucht wird deshalb die LETZTE
     * Prozentangabe im gelesenen Puffer.
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
