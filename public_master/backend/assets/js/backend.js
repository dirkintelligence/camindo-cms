/*
 * camindo backend – Central entry point
 *
 * window.cConfig must be defined before this script!
 * Loading order of the scripts:
 *   backend.js           (this file – namespace + jQuery extensions)
 *   backend-handlers.js  (cApp.handlers – event handlers under the script policy)
 *   backend-system.js    (cApp.system)
 *   backend-forms.js     (cApp.forms)
 *   backend-ui.js        (cApp.ui)
 *   backend-layers.js    (cApp.layers)
 *   backend-aside.js     (cApp.aside)
 *   backend-ctx-menu.js  (cApp.ctxMenu)
 *   backend-polling.js   (cApp.polling)
 *   backend-smartrows.js (cApp.smartrows)
 *   backend-embedded.js  (cApp.embedded)
 *   backend-init.js      ($(document).ready → all .init())
 */

// ---------------------------------------------------------------------------
// Global namespace
// ---------------------------------------------------------------------------
window.cApp = window.cApp || {};

// Safety fallback in case cConfig is missing
// window.cConfig ...

// ---------------------------------------------------------------------------
// jQuery extension: cLoadContent
// ---------------------------------------------------------------------------
(function ($) {
    'use strict';

    /**
     * Loads content dynamically via Ajax into the jQuery element.
     *
     * @param {string|object} options  URL string or options object:
     * url      {string}     Path relative to the modules directory
     * params   {string|FormData}  POST data
     * method   {string}     'GET' (default) or 'POST'
     * callback {function}   Called after successful loading
     * nocache  {boolean}    true (default): prevents browser caching
     * spinner  {boolean}    true (default): show white overlay+spinner until the content is loaded
     * timeout  {int}        0 (default), >0 : timeout in ms
     * background {boolean}  false (default); true: request in the background (polling) -
     *                       does not extend the session (header X-Camindo-Background)
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

        // Block ascending paths
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

        // Remove orphaned tooltips: all in the element that is about to be replaced,
        // and outside of it only those the mouse is NOT currently over -
        // the polling of the background processes (#c9-bgproc-container) must not
        // close an open tooltip elsewhere.
        $('[data-bs-toggle="tooltip"]').each(function () {
            const tooltip = bootstrap.Tooltip.getInstance(this);
            if (!tooltip) return;
            if ($this.has(this).length === 0 && this.matches(':hover')) return;
            tooltip.dispose();
        });

        // Abort a running request for this element if it has not finished yet
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
            // CSRF token of the session (developer docs, chapter 14 (Security: web)). The server requires it for everything
            // except GET; here it is always sent, so that an action is not forgotten
            // when switching to POST. Read now, not when the page
            // loads: after a login there is a new token in cConfig.
            headers:     settings.background
                ? Object.assign({ 'X-Camindo-Background': '1' }, cApp.system.csrfHeaders())
                : cApp.system.csrfHeaders(),
            success: function (response) {
                cApp.system.removeSpinner($this);

                if (response.trim() === '##accessdenied##') {
                    cApp.layers.login();
                } else {
                    // Cleanly shut down field extensions in the replaced content
                    if (cApp.extensions && cApp.extensions.destroyAll) {
                        cApp.extensions.destroyAll($this);
                    }
                    $this.html(response);
                    // Bind the event handlers of the new content immediately (backend-handlers.js;
                    // the MutationObserver would only come after this call)
                    if (cApp.handlers) cApp.handlers.scan($this[0]);
                    
                    // Wait until the content is surely rendered
                    requestAnimationFrame(() => {
                        // Initialize field extensions (class c9-ext) - loads their
                        // assets the first time
                        if (cApp.extensions && cApp.extensions.refreshAll) {
                            cApp.extensions.refreshAll($this);
                        }

                        // Refresh smartrows containers in the freshly loaded content.
                        // The up/down buttons are hidden via CSS and
                        // are only shown by refresh() - without this call
                        // every row loaded later would remain unsortable.
                        if (cApp.smartrows && cApp.smartrows.refreshAll) {
                            cApp.smartrows.refreshAll($this);
                        }

                        // Set focus on the first visible form element
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
                    settings.callback(); // So that polling does not die on error
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
                // Clean up after completion, if it is still this request
                if ($this.data('xhr') === xhr) {
                    $this.removeData('xhr');
                }
            }
        });

        $this.data('xhr', xhr);
        return this;
    };

    // ---------------------------------------------------------------------------
    // jQuery extension: serializeFiles
    // ---------------------------------------------------------------------------
    /**
     * Serializes a search form as FormData, but ignores completely empty
     * fields (value === '') and unused file uploads, to keep URLs short.
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
            // Text fields: only take over if the string is not completely empty.
            // Intentional spaces thus remain untouched.
            if (typeof value === 'string') {
                if (value !== '') {
                    filteredData.append(key, value);
                    totalSize += value.length;
                }
            } 
            // File uploads: only take over if an actual file was selected.
            else if (value instanceof File && value.size > 0) {
                filteredData.append(key, value);
                totalSize += value.size;
            }
            // For anything that might be added in the future
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

