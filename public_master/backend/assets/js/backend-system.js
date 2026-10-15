/*
 * cApp.system – System functions
 * Spinner, balloon messages, CSRF token, cookie helper functions
 */
(function (cApp) {
    'use strict';

    // Spinner SVG as a constant (inline data URI, avoids an external request). Plain markup
    // instead of Base64, so search-and-replace across the code cannot corrupt it unnoticed.
    const SPINNER_SVG = 'data:image/svg+xml,' + encodeURIComponent(
        '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 32 32">'
        + '<path fill="#444" d="M32 16c-0.040-2.089-0.493-4.172-1.331-6.077-0.834-1.906-2.046-3.633-3.533-5.060-1.486-1.428-3.248-2.557-5.156-3.302-1.906-0.748-3.956-1.105-5.981-1.061-2.025 0.040-4.042 0.48-5.885 1.292-1.845 0.809-3.517 1.983-4.898 3.424s-2.474 3.147-3.193 4.994c-0.722 1.846-1.067 3.829-1.023 5.79 0.040 1.961 0.468 3.911 1.254 5.694 0.784 1.784 1.921 3.401 3.316 4.736 1.394 1.336 3.046 2.391 4.832 3.085 1.785 0.697 3.701 1.028 5.598 0.985 1.897-0.040 3.78-0.455 5.502-1.216 1.723-0.759 3.285-1.859 4.574-3.208 1.29-1.348 2.308-2.945 2.977-4.67 0.407-1.046 0.684-2.137 0.829-3.244 0.039 0.002 0.078 0.004 0.118 0.004 1.105 0 2-0.895 2-2 0-0.056-0.003-0.112-0.007-0.167h0.007zM28.822 21.311c-0.733 1.663-1.796 3.169-3.099 4.412s-2.844 2.225-4.508 2.868c-1.663 0.646-3.447 0.952-5.215 0.909-1.769-0.041-3.519-0.429-5.119-1.14c-1.602-0.708-3.053-1.734-4.25-2.991s-2.141-2.743-2.76-4.346c-0.621-1.603-0.913-3.319-0.871-5.024 0.041-1.705 0.417-3.388 1.102-4.928 0.683-1.541 1.672-2.937 2.883-4.088s2.642-2.058 4.184-2.652c1.542-0.596 3.192-0.875 4.832-0.833 1.641 0.041 3.257 0.404 4.736 1.064 1.48 0.658 2.82 1.609 3.926 2.774s1.975 2.54 2.543 4.021c0.57 1.481 0.837 3.064 0.794 4.641h0.007c-0.005 0.055-0.007 0.11-0.007 0.167 0 1.032 0.781 1.88 1.784 1.988-0.195 1.088-0.517 2.151-0.962 3.156z"/></svg>'
    );

    cApp.system = {

        // --- Spinner ---

        addSpinner() {
            this.removeSpinner();
            // role="status": screen readers announce the loading (WCAG 4.1.3)
            const label = String((window.cConfig && window.cConfig.label_loading) || '')
                .replace(/[<>&"]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[c]));
            $('body').append(
                `<div class="c9-indicator" role="status"><div class="c9-indicator-inc">` +
                `<img src="${SPINNER_SVG}" class="c9-spinner" alt="">` +
                `<span class="visually-hidden">${label}</span></div></div>`
            );
            $('.c9-indicator-inc').css('opacity', 0).animate({ opacity: 0.75 });
        },

        removeSpinner() {
            $('.c9-indicator').remove();
        },

        // --- Balloon messages ---

        /**
         * Shows a temporary notification.
         * @param {string} cType  Bootstrap type: 'success', 'danger', 'warning', 'info'
         * @param {string} cMsg   Message text (HTML allowed)
         */
        balloon(cType, cMsg) {
            // Live regions (WCAG 4.1.3): errors assertive, everything else polite. They
            // must exist before the message - created once, empty.
            if ($('#balloon-messages').length === 0) {
                $('body').append('<div id="balloon-messages"><div class="c9-balloon-polite" role="status" aria-live="polite"></div>'
                    + '<div class="c9-balloon-assertive" role="alert" aria-live="assertive"></div></div>');
            }
            const closeLabel = String((window.cConfig && window.cConfig.button_close) || 'Close')
                .replace(/[<>&"]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[c]));

            const $message = $(`
                <div class="alert alert-${cType} shadow-sm position-relative pe-5">
                    <button type="button" class="btn btn-light btn-sm position-absolute" aria-label="${closeLabel}">
                        <span aria-hidden="true" class="icon">close</span>
                    </button>
                    <div>${cMsg}</div>
                </div>
            `);

            const assertive = (cType === 'danger' || cType === 'warning');
            $('#balloon-messages').find(assertive ? '.c9-balloon-assertive' : '.c9-balloon-polite').append($message);

            const close = () => {
                $message.animate({ opacity: 0 }, 'fast', function () {
                    $(this).slideUp('fast', function () { $(this).remove(); });
                });
            };

            // Enough time to read (WCAG 2.2.1): errors and warnings stay longer;
            // while the mouse or the focus is on the message it does not disappear
            let timer = null;
            const schedule = () => { timer = setTimeout(close, assertive ? 10000 : 5000); };
            $message.on('mouseenter focusin', () => clearTimeout(timer));
            $message.on('mouseleave focusout', () => { clearTimeout(timer); schedule(); });
            $message.find('button').on('click', close);
            schedule();
        },

        // --- CSRF token (developer docs, chapter 14 (Security: web)) ---

        /**
         * Header for every Ajax call to the backend. The server requires the token
         * for everything except GET (Session::verifyCsrfRequest()).
         *
         * Read on EVERY request, not once when the page loads: after
         * a login in the dialog a new session applies and thus a new
         * token (setCsrfToken(), sent by StartBackend::_loginComplete()).
         *
         * @returns {object}  empty as long as there is no token (not logged in)
         */
        csrfHeaders() {
            const token = (window.cConfig && window.cConfig.csrf_token) || '';
            return token ? { 'X-Camindo-Token': token } : {};
        },

        /**
         * Take over the token after a login, or clear it on logout.
         * @param {string} token
         */
        setCsrfToken(token) {
            if (window.cConfig) window.cConfig.csrf_token = String(token || '');
        },

        // --- Cookie helper functions ---

        /**
         * @param {string} name
         * @param {string} value
         * @param {number} days  Expiry in days (optional)
         */
        setCookie(name, value, days) {
            let expires = '';
            if (days) {
                const date = new Date();
                date.setTime(date.getTime() + days * 24 * 60 * 60 * 1000);
                expires = '; expires=' + date.toUTCString();
            }
            document.cookie = `${name}=${value}${expires}; path=/`;
        },

        /**
         * @param  {string} name
         * @returns {string} Cookie value or empty string
         */
        getCookie(name) {
            const needle = name + '=';
            for (let c of document.cookie.split(';')) {
                c = c.trimStart();
                if (c.startsWith(needle)) return c.substring(needle.length);
            }
            return '';
        }
    };

}(window.cApp));
