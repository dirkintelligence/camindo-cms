<?php
/**
 * Backend UI of the module Snippets: view the snippet sets of the instances
 * (var/custom/snippets/{instance_id}/lang/{code}.ini) and edit each snippet.
 *
 * Entry points (scripts in backend/):
 *
 *     index.php         -> list()   instances whose set the administrator may see
 *     set_table.php     -> table()  snippets of an instance: list, language selection
 *     snippet_edit.php  -> edit()   create, copy, change or delete a snippet
 *                                   (own layer)
 *
 * No dataset behind it - the files are the storage (snippets); sets are not
 * created or deleted, they belong to the instance. Whoever may not use the instance
 * (Snippets::allowed()) gets the access message from Access.
 *
 * THE TABLE
 * --------------------
 * One row per key, one column per DISPLAYED backend language - with 13
 * languages the table would otherwise be unreadable. The administrator chooses which languages in the
 * dropdown above the table; the selection is kept in the administrator session
 * (Session::uiState('snippets_languages'), applies to all instances); without a selection
 * only FALLBACK_LANG. The table is display only: clicking a row opens the
 * mask (layer with all languages one below the other), the context menu (right mouse button)
 * offers Edit, Copy (new mask with the texts, key empty) and Delete
 * (confirmation layer, then POST). Keys of existing snippets cannot be changed -
 * templates and label fields refer to the snippet via it.
 *
 * Instead of one column per language, the instance list shows only the gaps: languages in
 * which texts are missing, with their number.
 *
 * @see Camindo\Core\Modules\Snippets\Snippets   files, checks, access, cache
 */
namespace Camindo\Core\Modules\Snippets;

use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\Session;

class SnippetsBackend {

    /** Key of the UI state in the administrator session */
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
     * List of the instances (backend/index.php).
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
     * Snippets of an instance as a table (backend/set_table.php).
     *
     *     function=''         table
     *     function=languages  remember displayed languages (languages[]) in the session
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

        // Close: reload the instance list below (count and translation status
        // may have changed)
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
     * Input mask for ONE snippet (backend/snippet_edit.php) - all languages
     * one below the other, own layer.
     *
     *     key=''                   new snippet (enter key)
     *     copy={key}               new snippet with the texts of {key}
     *     key={key}                existing snippet (key fixed)
     *     function=save            save (POST only): success -> reload table,
     *                              close layer; error -> mask with inputs and message
     *     function=delete          confirmation: the snippet with all texts
     *     function=delete_confirm  delete (POST only): reload table, close layer
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
                // the browser does not send empty inputs (serializeFiles()) - they count as ''
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
            // Copy: the texts of the template, the key stays empty
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
                // writing direction of the language, not of the backend (Cms::isRtl())
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
    // Helper functions

    /**
     * function=delete: confirmation with the texts of all languages; delete_confirm (POST):
     * delete, reload table, close layer.
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
     * Cell "Translations" of the instance list - compact even with many languages: one
     * line with the complete languages, per partially translated language one badge
     * (existing/all), one line with the languages without any texts.
     *
     * @param array    $info   entry from Snippets::instances()
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
     * Displayed languages from the session - only backend languages that still exist, in
     * the order of Snippets::languages(); without a selection FALLBACK_LANG.
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
     * Take over the selection from the dropdown (languages[]). Unknown values are
     * dropped; nothing checked = only FALLBACK_LANG (the browser does not send the checkbox,
     * so an empty selection cannot be told apart from "nothing submitted").
     */
    private function _saveShownLanguages(array $langs): void
    {
        $posted = array_map('strval', (array)($_REQUEST['languages'] ?? []));
        $selected = array_values(array_intersect($langs, $posted));
        $this->session->setUiState(self::UI_LANGUAGES, $selected ?: [Cms::FALLBACK_LANG]);
    }

    /**
     * Checkboxes of the language dropdown.
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
     * Name of a backend language in its own language plus code,
     * e.g. "Svenska <code>sv_SE</code>" (HTML).
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
     * One row of the table: key, texts of the displayed languages (actions
     * via click and context menu).
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
