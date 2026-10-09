<?php
/**
 * Entscheidet, ob eine Massen-Löschung im Web-Request oder im Hintergrund läuft.
 *
 * Seit der Umstellung auf Einzelverarbeitung schickt Dataset::delete() jeden betroffenen
 * Datensatz einzeln durch softDelete() - das ist wartbar und lässt die Hooks laufen,
 * dauert aber je Datensatz spürbar länger als ein Massen-UPDATE. Bei einem
 * Inhaltsbereich mit vielen tausend Datensätzen würde der Request hängen.
 *
 * Diese Klasse ist die eine Stelle, an der die Grenze gezogen wird:
 *
 *     $count = $massDeletion->affectedCount('area', $areaId, $withChilds, 'delete');
 *     if ($massDeletion->needsBackground($count)) {
 *         $massDeletion->start('area', $areaId, 'delete', $withChilds);   // asynchron
 *     } else {
 *         $area->delete($areaId, $withChilds);                            // sofort
 *     }
 *
 * WICHTIG: Im Hintergrund läuft EXAKT DERSELBE Code - Core/cron/mass_delete.php ruft
 * dieselbe Dataset-Methode auf. Der Hintergrundprozess verlagert die Arbeit nur, er
 * ist kein zweiter Löschpfad.
 *
 * Der Schwellwert steht in admin.mass_delete_threshold (Standard 200).
 * 0 schaltet die Auslagerung ab - dann läuft alles im Request.
 *
 * Fortschritt und Abbruch laufen über die vorhandene Hintergrundprozess-Anzeige im
 * Backend (Tabelle `bgproc`, cApp.polling).
 *
 * @see Camindo\Core\Dataset            delete() / restore() / purge()
 * @see Camindo\Core\BackgroundProcess  Zustandsverwaltung der Prozesse
 */
namespace Camindo\Core;

class MassDeletion {

    /**
     * Tabellen, für die eine Massen-Löschung ausgelagert werden kann,
     * und die Spalte, über die ihre abhängigen Datensätze hängen.
     *
     * 'area'   -> Datensätze hängen über record.area_id am Bereich
     * 'record' -> Kind-Datensätze hängen über den Nested Set am Elternteil
     */
    private const TABLES = ['area', 'record'];

    public function __construct(
        private Config $config,
        private Database $db,
        private BackgroundProcess $bgproc,
        private Crypt $crypt,
        private Session $session,
        private System $system,
    )
    {
    }

    /**
     * Wie viele Datensätze umfasst der Vorgang?
     *
     * Nur eine Größenordnung für die Schwellwert-Entscheidung, keine exakte Vorschau:
     * gezählt werden die abhängigen Datensätze, nicht der angeklickte selbst.
     *
     * @param string $table       'area' oder 'record'
     * @param int    $id          Id des angeklickten Datensatzes
     * @param bool   $withChilds  Kind-Datensätze einbeziehen? (nur bei 'record')
     * @param string $mode        'delete' | 'restore' | 'purge'
     * @return int
     */
    public function affectedCount(string $table, int $id, bool $withChilds, string $mode = 'delete'): int
    {
        if (!in_array($table, self::TABLES, true) || $id < 1) return 0;

        // Bei 'delete' nur noch nicht markierte, bei 'restore' nur markierte zählen
        $deletedCondition = match ($mode) {
            'delete'  => ' AND `deleted` = 0',
            'restore' => ' AND `deleted` = 1',
            default   => '',
        };

        $this->db->pushResult();
        if ($table === 'area') {
            // Alle Datensätze des Bereichs
            $sql = "SELECT COUNT(*) FROM `record` WHERE `area_id` = ? AND `tmp` = 0" . $deletedCondition;
            $this->db->query($sql, 'i', [$id]);
        }
        elseif (!$withChilds) {
            // Nur der Datensatz selbst - nie ein Fall für den Hintergrund
            $this->db->popResult();
            return 1;
        }
        else {
            // Teilbaum über lft/rgt. Die Grenzen gelten je Inhaltsbereich - dort
            // beginnt lft jeweils wieder bei 1 (siehe TreeManagerTrait), `area_id`
            // muss deshalb mitgefiltert werden. Datensätze aus Bereichen OHNE Baum
            // haben lft = rgt = 0 und damit keinen Teilbaum: ohne die Prüfung würde
            // die Bedingung auf jeden anderen baumlosen Datensatz zutreffen.
            $this->db->query(
                "SELECT `lft`, `rgt`, `area_id` FROM `record` WHERE `record_id` = ?",
                'i', [$id]
            );
            $row = $this->db->fetch();
            $lft = (int)($row['lft'] ?? 0);
            $rgt = (int)($row['rgt'] ?? 0);
            if ($lft < 1 || $rgt <= $lft) {
                // Kein Teilbaum - nur der Datensatz selbst
                $this->db->popResult();
                return 1;
            }
            $sql = "SELECT COUNT(*) FROM `record`"
                 . " WHERE `area_id` = ? AND `lft` >= ? AND `rgt` <= ?"
                 . " AND `tmp` = 0" . $deletedCondition;
            $this->db->query($sql, 'iii', [(int)$row['area_id'], $lft, $rgt]);
        }
        $count = (int)($this->db->fetchNum()[0] ?? 0);
        $this->db->popResult();

        return $count;
    }

