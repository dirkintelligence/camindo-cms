/*
 * cApp.forms – Formular-Behandlung
 * - Änderungstracking (data-changed)
 * - Speichern-Shortcut (Cmd/Ctrl+S)
 * - Asynchrones Absenden per Ajax
 * - checkAndExecute: prüft ungespeicherte Änderungen vor Navigation
 */
(function (cApp) {
    'use strict';

    cApp.forms = {

        init() {
            this._bindChangeTracking();
            this._bindSaveShortcut();
            this._bindAjaxSubmit();
            this._bindColumnToggle();
        },

       // --- Öffentliche API ---

        /**
         * Sendet die Formularinhalte (außer Uploads) an das angegebene Script
         * und hängt das Ergebnis in das jQuery-Element $el ein
         * (Genutzt beispielsweise für Aktualisierung einer Select-Liste)
         */
        updateElement(name, action) {
            if (!name) return;
            if (!action) return;
            const $form = $('form:visible').first();
            if (!$form.length) return;
            const $el   = $form.find('[name="' + name + '"]');
            // Das Feld muss es nicht geben (z. B. Bereich ohne Positionsfeld)
            if (!$el.length) return;
            const cData = new FormData($form[0]);

            $el.cLoadContent({
                url:      action,
                params:   cData,
                method:   'POST'
            });
        },

        /**
         * Prüft den obersten sichtbaren Layer auf ungespeicherte Änderungen.
         * Führt callback aus, wenn keine Änderungen vorhanden oder der
         * Benutzer das Verwerfen bestätigt hat.
         *
         * @param {function} callback  Wird bei "sauberem" Zustand aufgerufen
         */
        checkAndExecute(callback) {
            const $form = $('form:visible').first();

            // Prüft auf data-changed ODER das Vorhandensein des Markers
            if ($form.length && $form.attr('data-changed') === 'true') {
                const discard = confirm(window.cConfig.message_edited);
                if (!discard) return;
                $form.attr('data-changed', 'false');
            }

            if (typeof callback === 'function') callback();
        },

        /**
         * Prüft den obersten Layer anhand seines DOM-Elements auf ungespeicherte Änderungen.
         * Wird von cApp.layers für die sukzessive Breadcrumb-Schließlogik verwendet.
         *
         * @param {jQuery}   $layer    Das zu prüfende Layer-Element
         * @param {function} callback  Wird ausgeführt, wenn der Layer "sauber" ist
         */
        checkLayerAndExecute($layer, callback) {
            const $form = $layer.find('form').first();

            if ($form.length && $form.attr('data-changed') === 'true') {
                const discard = confirm(window.cConfig.message_edited);
                if (!discard) return; // Kette stoppt hier
                $form.attr('data-changed', 'false');
            }

            if (typeof callback === 'function') callback();
        },

        // --- Privat: Event-Bindungen ---

        /**
         * Markiert ein Formular als geändert, sobald eine Eingabe erfolgt.
         */
        _bindChangeTracking() {
            $(document).on('input change', 'input, textarea, select', (e) => {
                this.markChanged(e.currentTarget);
            });
        },

        /**
         * Formular als geändert markieren - für Feld-Extensions, deren Editoren
         * keine input/change-Ereignisse auf dem versteckten Feld auslösen.
         */
        markChanged(el) {
            $(el).closest('form').attr('data-changed', 'true');
        },

        /**
         * Rechte-Tabellen (table.c9-access-table, Gruppen-Info / Reiter "Zugriff"):
         * ein Klick auf einen Spaltenkopf der unteren Kopfzeile schaltet alle
         * aktiven Checkboxen der Spalte - ist mindestens eine leer, werden alle
         * gesetzt, sonst alle gelöscht. change wird ausgelöst, damit die Kopplung
         * "Lesen" -> übrige Spalten (onchange der Zeile) mitzieht.
         */
        _bindColumnToggle() {
            $(document).on('click', 'table.c9-access-table thead tr:last-child th', (e) => {
                const $th = $(e.currentTarget);
                const col = $th.index();
                const $boxes = $th.closest('table').find('tbody tr').map(function () {
                    return $(this).children('td').eq(col).find('input[type=checkbox]:enabled').get();
                });
                if (!$boxes.length) return;
                const check = $boxes.filter((i, el) => !el.checked).length > 0;
                $boxes.each((i, el) => {
                    if (el.checked !== check) $(el).prop('checked', check).trigger('change');
                });
            });
        },

        /**
         * Cmd+S / Ctrl+S löst das sichtbare Formular aus.
         */
        _bindSaveShortcut() {
            $(document).on('keydown', (e) => {
                if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
                    e.preventDefault();
                    const $form = $('form:visible').first();
                    if (!$form.length) return;

                    if ($form[0].requestSubmit) {
                        $form[0].requestSubmit();
                    } else {
                        $form.trigger('submit');
                    }
                }
            });
        },

        /**
         * Fängt alle Formular-Submits ab und sendet sie asynchron.
         */
        _bindAjaxSubmit() {
            $(document).on('submit', 'form', (e) => {
                
                e.preventDefault();

                const $form      = $(e.currentTarget);                
                const action     = $form.attr('action');
                const method     = $form.attr('method') || 'GET';
                // Feld-Extensions: Editorinhalte in die Felder zurückschreiben
                if (cApp.extensions && cApp.extensions.beforeSubmit) {
                    cApp.extensions.beforeSubmit($form);
                }
                const cData      = $form.serializeFiles();

                if (!cData) return;

                // data-layer="new": Antwort in einem neuen Layer zeigen (z. B. Import-
                // Protokoll über den Einstellungen, die so erhalten bleiben)
                if ($form.attr('data-layer') === 'new') {
                    cApp.layers.addPost(action, cData);
                    $form.attr('data-changed', 'false');
                    return;
                }

                $form.closest('.c9-layer, .modal-dialog').cLoadContent({
                    url:      action,
                    params:   cData,
                    method:   method,
                    callback: () => {
                        $form.attr('data-changed', 'false');

                        $('article').scrollTop(0);
                        // (Früher lief hier der Code eines onsubmit-Attributs ein zweites
                        // Mal per new Function - ungenutzt, und die Script-Policy verbietet
                        // eval. Ein onsubmit läuft jetzt wie jeder Handler beim Absenden,
                        // backend-handlers.js; 19-csp.md.)
                    }
                });
            });
        }
 
    };

}(window.cApp));
