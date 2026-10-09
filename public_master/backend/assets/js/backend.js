/*
 * camindo Backend – Zentraler Einstiegspunkt
 *
 * window.cConfig muss vor diesem Script definiert sein!
 * Ladereihenfolge der Scripte:
 *   backend.js           (dieser File – Namespace + jQuery-Extensions)
 *   backend-handlers.js  (cApp.handlers – Event-Handler unter der Script-Policy)
 *   backend-system.js    (cApp.system)
 *   backend-forms.js     (cApp.forms)
 *   backend-ui.js        (cApp.ui)
 *   backend-layers.js    (cApp.layers)
 *   backend-aside.js     (cApp.aside)
 *   backend-ctx-menu.js  (cApp.ctxMenu)
 *   backend-polling.js   (cApp.polling)
 *   backend-smartrows.js (cApp.smartrows)
 *   backend-embedded.js  (cApp.embedded)
 *   backend-init.js      ($(document).ready → alle .init())
 */

// ---------------------------------------------------------------------------
// Globaler Namespace
// ---------------------------------------------------------------------------
window.cApp = window.cApp || {};

// Sicherheits-Fallback falls cConfig fehlt
// window.cConfig ...

// ---------------------------------------------------------------------------
// jQuery-Extension: cLoadContent
// ---------------------------------------------------------------------------
(function ($) {
    'use strict';

    /**
     * Lädt Inhalt dynamisch per Ajax in das jQuery-Element.
     *
     * @param {string|object} options  URL-String oder Options-Objekt:
     * url      {string}     Pfad relativ zum modules Directory
     * params   {string|FormData}  POST-Daten
     * method   {string}     'GET' (default) oder 'POST'
     * callback {function}   Wird nach erfolgreichem Laden aufgerufen
     * nocache  {boolean}    true (default): verhindert Browser-Caching
     * spinner  {boolean}    true (default): weißes Overlay+Spinner anzeigen bis Inhalt geladen ist
     * timeout  {int}        0 (default), >0 : Timeout in ms
     * background {boolean}  false (default); true: Abfrage im Hintergrund (Polling) -
     *                       verlängert die Session nicht (Header X-Camindo-Background)
     */
    $.fn.cLoadContent = function (options) {
        options = options || {};

        const settings = $.extend({
            url:      '',
            params:   '',
            method:   'GET',
            callback: function () {},
            nocache:  true,
            spinner:  true,
            timeout:  0,
            background: false
        }, (typeof options === 'string') ? { url: options } : options);

        // Aufsteigende Pfade blockieren
        const urlPath = settings.url.split('?')[0];
        if (urlPath.includes('..')) {
            alert('Ascending paths not permitted!');
            return this;
        }

        let contentType = 'application/x-www-form-urlencoded; charset=UTF-8';
        let processData = true;

        settings.method = settings.method.toUpperCase();
        if (settings.method !== 'GET') {
            contentType = false;
            processData = false;
        }

        let cPath = settings.url.replace(/^[./]+/, '');
        cPath = cPath.replace(/[?&]cache=[0-9]+/g, '');
        if (cPath === '') cPath = 'Start/index.php';

        if (settings.nocache) {
            cPath += cPath.includes('?') ? `&cache=${Date.now()}` : `?cache=${Date.now()}`;
        }

        if (settings.spinner) {
            cApp.system.addSpinner();
        }

        const $this = $(this);

        // Verwaiste Tooltips entfernen: alle im Element, das gleich ersetzt wird,
        // und außerhalb davon nur die, über denen die Maus NICHT gerade steht -
        // das Polling der Hintergrundprozesse (#c9-bgproc-container) darf einen
        // offenen Tooltip anderswo nicht schließen.
        $('[data-bs-toggle="tooltip"]').each(function () {
            const tooltip = bootstrap.Tooltip.getInstance(this);
            if (!tooltip) return;
            if ($this.has(this).length === 0 && this.matches(':hover')) return;
            tooltip.dispose();
        });

        // Laufenden Request für dieses Element abbrechen, falls er noch nicht abgeschlossen ist
        const existingXhr = $this.data('xhr');
        if (existingXhr && existingXhr.readyState !== 4) {
            existingXhr.abort();
        }

        const xhr = $.ajax({
            type:        settings.method,
            url:         cConfig.backend_path + 'Modules/' + cPath,
            data:        settings.params,
            dataType:    'html',
            contentType: contentType,
            processData: processData,
            timeout:     settings.timeout,
            // CSRF-Token der Session (17-csrf.md). Der Server verlangt es bei allem
            // außer GET; hier geht es immer mit, damit eine Aktion nicht beim
            // Umstellen auf POST vergessen wird. Jetzt gelesen, nicht beim Laden der
            // Seite: nach einer Anmeldung steht ein neues Token in cConfig.
            headers:     settings.background
                ? Object.assign({ 'X-Camindo-Background': '1' }, cApp.system.csrfHeaders())
                : cApp.system.csrfHeaders(),
            success: function (response) {
                cApp.system.removeSpinner($this);

                if (response.trim() === '##accessdenied##') {
                    cApp.layers.login();
                } else {
                    // Feld-Extensions im ersetzten Inhalt sauber beenden
                    if (cApp.extensions && cApp.extensions.destroyAll) {
                        cApp.extensions.destroyAll($this);
                    }
                    $this.html(response);
                    // Event-Handler des neuen Inhalts sofort binden (backend-handlers.js;
                    // der MutationObserver käme erst nach diesem Aufruf)
                    if (cApp.handlers) cApp.handlers.scan($this[0]);
                    
                    // Warte bis Inhalt sicher gerendert ist
                    requestAnimationFrame(() => {
                        // Feld-Extensions (class c9-ext) initialisieren - lädt ihre
                        // Assets beim ersten Mal nach
                        if (cApp.extensions && cApp.extensions.refreshAll) {
                            cApp.extensions.refreshAll($this);
                        }

                        // Smartrows-Container im frisch geladenen Inhalt auffrischen.
                        // Die Hoch-/Runter-Buttons sind per CSS ausgeblendet und
                        // werden erst von refresh() eingeblendet - ohne diesen Aufruf
                        // bliebe jede nachgeladene Zeile unsortierbar.
                        if (cApp.smartrows && cApp.smartrows.refreshAll) {
                            cApp.smartrows.refreshAll($this);
                        }

                        // Fokus auf erstes sichtbares Formularelement setzen
                        const $firstInput = $this.find('form:visible').first()
                            .find('input, select, textarea')
                            .filter(':visible:not(:disabled)')
                            .first();
                        
                        if ($firstInput.length) {
                            $firstInput.trigger('focus');
                        }

                        settings.callback();
                    });
                }
            },
            error: function (jqXHR, textStatus) {
                cApp.system.removeSpinner($this);

                if (jqXHR.status === 0) return;

                if (textStatus === 'timeout') {
                    console.warn('cLoadContent: Timeout erreicht für ' + settings.url);
                    settings.callback(); // Damit das Polling im Fehlerfall nicht stirbt
                    $this.html('timeout');
                    return;
                }

                if (jqXHR.status === 404) {
                    const $err = $('<div class="container"><div class="alert alert-danger"></div></div>');
                    $err.find('.alert').text('Error 404: "' + settings.url + '" not found!');
                    $this.html($err);
                    settings.callback();
                } else {
                    alert(window.cConfig.message_connection_error + ' | ' + jqXHR.status + ' | ' + textStatus);
                }
            },
            complete: function () {
                // Nach Abschluss sauber aufräumen, falls es sich noch um diesen Request handelt
                if ($this.data('xhr') === xhr) {
                    $this.removeData('xhr');
                }
            }
        });

        $this.data('xhr', xhr);
        return this;
    };

    // ---------------------------------------------------------------------------
    // jQuery-Extension: serializeFiles
    // ---------------------------------------------------------------------------
    /**
     * Serialisiert ein Suchformular als FormData, ignoriert dabei aber komplett leere 
     * Felder (value === '') und ungenutzte Datei-Uploads, um kurze URLs zu gewährleisten.
     * @returns {FormData}
     */
    $.fn.serializeFiles = function () {
        const maxSizeBytes = window.cConfig.max_upload_bytes ?? null;
        let totalSize = 0;
        const formElement = this.get(0);
        
        if (!formElement || formElement.tagName !== 'FORM') {
            return new FormData();
        }

        const rawData = new FormData(formElement);
        const filteredData = new FormData();

        for (const [key, value] of rawData.entries()) {
            // Textfelder: Nur übernehmen, wenn der String nicht komplett leer ist.
            // Gewollte Leerzeichen bleiben somit unangetastet.
            if (typeof value === 'string') {
                if (value !== '') {
                    filteredData.append(key, value);
                    totalSize += value.length;
                }
            } 
            // Datei-Uploads: Nur übernehmen, wenn eine tatsächliche Datei ausgewählt wurde.
            else if (value instanceof File && value.size > 0) {
                filteredData.append(key, value);
                totalSize += value.size;
            }
            // Für alles was künftig noch dazu kommen könnte
            else if (value !== null && value !== undefined) {
                filteredData.append(key, value);
            }
        }

        if (maxSizeBytes > 0 && totalSize > maxSizeBytes) {
            const sizeMB = (totalSize / (1024 * 1024)).toFixed(2);
            const limitMB = (maxSizeBytes / (1024 * 1024)).toFixed(2);
            
            let msg = window.cConfig.error_uploadsize_exceeded;
            alert(msg.replace('{current_size}', sizeMB).replace('{max_size}', limitMB));
            return false;
        }

        return filteredData;
    };

}(jQuery));

