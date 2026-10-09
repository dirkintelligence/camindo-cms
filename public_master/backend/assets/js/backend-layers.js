/*
 * cApp.layers – Layer-Verwaltung & Hash-Routing
 *
 * Layer sind übereinanderliegende Inhalts-Ebenen in <article>.
 * Die aktuellen Inhalte werden im URL-Hash abgebildet (Pfade mit ~ getrennt).
 * Methoden:
 *     add, close, replace, clear, removeReferer, refresh, invalidate
 *
 * Layer, die aus der Oberfläche heraus geöffnet wurden (add() ohne skipHashUpdate,
 * addPost(), replace(), replacePost()), tragen data-intent="1"; Layer aus dem Hash
 * (Lesezeichen, F5, Zurück-Taste, Link von außen) nicht. Daran entscheidet
 * openPost(), ob eine Eingabemaske mit Check-out sofort oder erst auf Knopfdruck
 * geladen wird (17-csrf.md §2.8, RecordBackendEdit::_editOpenStub()).
 *
 * Nach einer Änderung, die den Layer darunter betrifft (Speichern, Löschen, Import,
 * Stapelverarbeitung …), ist das Muster immer dasselbe:
 *
 *     cApp.layers.invalidate();   // Referer als veraltet markieren
 *     cApp.layers.close();        // bzw. replace(): der Referer lädt neu, sobald
 *                                 // er wieder oben liegt
 *
 */
