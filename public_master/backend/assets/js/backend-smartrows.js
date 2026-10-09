/*
 * cApp.smartrows – Row manipulation in forms
 * Moving, appending, deleting rows
 *
 * Structure in the HTML:
 *     .c9-smartrows-container      wraps all rows
 *       .c9-smartrows-row          one row
 *         .c9-smartrows-up         row up      (optional)
 *         .c9-smartrows-down       row down    (optional)
 *         .c9-smartrows-remove     remove row  (optional)
 *       .c9-smartrows-add          append row  (optional, outside the rows)
 *
 * Not every container needs all four actions: the display_type 'sortable'
 * (Backend::formField) only brings Up/Down - there the selection is fixed and
 * only its order is free.
 */
window.cApp = window.cApp || {}; // Make sure cApp exists

(function (cApp, $) {
    'use strict';

    cApp.smartrows = {

        /**
         * Bind the click handlers once and refresh all containers.
         *
         * Idempotent: init() is called twice - the module registers itself at the end
         * of this file (in case it is loaded alone) AND backend-init.js
         * calls it in the startup order. Without the lock all delegated
         * handlers would be attached twice to the document: a click on "Add row"
         * would create two rows, a click on "Down" would move the row two
         * positions further.
         */
        init() {
            if (!this._bound) {
                this._bindButtons();
                this._bound = true;
            }
            this.refreshAll();
        },

        /** true as soon as _bindButtons() has run */
        _bound: false,

        /**
         * Refresh all containers on the page.
         * Needed on the first build: the rows come ready-made from the server, without
         * refresh the first row would show an up button and the last a
         * down button that leads nowhere.
         */
        refreshAll($scope) {
            const $root = $scope || $(document);
            $root.find('.c9-smartrows-container')
                .addBack('.c9-smartrows-container')
                .each((i, el) => this.refresh($(el)));
        },

        // --- Public API ---

        /**
         * Update the visibility of .c9-smartrows-up and .c9-smartrows-down in a .c9-smartrows-container element
         * (first .c9-smartrows-row can only be moved down, last only up,
         * the rest in both directions, with only one entry in no direction)
         */
        refresh($container) {
            const $rows = $container.find('div.c9-smartrows-row');
            const rowCount = $rows.length;

            // First hide all buttons (sets the state for "only 1 entry" or base reset)
            $rows.find('.c9-smartrows-up, .c9-smartrows-down').css('visibility', 'hidden');

            if (rowCount > 1) {
                // If more than one row exists, we first show all buttons ...
                $rows.find('.c9-smartrows-up, .c9-smartrows-down').css('visibility', 'visible');

                // ... and hide the illogical actions for the first and last row
                $rows.first().find('.c9-smartrows-up').css('visibility', 'hidden');
                $rows.last().find('.c9-smartrows-down').css('visibility', 'hidden');
            }
        },
        
        // --- Internal ---

        _bindButtons() {
            const self = this;

            $(document)
                // --- MOVE UP ---
                .on('click', '.c9-smartrows-up', function (e) {
                    e.preventDefault();
                    const $btn = $(this);
                    const $row = $btn.closest('div.c9-smartrows-row');
                    const $prevRow = $row.prev('div.c9-smartrows-row');

                    if ($prevRow.length) {
                        $row.insertBefore($prevRow); // move row up
                        const $container = $row.closest('div.c9-smartrows-container');
                        self.refresh($container); // update visibility
                    }
                })

                // --- MOVE DOWN ---
                .on('click', '.c9-smartrows-down', function (e) {
                    e.preventDefault();
                    const $btn = $(this);
                    const $row = $btn.closest('div.c9-smartrows-row');
                    const $nextRow = $row.next('div.c9-smartrows-row');

                    if ($nextRow.length) {
                        $row.insertAfter($nextRow); // move row down
                        const $container = $row.closest('div.c9-smartrows-container');
                        self.refresh($container); // update visibility
                    }
                })

                // --- REMOVE ---
                .on('click', '.c9-smartrows-remove', function (e) {
                    e.preventDefault();
                    const $btn = $(this);
                    const $row = $btn.closest('div.c9-smartrows-row');
                    const $container = $row.closest('div.c9-smartrows-container');

                    if ($container.find('div.c9-smartrows-row').length > 1) {
                        $row.remove(); // delete row
                    }
                    else {
                        // The last row stays and is only cleared:
                        // "Append" clones an existing row - without it there would
                        // be no template left and the container could not be
                        // filled again.
                        self._clearRow($row);
                    }
                    self.refresh($container); // update buttons of the remaining rows
                })

                // --- ADD ---
                .on('click', '.c9-smartrows-add', function (e) {
                    e.preventDefault();
                    const $btn = $(this);
                    const $container = $btn.closest('div.c9-smartrows-container');
                    const $lastRow = $container.find('div.c9-smartrows-row').last();

                    if ($lastRow.length) {
                        // Clone the last row incl. event handlers (false)
                        // and clear the form fields of the copy
                        const $newRow = $lastRow.clone(false);
                        self._clearRow($newRow);

                        // Append the new row after the last one
                        $lastRow.after($newRow);

                        // Update visibility
                        self.refresh($container);
                    }
                });
        },

        /**
         * Clear the form fields of a row - common core of "Append"
         * (the cloned row) and "Remove" (the last remaining row).
         */
        _clearRow($row) {
            $row.find('input, select, textarea').each(function () {
                const $el = $(this);
                const type = this.type || this.tagName.toLowerCase();

                // Uncheck checkboxes and radio buttons
                if (type === 'checkbox' || type === 'radio') {
                    $el.prop('checked', false);
                }
                // Do not clear buttons or submit inputs, only input fields
                else if (type !== 'button' && type !== 'submit' && type !== 'reset') {
                    $el.val('');
                }
            });
        }
    };

    // Initialize on document ready, in case the module is loaded directly
    $(function() {
        cApp.smartrows.init();
    });

}(window.cApp, jQuery));