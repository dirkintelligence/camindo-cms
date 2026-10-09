/*
 * cApp.embedded – Fest verknüpfte Inhalte, eingebettet
 *
 * Eingabemaske der Datensätze: ein Feld vom Typ 'link' mit display_type 'embed'
 * zeigt die Eingabemasken seiner Inhalte als Zellen (.c9-embed-cell) in einem Block
 * (.c9-embed-block, RecordBackendEdit::_embedBlock()). Jede Zelle bekommt beim
 * Überfahren ein more_vert-Menü links oben (Einfügen vor / nach, nach oben / unten,
 * Löschen), unter den Zellen stehen Schaltflächen zum Anfügen.
 *
 * Alle Aktionen laufen über Record/ajax_embed.php (RecordBackendEdit::embedAction()):
 *
 *     insert   der Server legt die Arbeitskopie des neuen Inhalts an und liefert
 *              die fertige Zelle - sie wird an ihrer Stelle eingesetzt
 *     up/down  der Server tauscht die Reihenfolge (data_rel.position), hier werden
 *              die Zellen getauscht
 *     delete   der Server löst die Verknüpfung, die Zelle verschwindet
 *
 * Die übrigen Zellen bleiben unberührt - Eingaben darin gehen nicht verloren.
 * Gespeichert werden die Inhalte mit dem Datensatz (embed[{record_id}][feld]).
 * Eine nicht leere Antwort bei up/down/delete ist eine Fehlermeldung und wird
 * über dem Block angezeigt.
 */
