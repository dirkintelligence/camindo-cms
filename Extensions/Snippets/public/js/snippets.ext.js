/*
 * Extension Snippets - handler for cApp.extensions (developer docs, chapter 08 (Components and extensions))
 *
 * Attaches to a text field (e.g. the 'label' fields of the modules) a suffix with the
 * resolved text as soon as the value starts with '#', and a button that opens the
 * search window. There all snippets of the cache are searched -
 * core, modules, processors, extensions and the instance sets of the module Snippets
 * (custom.{instance_id}.*, only instances with access). A click on a hit
 * writes '#' + snippet id into the field (Cms::textOrSnippet() resolves it on display).
 *
 * Search, resolution and the texts of the user interface come from
 * backendExtensions/Snippets/search.php (language of the admin). No options.
 */
(function (cApp) {
    'use strict';

    const ENDPOINT = window.cConfig.backend_path + 'Extensions/Snippets/search.php?v9-0-0';
    let texts = null;
    let textsPromise = null;

    // Fetch the texts of the user interface once per page
    function loadTexts() {
        if (!textsPromise) {
            textsPromise = request({ texts: 1 }).then(() => texts).catch((err) => { textsPromise = null; throw err; });
        }
        return textsPromise;
    }

    function request(params) {
        const query = Object.keys(params).map((k) => encodeURIComponent(k) + '=' + encodeURIComponent(params[k])).join('&');
        return fetch(ENDPOINT + '&' + query, { credentials: 'same-origin' })
            .then((response) => {
                if (!response.ok) throw new Error('Snippets: HTTP ' + response.status);
                return response.json();
            })
            .then((data) => {
                if (data.texts) texts = data.texts;
                return data;
            });
    }

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function icon(name) {
        const span = el('span', 'icon', name);
        span.setAttribute('aria-hidden', 'true');
        return span;
    }

    function debounce(fn, ms) {
        let timer = null;
        return function () {
            clearTimeout(timer);
            const args = arguments;
            timer = setTimeout(() => fn.apply(null, args), ms);
        };
    }

    class Picker {

        constructor(input) {
            this.input = input;
            this.open = false;
            this.query = '';

            // Suffix (preview) + button. The field either already sits in an
            // .input-group (Backend::formField() with prefix/suffix) or gets one.
            let group = input.closest('.input-group');
            if (!group) {
                group = el('div', 'input-group');
                input.parentNode.insertBefore(group, input);
                group.appendChild(input);
            }
            this.group = group;
            this.wrapper = el('div', 'c9-snippets');
            group.parentNode.insertBefore(this.wrapper, group);
            this.wrapper.appendChild(group);

            this.preview = el('span', 'input-group-text c9-snippets-preview');
            this.preview.hidden = true;
            this.button = el('button', 'btn btn-outline-secondary c9-snippets-open');
            this.button.type = 'button';
            this.button.appendChild(icon('translate'));
            group.appendChild(this.preview);
            group.appendChild(this.button);

            this.resolve = debounce(() => this.updatePreview(), 300);
            this.onInput = () => this.resolve();
            this.onButton = () => { this.open ? this.close() : this.show(); };
            this.onDocumentClick = (e) => {
                if (this.open && !this.wrapper.contains(e.target)) this.close();
            };
            this.onKey = (e) => {
                if (this.open && e.key === 'Escape') { e.stopPropagation(); this.close(); this.button.focus(); }
            };
            input.addEventListener('input', this.onInput);
            input.addEventListener('change', this.onInput);
            this.button.addEventListener('click', this.onButton);
            document.addEventListener('click', this.onDocumentClick, true);
            this.wrapper.addEventListener('keydown', this.onKey);

            this.updatePreview();
            loadTexts().then(() => this.setButtonTexts()).catch((err) => console.error(err.message));
        }

        /**
         * Suffix: the resolved text if the value starts with '#'; otherwise empty.
         */
        updatePreview() {
            const value = this.input.value.trim();
            if (value.charAt(0) !== '#' || value.length < 2) {
                this.preview.hidden = true;
                this.preview.textContent = '';
                this.preview.classList.remove('c9-snippets-missing');
                return;
            }
            request({ resolve: value }).then((data) => {
                if (this.input.value.trim() !== value) return;   // typed on in the meantime
                this.preview.hidden = false;
                this.preview.textContent = data.found ? data.text : (texts ? texts.label_not_found : '?');
                this.preview.title = data.found ? data.id : '';
                this.preview.classList.toggle('c9-snippets-missing', !data.found);
            }).catch((err) => console.error(err.message));
        }

        setButtonTexts() {
            this.button.title = texts.speak_open;
            this.button.setAttribute('aria-label', texts.speak_open);
        }

        show() {
            // Open the window immediately (texts are available after loadTexts()), load the hits afterwards
            loadTexts().then(() => {
                const value = this.input.value.trim();
                const initial = value.charAt(0) === '#' ? value.substring(1) : '';
                if (!this.panel) this.buildPanel();
                this.search.value = initial;
                this.query = initial;
                this.panel.hidden = false;
                this.open = true;
                this.button.setAttribute('aria-expanded', 'true');
                this.list.replaceChildren();
                this.title.textContent = '';
                this.search.focus();
                this.search.select();
                this.fetchHits();
            }).catch((err) => {
                console.error(err.message);
                window.alert(err.message);
            });
        }

        fetchHits() {
            const q = this.query;
            request({ q: q }).then((data) => { if (q === this.query && this.open) this.render(data); })
                .catch((err) => console.error(err.message));
        }

        close() {
            if (!this.panel) return;
            this.panel.hidden = true;
            this.open = false;
            this.button.setAttribute('aria-expanded', 'false');
        }

        buildPanel() {
            const panel = el('div', 'c9-snippets-panel');
            panel.hidden = true;
            panel.setAttribute('role', 'dialog');

            const head = el('div', 'c9-snippets-head');
            this.search = el('input', 'form-control form-control-sm');
            this.search.type = 'search';
            this.search.placeholder = texts.placeholder_search;
            this.search.setAttribute('aria-label', texts.placeholder_search);
            this.search.autocomplete = 'off';
            this.search.addEventListener('input', debounce(() => {
                this.query = this.search.value.trim();
                this.fetchHits();
            }, 250));
            const closeBtn = el('button', 'btn btn-sm btn-light c9-snippets-close');
            closeBtn.type = 'button';
            closeBtn.title = texts.speak_close;
            closeBtn.setAttribute('aria-label', texts.speak_close);
            closeBtn.appendChild(icon('close'));
            closeBtn.addEventListener('click', () => { this.close(); this.button.focus(); });
            head.appendChild(this.search);
            head.appendChild(closeBtn);

            this.title = el('div', 'c9-snippets-title text-muted small');
            this.title.setAttribute('aria-live', 'polite');

            this.list = el('div', 'c9-snippets-list');
            this.list.addEventListener('click', (e) => {
                const btn = e.target.closest('button[data-id]');
                if (btn) this.select(btn.dataset.id);
            });

            panel.appendChild(head);
            panel.appendChild(this.title);
            panel.appendChild(this.list);
            this.wrapper.appendChild(panel);
            this.panel = panel;
        }

        render(data) {
            const current = this.input.value.trim().replace(/^#/, '');
            const hits = data.hits || [];
            if (data.total > hits.length) {
                this.title.textContent = texts.label_more.replace('{count}', String(data.total)).replace('{shown}', String(hits.length));
            }
            else {
                this.title.textContent = texts.label_results.replace('{count}', String(data.total));
            }
            const frag = document.createDocumentFragment();
            // First entry: remove '#', the value remains plain text
            if (current !== '' && this.input.value.trim().charAt(0) === '#') {
                const none = el('button', 'c9-snippets-item c9-snippets-none');
                none.type = 'button';
                none.dataset.id = '';
                none.appendChild(icon('block'));
                none.appendChild(el('span', 'c9-snippets-item-text', texts.label_none));
                frag.appendChild(none);
            }
            hits.forEach((hit) => {
                const btn = el('button', 'c9-snippets-item' + (hit.id === current ? ' active' : ''));
                btn.type = 'button';
                btn.dataset.id = hit.id;
                btn.title = '#' + hit.id;
                const id = el('span', 'c9-snippets-item-id', hit.id);
                if (hit.backend) {
                    const badge = el('span', 'badge text-bg-light c9-snippets-badge', texts.label_backend);
                    id.appendChild(badge);
                }
                if (hit.icu) {
                    const badge = el('span', 'badge text-bg-light c9-snippets-badge', texts.label_icu);
                    id.appendChild(badge);
                }
                btn.appendChild(id);
                btn.appendChild(el('span', 'c9-snippets-item-text', hit.text));
                frag.appendChild(btn);
            });
            if (!hits.length) frag.appendChild(el('div', 'c9-snippets-empty text-muted', texts.label_no_results));
            this.list.replaceChildren(frag);
            this.list.scrollTop = 0;
        }

        /**
         * Take over a hit: '#' + id into the field. Empty id: only remove the '#',
         * the previous text stays as plain text.
         */
        select(id) {
            this.input.value = id === '' ? this.input.value.trim().replace(/^#/, '') : '#' + id;
            cApp.forms.markChanged(this.input);
            this.input.dispatchEvent(new Event('change', { bubbles: true }));
            this.updatePreview();
            this.close();
            this.input.focus();
        }

        destroy() {
            this.input.removeEventListener('input', this.onInput);
            this.input.removeEventListener('change', this.onInput);
            this.button.removeEventListener('click', this.onButton);
            document.removeEventListener('click', this.onDocumentClick, true);
            this.wrapper.removeEventListener('keydown', this.onKey);
            if (this.panel) this.panel.remove();
            this.preview.remove();
            this.button.remove();
            // Remove the wrapper again; likewise a self-created .input-group
            this.wrapper.parentNode.insertBefore(this.group, this.wrapper);
            this.wrapper.remove();
            if (this.group.children.length === 1 && this.group.firstElementChild === this.input) {
                this.group.parentNode.insertBefore(this.input, this.group);
                this.group.remove();
            }
        }
    }

    cApp.extensions.register('snippets', {

        init(el) {
            el._c9Snippets = new Picker(el);
            el.classList.add('c9-snippets-input');
        },

        destroy(el) {
            if (el._c9Snippets) {
                el._c9Snippets.destroy();
                delete el._c9Snippets;
            }
            el.classList.remove('c9-snippets-input');
        }
    });

}(window.cApp));
