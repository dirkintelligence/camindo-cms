<?php
/**
 * Werkzeugkasten des Backends - die Seiten unter dem Menüpunkt "Tools".
 *
 * Diese Klasse ist nur das Gerüst: Kopf mit Reitern, Fuß und je Reiter eine Methode,
 * die den eigentlichen Inhalt an eine der Tool-Klassen abgibt. Vier Reiter, vier
 * Einstiegsscripte in backend/:
 *
 *     index.php           -> serverTools()    Serverumgebung, Versionen, phpinfo
 *     index_database.php  -> databaseTools()  Papierkorb leeren, Arbeitskopien verwerfen
 *     tool_delete.php     -> trashPurge()     dito, Papierkorb ausführen
 *     tool_tmp_delete.php -> tmpPurge()       dito, Arbeitskopien ausführen
 *     index_data.php      -> dataTools()      Inhaltswerkzeuge: Thumbnails neu erzeugen
 *     tool_thumbnails.php -> thumbnails()     dito, ausführen / im Hintergrund starten
 *     index_developer.php -> developerTools() Caches neu bauen, Debug
 *
 * $tabs hält Name und Ziel jedes Reiters an einer Stelle - _printHeader() baut die
 * Leiste daraus und markiert den übergebenen als aktiv.
 *
 * REITER DATENBANK
 * --------------------
 * Zwei Sofort-Werkzeuge mit Frist "älter als": Papierkorb leeren (Tage, Vorgabe
 * admin.trash_retention_days) und Arbeitskopien verwerfen (Stunden, Vorgabe 24).
 * 0 heißt: alles. Beide laufen im Request (ToolDatabase); der Normalfall für den
 * Papierkorb bleibt die Zeitplaner-Aufgabe "Papierkorb leeren" (Typ trash).
 *
 * CACHES OHNE BACKEND
 * --------------------
 * Ist das Backend wegen eines fehlenden Snippets oder einer fehlenden Vorlage nicht
 * benutzbar, baut `php Core/cron/camindo.php cache all` beide Caches von der
 * Kommandozeile - tool_snippets.php und tool_html.php verlangen wie alle anderen
 * Scripte eine Anmeldung.
 *
 * @see Camindo\Core\Modules\Tools\ToolServer         Inhalt des Server-Reiters
 * @see Camindo\Core\Modules\Tools\ToolDeveloper      Inhalt des Entwickler-Reiters
 * @see Camindo\Core\Modules\Tools\ToolSnippets       Sprach-Cache
 * @see Camindo\Core\Modules\Tools\ToolHtmlTemplates  HTML-Vorlagen-Cache
 * @see Camindo\Core\Modules\Tools\ToolDatabase       Papierkorb leeren, Arbeitskopien verwerfen
 * @see Camindo\Core\Modules\Tools\ToolThumbnails     Thumbnails neu erzeugen
 */
namespace Camindo\Core\Modules\Tools;

use Camindo\Core\Config;
use Camindo\Core\Backend;
use Camindo\Core\Database;
use Camindo\Core\Cms;
use Camindo\Core\Crypt;
use Camindo\Core\Execute;
use Camindo\Core\Modules\Tools\ToolServer;
use Camindo\Core\Modules\Tools\ToolDeveloper;
use Camindo\Core\Modules\Tools\ToolThumbnails;
use Camindo\Core\Modules\Tools\ToolTree;
use Camindo\Core\Modules\Tools\ToolDatabase;

class ToolsBackend {

    protected int $id = 0;
    protected ?array $data = [];
    protected string $function = '';

    // Reiter der Werkzeugseite: Name => Einstiegsscript. Der Name dient zugleich als
    // Snippet-Schlüssel (#tools.tab_label_{name}).
    protected $tabs = [
        'server' => 'Tools/index.php',
        'database' => 'Tools/index_database.php',
        'data' => 'Tools/index_data.php',
        'developer' => 'Tools/index_developer.php',
        'trash_user' => 'Tools/index_trash_user.php',
    ];

    public function __construct(
        private Config $config,
        private Backend $backend,
        private Database $db,
        private Cms $cms,
        private Crypt $crypt,
        private Execute $execute,
        private ToolServer $toolServer,
        private ToolDeveloper $toolDeveloper,
        private ToolThumbnails $toolThumbnails,
        private ToolTree $toolTree,
        private ToolDatabase $toolDatabase,
    )
    {}

    /** Vorgabe "älter als" für das Verwerfen von Arbeitskopien (Stunden) */
    private const TMP_DEFAULT_HOURS = 24;