(function (cApp) {
    'use strict';

    // ---------------------------------------------------------------------------
    // Hilfsfunktionen (privat, nicht am Objekt)
    // ---------------------------------------------------------------------------

    /**
     * Extrahiert alle Pfade aus dem URL-Hash als Array.
     * @param  {string} hash  z. B. "#pfad/a~pfad/b"
     * @returns {string[]}
     */
    function hashToPaths(hash) {
        hash = hash.replace(/^[#/]+/, '');
        if (!hash) return [];
        return hash.split('~').filter(Boolean);
    }

    /**
     * Schreibt den Hash in die Browser-History.
     * @param {string}  hash
     * @param {boolean} replace  true → replaceState statt pushState
     */
    function hashWrite(hash, replace) {
        hash = '#' + hash.replace(/^[#/]+/, '');
        if (replace) {
            history.replaceState(null, null, hash);
        } else {
            history.pushState(null, null, hash);
        }
    }

    /**
     * Entfernt Query-Parameter aus einem Pfad (für Anzeige in Breadcrumb).
     * @param  {string} path
     * @returns {string}
     */
    function pathWithoutParameters(path) {
        if (!path) return '';
        return path.replace(/\?[^/~]*/g, '');
    }


    // ---------------------------------------------------------------------------
    // cApp.layers
    // ---------------------------------------------------------------------------
    cApp.layers = {

        // --- Initialisierung ---

        init() {
            window.addEventListener('popstate', () => {
                // Browser hat Hash bereits geändert → skipHashUpdate = true
                this.change(true);
            });
            this.change(true);
        },


        // --- Hash-Synchronisation ---

        /**
         * Gleicht die vorhandenen Layer mit den Pfaden im Hash ab.
         * @param {boolean} skipHashUpdate  Verhindert erneutes Schreiben des Hash
         */
        change(skipHashUpdate) {
            skipHashUpdate = skipHashUpdate || false;

            const allPaths    = hashToPaths(location.hash);
            const prevPaths   = allPaths.slice(0, -1);
            const lastPath    = allPaths.at(-1);
            const pathCount   = allPaths.length;
            const layerCount  = $('article .c9-layer').length;
            const maxCount    = Math.max(pathCount, layerCount);

            if (!pathCount) {
                $('article').empty();
                this.clear('', true, skipHashUpdate);
                return;
            }

            // Wie viele Layer passen ab dem Anfang zu den Hash-Pfaden?
            let matchCount = 0;
            $('article .c9-layer').each(function (i) {
                if ($(this).attr('data-url') !== allPaths[i]) return false; // break
                matchCount++;
            });

            // Bei zu vielen Abweichungen: komplett neu aufbauen
            if (matchCount < maxCount - 1) {
                $('article').empty();
                this.loadSilent('');          // HOME-Layer zuerst anlegen
                prevPaths.forEach(p => this.loadSilent(p));
                this.add(lastPath, false, skipHashUpdate);
                return;
            }

            if (pathCount === layerCount) {
                if (matchCount === pathCount) return; // Nichts zu tun
                $('article .c9-layer:last').cLoadContent(lastPath);
            } else if (layerCount === pathCount - 1) {
                this.add(lastPath, false, skipHashUpdate);
            } else {
                this.close(false, skipHashUpdate);
            }
        },


        // --- Layer-Operationen ---

        /**
         * Lädt einen Pfad still (ohne Anzeige) – für den Aufbau vorheriger Layer.
         */
        loadSilent(path) {
            path = path.replace(/^\/+/, '');
            const $layer = $(`<div class="c9-layer" style="display:none" data-url="${path}"></div>`);
            $('article').append($layer);
            
            // Anpassung: Callback hinzufügen, damit die Navigation 
            // den Titel auslesen kann, sobald das HTML geladen ist.
            $layer.cLoadContent({
                url: path,
                callback: () => {
                    this._naviRebuild();
                }
            });
        },

        /**
         * Fügt einen neuen Layer hinzu und zeigt ihn an.
         * @param {string}  path
         * @param {boolean} replaceHash     History-Eintrag ersetzen statt hinzufügen
         * @param {boolean} skipHashUpdate  Hash nicht anfassen (init/popstate)
         * @param {boolean} slide           Layer von rechts hereinschieben und beim
         *                                  Schließen wieder hinaus (Datensatzliste
         *                                  eines Lookup-Feldes, _slideIn())
         */
        add(path, replaceHash, skipHashUpdate, slide) {
            replaceHash    = replaceHash    || false;
            skipHashUpdate = skipHashUpdate || false;

            const $lastLayer = $('.c9-layer:last');
            if ($lastLayer.length) {
                $lastLayer.attr('data-scroll', $('article').scrollTop());
            }

            path = path.replace(/^\/+/, '');
            const $newLayer = $(`<div class="c9-layer" style="display:none" data-url="${path}"></div>`);
            if (!skipHashUpdate) $newLayer.attr('data-intent', '1');
            if (slide) $newLayer.attr('data-slide', '1');
            $('article').append($newLayer);

            if (!skipHashUpdate) {
                const paths = hashToPaths(location.hash);
                if (path !== '') paths.push(path);
                hashWrite(paths.join('~'), replaceHash);
            }

            // Nav-Punkt vorab mit Pfadname anlegen und mit Layer verknüpfen
            this._naviRebuild();
            const $navItem = $('#c9-navi .c9-navitem-text:last');
            $newLayer.data('nav-item', $navItem);

            $newLayer.cLoadContent({
                url:      path,
                callback: () => {
                    if (!$.contains(document, $newLayer[0])) return;
                    if (!(slide && this._slideIn($newLayer))) {
                        $('article').scrollTop(0);
                        $('article .c9-layer').css('display','none').last().css('display', 'block');
                    }
                    this._naviRebuild();
                }
            });
        },

        /**
         * Neuen Layer mit einer POST-Anfrage öffnen (Formular mit data-layer="new").
         * Wie add(), nur mit Formulardaten; im Hash steht der Pfad ohne Parameter -
         * nach einem Neuladen zeigt der Layer also die Startmaske des Scripts.
         * @param {string}   path
         * @param {FormData} params
         */
        addPost(path, params) {
            const $lastLayer = $('.c9-layer:last');
            if ($lastLayer.length) {
                $lastLayer.attr('data-scroll', $('article').scrollTop());
            }
            path = path.replace(/^\/+/, '');
            const $newLayer = $(`<div class="c9-layer" style="display:none" data-url="${path}" data-intent="1"></div>`);
            $('article').append($newLayer);

            const paths = hashToPaths(location.hash);
            if (path !== '') paths.push(path);
            hashWrite(paths.join('~'), false);

            this._naviRebuild();
            const $navItem = $('#c9-navi .c9-navitem-text:last');
            $newLayer.data('nav-item', $navItem);

            $newLayer.cLoadContent({
                url:      path,
                params:   params,
                method:   'POST',
                callback: () => {
                    if (!$.contains(document, $newLayer[0])) return;
                    $('article').scrollTop(0);
                    $('article .c9-layer').css('display','none').last().css('display', 'block');
                    this._naviRebuild();
                }
            });
        },

        /**
         * Entfernt den obersten Layer.
         * @param {boolean} replaceHash
         * @param {boolean} skipHashUpdate
         */
        close(replaceHash, skipHashUpdate) {
            replaceHash    = replaceHash    || false;
            skipHashUpdate = skipHashUpdate || false;

            this._slideFinish();
            const paths = hashToPaths(location.hash);
            const $last = $('article .c9-layer:last');
            if (cApp.extensions && cApp.extensions.destroyAll) cApp.extensions.destroyAll($last);

            // Hereingeschobener Layer (add() mit slide): er verlässt den Stapel sofort -
            // Aufrufe direkt nach close() sehen schon den Layer darunter -, bleibt
            // aber für die Animation als Ghost ohne Klasse c9-layer stehen
            const slide = $last.attr('data-slide') === '1'
                && $('article .c9-layer').length > 1 && this._slideOk();
            const scrollFrom = $('article').scrollTop();
            if (slide) {
                $last.removeClass('c9-layer').addClass('c9-layer-ghost')
                    .attr({ 'inert': '', 'aria-hidden': 'true' })
                    .css({ position: 'absolute', top: 0, left: 0, right: 0 });
            } else {
                $last.remove();
            }

            if ($('article .c9-layer').length > 0) {
                this._showLast();
                if (slide) this._slideOut($last, scrollFrom);
                if (!skipHashUpdate) {
                    paths.pop();
                    hashWrite(paths.join('~'), replaceHash);
                }
                this._naviRebuild();
            } else {
                if (!skipHashUpdate) hashWrite('', true);
                this.clear('', replaceHash, skipHashUpdate);
            }
        },

        /**
         * Entfernt den zweitobersten Layer samt seinem Pfad im Hash - der oberste
         * bleibt. Für Schritte, deren Vorgänger-Maske hinfällig geworden ist (der
         * Import: seine Datei ist nach dem Ausführen weg); "Schließen" des obersten
         * Layers landet dann direkt auf der Ebene darunter.
         */
        removeReferer() {
            const allLayers = $('article .c9-layer');
            if (allLayers.length < 2) return;
            allLayers.eq(-2).remove();
            const paths = hashToPaths(location.hash);
            if (paths.length >= 2) {
                paths.splice(-2, 1);
                hashWrite(paths.join('~'), true);
            }
            this._naviRebuild();
        },

        /**
         * Bleibt im aktuellen Layer, lädt aber einen anderen Pfad.
         * @param {string}  path
         * @param {boolean} replaceHash
         * @param {boolean} scrollTop
         */
        replace(path, replaceHash = false, scrollTop = true) {
            path = (path || '').replace(/^\/+/, '');

            const $last = $('article .c9-layer:last');
            $last.attr('data-url', path).attr('data-intent', '1');

            const paths = hashToPaths(location.hash);
            paths.pop();
            if (path !== '') paths.push(path);
            hashWrite(paths.join('~'), replaceHash);

            function callbackFunction() {
                cApp.layers._naviRebuild();
                if (scrollTop) {
                    $('article').scrollTop(0);
                }
            }
            
            $last.cLoadContent({
                url:      path,
                callback: callbackFunction
            });
        },

        /**
         * Wie replace(), aber per POST - für Aktionen mit Seiteneffekt
         * (17-csrf.md §2.7: verländernde Aktionen dürfen nicht per GET laufen,
         * sonst hilft weder `SameSite=Lax` noch das CSRF-Token).
         *
         * Im Hash steht der Pfad wie bei replace(), also MIT Parametern. Nach einem
         * Neuladen der Seite würde daraus ein GET - der Server lehnt die Aktion dann
         * ab (Backend::requirePost()) und zeigt die Meldung. Das ist gewollt: eine
         * schreibende Aktion soll sich nicht durch F5 wiederholen.
         *
         * @param {string}   path
         * @param {FormData} params  optional
         */
        replacePost(path, params) {
            path = (path || '').replace(/^\/+/, '');

            const $last = $('article .c9-layer:last');
            $last.attr('data-url', path).attr('data-intent', '1');

            const paths = hashToPaths(location.hash);
            paths.pop();
            if (path !== '') paths.push(path);
            hashWrite(paths.join('~'), false);

            $last.cLoadContent({
                url:      path,
                params:   params || new FormData(),
                method:   'POST',
                callback: () => {
                    cApp.layers._naviRebuild();
                    $('article').scrollTop(0);
                }
            });
        },

        /**
         * Platzhalter einer Seite mit Seiteneffekt beim Öffnen (Check-out der
         * Eingabemaske): den Layer, in dem er steht, per POST neu laden - sofort, wenn
         * der Layer aus der Oberfläche geöffnet wurde (data-intent), sonst erst mit
         * force (Knopf im Platzhalter). Siehe Kopfkommentar.
         *
         * @param {string}  elementId  Id des Platzhalters
         * @param {boolean} force      Klick auf den Knopf
         */
        openPost(elementId, force) {
            const el = document.getElementById(elementId);
            if (!el) return;
            const $layer = $(el).closest('.c9-layer');
            if (!$layer.length) return;
            if (!force && $layer.attr('data-intent') !== '1') {
                el.classList.remove('d-none');
                return;
            }
            $layer.attr('data-intent', '1');
            $layer.cLoadContent({
                url:      $layer.attr('data-url') || '',
                params:   new FormData(),
                method:   'POST',
                callback: () => {
                    cApp.layers._naviRebuild();
                }
            });
        },

        /**
         * Ermittelt aus data-url des obersten Layers den Query-String (ohne ?)
         * und ohne function Parameter
         * @returns {string} Der Query-String oder ein leerer String
         */
        queryString() {
            const cPath = $('article .c9-layer:last').attr('data-url') || ''; 
            const index = cPath.indexOf('?');
            
            if (index === -1) {
                return '';
            }
            
            const params = new URLSearchParams(cPath.substring(index));
            params.delete('function');
            
            return params.toString();
        },

        /**
         * Ermittelt aus data-url des obersten Layers den Pfad ohne queryString
         * Außnahmen sind die Parameter area_id, src_field, src_id
         * @returns {string} Der Pfad ohne queryString
         */
        base() {
            const cPath = $('article .c9-layer:last').attr('data-url') || ''; 
            if (!cPath) return '';

            const index = cPath.indexOf('?');
            const basePath = index !== -1 ? cPath.substring(0, index) : cPath;

            try {
                // Nutze eine Basis-URL, falls cPath ein relativer Pfad ist
                const url = new URL(cPath, window.location.origin);
                
                const newParams = new URLSearchParams();
                const paramsToKeep = ['area_id', 'src_field', 'src_id'];
                
                // Prüfe auf die Ausnahmeparameter und füge sie hinzu, falls vorhanden
                paramsToKeep.forEach(param => {
                    const value = url.searchParams.get(param);
                    if (value !== null) {
                        newParams.append(param, value);
                    }
                });
                
                const newQueryString = newParams.toString();
                
                // Wenn Parameter vorhanden sind, trenne mit &, sonst starte mit ?
                if (newQueryString) {
                    return `${basePath}?${newQueryString}&`;
                }
                
                return `${basePath}?`;
            } catch (e) {
                return `${basePath}?`;
            }
        },

        /**
         * Zeigt der oberste Layer dieses Script (Pfad ohne Query-String)?
         *
         * Verglichen wird nur der Script-Anteil von data-url - dieselbe Seite mit
         * anderen Parametern (z. B. Messaging/message_list.php?tab=outbox) zählt
         * also mit. Layer darunter zählen nicht: sie sind verdeckt, und ob sie
         * offen bleiben, entscheidet der Benutzer.
         *
         * @param {string} path  Pfad, ggf. mit Query-String
         * @returns {boolean}
         */
        isOnTop(path) {
            const script = String(path || '').replace(/^\/+/, '').split('?')[0];
            if (!script) return false;
            const url = String($('article .c9-layer:last').attr('data-url') || '').split('?')[0];
            return url === script;
        },

        /**
         * Wie add(), für Seiten, die es nur einmal im Stapel geben soll (Kopfzeile:
         * Einstellungen, Nachrichten; Hauptmenü: Info, Handbücher):
         *  - zeigt der oberste Layer schon dieses Script, wird kein neuer angelegt
         *    (kein Stapel gleicher Layer durch wiederholte Klicks) - er wird nur
         *    an den Anfang gescrollt;
         *  - liegt es weiter unten, wird dieser Layer geschlossen (samt seinem Pfad
         *    im Hash) und die Seite oben neu geöffnet.
         *
         * @param {string} path
         * @returns {boolean}  true: Layer wurde angelegt
         */
        addOnce(path) {
            if (this.isOnTop(path)) {
                $('article').scrollTop(0);
                return false;
            }
            const script = String(path || '').replace(/^\/+/, '').split('?')[0];
            const paths = hashToPaths(location.hash);
            let removed = false;
            $('article .c9-layer').slice(0, -1).each(function () {
                const url = String($(this).attr('data-url') || '');
                if (url.split('?')[0] !== script) return;
                const xhr = $(this).data('xhr');
                if (xhr) xhr.abort();
                if (cApp.extensions && cApp.extensions.destroyAll) cApp.extensions.destroyAll($(this));
                const index = paths.lastIndexOf(url);
                if (index > -1) paths.splice(index, 1);
                $(this).remove();
                removed = true;
            });
            if (removed) hashWrite(paths.join('~'), true);
            this.add(path);
            return true;
        },

        /**
         * Entfernt alle Layer und legt einen neuen an.
         * @param {string}  path
         * @param {boolean} replaceHash
         * @param {boolean} skipHashUpdate
         */
        clear(path, replaceHash, skipHashUpdate) {
            path           = path           || '';
            replaceHash    = replaceHash    || false;
            skipHashUpdate = skipHashUpdate || false;

            $('article .c9-layer').each(function () {
                const xhr = $(this).data('xhr');
                if (xhr) xhr.abort();
            });
            $('article').empty();
            this.add(path, replaceHash, skipHashUpdate);
        },

        /**
         * Markiert den Layer unter dem obersten (den Referer) als veraltet: er wird
         * neu geladen, sobald er wieder oben liegt (_showLast()) - gleich, ob der
         * oberste Layer über einen Knopf, die Breadcrumb-Navi oder "Zurück" des
         * Browsers geschlossen bzw. mit replace() ersetzt wird. Ersetzt das frühere
         * refreshReferer() und das Paar close() + refresh().
         *
         * Standardmäßig nur der Referer: tiefere Layer können Eingabemasken mit
         * Arbeitskopie sein, die ein Neuladen nicht verträgt. levels > 1 markiert
         * zusätzlich die Layer darunter - nur für Masken, unter denen sicher keine
         * Eingabemaske liegt (z. B. Bereich bearbeiten über Info-Seite und Liste).
         *
         * @param {function} [callback]  nach dem Neuladen des Referers ausführen
         *                               (z. B. ein Lookup-Feld der Maske darunter füllen)
         * @param {number}   [levels=1]  Anzahl der Layer unter dem obersten
         */
        invalidate(callback, levels = 1) {
            const $layers = $('article .c9-layer');
            for (let i = 2; i <= levels + 1 && i <= $layers.length; i++) {
                $layers.eq(-i).attr('data-stale', '1');
            }
            if ($layers.length >= 2 && typeof callback === 'function') {
                $layers.eq(-2).data('stale-callback', callback);
            }
        },

        /**
         * Lädt den obersten Layer neu.
         */
        refresh(callback) {
            const $last = $('article .c9-layer:last');
            $last.css('display', 'block').removeAttr('data-stale').removeData('stale-callback');
            const path  = $last.attr('data-url') || '';
            $last.cLoadContent({
                url:path,
                callback:callback
            });
        },


        /**
         * Login abschließen: Lädt Inhalte leer gebliebener Layer nach.
         *
         * Steht die Kopfzeile (serverseitig in index.php gerendert) in einer anderen
         * Sprache als der beim Anmelden gewählten, wird das Backend komplett neu
         * geladen - die Layer entstehen aus dem Hash ohnehin neu.
         *
         * @param {string} backendCode  gewählte Backend-Sprache ('' = unbekannt)
         */
        loginComplete(backendCode, locale) {
            // Kopfzeile in anderer Sprache oder cConfig.locale (Datumsformate der
            // Extensions) veraltet: die Seite wurde ohne Anmeldung geladen
            // (<html lang> in BCP-47-Schreibweise: de_DE -> de-DE)
            if ((backendCode && document.documentElement.lang !== backendCode.replace('_', '-'))
                || (locale && window.cConfig.locale !== locale)) {
                window.location.reload();
                return;
            }
            $('article .c9-layer').each(function(index) {
                const $this = $(this);
                if ($this.text().length < 40) {
                    const path  = $this.attr('data-url') || '';
                    $this.cLoadContent({
                        url: path,
                        callback: () => {
                            cApp.layers._naviRebuild();
                        }
                    });
                }
                // Login-Dialog schließen
                $('#login-modal').modal('hide');
            });
            $('article').scrollTop(0);
            $('article .c9-layer').css('display','none').last().css('display', 'block');
            // Hintergrundprozesse aus einer früheren Sitzung? Einmal nachsehen,
            // die Überwachung endet von selbst, wenn nichts läuft (cApp.polling)
            if (cApp.polling && cApp.polling.watchBgproc) cApp.polling.watchBgproc();
            // Abzeichen der internen Nachrichten: index.php wurde ohne Anmeldung
            // geladen und zeigt 0 (cApp.mail)
            if (cApp.mail && cApp.mail.refreshBadge) cApp.mail.refreshBadge();
        },

        /**
         * Zeigt den Login-Dialog.
         *
         * Jede Ajax-Antwort '##accessdenied##' landet hier - beim Start ohne
         * Sitzung sind das mehrere auf einmal (jeder Layer aus dem Hash) und
         * danach laufend weitere (Polling). Ist der Dialog schon offen, wird das
         * Formular deshalb NICHT erneut geladen: das würde die laufende Eingabe
         * verwerfen und den Fokus wieder auf das Namensfeld setzen.
         */
        login() {
            const $modal  = $('#login-modal');
            const $dialog = $('#login-dialog');

            // Bereits offen und Formular da bzw. gerade unterwegs: nichts tun
            if (this._loginOpen && ($dialog.data('xhr') || $dialog.find('form').length)) return;
            this._loginOpen = true;

            // Bootstrap setzt beim Ende der Fade-Transition (shown.bs.modal)
            // per Focus-Trap den Fokus zwingend auf das Modal-Element selbst.
            // Läuft unser Ajax-Callback vor diesem Event durch (z. B. bei
            // warmem Browser/schneller Verbindung), wird sein Fokus dadurch
            // wieder überschrieben. Deshalb zusätzlich hier fokussieren,
            // da shown.bs.modal garantiert nach dem Focus-Trap feuert.
            $modal
                .off('shown.bs.modal.login hidden.bs.modal.login')
                .on('shown.bs.modal.login', function () {
                    $('#login-name').trigger('focus');
                })
                .on('hidden.bs.modal.login', () => {
                    this._loginOpen = false;
                })
                .modal('show');

            // Formular in der zuletzt gewählten bzw. der Browsersprache laden
            const lang = (cApp.ui && cApp.ui.loginPrefLang) ? cApp.ui.loginPrefLang() : '';
            $dialog.empty().cLoadContent({
                url: 'Start/login.php' + (lang ? '?lang=' + encodeURIComponent(lang) : '')
            });
        },

        /**
         * Ist der Login-Dialog offen (abgemeldet)? Polling-Anfragen unterbleiben
         * dann - ihre Antwort '##accessdenied##' hätte sonst nichts zu tun.
         * @returns {boolean}
         */
        loginOpen() {
            return !!this._loginOpen || $('#login-modal').hasClass('show');
        },

        /**
         * Zeigt die Rückfrage vor dem Abmelden.
         *
         * Benutzt denselben Dialog wie die Anmeldung (#login-modal): das
         * Script Start/logout.php liefert die Teile des Modals. Bestätigt der
         * Benutzer, schickt sich das Formular an dasselbe Script zurück und die
         * Antwort lädt das Fenster neu; bricht er ab, schließt der Knopf das
         * Modal (data-bs-dismiss) und die Oberfläche bleibt, wie sie war.
         */
        logout() {
            const $modal  = $('#login-modal');
            const $dialog = $('#login-dialog');

            $modal
                .off('hidden.bs.modal.logout')
                .one('hidden.bs.modal.logout', () => {
                    // Der Dialog gehört danach wieder dem Anmeldeformular
                    $dialog.empty();
                })
                .modal('show');

            $dialog.empty().cLoadContent({ url: 'Start/logout.php' });
        },

        /**
         * Schließt alle Layer sicher (mit Prüfung auf ungespeicherte Daten) 
         * und springt zur Startseite zurück.
         */
        home() {
            this._closeLayersTo(0, () => {
                // Alle Layer wurden sicher geschlossen. Hash komplett entfernen.
                history.pushState(null, null, window.location.pathname + window.location.search);
                
                // Navigation aktualisieren
                this._naviRebuild();

                // Basis-Layer (Startseite) neu aufbauen
                this.loadSilent('');
                $('article').scrollTop(0);
                $('article .c9-layer:last').css('display', 'block');
            });
        },


        // --- Breadcrumb: sukzessives Schließen mit dirty-check ---

        /**
         * Schließt Layer sukzessive vom obersten bis zur Ziel-Tiefe.
         * Prüft bei jedem Schritt, ob ungespeicherte Änderungen vorhanden sind.
         * Bricht ab, wenn der Nutzer den Verwerfen-Dialog abbricht.
         *
         * @param {number} targetDepth  Anzahl der Layer, die danach noch existieren sollen
         * @param {function} onDone    Wird aufgerufen, wenn das Ziel erreicht ist
         */
        _closeLayersTo(targetDepth, onDone) {
            const $layers    = $('article .c9-layer');
            const currentDepth = $layers.length;

            if (currentDepth <= targetDepth) {
                // Ziel erreicht
                if (typeof onDone === 'function') onDone();
                return;
            }

            // Obersten Layer auf ungespeicherte Änderungen prüfen
            const $topLayer = $layers.last();
            cApp.forms.checkLayerAndExecute($topLayer, () => {
                // Nutzer hat bestätigt (oder kein dirty state) → Layer entfernen
                $topLayer.remove();
                // Nächste Ebene rekursiv prüfen
                this._closeLayersTo(targetDepth, onDone);
            });
        },


        // --- Animation: Layer schieben (Datensatzliste eines Lookup-Feldes) ---

        /** Dauer der Schiebe-Animation in ms */
        _slideDuration: 180,

        /** Laufende Animation sofort beenden (Funktion) bzw. null */
        _slideDone: null,

        /**
         * Animation möglich und erwünscht? Nicht ohne Web Animations API und nicht
         * bei "Bewegung reduzieren" im Betriebssystem.
         * @returns {boolean}
         */
        _slideOk() {
            return typeof Element.prototype.animate === 'function'
                && !(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
        },

        /** Laufende Schiebe-Animation sofort zum Ende bringen */
        _slideFinish() {
            if (this._slideDone) this._slideDone();
        },

        /**
         * Beide Layer gleichzeitig verschieben; done() räumt auf - am Ende der
         * Animation oder vorzeitig über _slideFinish().
         * @param {Element[]} elements
         * @param {Array[]}   offsets   je Element [von, bis] in % der Breite
         * @param {function}  cleanup
         */
        _slide(elements, offsets, cleanup) {
            // in Sprachen von rechts nach links gespiegelt
            const dir = document.documentElement.dir === 'rtl' ? -1 : 1;
            const $article = $('article').addClass('c9-layers-sliding');
            const animations = elements.map((el, i) => el.animate([
                { transform: `translateX(${offsets[i][0] * dir}%)` },
                { transform: `translateX(${offsets[i][1] * dir}%)` }
            ], { duration: this._slideDuration, easing: 'ease-out' }));
            const done = () => {
                if (this._slideDone !== done) return;
                this._slideDone = null;
                animations.forEach(a => a.cancel());
                $article.removeClass('c9-layers-sliding');
                cleanup();
            };
            this._slideDone = done;
            animations.forEach(a => a.finished.then(done, () => {}));
        },

        /**
         * Öffnen: der neue Layer startet mit seiner linken Kante am rechten Rand,
         * beide Layer schieben nach links, bis der neue am linken Rand anliegt.
         * Der neue Layer liegt währenddessen absolut auf Höhe der Scrollposition.
         * @param {jQuery} $new
         * @returns {boolean}  false: nicht animiert (der Aufrufer schaltet sofort um)
         */
        _slideIn($new) {
            this._slideFinish();
            const $prev = $new.prevAll('.c9-layer').filter(function () {
                return this.style.display !== 'none';
            }).first();
            if (!$prev.length || !this._slideOk()) return false;
            const $article = $('article');
            $new.css({ display: 'block', position: 'absolute', top: $article.scrollTop() + 'px', left: 0, right: 0 });
            this._slide([$prev[0], $new[0]], [[0, -100], [100, 0]], () => {
                $new.css({ position: '', top: '', left: '', right: '' });
                $('article .c9-layer').css('display', 'none').last().css('display', 'block');
                $article.scrollTop(0);
            });
            return true;
        },

        /**
         * Schließen: der Ghost des geschlossenen Layers und der wieder sichtbare
         * Layer darunter schieben gemeinsam nach rechts, dann verschwindet der Ghost.
         * @param {jQuery} $ghost       geschlossener Layer (absolut positioniert)
         * @param {number} scrollFrom   Scrollposition, in der er zu sehen war
         */
        _slideOut($ghost, scrollFrom) {
            const $cur = $('article .c9-layer:last');
            // Ghost so versetzen, dass er an derselben Stelle sichtbar bleibt
            $ghost.css('top', ($('article').scrollTop() - scrollFrom) + 'px');
            this._slide([$cur[0], $ghost[0]], [[-100, 0], [0, 100]], () => {
                $ghost.remove();
            });
        },


        // --- Interne Hilfsfunktionen ---

        _showLast() {
            const $last = $('article .c9-layer:last');
            $last.css('display', 'block');
            const scrollY = parseInt($last.attr('data-scroll') || 0, 10);
            $('article').scrollTop(scrollY);
            // veraltet (invalidate()): neu laden, Scrollposition beibehalten
            if ($last.attr('data-stale')) {
                const callback = $last.data('stale-callback');
                $last.removeAttr('data-stale').removeData('stale-callback');
                $last.cLoadContent({
                    url:      $last.attr('data-url') || '',
                    callback: () => {
                        this._naviRebuild();
                        $('article').scrollTop(scrollY);
                        if (typeof callback === 'function') callback();
                    }
                });
            }
        },

        /**
         * Baut die Breadcrumb-Navi anhand des aktuellen Hash neu auf.
         */
        _naviRebuild() {
            const paths = hashToPaths(location.hash);

            const attr =  ' aria-label="' + window.cConfig.label_home + '"';
            const icon = window.cConfig.icon_home;
            if (!paths.length) {
                $('#c9-navi > nav').html('<span class="c9-navitem-text"' + attr + '>' + icon + '</span>');
                return;
            }

            let html = '<a role="button" tabindex="0" class="c9-navitem-link"' + attr + '>' + icon + '</a>'
                     + '<span class="c9-navitem-separator"></span>';

            paths.forEach((p, i) => {
                const separator = window.cConfig.path_separator;
                const name = pathWithoutParameters(p).split('/').pop();
                if (i < paths.length - 1) {
                    html += `<a role="button" tabindex="0" class="c9-navitem-link">${name}</a>`
                          + ' <span class="c9-navitem-separator">' + separator + '</span>';
                } else {
                    html += `<span class="c9-navitem-text">${name}</span>`;
                }
            });

            $('#c9-navi > nav').html(html);

            // Eigenen Zähler für die Pfade definieren
            let pathIndex = 0;
            
            $('article .c9-layer').each(function() {
                const url = $(this).attr('data-url') || '';
                
                // Den leeren Basis-Layer überspringen, da er im paths-Array nicht vorkommt
                if (url === '') return; 

                const $navSpan = $(this).find('.c9-navtext').first();
                if ($navSpan.length) {
                    $('#c9-navi .c9-navitem-link, #c9-navi .c9-navitem-text')
                        .eq(pathIndex + 1) // +1 wegen des Home-Icons ganz links
                        .text($navSpan.text());
                }
                pathIndex++;
            });
        }
    };


    // ---------------------------------------------------------------------------
    // Breadcrumb-Klick-Handler
    // Sukzessives Schließen mit dirty-check pro Layer
    // ---------------------------------------------------------------------------
    $(document).on('click', '#c9-navi a', function (e) {
        e.preventDefault();

        const clickedIndex = $(this).index('#c9-navi a'); // 0 = Home
        const targetDepth  = clickedIndex; // so viele Hash-Pfade sollen übrig bleiben

        // Prüfen ob ein leerer Basis-Layer ('') vorhanden ist
        const hasBaseLayer = $('article .c9-layer').first().attr('data-url') === '';
        const targetLayerCount = targetDepth + (hasBaseLayer ? 1 : 0);

        cApp.layers._closeLayersTo(targetLayerCount, () => {
            const paths    = hashToPaths(location.hash);
            const newPaths = paths.slice(0, targetDepth);
            hashWrite(newPaths.join('~'));
            cApp.layers._naviRebuild();

            if (targetDepth === 0) {
                // Home
                if ($('article .c9-layer').length === 0) {
                    cApp.layers.loadSilent('');
                }
                
                // (_showLast(): eine veraltete Startseite lädt neu)
                cApp.layers._showLast();
                $('article').scrollTop(0);
            } else {
                cApp.layers._showLast();
            }
        });
    });

}(window.cApp));
