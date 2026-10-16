/*
 * cApp.templateCommands – command picker of the template input mask
 *
 * The selection list next to the label "Code" (TemplateBackend::_commandPicker())
 * remembers the code field and its cursor position and opens the layer
 * Template/template_command.php?cmd=… (TemplateBackend::command()). "Insert" there
 * assembles the command from the rows and inserts it at the remembered position -
 * entirely in the browser, nothing is sent to the server.
 */
(function (cApp) {
    'use strict';

    // Bare values/keys without quotes; everything else in "…" (TemplateParserTrait::readToken())
    const BARE = /^[A-Za-z0-9_.,:\/*+@#%-]+$/;

    cApp.templateCommands = {

        // {el: textarea, start, end} - set by pick(), used by insert()
        target: null,

        /**
         * Selection list changed: remember the code field and the cursor, open the layer.
         * @param {HTMLSelectElement} select
         * @param {Event} event
         */
        pick(select, event) {
            // The selection is no change of the template form (backend-forms.js change tracking)
            if (event) event.stopPropagation();
            const name = select.value;
            select.value = '';
            if (!name) return;
            const block = select.closest('.c9-layer') || document;
            const el = block.querySelector('textarea.c9-code-grow');
            if (!el) return;
            this.target = { el: el, start: el.selectionStart, end: el.selectionEnd };
            cApp.layers.add('Template/template_command.php?cmd=' + encodeURIComponent(name));
        },

        /**
         * "+": another row of the same parameter, below the last one.
         * @param {HTMLElement} button
         */
        addRow(button) {
            const param = button.getAttribute('data-param');
            const form = button.closest('.c9-cmd-form');
            const rows = [...form.querySelectorAll('.c9-cmd-row')].filter(r => r.getAttribute('data-param') === param);
            const last = rows[rows.length - 1];
            if (!last) return;
            const row = last.cloneNode(true);
            row.querySelectorAll('input').forEach(input => { input.value = ''; });
            row.querySelectorAll('select').forEach(select => { select.value = '='; });
            // own id for label/value, tooltip of the copy without Bootstrap's leftovers
            const value = row.querySelector('.c9-cmd-value');
            const id = value.id.replace(/-\d+$/, '') + '-' + Date.now();
            value.id = id;
            row.querySelector('label').setAttribute('for', id);
            row.querySelectorAll('[aria-describedby]').forEach(e => e.removeAttribute('aria-describedby'));
            last.after(row);
            (row.querySelector('.c9-cmd-sub') || value).focus();
        },

        /**
         * Enter in an input field = "Insert".
         * @param {HTMLElement} form
         * @param {KeyboardEvent} event
         */
        keydown(form, event) {
            if (event.key === 'Enter' && event.target.matches('input')) {
                event.preventDefault();
                this.insert(form);
            }
        },

        /**
         * Assemble the command from the rows of the layer.
         * Rows without a value are left out. One or two parameters stay on one line,
         * more get one line each, indented like the line of the cursor.
         *
         * @param {HTMLElement} form  .c9-cmd-form
         * @param {string} indent     indentation of the current line
         * @returns {string}
         */
        build(form, indent) {
            const parts = [];
            form.querySelectorAll('.c9-cmd-row').forEach(row => {
                const value = row.querySelector('.c9-cmd-value').value;
                if (value.trim() === '') return;
                // Sub-keys: trailing empty ones are dropped, empty ones in between become []
                const subs = [...row.querySelectorAll('.c9-cmd-sub')].map(input => input.value.trim());
                while (subs.length && subs[subs.length - 1] === '') subs.pop();
                const key = row.getAttribute('data-param')
                    + subs.map(sub => '[' + (sub === '' || BARE.test(sub) ? sub : this.quote(sub)) + ']').join('');
                const opSelect = row.querySelector('.c9-cmd-op');
                const op = opSelect ? opSelect.value : '=';
                const val = BARE.test(value) ? value : this.quote(value);
                parts.push(op === '=' ? key + '=' + val : key + ' ' + op + ' ' + val);
            });
            const name = 'CMS:' + form.getAttribute('data-cmd').toUpperCase();
            if (parts.length === 0) return '(' + name + ')';
            if (parts.length <= 2) return '(' + name + ' ' + parts.join(' ') + ')';
            return '(' + name + '\n' + parts.map(p => indent + '  ' + p).join('\n') + '\n' + indent + ')';
        },

        /**
         * Value in double quotes; a " inside is escaped with a backslash.
         * @param {string} text
         * @returns {string}
         */
        quote(text) {
            return '"' + text.replace(/"/g, '\\"') + '"';
        },

        /**
         * "Insert": command into the code field at the remembered position,
         * close the layer, cursor behind the inserted command.
         * @param {HTMLElement} el  element inside .c9-cmd-form
         */
        insert(el) {
            const form = el.closest('.c9-cmd-form');
            const target = this.target;
            if (!form || !target || !document.contains(target.el)) {
                cApp.layers.close();
                return;
            }
            const textarea = target.el;
            const text = textarea.value;
            const start = Math.min(target.start, text.length);
            const end = Math.min(target.end, text.length);
            const lineStart = text.lastIndexOf('\n', start - 1) + 1;
            const indent = (text.slice(lineStart, start).match(/^[ \t]*/) || [''])[0];
            textarea.setRangeText(this.build(form, indent), start, end, 'end');
            // line numbers, change tracking of the form; height when shown again (_showLast())
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
            this.target = null;
            // focus back to the code field instead of the selection list (cApp.a11y.restoreFocus())
            $(textarea).closest('.c9-layer').data('c9-return-focus', textarea);
            cApp.layers.close();
        }
    };
})(window.cApp);