(function (cApp) {
    'use strict';

    cApp.embedded = {

        init() {
            this._bindMenuActions();
            this._bindAddButtons();
            this._bindMenuState();
        },

        /**
         * Einträge des Zellenmenüs
         */
        _bindMenuActions() {
            $(document).on('click', '.c9-embed-menu [data-embed-action]', (e) => {
                e.preventDefault();
                const $item = $(e.currentTarget);
                const $cell = $item.closest('.c9-embed-cell');
                const action = $item.data('embed-action');
                const areaId = $item.data('area-id') || 0;
                this._closeMenu($cell);
                switch (action) {
                    case 'insert_before': this.insert($cell, true, areaId); break;
                    case 'insert_after':  this.insert($cell, false, areaId); break;
                    case 'up':            this.move($cell, 'up'); break;
                    case 'down':          this.move($cell, 'down'); break;
                    case 'delete':        this.remove($cell); break;
                }
            });
        },

        /**
         * Schaltflächen "Inhalt hinzufügen" unter den Zellen
         */
        _bindAddButtons() {
            $(document).on('click', '.c9-embed-add [data-embed-action="append"]', (e) => {
                e.preventDefault();
                const $btn = $(e.currentTarget);
                const $block = $btn.closest('.c9-embed-block');
                this.insert(null, false, $btn.data('area-id') || 0, $block);
            });
        },

        /**
         * Solange das Menü offen ist, bleibt die Zelle hervorgehoben (das Menü
         * hängt am Zellenrand, die Maus verlässt dabei die Zelle)
         */
        _bindMenuState() {
            $(document).on('show.bs.dropdown', '.c9-embed-handle', (e) => {
                $(e.currentTarget).closest('.c9-embed-cell').addClass('c9-embed-active');
            });
            $(document).on('hidden.bs.dropdown', '.c9-embed-handle', (e) => {
                $(e.currentTarget).closest('.c9-embed-cell').removeClass('c9-embed-active');
            });
        },

        _closeMenu($cell) {
            const btn = $cell.find('.c9-embed-menu-btn')[0];
            if (!btn) return;
            const dropdown = bootstrap.Dropdown.getInstance(btn);
            if (dropdown) dropdown.hide();
        },

        /**
         * URL einer Aktion: update-url des Blocks plus Parameter
         */
        _url($block, params) {
            let url = $block.data('update-url');
            const tab = String($block.data('tab') ?? '');
            if (tab !== '') params.tab = tab;
            Object.keys(params).forEach((key) => {
                url += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
            });
            return url;
        },

        /**
         * Ajax-Aufruf ohne Ziel-Element: Antwort als Text
         */
        _request(url, done) {
            cApp.system.addSpinner();
            $.ajax({
                // POST: insert/up/down/delete legen an, sortieren um und hängen aus
                // (17-csrf.md §2.7). Die Parameter bleiben im Query-String, der Körper
                // ist leer - Record/ajax_embed.php liest sie über $_REQUEST.
                type: 'POST',
                url: cConfig.backend_path + 'Modules/' + url + '&cache=' + Date.now(),
                data: new FormData(),
                contentType: false,
                processData: false,
                dataType: 'html',
                // eigener $.ajax statt cLoadContent (Antwort als Text, kein Zielelement) -
                // das CSRF-Token muss hier von Hand mit (17-csrf.md §2.4)
                headers: cApp.system.csrfHeaders(),
                success: (response) => {
                    cApp.system.removeSpinner();
                    if (response.trim() === '##accessdenied##') {
                        cApp.layers.login();
                        return;
                    }
                    done(response);
                },
                error: (jqXHR, textStatus) => {
                    cApp.system.removeSpinner();
                    if (jqXHR.status === 0) return;
                    alert(window.cConfig.message_connection_error + ' | ' + jqXHR.status + ' | ' + textStatus);
                }
            });
        },

        /**
         * Fehlermeldung des Servers über dem Block anzeigen (ersetzt eine vorherige)
         */
        _showError($block, html) {
            $block.find('.c9-embed-error').remove();
            $('<div class="c9-embed-error"></div>').html(html).prependTo($block);
        },

        _markChanged($block) {
            $block.closest('form').attr('data-changed', 'true');
        },

        /**
         * Neuen Inhalt einfügen: vor/nach $cell, ohne $cell ans Ende des Blocks
         *
         * @param {jQuery|null} $cell   Bezugszelle
         * @param {boolean}     before  vor der Bezugszelle
         * @param {number}      areaId  Zielbereich des neuen Inhalts
         * @param {jQuery}      $block  Block (nur ohne $cell)
         */
        insert($cell, before, areaId, $block = null) {
            $block = $block || $cell.closest('.c9-embed-block');
            const url = this._url($block, {
                function: 'insert',
                dest_id: $cell ? $cell.data('dest-id') : 0,
                before: before ? 1 : 0,
                area_id: areaId
            });
            this._request(url, (response) => {
                const $response = $('<div></div>').html(response);
                const $newCell = $response.find('.c9-embed-cell').first();
                if (!$newCell.length) {
                    this._showError($block, response);
                    return;
                }
                $block.find('.c9-embed-error').remove();
                if ($cell) {
                    if (before) $cell.before($newCell);
                    else $cell.after($newCell);
                }
                else {
                    $block.find('.c9-embed-table').append($newCell);
                }
                cApp.ui._initTextareaGrow($newCell);
                if (cApp.smartrows && cApp.smartrows.refreshAll) cApp.smartrows.refreshAll($newCell);
                this._flash($newCell);
                this._markChanged($block);
                $newCell.find('input, select, textarea').filter(':visible:not(:disabled)').first().trigger('focus');
            });
        },

        /**
         * Inhalt eine Stelle nach oben / unten
         */
        move($cell, direction) {
            const $block = $cell.closest('.c9-embed-block');
            const $target = (direction === 'up') ? $cell.prev('.c9-embed-cell') : $cell.next('.c9-embed-cell');
            if (!$target.length) return;
            const url = this._url($block, {
                function: direction,
                dest_id: $cell.data('dest-id')
            });
            this._request(url, (response) => {
                if (response.trim() !== '') {
                    this._showError($block, response);
                    return;
                }
                $block.find('.c9-embed-error').remove();
                if (direction === 'up') $target.before($cell);
                else $target.after($cell);
                this._flash($cell);
                this._markChanged($block);
            });
        },

        /**
         * Inhalt entfernen (nach Rückfrage)
         */
        remove($cell) {
            const $block = $cell.closest('.c9-embed-block');
            const question = $block.data('confirm-delete');
            if (question && !confirm(question)) return;
            const url = this._url($block, {
                function: 'delete',
                dest_id: $cell.data('dest-id')
            });
            this._request(url, (response) => {
                if (response.trim() !== '') {
                    this._showError($block, response);
                    return;
                }
                $block.find('.c9-embed-error').remove();
                $cell.remove();
                this._markChanged($block);
            });
        },

        _flash($cell) {
            $cell.removeClass('c9-embed-moved');
            void $cell[0].offsetWidth; // Animation neu starten
            $cell.addClass('c9-embed-moved');
        }
    };

}(window.cApp));
