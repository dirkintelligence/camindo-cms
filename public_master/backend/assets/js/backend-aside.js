/*
 * cApp.aside – Seitliches Panel
 * Resize per Drag-Handle, open/close API
 */
(function (cApp) {
    'use strict';

    cApp.aside = {

        minWidth:        200,
        minArticleWidth: 200,
        defaultWidth:    300,
        currentWidth:    0,
        _isResizing:     false,

        init() {
            const $handle  = $('.resize-handle');
            const $overlay = $('.resize-overlay');

            // Drag starten
            $handle.on('mousedown', (e) => {
                e.preventDefault();
                this._isResizing = true;
                $overlay.show();
                $('body').css('user-select', 'none');
            });

            // Drag bewegen
            $(document).on('mousemove', (e) => {
                if (!this._isResizing) return;

                const windowWidth  = $(window).width();
                const maxAsideWidth = windowWidth - this.minArticleWidth;
                // Das Panel liegt am Zeilenende: rechts, bei dir="rtl" links
                let width = this._rtl() ? e.clientX : windowWidth - e.clientX;

                width = Math.min(width, maxAsideWidth);
                if (maxAsideWidth >= this.minWidth) {
                    width = Math.max(width, this.minWidth);
                }
                width = Math.max(0, width);

                this._setWidth(width);
            });

            // Drag beenden
            $(document).on('mouseup', () => {
                if (!this._isResizing) return;
                this._isResizing = false;
                $overlay.hide();
                $('body').css('user-select', '');
            });

            // Fenstergröße anpassen
            $(window).on('resize', () => {
                if (this.currentWidth > 0 && $('aside').is(':visible')) {
                    const maxAsideWidth = $(window).width() - this.minArticleWidth;
                    if (this.currentWidth > maxAsideWidth) {
                        this._setWidth(Math.max(0, maxAsideWidth));
                    }
                }
            });
        },

        // --- Öffentliche API ---

        /**
         * Öffnet das Aside-Panel und lädt optional einen Inhalt.
         * @param {string} url  Optionaler Pfad für cLoadContent
         */
        open(url, width) {
            const maxPossible = $(window).width() - this.minArticleWidth;
            if (width) {
                this.currentWidth = Math.min(width, Math.max(0, maxPossible));
            }
            else if (this.currentWidth === 0) {
                this.currentWidth = Math.min(this.defaultWidth, Math.max(0, maxPossible));
            }
            $('aside').show();
            this._setWidth(this.currentWidth);
            if (url) {
                $('#aside-content').cLoadContent(url);
            }
        },

        /**
         * Wie open(), aber der Inhalt wird per POST geholt - für Aktionen mit
         * Seiteneffekt im Aside (17-csrf.md §2.7), z. B. der Komponenten-Abgleich.
         * @param {string} url
         * @param {number} width  optional
         */
        openPost(url, width) {
            this.open('', width);
            if (url) {
                $('#aside-content').cLoadContent({
                    url:    url,
                    params: new FormData(),
                    method: 'POST'
                });
            }
        },

        /**
         * Schließt das Aside-Panel und leert den Inhalt.
         */
        close() {
            this._setWidth(0);
            $('#aside-content').empty();
            $('aside').hide();
        },

        // --- Intern ---

        // Logische Properties (inset-inline-end, margin-inline-end): rechts bzw. bei
        // dir="rtl" links - wie aside in backend.css. Einheit ausdrücklich, jQuery
        // hängt 'px' nur an bekannte Properties an.
        _setWidth(width) {
            this.currentWidth = width;
            $('aside').css('width', width);
            $('article').css('inset-inline-end', width + 'px');
            $('#c9-navi').css('inset-inline-end', (width + 20) + 'px');
            $('.button-bar').css('margin-inline-end', width + 'px');
        },

        _rtl() {
            return document.documentElement.dir === 'rtl';
        }
    };

}(window.cApp));
