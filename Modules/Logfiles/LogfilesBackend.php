<?php
/**
 * Backend-Oberfläche des Moduls Logfiles: die Protokolle unter var/log im Backend
 * durchsehen, statt sie über die Shell zu holen.
 *
 * Einstiegspunkte (Scripte in backend/):
 *
 *     index.php   -> list()    Filter und Trefferliste
 *
 * Dazu das Startseiten-Widget widget() (settings.php 'widget'): eine kurze
 * Zusammenfassung der letzten Tage, die wichtigste Meldung zuerst.
 *
 * NUR FÜR SUPERUSER
 * --------------------
 * Logs enthalten Anmeldenamen, Dateipfade, SQL und Stacktraces - das geht keinen
 * Redakteur etwas an. Das Modul trägt deshalb 'superuser' => true in seiner
 * settings.php; damit lässt Access::module() niemanden sonst durch, und weder
 * Kachel noch Widget erscheinen. Jeder Einstiegspunkt prüft zusätzlich selbst mit
 * Backend::isSuperuser() - eine Rechteregel, die nur an einer Stelle steht, hält
 * nicht lange.
 *
 * ALLES IST FREMDER TEXT
 * --------------------
 * In einem Logeintrag steht, was jemand ins Anmeldeformular getippt hat, was eine
 * Ausnahme meldet, wie eine hochgeladene Datei heißt. Nichts davon ist vertrauens-
 * würdig. JEDE Ausgabe dieses Moduls geht deshalb durch htmlspecialchars(), auch
 * Datei- und Kategorienamen - die kommen zwar aus dem Verzeichnis bzw. aus einer
 * Konstanten, aber die Regel gilt hier ohne Ausnahme, damit man beim Lesen nicht
 * jedes Mal neu prüfen muss.
 *
 * @see Camindo\Core\Modules\Logfiles\Logfiles  Dateien lesen, zerlegen, einordnen
 * @see Camindo\Core\Widgets                    Startseiten-Widgets der Module
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
     * Recherche (backend/index.php).
     *
     * Request: name, date, category, search - alle optional. Geprüft wird gegen die
     * tatsächlich vorhandenen Dateien bzw. gegen Logfiles::CATEGORIES; was nicht
     * passt, gilt als "alle".
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

        // Eingaben gegen das Vorhandene prüfen
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

        // Blättern: je Seite PAGE_SIZE Einträge, einer mehr zeigt an, ob es weitere gibt
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
     * Widget für die Startseite (settings.php 'widget').
     *
     * Je Kategorie eine Zeile mit der Anzahl der Einträge der letzten Tage und dem
     * jüngsten Beispiel; ein Klick öffnet die Recherche mit genau dieser Kategorie.
     * Die Reihenfolge kommt aus Logfiles::CATEGORIES - Dringlichkeit, nicht Menge.
     */
    public function widget(): string
    {
        // Das Widget wird über Widgets::render() geholt, das Access::module() prüft;
        // für den Fall, dass es jemand direkt aufruft, hier noch einmal
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
     * Hinweis auf den Zeitraum plus Verweis in die volle Recherche.
     */
    private function _widgetFooter(): string
    {
        // Das Snippet ist ein reiner Plural (ICU) - der Zeitraum wird deshalb hier
        // aufgelöst und nicht in der Vorlage (dort käme die Ersetzung zu früh)
        return $this->backend->template('logfiles.widget_footer', [
            '{period}' => htmlspecialchars($this->cms->snippet('logfiles.widget_period', ['days' => Logfiles::SUMMARY_DAYS])),
        ]);
    }

    /**
     * Das Filterformular.
     *
     * @param array $names  Logbezeichnung => Anzahl Dateien
     * @param array $dates  vorhandene Tage
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
     * Die Trefferliste.
     *
     * @param array $entries  Einträge aus Logfiles::entries()
     * @param int   $skip     übersprungene Einträge (Seite)
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

        // Trefferzahl auf der ersten Seite ohne weitere, sonst der Ausschnitt
        $count = count($entries);
        $note = $skip === 0 && $count < Logfiles::PAGE_SIZE
            ? $this->cms->snippet('logfiles.note_count', ['count' => $count])
            : strtr($this->cms->snippet('logfiles.note_range'), ['{from}' => (string)($skip + 1), '{to}' => (string)($skip + $count)]);

        return $this->backend->template('remark_block', ['{text}' => htmlspecialchars($note)]) . $table;
    }

    /**
     * Blättern unter der Trefferliste: "Neuere Einträge" ab der zweiten Seite,
     * "Ältere Einträge", solange es weitere gibt. Die Filter reisen mit.
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
     * Beschriftung einer Kategorie - Snippet logfiles.category_{key}.
     */
    private function _categoryLabel(string $category): string
    {
        return $this->cms->snippet('logfiles.category_' . $category);
    }

    /**
     * Ein Datum in der Locale des Admins (die Logdateien heißen immer Y-m-d).
     * Cms::_formatDate() lässt die Uhrzeit weg, wenn sie 00:00:00 ist - genau das
     * ist hier gewollt.
     */
    private function _localDate(string $date): string
    {
        return $this->cms->_formatDate($date) ?: $date;
    }

    /**
     * Auswahlliste bauen. Die Werte sind bereits geprüft, maskiert wird trotzdem.
     *
     * @param array  $options   Wert => Beschriftung
     * @param string $selected  aktueller Wert
     * @param string $allLabel  Beschriftung des leeren Eintrags ("alle")
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
     * Langen Text für die Vorschau kürzen - vor dem Maskieren aufgerufen, damit die
     * Länge die des Textes ist und nicht die der Entities.
     */
    private function _shorten(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if (mb_strlen($text) <= $max) return $text;
        return mb_substr($text, 0, $max - 1) . '…';
    }
}
