/*
 * cApp.embedded – Permanently linked content, embedded
 *
 * Input mask of records: a field of type 'link' with display_type 'embed'
 * shows the input masks of its contents as cells (.c9-embed-cell) in a block
 * (.c9-embed-block, RecordBackendEdit::_embedBlock()). On hover each cell gets
 * a more_vert menu at the top left (insert before / after, move up / down,
 * delete), below the cells there are buttons for appending.
 *
 * All actions go through Record/ajax_embed.php (RecordBackendEdit::embedAction()):
 *
 *     insert   the server creates the working copy of the new content and returns
 *              the finished cell - it is inserted at its position
 *     up/down  the server swaps the order (data_rel.position), here the cells
 *              are swapped
 *     delete   the server removes the relation, the cell disappears
 *
 * The other cells remain untouched - input in them is not lost.
 * The contents are saved with the record (embed[{record_id}][feld]).
 * A non-empty response for up/down/delete is an error message and is
 * shown above the block.
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
         * Entries of the cell menu
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
         * Buttons "Add content" below the cells
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
         * While the menu is open the cell stays highlighted (the menu
         * hangs at the cell edge, so the mouse leaves the cell)
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
         * URL of an action: update-url of the block plus parameters
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
         * Ajax call without target element: response as text
         */
        _request(url, done) {
            cApp.system.addSpinner();
            $.ajax({
                // POST: insert/up/down/delete create, reorder and detach
                // (developer docs, chapter 14 (Security: web)). The parameters stay in the query string, the body
                // is empty - Record/ajax_embed.php reads them via $_REQUEST.
                type: 'POST',
                url: cConfig.backend_path + 'Modules/' + url + '&cache=' + Date.now(),
                data: new FormData(),
                contentType: false,
                processData: false,
                dataType: 'html',
                // own $.ajax instead of cLoadContent (response as text, no target element) -
                // the CSRF token has to be added by hand here (developer docs, chapter 14 (Security: web))
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
         * Show the server's error message above the block (replaces a previous one)
         */
        _showError($block, html) {
            $block.find('.c9-embed-error').remove();
            $('<div class="c9-embed-error"></div>').html(html).prependTo($block);
        },

        _markChanged($block) {
            $block.closest('form').attr('data-changed', 'true');
        },

        /**
         * Insert new content: before/after $cell, without $cell at the end of the block
         *
         * @param {jQuery|null} $cell   Reference cell
         * @param {boolean}     before  before the reference cell
         * @param {number}      areaId  Target area of the new content
         * @param {jQuery}      $block  Block (only without $cell)
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
         * Move content one position up / down
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
         * Remove content (after confirmation)
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
            void $cell[0].offsetWidth; // restart animation
            $cell.addClass('c9-embed-moved');
        }
    };

}(window.cApp));
