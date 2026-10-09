/*
 * cApp.ui – Allgemeine UI-Events
 * - Bootstrap-Tooltip-Initialisierung per Event Delegation
 * - "Mehr laden"-Schaltflächen (.c9-load-more)
 * - Auto-Resize für Textareas (.c9-textarea-grow)
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

        // --- Anmeldeformular: Backend-Sprache, Locale und Zeitzone ---
        //
        // Die letzte Wahl liegt im localStorage (Schlüssel c9.backend_code,
        // c9.locale, c9.timezone) - abgelegt beim Anmelden und beim Speichern der
        // persönlichen Einstellungen (AdminBackend::settings()). Ohne Eintrag
        // entscheiden Browsersprache, -locale und -zeitzone; eine Sprache, die das
        // Backend nicht kennt, löst der Server zu 'en_US' auf (Cms::resolveBackendLang()),
        // eine unbekannte Locale zu system.locale. Backend-Sprachen haben die Form
        // de_DE; ein früher gemerktes "de" gilt für die erste Option mit "de_".

        /**
         * Gemerkte Wahl lesen.
         * @returns {{backend_code: string, timezone: string, locale: string}}
         */
        loginPrefs() {
            const prefs = { backend_code: '', timezone: '', locale: '' };
            try {
                prefs.backend_code = localStorage.getItem('c9.backend_code') || '';
                prefs.timezone     = localStorage.getItem('c9.timezone') || '';
                prefs.locale       = localStorage.getItem('c9.locale') || '';
            } catch (e) { /* localStorage gesperrt - dann ohne Vorbelegung */ }
            return prefs;
        },

        /**
         * Wahl merken (leere Werte lassen den bisherigen Eintrag stehen).
         */
        loginPrefsStore(backendCode, timezone, locale) {
            try {
                if (backendCode) localStorage.setItem('c9.backend_code', backendCode);
                if (timezone)    localStorage.setItem('c9.timezone', timezone);
                if (locale)      localStorage.setItem('c9.locale', locale);
            } catch (e) { /* siehe loginPrefs() */ }
        },

        /**
         * Cookie des Frontend-Editings auf allen Websites abgleichen
         * (FrontendHandover::fedSyncData(), AdminBackend::settings()).
         *
         * Je Website erst eine Probe per Bild (c9probe=1): nur Domains, die erreichbar
         * sind und zu dieser Installation gehören, kommen in die Kette. Dann läuft das
         * Fenster als normale Navigation durch diese Websites (jede setzt ihr Cookie)
         * und kehrt ins Backend zurück - der Hash bleibt über die Weiterleitungen
         * erhalten, die Layer bauen sich wieder auf.
         *
         * @param {{token: string, sites: {i: number, origin: string}[]}} data
         * @param {function} [fallback]  keine Website erreichbar (z. B. neu laden)
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
         * QR-Codes zeichnen: jedes Element mit data-c9-qr="…" bekommt ein SVG seines
         * Inhalts (Einrichten der Authenticator-App, AdminBackend::totp()).
         *
         * Erzeugt wird im Browser mit qrcode-generator (MIT, von cdnjs nach
         * assets/qrcode/ übernommen, erst beim ersten Bedarf geladen) - die
         * otpauth://-URI samt Secret geht an keinen fremden Dienst.
         *
         * @param {Element} [root]  Bereich, sonst das ganze Dokument
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
         * Text aus data-copy des Knopfs in die Zwischenablage, Rückmeldung als Balloon
         * (Text aus data-copied).
         */
        copyText(button) {
            const text = button.dataset.copy || '';
            if (!navigator.clipboard) return;
            navigator.clipboard.writeText(text).then(() => {
                if (button.dataset.copied) cApp.system.balloon('success', button.dataset.copied);
            });
        },

        // --- Passkeys (WebAuthn, developer_docs/22-webauthn.md) ---
        //
        // Der Server liefert die Optionen als JSON im Attribut data-options des
        // Knopfs, Binärwerte base64url. Die Antwort des Geräts landet in versteckten
        // Feldern des Formulars, das dann wie jedes andere abgeschickt wird
        // (cApp.forms, Ajax). Fehler zeigt das Element [data-c9-passkey-error] im
        // Formular (Texte in data-cancelled / data-failed / data-unsupported).

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
         * Anmeldung, zweite Stufe: Signatur vom Gerät holen, ins Feld 'passkey'
         * (JSON) schreiben, Formular abschicken (StartBackend, function=step2).
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
         * Passkey anlegen (AdminBackend, function=passkey_add): Schlüsselpaar auf dem
         * Gerät erzeugen lassen, Antwort in die Felder client_data, attestation und
         * transports, Formular abschicken.
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
         * Locales des Browsers in ICU-Schreibweise (de-AT -> de_AT), nur die mit
         * Region - erst sie legt ein Datumsformat fest.
         * @returns {string[]}
         */
        browserLocales() {
            const list = (navigator.languages && navigator.languages.length) ? navigator.languages : [navigator.language || ''];
            return list
                .map((tag) => String(tag).replace(/-/g, '_'))
                .filter((tag) => /^[a-z]{2,3}_(?:[A-Z][a-z]{3}_)?[A-Z]{2}$/.test(tag));
        },

        /**
         * Sprache, mit der das Anmeldeformular geladen wird: die gemerkte, sonst die
         * des Browsers ("de-AT"; der Server wählt de_AT, sonst die erste Backend-
         * Sprache mit "de_", sonst 'en_US').
         */
        loginPrefLang() {
            const stored = this.loginPrefs().backend_code;
            if (stored) return stored;
            return String(navigator.language || '');
        },

        /**
         * Auswahllisten des Anmeldeformulars vorbelegen - Aufruf nach dem Laden des
         * Formulars (StartBackend::login()). Nur Werte, die es als Option gibt.
         */
        loginPrefsInit() {
            const prefs = this.loginPrefs();
            const setIfOption = ($select, value) => {
                if (!value || !$select.length) return false;
                if (!$select.find('option[value="' + value + '"]').length) return false;
                $select.val(value);
                return true;
            };

            // Backend-Sprache: exakt ("de_AT"), sonst die erste Option mit demselben
            // Sprachteil ("de" -> "de_DE") - wie Cms::matchLanguage()
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
                // Browsersprache ("de-AT"); unbekannt -> 'en_US'
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
                // Erste Browser-Locale, die es als Option gibt; sonst bleibt system.locale
                this.browserLocales().some((tag) => setIfOption($locale, tag));
            }
        },

        /**
         * Jede Änderung im Anmeldeformular sofort merken.
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
         * Zahnrad rechts oben: persönliche Einstellungen als Layer - nur einmal
         * (cApp.layers.addOnce(): ein weiterer Klick wird ignoriert, solange der
         * Layer oben liegt)
         */
        _initSettingsButton() {
            $('#c9-settings-btn').on('click', (e) => {
                e.preventDefault();
                cApp.layers.addOnce('Admin/settings.php');
            });
        },

        /**
         * Logout-Button rechts oben
         */
        _initLogoutButton() {
            $('#c9-logout-btn').on('click', (e) => {
                e.preventDefault();
                cApp.layers.logout();
            });
        },

        /**
         * Eingabemaske der Datensätze: Sprach-Reiter wechseln.
         *
         * Die Maske wird nicht einfach umgeschaltet, sondern über das Formular
         * zwischengespeichert (function=tab) und mit dem Ziel-Reiter neu geladen -
         * siehe RecordBackendEdit::_editTab(). Das Formular trägt die versteckten
         * Felder tab (angezeigter Reiter) und tab_target (Ziel).
         *
         * @param {Element} el      angeklickter Reiter (steht innerhalb des Formulars)
         * @param {string}  target  language_id oder 'all'
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
         * Ändert den Visibility-Status eines Feldes
         */
        visibility(el, id, name, value) {
            const $parent = $(el).closest('.form-check').parent();
            // POST: schreibt visibility_* des Feldes (17-csrf.md §2.7)
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
         * Lookupfeld: Verlassen mit Tab
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
         * sLookupfeld: Verlassen mit Tab
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
         * Lookupfeld: Klick auf Such-Button
         */
        lookupSearch(forceElement = null) {
            // Wenn von lookupTab aufgerufen, nutze das übergebene Element, sonst event.currentTarget vom Klick
            const targetElement = forceElement || event.currentTarget;
            const $container = $(targetElement).closest('.c9-lookup-field');
            let searchUrl = $container.data('search-url');
            if (searchUrl) {
                // Hänge Text aus Eingabefeld an
                const searchText = $container.find('input[type="text"]').val();
                if (searchText.trim() !== '') {
                    searchUrl += '&filter[_default]=' + encodeURIComponent(searchText.trim());
                }
                cApp.layers.add(searchUrl, false, false, true);
            }
        },

        /**
         * Lookupfeld: Klick auf Lookup-Feld Plus-Button
         */
        lookupCreate() {
            const $container = $(event.currentTarget).closest('.c9-lookup-field');
            const createUrl = $container.data('create-url');
            if (createUrl) {
                cApp.layers.add(createUrl);
            }
        },

        /**
         * Lookupfeld eines Layers finden. In einer Maske mit eingebetteten Inhalten
         * (Feldtyp 'link', backend-embedded.js) kann dasselbe Feld mehrfach stehen -
         * je Inhalt einmal; dann entscheidet zusätzlich die src_id (die Arbeitskopie,
         * an der die Verknüpfungen hängen). Ohne src_id bzw. ohne Treffer damit gilt
         * das erste Feld mit der src_field.
         *
         * @param {jQuery} $layer
         * @param {string} selector  '.c9-lookup-field' | '.c9-upload-field'
         * @param {string} extra     weitere Attribut-Selektoren, z. B. '[data-lang="3"]'
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
         * Lookupfeld: Focus auf Lookup-Text-Feld setzen
         */
        lookupFocus(srcField, clear=false, srcId = 0) {
            const $container = this._fieldContainer($('.c9-layer:last'), '.c9-lookup-field', srcField, srcId);
            const $textInput = $container.find('input[type="text"]');
            if (clear) $textInput.val('');
            $textInput.focus();
        },

        /**
         * Lookupfeld: Klick auf Update-/Clear-/Move-Button
         */
        lookupUpdate(func = null, destId = null, swapId = null) {
            const $container = $(event.currentTarget).closest('.c9-lookup-field');
            const srcField = $container.data('src-field');
            if (srcField) {
                cApp.ui._lookupUpdate($container, srcField, func, destId, swapId);
            }
        },

        /**
         * Lookupfeld im Referrer-Layer: Preview-Container aktualisieren
         */
        lookupRUpdate(srcField, func = '', destId = 0, swapId = 0, preventClosing = false, srcId = 0) {
            const $referrerLayer = $('.c9-layer').eq(-2);
            const $container = this._fieldContainer($referrerLayer, '.c9-lookup-field', srcField, srcId);
            cApp.ui._lookupUpdate($container, srcField, func, destId, swapId, preventClosing);
        },

        /**
         * Lookupfeld: Hilfsfunktion
         */
        _lookupUpdate($container, srcField, func = '', destId = 0, swapId = 0, preventClosing = false) {
            const max = $container.data('max');
            const $previewContainer = $container.find('.lookup-preview');

            // preview-Container aktualisieren. POST: bei einem Datensatz-Lookup
            // schreibt der Aufruf die Verknüpfung der Arbeitskopie
            // (RecordBackendEdit::lookupPreview(), 17-csrf.md §2.7). Die Lookups der
            // Module Admin und Template lesen nur - POST stört sie nicht.
            let updateUrl = $container.data('update-url');
            if ($previewContainer.length && updateUrl) {
                // src_field und src_id stecken schon in der update-url
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

            // Wenn Datensatz verlinkt wird, aber max eine Verlinkung erlaubt ist:
            // obersten Layer jetzt schließen
            if (func == 'link' && max == 1 && !preventClosing) {
                cApp.layers.close();
            }
        },

        /**
         * Lookupfeld: Zeile im Preview-Bereich nach Verschieben highlighten
         */
        lookupHighlightRow(srcField, destId) {
            const $container = $('.c9-layer:last').find('.c9-lookup-field[data-src-field="' + srcField + '"]');
            const $previewContainer = $container.find('.lookup-preview');
            const $row = $previewContainer.find('tr[data-id="' + destId + '"]');
            $row.addClass('c9-moved c9-moved-active');
        },

        /**
         * Checkbox "alle" (z. B. Felder des Exports): setzt alle sichtbaren Checkboxen
         * im Container mit data-check-all - sind schon alle gesetzt, nimmt sie alle
         * heraus. Zugeklappte Zeilen (Unterfelder) bleiben unberührt.
         */
        checkAll(element) {
            const $boxes = $(element).closest('[data-check-all]').find('tbody input[type="checkbox"]:visible');
            const all = $boxes.length > 0 && $boxes.filter(':checked').length === $boxes.length;
            $boxes.prop('checked', !all);
            element.checked = !all;
        },

        /**
         * Checkbox "alle" nachführen, wenn eine einzelne Checkbox wechselt
         * (onchange am Container mit data-check-all)
         */
        checkAllSync(container) {
            const $boxes = $(container).find('tbody input[type="checkbox"]:visible');
            $(container).find('input[data-check-all-box]').prop('checked',
                $boxes.length > 0 && $boxes.filter(':checked').length === $boxes.length);
        },

        /**
         * Search Lookupfeld: Auswahl entfernen
         */
        slookupEmpty() {
            const $container = $(event.currentTarget).closest('.c9-lookup-field');
            $container.data('ids', '');
            const $previewContainer = $container.find('.lookup-preview');
            $previewContainer.empty();
        },

        /**
         * Search Lookupfeld: Datensatzliste öffnen
         */
        slookupSearch(forceElement = null) {
            const targetElement = forceElement || event.currentTarget;
            const $container = $(targetElement).closest('.c9-lookup-field');
            const ids = String($container.data('ids') ?? '');
            const $previewContainer = $container.find('.lookup-preview');

            let searchUrl = $container.data('search-url');
            if (searchUrl) {
                // Hänge Text aus Eingabefeld an
                const searchText = $container.find('input[type="text"]').val();
                if (searchText.trim() !== '') {
                    searchUrl += '&filter[_default]=' + encodeURIComponent(searchText.trim());
                }
                // Hänge bereits ausgewählte Datensatz-Ids als "checked"-Parameter an
                searchUrl += '&checked=' + encodeURIComponent(ids);
                
                cApp.layers.add(searchUrl, false, false, true);
            }
        },

        /**
         * Search Lookupfeld: Preview-Container aktualisieren
         */
        slookupUpdate(srcField, func = '', id = 0, preventClosing = false) {
            const $referrerLayer = $('.c9-layer').eq(-2);
            const $container = $referrerLayer.find('.c9-lookup-field[data-src-field="' + srcField + '"]');
            const max = $container.data('max');
            const $previewContainer = $container.find('.lookup-preview');
            const newIds = cApp.ui._slookupUpdate($container, srcField, func, id, max);

            // preview-Container aktualisieren
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
         * Search Lookupfeld: Preview-Container aktualisieren
         */
        slookupUnlink(id) {
            const $container = $(event.currentTarget).closest('.c9-lookup-field');
            const $previewContainer = $container.find('.lookup-preview');
            const srcField = $container.data('src-field');
            const newIds = cApp.ui._slookupUpdate($container, srcField, 'unlink', id, 0);

            // preview-Container aktualisieren
            let updateUrl = $container.data('update-url');
            if (updateUrl) {
                updateUrl += '&ids=' + encodeURIComponent(newIds);
                $previewContainer.cLoadContent(updateUrl);
            }
        },

        /**
         * Search Lookupfeld mit Reihenfolge (Trigger-Templates): zwei Einträge
         * tauschen und die Vorschau neu laden - sie trägt die Hiddenfelder, ihre
         * Reihenfolge ist der gespeicherte Wert
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
         * Parent-Lookupfeld: Verlassen mit Tab
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
         * Parent-Lookupfeld: Datensatzliste öffnen
         *
         * Der bereits gewählte Vaterdatensatz reist als 'checked' mit - nur so kann
         * die Liste die Zeile angehakt darstellen; im Datensatz steht er noch nicht.
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
         * Parent-Lookupfeld: Auswahl aus der Datensatzliste übernehmen
         *
         * Aufgerufen aus dem Listen-Layer, das Feld steckt im Layer darunter.
         * Geschrieben wird nichts - die Auswahl landet als verstecktes
         * data[parent_id] in der Vorschau und geht erst mit dem Formular ab.
         */
        plookupUpdate(srcField, func = 'link', id = 0, preventClosing = false) {
            // Nicht über eq(-2) suchen: das Feld liegt nicht zwingend im Layer direkt
            // darunter (beim Anlegen aus der Auswahlliste heraus etwa nicht). Es gibt
            // je src-field nur ein Feld, das letzte Vorkommen ist das oberste.
            const $container = $('.c9-lookup-field[data-src-field="' + srcField + '"]').last();
            if (func === 'link' && !preventClosing) cApp.layers.close();
            cApp.ui._plookupUpdate($container, func, id);
        },

        /**
         * Parent-Lookupfeld: Auswahl entfernen (Datensatz kommt auf die Wurzelebene)
         */
        plookupEmpty() {
            const $container = $(event.currentTarget).closest('.c9-lookup-field');
            cApp.ui._plookupUpdate($container, 'unlink', 0);
        },

        /**
         * Parent-Lookupfeld: Hilfsfunktion
         *
         * Höchstens ein Vaterdatensatz, deshalb ersetzt 'link' die bisherige Auswahl
         * und 'unlink' leert sie. Nach dem Neuaufbau der Vorschau wird die
         * Vorgängerliste nachgezogen - sie zeigt nur Geschwister des neuen Parents.
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
         * Parent-Lookupfeld: Vorgängerliste im selben Formular nachladen.
         *
         * Nicht über cApp.forms.updateElement(): das nimmt das oberste sichtbare
         * Formular, und das ist nicht zwangsläufig dieses. Ein Bereich ohne
         * Positionsfeld hat gar keine Vorgängerliste - dann ist hier nichts zu tun.
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
         * Search Lookupfeld: Hilfsfunktion
         */
        _slookupUpdate($container, srcField, func, id, max) {
            const idsStr = String($container.data('ids') ?? '');
            const idStr = String(id);
            // Bestehende IDs in ein Array aufteilen (leere Einträge herausfiltern)
            let idArray = idsStr ? idsStr.split(',') : [];

            if (func == 'link') {
                // id hinzufügen
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
                // id entfernen
                idArray = idArray.filter(item => item !== idStr);
            }

            // Id-String zurück ins data-Attribut schreiben
            const newIds = idArray.join(',');
            $container.data('ids', newIds); 

            return newIds;
        },

        /**
         * Info-Seite: ursprünglichen Dateinamen bearbeiten - blendet das
         * Eingabefeld der Zelle ein (Backend::fileNameCell()). Die Endung ist
         * nicht Teil der Eingabe.
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
         * Neuen Namen speichern (POST, RecordBackendInfo::fileRename()) - die
         * Antwort ersetzt die Zelle (neuer Name bzw. Fehlermeldung)
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
         * File aus File-Feld löschen (Ajax)
         */
        fileUnlink() {
            const $container = $(event.currentTarget).closest('.c9-upload-field');
            const $previewContainer = $container.find('.file-preview');
            const srcField = $container.data('src-field');
            const srcId = $container.data('src-id');
            // Sprache der Datei bei mehrsprachigen Upload-Feldern ('' einsprachig)
            const lang = String($container.data('lang') ?? '');

            // preview-Container aktualisieren
            // POST: markiert die Datei der Arbeitskopie als gelöscht (17-csrf.md §2.7)
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
         * Filename aus Uploadverzeichnis des Servers eintragen
         *
         * @param {string} lang         Sprache des Upload-Feldes ('' bei einsprachigen Feldern)
         * @param {number} wantedSrcId  Arbeitskopie (eingebettete Inhalte, siehe _fieldContainer())
         */
        selectUploadFile(srcField, filename, lang, wantedSrcId = 0) {
            lang = String(lang ?? '');
            const $referrerLayer = $('.c9-layer').eq(-2);
            const $container = this._fieldContainer($referrerLayer, '.c9-upload-field', srcField, wantedSrcId, '[data-lang="' + lang + '"]');
            const srcId = $container.data('src-id');
            const $previewContainer = $container.find('.file-preview');

            // preview-Container aktualisieren. Ein Feld ohne Datensatz (Import-Maske)
            // nennt sein eigenes Script in data-select-url
            const selectUrl = $container.data('select-url');
            if (selectUrl) {
                // Import-Maske: die Vorschau trägt den Pfad nur ins Formular ein,
                // geschrieben wird nichts (RecordBackendImport::serverFilePreview())
                $previewContainer.cLoadContent(
                    selectUrl + (selectUrl.includes('?') ? '&' : '?') + 'filename=' + encodeURIComponent(filename)
                );
            }
            else {
                // POST: übernimmt die Datei ins Feld und markiert die bisherige als
                // gelöscht (17-csrf.md §2.7)
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
         * Alle Checkboxes einer Tabellen-Spalte (außer disabled) toggeln - nur in der
         * Tabelle des angeklickten Spaltenkopfs. Die Checkboxes werden angeklickt
         * (nicht nur umgesetzt), damit ihre change-Handler laufen.
         */
        toggleColumnCheckboxes(element) {
            const columnIndex = $(element).closest('th, td').index();
            var targetIndex = columnIndex;
            var $checkboxes = $(element).closest('table').find('tbody tr').find('td:eq(' + targetIndex + ') input[type="checkbox"]:not(:disabled)');
            if ($checkboxes.length === 0) return;

            // Prüfen, ob bereits alle aktiv sind
            var allChecked = $checkboxes.length === $checkboxes.filter(':checked').length;

            if (allChecked) {
                // Wenn alle aktiv sind: Alle klicken, die aktuell "checked" sind (um sie zu deaktivieren)
                $checkboxes.filter(':checked').trigger('click');
            } else {
                // Wenn nicht alle aktiv sind: Nur die klicken, die aktuell "nicht checked" sind (um sie zu aktivieren)
                $checkboxes.not(':checked').trigger('click');
            }
        },

        /**
         * Sprachrechte einer Benutzergruppe (GroupBackend, Vorlage group_language_row):
         * "schreiben" setzt "lesen" mit, "lesen" abhaken nimmt "schreiben" mit.
         * "schreiben" abhaken lässt "lesen" stehen.
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
         * Prüft, ob der Layer mit den Hintergrundprozessen sichtbar (geöffnet) ist
         */
        bgprocVisible() {
            return $('#c9-bgproc-container').is(':visible');
        },

        /**
         * Buttons zum Beenden von Hintergrundprozessen initialisieren
         */
        _bindBgprocRemove() {
            $(document).on('click', '.c9-abort-bgproc', (e) => {
                e.preventDefault();
                const $listElement = $(e.currentTarget).closest('li');
                const token = $listElement.data('token');
                // POST: bricht einen laufenden Prozess ab (17-csrf.md §2.7)
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
         * Klickbare Tabellen-Spaltenköpfe initialisieren
         */
        _bindColumnCheckboxes() {
            $(document).on('click', '.c9-toggle-col-checkboxes', (e) => {
                e.preventDefault();
                this.toggleColumnCheckboxes(e.currentTarget);
            });
        },

        /**
         * Initialisiert Bootstrap-Tooltips lazy per Event Delegation.
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
         * Lädt weiteren Inhalt per Ajax in den Eltern-Container.
         */
        _bindLoadMore() {
            $(document).on('click', '.c9-load-more', (e) => {
                e.preventDefault();
                const $btn = $(e.currentTarget);
                $btn.parent().cLoadContent($btn.data('url'));
            });
        },

        /**
         * Delegierter Handler für Textarea-Auto-Resize.
         * Reagiert auf Eingaben in beliebig späte DOM-Ergänzungen.
         */
        _bindTextareaGrow() {
            // 1. Für normale Textfelder (mit Limitierung auf 75vh)
            $(document).on('input', 'textarea.c9-textarea-grow', (e) => {
                this._resizeTextarea(e.currentTarget);
            });
            
            // 2. Für Code-Editoren (endloses Wachstum + Zeilennummern)
            $(document).on('input', 'textarea.c9-code-grow', (e) => {
                this._resizeCodeTextarea(e.currentTarget);
                this._updateLineNumbers(e.currentTarget);
            });
        },

        /**
         * Initialisiert alle .c9-textarea-grow innerhalb eines Containers.
         * Aufruf nach Ajax-Ladungen mit dem geladenen Container als Scope.
         *
         * @param {jQuery} $scope – Container des geladenen Inhalts
         */
        _initTextareaGrow($scope) {
            // 1. Normale Textfelder
            $scope.find('textarea.c9-textarea-grow').each((_, el) => {
                this._resizeTextarea(el);
            });
            
            // 2. Code-Editoren
            $scope.find('textarea.c9-code-grow').each((_, el) => {
                this._resizeCodeTextarea(el);
                this._updateLineNumbers(el);
            });
        },

        /**
         * Initialisiert alle .c9-textarea-grow nach Ajax-Loads
         */
        _bindAjaxTextareaGrow() {
            $(document).ajaxComplete((event, xhr, settings) => {
                // 1. Normale Textfelder
                $('textarea.c9-textarea-grow').each((index, element) => {
                    this._resizeTextarea(element);
                });
                
                // 2. Code-Editoren
                $('textarea.c9-code-grow').each((index, element) => {
                    this._resizeCodeTextarea(element);
                    this._updateLineNumbers(element);
                });
            });
        },

        /**
         * Berechnet und setzt die Höhe einer Textarea.
         * Mindesthöhe wird beim ersten Aufruf einmalig gespeichert.
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
         * Berechnet und setzt die Höhe der Code-Textarea.
         * Wächst endlos mit (ohne window.innerHeight Limit).
         *
         * @param {HTMLElement} el
         */
        _resizeCodeTextarea(el) {
            if (!el._c9MinHeight) {
                el._c9MinHeight = el.offsetHeight;
            }
            el.style.height = 'auto';

            // Prüfen, ob eine horizontale Scrollbar aktiv ist
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
         * Zählt die Zeilenumbrüche in der Textarea und baut 
         * den HTML-String für die Zeilennummern auf.
         *
         * @param {HTMLElement} el
         */
        _updateLineNumbers(el) {
            const targetSelector = el.getAttribute('data-lines-target');
            if (!targetSelector) return;
            
            const targetDiv = document.querySelector(targetSelector);
            if (!targetDiv) return;

            // Anzahl der Zeilen anhand der \n (Newlines) ermitteln
            const linesCount = el.value.split('\n').length;
            
            // Performance-Check: DOM nur updaten, wenn sich die Zeilenanzahl geändert hat
            if (el._c9LinesCount === linesCount) return;
            el._c9LinesCount = linesCount;

            // Nummern neu generieren
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

                // Inhalt bei jedem Öffnen neu vom Server (Start/menu.php): Info,
                // Handbücher und die Module, die die Gruppe gerade darf
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