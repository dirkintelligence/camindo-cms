/*
 * cApp.smartrows – Zeilenmanipulation in Formularen
 * Zeilen Verschieben, Anfügen, Löschen
 *
 * Aufbau im HTML:
 *     .c9-smartrows-container      umschließt alle Zeilen
 *       .c9-smartrows-row          eine Zeile
 *         .c9-smartrows-up         Zeile nach oben  (optional)
 *         .c9-smartrows-down       Zeile nach unten (optional)
 *         .c9-smartrows-remove     Zeile entfernen  (optional)
 *       .c9-smartrows-add          Zeile anfügen    (optional, außerhalb der Zeilen)
 *
 * Nicht jeder Container braucht alle vier Aktionen: der display_type 'sortable'
 * (Backend::formField) bringt nur Up/Down mit - dort steht die Auswahl fest und
 * frei ist nur ihre Reihenfolge.
 */
window.cApp = window.cApp || {}; // Stelle sicher, dass cApp existiert

(function (cApp, $) {
    'use strict';

    cApp.smartrows = {

        /**
         * Einmalig die Klick-Handler binden und alle Container auffrischen.
         *
         * Idempotent: init() wird zweimal gerufen - das Modul meldet sich am Ende
         * dieser Datei selbst an (falls es allein geladen wird) UND backend-init.js
         * ruft es in der Startreihenfolge auf. Ohne die Sperre hingen alle
         * delegierten Handler doppelt am document: ein Klick auf "Zeile anfügen"
         * erzeugte zwei Zeilen, ein Klick auf "Runter" schob die Zeile zwei
         * Positionen weiter.
         */
        init() {
            if (!this._bound) {
                this._bindButtons();
                this._bound = true;
            }
            this.refreshAll();
        },

        /** true, sobald _bindButtons() gelaufen ist */
        _bound: false,

        /**
         * Alle Container auf der Seite auffrischen.
         * Nötig beim ersten Aufbau: die Zeilen kommen fertig vom Server, ohne
         * Refresh zeigte die erste Zeile einen Hoch- und die letzte einen
         * Runter-Button, der ins Leere greift.
         */
        refreshAll($scope) {
            const $root = $scope || $(document);
            $root.find('.c9-smartrows-container')
                .addBack('.c9-smartrows-container')
                .each((i, el) => this.refresh($(el)));
        },

        // --- Öffentliche API ---

        /**
         * Sichtbarkeit von .c9-smartrows-up und .c9-smartrows-down in einem .c9-smartrows-container Element
         * aktualisieren (erste .c9-smartrows-row nur nach unten schiebbar, letzte nur nach oben,
         * restliche in beide Richtungen, bei nur einem Eintrag in keine Richtung)
         */
        refresh($container) {
            const $rows = $container.find('div.c9-smartrows-row');
            const rowCount = $rows.length;

            // Zunächst alle Buttons ausblenden (Setzt den Zustand für "nur 1 Eintrag" oder Basis-Reset)
            $rows.find('.c9-smartrows-up, .c9-smartrows-down').css('visibility', 'hidden');

            if (rowCount > 1) {
                // Wenn mehr als eine Zeile existiert, blenden wir erstmal alle Buttons ein ...
                $rows.find('.c9-smartrows-up, .c9-smartrows-down').css('visibility', 'visible');

                // ... und verstecken die unlogischen Aktionen für die erste und letzte Zeile
                $rows.first().find('.c9-smartrows-up').css('visibility', 'hidden');
                $rows.last().find('.c9-smartrows-down').css('visibility', 'hidden');
            }
        },
        
        // --- Intern ---

        _bindButtons() {
            const self = this;

            $(document)
                // --- NACH OBEN VERSCHIEBEN ---
                .on('click', '.c9-smartrows-up', function (e) {
                    e.preventDefault();
                    const $btn = $(this);
                    const $row = $btn.closest('div.c9-smartrows-row');
                    const $prevRow = $row.prev('div.c9-smartrows-row');

                    if ($prevRow.length) {
                        $row.insertBefore($prevRow); // Zeile nach oben schieben
                        const $container = $row.closest('div.c9-smartrows-container');
                        self.refresh($container); // Sichtbarkeiten aktualisieren
                    }
                })

                // --- NACH UNTEN VERSCHIEBEN ---
                .on('click', '.c9-smartrows-down', function (e) {
                    e.preventDefault();
                    const $btn = $(this);
                    const $row = $btn.closest('div.c9-smartrows-row');
                    const $nextRow = $row.next('div.c9-smartrows-row');

                    if ($nextRow.length) {
                        $row.insertAfter($nextRow); // Zeile nach unten schieben
                        const $container = $row.closest('div.c9-smartrows-container');
                        self.refresh($container); // Sichtbarkeiten aktualisieren
                    }
                })

                // --- ENTFERNEN ---
                .on('click', '.c9-smartrows-remove', function (e) {
                    e.preventDefault();
                    const $btn = $(this);
                    const $row = $btn.closest('div.c9-smartrows-row');
                    const $container = $row.closest('div.c9-smartrows-container');

                    if ($container.find('div.c9-smartrows-row').length > 1) {
                        $row.remove(); // Zeile löschen
                    }
                    else {
                        // Die letzte Zeile bleibt stehen und wird nur geleert:
                        // "Anfügen" klont eine vorhandene Zeile - ohne sie gäbe es
                        // keine Vorlage mehr und der Container ließe sich nicht
                        // wieder füllen.
                        self._clearRow($row);
                    }
                    self.refresh($container); // Buttons der verbleibenden Zeilen aktualisieren
                })

                // --- HINZUFÜGEN ---
                .on('click', '.c9-smartrows-add', function (e) {
                    e.preventDefault();
                    const $btn = $(this);
                    const $container = $btn.closest('div.c9-smartrows-container');
                    const $lastRow = $container.find('div.c9-smartrows-row').last();

                    if ($lastRow.length) {
                        // Letzte Zeile inkl. Event-Handler (false) klonen
                        // und die Formularfelder der Kopie leeren
                        const $newRow = $lastRow.clone(false);
                        self._clearRow($newRow);

                        // Neue Zeile hinter die letzte anhängen
                        $lastRow.after($newRow);

                        // Sichtbarkeiten aktualisieren
                        self.refresh($container);
                    }
                });
        },

        /**
         * Formularfelder einer Zeile leeren - gemeinsamer Kern von "Anfügen"
         * (die geklonte Zeile) und "Entfernen" (die letzte verbliebene Zeile).
         */
        _clearRow($row) {
            $row.find('input, select, textarea').each(function () {
                const $el = $(this);
                const type = this.type || this.tagName.toLowerCase();

                // Checkboxen und Radio-Buttons unchecken
                if (type === 'checkbox' || type === 'radio') {
                    $el.prop('checked', false);
                }
                // Keine Buttons oder Submit-Inputs leeren, nur Eingabefelder
                else if (type !== 'button' && type !== 'submit' && type !== 'reset') {
                    $el.val('');
                }
            });
        }
    };

    // Im Document-Ready initialisieren, falls das Modul direkt geladen wird
    $(function() {
        cApp.smartrows.init();
    });

}(window.cApp, jQuery));