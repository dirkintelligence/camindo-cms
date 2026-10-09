/*
 * cApp.polling – Event-basierter Task Scheduler
 */
(function (cApp) {
    'use strict';

    cApp.polling = {

        timerId: null,

        // Letzte Ausführungszeitpunkte in Sekunden (Timestamp)
        _lastBgprocCheck: 0,
        _lastMailCheck: 0,

        // Status-Flags für laufende Prozesse
        _isCheckingBgproc: false,
        _isCheckingMails: false,

        // Überwachung der Hintergrundprozesse - nur solange nötig.
        // watchBgproc() schaltet sie ein (ein Hintergrundprozess wurde gestartet:
        // BackgroundProcess::create() schickt den Aufruf mit der Antwort), die
        // Antwort von bgproc_list.php meldet über bgprocState() zurück, ob noch etwas
        // läuft. Weil Folgeprozesse (mehrstufige Thumbnails) erst vom Cron-Trigger
        // gestartet werden, gilt "alles abgeschlossen" erst, wenn BGPROC_SETTLE
        // Sekunden lang nichts mehr lief - dann EINE Meldung; nach BGPROC_GRACE
        // Sekunden endet die Überwachung.
        _bgprocWatch: false,
        _bgprocIdleSince: 0,
        _bgprocSeenRunning: false,
        BGPROC_SETTLE: 6,
        BGPROC_GRACE: 15,

        init() {
            if (this.timerId) return;
            // Beim Start einmal nachsehen: laufende Prozesse aus einer früheren
            // Sitzung werden dann weiter überwacht (bgprocState() schaltet ein)
            this._bgprocWatch = true;
            this._bgprocIdleSince = Math.floor(Date.now() / 1000);
            // 1-Sekunden-Heartbeat
            this.timerId = window.setInterval(() => this._tick(), 1000);
        },

        /**
         * Überwachung einschalten - nach dem Start eines Hintergrundprozesses
         * (aufgerufen aus der Antwort des Requests, der ihn gestartet hat)
         */
        watchBgproc() {
            this._bgprocWatch = true;
            this._bgprocIdleSince = 0;
            this._lastBgprocCheck = 0; // nächster Tick prüft sofort
        },

        /**
         * Rückmeldung von bgproc_list.php: läuft noch ein Prozess des Admins?
         * @param {boolean} running
         * @param {number}  failed   Anzahl fehlgeschlagener Prozesse in der Liste
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
                // Alles abgeschlossen (auch die Folgeprozesse) - eine Meldung
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

            // Alle 2 Sekunden - nur solange Hintergrundprozesse zu überwachen sind
            // (oder die Liste gerade offen ist)
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

            // Alle 30 Sekunden: Abzeichen der internen Nachrichten (cApp.mail)
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
         * Layer mit Hintergrundprozessen aktualisieren
         * @param {number} timeoutMs 
         * @returns {Promise<boolean>}
         */
        refreshBgproc(timeoutMs = 5000) {
            // Abgemeldet (Anmeldedialog offen): nicht anfragen - wie
            // cApp.mail.refreshBadge(); die Antwort ##accessdenied## würde nur
            // cApp.layers.login() erneut aufrufen
            if (cApp.layers && cApp.layers.loginOpen && cApp.layers.loginOpen()) {
                return Promise.resolve(false);
            }
            const details = cApp.ui.bgprocVisible() ? '1' : '0';
            const $container = $('#c9-bgproc-container');

            return new Promise((resolve) => {
                $container.cLoadContent({
                    url: 'Start/bgproc_list.php?details=' + encodeURIComponent(details),
                    spinner: false,
                    // Polling: verlängert die Session nicht (cApp.mail.refreshBadge())
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