<?php
/**
 * Backend UI of the module Logfiles: browse the logs under var/log in the backend
 * instead of fetching them via the shell.
 *
 * Entry points (scripts in backend/):
 *
 *     index.php   -> list()    filters and hit list
 *
 * Also the start page widget widget() (settings.php 'widget'): a short
 * summary of the last few days, the most important message first.
 *
 * SUPERUSERS ONLY
 * --------------------
 * Logs contain login names, file paths, SQL and stack traces - none of this is
 * any editor's business. The module therefore sets 'superuser' => true in its
 * settings.php; this way Access::module() lets nobody else through, and neither
 * tile nor widget appears. Every entry point additionally checks for itself with
 * Backend::isSuperuser() - a permission rule that exists in only one place does not
 * last long.
 *
 * EVERYTHING IS FOREIGN TEXT
 * --------------------
 * A log entry contains what someone typed into the login form, what an
 * exception reports, what an uploaded file is called. None of this is trustworthy.
 * EVERY output of this module therefore goes through htmlspecialchars(), including
 * file and category names - they do come from the directory or from a
 * constant, but the rule applies here without exception, so that one does not have to
 * check again each time when reading.
 *
 * @see Camindo\Core\Modules\Logfiles\Logfiles  read, split and classify files
 * @see Camindo\Core\Widgets                    start page widgets of the modules
 */
namespace Camindo\Core\Modules\Logfiles;

use Camindo\Core\Backend;
use Camindo\Core\Cms;

class LogfilesBackend {

    public function __construct(
        private Backend $backend,
        private Cms $cms,
        private Logfiles $logfiles,
    )
    {}

    /**
     * Search (backend/index.php).
     *
     * Request: name, date, category, search - all optional. Checked against the
     * files actually present or against Logfiles::CATEGORIES; anything that does not
     * match counts as "all".
     */
    public function list(): void
    {
        $this->backend->setNaviLabel('#logfiles.module_label');
        echo $this->backend->template('container_header');

        if (!$this->backend->isSuperuser()) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }

        echo $this->backend->headline('#logfiles.headline_list');

        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_refresh', "cApp.layers.refresh();");
        echo $this->backend->buttonBar();

        // Check input against what exists
        $names = $this->logfiles->names();
        $dates = $this->logfiles->dates();
        $name = (string)($_REQUEST['name'] ?? '');
        if (!isset($names[$name])) $name = '';
        $date = (string)($_REQUEST['date'] ?? '');
        if (!in_array($date, $dates, true)) $date = '';
        $category = (string)($_REQUEST['category'] ?? '');
        if (!isset(Logfiles::CATEGORIES[$category])) $category = '';
        $search = trim((string)($_REQUEST['search'] ?? ''));

        echo $this->_searchForm($names, $dates, $name, $date, $category, $search);

        if (!$names) {
            echo $this->backend->template('alert_success', ['{text}' => '{#logfiles.note_no_files}']);
            echo $this->backend->template('container_footer');
            return;
        }

        // Paging: PAGE_SIZE entries per page, one more shows whether there are more
        $skip = max(0, (int)($_REQUEST['skip'] ?? 0));
        $entries = $this->logfiles->entries($name, $date, $search, $category, Logfiles::PAGE_SIZE + 1, $skip);
        $more = count($entries) > Logfiles::PAGE_SIZE;
        $entries = array_slice($entries, 0, Logfiles::PAGE_SIZE);
        $filter = ['name' => $name, 'date' => $date, 'category' => $category, 'search' => $search];
        echo $this->_entryTable($entries, $skip);
        echo $this->_pager($filter, $skip, $more);

        if ($this->logfiles->truncated()) {
            echo $this->backend->template('alert_warning', ['{text}' => '{#logfiles.note_truncated}']);
        }

