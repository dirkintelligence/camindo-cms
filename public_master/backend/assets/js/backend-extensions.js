/*
 * cApp.extensions – Feld-Extensions (docs/developers/13-component-management.md, 3.4)
 *
 * Ein Eingabefeld mit Extension trägt class="c9-ext" data-c9-ext="calendar"
 * data-c9-ext-options='{…}' (Backend::formField()). Die Assets der aktiven
 * Extensions stehen als Manifest in cConfig.extensions (backend/index.php) und
 * werden erst geladen, wenn ein Feld sie braucht (ensure()). Jede Extension
 * registriert beim Laden ihres Scripts einen Handler:
 *
 *     cApp.extensions.register('calendar', {
 *         init(el, options)  { … },          // Element aufrüsten; Änderungen: cApp.forms.markChanged(el)
 *         beforeSubmit(el)   { … },          // Wert nach el.value zurückschreiben (optional)
 *         destroy(el)        { … }           // Instanzen entfernen (optional)
 *     });
 *
 * Drei Hooks im Rahmen: cLoadContent (destroyAll vor, refreshAll nach dem Einsetzen
 * der Antwort), cApp.forms._bindAjaxSubmit (beforeSubmit) und cApp.layers.close
 * (destroyAll).
 */
(function (cApp) {
    'use strict';

    cApp.extensions = {

        _registry: {},   // name -> handler
        _loaded:   {},   // name -> Promise

        register(name, handler) {
            this._registry[name] = handler;
        },

        /**
         * Assets einer Extension einmalig laden (CSS parallel, JS in Reihenfolge).
         * @returns {Promise}
         */
        ensure(name) {
            if (!this._loaded[name]) {
                const manifest = (window.cConfig.extensions || {})[name];
                if (!manifest) {
                    this._loaded[name] = Promise.reject(new Error('extension not active: ' + name));
                }
                else {
                    (manifest.css || []).forEach((href) => {
                        if (!document.querySelector('link[href="' + href + '"]')) {
                            const link = document.createElement('link');
                            link.rel = 'stylesheet';
                            link.href = href;
                            document.head.appendChild(link);
                        }
                    });
                    this._loaded[name] = (manifest.js || []).reduce(
                        (chain, src) => chain.then(() => this._loadScript(src)),
                        Promise.resolve()
                    );
                }
            }
            return this._loaded[name];
        },

        /**
         * Alle Felder mit Extension in $root initialisieren (nach cLoadContent).
         */
        refreshAll($root) {
            $root.find('.c9-ext').each((i, el) => {
                if (el.dataset.c9ExtReady) return;
                const name = el.dataset.c9Ext;
                this.ensure(name).then(() => {
                    const handler = this._registry[name];
                    if (!handler || !el.isConnected) return;
                    let options = {};
                    try { options = JSON.parse(el.dataset.c9ExtOptions || '{}'); } catch (e) { options = {}; }
                    el.dataset.c9ExtReady = '1';
                    handler.init(el, options);
                }).catch((err) => console.error('cApp.extensions:', err.message));
            });
        },

        /**
         * Vor dem Absenden eines Formulars: Editorinhalte in die Felder zurück.
         */
        beforeSubmit($form) {
            $form.find('.c9-ext').each((i, el) => {
                const handler = this._handler(el);
                if (handler && handler.beforeSubmit) handler.beforeSubmit(el);
            });
        },

        /**
         * Instanzen in $root entfernen (Layer wird ersetzt oder geschlossen).
         */
        destroyAll($root) {
            $root.find('.c9-ext').each((i, el) => {
                const handler = this._handler(el);
                if (handler && handler.destroy && el.dataset.c9ExtReady) handler.destroy(el);
                delete el.dataset.c9ExtReady;
            });
        },

        /**
         * Feld-Eingabemaske (Modul Area): die Extension-Auswahl (select.c9-ext-select)
         * folgt dem Display-Typ - nur passende Extensions bleiben wählbar
         * (data-ext-types) -, und beim Wechsel der Extension werden ihre Optionen
         * nachgeladen (data-ext-url in das Element data-ext-target).
         */
        fieldForm: {
            init() {
                $(document).on('change', 'select[name="data[display_type]"]', (e) => {
                    const $form = $(e.currentTarget).closest('form');
                    const $select = $form.find('select.c9-ext-select');
                    if (!$select.length) return;
                    this.filter($select[0], String($(e.currentTarget).val()));
                });
                $(document).on('change', 'select.c9-ext-select', (e) => {
                    this.loadOptions(e.currentTarget);
                });
            },

            filter(select, displayType) {
                let types = {};
                try { types = JSON.parse(select.dataset.extTypes || '{}'); } catch (err) { types = {}; }
                let changed = false;
                Array.from(select.options).forEach((opt) => {
                    const ok = opt.value === '' || (types[opt.value] || []).includes(displayType);
                    opt.hidden = !ok;
                    opt.disabled = !ok;
                    if (!ok && opt.selected) { opt.selected = false; changed = true; }
                });
                if (changed) {
                    select.value = '';
                    this.loadOptions(select);
                }
            },

            loadOptions(select) {
                const $target = $('#' + select.dataset.extTarget);
                if (!$target.length) return;
                if (select.value === '') { $target.empty(); return; }
                const $form = $(select).closest('form');
                $target.cLoadContent({
                    url:    select.dataset.extUrl + '&extension=' + encodeURIComponent(select.value),
                    params: new FormData($form[0]),
                    method: 'POST',
                    spinner: false
                });
            }
        },

        _handler(el) {
            return this._registry[el.dataset.c9Ext] || null;
        },

        _loadScript(src) {
            return new Promise((resolve, reject) => {
                if (document.querySelector('script[src="' + src + '"]')) { resolve(); return; }
                const script = document.createElement('script');
                script.src = src;
                script.onload = resolve;
                script.onerror = () => reject(new Error('could not load ' + src));
                document.head.appendChild(script);
            });
        }
    };

}(window.cApp));
