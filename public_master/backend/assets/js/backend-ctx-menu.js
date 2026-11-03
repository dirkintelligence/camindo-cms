/*
 * cApp.ctxMenu – Context menu logic
 * Left click and right click on .c9-ctx-row
 *
 * Row click opens data-path of the table as a layer ("#" = data-id of the row). Instead
 * of a layer, data-event on the table (row click) or on a menu entry triggers a jQuery
 * event on document with the id - for actions of a module script, e.g.
 * data-event="c9:ai-open" -> $(document).on('c9:ai-open', (e, id) => …).
 * Entries that open a new tab become real links when the menu opens (href,
 * target="_blank") - so a right click on them offers the link menu of the browser
 * (e.g. open in split view), and they can be dragged like links:
 *     data-row-url="preview"   address of the row (data-url-preview) - addresses that
 *                              differ per row; without it on the row the entry is hidden
 *     data-layer-href="…?id=#" backend address with this layer path in the hash
 *
 * Rows of a table with data-drag-path ("#" = data-id) can be dragged to another
 * tab or window: the backend address with this path in the hash, additionally the
 * path as LAYER_TYPE. Another backend tab accepts the drop and opens the path as a
 * new layer (_bindLayerDrop()); elsewhere the browser opens the address.
 */
(function (cApp) {
    'use strict';

    // drag data type of a layer path (row of a table with data-drag-path)
    const LAYER_TYPE = 'application/x-camindo-layer';

    /**
     * Backend address that opens a layer path (cApp.layers reads the hash).
     * @param  {string} path  e.g. "Record/record_info.php?id=42"
     * @returns {string}
     */
    function layerUrl(path) {
        return location.href.split('#')[0] + '#' + path;
    }

    cApp.ctxMenu = {

        // true while a row of THIS document is being dragged - no drop on itself
        _dragging: false,

        init() {
            this._bindRowClick();
            this._bindRowContextMenu();
            this._bindDismiss();
            this._bindMenuItemClick();
            this._bindNoMenu();
            this._bindRowDrag();
            this._bindLayerDrop();
        },

        /**
         * Left click on table row → open layer
         */
        _bindRowClick() {
            $(document).on('click', '.c9-ctx-row:not(.c9-click-disabled) td:not(.c9-click-disabled)', function (e) {
                e.preventDefault();
                const $tr    = $(this).closest('tr'); 
                const $table = $(this).closest('table');
                const path   = $table.data('path');
                const id     = $tr.data('id');
                const event  = $table.data('event');

                if (event) $(document).trigger(String(event), [id]);
                else cApp.layers.add(path.replace('#', id));
            });
        },

        /**
         * Right click → position and show the context menu
         */
        _bindRowContextMenu() {
            $(document).on('contextmenu', '.c9-ctx-row', function (e) {
                // Check whether the row is disabled
                if ($(this).hasClass('c9-ctx-disabled')) {
                    e.preventDefault(); // Prevents the default browser menu
                    return;             // Aborts, our own menu is not loaded
                }

                // Checks whether the cell under the mouse (or an element inside it) is disabled
                if ($(e.target).closest('.c9-ctx-disabled').length > 0) {
                    e.preventDefault();
                    return;
                }

                e.preventDefault();

                const $menu = $(this).closest('.container').find('.c9-ctx-menu');
                $menu.data('current-id', $(this).data('id'));

                // Entries that do not apply to THIS row (data-ctx-hide="copy,delete"
                // on the row, data-ctx="..." on the entry) - e.g. missing permissions
                const hide = String($(this).data('ctx-hide') || '').split(',').filter(Boolean);
                const $row = $(this);
                const id   = $row.data('id');
                $menu.find('[data-ctx]').each(function () {
                    // entries for a new tab: link to the address of this row
                    let href = null;
                    if ($(this).is('[data-row-url]')) {
                        href = $row.attr('data-url-' + $(this).data('row-url')) || '';
                    }
                    else if ($(this).is('[data-layer-href]')) {
                        href = layerUrl(String($(this).data('layer-href')).replace('#', id));
                    }
                    if (href) $(this).attr({ href: href, target: '_blank', rel: 'noopener' });
                    $(this).toggle(href !== '' && !hide.includes(String($(this).data('ctx'))));
                });

                // Close all other menus
                $('.c9-ctx-menu').not($menu).fadeOut(100);

                // Menu next to the mouse pointer, with dir="rtl" to the left of it
                $menu.show();
                const left = (document.documentElement.dir === 'rtl')
                    ? Math.max(0, e.pageX - $menu.outerWidth()) : e.pageX;
                $menu.offset({ left: left, top: e.pageY }).hide().fadeIn(100);

                // Keyboard: menu roles, focus on the first entry, Escape back to the row
                if (cApp.a11y) cApp.a11y.menuOpened($menu, this);
            });
        },

        /**
         * Close menus on click outside
         */
        _bindDismiss() {
            $(document).on('click', function (e) {
                if (!$(e.target).closest('.c9-ctx-menu').length) {
                    $('.c9-ctx-menu').fadeOut(100);
                }
            });
        },

        /**
         * Click on a menu item → execute the action
         *
         * data-method="post" on the entry sends the action via POST - mandatory for
         * everything with side effects (developer docs, chapter 14 (Security: web)). Without it the
         * request stays a GET, which is the normal case (opening a mask).
         */
        _bindMenuItemClick() {
            $(document).on('click', '.c9-ctx-menu .dropdown-item', function (e) {
                // entry for a new tab: the link opens it itself
                if ($(this).is('[data-row-url], [data-layer-href]')) {
                    $(this).closest('.c9-ctx-menu').fadeOut(100);
                    return;
                }
                e.preventDefault();

                const action = $(this).data('action');
                const target = $(this).data('target');
                const post   = String($(this).data('method') || '').toLowerCase() === 'post';
                const $menu  = $(this).closest('.c9-ctx-menu');
                const dataId = $menu.data('current-id');

                if (!dataId) return;

                const event = $(this).data('event');
                if (event) {
                    $menu.fadeOut(100);
                    $(document).trigger(String(event), [dataId]);
                    return;
                }

                const url = action.replace('#', dataId);
                if (target) {
                    $(target).cLoadContent(post
                        ? { url: url, params: new FormData(), method: 'POST' }
                        : url);
                }
                else if (post) cApp.layers.addPost(url, new FormData());
                else cApp.layers.add(url);

                $menu.fadeOut(100);
            });
        },

        /**
         * Right click on element → show no menu
         */
        _bindNoMenu() {
            $(document).on('contextmenu', '.c9-no-ctx', function (e) {
                e.preventDefault(); // Prevents the default browser menu
                return;
            });
        },

        /**
         * Drag a row → link to the backend with the drag path of the table in the hash
         */
        _bindRowDrag() {
            const self = this;
            $(document).on('dragstart', 'table[data-drag-path] tr.c9-ctx-row', function (e) {
                const dt = e.originalEvent.dataTransfer;
                if (!dt) return;
                const path = String($(this).closest('table').data('drag-path')).replace('#', $(this).data('id'));
                dt.setData('text/uri-list', layerUrl(path));
                dt.setData('text/plain', layerUrl(path));
                dt.setData(LAYER_TYPE, path);
                dt.effectAllowed = 'copyLink';
                self._dragging = true;
            });
            $(document).on('dragend', 'table[data-drag-path] tr.c9-ctx-row', function () {
                self._dragging = false;
            });
        },

        /**
         * Drop of a row from ANOTHER backend tab or window → its layer path as a new
         * layer here (the layers below stay). Input fields keep the default (the address
         * is inserted).
         */
        _bindLayerDrop() {
            const self = this;
            const accepts = function (e) {
                const dt = e.originalEvent.dataTransfer;
                return !self._dragging && dt && Array.from(dt.types || []).includes(LAYER_TYPE)
                    && !$(e.target).closest('input, textarea, [contenteditable]').length;
            };
            $(document).on('dragover', function (e) {
                if (!accepts(e)) return;
                e.preventDefault();
                e.originalEvent.dataTransfer.dropEffect = 'link';
            });
            $(document).on('drop', function (e) {
                if (!accepts(e)) return;
                e.preventDefault();
                const path = String(e.originalEvent.dataTransfer.getData(LAYER_TYPE));
                // a module path as in the hash - nothing else
                if (!/^[A-Za-z0-9_]+\/[A-Za-z0-9_.-]+\.php(\?[^~#]*)?$/.test(path)) return;
                window.focus();
                cApp.layers.add(path);
            });
        }
    };

}(window.cApp));
