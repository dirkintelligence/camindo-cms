<?php
/**
 * Basisklasse der Medien-Trigger.
 *
 * Ein Medien-Prozessor kann seine Umwandlung als Hintergrundprozess starten
 * (Konverter-Einstellung background_process, oder erzwungen, wenn schon die Quelldatei
 * im Hintergrund entsteht). Der Webrequest ist dann längst beendet, wenn das Kommando
 * durchläuft. Ein Trigger holt das Ergebnis ab: er verfolgt den Fortschritt, greift bei
 * Zeitüberschreitung ein und trägt die endgültigen Dateiparameter nach.
 *
 * DREI ARTEN VON WISSEN
 * --------------------
 * Diese Klasse trennt sie bewusst, weil sie unterschiedlich schnell wachsen:
 *
 *   1. FORTSCHRITTSPROTOKOLL  -> Kindklasse dieser Basisklasse
 *      Wie die laufende Umwandlung ihren Stand meldet. Das hängt am Kommandozeilen-
 *      werkzeug, nicht am Prozessor: alle drei FFmpeg-Prozessoren schreiben dieselben
 *      "schluessel=wert"-Blöcke, magick schreibt "50% complete". Deshalb gibt es
 *      genau einen Trigger je BGPROC_TYPE, nicht einen je Prozessor.
 *
 *   2. DATEI-METADATEN        -> MediaProcessor::rectifyParams()
 *      Was in der fertigen Datei steht (Abmessungen, Größe, Mime-Typ). Das weiß nur
 *      der Prozessor, der sie erzeugt hat - eine Tonspur hat keine Bildbreite.
 *
 *   3. PERSISTENZ             -> VirtualFieldsTrait::promoteBackgroundThumbnails()
 *      Wie aus Metadaten `data_fil`-Zeilen werden und wie die Thumbnail-Kette
 *      weiterläuft. Das ist Sache des Datensatzes und für alle Medien gleich.
 *
 * WELCHER PROZESSOR WAR ES?
 * --------------------
 * Aus `bgproc`.`type` allein lässt sich das nicht ableiten - FFmpegVideo, FFmpegAudio
 * und FFmpegImage teilen sich 'ffmpeg'. MediaProcessor::startBackgroundProcess() legt
 * seinen Namen deshalb als 'proc' in `bgproc`.`message` ab, hier wird er wieder
 * ausgelesen und über die ProcessorFactory zur Instanz. Ältere Einträge ohne 'proc'
 * fallen auf DEFAULT_PROCESSOR zurück.
 *
 * MEHRSTUFIGE KETTEN
 * --------------------
 * Ein Thumbnail kann selbst Quelle weiterer Thumbnails sein. Solange die Quelldatei
 * noch entsteht, kann das Folge-Thumbnail nicht erzeugt werden - es entsteht erst hier,
 * wieder als Hintergrundprozess. Eine Kette arbeitet sich also Cron-Lauf für Cron-Lauf
 * vor, je Stufe ein Durchgang.
 *
 * EIGENEN TRIGGER ANLEGEN
 * --------------------
 * Nur nötig, wenn ein Werkzeug seinen Fortschritt in einem NEUEN Format meldet:
 *   - Kindklasse mit BGPROC_TYPE, PROGRESS_PREFIX und DEFAULT_PROCESSOR
 *   - readProgress() implementieren
 *   - Startskript nach dem Muster von cron/trigger_ff.php, Aufruf in TriggerCron
 * Ein neuer Prozessor, der ein vorhandenes Format benutzt, braucht hier gar nichts:
 * er setzt BGPROC_TYPE auf den passenden Wert und implementiert rectifyParams().
 *
 * @see Camindo\Core\MediaProcessor                                  convert(), rectifyParams()
 * @see Camindo\Core\VirtualFieldsTrait::promoteBackgroundThumbnails Fortsetzung der Kette
 * @see Camindo\Core\BackgroundProcess                               Zustand in `bgproc`
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

    /** Hintergrundprozess-Typ, Spalte `bgproc`.`type` */
    protected const BGPROC_TYPE = '';

    /** Prozessor für Einträge ohne 'proc' in `message` (Kurzname, z. B. 'FFmpegVideo') */
    protected const DEFAULT_PROCESSOR = '';

    /** Präfix und Endung der Fortschrittsdatei, Dateiname enthält das Token */
    protected const PROGRESS_PREFIX    = '';
    protected const PROGRESS_EXTENSION = '.progress';

    /** Wie lange verwaiste Fortschrittsdateien liegen bleiben dürfen (Stunden) */
    protected const ORPHAN_HOURS = 24;

    /** Wie viele Bytes vom Ende der Fortschrittsdatei gelesen werden */
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
     * Stand eines laufenden Prozesses aus seiner Fortschrittsdatei lesen.
     *
     * Einziger Teil, den eine Kindklasse beisteuern muss.
     *
     * @param string $file      Pfad zur Fortschrittsdatei
     * @param array  $parts     Dekodiertes `bgproc`.`message` (z. B. 'frames', 'dur')
     * @param int    $previous  Zuletzt gespeicherter Fortschritt, Rückfallwert
     * @return array|null       ['percent' => int (0-100), 'finished' => bool] oder null,
     *                          wenn die Datei fehlt oder noch nichts Verwertbares enthält
     */
    abstract protected function readProgress(string $file, array $parts, int $previous): ?array;

    /**
     * Alle Hintergrundprozesse dieses Typs abarbeiten.
     */
    public function check(): void
    {
        // Keine parallelen Durchläufe erlauben.
        // Die Sperre gilt für den GESAMTEN Lauf und wird ausschließlich im finally
        // wieder freigegeben - nicht innerhalb der Schleife: nach dem Ende eines
        // Prozesses folgt mit rectifyParams() und promoteBackgroundThumbnails() die
        // eigentliche Arbeit, inklusive Start weiterer Hintergrundprozesse. Würde die
        // Sperre vorher fallen, könnte der im Minutentakt laufende Cron parallel
        // dieselben Tokens finalisieren und Prozesse doppelt starten.
        // Die Sperre gilt je Typ: trigger_ff und trigger_magick behindern sich nicht.
        if (!$this->bgproc->lock(static::BGPROC_TYPE)) {
            echo "Backgroundprocess of type=" . static::BGPROC_TYPE . " is already running. exit.\n";
            exit(0); // läuft bereits
        }

        try {
            $now        = time(); // UTC
            $knownFiles = [];
            $tmpDir     = $this->config->get('paths.tmp_dir');

            // list() gleicht nebenbei den gespeicherten mit dem tatsächlichen Prozesszustand ab,
            // die Spalte `status` ist danach also aktuell.
            $processes = $this->bgproc->list();

            foreach ($processes as $row) {
                if (($row['type'] ?? '') !== static::BGPROC_TYPE) continue;

                $token = (string)($row['token'] ?? '');
                // Ohne Token lässt sich weder die Fortschrittsdatei noch der
                // `data_fil`-Eintrag zuordnen
                if ($token === '') continue;

                // Auch beendete Prozesse untersuchen:
                // Solange sie in `bgproc` registriert sind, wurden sie noch nicht finalisiert
                $status   = (int)($row['status'] ?? 0);
                $pid      = (int)($row['pid'] ?? 0);
                $progress = (int)($row['progress'] ?? 0);
                $parts    = json_decode((string)($row['message'] ?? ''), true) ?? [];

                // ---- Fortschritt ermitteln ----
                $progressFile = $tmpDir . DIRECTORY_SEPARATOR . $this->progressFileName($token);
                $knownFiles[basename($progressFile)] = true;

                $state = $this->readProgress($progressFile, $parts, $progress);
                if ($state === null) {
                    // noch keine Ausgabe. Ist der Prozess aber schon weg, kommt auch
                    // keine mehr (z. B. ffmpeg gleich beim Start gescheitert) - sonst
                    // bliebe der Eintrag für immer "beendet, 0 %" stehen
                    if ($status != Execute::STATE_RUNNING) {
                        $this->fail($token, 'error_bgproc_aborted', $progress);
                        @unlink($progressFile);
                        echo "failed (no output): $token\n";
                    }
                    continue;
                }

                $finished = (bool)($state['finished'] ?? false);
                // Solange der Prozess läuft, nie 100 melden: das ist dem Ende vorbehalten
                $percent  = max(0, min(99, (int)($state['percent'] ?? $progress)));

                if (!$finished) {
                    $this->trackRunning($token, $pid, $percent, $status, $parts, $row, $progressFile, $now);
                    continue;
                }

                // ---- Hintergrundprozess erfolgreich fertiggestellt ----
                @unlink($progressFile);
                $this->finalize($token, $parts);
            }

            $this->cleanupOrphans($tmpDir, $knownFiles, $now);

        } catch (\Throwable $e) {
            // Fehler fangen und bei Bedarf loggen oder weiterwerfen.
            // Wichtig ist, dass durch den Catch-Block das finally erreicht wird.
            throw $e;
        } finally {
            // Wird garantiert immer ausgeführt (außer bei fatalen Fehlern oder exit() im try-Block).
            // Einziger Ort, an dem die Sperre aus lock() wieder fällt.
            $this->bgproc->unlock();
        }

        echo "done.\n";
        exit(0);
    }

    /**
     * Noch laufender Prozess: Fortschritt fortschreiben, Abbruch und
     * Zeitüberschreitung behandeln.
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
        // Prüfen, ob der Prozess noch arbeitet
        if ($status != Execute::STATE_RUNNING) {
            // Prozess ist weg, hat aber kein Ende gemeldet: abgebrochen oder
            // abgestürzt. Der Fortschritt bleibt stehen.
            $this->fail($token, 'error_bgproc_aborted', $percent);
            @unlink($progressFile);
            echo "failed: $token\n";
            return;
        }

        // Zeitüberschreitung prüfen
        $timeout   = (int)($parts['timeout'] ?? 0); // aus dem JSON Feld
        $createdAt = strtotime(((string)($row['created_at'] ?? '')) . ' UTC');
        $overdue   = $timeout > 0 && $createdAt > 0 && ($now - $createdAt) > $timeout;
        if ($overdue) {
            // Erst SIGTERM, damit das Werkzeug die Datei noch schließen kann, dann SIGKILL
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
     * Fertiggestellten Prozess abschließen: endgültige Dateiparameter in `data_fil`
     * nachtragen, darauf aufbauende Thumbnails anstoßen, Prozess entfernen.
     */
    private function finalize(string $token, array $parts): void
    {
        // Endgültige Parameter der erstellten Datei ermitteln
        $sql = "SELECT `d`.*, `r`.`area_id` FROM `data_fil` `d` LEFT JOIN `record` `r`"
            . " ON `d`.`record_id` = `r`.`record_id`"
            . " WHERE `d`.`token` = ?";
        $this->db->query($sql, 's', [$token]);

        if (!$fileData = $this->db->fetch()) {
            // File-Eintrag nicht gefunden
            $this->fail($token, 'error_file_not_found');
            return;
        }

        $areaId = (int)($fileData['area_id'] ?? 0);
        if (empty($areaId)) {
            // Datensatz besteht nicht mehr
            $this->fail($token, 'error_record_not_found');
            return;
        }

        // Prozessor, der die Datei erzeugt hat (siehe Klassenkommentar)
        $processor = $this->processor($parts);
        if ($processor === null) {
            $this->fail($token, 'error_processor_not_found');
            return;
        }

        $recordId    = (int)$fileData['record_id'];
        $fieldId     = (int)$fileData['field_id'];
        $fieldNameId = (int)$fileData['fieldname_id'];
        // Sprache der Datei (mehrsprachiges Feld), NULL bei einsprachigen Feldern
        $fileLanguageId = $this->cms->fileLanguageId($fileData);
        // relativer Pfad zur Datei ab Medienverzeichnis
        $srcPath = $this->cms->mediaPath($fieldId, $recordId, $fileLanguageId, (string)$fileData['extension']);

        // Eigenschaften mittels Prozessor-Methode auslesen
        $srcInfo = $processor->rectifyParams($srcPath);
        if (empty($srcInfo['size'])) {
            // Datei-Eigenschaften konnten nicht ermittelt werden
            $this->fail($token, (string)($srcInfo['error'] ?? 'error_file_conversion'));
            return;
        }

        // Dateiparameter in Tabelle `data_fil` eintragen.
        // extension und mime_type bleiben stehen: die kannte der Konverter schon beim
        // Start, und nicht jeder Prozessor kann einen Mime-Typ aus der Datei lesen.
        $width  = (int)($srcInfo['width'] ?? 0);
        $height = (int)($srcInfo['height'] ?? 0);
        $size   = (int)($srcInfo['size'] ?? 0);
        // data enthält alle Infos, die der Prozessor ermitteln konnte
        $srcData = json_encode($srcInfo, JSON_UNESCAPED_UNICODE) ?: '{}';
        // Alle Zeilen, die diese Datei beschreiben: je Sprache eine bei einsprachigen
        // Feldern, bei mehrsprachigen die eigene Zeile plus die Fallback-Zeilen der
        // Sprachen ohne eigene Datei (gleiche file_language_id)
        $sql = "UPDATE `data_fil` SET `token` = '', `width` = ?, `height` = ?, `size` = ?,"
            . " `data` = ? WHERE `record_id` = ? AND `fieldname_id` = ? AND `file_language_id` <=> ?";
        $this->db->query($sql, 'iiisiii', [$width, $height, $size, $srcData, $recordId, $fieldNameId, $fileLanguageId]);

        // Inhalts-Cache verwerfen: Cms::fetch() baut ihn beim nächsten Zugriff neu auf
        $this->db->query("DELETE FROM `record_cache` WHERE `record_id` = ?", 'i', [$recordId]);

        // Diesen Prozess als erfolgreich beendet markieren
        $this->bgproc->setProgressByToken($token, 100, '{}');
        $this->bgproc->setStatusByToken($token, BackgroundProcess::STATUS_FINISHED);

        // Thumbnails erzeugen, die auf dieser Datei aufbauen, und ihre `data_fil`-
        // Einträge anlegen. Die frisch ermittelten Abmessungen gewinnen, alles Übrige
        // (mime_type, extension, filename) steht im vorhandenen Eintrag.
        $schemaVirtual = $this->record->virtualSchema($areaId, false);
        $parentData = array_merge($fileData, [
            'width'  => $width,
            'height' => $height,
            'size'   => $size,
        ]);
        $written = $this->record->promoteBackgroundThumbnails(
            $recordId, $fieldId, $srcPath, $parentData, $schemaVirtual
        );

        // Bgproc-Eintrag aus der Datenbank löschen
        $this->bgproc->remove($token);

        echo "finished: $token" . ($written ? ' -> ' . implode(', ', $written) : '') . "\n";
    }

    /**
     * Prozess als fehlgeschlagen markieren und die Ursache in `message` hinterlegen.
     * Der Eintrag bleibt in `bgproc` stehen - das Backend zeigt ihn an, und der
     * Fehler geht nicht still verloren.
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
     * Prozessor-Instanz zu einem `bgproc`-Eintrag ermitteln.
     *
     * @param array $parts  Dekodiertes `bgproc`.`message`
     */
    private function processor(array $parts): ?MediaProcessor
    {
        $name = (string)($parts['proc'] ?? '');
        // Einträge aus der Zeit vor 'proc' bzw. von Prozessoren, die den Namen
        // nicht mitgeben: beim Typ bleibt es beim üblichen Verdächtigen
        if ($name === '') $name = static::DEFAULT_PROCESSOR;
        if ($name === '') return null;

        try {
            $processor = $this->factory->create($name);
        } catch (\RuntimeException $e) {
            // Komponente deinstalliert, während ihr Prozess lief
            echo "processor not found: $name\n";
            return null;
        }

        return ($processor instanceof MediaProcessor) ? $processor : null;
    }

    /**
     * Fortschrittsdateien entfernen, zu denen es keinen Eintrag in `bgproc` mehr gibt.
     * Die Altersgrenze schützt Dateien, deren Prozess gerade erst startet und noch
     * nicht registriert ist.
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
     * Das Ende einer Fortschrittsdatei lesen.
     *
     * Fortschrittsdateien wachsen fortlaufend; für den aktuellen Stand genügt das Ende.
     * Kindklassen bauen darauf ihre readProgress()-Auswertung.
     *
     * @return string  Inhalt oder '' , wenn die Datei fehlt oder leer ist
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
     * Dateiname der temporären Fortschrittsdatei zu einem Token.
     */
    protected function progressFileName(string $token): string
    {
        // Token kommt aus der Datenbank und wird daher - entsprechend Crypt::randomKey() -
        // auf nötige Zeichen reduziert.
        $token = (string)preg_replace('/[^A-Za-z0-9_-]/', '', $token);
        return static::PROGRESS_PREFIX . $token . static::PROGRESS_EXTENSION;
    }
}