    /**
     * Gemeinsamer Seitenkopf mit der Reiterleiste.
     *
     * @param string $activeTab  Schlüssel aus $tabs; dieser Reiter wird als aktiv
     *                           markiert und bekommt keinen Link
     */
    private function _printHeader(string $activeTab):void {
        // Zusatzrecht des Reiters (zweite Sicherung neben Access::script())
        $access = $this->backend->access();
        if (!$access->moduleRight('Tools', $activeTab)) $access->denied();

        // Bread crum navi
        $this->backend->setNaviLabel('#tools.module_label');
        
        // Container header
        echo $this->backend->template('container_header');

        // H1
        echo $this->backend->headline('#tools.headline_tools_list');

        // Tabs - nur die mit Zusatzrecht (settings.php 'access', ein Recht je Reiter)
        $this->backend->tabBarReset();
        foreach ($this->tabs as $tabName => $tabLink) {
            if (!$access->moduleRight('Tools', $tabName)) continue;
            if ($tabName == $activeTab) {
                $this->backend->tabAdd('#tools.tab_label_' . $tabName, '', true);
            }
            else {
                $this->backend->tabAdd('#tools.tab_label_' . $tabName, "cApp.layers.replace('$tabLink');");
            }
        }
        echo $this->backend->tabBar();
        echo "<br>";
    }

    /**
     * Gemeinsamer Seitenfuß.
     */
    private function _printFooter():void {
        echo $this->backend->template('container_footer');
    }

    /**
     * Reiter "Datenbank" (backend/index_database.php): "Papierkorb leeren" und
     * "Arbeitskopien verwerfen", je mit Frist "älter als".
     */
    public function databaseTools(): void
    {
        $this->_printHeader('database');

        $this->_trashForm();
        $this->_tmpForm();

        $this->_printFooter();
    }

    /**
     * "Papierkorb leeren" ausführen (backend/tool_delete.php, POST aus dem Formular
     * des Datenbank-Reiters: days, function=run).
     *
     * Löscht endgültig, was seit mindestens `days` Tagen als gelöscht markiert ist
     * (ToolDatabase::purgeTrash(); 0 = alles). Das Ergebnis steht über dem Formular,
     * das die eingegebene Frist behält. Administratoren im Papierkorb bleiben immer.
     */
    public function trashPurge(): void
    {
        $this->_printHeader('database');

        $days   = max(0, (int)($_REQUEST['days'] ?? $this->toolDatabase->trashRetentionDays()));
        $report = '';
        if ((string)($_REQUEST['function'] ?? '') === 'run') {
            $result = $this->toolDatabase->purgeTrash($days);
            $report = $this->_purgeAlert(
                $this->cms->snippet('tools.trash_summary', ['records' => $result['record'], 'areas' => $result['area']]),
                $result['errors']
            );
        }

        $this->_trashForm($days, $report);
        $this->_tmpForm();

        $this->_printFooter();
    }

    /**
     * Reiter "Papierkorb eines Users" (backend/index_trash_user.php, Zusatzrecht
     * trash_user): Profil-Id eingeben, sein Papierkorb wird sofort geleert.
     */
    public function trashUserTools(): void
    {
        $this->_printHeader('trash_user');
        $this->_trashUserForm();
        $this->_printFooter();
    }

    /**
     * "Papierkorb eines Users leeren" ausführen (backend/tool_trash_user.php, POST:
     * data[profile_id] bzw. trashed_profile_id, function=run) - ToolDatabase::purgeUserTrash().
     */
    public function trashUserPurge(): void
    {
        $this->_printHeader('trash_user');
        // Profil aus dem Lookup bzw. der Auswahl der Profile im Papierkorb
        $profileId = $this->_privacyBackend()->pickedProfileId('profile_id');
        $report = '';
        if ((string)($_REQUEST['function'] ?? '') === 'run' && $this->backend->requirePost()) {
            $result = $this->toolDatabase->purgeUserTrash($profileId);
            $report = $result === null
                ? $this->backend->template('alert_danger', ['{text}' => htmlspecialchars($this->cms->snippet('tools.error_trash_user_profile'))])
                : $this->_purgeAlert($this->cms->snippet('tools.trash_user_summary', ['records' => $result['record']]), $result['errors']);
        }
        $this->_trashUserForm($profileId ?: null, $report);
        $this->_printFooter();
    }

