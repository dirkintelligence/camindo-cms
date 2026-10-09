/*
 * cApp.mail – Brief-Symbol im Backend-Kopf (Modul Messaging)
 *
 * Klick öffnet den Posteingang als Layer (Messaging/message_list.php). Das
 * Abzeichen auf dem Symbol zeigt die ungelesenen Nachrichten des angemeldeten
 * Admins - gesetzt serverseitig beim Laden des Backends (index.php), von jeder
 * Antwort des Moduls (MessagingBackend::_badgeScript()) und alle 30 Sekunden
 * vom Timer (cApp.polling -> refreshBadge() -> ajax_unread.php).
 */
(function (cApp) {
    'use strict';

    cApp.mail = {

        init() {
            $('#c9-mail-btn').on('click', (e) => {
                e.preventDefault();
                this.open();
            });
        },

        /**
         * Posteingang als Layer öffnen - nur einmal: liegt schon ein Layer des
         * Moduls (Ein- oder Ausgang) oben, wird der Klick ignoriert (cApp.layers.addOnce())
         */
        open() {
            cApp.layers.addOnce('Messaging/message_list.php');
        },

        /**
         * Alle offenen Listen-Layer des Moduls neu laden (Ein-/Ausgang) - nach dem
         * Lesen oder Markieren einer Nachricht, deren Layer darüber liegt, und nach
         * Speichern/Löschen. Andere Layer bleiben unberührt.
         */
        refreshList() {
            $('article .c9-layer').each(function () {
                const url = String($(this).attr('data-url') || '');
                if (url.split('?')[0] !== 'Messaging/message_list.php') return;
                $(this).cLoadContent({ url: url, spinner: false });
            });
        },

        /**
         * Abzeichen setzen (der Server deckelt bereits auf 99)
         * @param {number} count
         */
        setBadge(count) {
            const $badge = $('#c9-mail-btn .c9-badge');
            $badge.find('small').text(count);
            $badge.toggle(count > 0);
        },

        /**
         * Abzeichen vom Server aktualisieren (Timer). Die Antwort ist nur das
         * Javascript für setBadge(); der Zielcontainer bleibt unsichtbar.
         * @param {number} timeoutMs
         * @returns {Promise<boolean>}
         */
        refreshBadge(timeoutMs = 5000) {
            // Abgemeldet (Anmeldedialog offen): nicht anfragen - die Antwort
            // ##accessdenied## würde den Anmeldedialog neu laden und dabei die
            // gerade getippte Eingabe verwerfen
            if (cApp.layers && cApp.layers.loginOpen && cApp.layers.loginOpen()) {
                return Promise.resolve(false);
            }
            return new Promise((resolve) => {
                $('#c9-mail-container').cLoadContent({
                    url: 'Messaging/ajax_unread.php',
                    spinner: false,
                    // Polling: kein Zeichen von Aktivität, hält die Session nicht offen
                    background: true,
                    timeout: timeoutMs,
                    callback: () => resolve(true)
                });
            });
        }
    };

}(window.cApp));
