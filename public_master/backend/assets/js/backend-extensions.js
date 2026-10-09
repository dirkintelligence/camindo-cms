/*
 * cApp.extensions – Field extensions (developer docs, chapter 08 (Components and extensions))
 *
 * An input field with an extension carries class="c9-ext" data-c9-ext="calendar"
 * data-c9-ext-options='{…}' (Backend::formField()). The assets of the active
 * extensions are listed as a manifest in cConfig.extensions (backend/index.php) and
 * are only loaded when a field needs them (ensure()). Each extension
 * registers a handler when its script is loaded:
 *
 *     cApp.extensions.register('calendar', {
 *         init(el, options)  { … },          // upgrade the element; changes: cApp.forms.markChanged(el)
 *         beforeSubmit(el)   { … },          // write the value back to el.value (optional)
 *         destroy(el)        { … }           // remove instances (optional)
 *     });
 *
 * Three hooks in the frame: cLoadContent (destroyAll before, refreshAll after inserting
 * the response), cApp.forms._bindAjaxSubmit (beforeSubmit) and cApp.layers.close
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
         * Load the assets of an extension once (CSS in parallel, JS in order).
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
         * Initialize all fields with an extension in $root (after cLoadContent).
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
         * Before a form is submitted: editor contents back into the fields.
         */
        beforeSubmit($form) {
            $form.find('.c9-ext').each((i, el) => {
                const handler = this._handler(el);
                if (handler && handler.beforeSubmit) handler.beforeSubmit(el);
            });
        },

        /**
         * Remove instances in $root (layer is replaced or closed).
         */
        destroyAll($root) {
            $root.find('.c9-ext').each((i, el) => {
                const handler = this._handler(el);
                if (handler && handler.destroy && el.dataset.c9ExtReady) handler.destroy(el);
                delete el.dataset.c9ExtReady;
            });
        },

        /**
         * Field input mask (module Area): the extension selection (select.c9-ext-select)
         * follows the display type - only matching extensions remain selectable
         * (data-ext-types) -, and when the extension changes its options are
         * reloaded (data-ext-url into the element data-ext-target).
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
