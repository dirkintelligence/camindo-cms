/*
 * cApp.quicksearch – quick search of the backend (Ctrl+G, macOS Cmd+G)
 *
 * Opens a search field at the top centre of the window (also on phones: button with the
 * magnifier in the header). The field works with prefixes - one per module:
 *
 *     area:  content:  instance:  template:  converter:  tool:  snippet:  user:
 *     group:  trash:  task:
 *
 * Without a colon the list below shows the prefixes the user may use; typing shortens
 * it, Arrow down/up selects, Enter (or a click) writes the prefix including the colon
 * into the field. After the colon every keystroke restarts a timer (DELAY); only when
 * it runs out the server searches - fast typing produces one request. The hits come in
 * two groups: main field starts with the term, main field contains it.
 *
 *     Arrow up/down   select a hit (the focus stays in the field, aria-activedescendant)
 *     Enter on a hit  open its info page as a new layer
 *     Enter otherwise the search result of the module (full-text search) or its overview
 *     Escape          close; Ctrl+G again in the field closes as well
 *
 * Server: Start/quicksearch.php (StartQuickSearch) - ?texts=1 once per page for the
 * texts and prefixes, ?q=prefix:term for the hits, ?q=…&submit=1 only for the layers
 * of Enter. Only GET, so no CSRF action; the token goes along all the same.
 *
 * Loaded after backend-extensions.js, started by backend-init.js. The DOM is built
 * here (no handlers in HTML, developer docs, chapter 14 (Security: web)).
 */
