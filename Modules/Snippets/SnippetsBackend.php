<?php
/**
 * Backend-Oberfläche des Moduls Snippets: die Snippet-Sets der Instanzen
 * (var/custom/snippets/{instance_id}/lang/{code}.ini) ansehen und je Snippet
 * bearbeiten.
 *
 * Einstiegspunkte (Scripte in backend/):
 *
 *     index.php         -> list()   Instanzen, deren Set der Admin sehen darf
 *     set_table.php     -> table()  Snippets einer Instanz: Liste, Sprachauswahl
 *     snippet_edit.php  -> edit()   ein Snippet anlegen, kopieren, ändern oder löschen
 *                                   (eigener Layer)
 *
 * Kein Dataset dahinter - die Dateien sind die Ablage (Snippets); Sets werden nicht
 * angelegt oder gelöscht, sie gehören zur Instanz. Wer die Instanz nicht darf
 * (Snippets::allowed()), bekommt die Zugriffsmeldung von Access.
 *
 * DIE TABELLE
 * --------------------
 * Eine Zeile je Schlüssel, eine Spalte je ANGEZEIGTER Backend-Sprache - bei 13
 * Sprachen wäre die Tabelle sonst unlesbar. Welche Sprachen, wählt der Admin im
 * Dropdown über der Tabelle; die Auswahl steht in der Admin-Session
 * (Session::uiState('snippets_languages'), gilt für alle Instanzen), ohne Auswahl
 * nur FALLBACK_LANG. Die Tabelle ist nur Anzeige: Klick auf eine Zeile öffnet die
 * Maske (Layer mit allen Sprachen untereinander), das Kontextmenü (rechte Maustaste)
 * bietet Bearbeiten, Kopieren (neue Maske mit den Texten, Schlüssel leer) und Löschen
 * (Rückfrage-Layer, dann POST). Schlüssel bestehender Snippets sind nicht änderbar -
 * Templates und Beschriftungsfelder verweisen über ihn auf das Snippet.
 *
 * Die Instanzliste zeigt statt einer Spalte je Sprache nur die Lücken: Sprachen, in
 * denen Texte fehlen, mit ihrer Anzahl.
 *
 * @see Camindo\Core\Modules\Snippets\Snippets   Dateien, Prüfung, Zugriff, Cache
 */
namespace Camindo\Core\Modules\Snippets;

use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\Session;

class SnippetsBackend {

    /** Schlüssel des Oberflächen-Zustands in der Admin-Session */
    private const UI_LANGUAGES = 'snippets_languages';

    protected int $instanceId = 0;
    protected string $function = '';

    public function __construct(
        private Backend $backend,
        private Cms $cms,
        private Session $session,
        private Snippets $snippets,
    )
    {}

    /**
     * Liste der Instanzen (backend/index.php).
     */
    public function list(): void
    {
        $this->backend->setNaviLabel('#snippets.module_label');
        echo $this->backend->template('container_header');
        echo $this->backend->headline('#snippets.headline_list');

        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_refresh', "cApp.layers.refresh();");
        echo $this->backend->buttonBar();

        echo $this->backend->template('p', ['{text}' => $this->cms->snippet('snippets.note_list')]);

        $instances = $this->snippets->instances();
        $langs = $this->snippets->languages();
        if (!$instances) {
            echo $this->backend->template('snippets.note_no_instances');
        }
        else {
            $rows = '';
            foreach ($instances as $instanceId => $info) {
                $rows .= $this->backend->template('snippets.table_row', [
                    '{instance_id}' => (string)$instanceId,
                    '{label}' => htmlspecialchars($info['label']),
                    '{prefix}' => htmlspecialchars($this->snippets->prefix($instanceId)),
                    '{keys}' => (string)$info['keys'],
                    '{translations}' => $this->_translations($info, $langs),
                ]) . "\n";
            }
            echo $this->backend->template('snippets.table_with_head', [
                '{table_rows}' => $rows,
            ]);
        }
        echo $this->backend->template('container_footer');
    }

    /**
     * Snippets einer Instanz als Tabelle (backend/set_table.php).
     *
     *     function=''         Tabelle
     *     function=languages  angezeigte Sprachen (languages[]) in der Session merken
     */
    public function table(): void
    {
        $this->instanceId = (int)($_REQUEST['instance_id'] ?? 0);
        $this->function = (string)($_REQUEST['function'] ?? '');

        $this->backend->setNaviLabel('#button_edit');
        echo $this->backend->template('container_header');

        if (!$this->snippets->allowed($this->instanceId)) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }
        $prefix = $this->snippets->prefix($this->instanceId);
        $label = (string)($this->snippets->instances()[$this->instanceId]['label'] ?? '');
        echo $this->backend->template('headline1', ['{text}' => $this->backend->template('snippets.headline_prefix', [
            '{label}' => $this->backend->escape($label),
            '{prefix}' => $this->backend->escape($prefix),
        ])]);

