/*
 * cApp.aside – Side panel
 * Resize via drag handle, open/close API
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

            // Start drag
            $handle.on('mousedown', (e) => {
                e.preventDefault();
                this._isResizing = true;
                $overlay.show();
                $('body').css('user-select', 'none');
            });

            // Move drag
            $(document).on('mousemove', (e) => {
                if (!this._isResizing) return;

                const windowWidth  = $(window).width();
                const maxAsideWidth = windowWidth - this.minArticleWidth;
                // The panel sits at the end of the line: right, with dir="rtl" left
                let width = this._rtl() ? e.clientX : windowWidth - e.clientX;

                width = Math.min(width, maxAsideWidth);
                if (maxAsideWidth >= this.minWidth) {
                    width = Math.max(width, this.minWidth);
                }
                width = Math.max(0, width);

                this._setWidth(width);
            });

            // End drag
            $(document).on('mouseup', () => {
                if (!this._isResizing) return;
                this._isResizing = false;
                $overlay.hide();
                $('body').css('user-select', '');
            });

            // Adjust to window size
            $(window).on('resize', () => {
                if (this.currentWidth > 0 && $('aside').is(':visible')) {
                    const maxAsideWidth = $(window).width() - this.minArticleWidth;
                    if (this.currentWidth > maxAsideWidth) {
                        this._setWidth(Math.max(0, maxAsideWidth));
                    }
                }
            });
        },

        // --- Public API ---

        /**
         * Opens the aside panel and optionally loads content.
         * @param {string} url  Optional path for cLoadContent
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
         * Like open(), but the content is fetched via POST - for actions with
         * side effects in the aside (developer docs, chapter 14 (Security: web)), e.g. the component sync.
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
         * Closes the aside panel and clears its content.
         */
        close() {
            this._setWidth(0);
            $('#aside-content').empty();
            $('aside').hide();
        },

        // --- Internal ---

        // Logical properties (inset-inline-end, margin-inline-end): right, or with
        // dir="rtl" left - like aside in backend.css. Unit stated explicitly, jQuery
        // only appends 'px' to known properties.
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
