/*
 * cApp.system – System functions
 * Spinner, balloon messages, CSRF token, cookie helper functions
 */
(function (cApp) {
    'use strict';

    // Spinner SVG as a constant (Base64, avoids an external request)
    const SPINNER_SVG = 'data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz4KPCEtLSBHZW5lcmF0ZWQgYnkgSWNvTW9vbi5pbyAtLT4KPCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHdpZHRoPSIzMiIgaGVpZ2h0PSIzMiIgdmlld0JveD0iMCAwIdMyIdMyIj4KPHBhdGggZmlsbD0iIzQ0NCIgZD0iTTMyIdE2Yy0wLjA0MC0yLjA4OS0wLjQ5My00LjE3Mi0xLjMzMS02LjA3Ny0wLjgzNC0xLjkwNi0yLjA0Ni0zLjYzMy0zLjUzMy01LjA2MC0xLjQ4Ni0xLjQyOC0zLjI0OC0yLjU1Ny01LjE1Ni0zLjMwMi0xLjkwNi0wLjc0OC0zLjk1Ni0xLjEwNS01Ljk4MS0xLjA2MS0yLjAyNSAwLjA0MC00LjA0MiAwLjQ4LTUuODg1IdEuMjkyLTEuODQ1IdAuODA5LTMuNTE3IdEuOTgzLTQuODk4IdMuNDI0cy0yLjQ3NCAzLjE0Ny0zLjE5MyA0Ljk5NGMtMC43MjIgMS44NDYtMS4wNjcgMy44MjktMS4wMjMgNS43OSAwLjA0MCAxLjk2MSAwLjQ2OCAzLjkxMSAxLjI1NCA1LjY5NCAwLjc4NCAxLjc4NCAxLjkyMSAzLjQwMSAzLjMxNiA0LjczNiAxLjM5NCAxLjMzNiAzLjA0NiAyLjM5MSA0LjgzMiAzLjA4NSAxLjc4NSAwLjY5NyAzLjcwMSAxLjAyOCA1LjU5OCAwLjk4NSAxLjg5Ny0wLjA0MCAzLjc4LTAuNDU1IdUuNTAyLTEuMjE2IdEuNzIzLTAuNzU5IdMuMjg1LTEuODU5IdQuNTc0LTMuMjA4IdEuMjktMS4zNDggMi4zMDgtMi45NDUgMi45NzctNC42NyAwLjQwNy0xLjA0NiAwLjY4NC0yLjEzNyAwLjgyOS0zLjI0NCAwLjAzOSAwLjAwMiAwLjA3OCAwLjAwNCAwLjExOCAwLjAwNCAxLjEwNSAwIdItMC44OTUgMi0yIdAtMC4wNTYtMC4wMDMtMC4xMTItMC4wMDctMC4xNjdoMC4wMDd6TTI4LjgyMiAyMS4zMTFjLTAuNzMzIdEuNjYzLTEuNzk2IdMuMTY5LTMuMDk5IdQuNDEycy0yLjg0NCAyLjIyNS00LjUwOCAyLjg2OGMtMS42NjMgMC42NDYtMy40NDcgMC45NTItNS4yMTUgMC45MDktMS43NjktMC4wNDEtMy41MTktMC40MjktNS4xMTktMS4xNGMtMS42MDItMC43MDgtMy4wNTMtMS43MzQtNC4yNS0yLjk5MXMtMi4xNDEtMi43NDMtMi43Ni00LjM0NmMtMC42MjEtMS42MDMtMC45MTMtMy4zMTktMC44NzEtNS4wMjQgMC4wNDEtMS43MDUgMC40MTctMy4zODggMS4xMDItNC45MjggMC42ODMtMS41NDEgMS42NzItMi45MzcgMi44ODMtNC4wODhzMi42NDItMi4wNTggNC4xODQtMi42NTJjMS41NDItMC41OTYgMy4xOTItMC44NzUgNC44MzItMC44MzMgMS42NDEgMC4wNDEgMy4yNTcgMC40MDQgNC43MzYgMS4wNjQgMS40OCAwLjY1OCAyLjgyIdEuNjA5IdMuOTI2IdIuNzc0czEuOTc1IdIuNTQgMi41NDMgNC4wMjFjMC41NyAxLjQ4MSAwLjgzNyAzLjA2NCAwLjc5NCA0LjY0MWgwLjAwN2MtMC4wMDUgMC4wNTUtMC4wMDcgMC4xMS0wLjAwNyAwLjE2NyAwIdEuMDMyIdAuNzgxIdEuODggMS43ODQgMS45ODgtMC4xOTUgMS4wODgtMC41MTcgMi4xNTEtMC45NjIgMy4xNTZ6Ij48L3BhdGg+Cjwvc3ZnPgo=';

    cApp.system = {

        // --- Spinner ---

        addSpinner() {
            this.removeSpinner();
            $('body').append(
                `<div class="c9-indicator"><div class="c9-indicator-inc">` +
                `<img src="${SPINNER_SVG}" class="c9-spinner"></div></div>`
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
            if ($('#balloon-messages').length === 0) {
                $('body').append('<div id="balloon-messages"></div>');
            }

            const $message = $(`
                <div class="alert alert-${cType} shadow-sm position-relative pe-5">
                    <button type="button" class="btn btn-light btn-sm position-absolute" aria-label="Schließen">
                        <span aria-hidden="true" class="icon">close</span>
                    </button>
                    <div>${cMsg}</div>
                </div>
            `);

            $('#balloon-messages').append($message);

            const close = () => {
                $message.animate({ opacity: 0 }, 'fast', function () {
                    $(this).slideUp('fast', function () { $(this).remove(); });
                });
            };

            $message.find('button').on('click', close);
            setTimeout(close, 3000);
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