        echo $this->backend->template('container_footer');
    }

    /**
     * Widget for the start page (settings.php 'widget').
     *
     * One line per category with the number of entries of the last days and the
     * newest example; a click opens the search with exactly this category.
     * The order comes from Logfiles::CATEGORIES - urgency, not volume.
     */
    public function widget(): string
    {
        // The widget is fetched via Widgets::render(), which checks Access::module();
        // in case someone calls it directly, check again here
        if (!$this->backend->isSuperuser()) return $this->backend->access()->deniedHtml();

        $summary = $this->logfiles->summary();
        if (!$summary) {
            return $this->backend->template('alert_success', ['{text}' => '{#logfiles.widget_nothing}'])
                . $this->_widgetFooter();
        }

        $rows = '';
        foreach ($summary as $category => $info) {
            $latest = $info['latest'];
            $rows .= $this->backend->template('logfiles.widget_row', [
                '{category}' => htmlspecialchars($category),
                '{label}' => htmlspecialchars($this->_categoryLabel($category)),
                '{level}' => htmlspecialchars((string)$info['level']),
                '{number}' => (string)(int)$info['count'],
                '{latest}' => htmlspecialchars($this->_shorten((string)($latest['text'] ?? ''), 120)),
            ]);
        }
        return $this->backend->template('ul_unstyled', ['{items}' => $rows])
            . $this->_widgetFooter();
    }

    // ====================

    /**
     * Note on the period plus a link to the full search.
     */
    private function _widgetFooter(): string
    {
        // The snippet is a pure plural (ICU) - the period is therefore resolved here
        // and not in the template (the substitution would come too early there)
        return $this->backend->template('logfiles.widget_footer', [
            '{period}' => htmlspecialchars($this->cms->snippet('logfiles.widget_period', ['days' => Logfiles::SUMMARY_DAYS])),
        ]);
    }

    /**
     * The filter form.
     *
     * @param array $names  log name => number of files
     * @param array $dates  existing days
     */
    private function _searchForm(array $names, array $dates, string $name, string $date, string $category, string $search): string
    {
        $nameOptions = $this->_options(
            array_combine(array_keys($names), array_keys($names)),
            $name,
            $this->cms->snippet('logfiles.option_all_names')
        );
        $dateOptions = $this->_options(
            array_combine($dates, array_map([$this, '_localDate'], $dates)),
            $date,
            $this->cms->snippet('logfiles.option_all_dates')
        );
        $categoryLabels = [];
        foreach (array_keys(Logfiles::CATEGORIES) as $key) {
            $categoryLabels[$key] = $this->_categoryLabel($key);
        }
        $categoryOptions = $this->_options(
            $categoryLabels,
            $category,
            $this->cms->snippet('logfiles.option_all_categories')
        );

        return $this->backend->template('logfiles.search_form', [
            '{name_options}' => $nameOptions,
            '{date_options}' => $dateOptions,
            '{category_options}' => $categoryOptions,
            '{search}' => htmlspecialchars($search),
        ]);
    }

    /**
     * The hit list.
     *
     * @param array $entries  entries from Logfiles::entries()
     * @param int   $skip     skipped entries (page)
     */
    private function _entryTable(array $entries, int $skip = 0): string
    {
        if (!$entries) {
            return $this->backend->template('alert_success', ['{text}' => '{#logfiles.note_no_entries}']);
        }

        $headColumns = '';
        foreach (['column_date', 'column_name', 'column_category', 'column_message'] as $key) {
            $headColumns .= $this->backend->template('table_head_column', [
                '{text}' => htmlspecialchars($this->cms->snippet('logfiles.' . $key)),
            ]);
        }

        $rows = '';
        foreach ($entries as $entry) {
            $category = (string)$entry['category'];
            $rows .= $this->backend->template('logfiles.table_row', [
                '{date}' => htmlspecialchars($this->_localDate((string)$entry['date'])),
                '{time}' => htmlspecialchars((string)$entry['time']),
                '{name}' => htmlspecialchars((string)$entry['name']),
                '{category}' => htmlspecialchars($this->_categoryLabel($category)),
                '{level}' => htmlspecialchars((string)(Logfiles::CATEGORIES[$category] ?? 'secondary')),
                '{message}' => nl2br(htmlspecialchars((string)$entry['text'])),
            ]);
        }

        $table = $this->backend->template('table_with_head', [
            '{head_columns}' => $headColumns,
            '{table_rows}' => $rows,
        ]);

        // Hit count on the first page without more, otherwise the excerpt
        $count = count($entries);
        $note = $skip === 0 && $count < Logfiles::PAGE_SIZE
            ? $this->cms->snippet('logfiles.note_count', ['count' => $count])
            : strtr($this->cms->snippet('logfiles.note_range'), ['{from}' => (string)($skip + 1), '{to}' => (string)($skip + $count)]);

        return $this->backend->template('remark_block', ['{text}' => htmlspecialchars($note)]) . $table;
    }

    /**
     * Paging below the hit list: "Newer entries" from the second page on,
     * "Older entries" as long as there are more. The filters are carried along.
     */
    private function _pager(array $filter, int $skip, bool $more): string
    {
        $links = [];
        foreach ([[$skip > 0, max(0, $skip - Logfiles::PAGE_SIZE), 'link_newer'], [$more, $skip + Logfiles::PAGE_SIZE, 'link_older']] as [$show, $target, $key]) {
            if (!$show) continue;
            $query = http_build_query(array_filter($filter + ['skip' => $target], fn($v) => $v !== '' && $v !== 0));
            $links[] = $this->backend->template('link_replace', [
                '{href}' => 'Logfiles/index.php' . ($query !== '' ? '?' . $query : ''),
                '{text}' => '{#logfiles.' . $key . '}',
            ]);
        }
        return $links ? '<p class="mt-2">' . implode(' &nbsp; ', $links) . '</p>' : '';
    }

    /**
     * Label of a category - snippet logfiles.category_{key}.
     */
    private function _categoryLabel(string $category): string
    {
        return $this->cms->snippet('logfiles.category_' . $category);
    }

    /**
     * A date in the locale of the administrator (the log files are always named Y-m-d).
     * Cms::_formatDate() omits the time if it is 00:00:00 - exactly what
     * is wanted here.
     */
    private function _localDate(string $date): string
    {
        return $this->cms->_formatDate($date) ?: $date;
    }

    /**
     * Build the select list. The values are already checked, but are escaped anyway.
     *
     * @param array  $options   value => label
     * @param string $selected  current value
     * @param string $allLabel  label of the empty entry ("all")
     */
    private function _options(array $options, string $selected, string $allLabel): string
    {
        $html = '<option value="">' . htmlspecialchars($allLabel) . '</option>' . "\n";
        foreach ($options as $value => $label) {
            $html .= '<option value="' . htmlspecialchars((string)$value) . '"'
                . ((string)$value === $selected ? ' selected' : '') . '>'
                . htmlspecialchars((string)$label) . "</option>\n";
        }
        return $html;
    }

    /**
     * Shorten long text for the preview - called before escaping, so that the
     * length is that of the text and not that of the entities.
     */
    private function _shorten(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if (mb_strlen($text) <= $max) return $text;
        return mb_substr($text, 0, $max - 1) . '…';
    }
}
