/*
 * cApp.polling – Event-based task scheduler
 */
(function (cApp) {
    'use strict';

    cApp.polling = {

        timerId: null,

        // Last execution times in seconds (timestamp)
        _lastBgprocCheck: 0,
        _lastMailCheck: 0,

        // Status flags for running processes
        _isCheckingBgproc: false,
        _isCheckingMails: false,

        // Monitoring of background processes - only as long as needed.
        // watchBgproc() switches it on (a background process was started:
        // BackgroundProcess::create() sends the call with the response), the
        // response of bgproc_list.php reports back via bgprocState() whether anything
        // is still running. Because follow-up processes (multi-stage thumbnails) are only
        // started by the cron trigger, "all finished" only applies once nothing has run
        // for BGPROC_SETTLE seconds - then ONE message; after BGPROC_GRACE
        // seconds the monitoring ends.
        _bgprocWatch: false,
        _bgprocIdleSince: 0,
        _bgprocSeenRunning: false,
        BGPROC_SETTLE: 6,
        BGPROC_GRACE: 15,

        init() {
            if (this.timerId) return;
            // Check once at startup: running processes from an earlier
            // session are then monitored further (bgprocState() switches it on)
            this._bgprocWatch = true;
            this._bgprocIdleSince = Math.floor(Date.now() / 1000);
            // 1-second heartbeat
            this.timerId = window.setInterval(() => this._tick(), 1000);
        },

        /**
         * Switch monitoring on - after a background process has been started
         * (called from the response of the request that started it)
         */
        watchBgproc() {
            this._bgprocWatch = true;
            this._bgprocIdleSince = 0;
            this._lastBgprocCheck = 0; // next tick checks immediately
        },

        /**
         * Feedback from bgproc_list.php: is a process of the admin still running?
         * @param {boolean} running
         * @param {number}  failed   Number of failed processes in the list
         */
        bgprocState(running, failed = 0) {
            const now = Math.floor(Date.now() / 1000);
            if (running) {
                this._bgprocSeenRunning = true;
                this._bgprocIdleSince = 0;
                return;
            }
            if (!this._bgprocIdleSince) {
                this._bgprocIdleSince = now;
                return;
            }
            const idle = now - this._bgprocIdleSince;
            if (this._bgprocSeenRunning && idle >= this.BGPROC_SETTLE) {
                // All finished (including the follow-up processes) - one message
                this._bgprocSeenRunning = false;
                if (failed > 0) {
                    cApp.system.balloon('danger', cConfig.bgproc_finished_errors);
                }
                else {
                    cApp.system.balloon('success', cConfig.bgproc_finished);
                }
            }
            if (idle >= this.BGPROC_GRACE) {
                this._bgprocWatch = false;
            }
        },

        /**
         * Central Heartbeat Loop
         */
        _tick() {
            const now = Math.floor(Date.now() / 1000);

            // Every 2 seconds - only as long as background processes are to be monitored
            // (or the list is currently open)
            if ((this._bgprocWatch || cApp.ui.bgprocVisible()) && now - this._lastBgprocCheck >= 2) {
                if (!this._isCheckingBgproc) {
                    this._isCheckingBgproc = true;
                    this._lastBgprocCheck = now;

                    this.refreshBgproc()
                        .finally(() => {
                            this._isCheckingBgproc = false;
                        });
                }
            }

            // Every 30 seconds: badge of the internal messages (cApp.mail)
            if (now - this._lastMailCheck >= 30) {
                if (!this._isCheckingMails && cApp.mail) {
                    this._isCheckingMails = true;
                    this._lastMailCheck = now;

                    cApp.mail.refreshBadge()
                        .finally(() => {
                            this._isCheckingMails = false;
                        });
                }
            }
        },

        stop() {
            if (this.timerId) {
                window.clearInterval(this.timerId);
                this.timerId = null;
            }
        },

        // --------------------
        // Processes

        /**
         * Update the layer with background processes
         * @param {number} timeoutMs 
         * @returns {Promise<boolean>}
         */
        refreshBgproc(timeoutMs = 5000) {
            // Logged out (login dialog open): do not request - like
            // cApp.mail.refreshBadge(); the response ##accessdenied## would only
            // call cApp.layers.login() again
            if (cApp.layers && cApp.layers.loginOpen && cApp.layers.loginOpen()) {
                return Promise.resolve(false);
            }
            const details = cApp.ui.bgprocVisible() ? '1' : '0';
            const $container = $('#c9-bgproc-container');

            return new Promise((resolve) => {
                $container.cLoadContent({
                    url: 'Start/bgproc_list.php?details=' + encodeURIComponent(details),
                    spinner: false,
                    // Polling: does not extend the session (cApp.mail.refreshBadge())
                    background: true,
                    timeout: timeoutMs,
                    callback: () => {
                        resolve(true);
                    }
                });                
            });
        }
    };

}(window.cApp));