    /** Das Formular "Papierkorb eines Users leeren" */
    private function _trashUserForm(?int $profileId = null, string $report = ''): void
    {
        echo $this->backend->template('tools.trash_user_form', [
            '{picker}' => $this->_privacyBackend()->profilePickerHtml('profile_id', (int)$profileId),
            '{report}' => $report,
        ]);
    }

    /** Auswahl eines Profils (Lookup + Papierkorb) kommt aus dem Modul Privacy */
    private function _privacyBackend(): \Camindo\Core\Modules\Privacy\PrivacyBackend
    {
        return $GLOBALS['app']->get(\Camindo\Core\Modules\Privacy\PrivacyBackend::class);
    }

    /**
     * "Arbeitskopien verwerfen" ausführen (backend/tool_tmp_delete.php, POST aus dem
     * Formular des Datenbank-Reiters: hours, function=run).
     *
     * Entfernt Arbeitskopien (record.tmp = 1), die seit mindestens `hours` Stunden
     * nicht mehr angefasst wurden (ToolDatabase::purgeTmp(); 0 = alle) - samt ihrer
     * hochgeladenen Dateien. Ein Admin, der gerade noch bearbeitet, verliert damit
     * seinen Zwischenstand; deshalb die Rückfrage im Formular.
     */
    public function tmpPurge(): void
    {
        $this->_printHeader('database');

        $hours  = max(0, (int)($_REQUEST['hours'] ?? self::TMP_DEFAULT_HOURS));
        $report = '';
        if ((string)($_REQUEST['function'] ?? '') === 'run') {
            $result = $this->toolDatabase->purgeTmp($hours);
            $report = $this->_purgeAlert(
                $this->cms->snippet('tools.tmp_summary', ['records' => $result['record']]),
                $result['errors']
            );
        }

        $this->_trashForm();
        $this->_tmpForm($hours, $report);

        $this->_printFooter();
    }

    /**
     * Das Formular "Papierkorb leeren": Frist in Tagen, Knopf mit Rückfrage.
     *
     * @param int|null $days    Frist; null: admin.trash_retention_days
     * @param string   $report  Ergebnis des letzten Laufs (HTML) über dem Formular
     */
    private function _trashForm(?int $days = null, string $report = ''): void
    {
        echo $this->backend->template('tools.trash_form', [
            '{days}'   => $days ?? $this->toolDatabase->trashRetentionDays(),
            '{report}' => $report,
        ]);
    }

    /**
     * Das Formular "Arbeitskopien verwerfen": Frist in Stunden, Knopf mit Rückfrage.
     *
     * @param int|null $hours   Frist; null: TMP_DEFAULT_HOURS
     * @param string   $report  Ergebnis des letzten Laufs (HTML) über dem Formular
     */
    private function _tmpForm(?int $hours = null, string $report = ''): void
    {
        echo $this->backend->template('tools.tmp_form', [
            '{hours}'  => $hours ?? self::TMP_DEFAULT_HOURS,
            '{report}' => $report,
        ]);
    }

    /**
     * Meldung über einem Aufräum-Formular: Zusammenfassung, bei gescheiterten
     * Datensätzen als Warnung mit deren Liste (die Details stehen zudem in
     * var/log/{Y-m-d}_trash.log).
     *
     * @param string   $summary  Zusammenfassung des Laufs
     * @param string[] $errors   Meldungen je gescheitertem Datensatz
     */
    private function _purgeAlert(string $summary, array $errors): string
    {
        if (empty($errors)) {
            $this->backend->balloon('success', $this->cms->snippet('completed'));
            return $this->backend->template('alert_success', ['{text}' => htmlspecialchars($summary)]);
        }
        $text = $summary . ' ' . $this->cms->snippet('tools.purge_failed', ['count' => count($errors)])
              . "\n" . implode("\n", $errors);
        return $this->backend->template('alert_warning', ['{text}' => nl2br(htmlspecialchars($text))]);
    }

    /**
     * Reiter "Entwickler" (backend/index_developer.php).
     *
     * Enthält die Knöpfe zum Neubau der beiden Caches und die Session-Anzeige;
     * zusammengestellt wird die Leiste in ToolDeveloper::list().
     */
    public function developerTools():void {

        // header with tabs
        $this->_printHeader('developer');

        $this->toolDeveloper->list();

        // footer
        $this->_printFooter();
    }

