/*
 * Extension IconPicker - Handler für cApp.extensions (13 §3.4)
 *
 * Hängt an ein Textfeld, das einen Material-Icon-Namen trägt (z. B. area.icon),
 * ein Suffix mit der Vorschau des Icons und einem Knopf, der das Auswahlfenster
 * öffnet. Das Fenster zeigt die Icons einer Kategorie (blätterbar) oder - sobald
 * etwas im Suchfeld steht - alle Icons, deren Name oder Tags jeden Suchbegriff
 * enthalten. Ein Klick schreibt den Icon-Namen ins Feld.
 *
 * Index und Texte kommen einmal je Seite von backendExtensions/IconPicker/index.php
 * (data/reduced-index.json, Sprache des Admins); die Icons selbst zeichnet der
 * Backend-Font ("icon"-Klasse, Ligaturen). Keine Optionen.
 */
(function (cApp) {
    'use strict';

    let dataPromise = null;

    function loadData() {
        if (!dataPromise) {
            dataPromise = fetch(window.cConfig.backend_path + 'Extensions/IconPicker/index.php?v9-0-0', { credentials: 'same-origin' })
                .then((response) => {
                    if (!response.ok) throw new Error('IconPicker: HTTP ' + response.status);
                    return response.json();
                })
                .then((data) => {
                    // Suchtext je Icon einmal vorbereiten: Name (mit Leerzeichen statt
                    // Unterstrich) und Tags, kleingeschrieben
                    data.icons.forEach((icon) => {
                        icon.search = (icon.name.replace(/_/g, ' ') + ' ' + icon.name + ' ' + (icon.tags || []).join(' ')).toLowerCase();
                    });
                    data.byCategory = {};
                    data.categories.forEach((category) => { data.byCategory[category.name] = []; });
                    data.icons.forEach((icon) => {
                        (icon.categories || []).forEach((name) => {
                            if (data.byCategory[name]) data.byCategory[name].push(icon);
                        });
                    });
                    return data;
                })
                .catch((err) => { dataPromise = null; throw err; });
        }
        return dataPromise;
    }

    function el(tag, className, html) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (html !== undefined) node.innerHTML = html;
        return node;
    }

    function icon(name, extraClass) {
        const span = el('span', 'icon' + (extraClass ? ' ' + extraClass : ''));
        span.setAttribute('aria-hidden', 'true');
        span.textContent = name;
        return span;
    }

    class Picker {

        constructor(input) {
            this.input = input;
            this.data = null;
            this.categoryIndex = 0;
            this.query = '';
            this.open = false;

            // Suffix: Vorschau + Knopf. Das Feld steckt entweder schon in einer
            // .input-group (Backend::formField() mit prefix/suffix) oder bekommt eine.
            let group = input.closest('.input-group');
            if (!group) {
                group = el('div', 'input-group');
                input.parentNode.insertBefore(group, input);
                group.appendChild(input);
            }
            this.group = group;
            this.wrapper = el('div', 'c9-iconpicker');
            group.parentNode.insertBefore(this.wrapper, group);
            this.wrapper.appendChild(group);

            this.preview = el('span', 'input-group-text c9-iconpicker-preview');
            this.previewIcon = icon('', 'icon-lg');
            this.preview.appendChild(this.previewIcon);
            this.button = el('button', 'btn btn-outline-secondary c9-iconpicker-open');
            this.button.type = 'button';
            this.button.appendChild(icon('add'));
            group.appendChild(this.preview);
            group.appendChild(this.button);

            this.onInput = () => this.updatePreview();
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
            // Texte (Knopf-Titel) sobald die Daten da sind - das Fenster selbst wird
            // erst beim Öffnen gebaut
            loadData().then((data) => {
                this.data = data;
                this.button.title = data.texts.speak_open;
                this.button.setAttribute('aria-label', data.texts.speak_open);
            }).catch((err) => console.error(err.message));
        }

        updatePreview() {
            const name = this.input.value.trim();
            this.previewIcon.textContent = name;
            this.preview.classList.toggle('c9-iconpicker-empty', name === '');
        }

        show() {
            loadData().then((data) => {
                this.data = data;
                if (!this.panel) this.buildPanel();
                // Kategorie des aktuellen Icons voreinstellen
                const current = this.input.value.trim();
                if (current !== '' && this.query === '') {
                    const hit = data.icons.find((i) => i.name === current);
                    if (hit && hit.categories && hit.categories.length) {
                        const idx = data.categories.findIndex((c) => c.name === hit.categories[0]);
                        if (idx >= 0) this.categoryIndex = idx;
                    }
                }
                this.panel.hidden = false;
                this.open = true;
                this.button.setAttribute('aria-expanded', 'true');
                this.render();
                this.search.focus();
            }).catch((err) => {
                console.error(err.message);
                cApp.ui && cApp.ui.alert ? cApp.ui.alert(err.message) : window.alert(err.message);
            });
        }

        close() {
            if (!this.panel) return;
            this.panel.hidden = true;
            this.open = false;
            this.button.setAttribute('aria-expanded', 'false');
        }

        buildPanel() {
            const texts = this.data.texts;
            const panel = el('div', 'c9-iconpicker-panel');
            panel.hidden = true;
            panel.setAttribute('role', 'dialog');

            // Kopf: Suche, Schließen
            const head = el('div', 'c9-iconpicker-head');
            this.search = el('input', 'form-control form-control-sm');
            this.search.type = 'search';
            this.search.placeholder = texts.placeholder_search;
            this.search.setAttribute('aria-label', texts.placeholder_search);
            this.search.addEventListener('input', () => {
                this.query = this.search.value.trim().toLowerCase();
                this.render();
            });
            const closeBtn = el('button', 'btn btn-sm btn-light c9-iconpicker-close');
            closeBtn.type = 'button';
            closeBtn.title = texts.speak_close;
            closeBtn.setAttribute('aria-label', texts.speak_close);
            closeBtn.appendChild(icon('close'));
            closeBtn.addEventListener('click', () => { this.close(); this.button.focus(); });
            head.appendChild(this.search);
            head.appendChild(closeBtn);

            // Kategorie-Navigation: zurück, Titel, vor
            const nav = el('div', 'c9-iconpicker-nav');
            this.prevBtn = el('button', 'btn btn-sm btn-light');
            this.prevBtn.type = 'button';
            this.prevBtn.title = texts.speak_prev;
            this.prevBtn.setAttribute('aria-label', texts.speak_prev);
            this.prevBtn.appendChild(icon('chevron_left', 'icon-dir'));
            this.prevBtn.addEventListener('click', () => this.step(-1));
            this.nextBtn = el('button', 'btn btn-sm btn-light');
            this.nextBtn.type = 'button';
            this.nextBtn.title = texts.speak_next;
            this.nextBtn.setAttribute('aria-label', texts.speak_next);
            this.nextBtn.appendChild(icon('chevron_right', 'icon-dir'));
            this.nextBtn.addEventListener('click', () => this.step(1));
            this.title = el('div', 'c9-iconpicker-title');
            this.title.setAttribute('aria-live', 'polite');
            nav.appendChild(this.prevBtn);
            nav.appendChild(this.title);
            nav.appendChild(this.nextBtn);
            this.nav = nav;

            // Raster
            this.grid = el('div', 'c9-iconpicker-grid');
            this.grid.addEventListener('click', (e) => {
                const btn = e.target.closest('button[data-icon]');
                if (btn) this.select(btn.dataset.icon);
            });

            panel.appendChild(head);
            panel.appendChild(nav);
            panel.appendChild(this.grid);
            this.wrapper.appendChild(panel);
            this.panel = panel;
        }

        step(delta) {
            const n = this.data.categories.length;
            this.categoryIndex = (this.categoryIndex + delta + n) % n;
            this.render();
        }

        // Treffer der Suche: jeder Begriff muss in Name oder Tags vorkommen
        matches() {
            const terms = this.query.split(/\s+/).filter(Boolean);
            return this.data.icons.filter((i) => terms.every((t) => i.search.includes(t)));
        }

        render() {
            const texts = this.data.texts;
            const current = this.input.value.trim();
            let icons;
            if (this.query !== '') {
                icons = this.matches();
                this.nav.classList.add('c9-iconpicker-nav-search');
                this.prevBtn.disabled = this.nextBtn.disabled = true;
                this.title.textContent = texts.label_results.replace('{count}', String(icons.length));
            }
            else {
                const category = this.data.categories[this.categoryIndex];
                icons = this.data.byCategory[category.name];
                this.nav.classList.remove('c9-iconpicker-nav-search');
                this.prevBtn.disabled = this.nextBtn.disabled = false;
                this.title.textContent = category.label + ' (' + icons.length + ')';
            }
            const frag = document.createDocumentFragment();
            // "Kein Icon" - leert das Feld
            const none = el('button', 'c9-iconpicker-item c9-iconpicker-none' + (current === '' ? ' active' : ''));
            none.type = 'button';
            none.dataset.icon = '';
            none.title = texts.label_none;
            none.appendChild(icon('block'));
            frag.appendChild(none);
            icons.forEach((i) => {
                const btn = el('button', 'c9-iconpicker-item' + (i.name === current ? ' active' : ''));
                btn.type = 'button';
                btn.dataset.icon = i.name;
                btn.title = i.name;
                btn.appendChild(icon(i.name));
                frag.appendChild(btn);
            });
            if (!icons.length) frag.appendChild(el('div', 'c9-iconpicker-empty-note text-muted', texts.label_no_results));
            this.grid.replaceChildren(frag);
            this.grid.scrollTop = 0;
        }

        select(name) {
            this.input.value = name;
            this.updatePreview();
            cApp.forms.markChanged(this.input);
            this.input.dispatchEvent(new Event('change', { bubbles: true }));
            this.close();
            this.button.focus();
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
            // Hülle wieder entfernen; eine selbst angelegte .input-group ebenso
            this.wrapper.parentNode.insertBefore(this.group, this.wrapper);
            this.wrapper.remove();
            if (this.group.children.length === 1 && this.group.firstElementChild === this.input) {
                this.group.parentNode.insertBefore(this.input, this.group);
                this.group.remove();
            }
        }
    }

    cApp.extensions.register('iconpicker', {

        init(el) {
            el._c9IconPicker = new Picker(el);
            el.classList.add('c9-iconpicker-input');
        },

        destroy(el) {
            if (el._c9IconPicker) {
                el._c9IconPicker.destroy();
                delete el._c9IconPicker;
            }
            el.classList.remove('c9-iconpicker-input');
        }
    });

}(window.cApp));
