<?php
/**
 * Anzeige der Hintergrundprozesse im Kopfbereich des Backends.
 *
 * Einstiegspunkte (Scripte in backend/):
 *
 *     bgproc_list.php   -> bgprocList()   Liste und Zähler aktualisieren
 *     bgproc_abort.php  -> bgprocAbort()  einen Prozess abbrechen
 *
 * Lange laufende Arbeiten - Videokonvertierungen, Massen-Löschungen - wandern in einen
 * eigenen Betriebssystemprozess (BackgroundProcess, Tabelle `bgproc`) und laufen dort
 * weiter, während der Redakteur im Backend weiterarbeitet. Diese Klasse ist der Blick
 * darauf.
 *
 * ZWEI ANTWORTEN AUS EINER METHODE
 * --------------------
 * bgprocList() wird in zwei Situationen gerufen und liefert je nachdem mehr oder
 * weniger:
 *
 *     ohne details=1   nur das Javascript für den Kopfbereich: Zähler im Abzeichen und
 *                      das drehende Symbol, wenn etwas läuft. Das ruft der Timer im
 *                      Sekundentakt, es soll also klein bleiben.
 *     mit  details=1   zusätzlich die aufgeklappte Liste der einzelnen Prozesse.
 *
 * Gezeigt werden nur die Prozesse des angemeldeten Benutzers - die admin_id kommt aus
 * der Session, nicht aus dem Request.
 *
 * STATUS-WERTE in `bgproc`
 * --------------------
 *     0    beendet
 *     1    läuft
 *     > 1  gescheitert (Einzelheiten im JSON-Feld `message`)
 *
 * @see Camindo\Core\BackgroundProcess  Start, Fortschritt, Abbruch
 * @see Camindo\Core\MassDeletion       einer der Auftraggeber
 * @see Core/cron/cron.php               führt den Fortschritt laufender Prozesse nach
 */
namespace Camindo\Core\Modules\Start;

use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\Session;
use Camindo\Core\BackgroundProcess;
use Camindo\Core\Execute;

class StartBgproc {
    public function __construct(
        private Backend $backend,
        private Cms $cms,
        private Execute $execute,
        private BackgroundProcess $bgproc,
        private Session $session,
    )
    {}

