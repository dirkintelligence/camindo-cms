/*
 * cApp.ctxMenu – Kontextmenü-Logik
 * Linksklick und Rechtsklick auf .c9-ctx-row
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
         * Linksklick auf Tabellenzeile → Layer öffnen
         */
        _bindRowClick() {
            $(document).on('click', '.c9-ctx-row:not(.c9-click-disabled) td:not(.c9-click-disabled)', function (e) {
                e.preventDefault();
                const $tr    = $(this).closest('tr'); 
                const $table = $(this).closest('table');
                const path   = $table.data('path');
                const id     = $tr.data('id');
                
                cApp.layers.add(path.replace('#', id));
            });
        },

        /**
         * Rechtsklick → Kontextmenü positionieren und einblenden
         */
        _bindRowContextMenu() {
            $(document).on('contextmenu', '.c9-ctx-row', function (e) {
                // Prüfen, ob die Zeile deaktiviert ist
                if ($(this).hasClass('c9-ctx-disabled')) {
                    e.preventDefault(); // Verhindert das Standard-Browser-Menü
                    return;             // Bricht ab, das eigene Menü wird nicht geladen
                }

                // Prüft, ob die Zelle - über der die Maus ist - (oder ein Element darin) deaktiviert ist
                if ($(e.target).closest('.c9-ctx-disabled').length > 0) {
                    e.preventDefault();
                    return;
                }

                e.preventDefault();

                const $menu = $(this).closest('.container').find('.c9-ctx-menu');
                $menu.data('current-id', $(this).data('id'));

                // Einträge, die für DIESE Zeile nicht gelten (data-ctx-hide="copy,delete"
                // an der Zeile, data-ctx="..." am Eintrag) - z. B. fehlende Rechte
                const hide = String($(this).data('ctx-hide') || '').split(',').filter(Boolean);
                $menu.find('[data-ctx]').each(function () {
                    $(this).toggle(!hide.includes(String($(this).data('ctx'))));
                });

                // Alle anderen Menüs schließen
                $('.c9-ctx-menu').not($menu).fadeOut(100);

                // Menü neben dem Mauszeiger, bei dir="rtl" links davon
                $menu.show();
                const left = (document.documentElement.dir === 'rtl')
                    ? Math.max(0, e.pageX - $menu.outerWidth()) : e.pageX;
                $menu.offset({ left: left, top: e.pageY }).hide().fadeIn(100);
            });
        },

        /**
         * Menüs bei Klick außerhalb schließen
         */
        _bindDismiss() {
            $(document).on('click', function (e) {
                if (!$(e.target).closest('.c9-ctx-menu').length) {
                    $('.c9-ctx-menu').fadeOut(100);
                }
            });
        },

        /**
         * Klick auf ein Menü-Item → Aktion ausführen
         *
         * data-method="post" am Eintrag schickt die Aktion per POST - Pflicht für
         * alles mit Seiteneffekt (17-csrf.md §2.7). Ohne die Angabe bleibt es beim
         * GET, das ist der Normalfall (eine Maske öffnen).
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
         * Rechts auf Element → kein Menü anzeigen
         */
        _bindNoMenu() {
            $(document).on('contextmenu', '.c9-no-ctx', function (e) {
                e.preventDefault(); // Verhindert das Standard-Browser-Menü
                return;
            });
        }
    };

}(window.cApp));
