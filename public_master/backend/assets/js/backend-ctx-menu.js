/*
 * cApp.ctxMenu – Context menu logic
 * Left click and right click on .c9-ctx-row
 *
 * Row click opens data-path of the table as a layer ("#" = data-id of the row). Instead
 * of a layer, data-event on the table (row click) or on a menu entry triggers a jQuery
 * event on document with the id - for actions of a module script, e.g.
 * data-event="c9:ai-open" -> $(document).on('c9:ai-open', (e, id) => …).
 */
(function (cApp) {
    'use strict';

    cApp.ctxMenu = {

        init() {
            this._bindRowClick();
            this._bindRowContextMenu();
            this._bindDismiss();
            this._bindMenuItemClick();
            this._bindNoMenu();
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
                $menu.find('[data-ctx]').each(function () {
                    $(this).toggle(!hide.includes(String($(this).data('ctx'))));
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
        }
    };

}(window.cApp));
