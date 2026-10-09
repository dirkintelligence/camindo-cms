/*
 * cApp.forms – Form handling
 * - Change tracking (data-changed)
 * - Save shortcut (Cmd/Ctrl+S)
 * - Asynchronous submit via Ajax
 * - checkAndExecute: checks for unsaved changes before navigation
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

       // --- Public API ---

        /**
         * Sends the form contents (except uploads) to the given script
         * and inserts the result into the jQuery element $el
         * (used e.g. for updating a select list)
         */
        updateElement(name, action) {
            if (!name) return;
            if (!action) return;
            const $form = $('form:visible').first();
            if (!$form.length) return;
            const $el   = $form.find('[name="' + name + '"]');
            // The field does not have to exist (e.g. area without position field)
            if (!$el.length) return;
            const cData = new FormData($form[0]);

            $el.cLoadContent({
                url:      action,
                params:   cData,
                method:   'POST'
            });
        },

        /**
         * Checks the topmost visible layer for unsaved changes.
         * Executes callback if there are no changes or the
         * user has confirmed discarding them.
         *
         * @param {function} callback  Called in a "clean" state
         */
        checkAndExecute(callback) {
            const $form = $('form:visible').first();

            // Checks for data-changed OR the presence of the marker
            if ($form.length && $form.attr('data-changed') === 'true') {
                const discard = confirm(window.cConfig.message_edited);
                if (!discard) return;
                $form.attr('data-changed', 'false');
            }

            if (typeof callback === 'function') callback();
        },

        /**
         * Checks the topmost layer by its DOM element for unsaved changes.
         * Used by cApp.layers for the successive breadcrumb closing logic.
         *
         * @param {jQuery}   $layer    The layer element to check
         * @param {function} callback  Executed if the layer is "clean"
         */
        checkLayerAndExecute($layer, callback) {
            const $form = $layer.find('form').first();

            if ($form.length && $form.attr('data-changed') === 'true') {
                const discard = confirm(window.cConfig.message_edited);
                if (!discard) return; // chain stops here
                $form.attr('data-changed', 'false');
            }

            if (typeof callback === 'function') callback();
        },

        // --- Private: event bindings ---

        /**
         * Marks a form as changed as soon as input occurs.
         */
        _bindChangeTracking() {
            $(document).on('input change', 'input, textarea, select', (e) => {
                this.markChanged(e.currentTarget);
            });
        },

        /**
         * Mark a form as changed - for field extensions whose editors
         * do not trigger input/change events on the hidden field.
         */
        markChanged(el) {
            $(el).closest('form').attr('data-changed', 'true');
        },

        /**
         * Permission tables (table.c9-access-table, group info / tab "Access"):
         * a click on a column header of the lower header row toggles all
         * active checkboxes of the column - if at least one is empty, all are
         * checked, otherwise all are cleared. change is triggered so that the coupling
         * "Read" -> other columns (onchange of the row) follows.
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
         * Cmd+S / Ctrl+S triggers the visible form.
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
         * Intercepts all form submits and sends them asynchronously.
         */
        _bindAjaxSubmit() {
            $(document).on('submit', 'form', (e) => {
                
                e.preventDefault();

                const $form      = $(e.currentTarget);                
                const action     = $form.attr('action');
                const method     = $form.attr('method') || 'GET';
                // Field extensions: write editor contents back into the fields
                if (cApp.extensions && cApp.extensions.beforeSubmit) {
                    cApp.extensions.beforeSubmit($form);
                }
                const cData      = $form.serializeFiles();

                if (!cData) return;

                // data-layer="new": show the response in a new layer (e.g. import
                // log above the settings, which are kept that way)
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
                        // (Formerly the code of an onsubmit attribute ran here a second
                        // time via new Function - unused, and the script policy forbids
                        // eval. An onsubmit now runs like any handler on submit,
                        // backend-handlers.js; developer docs, chapter 14 (Security: web).)
                    }
                });
            });
        }
 
    };

}(window.cApp));
