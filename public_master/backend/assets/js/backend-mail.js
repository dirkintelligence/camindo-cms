/*
 * cApp.mail – Envelope icon in the backend header (module Messaging)
 *
 * A click opens the inbox as a layer (Messaging/message_list.php). The
 * badge on the icon shows the unread messages of the logged-in
 * admin - set server-side when the backend loads (index.php), by every
 * response of the module (MessagingBackend::_badgeScript()) and every 30 seconds
 * by the timer (cApp.polling -> refreshBadge() -> ajax_unread.php).
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
         * Open the inbox as a layer - only once: if a layer of the
         * module (inbox or outbox) is already on top, the click is ignored (cApp.layers.addOnce())
         */
        open() {
            cApp.layers.addOnce('Messaging/message_list.php');
        },

        /**
         * Reload all open list layers of the module (inbox/outbox) - after
         * reading or marking a message whose layer lies above them, and after
         * saving/deleting. Other layers remain untouched.
         */
        refreshList() {
            $('article .c9-layer').each(function () {
                const url = String($(this).attr('data-url') || '');
                if (url.split('?')[0] !== 'Messaging/message_list.php') return;
                $(this).cLoadContent({ url: url, spinner: false });
            });
        },

        /**
         * Set the badge (the server already caps it at 99)
         * @param {number} count
         */
        setBadge(count) {
            const $badge = $('#c9-mail-btn .c9-badge');
            $badge.find('small').text(count);
            $badge.toggle(count > 0);
        },

        /**
         * Update the badge from the server (timer). The response is only the
         * JavaScript for setBadge(); the target container stays invisible.
         * @param {number} timeoutMs
         * @returns {Promise<boolean>}
         */
        refreshBadge(timeoutMs = 5000) {
            // Logged out (login dialog open): do not request - the response
            // ##accessdenied## would reload the login dialog and discard
            // the input just typed
            if (cApp.layers && cApp.layers.loginOpen && cApp.layers.loginOpen()) {
                return Promise.resolve(false);
            }
            return new Promise((resolve) => {
                $('#c9-mail-container').cLoadContent({
                    url: 'Messaging/ajax_unread.php',
                    spinner: false,
                    // Polling: not a sign of activity, does not keep the session open
                    background: true,
                    timeout: timeoutMs,
                    callback: () => resolve(true)
                });
            });
        }
    };

}(window.cApp));