    /**
     * Soll dieser Vorgang in den Hintergrund?
     *
     * @param int $count  Ergebnis von affectedCount()
     */
    public function needsBackground(int $count): bool
    {
        $threshold = (int)($this->config->get('admin.mass_delete_threshold') ?? 200);
        if ($threshold < 1) return false;   // Auslagerung abgeschaltet
        return $count > $threshold;
    }

    /**
     * Vorgang als Hintergrundprozess starten.
     *
     * Der Prozess ruft dieselbe Dataset-Methode auf, die auch der Web-Request nutzen
     * würde - siehe Core/cron/mass_delete.php.
     *
     * @param string $table       'area' oder 'record'
     * @param int    $id          Id des angeklickten Datensatzes
     * @param string $mode        'delete' | 'restore' | 'purge'
     * @param bool   $withChilds  Kind-Datensätze einbeziehen?
     * @param string $error       Grund eines Fehlschlags (per Referenz)
     * @return array|null         ['pid' => int, 'token' => string, 'id' => int] oder null
     */
    public function start(string $table, int $id, string $mode, bool $withChilds, ?string &$error = null): ?array
    {
        $error = '';
        if (!in_array($table, self::TABLES, true) || $id < 1) {
            $error = 'invalid target';
            return null;
        }
        if (!in_array($mode, ['delete', 'restore', 'purge'], true)) {
            $error = 'invalid mode';
            return null;
        }

        $phpExec = (string)$this->config->get('paths.php');
        if ($phpExec === '' || !is_executable($phpExec)) {
            $error = "Error: 'php' is not executable (paths.php)";
            $this->system->log('massdelete-errors', $error);
            return null;
        }

        // Token vorab erzeugen, damit es dem Kommando als Argument mitgegeben werden
        // kann - der Hintergrundprozess meldet darüber seinen Abschluss zurück.
        // (Gleiche Vorgehensweise wie in FFmpegVideo::convert().)
        $token = $this->crypt->randomKey(16);

        $command = [
            $phpExec,
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . 'mass_delete.php',
            '--table=' . $table,
            '--id=' . $id,
            '--mode=' . $mode,
            '--withchilds=' . ($withChilds ? '1' : '0'),
            // Der Hintergrundprozess hat keine Session; die Id des auslösenden
            // Administrators wandert deshalb als Argument mit - sie landet in
            // changed_by der markierten Datensätze.
            '--admin=' . (int)$this->session->get('admin_id'),
            '--token=' . $token,
        ];

        $result = $this->bgproc->start($command, 'massdelete', $token, null, null, $error);
        if ($result === null) {
            $this->system->log('massdelete-errors', "start failed: {$error}");
        }
        return $result;
    }

}