(function ($, cApp) {
    'use strict';

    const isMac = /Mac|iPhone|iPad|iPod/.test(navigator.platform || navigator.userAgent || '');
    const ENDPOINT = 'Start/quicksearch.php';
    const DELAY = 1000;

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = text;
        return node;
    }

    function icon(name) {
        const span = el('span', 'icon', name);
        span.setAttribute('aria-hidden', 'true');
        return span;
    }

    cApp.quicksearch = {

        _root: null,
        _input: null,
        _list: null,
        _status: null,
        _texts: null,
        _prefixes: [],
        _token: null,          // CSRF token the texts were loaded with (new login = reload)
        _loading: null,        // promise of the texts
        _timer: null,
        _request: 0,           // number of the latest search - older answers are dropped
        _options: [],          // selectable entries of the list: {type, prefix|item}
        _active: -1,
        _returnFocus: null,

        init() {
            document.addEventListener('keydown', (e) => {
                const mod = isMac ? (e.metaKey && !e.ctrlKey) : (e.ctrlKey && !e.metaKey);
                if (!mod || e.altKey || e.shiftKey || (e.key || '').toLowerCase() !== 'g') return;
                if (!this._loggedIn()) return;
                e.preventDefault();
                this.toggle();
            });
            $(document).on('click', '#c9-quicksearch-btn', (e) => {
                e.preventDefault();
                if (this._loggedIn()) this.open();
            });
        },

        _loggedIn() {
            return !!(window.cConfig && cConfig.csrf_token);
        },

        isOpen() {
            return !!(this._root && !this._root.hidden);
        },

        toggle() {
            if (this.isOpen()) {
                if (document.activeElement === this._input) this.close();
                else this._input.focus();
                return;
            }
            this.open();
        },

        open() {
            this._build();
            const active = document.activeElement;
            this._returnFocus = (active && active !== document.body && !this._root.contains(active)) ? active : null;
            this._root.hidden = false;
            document.documentElement.classList.add('c9-qs-open');
            $('#c9-quicksearch-btn').attr('aria-expanded', 'true');
            this._input.value = '';
            this._input.focus();
            this._ensureTexts().then(() => this._update(true)).catch(() => this._message(cConfig.message_connection_error || 'Error'));
        },

        close() {
            if (!this._root) return;
            clearTimeout(this._timer);
            this._request++;
            this._root.hidden = true;
            document.documentElement.classList.remove('c9-qs-open');
            $('#c9-quicksearch-btn').attr('aria-expanded', 'false');
            this._clearList();
            const target = this._returnFocus;
            this._returnFocus = null;
            if (target && document.contains(target)) target.focus({ preventScroll: true });
        },

        // --- DOM ---

        _build() {
            if (this._root) return;
            const root = el('div', 'c9-qs');
            root.hidden = true;
            const backdrop = el('div', 'c9-qs-backdrop');
            const dialog = el('div', 'c9-qs-dialog shadow-lg');
            dialog.setAttribute('role', 'dialog');
            dialog.setAttribute('aria-modal', 'true');
            dialog.setAttribute('aria-labelledby', 'c9-qs-label');

            const label = el('label', 'visually-hidden');
            label.id = 'c9-qs-label';
            label.htmlFor = 'c9-qs-input';

            const field = el('div', 'c9-qs-field');
            const input = el('input', 'c9-qs-input');
            input.id = 'c9-qs-input';
            input.type = 'search';
            input.autocomplete = 'off';
            input.spellcheck = false;
            input.setAttribute('autocapitalize', 'off');
            input.setAttribute('enterkeyhint', 'search');
            input.setAttribute('role', 'combobox');
            input.setAttribute('aria-autocomplete', 'list');
            input.setAttribute('aria-expanded', 'false');
            input.setAttribute('aria-controls', 'c9-qs-list');
            const closeBtn = el('button', 'btn c9-qs-close');
            closeBtn.type = 'button';
            closeBtn.appendChild(icon('close'));
            const closeText = el('span', 'visually-hidden');
            closeBtn.appendChild(closeText);
            field.append(icon('search'), input, closeBtn);

            const list = el('div', 'c9-qs-list');
            list.id = 'c9-qs-list';
            list.setAttribute('role', 'listbox');
            list.setAttribute('aria-labelledby', 'c9-qs-label');

            const status = el('div', 'c9-qs-status');
            status.setAttribute('role', 'status');
            status.setAttribute('aria-live', 'polite');

            dialog.append(label, field, list, status);
            root.append(backdrop, dialog);
            document.body.appendChild(root);

            this._root = root;
            this._input = input;
            this._list = list;
            this._status = status;
            this._closeText = closeText;
            this._label = label;

            backdrop.addEventListener('click', () => this.close());
            closeBtn.addEventListener('click', () => this.close());
            input.addEventListener('input', () => this._update(false));
            input.addEventListener('keydown', (e) => this._keydown(e));
            // keep the focus inside the dialog (Tab between field and close button)
            dialog.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    e.stopPropagation();
                    this.close();
                }
                if (e.key === 'Tab') {
                    e.preventDefault();
                    (document.activeElement === input ? closeBtn : input).focus();
                }
            });
            list.addEventListener('mousedown', (e) => e.preventDefault()); // focus stays in the field
            list.addEventListener('click', (e) => {
                const option = e.target.closest('[data-index]');
                if (option) this._choose(parseInt(option.getAttribute('data-index'), 10));
            });
        },

        _applyTexts() {
            const t = this._texts || {};
            this._label.textContent = t.label || '';
            this._input.placeholder = t.placeholder || '';
            this._closeText.textContent = t.close || '';
            this._root.querySelector('.c9-qs-close').title = t.close || '';
        },

        // --- server ---

        _get(params) {
            const query = Object.keys(params).map((k) => encodeURIComponent(k) + '=' + encodeURIComponent(params[k])).join('&');
            return fetch(cConfig.backend_path + 'Modules/' + ENDPOINT + '?' + query, {
                credentials: 'same-origin',
                headers: Object.assign({ 'Accept': 'application/json' }, cApp.system.csrfHeaders())
            }).then((response) => {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.text();
            }).then((text) => {
                if (text.trim() === '##accessdenied##') {
                    this.close();
                    cApp.layers.login();
                    throw new Error('access denied');
                }
                return JSON.parse(text);
            });
        },

        _ensureTexts() {
            const token = cConfig.csrf_token || '';
            if (this._loading && this._token === token) return this._loading;
            this._token = token;
            this._loading = this._get({ texts: 1 }).then((data) => {
                this._texts = data.texts || {};
                this._prefixes = Array.isArray(data.prefixes) ? data.prefixes : [];
                this._applyTexts();
            }).catch((err) => {
                this._loading = null;
                throw err;
            });
            return this._loading;
        },

        // --- input ---

        /** Split the field value into prefix and term; prefix null = no colon yet */
        _parse() {
            const value = this._input.value;
            const pos = value.indexOf(':');
            if (pos < 0) return { prefix: null, text: value.trim() };
            return { prefix: value.slice(0, pos).trim().toLowerCase(), term: value.slice(pos + 1).trim() };
        },

        _knownPrefix(prefix) {
            return this._prefixes.some((p) => p.prefix === prefix);
        },

        /**
         * After each input: without a colon filter the prefixes at once, with a colon
         * start the timer of the search.
         * @param {boolean} immediate  search without waiting (on opening)
         */
        _update(immediate) {
            if (!this._texts) return;
            clearTimeout(this._timer);
            this._request++;
            const parsed = this._parse();
            if (parsed.prefix === null) {
                this._showPrefixes(parsed.text);
                return;
            }
            if (!this._knownPrefix(parsed.prefix)) {
                this._clearList();
                this._message(this._texts.unknown_prefix);
                return;
            }
            if (parsed.term === '') {
                this._clearList();
                this._message(this._texts.hint_overview);
                return;
            }
            this._message(this._texts.searching);
            const run = () => this._search(parsed.prefix, parsed.term);
            if (immediate) run();
            else this._timer = setTimeout(run, DELAY);
        },

        _search(prefix, term) {
            const request = ++this._request;
            this._get({ q: prefix + ':' + term }).then((data) => {
                if (request !== this._request || !this.isOpen()) return;
                this._showHits(data);
            }).catch(() => {
                if (request === this._request) this._message(cConfig.message_connection_error || 'Error');
            });
        },

        _keydown(e) {
            if (e.isComposing) return;
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                if (!this._options.length) return;
                e.preventDefault();
                const step = e.key === 'ArrowDown' ? 1 : -1;
                let next = this._active + step;
                if (next < 0) next = this._options.length - 1;
                if (next >= this._options.length) next = 0;
                this._setActive(next);
                return;
            }
            if (e.key === 'Enter') {
                e.preventDefault();
                if (this._active >= 0) {
                    this._choose(this._active);
                    return;
                }
                this._submit();
            }
        },

        /** Enter without a selection */
        _submit() {
            const parsed = this._parse();
            if (parsed.prefix === null) {
                // the first prefix of the (shortened) list
                if (this._options.length && this._options[0].type === 'prefix') this._choose(0);
                return;
            }
            if (!this._knownPrefix(parsed.prefix)) return;
            clearTimeout(this._timer);
            this._request++;
            this._get({ q: parsed.prefix + ':' + parsed.term, submit: 1 }).then((data) => {
                this._openPaths(data.submit);
            }).catch(() => this._message(cConfig.message_connection_error || 'Error'));
        },

        _choose(index) {
            const option = this._options[index];
            if (!option) return;
            if (option.type === 'prefix') {
                this._input.value = option.prefix + ':';
                this._input.focus();
                this._update(false);
                return;
            }
            this._openPaths([option.item.path]);
        },

        /** Close and open the layers one after the other (only module paths) */
        _openPaths(paths) {
            paths = (Array.isArray(paths) ? paths : []).filter((p) => typeof p === 'string' && /^[A-Za-z]+\/[a-z_]+\.php(\?|$)/.test(p));
            if (!paths.length) return;
            this._returnFocus = null;
            this.close();
            paths.forEach((path) => cApp.layers.add(path));
        },

        // --- list ---

        _clearList() {
            this._options = [];
            this._active = -1;
            if (!this._list) return;
            this._list.textContent = '';
            this._input.setAttribute('aria-expanded', 'false');
            this._input.removeAttribute('aria-activedescendant');
        },

        _message(text) {
            if (this._status) this._status.textContent = text || '';
        },

        _setActive(index) {
            this._active = index;
            const nodes = this._list.querySelectorAll('[role="option"]');
            nodes.forEach((node) => {
                const on = parseInt(node.getAttribute('data-index'), 10) === index;
                node.classList.toggle('active', on);
                node.setAttribute('aria-selected', on ? 'true' : 'false');
                if (on) {
                    this._input.setAttribute('aria-activedescendant', node.id);
                    node.scrollIntoView({ block: 'nearest' });
                }
            });
            if (index < 0) this._input.removeAttribute('aria-activedescendant');
        },

        _group(headline, id) {
            const group = el('div', 'c9-qs-group');
            group.setAttribute('role', 'group');
            const head = el('div', 'c9-qs-group-head', headline);
            head.id = id;
            group.setAttribute('aria-labelledby', id);
            group.appendChild(head);
            this._list.appendChild(group);
            return group;
        },

        _option(group, index, iconName, title, detail, sub) {
            const node = el('div', 'c9-qs-option');
            node.id = 'c9-qs-option-' + index;
            node.setAttribute('role', 'option');
            node.setAttribute('aria-selected', 'false');
            node.setAttribute('data-index', String(index));
            const text = el('div', 'c9-qs-text');
            const line = el('div', 'c9-qs-title');
            line.appendChild(el('span', 'c9-qs-title-text', title));
            if (detail) line.appendChild(el('span', 'c9-qs-detail', detail));
            text.appendChild(line);
            if (sub) text.appendChild(el('div', 'c9-qs-sub', sub));
            node.append(icon(iconName), text);
            group.appendChild(node);
        },

        _showPrefixes(text) {
            this._clearList();
            const needle = text.toLowerCase();
            const prefixes = this._prefixes.filter((p) => needle === ''
                || p.prefix.startsWith(needle) || String(p.label).toLowerCase().startsWith(needle));
            if (!prefixes.length) {
                this._message(this._texts.unknown_prefix);
                return;
            }
            const group = this._group(this._texts.headline_prefixes, 'c9-qs-head-prefixes');
            prefixes.forEach((p, i) => {
                this._options.push({ type: 'prefix', prefix: p.prefix });
                this._option(group, i, p.icon, p.prefix + ':', p.label, '');
            });
            this._input.setAttribute('aria-expanded', 'true');
            this._message(this._texts.hint);
        },

        _showHits(data) {
            this._clearList();
            let index = 0;
            [['starts', 'headline_starts'], ['contains', 'headline_contains']].forEach(([key, headline]) => {
                const items = Array.isArray(data[key]) ? data[key] : [];
                if (!items.length) return;
                const group = this._group(this._texts[headline], 'c9-qs-head-' + key);
                items.forEach((item) => {
                    this._options.push({ type: 'item', item: item });
                    this._option(group, index++, item.icon || 'search', item.title, item.detail, item.sub);
                });
            });
            if (index) {
                this._input.setAttribute('aria-expanded', 'true');
                this._message(this._texts.hint_submit);
            } else {
                this._message(this._texts.no_results + ' ' + this._texts.hint_submit);
            }
        }
    };
})(jQuery, window.cApp);