    /**
     * Reiter "Server" (backend/index.php) - der Standardreiter.
     *
     * Zeigt die Serverumgebung; zusammengestellt wird sie in ToolServer::list().
     */
    public function serverTools():void {

        // (Ohne das Recht "Server" lässt Access::script() index.php nicht durch; die
        //  Modulkachel der Startseite zeigt dann auf den ersten erlaubten Reiter -
        //  settings.php 'entries', StartBackend::_moduleButtons().)
        $this->_printHeader('server');

        $this->toolServer->list();

        $this->_printFooter();
    }

    /**
     * Reiter "Daten" (backend/index_data.php): "Thumbnails neu erzeugen" und
     * "Baum normalisieren".
     */
    public function dataTools():void {

        $this->_printHeader('data');

        $this->_thumbnailsForm();
        $this->_treeForm();

        $this->_printFooter();
    }

    /**
     * "Thumbnails neu erzeugen" ausführen (backend/tool_thumbnails.php, POST aus
     * dem Formular des Daten-Reiters: area_id, background, function=run).
     *
     * Mit Schalter "Hintergrundprozess" (Vorgabe) startet ToolThumbnails::start()
     * den Lauf in Core/cron/thumbnails.php und die Seite meldet nur den Start; das
     * Ergebnis kommt als interne Nachricht. Sonst läuft ToolThumbnails::run() im
     * Request und das Ergebnis steht über dem Formular.
     */
    public function thumbnails(): void
    {
        $this->_printHeader('data');

        $areaId = (int)($_REQUEST['area_id'] ?? 0);
        $background = !empty($_REQUEST['background']);
        if ((string)($_REQUEST['function'] ?? '') === 'run' && $areaId > 0) {
            if ($background) {
                $error = '';
                if ($this->toolThumbnails->start($areaId, $error) !== null) {
                    $this->backend->balloon('info', $this->cms->snippet('message_background_started'));
                }
                else {
                    echo $this->backend->template('alert_danger', [
                        '{text}' => htmlspecialchars($this->cms->snippet('record.error_batch_start') . ' ' . $error)
                    ]);
                }
            }
            else {
                $result = $this->toolThumbnails->run($areaId);
                if ($result['error'] !== '') {
                    echo $this->backend->template('alert_danger', ['{text}' => htmlspecialchars((string)$result['error'])]);
                }
                else {
                    echo $this->backend->template($result['failed'] > 0 ? 'alert_warning' : 'alert_success', [
                        '{text}' => htmlspecialchars($this->toolThumbnails->summaryText($result))
                    ]);
                }
            }
        }

        $this->_thumbnailsForm($areaId, $background);
        $this->_treeForm();

        $this->_printFooter();
    }

    /**
     * Das Formular: Bereich (nur solche mit Thumbnail-Feldern), Schalter
     * "Hintergrundprozess" (Vorgabe: an), Knopf "Generieren".
     */
    private function _thumbnailsForm(int $areaId = 0, bool $background = true): void
    {
        $areas = $this->toolThumbnails->areaOptions();
        $options = '';
        if (empty($areas)) {
            $options = '<option value="0">' . htmlspecialchars($this->cms->snippet('tools.note_thumbnails_no_area')) . '</option>';
        }
        foreach ($areas as $id => $label) {
            $selected = ($id === $areaId) ? ' selected' : '';
            $options .= '<option value="' . $id . '"' . $selected . '>' . htmlspecialchars($label) . '</option>' . "\n";
        }
        echo $this->backend->template('tools.thumbnails_form', [
            '{options}' => $options,
            '{checked}' => $background ? ' checked' : '',
            '{disabled}' => empty($areas) ? ' disabled' : '',
        ]);
    }

    /**
     * "Baum normalisieren" prüfen / ausführen (backend/tool_tree_normalize.php,
     * POST aus dem Formular des Daten-Reiters: area_id, function=check|run).
     *
     * 'check' ruft nur ToolTree::diagnose() (rein lesend) und zeigt den Befund.
     * 'run' repariert den Baum (ToolTree::normalize()) und zeigt, was behoben wurde.
     *
     * Beide Wege melden IMMER ein Ergebnis - ein Knopfdruck ohne sichtbare Antwort
     * wäre für den Anwender nicht von einem Fehlschlag zu unterscheiden.
     */
    public function treeNormalize(): void
    {
        $this->_printHeader('data');

        $this->_thumbnailsForm();

        $areaId   = (int)($_REQUEST['area_id'] ?? 0);
        $function = (string)($_REQUEST['function'] ?? '');
        $report   = null;
        $before   = null;
        if ($areaId > 0 && $function === 'run') {
            $result = $this->toolTree->normalize($areaId);
            $before = $result['before'];
            $report = $result['after'];
            $this->backend->balloon(
                $before['clean'] ? 'info' : 'success',
                $this->cms->snippet($before['clean'] ? 'tools.tree_note_already_clean' : 'tools.tree_message_normalized')
            );
        }
        elseif ($areaId > 0) {
            // 'check' und jeder andere Aufruf mit Bereich: nur prüfen
            $report = $this->toolTree->diagnose($areaId);
        }

        $this->_treeForm($areaId, $report, $before);

        $this->_printFooter();
    }