        // Schließen: die Instanzliste darunter neu laden (Anzahl und Übersetzungsstand
        // können sich geändert haben)
        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_close', "cApp.layers.invalidate(); cApp.layers.close()");
        echo $this->backend->buttonBar();

        $langs = $this->snippets->languages();
        if ($this->function === 'languages') {
            $this->_saveShownLanguages($langs);
        }

        echo $this->backend->template('p', ['{text}' => str_replace('{prefix}', htmlspecialchars($prefix), $this->cms->snippet('snippets.note_table'))]);

        $shown = $this->_shownLanguages($langs);
        $langColumns = '';
        foreach ($shown as $lang) {
            $langColumns .= $this->backend->template('table_head_column', ['{text}' => $this->_languageLabel($lang)]);
        }
        $rowsHtml = '';
        foreach ($this->snippets->load($this->instanceId) as $key => $texts) {
            $rowsHtml .= $this->_tableRow((string)$key, $texts, $shown);
        }

        echo $this->backend->template('snippets.table_toolbar', [
            '{instance_id}' => (string)$this->instanceId,
            '{language_options}' => $this->_languageOptions($langs, $shown),
            '{shown_count}' => (string)count($shown),
            '{language_count}' => (string)count($langs),
            '{add_path}' => htmlspecialchars('Snippets/snippet_edit.php?instance_id=' . $this->instanceId),
        ]);
        echo $this->backend->template($rowsHtml === '' ? 'snippets.note_no_snippets' : 'snippets.table_snippets', [
            '{edit_path}' => htmlspecialchars('Snippets/snippet_edit.php?instance_id=' . $this->instanceId . '&key=#'),
            '{lang_columns}' => $langColumns,
            '{table_rows}' => $rowsHtml,
        ]);
        if ($rowsHtml !== '') {
            echo $this->backend->template('snippets.context_menu', ['{instance_id}' => (string)$this->instanceId]);
        }
        echo $this->backend->template('container_footer');
    }

    /**
     * Eingabemaske für EIN Snippet (backend/snippet_edit.php) - alle Sprachen
     * untereinander, eigener Layer.
     *
     *     key=''                   neues Snippet (Schlüssel eingeben)
     *     copy={schlüssel}         neues Snippet mit den Texten von {schlüssel}
     *     key={schlüssel}          vorhandenes Snippet (Schlüssel fest)
     *     function=save            speichern (nur POST): Erfolg -> Tabelle neu laden,
     *                              Layer zu; Fehler -> Maske mit Eingaben und Meldung
     *     function=delete          Rückfrage: das Snippet mit allen Texten
     *     function=delete_confirm  löschen (nur POST): Tabelle neu laden, Layer zu
     */
    public function edit(): void
    {
        $this->instanceId = (int)($_REQUEST['instance_id'] ?? 0);
        $this->function = (string)($_REQUEST['function'] ?? '');
        $isNew = !empty($_REQUEST['new']) || (string)($_REQUEST['key'] ?? '') === '';
        $key = trim((string)($_REQUEST['key'] ?? ''));

        $this->backend->setNaviLabel($isNew ? '#snippets.headline_new' : htmlspecialchars($key));
        echo $this->backend->template('container_header');

        if (!$this->snippets->allowed($this->instanceId)) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }
        $langs = $this->snippets->languages();

        if (!$isNew && in_array($this->function, ['delete', 'delete_confirm'], true)) {
            $this->_delete($key, $langs);
            echo $this->backend->template('container_footer');
            return;
        }

        if ($this->function === 'save' && $this->backend->requirePost()) {
            $texts = [];
            foreach ($langs as $lang) {
                // leere Eingaben schickt der Browser nicht (serializeFiles()) - sie gelten als ''
                $texts[$lang] = (string)($_POST['text'][$lang] ?? '');
            }
            if ($this->snippets->saveSnippet($this->instanceId, $key, $texts, $isNew)) {
                $this->backend->balloon('success', $this->cms->snippet('snippets.message_snippets_saved'));
                $this->backend->script("cApp.layers.invalidate(); cApp.layers.close();");
                echo $this->backend->template('container_footer');
                return;
            }
            $error = $this->cms->errorMsg();
        }
        else {
            // Kopie: die Texte des Vorbilds, der Schlüssel bleibt leer
            $source = $isNew ? trim((string)($_REQUEST['copy'] ?? '')) : $key;
            $current = $source === '' ? [] : ($this->snippets->load($this->instanceId)[$source] ?? null);
            if ($current === null) {
                echo $this->backend->template('alert_danger', ['{text}' => htmlspecialchars(str_replace('{key}', $source, $this->cms->snippet('snippets.error_key_not_found')))]);
                echo $this->backend->template('container_footer');
                return;
            }
            $texts = $current;
            $error = '';
        }

        $prefix = $this->snippets->prefix($this->instanceId);
        echo $this->backend->headline($isNew ? '#snippets.headline_new' : '#snippets.headline_edit');
        if ($error !== '') {
            echo $this->backend->template('alert_danger', ['{text}' => $error]);
        }

        $langBlocks = '';
        foreach ($langs as $lang) {
            $langBlocks .= $this->backend->template('snippets.edit_form_language', [
                '{id}' => 'snippet-text-' . htmlspecialchars($lang),
                '{lang}' => htmlspecialchars($lang),
                '{lang_tag}' => htmlspecialchars(str_replace('_', '-', $lang)),
                // Schreibrichtung der Sprache, nicht des Backends (Cms::isRtl())
                '{dir}' => $this->cms->textDirection($lang),
                '{label}' => $this->_languageLabel($lang),
                '{value}' => $this->backend->escape((string)($texts[$lang] ?? '')),
            ]);
        }
        echo $this->backend->template('snippets.edit_form', [
            '{instance_id}' => (string)$this->instanceId,
            '{new}' => $isNew ? '1' : '',
            '{key_field}' => $this->backend->template($isNew ? 'snippets.edit_form_key_new' : 'snippets.edit_form_key', [
                '{key}' => htmlspecialchars($key),
                '{usage}' => htmlspecialchars('custom.' . ($key !== '' ? $key : '…') . ' · #' . $prefix . '.' . ($key !== '' ? $key : '…')),
                '{class}' => $error !== '' && isset($this->cms->errors['key']) ? ' is-invalid' : '',
                '{label}' => $isNew ? $this->backend->template('input_label_tt', [
                    '{id}' => 'snippet-key',
                    '{label}' => $this->backend->escape($this->cms->snippet('snippets.column_key')) . ' *',
                    '{tooltip}' => $this->backend->escape($this->cms->snippet('snippets.note_key')),
                ]) : '',
            ]),
            '{language_blocks}' => $langBlocks,
        ]);
        echo $this->backend->template('container_footer');
    }

    // ====================
    // Hilfsfunktionen

    /**
     * function=delete: Rückfrage mit den Texten aller Sprachen; delete_confirm (POST):
     * löschen, Tabelle neu laden, Layer zu.
     */
    private function _delete(string $key, array $langs): void
    {
        if ($this->function === 'delete_confirm' && $this->backend->requirePost()) {
            if ($this->snippets->deleteSnippet($this->instanceId, $key)) {
                $this->backend->balloon('success', str_replace('{key}', $key, $this->cms->snippet('snippets.message_snippet_deleted')));
                $this->backend->script("cApp.layers.invalidate(); cApp.layers.close();");
                return;
            }
            echo $this->backend->template('alert_danger', ['{text}' => $this->cms->errorMsg()]);
        }
        $texts = $this->snippets->load($this->instanceId)[$key] ?? null;
        if ($texts === null) {
            echo $this->backend->template('alert_danger', ['{text}' => htmlspecialchars(str_replace('{key}', $key, $this->cms->snippet('snippets.error_key_not_found')))]);
            return;
        }
        echo $this->backend->headline('#snippets.headline_delete');
        $langBlocks = '';
        foreach ($langs as $lang) {
            $text = (string)($texts[$lang] ?? '');
            $langBlocks .= $this->backend->template('snippets.delete_form_language', [
                '{label}' => $this->_languageLabel($lang),
                '{lang_tag}' => htmlspecialchars(str_replace('_', '-', $lang)),
                '{dir}' => $this->cms->textDirection($lang),
                '{text}' => $text === '' ? $this->backend->template('text-muted', ['{text}' => '–']) : nl2br($this->backend->escape($text)),
            ]);
        }
        echo $this->backend->template('snippets.delete_form', [
            '{instance_id}' => (string)$this->instanceId,
            '{key}' => htmlspecialchars($key),
            '{confirm}' => htmlspecialchars(str_replace('{key}', $key, $this->cms->snippet('snippets.confirm_delete'))),
            '{key_field}' => $this->backend->template('snippets.edit_form_key', [
                '{key}' => htmlspecialchars($key),
                '{usage}' => htmlspecialchars('custom.' . $key . ' · #' . $this->snippets->prefix($this->instanceId) . '.' . $key),
            ]),
            '{language_blocks}' => $langBlocks,
        ]);
    }

    /**
     * Zelle "Übersetzungen" der Instanzliste - kompakt auch bei vielen Sprachen: eine
     * Zeile mit den vollständigen Sprachen, je teilweise übersetzter Sprache ein Badge
     * (vorhandene/alle), eine Zeile mit den Sprachen ganz ohne Texte.
     *
     * @param array    $info   Eintrag aus Snippets::instances()
     * @param string[] $langs  Snippets::languages()
     */
    private function _translations(array $info, array $langs): string
    {
        $keys = (int)$info['keys'];
        if ($keys === 0) return $this->backend->template('text-muted', ['{text}' => '–']);
        $complete = $empty = [];
        $gaps = '';
        foreach ($langs as $lang) {
            $count = (int)($info['languages'][$lang] ?? 0);
            if ($count >= $keys) $complete[] = $lang;
            elseif ($count === 0) $empty[] = $lang;
            else $gaps .= $this->backend->template('snippets.translations_gap', [
                '{lang}' => htmlspecialchars($lang),
                '{count}' => (string)$count,
                '{keys}' => (string)$keys,
            ]);
        }
        $line = fn(string $snippet, array $list) => $list ? $this->backend->template('snippets.translations_line', [
            '{text}' => htmlspecialchars(str_replace('{langs}', implode(', ', $list), $this->cms->snippet($snippet))),
        ]) : '';
        return $line('snippets.label_translations_complete', $complete)
            . ($gaps !== '' ? '<div>' . $gaps . '</div>' : '')
            . $line('snippets.label_translations_empty', $empty);
    }

    /**
     * Angezeigte Sprachen aus der Session - nur noch vorhandene Backend-Sprachen, in
     * der Reihenfolge von Snippets::languages(); ohne Auswahl FALLBACK_LANG.
     *
     * @param string[] $langs  Snippets::languages()
     * @return string[]
     */
    private function _shownLanguages(array $langs): array
    {
        $selected = (array)$this->session->uiState(self::UI_LANGUAGES, []);
        $shown = array_values(array_filter($langs, fn(string $lang) => in_array($lang, $selected, true)));
        return $shown ?: [Cms::FALLBACK_LANG];
    }

    /**
     * Auswahl aus dem Dropdown (languages[]) übernehmen. Unbekannte Werte fallen
     * weg; nichts angehakt = nur FALLBACK_LANG (die Checkbox schickt der Browser
     * nicht, eine leere Auswahl ist also nicht von "nichts übermittelt" zu trennen).
     */
    private function _saveShownLanguages(array $langs): void
    {
        $posted = array_map('strval', (array)($_REQUEST['languages'] ?? []));
        $selected = array_values(array_intersect($langs, $posted));
        $this->session->setUiState(self::UI_LANGUAGES, $selected ?: [Cms::FALLBACK_LANG]);
    }

    /**
     * Checkboxen des Sprach-Dropdowns.
     */
    private function _languageOptions(array $langs, array $shown): string
    {
        $html = '';
        foreach ($langs as $lang) {
            $html .= $this->backend->template('snippets.table_toolbar_language', [
                '{id}' => 'snippets-lang-' . htmlspecialchars($lang),
                '{lang}' => htmlspecialchars($lang),
                '{label}' => $this->_languageLabel($lang),
                '{checked}' => in_array($lang, $shown, true) ? ' checked' : '',
            ]);
        }
        return $html;
    }

    /**
     * Bezeichnung einer Backend-Sprache in ihrer eigenen Sprache plus Kürzel,
     * z. B. "Svenska <code>sv_SE</code>" (HTML).
     */
    private function _languageLabel(string $lang): string
    {
        $name = $this->cms->snippet('backend_language_' . $lang);
        if ($name === 'backend_language_' . $lang) $name = $lang;
        return $this->backend->template('snippets.language_label', [
            '{name}' => $this->backend->escape($name),
            '{lang}' => $this->backend->escape($lang),
        ]);
    }

    /**
     * Eine Zeile der Tabelle: Schlüssel, Texte der angezeigten Sprachen (Aktionen
     * über Klick und Kontextmenü).
     */
    private function _tableRow(string $key, array $texts, array $shown): string
    {
        $cells = '';
        foreach ($shown as $lang) {
            $text = (string)($texts[$lang] ?? '');
            $cells .= $this->backend->template('snippets.table_snippets_cell', [
                '{lang_tag}' => htmlspecialchars(str_replace('_', '-', $lang)),
                '{dir}' => $this->cms->textDirection($lang),
                '{text}' => $text === '' ? $this->backend->template('text-muted', ['{text}' => '–']) : nl2br($this->backend->escape($text)),
            ]);
        }
        return $this->backend->template('snippets.table_snippets_row', [
            '{data_id}' => htmlspecialchars(rawurlencode($key)),
            '{key}' => htmlspecialchars($key),
            '{cells}' => $cells,
        ]);
    }
}