    /**
     * Liste und Zähler der Hintergrundprozesse (backend/bgproc_list.php).
     *
     * Ohne details=1 nur das Javascript für Zähler und Symbol, mit details=1 auch die
     * Liste (siehe Klassenkommentar).
     *
     * Die Zeitangabe je Prozess ist gestaffelt: was in den letzten 24 Stunden begonnen
     * hat, wird nur mit Uhrzeit gezeigt, Älteres mit Datum. In der Datenbank stehen die
     * Zeitstempel in UTC, für die Anzeige rechnet utcToUserTZ() sie in die Zeitzone des
     * Benutzers um.
     *
     * Request: details = 0|1
     */
    public function bgprocList(): void
    {
        // Prozesse des Session-Admins laden - Beendete vorher abräumen: die Liste
        // zeigt nur, was läuft oder fehlgeschlagen ist (list() gleicht zuerst den
        // Zustand ab, der Rest wird danach gelöscht)
        $adminId = (int)$this->session->get('admin_id');
        $processes = $this->bgproc->list($adminId);
        if ($this->bgproc->purgeFinishedOfAdmin($adminId) > 0) {
            $processes = array_values(array_filter(
                $processes,
                fn(array $p) => (int)$p['status'] !== BackgroundProcess::STATUS_FINISHED
                    || in_array((string)$p['type'], BackgroundProcess::TRIGGER_TYPES, true)
            ));
        }

        // ---- Liste ausgeben ----
        $details = (int)($_REQUEST['details'] ?? 0);
        if ($details) {
            // Schwelle für "älter als 24 Stunden".
            // Achtung: gmdate() liefert UTC, verglichen wird unten aber mit
            // $createdAt, das bereits in die Benutzer-Zeitzone umgerechnet ist.
            // Bei einer Abweichung von der UTC verschiebt sich die Grenze um
            // genau diesen Versatz. Folgenlos - es geht nur um die Wahl zwischen
            // Uhrzeit und Datum in der Anzeige.
            $before24h = gmdate('Y-m-d H:i:s', time() - 24 * 3600);
            $items = [];
            foreach ($processes as $process) {
                // Datum liegt in UTC-Zeit in der Datenbank
                $createdAt = $this->cms->utcToUserTZ($process['created_at']);
                // Älter als 24 Stunden? Dann mit Datum angzeigen
                if ($createdAt <= $before24h) {
                    $date = $this->cms->_formatDate(substr($createdAt, 0, 16), false);
                }
                else {
                    $date = $this->cms->_formatTime(substr($createdAt, 11, 5));
                }
                // Fortschritt in %
                $progress = (int)($process['progress'] ?? 0);
                // Fehler?
                $status = (int)($process['status'] ?? 0);
                $class = 'success';
                $tooltip = '';
                // status
                //     0: finished
                //     1: running
                //     > 1: failed
                if ($status > 1) {
                    $class = 'danger';
                    $message = (array)(json_decode($process['message'], true) ?? []);
                    $tooltip = (string)($message['error'] ?? '');
                }
                $tplItem = $tooltip !== '' ? 'bgproc_list_item_tt' : 'bgproc_list_item';
                $items[] = $this->backend->template($tplItem, [
                '{date}' => $date,
                '{type}' => $process['type'],
                '{token}' => $process['token'],
                '{progress}' => $progress,
                '{class}' => $class,
                '{tooltip}' => htmlspecialchars($tooltip),
            ]);
            }
            if (empty($items)) $items = [$this->backend->template('bgproc_list_none')];
            $itemsHtml = implode("\n", $items);
            echo $this->backend->template('bgproc_list', [
                '{items}' => $itemsHtml
            ]);
        }

        // ---- Icon aktualisieren----
        // Das Abzeichen zählt nur laufende Prozesse und verschwindet bei 0;
        // Fehlgeschlagene und noch nicht abgeräumte Beendete (TRIGGER_TYPES)
        // stehen nur in der Liste
        $runningCount = 0;
        $failed = 0;
        foreach ($processes as $process) {
            if ((int)$process['status'] === BackgroundProcess::STATUS_RUNNING) $runningCount++;
            if ((int)$process['status'] > BackgroundProcess::STATUS_RUNNING) $failed++;
        }
        $running = $runningCount > 0;
        $count = min($runningCount, 99);
        if ($running) {
            $script = ["\$('#c9-bgproc-btn .icon').addClass('c9-spinning');"];
        }
        else {
            $script = ["\$('#c9-bgproc-btn .icon').removeClass('c9-spinning');"];
        }
        $script[] = "\$('#c9-bgproc-btn .c9-badge small').text({$count});";
        $script[] = "\$('#c9-bgproc-btn .c9-badge').toggle(" . ($running ? 'true' : 'false') . ");";
        // Überwachung im Browser: weiter, solange etwas läuft; die Meldung
        // "abgeschlossen" kommt, wenn nichts mehr läuft (cApp.polling)
        $script[] = "cApp.polling.bgprocState(" . ($running ? 'true' : 'false') . ", {$failed});";
        echo '<script' . $this->backend->nonceAttr() . '>' . implode("\n", $script) . "</script>";
    }

    /**
     * Einen Hintergrundprozess abbrechen (backend/bgproc_abort.php).
     *
     * Der Token identifiziert den Prozess und wird hart auf [a-z0-9_-] gefiltert -
     * er geht in ein Betriebssystem-Kommando ein.
     *
     * BackgroundProcess::abort() kehrt erst zurück, wenn der Prozess tatsächlich
     * beendet ist, längstens nach drei Sekunden. Die Antwort ist nur eine
     * Meldungs-Blase.
     *
     * Request: token
     */
    public function bgprocAbort(): void
    {
        // Abbrechen ist ein Seiteneffekt - nur per POST (17-csrf.md §2.7)
        if (!$this->backend->requirePost()) {
            echo $this->backend->access()->deniedHtml();
            return;
        }

        $msgSuccess = $this->cms->snippet('start.message_bgproc_aborted');
        $msgError = $this->cms->snippet('start.message_bgproc_error');

        $rawToken = (string)($_REQUEST['token'] ?? '');
        $token = preg_replace('/[^a-z0-9_\\-]/i', '', $rawToken ?? '');
        // stopProcess wartet bis Prozess beendet ist oder 3 Sek Timeout verstrichen sind
        if ($this->bgproc->abort($token)) {
            // Prozess wurde gestoppt
            $this->backend->balloon('danger', $msgSuccess);
        }
        else {
            // Prozess konnte nicht gestoppt werden
            $this->backend->balloon('danger', $msgError);
        }
    }
}