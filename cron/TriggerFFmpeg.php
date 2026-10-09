<?php
/**
 * Trigger für Hintergrundprozesse des Typs 'ffmpeg'.
 *
 * Bedient alle Prozessoren, die ffmpeg aufrufen - FFmpegVideo, FFmpegAudio und
 * FFmpegImage. Welcher davon eine fertige Datei auslesen darf, entscheidet nicht dieser
 * Trigger, sondern der 'proc'-Eintrag in `bgproc`.`message`, siehe TriggerMedia.
 *
 * Fortschrittsformat: ffmpeg schreibt mit "-progress DATEI" fortlaufend Blöcke aus
 * "schlüssel=wert"-Zeilen. Ein Block endet mit "progress=continue", der letzte mit
 * "progress=end".
 *
 * @see Camindo\Core\cron\TriggerMedia  gemeinsamer Ablauf aller Medien-Trigger
 */
namespace Camindo\Core\cron;

class TriggerFFmpeg extends TriggerMedia {

    protected const BGPROC_TYPE       = 'ffmpeg';
    protected const DEFAULT_PROCESSOR = 'FFmpegVideo';

    protected const PROGRESS_PREFIX    = 'ffmpeg-';
    protected const PROGRESS_EXTENSION = '.progress';

    /** Ein Fortschrittsblock ist wenige hundert Bytes groß */
    protected const TAIL_BYTES = 8192;

    /**
     * Fortschritt aus der ffmpeg-Fortschrittsdatei lesen.
     *
     * Bevorzugt über die Framezahl, weil sie exakt ist. Streams ohne nb_frames und
     * ohne verwertbare Bildrate (und Tonspuren, die gar keine Frames haben) fallen
     * auf die Zeitachse zurück.
     */
    protected function readProgress(string $file, array $parts, int $previous): ?array
    {
        $state = $this->readProgressBlock($file);
        if ($state === null) return null;

        $percent     = $previous;
        $totalFrames = (int)($parts['frames'] ?? 0); // wurde mittels ffprobe ermittelt
        $frame       = (int)($state['frame'] ?? 0);  // aus der Fortschrittsdatei

        if ($totalFrames > 0 && $frame > 0) {
            $percent = (int)floor($frame * 100 / $totalFrames);
        } else {
            // Rückfallebene: Zeitachse statt Frames
            $totalSeconds = (float)($parts['dur'] ?? 0);
            $doneSeconds  = $this->elapsedSeconds($state);

            if ($totalSeconds > 0 && $doneSeconds > 0) {
                $percent = (int)floor($doneSeconds * 100 / $totalSeconds);
            }
            // else: $percent bleibt stehen
        }

        return [
            'percent'  => $percent,
            'finished' => ($state['progress'] ?? '') === 'end',
        ];
    }

    /**
     * Liest die letzten Zeilen einer ffmpeg-Fortschrittsdatei und gibt die Werte des
     * jüngsten Blocks zurück.
     *
     * Es genügt, das Dateiende zu lesen und den letzten Wert je Schlüssel zu behalten.
     *
     * @return array|null ['frame' => '4200', 'speed' => '1.5x', 'progress' => 'continue', ...]
     *                    oder null, wenn die Datei fehlt oder noch leer ist
     */
    private function readProgressBlock(string $file): ?array
    {
        $tail = $this->readTail($file);
        if ($tail === '') return null;

        $values = [];
        $lines = preg_split('/\r?\n/', $tail) ?: [];
        if (count($lines) > 1 && (int)@filesize($file) > self::TAIL_BYTES) {
            // erste Zeile könnte wegen des Sprungs ans Dateiende unvollständig sein
            array_shift($lines);
        }
        foreach ($lines as $line) {
            if (!str_contains($line, '=')) continue;
            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);
            if ($key === '') continue;
            // Der letzte Wert je Schlüssel gewinnt, das ist der jüngste Block
            $values[$key] = $value;
        }

        return $values === [] ? null : $values;
    }

    /**
     * Bereits verarbeitete Laufzeit in Sekunden aus einem Fortschrittsblock lesen.
     *
     * out_time_us gibt es erst ab ffmpeg 5, out_time_ms ist trotz des Namens
     * ebenfalls in Mikrosekunden angegeben, out_time ist die Klartextfassung.
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
