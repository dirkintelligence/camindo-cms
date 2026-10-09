<?php
/**
 * Tools panel of the backend - the pages under the menu item "Tools".
 *
 * This class is only the framework: header with tabs, footer and for each tab one method
 * that hands the actual content over to one of the tool classes. Four tabs, four
 * entry scripts in backend/:
 *
 *     index.php           -> serverTools()    server environment, versions, phpinfo
 *     index_database.php  -> databaseTools()  empty trash, discard working copies
 *     tool_delete.php     -> trashPurge()     same, run emptying the trash
 *     tool_tmp_delete.php -> tmpPurge()       same, run discarding working copies
 *     index_data.php      -> dataTools()      content tools: regenerate thumbnails
 *     tool_thumbnails.php -> thumbnails()     same, run / start in the background
 *     index_developer.php -> developerTools() rebuild caches, debug
 *
 * $tabs holds name and target of each tab in one place - _printHeader() builds the
 * bar from it and marks the passed one as active.
 *
 * DATABASE TAB
 * --------------------
 * Two immediate tools with a period "older than": empty trash (days, default
 * admin.trash_retention_days) and discard working copies (hours, default 24).
 * 0 means: everything. Both run in the request (ToolDatabase); the normal case for the
 * trash remains the scheduler task "Empty trash" (type trash).
 *
 * CACHES WITHOUT BACKEND
 * --------------------
 * If the backend is unusable due to a missing snippet or a missing template,
 * `php Core/cron/camindo.php cache all` builds both caches from the
 * command line - tool_snippets.php and tool_html.php, like all other
 * scripts, require a login.
 *
 * @see Camindo\Core\Modules\Tools\ToolServer         content of the Server tab
 * @see Camindo\Core\Modules\Tools\ToolDeveloper      content of the Developer tab
 * @see Camindo\Core\Modules\Tools\ToolSnippets       language cache
 * @see Camindo\Core\Modules\Tools\ToolHtmlTemplates  HTML template cache
 * @see Camindo\Core\Modules\Tools\ToolDatabase       empty trash, discard working copies
 * @see Camindo\Core\Modules\Tools\ToolThumbnails     regenerate thumbnails
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

    // Tabs of the tools page: name => entry script. The name also serves as
    // snippet key (#tools.tab_label_{name}).
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

    /** Default "older than" for discarding working copies (hours) */
    private const TMP_DEFAULT_HOURS = 24;

    /**
     * Common page header with the tab bar.
     *
     * @param string $activeTab  key from $tabs; this tab is marked as active
     *                           and gets no link
     */
    private function _printHeader(string $activeTab):void {
        // Additional right of the tab (second safeguard next to Access::script())
        $access = $this->backend->access();
        if (!$access->moduleRight('Tools', $activeTab)) $access->denied();

        // Bread crum navi
        $this->backend->setNaviLabel('#tools.module_label');
        
        // Container header
        echo $this->backend->template('container_header');

        // H1
        echo $this->backend->headline('#tools.headline_tools_list');

        // Tabs - only those with the additional right (settings.php 'access', one right per tab)
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
     * Common page footer.
     */
    private function _printFooter():void {
        echo $this->backend->template('container_footer');
    }

    /**
     * Tab "Database" (backend/index_database.php): "Empty trash" and
     * "Discard working copies", each with a period "older than".
     */
    public function databaseTools(): void
    {
        $this->_printHeader('database');

        $this->_trashForm();
        $this->_tmpForm();

        $this->_printFooter();
    }

    /**
     * Run "Empty trash" (backend/tool_delete.php, POST from the form
     * of the Database tab: days, function=run).
     *
     * Permanently deletes what has been marked as deleted for at least `days` days
     * (ToolDatabase::purgeTrash(); 0 = everything). The result stands above the form,
     * which keeps the entered period. Administrators in the trash always remain.
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
     * Tab "Trash of a user" (backend/index_trash_user.php, additional right
     * trash_user): enter profile ID, its trash is emptied immediately.
     */
    public function trashUserTools(): void
    {
        $this->_printHeader('trash_user');
        $this->_trashUserForm();
        $this->_printFooter();
    }

    /**
     * Run "Empty the trash of a website user" (backend/tool_trash_user.php, POST:
     * data[profile_id] resp. trashed_profile_id, function=run) - ToolDatabase::purgeUserTrash().
     */
    public function trashUserPurge(): void
    {
        $this->_printHeader('trash_user');
        // Profile from the lookup resp. the choice of profiles in the trash
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

    /** The form "Empty the trash of a website user" */
    private function _trashUserForm(?int $profileId = null, string $report = ''): void
    {
        echo $this->backend->template('tools.trash_user_form', [
            '{picker}' => $this->_privacyBackend()->profilePickerHtml('profile_id', (int)$profileId),
            '{report}' => $report,
        ]);
    }

    /** Choice of a profile (lookup + trash) comes from the module Privacy */
    private function _privacyBackend(): \Camindo\Core\Modules\Privacy\PrivacyBackend
    {
        return $GLOBALS['app']->get(\Camindo\Core\Modules\Privacy\PrivacyBackend::class);
    }

    /**
     * Run "Discard working copies" (backend/tool_tmp_delete.php, POST from the
     * form of the Database tab: hours, function=run).
     *
     * Removes working copies (record.tmp = 1) that have not been touched for at least
     * `hours` hours (ToolDatabase::purgeTmp(); 0 = all), including their uploaded files.
     * An admin who is still editing loses the interim state with it;
     * hence the confirmation in the form.
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
     * The form "Empty trash": period in days, button with confirmation.
     *
     * @param int|null $days    period; null: admin.trash_retention_days
     * @param string   $report  result of the last run (HTML) above the form
     */
    private function _trashForm(?int $days = null, string $report = ''): void
    {
        echo $this->backend->template('tools.trash_form', [
            '{days}'   => $days ?? $this->toolDatabase->trashRetentionDays(),
            '{report}' => $report,
        ]);
    }

    /**
     * The form "Discard working copies": period in hours, button with confirmation.
     *
     * @param int|null $hours   period; null: TMP_DEFAULT_HOURS
     * @param string   $report  result of the last run (HTML) above the form
     */
    private function _tmpForm(?int $hours = null, string $report = ''): void
    {
        echo $this->backend->template('tools.tmp_form', [
            '{hours}'  => $hours ?? self::TMP_DEFAULT_HOURS,
            '{report}' => $report,
        ]);
    }

    /**
     * Message above a cleanup form: summary, with a warning for failed records
     * and their list (the details are also in
     * var/log/{Y-m-d}_trash.log).
     *
     * @param string   $summary  summary of the run
     * @param string[] $errors   messages per failed record
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
     * Tab "Developer" (backend/index_developer.php).
     *
     * Contains the buttons for rebuilding both caches and the session display;
     * the bar is assembled in ToolDeveloper::list().
     */
    public function developerTools():void {

        // header with tabs
        $this->_printHeader('developer');

        $this->toolDeveloper->list();

        // footer
        $this->_printFooter();
    }

    /**
     * Tab "Server" (backend/index.php) - the default tab.
     *
     * Shows the server environment; it is assembled in ToolServer::list().
     */
    public function serverTools():void {

        // (Without the right "Server", Access::script() does not let index.php through; the
        //  module tile on the start page then points to the first permitted tab -
        //  settings.php 'entries', StartBackend::_moduleButtons().)
        $this->_printHeader('server');

        $this->toolServer->list();

        $this->_printFooter();
    }

    /**
     * Tab "Data" (backend/index_data.php): "Regenerate thumbnails" and
     * "Normalize tree".
     */
    public function dataTools():void {

        $this->_printHeader('data');

        $this->_thumbnailsForm();
        $this->_treeForm();

        $this->_printFooter();
    }

    /**
     * Run "Regenerate thumbnails" (backend/tool_thumbnails.php, POST from
     * the form of the Data tab: area_id, background, function=run).
     *
     * With the switch "Background process" (default) ToolThumbnails::start()
     * starts the run in Core/cron/thumbnails.php and the page only reports the start; the
     * result comes as an internal message. Otherwise ToolThumbnails::run() runs in the
     * request and the result stands above the form.
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
     * The form: area (only those with thumbnail fields), switch
     * "Background process" (default: on), button "Generate".
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
     * "Normalize tree": check / run (backend/tool_tree_normalize.php,
     * POST from the form of the Data tab: area_id, function=check|run).
     *
     * 'check' only calls ToolTree::diagnose() (read-only) and shows the finding.
     * 'run' repairs the tree (ToolTree::normalize()) and shows what was fixed.
     *
     * Both paths ALWAYS report a result - a button press without a visible answer
     * would not be distinguishable from a failure for the user.
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
            // 'check' and every other call with area: only check
            $report = $this->toolTree->diagnose($areaId);
        }

        $this->_treeForm($areaId, $report, $before);

        $this->_printFooter();
    }

    /**
     * The form: area (only those with a tree, ToolTree::areaOptions()), buttons
     * "Check" / "Normalize"; when a finding is present (ToolTree::diagnose()),
     * its display above it.
     *
     * @param int        $areaId  selected area (0: none)
     * @param array|null $report  current diagnose() finding, null without a run
     * @param array|null $before  finding BEFORE a repair; null after a pure check.
     *                            It supplies the list of what was fixed -
     *                            $report is inconspicuous afterwards.
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
                // Pure check
                $reportHtml = $report['clean']
                    ? $this->_treeAlert('alert_success', 'tools.tree_report_clean', [], (int)$report['total'])
                    : $this->_treeAlert('alert_warning', 'tools.tree_report_issues_intro', $report);
            }
            elseif (!$report['clean']) {
                // Still conspicuous after the run
                $reportHtml = $this->_treeAlert('alert_warning', 'tools.tree_report_still_issues_intro', $report);
            }
            elseif ($before['clean']) {
                $reportHtml = $this->_treeAlert('alert_success', 'tools.tree_note_already_clean');
            }
            else {
                // Fixed is what was conspicuous BEFORE the run
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
     * Message above the form "Normalize tree".
     *
     * With $report, the list of the conspicuous points is appended to the introductory sentence
     * ("… 2 duplicate positions; 1 duplicate lft value.");
     * depending on the caller, these are the found or the fixed ones.
     *
     * @param string     $template  alert_success | alert_warning
     * @param string     $snippet   introductory sentence
     * @param array      $report    diagnose() finding whose points are enumerated
     * @param int|null   $count     counter for a plural snippet without enumeration
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