    /**
     * Das Formular: Bereich (nur solche mit Baum, ToolTree::areaOptions()), Knöpfe
     * "Prüfen" / "Normalisieren"; bei vorliegendem Befund (ToolTree::diagnose())
     * dessen Anzeige darüber.
     *
     * @param int        $areaId  gewählter Bereich (0: keiner)
     * @param array|null $report  aktueller diagnose()-Befund, null ohne Lauf
     * @param array|null $before  Befund VOR einer Reparatur; null nach reiner Prüfung.
     *                            Er liefert die Liste dessen, was behoben wurde -
     *                            $report ist danach ja unauffällig.
     */
    private function _treeForm(int $areaId = 0, ?array $report = null, ?array $before = null): void
    {
        $areas = $this->toolTree->areaOptions();
        $options = '';
        if (empty($areas)) {
            $options = '<option value="0">' . htmlspecialchars($this->cms->snippet('tools.note_tree_no_area')) . '</option>';
        }
        foreach ($areas as $id => $label) {
            $selected = ($id === $areaId) ? ' selected' : '';
            $options .= '<option value="' . $id . '"' . $selected . '>' . htmlspecialchars($label) . '</option>' . "\n";
        }

        $reportHtml = '';
        if ($report !== null) {
            if ($before === null) {
                // Reine Prüfung
                $reportHtml = $report['clean']
                    ? $this->_treeAlert('alert_success', 'tools.tree_report_clean', [], (int)$report['total'])
                    : $this->_treeAlert('alert_warning', 'tools.tree_report_issues_intro', $report);
            }
            elseif (!$report['clean']) {
                // Nach dem Lauf immer noch auffällig
                $reportHtml = $this->_treeAlert('alert_warning', 'tools.tree_report_still_issues_intro', $report);
            }
            elseif ($before['clean']) {
                $reportHtml = $this->_treeAlert('alert_success', 'tools.tree_note_already_clean');
            }
            else {
                // Behoben wurde das, was VOR dem Lauf auffiel
                $reportHtml = $this->_treeAlert('alert_success', 'tools.tree_report_repaired_intro', $before);
            }
        }

        echo $this->backend->template('tools.tree_form', [
            '{options}' => $options,
            '{disabled}' => empty($areas) ? ' disabled' : '',
            '{report}' => $reportHtml,
        ]);
    }

    /**
     * Meldung über dem Formular "Baum normalisieren".
     *
     * Mit $report wird an den Einleitungssatz die Aufzählung der auffälligen Punkte
     * angehängt ("… 2 doppelt vergebene Positionen; 1 doppelt vergebener lft-Wert.");
     * je nach Aufrufer sind das die gefundenen oder die behobenen.
     *
     * @param string     $template  alert_success | alert_warning
     * @param string     $snippet   Einleitungssatz
     * @param array      $report    diagnose()-Befund, dessen Punkte aufgezählt werden
     * @param int|null   $count     Zähler für ein Plural-Snippet ohne Aufzählung
     */
    private function _treeAlert(string $template, string $snippet, array $report = [], ?int $count = null): string
    {
        $text = $this->cms->snippet($snippet, $count === null ? [] : ['count' => $count]);
        if (!empty($report)) {
            $issues = [];
            foreach (['duplicate_positions', 'unclean_parents', 'invalid_bounds', 'duplicate_lft', 'orphan_parents'] as $key) {
                if (($report[$key] ?? 0) > 0) {
                    $issues[] = $this->cms->snippet('tools.tree_issue_' . $key, ['count' => (int)$report[$key]]);
                }
            }
            if (!($report['range_ok'] ?? true)) {
                $issues[] = $this->cms->snippet('tools.tree_issue_range');
            }
            if (!empty($issues)) $text .= ' ' . implode('; ', $issues) . '.';
        }
        return $this->backend->template($template, ['{text}' => htmlspecialchars($text)]);
    }

}
