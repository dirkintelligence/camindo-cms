/*
 * cApp.ui – General UI events
 * - Bootstrap tooltip initialization via event delegation
 * - "Load more" buttons (.c9-load-more)
 * - Auto-resize for textareas (.c9-textarea-grow)
 */
(function (cApp) {
    'use strict';

    cApp.ui = {

        init() {
            this._bindTooltips();
            this._bindLoadMore();
            this._bindTextareaGrow();
            this._bindAjaxTextareaGrow();
            this._bindLoginPrefs();
            this._initTextareaGrow($(document));
            this._initMainMenu();
            this._initSettingsButton();
            this._initLogoutButton();
            this._initBgprocContainer();
            this._bindColumnCheckboxes();
            this._bindBgprocRemove();
        },

        // --- Login form: backend language, locale and time zone ---
        //
        // The last choice is kept in localStorage (keys c9.backend_code,
        // c9.locale, c9.timezone) - stored at login and when saving the
        // personal settings (AdminBackend::settings()). Without an entry
        // the browser language, locale and time zone decide; a language the
        // backend does not know is resolved by the server to 'en_US' (Cms::resolveBackendLang()),
        // an unknown locale to system.locale. Backend languages have the form
        // de_DE; a previously remembered "de" applies to the first option with "de_".

        /**
         * Read the remembered choice.
         * @returns {{backend_code: string, timezone: string, locale: string}}
         */
        loginPrefs() {
            const prefs = { backend_code: '', timezone: '', locale: '' };
            try {
                prefs.backend_code = localStorage.getItem('c9.backend_code') || '';
                prefs.timezone     = localStorage.getItem('c9.timezone') || '';
                prefs.locale       = localStorage.getItem('c9.locale') || '';
            } catch (e) { /* localStorage blocked - then without preselection */ }
            return prefs;
        },

        /**
         * Remember the choice (empty values leave the previous entry in place).
         */
        loginPrefsStore(backendCode, timezone, locale) {
            try {
                if (backendCode) localStorage.setItem('c9.backend_code', backendCode);
                if (timezone)    localStorage.setItem('c9.timezone', timezone);
                if (locale)      localStorage.setItem('c9.locale', locale);
            } catch (e) { /* see loginPrefs() */ }
        },

        /**
         * Synchronize the frontend editing cookie on all websites
         * (FrontendHandover::fedSyncData(), AdminBackend::settings()).
         *
         * For each website first a probe via image (c9probe=1): only domains that are reachable
         * and belong to this installation go into the chain. Then the window runs
         * as a normal navigation through these websites (each sets its cookie)
         * and returns to the backend - the hash is preserved across the redirects,
         * the layers are rebuilt.
         *
         * @param {{token: string, sites: {i: number, origin: string}[]}} data
         * @param {function} [fallback]  no website reachable (e.g. reload)
         */
        fedSync(data, fallback) {
            const param = '/index.php?c9fed=' + encodeURIComponent(data.token) + '&c9hops=';
            const probe = (site) => new Promise((resolve) => {
                const img = new Image();
                const timer = setTimeout(() => resolve(false), 4000);
                img.onload  = () => { clearTimeout(timer); resolve(true); };
                img.onerror = () => { clearTimeout(timer); resolve(false); };
                img.src = site.origin + param + site.i + '&c9probe=1&_=' + Date.now();
            });
            Promise.all(data.sites.map(probe)).then((results) => {
                const sites = data.sites.filter((site, k) => results[k]);
                if (!sites.length) {
                    if (typeof fallback === 'function') fallback();
                    return;
                }
                window.location.href = sites[0].origin + param
                    + sites.map((site) => site.i).join('-') + location.hash;
            });
        },

        /**
         * Draw QR codes: each element with data-c9-qr="…" gets an SVG of its
         * content (setting up the authenticator app, AdminBackend::totp()).
         *
         * Generated in the browser with qrcode-generator (MIT, taken over from cdnjs into
         * assets/qrcode/, only loaded when first needed) - the
         * otpauth:// URI including the secret is not sent to any third-party service.
         *
         * @param {Element} [root]  area, otherwise the whole document
         */
        qrCodes(root) {
            const els = (root || document).querySelectorAll('[data-c9-qr]:not([data-c9-qr-ready])');
            if (!els.length) return;
            this._qrLib = this._qrLib || cApp.extensions._loadScript('assets/qrcode/qrcode.min.js');
            this._qrLib.then(() => {
                els.forEach((el) => {
                    const qr = window.qrcode(0, 'M');
                    qr.addData(el.dataset.c9Qr);
                    qr.make();
                    el.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 4, scalable: true });
                    el.dataset.c9QrReady = '1';
                });
            }).catch((err) => console.error('cApp.ui.qrCodes:', err.message));
        },

        /**
         * Copy the text from data-copy of the button to the clipboard, feedback as a balloon
         * (text from data-copied).
         */
        copyText(button) {
            const text = button.dataset.copy || '';
            if (!navigator.clipboard) return;
            navigator.clipboard.writeText(text).then(() => {
                if (button.dataset.copied) cApp.system.balloon('success', button.dataset.copied);
            });
        },

        // --- Passkeys (WebAuthn, developer docs, chapter 13 (Security: authentication)) ---
        //
        // The server delivers the options as JSON in the attribute data-options of the
        // button, binary values base64url. The device's response ends up in hidden
        // fields of the form, which is then submitted like any other
        // (cApp.forms, Ajax). Errors are shown by the element [data-c9-passkey-error] in the
        // form (texts in data-cancelled / data-failed / data-unsupported).

        _b64uToBuf(text) {
            let s = String(text).replace(/-/g, '+').replace(/_/g, '/');
            while (s.length % 4) s += '=';
            const bin = atob(s);
            const bytes = new Uint8Array(bin.length);
            for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
            return bytes.buffer;
        },

        _bufToB64u(buf) {
            const bytes = new Uint8Array(buf);
            let bin = '';
            for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
            return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
        },

        passkeySupported() {
            return !!(window.PublicKeyCredential && navigator.credentials && window.isSecureContext);
        },

        _passkeyError(button, err) {
            const form = button.closest('form');
            const box = form ? form.querySelector('[data-c9-passkey-error]') : null;
            if (!box) return;
            let text;
            if (!err) text = box.dataset.unsupported || '';
            else if (err.name === 'NotAllowedError' || err.name === 'AbortError') text = box.dataset.cancelled || '';
            else text = (box.dataset.failed || '') + (err.message ? ' (' + err.message + ')' : '');
            box.textContent = text;
            box.classList.remove('d-none');
        },

        _submit(form) {
            if (form.requestSubmit) form.requestSubmit();
            else $(form).trigger('submit');
        },

        /**
         * Login, second stage: get the signature from the device, write it into the field 'passkey'
         * (JSON), submit the form (StartBackend, function=step2).
         */
        async passkeyLogin(button) {
            const form = button.closest('form');
            if (!form) return;
            if (!this.passkeySupported()) { this._passkeyError(button, null); return; }
            const o = JSON.parse(button.dataset.options || '{}');
            const publicKey = Object.assign({}, o, {
                challenge: this._b64uToBuf(o.challenge),
                allowCredentials: (o.allowCredentials || []).map((c) => Object.assign({}, c, { id: this._b64uToBuf(c.id) }))
            });
            button.disabled = true;
            try {
                const cred = await navigator.credentials.get({ publicKey });
                const r = cred.response;
                form.querySelector('input[name="passkey"]').value = JSON.stringify({
                    id: this._bufToB64u(cred.rawId),
                    clientDataJSON: this._bufToB64u(r.clientDataJSON),
                    authenticatorData: this._bufToB64u(r.authenticatorData),
                    signature: this._bufToB64u(r.signature)
                });
                this._submit(form);
            }
            catch (err) {
                this._passkeyError(button, err);
            }
            finally {
                button.disabled = false;
            }
        },

        /**
         * Create a passkey (AdminBackend, function=passkey_add): have the device generate
         * a key pair, response into the fields client_data, attestation and
         * transports, submit the form.
         */
        async passkeyRegister(button) {
            const form = button.closest('form');
            if (!form) return;
            if (!this.passkeySupported()) { this._passkeyError(button, null); return; }
            const o = JSON.parse(button.dataset.options || '{}');
            const publicKey = Object.assign({}, o, {
                challenge: this._b64uToBuf(o.challenge),
                user: Object.assign({}, o.user, { id: this._b64uToBuf(o.user.id) }),
                excludeCredentials: (o.excludeCredentials || []).map((c) => Object.assign({}, c, { id: this._b64uToBuf(c.id) }))
            });
            button.disabled = true;
            try {
                const cred = await navigator.credentials.create({ publicKey });
                const r = cred.response;
                form.querySelector('input[name="client_data"]').value = this._bufToB64u(r.clientDataJSON);
                form.querySelector('input[name="attestation"]').value = this._bufToB64u(r.attestationObject);
                form.querySelector('input[name="transports"]').value = JSON.stringify(r.getTransports ? r.getTransports() : []);
                this._submit(form);
            }
            catch (err) {
                this._passkeyError(button, err);
            }
            finally {
                button.disabled = false;
            }
        },

        /**
         * Locales of the browser in ICU notation (de-AT -> de_AT), only those with
         * a region - only that determines a date format.
         * @returns {string[]}
         */
        browserLocales() {
            const list = (navigator.languages && navigator.languages.length) ? navigator.languages : [navigator.language || ''];
            return list
                .map((tag) => String(tag).replace(/-/g, '_'))
                .filter((tag) => /^[a-z]{2,3}_(?:[A-Z][a-z]{3}_)?[A-Z]{2}$/.test(tag));
        },

        /**
         * Language with which the login form is loaded: the remembered one, otherwise the
         * browser's ("de-AT"; the server chooses de_AT, otherwise the first backend
         * language with "de_", otherwise 'en_US').
         */
        loginPrefLang() {
            const stored = this.loginPrefs().backend_code;
            if (stored) return stored;
            return String(navigator.language || '');
        },

        /**
         * Preselect the select lists of the login form - called after the
         * form has loaded (StartBackend::login()). Only values that exist as an option.
         */
        loginPrefsInit() {
            const prefs = this.loginPrefs();
            const setIfOption = ($select, value) => {
                if (!value || !$select.length) return false;
                if (!$select.find('option[value="' + value + '"]').length) return false;
                $select.val(value);
                return true;
            };

            // Backend language: exact ("de_AT"), otherwise the first option with the same
            // language part ("de" -> "de_DE") - like Cms::matchLanguage()
            const setLangOption = ($select, value) => {
                const parts = String(value || '').split(/[-_]/);
                const base = parts[0].toLowerCase();
                if (!base || !$select.length) return false;
                if (parts.length > 1 && setIfOption($select, base + '_' + parts[parts.length - 1].toUpperCase())) return true;
                const $match = $select.find('option').filter((i, el) => el.value.startsWith(base + '_')).first();
                if (!$match.length) return false;
                $select.val($match.val());
                return true;
            };
            const $lang = $('#login-lang');
            if (!setLangOption($lang, prefs.backend_code)) {
                // Browser language ("de-AT"); unknown -> 'en_US'
                if (!setLangOption($lang, navigator.language)) setIfOption($lang, 'en_US');
            }

            const $tz = $('#login-timezone');
            if (!setIfOption($tz, prefs.timezone)) {
                let browserTz = '';
                try { browserTz = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) {}
                setIfOption($tz, browserTz);
            }

            const $locale = $('#login-locale');
            if (!setIfOption($locale, prefs.locale)) {
                // First browser locale that exists as an option; otherwise system.locale stays
                this.browserLocales().some((tag) => setIfOption($locale, tag));
            }
        },

        /**
         * Remember every change in the login form immediately.
         */
        _bindLoginPrefs() {
            $(document).on('change', 'select.c9-login-pref', (e) => {
                const $el = $(e.currentTarget);
                const pref = $el.data('pref');
                const value = $el.val();
                if (pref === 'backend_code') this.loginPrefsStore(value, '', '');
                if (pref === 'timezone')     this.loginPrefsStore('', value, '');
                if (pref === 'locale')       this.loginPrefsStore('', '', value);
            });
        },

        /**
         * Gear icon at the top right: personal settings as a layer - only once
         * (cApp.layers.addOnce(): a further click is ignored as long as the
         * layer is on top)
         */
        _initSettingsButton() {
            $('#c9-settings-btn').on('click', (e) => {
                e.preventDefault();
                cApp.layers.addOnce('Admin/settings.php');
            });
        },

        /**
         * Logout button at the top right
         */
        _initLogoutButton() {
            $('#c9-logout-btn').on('click', (e) => {
                e.preventDefault();
                cApp.layers.logout();
            });
        },

        /**
         * Input mask of records: switch language tabs.
         *
         * The mask is not simply switched, but saved temporarily via the form
         * (function=tab) and reloaded with the target tab -
         * see RecordBackendEdit::_editTab(). The form carries the hidden
         * fields tab (displayed tab) and tab_target (target).
         *
         * @param {Element} el      clicked tab (located inside the form)
         * @param {string}  target  language_id or 'all'
         */
        recordTab(el, target) {
            const $form = $(el).closest('form');
            if (!$form.length) return;
            $form.find('input[name="function"]').val('tab');
            $form.find('input[name="tab_target"]').val(target);
            if ($form[0].requestSubmit) $form[0].requestSubmit();
            else $form.trigger('submit');
        },

        /**
         * Changes the visibility status of a field
         */
        visibility(el, id, name, value) {
            const $parent = $(el).closest('.form-check').parent();
            // POST: writes visibility_* of the field (developer docs, chapter 14 (Security: web))
            const params = new FormData();
            params.append('id', id);
            params.append('name', name);
            params.append('value', value);
            $parent.cLoadContent({
                url:    'Area/ajax_update_visibility.php',
                params: params,
                method: 'POST'
            });
        },

        displayType(el, targetId, options) {
            const optionsArray = options.split(',');
            const val = $(el).val();
            const $target = $('#' + targetId);
            $target.removeClass('d-none');
            if (!optionsArray.includes(val)) {
                $target.addClass('d-none');
            }
        },

        /**
         * Lookup field: leave with Tab
         */
        lookupTab() {
            const element = event.currentTarget;             
            if ((event.key === 'Tab' || event.key === 'Enter')
                && !event.shiftKey
                && element.value.trim() !== ''
            ) {
                event.preventDefault();
                this.lookupSearch(element);
            }
        },

        /**
         * sLookup field: leave with Tab
         */
        sLookupTab() {
            const element = event.currentTarget;             
            if ((event.key === 'Tab' || event.key === 'Enter')
                && !event.shiftKey
                && element.value.trim() !== ''
            ) {
                event.preventDefault();
                this.slookupSearch(element);
            }
        },

        /**
         * Lookup field: click on the search button
         */
        lookupSearch(forceElement = null) {
            // If called from lookupTab, use the passed element, otherwise event.currentTarget of the click
            const targetElement = forceElement || event.currentTarget;
            const $container = $(targetElement).closest('.c9-lookup-field');
            let searchUrl = $container.data('search-url');
            if (searchUrl) {
                // Append text from the input field
                const searchText = $container.find('input[type="text"]').val();
                if (searchText.trim() !== '') {
                    searchUrl += '&filter[_default]=' + encodeURIComponent(searchText.trim());
                }
                cApp.layers.add(searchUrl, false, false, true);
            }
        },

        /**
         * Lookup field: click on the lookup field plus button
         */
        lookupCreate() {
            const $container = $(event.currentTarget).closest('.c9-lookup-field');
            const createUrl = $container.data('create-url');
            if (createUrl) {
                cApp.layers.add(createUrl);
            }
        },

        /**
         * Find the lookup field of a layer. In a mask with embedded contents
         * (field type 'link', backend-embedded.js) the same field can appear several times -
         * once per content; then the src_id (the working copy the relations
         * are attached to) also decides. Without src_id or without a match with it, the
         * first field with the src_field applies.
         *
         * @param {jQuery} $layer
         * @param {string} selector  '.c9-lookup-field' | '.c9-upload-field'
         * @param {string} extra     further attribute selectors, e.g. '[data-lang="3"]'
         */
        _fieldContainer($layer, selector, srcField, srcId = 0, extra = '') {
            const base = selector + '[data-src-field="' + srcField + '"]' + extra;
            if (srcId > 0) {
                const $exact = $layer.find(base + '[data-src-id="' + srcId + '"]');
                if ($exact.length) return $exact.first();
            }
            return $layer.find(base).first();
        },

        /**
         * Lookup field: set focus on the lookup text field
         */
        lookupFocus(srcField, clear=false, srcId = 0) {
            const $container = this._fieldContainer($('.c9-layer:last'), '.c9-lookup-field', srcField, srcId);
            const $textInput = $container.find('input[type="text"]');
            if (clear) $textInput.val('');
            $textInput.focus();
        },

        /**
         * Lookup field: click on the update/clear/move button
         */
        lookupUpdate(func = null, destId = null, swapId = null) {
            const $container = $(event.currentTarget).closest('.c9-lookup-field');
            const srcField = $container.data('src-field');
            if (srcField) {
                cApp.ui._lookupUpdate($container, srcField, func, destId, swapId);
            }
        },

        /**
         * Lookup field in the referrer layer: update the preview container
         */
        lookupRUpdate(srcField, func = '', destId = 0, swapId = 0, preventClosing = false, srcId = 0) {
            const $referrerLayer = $('.c9-layer').eq(-2);
            const $container = this._fieldContainer($referrerLayer, '.c9-lookup-field', srcField, srcId);
            cApp.ui._lookupUpdate($container, srcField, func, destId, swapId, preventClosing);
        },

        /**
         * Lookup field: helper function
         */
        _lookupUpdate($container, srcField, func = '', destId = 0, swapId = 0, preventClosing = false) {
            const max = $container.data('max');
            const $previewContainer = $container.find('.lookup-preview');

            // Update the preview container. POST: for a record lookup
            // the call writes the relation of the working copy
            // (RecordBackendEdit::lookupPreview(), developer docs, chapter 14 (Security: web)). The lookups of the
            // modules Admin and Template only read - POST does not bother them.
            let updateUrl = $container.data('update-url');
            if ($previewContainer.length && updateUrl) {
                // src_field and src_id are already in the update-url
                const params = new FormData();
                params.append('function', func);
                params.append('dest_id', destId);
                params.append('swap_id', swapId);
                if (max == 1) params.append('max', '1');
                $previewContainer.cLoadContent({
                    url:    updateUrl,
                    params: params,
                    method: 'POST'
                });
            }

            // If a record is linked, but at most one link is allowed:
            // close the topmost layer now
            if (func == 'link' && max == 1 && !preventClosing) {
                cApp.layers.close();
            }
        },

        /**
         * Lookup field: highlight the row in the preview area after moving
         */
        lookupHighlightRow(srcField, destId) {
            const $container = $('.c9-layer:last').find('.c9-lookup-field[data-src-field="' + srcField + '"]');
            const $previewContainer = $container.find('.lookup-preview');
            const $row = $previewContainer.find('tr[data-id="' + destId + '"]');
            $row.addClass('c9-moved c9-moved-active');
        },

        /**
         * Checkbox "all" (e.g. fields of the export): checks all visible checkboxes
         * in the container with data-check-all - if all are already checked, it unchecks
         * them all. Collapsed rows (sub-fields) remain untouched.
         */
        checkAll(element) {
            const $boxes = $(element).closest('[data-check-all]').find('tbody input[type="checkbox"]:visible');
            const all = $boxes.length > 0 && $boxes.filter(':checked').length === $boxes.length;
            $boxes.prop('checked', !all);
            element.checked = !all;
        },

        /**
         * Update the checkbox "all" when a single checkbox changes
         * (onchange on the container with data-check-all)
         */
        checkAllSync(container) {
            const $boxes = $(container).find('tbody input[type="checkbox"]:visible');
            $(container).find('input[data-check-all-box]').prop('checked',
                $boxes.length > 0 && $boxes.filter(':checked').length === $boxes.length);
        },

        /**
         * Search lookup field: remove selection
         */
        slookupEmpty() {
            const $container = $(event.currentTarget).closest('.c9-lookup-field');
            $container.data('ids', '');
            const $previewContainer = $container.find('.lookup-preview');
            $previewContainer.empty();
        },

        /**
         * Search lookup field: open the record list
         */
        slookupSearch(forceElement = null) {
            const targetElement = forceElement || event.currentTarget;
            const $container = $(targetElement).closest('.c9-lookup-field');
            const ids = String($container.data('ids') ?? '');
            const $previewContainer = $container.find('.lookup-preview');

            let searchUrl = $container.data('search-url');
            if (searchUrl) {
                // Append text from the input field
                const searchText = $container.find('input[type="text"]').val();
                if (searchText.trim() !== '') {
                    searchUrl += '&filter[_default]=' + encodeURIComponent(searchText.trim());
                }
                // Append already selected record ids as the "checked" parameter
                searchUrl += '&checked=' + encodeURIComponent(ids);
                
                cApp.layers.add(searchUrl, false, false, true);
            }
        },

        /**
         * Search lookup field: update the preview container
         */
        slookupUpdate(srcField, func = '', id = 0, preventClosing = false) {
            const $referrerLayer = $('.c9-layer').eq(-2);
            const $container = $referrerLayer.find('.c9-lookup-field[data-src-field="' + srcField + '"]');
            const max = $container.data('max');
            const $previewContainer = $container.find('.lookup-preview');
            const newIds = cApp.ui._slookupUpdate($container, srcField, func, id, max);

            // Update the preview container
            let updateUrl = $container.data('update-url');
            if (updateUrl) {
                updateUrl += '&ids=' + encodeURIComponent(newIds);
                updateUrl += '&function=' + encodeURIComponent(func);
                if (max == 1) {
                    updateUrl += '&max=1';
                }
                $previewContainer.cLoadContent(updateUrl);
            }
            if (func == 'link' && max == 1 && !preventClosing) {
                cApp.layers.close();
            }
        },

        /**
         * Search lookup field: update the preview container
         */
        slookupUnlink(id) {
            const $container = $(event.currentTarget).closest('.c9-lookup-field');
            const $previewContainer = $container.find('.lookup-preview');
            const srcField = $container.data('src-field');
            const newIds = cApp.ui._slookupUpdate($container, srcField, 'unlink', id, 0);

            // Update the preview container
            let updateUrl = $container.data('update-url');
            if (updateUrl) {
                updateUrl += '&ids=' + encodeURIComponent(newIds);
                $previewContainer.cLoadContent(updateUrl);
            }
        },

        /**
         * Search lookup field with order (trigger templates): swap two entries
         * and reload the preview - it carries the hidden fields, their
         * order is the saved value
         */
        slookupSwap(id, otherId) {
            const $container = $(event.currentTarget).closest('.c9-lookup-field');
            const idArray = String($container.data('ids') ?? '').split(',').filter(item => item !== '');
            const from = idArray.indexOf(String(id));
            const to = idArray.indexOf(String(otherId));
            if (from < 0 || to < 0) return;
            [idArray[from], idArray[to]] = [idArray[to], idArray[from]];
            const newIds = idArray.join(',');
            $container.data('ids', newIds);

            const updateUrl = $container.data('update-url');
            if (updateUrl) {
                $container.find('.lookup-preview').cLoadContent(updateUrl + '&ids=' + encodeURIComponent(newIds));
            }
        },

        /**
         * Parent lookup field: leave with Tab
         */
        pLookupTab() {
            const element = event.currentTarget;
            if ((event.key === 'Tab' || event.key === 'Enter')
                && !event.shiftKey
                && element.value.trim() !== ''
            ) {
                event.preventDefault();
                this.plookupSearch(element);
            }
        },

        /**
         * Parent lookup field: open the record list
         *
         * The already chosen parent record travels along as 'checked' - only then can
         * the list show the row as checked; it is not yet in the record.
         */
        plookupSearch(forceElement = null) {
            const targetElement = forceElement || event.currentTarget;
            const $container = $(targetElement).closest('.c9-lookup-field');
            let searchUrl = $container.data('search-url');
            if (!searchUrl) return;

            const searchText = $container.find('input[type="text"]').val();
            if (searchText.trim() !== '') {
                searchUrl += '&filter[_default]=' + encodeURIComponent(searchText.trim());
            }
            searchUrl += '&checked=' + encodeURIComponent(String($container.data('ids') ?? ''));
            cApp.layers.add(searchUrl, false, false, true);
        },

        /**
         * Parent lookup field: take over the selection from the record list
         *
         * Called from the list layer, the field is in the layer below.
         * Nothing is written - the selection ends up as a hidden
         * data[parent_id] in the preview and is only sent with the form.
         */
        plookupUpdate(srcField, func = 'link', id = 0, preventClosing = false) {
            // Do not search via eq(-2): the field is not necessarily in the layer directly
            // below (e.g. not when creating from the selection list). There is
            // only one field per src-field, the last occurrence is the topmost.
            const $container = $('.c9-lookup-field[data-src-field="' + srcField + '"]').last();
            if (func === 'link' && !preventClosing) cApp.layers.close();
            cApp.ui._plookupUpdate($container, func, id);
        },

        /**
         * Parent lookup field: remove selection (record moves to the root level)
         */
        plookupEmpty() {
            const $container = $(event.currentTarget).closest('.c9-lookup-field');
            cApp.ui._plookupUpdate($container, 'unlink', 0);
        },

        /**
         * Parent lookup field: helper function
         *
         * At most one parent record, therefore 'link' replaces the previous selection
         * and 'unlink' clears it. After the preview is rebuilt, the
         * predecessor list is updated - it only shows siblings of the new parent.
         */
        _plookupUpdate($container, func, id) {
            if (!$container.length) return;
            const newId = (func === 'link') ? String(id) : '';
            $container.data('ids', newId);

            const $previewContainer = $container.find('.lookup-preview');
            let updateUrl = $container.data('update-url');
            if (!$previewContainer.length || !updateUrl) return;

            updateUrl += '&parent_id=' + encodeURIComponent(newId === '' ? '0' : newId);
            $previewContainer.cLoadContent({
                url: updateUrl,
                callback: function () {
                    cApp.ui._plookupPrevious($container);
                }
            });
        },

        /**
         * Parent lookup field: reload the predecessor list in the same form.
         *
         * Not via cApp.forms.updateElement(): that takes the topmost visible
         * form, and that is not necessarily this one. An area without a
         * position field has no predecessor list at all - then there is nothing to do here.
         */
        _plookupPrevious($container) {
            const $form = $container.closest('form');
            if (!$form.length) return;
            const $el = $form.find('[name="data[previous_id]"]');
            if (!$el.length) return;

            $el.cLoadContent({
                url:    'Record/record_previous.php',
                params: new FormData($form[0]),
                method: 'POST'
            });
        },

        /**
         * Search lookup field: helper function
         */
        _slookupUpdate($container, srcField, func, id, max) {
            const idsStr = String($container.data('ids') ?? '');
            const idStr = String(id);
            // Split existing IDs into an array (filter out empty entries)
            let idArray = idsStr ? idsStr.split(',') : [];

            if (func == 'link') {
                // add id
                if (max == 1) {
                    idArray = [id];
                }
                else {
                    if (!idArray.includes(idStr)) {
                        idArray.push(idStr);
                    }
                }
            }

            if (func == 'unlink') {
                // remove id
                idArray = idArray.filter(item => item !== idStr);
            }

            // Write the id string back into the data attribute
            const newIds = idArray.join(',');
            $container.data('ids', newIds); 

            return newIds;
        },

        /**
         * Info page: edit the original file name - shows the
         * input field of the cell (Backend::fileNameCell()). The extension is
         * not part of the input.
         */
        fileRename(button) {
            const $cell = $(button).closest('.c9-file-filename');
            $cell.find('.c9-file-filename-view').addClass('d-none');
            $cell.find('.c9-file-filename-edit').removeClass('d-none');
            $cell.find('input').trigger('focus').trigger('select');
        },

        fileRenameCancel(element) {
            const $cell = $(element).closest('.c9-file-filename');
            const $input = $cell.find('input');
            $input.val($input.prop('defaultValue'));
            $cell.find('.c9-file-filename-edit').addClass('d-none');
            $cell.find('.c9-file-filename-view').removeClass('d-none');
        },

        /**
         * Save the new name (POST, RecordBackendInfo::fileRename()) - the
         * response replaces the cell (new name or error message)
         */
        fileRenameSave(element) {
            const $cell = $(element).closest('.c9-file-filename');
            const params = new FormData();
            params.append('id', $cell.data('id'));
            params.append('field_id', $cell.data('field'));
            params.append('lang', String($cell.data('lang') ?? ''));
            params.append('name', $cell.find('input').val());
            $cell.parent().cLoadContent({
                url:    'Record/ajax_file_rename.php',
                params: params,
                method: 'POST'
            });
        },

        /**
         * Delete a file from a file field (Ajax)
         */
        fileUnlink() {
            const $container = $(event.currentTarget).closest('.c9-upload-field');
            const $previewContainer = $container.find('.file-preview');
            const srcField = $container.data('src-field');
            const srcId = $container.data('src-id');
            // Language of the file for multilingual upload fields ('' monolingual)
            const lang = String($container.data('lang') ?? '');

            // Update the preview container
            // POST: marks the file of the working copy as deleted (developer docs, chapter 14 (Security: web))
            const params = new FormData();
            params.append('id', srcId);
            params.append('field_id', srcField);
            params.append('lang', lang);
            $previewContainer.cLoadContent({
                url:    'Record/ajax_file_remove.php',
                params: params,
                method: 'POST'
            });
        },

        /**
         * Enter a file name from the server's upload directory
         *
         * @param {string} lang         Language of the upload field ('' for monolingual fields)
         * @param {number} wantedSrcId  Working copy (embedded contents, see _fieldContainer())
         */
        selectUploadFile(srcField, filename, lang, wantedSrcId = 0) {
            lang = String(lang ?? '');
            const $referrerLayer = $('.c9-layer').eq(-2);
            const $container = this._fieldContainer($referrerLayer, '.c9-upload-field', srcField, wantedSrcId, '[data-lang="' + lang + '"]');
            const srcId = $container.data('src-id');
            const $previewContainer = $container.find('.file-preview');

            // Update the preview container. A field without a record (import mask)
            // names its own script in data-select-url
            const selectUrl = $container.data('select-url');
            if (selectUrl) {
                // Import mask: the preview only enters the path into the form,
                // nothing is written (RecordBackendImport::serverFilePreview())
                $previewContainer.cLoadContent(
                    selectUrl + (selectUrl.includes('?') ? '&' : '?') + 'filename=' + encodeURIComponent(filename)
                );
            }
            else {
                // POST: takes the file into the field and marks the previous one as
                // deleted (developer docs, chapter 14 (Security: web))
                const params = new FormData();
                params.append('id', srcId);
                params.append('field_id', srcField);
                params.append('lang', lang);
                params.append('filename', filename);
                $previewContainer.cLoadContent({
                    url:    'Record/ajax_file_server.php',
                    params: params,
                    method: 'POST'
                });
            }
            cApp.layers.close();
        },

        /**
         * Toggle all checkboxes of a table column (except disabled) - only in the
         * table of the clicked column header. The checkboxes are clicked
         * (not just set), so that their change handlers run.
         */
        toggleColumnCheckboxes(element) {
            const columnIndex = $(element).closest('th, td').index();
            var targetIndex = columnIndex;
            var $checkboxes = $(element).closest('table').find('tbody tr').find('td:eq(' + targetIndex + ') input[type="checkbox"]:not(:disabled)');
            if ($checkboxes.length === 0) return;

            // Check whether all are already active
            var allChecked = $checkboxes.length === $checkboxes.filter(':checked').length;

            if (allChecked) {
                // If all are active: click all that are currently "checked" (to deactivate them)
                $checkboxes.filter(':checked').trigger('click');
            } else {
                // If not all are active: click only those that are currently "not checked" (to activate them)
                $checkboxes.not(':checked').trigger('click');
            }
        },

        /**
         * Language permissions of a user group (GroupBackend, template group_language_row):
         * "write" also sets "read", unchecking "read" also removes "write".
         * Unchecking "write" leaves "read" in place.
         */
        groupLanguageRight(checkbox) {
            const $row = $(checkbox).closest('tr');
            if (checkbox.dataset.right === 'write' && checkbox.checked) {
                $row.find('input[data-right="read"]').prop('checked', true);
            }
            else if (checkbox.dataset.right === 'read' && !checkbox.checked) {
                $row.find('input[data-right="write"]').prop('checked', false);
            }
        },

        /**
         * Checks whether the layer with the background processes is visible (open)
         */
        bgprocVisible() {
            return $('#c9-bgproc-container').is(':visible');
        },

        /**
         * Initialize the buttons for ending background processes
         */
        _bindBgprocRemove() {
            $(document).on('click', '.c9-abort-bgproc', (e) => {
                e.preventDefault();
                const $listElement = $(e.currentTarget).closest('li');
                const token = $listElement.data('token');
                // POST: aborts a running process (developer docs, chapter 14 (Security: web))
                const params = new FormData();
                params.append('token', token);
                $listElement.cLoadContent({
                    url:    'Start/ajax_bgproc_abort.php',
                    params: params,
                    method: 'POST'
                });
            });
        },

        /**
         * Initialize clickable table column headers
         */
        _bindColumnCheckboxes() {
            $(document).on('click', '.c9-toggle-col-checkboxes', (e) => {
                e.preventDefault();
                this.toggleColumnCheckboxes(e.currentTarget);
            });
        },

        /**
         * Initializes Bootstrap tooltips lazily via event delegation.
         */
        _bindTooltips() {
            $('body').on('mouseenter', '[data-bs-toggle="tooltip"]', (e) => {
                const target = e.currentTarget;
                let instance = bootstrap.Tooltip.getInstance(target);
                if (!instance) {
                    new bootstrap.Tooltip(target).show();
                }
            });
        },

        /**
         * Loads further content via Ajax into the parent container.
         */
        _bindLoadMore() {
            $(document).on('click', '.c9-load-more', (e) => {
                e.preventDefault();
                const $btn = $(e.currentTarget);
                $btn.parent().cLoadContent($btn.data('url'));
            });
        },

        /**
         * Delegated handler for textarea auto-resize.
         * Reacts to input in DOM additions made at any later time.
         */
        _bindTextareaGrow() {
            // 1. For normal text fields (limited to 75vh)
            $(document).on('input', 'textarea.c9-textarea-grow', (e) => {
                this._resizeTextarea(e.currentTarget);
            });
            
            // 2. For code editors (endless growth + line numbers)
            $(document).on('input', 'textarea.c9-code-grow', (e) => {
                this._resizeCodeTextarea(e.currentTarget);
                this._updateLineNumbers(e.currentTarget);
            });
        },

        /**
         * Initializes all .c9-textarea-grow within a container.
         * Called after Ajax loads with the loaded container as scope.
         *
         * @param {jQuery} $scope – container of the loaded content
         */
        _initTextareaGrow($scope) {
            // 1. Normal text fields
            $scope.find('textarea.c9-textarea-grow').each((_, el) => {
                this._resizeTextarea(el);
            });
            
            // 2. Code editors
            $scope.find('textarea.c9-code-grow').each((_, el) => {
                this._resizeCodeTextarea(el);
                this._updateLineNumbers(el);
            });
        },

        /**
         * Initializes all .c9-textarea-grow after Ajax loads
         */
        _bindAjaxTextareaGrow() {
            $(document).ajaxComplete((event, xhr, settings) => {
                // 1. Normal text fields
                $('textarea.c9-textarea-grow').each((index, element) => {
                    this._resizeTextarea(element);
                });
                
                // 2. Code editors
                $('textarea.c9-code-grow').each((index, element) => {
                    this._resizeCodeTextarea(element);
                    this._updateLineNumbers(element);
                });
            });
        },

        /**
         * Calculates and sets the height of a textarea.
         * The minimum height is stored once on the first call.
         *
         * @param {HTMLElement} el
         */
        _resizeTextarea(el) {
            if (!el._c9MinHeight) {
                el._c9MinHeight = el.offsetHeight;
            }
            el.style.height = 'auto';
            el.style.height = Math.min(
                Math.max(el.scrollHeight + 4, el._c9MinHeight),
                window.innerHeight * 0.75
            ) + 'px';
        },

        /**
         * Calculates and sets the height of the code textarea.
         * Grows endlessly (without the window.innerHeight limit).
         *
         * @param {HTMLElement} el
         */
        _resizeCodeTextarea(el) {
            if (!el._c9MinHeight) {
                el._c9MinHeight = el.offsetHeight;
            }
            el.style.height = 'auto';

            // Check whether a horizontal scrollbar is active
            const hasHorizontalScrollbar = el.scrollWidth > el.clientWidth;
            const horizontalScrollbarHeight = hasHorizontalScrollbar 
                ? (el.offsetHeight - el.clientHeight) 
                : 0;

            el.style.height = Math.max(
                el.scrollHeight + horizontalScrollbarHeight, 
                el._c9MinHeight
            ) + 'px';
        },

        /**
         * Counts the line breaks in the textarea and builds
         * the HTML string for the line numbers.
         *
         * @param {HTMLElement} el
         */
        _updateLineNumbers(el) {
            const targetSelector = el.getAttribute('data-lines-target');
            if (!targetSelector) return;
            
            const targetDiv = document.querySelector(targetSelector);
            if (!targetDiv) return;

            // Determine the number of lines based on \n (newlines)
            const linesCount = el.value.split('\n').length;
            
            // Performance check: only update the DOM if the number of lines has changed
            if (el._c9LinesCount === linesCount) return;
            el._c9LinesCount = linesCount;

            // Regenerate the numbers
            let numbersHtml = '';
            for (let i = 1; i <= linesCount; i++) {
                numbersHtml += i + '<br>';
            }
            targetDiv.innerHTML = numbersHtml;
        },

        _initMainMenu() {
            $(document).ready(function() {
                const $button = $('#c9-hamburger');
                const $menu = $('#c9-menu-container');

                // Content fresh from the server on every opening (Start/menu.php): info,
                // manuals and the modules the group is currently allowed to use
                $button.on('click', function(e) {
                    e.stopPropagation();
                    if (!$menu.is(':visible')) {
                        $menu.cLoadContent({ url: 'Start/menu.php', spinner: false });
                    }
                    $menu.fadeToggle(200);
                });
                $(document).on('click', function(e) {
                    if (!$button.is(e.target) && $button.has(e.target).length === 0) {
                        if ($menu.is(':visible')) {
                            $menu.fadeOut(200);
                        }
                    }
                });
            });
        },

        _initBgprocContainer() {
            $(document).ready(function() {
                const $button = $('#c9-bgproc-btn');
                const $menu = $('#c9-bgproc-container');
                $button.on('click', function(e) {
                    e.stopPropagation();
                    if (!this.bgprocVisible) {
                        $menu.html('wait...').cLoadContent({
                            url: 'Start/bgproc_list.php?details=1',
                            spinner: false
                        });
                    }
                    $menu.toggle();
                });
                $(document).on('click', function(e) {
                    if (
                        (!$button.is(e.target) && $button.has(e.target).length === 0)
                        && $(e.target).closest('.c9-abort-bgproc').length === 0
                     ) {
                        $menu.hide();
                    }
                });
            });
        }
    }

}(window.cApp));