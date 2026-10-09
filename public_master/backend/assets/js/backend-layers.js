/*
 * cApp.layers – Layer management & hash routing
 *
 * Layers are stacked content levels in <article>.
 * The current contents are mapped in the URL hash (paths separated by ~).
 * Methods:
 *     add, close, replace, clear, removeReferer, refresh, invalidate
 *
 * Layers opened from the user interface (add() without skipHashUpdate,
 * addPost(), replace(), replacePost()) carry data-intent="1"; layers from the hash
 * (bookmark, F5, back button, link from outside) do not. Based on this,
 * openPost() decides whether an input mask with check-out is loaded immediately or
 * only at the push of a button (developer docs, chapter 14 (Security: web), RecordBackendEdit::_editOpenStub()).
 *
 * After a change that affects the layer below (save, delete, import,
 * batch processing …), the pattern is always the same:
 *
 *     cApp.layers.invalidate();   // mark the referer as outdated
 *     cApp.layers.close();        // or replace(): the referer reloads as soon as
 *                                 // it is on top again
 *
 */
(function (cApp) {
    'use strict';

    // ---------------------------------------------------------------------------
    // Helper functions (private, not on the object)
    // ---------------------------------------------------------------------------

    /**
     * Extracts all paths from the URL hash as an array.
     * @param  {string} hash  e.g. "#pfad/a~pfad/b"
     * @returns {string[]}
     */
    function hashToPaths(hash) {
        hash = hash.replace(/^[#/]+/, '');
        if (!hash) return [];
        return hash.split('~').filter(Boolean);
    }

    /**
     * Writes the hash to the browser history.
     * @param {string}  hash
     * @param {boolean} replace  true → replaceState instead of pushState
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
     * Removes query parameters from a path (for display in the breadcrumb).
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

        // --- Initialization ---

        init() {
            window.addEventListener('popstate', () => {
                // Browser has already changed the hash → skipHashUpdate = true
                this.change(true);
            });
            this.change(true);
        },


        // --- Hash synchronization ---

        /**
         * Matches the existing layers against the paths in the hash.
         * @param {boolean} skipHashUpdate  Prevents writing the hash again
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

            // How many layers from the beginning match the hash paths?
            let matchCount = 0;
            $('article .c9-layer').each(function (i) {
                if ($(this).attr('data-url') !== allPaths[i]) return false; // break
                matchCount++;
            });

            // With too many differences: rebuild completely
            if (matchCount < maxCount - 1) {
                $('article').empty();
                this.loadSilent('');          // create the HOME layer first
                prevPaths.forEach(p => this.loadSilent(p));
                this.add(lastPath, false, skipHashUpdate);
                return;
            }

            if (pathCount === layerCount) {
                if (matchCount === pathCount) return; // nothing to do
                $('article .c9-layer:last').cLoadContent(lastPath);
            } else if (layerCount === pathCount - 1) {
                this.add(lastPath, false, skipHashUpdate);
            } else {
                this.close(false, skipHashUpdate);
            }
        },


        // --- Layer operations ---

        /**
         * Loads a path silently (without display) – for building previous layers.
         */
        loadSilent(path) {
            path = path.replace(/^\/+/, '');
            const $layer = $(`<div class="c9-layer" style="display:none" data-url="${path}"></div>`);
            $('article').append($layer);
            
            // Adjustment: add a callback so that the navigation
            // can read the title as soon as the HTML is loaded.
            $layer.cLoadContent({
                url: path,
                callback: () => {
                    this._naviRebuild();
                }
            });
        },

        /**
         * Adds a new layer and shows it.
         * @param {string}  path
         * @param {boolean} replaceHash     Replace the history entry instead of adding one
         * @param {boolean} skipHashUpdate  Do not touch the hash (init/popstate)
         * @param {boolean} slide           Slide the layer in from the right and out again
         *                                  when closing (record list
         *                                  of a lookup field, _slideIn())
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

            // Create the nav item in advance with the path name and link it to the layer
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
         * Open a new layer with a POST request (form with data-layer="new").
         * Like add(), only with form data; the hash contains the path without parameters -
         * so after a reload the layer shows the start mask of the script.
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
         * Removes the topmost layer.
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

            // Slid-in layer (add() with slide): it leaves the stack immediately -
            // calls directly after close() already see the layer below -, but
            // stays as a ghost without class c9-layer for the animation
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
         * Removes the second-topmost layer together with its path in the hash - the topmost
         * stays. For steps whose predecessor mask has become obsolete (the
         * import: its file is gone after execution); "Close" of the topmost
         * layer then lands directly on the level below.
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
         * Stays in the current layer, but loads a different path.
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
         * Like replace(), but via POST - for actions with side effects
         * (developer docs, chapter 14 (Security: web)): modifying actions must not run via GET,
         * otherwise neither `SameSite=Lax` nor the CSRF token helps).
         *
         * The hash contains the path as with replace(), i.e. WITH parameters. After a
         * reload of the page this would become a GET - the server then rejects the action
         * (Backend::requirePost()) and shows the message. This is intended: a
         * writing action should not be repeated by F5.
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
         * Placeholder of a page with a side effect on opening (check-out of the
         * input mask): reload the layer it is in via POST - immediately if
         * the layer was opened from the user interface (data-intent), otherwise only with
         * force (button in the placeholder). See header comment.
         *
         * @param {string}  elementId  Id of the placeholder
         * @param {boolean} force      Click on the button
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
         * Determines the query string (without ?) from data-url of the topmost layer
         * and without the function parameter
         * @returns {string} The query string or an empty string
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
         * Determines the path without query string from data-url of the topmost layer
         * Exceptions are the parameters area_id, src_field, src_id
         * @returns {string} The path without query string
         */
        base() {
            const cPath = $('article .c9-layer:last').attr('data-url') || ''; 
            if (!cPath) return '';

            const index = cPath.indexOf('?');
            const basePath = index !== -1 ? cPath.substring(0, index) : cPath;

            try {
                // Use a base URL in case cPath is a relative path
                const url = new URL(cPath, window.location.origin);
                
                const newParams = new URLSearchParams();
                const paramsToKeep = ['area_id', 'src_field', 'src_id'];
                
                // Check for the exception parameters and add them if present
                paramsToKeep.forEach(param => {
                    const value = url.searchParams.get(param);
                    if (value !== null) {
                        newParams.append(param, value);
                    }
                });
                
                const newQueryString = newParams.toString();
                
                // If parameters are present, separate with &, otherwise start with ?
                if (newQueryString) {
                    return `${basePath}?${newQueryString}&`;
                }
                
                return `${basePath}?`;
            } catch (e) {
                return `${basePath}?`;
            }
        },

        /**
         * Does the topmost layer show this script (path without query string)?
         *
         * Only the script part of data-url is compared - the same page with
         * other parameters (e.g. Messaging/message_list.php?tab=outbox) thus
         * counts as well. Layers below do not count: they are covered, and whether they
         * stay open is up to the user.
         *
         * @param {string} path  Path, possibly with query string
         * @returns {boolean}
         */
        isOnTop(path) {
            const script = String(path || '').replace(/^\/+/, '').split('?')[0];
            if (!script) return false;
            const url = String($('article .c9-layer:last').attr('data-url') || '').split('?')[0];
            return url === script;
        },

        /**
         * Like add(), for pages that should exist only once in the stack (header:
         * settings, messages; main menu: info, manuals):
         *  - if the topmost layer already shows this script, no new one is created
         *    (no stack of identical layers from repeated clicks) - it is only
         *    scrolled to the top;
         *  - if it lies further down, that layer is closed (together with its path
         *    in the hash) and the page is reopened on top.
         *
         * @param {string} path
         * @returns {boolean}  true: layer was created
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
         * Removes all layers and creates a new one.
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
         * Marks the layer below the topmost one (the referer) as outdated: it is
         * reloaded as soon as it is on top again (_showLast()) - no matter whether the
         * topmost layer is closed via a button, the breadcrumb navigation or the browser's
         * "Back", or replaced with replace(). Replaces the former
         * refreshReferer() and the pair close() + refresh().
         *
         * By default only the referer: deeper layers can be input masks with a
         * working copy that cannot tolerate a reload. levels > 1 additionally marks
         * the layers below - only for masks below which there is certainly no
         * input mask (e.g. editing an area via the info page and list).
         *
         * @param {function} [callback]  execute after the referer has been reloaded
         *                               (e.g. fill a lookup field of the mask below)
         * @param {number}   [levels=1]  Number of layers below the topmost one
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
         * Reloads the topmost layer.
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
         * Complete the login: loads the contents of layers that remained empty.
         *
         * If the header (rendered server-side in index.php) is in a different
         * language than the one chosen at login, the backend is reloaded
         * completely - the layers are rebuilt from the hash anyway.
         *
         * @param {string} backendCode  chosen backend language ('' = unknown)
         */
        loginComplete(backendCode, locale) {
            // Header in a different language or cConfig.locale (date formats of the
            // extensions) outdated: the page was loaded without login
            // (<html lang> in BCP 47 notation: de_DE -> de-DE)
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
                // Close the login dialog
                $('#login-modal').modal('hide');
            });
            $('article').scrollTop(0);
            $('article .c9-layer').css('display','none').last().css('display', 'block');
            // Background processes from an earlier session? Check once,
            // the monitoring ends by itself if nothing is running (cApp.polling)
            if (cApp.polling && cApp.polling.watchBgproc) cApp.polling.watchBgproc();
            // Badge of the internal messages: index.php was loaded without login
            // and shows 0 (cApp.mail)
            if (cApp.mail && cApp.mail.refreshBadge) cApp.mail.refreshBadge();
        },

        /**
         * Shows the login dialog.
         *
         * Every Ajax response '##accessdenied##' ends up here - at startup without a
         * session there are several at once (each layer from the hash) and
         * then more continuously (polling). If the dialog is already open, the
         * form is therefore NOT loaded again: that would discard the current input
         * and set the focus back to the name field.
         */
        login() {
            const $modal  = $('#login-modal');
            const $dialog = $('#login-dialog');

            // Already open and form present or on its way: do nothing
            if (this._loginOpen && ($dialog.data('xhr') || $dialog.find('form').length)) return;
            this._loginOpen = true;

            // At the end of the fade transition (shown.bs.modal) Bootstrap
            // forces the focus onto the modal element itself via its focus trap.
            // If our Ajax callback runs before this event (e.g. with a
            // warm browser/fast connection), its focus is overwritten
            // by that. Therefore also focus here,
            // since shown.bs.modal is guaranteed to fire after the focus trap.
            $modal
                .off('shown.bs.modal.login hidden.bs.modal.login')
                .on('shown.bs.modal.login', function () {
                    $('#login-name').trigger('focus');
                })
                .on('hidden.bs.modal.login', () => {
                    this._loginOpen = false;
                })
                .modal('show');

            // Load the form in the last chosen language or the browser language
            const lang = (cApp.ui && cApp.ui.loginPrefLang) ? cApp.ui.loginPrefLang() : '';
            $dialog.empty().cLoadContent({
                url: 'Start/login.php' + (lang ? '?lang=' + encodeURIComponent(lang) : '')
            });
        },

        /**
         * Is the login dialog open (logged out)? Polling requests are then
         * skipped - their response '##accessdenied##' would have nothing to do otherwise.
         * @returns {boolean}
         */
        loginOpen() {
            return !!this._loginOpen || $('#login-modal').hasClass('show');
        },

        /**
         * Shows the confirmation prompt before logging out.
         *
         * Uses the same dialog as the login (#login-modal): the
         * script Start/logout.php delivers the parts of the modal. If the
         * user confirms, the form sends itself back to the same script and the
         * response reloads the window; if they cancel, the button closes the
         * modal (data-bs-dismiss) and the user interface stays as it was.
         */
        logout() {
            const $modal  = $('#login-modal');
            const $dialog = $('#login-dialog');

            $modal
                .off('hidden.bs.modal.logout')
                .one('hidden.bs.modal.logout', () => {
                    // Afterwards the dialog belongs to the login form again
                    $dialog.empty();
                })
                .modal('show');

            $dialog.empty().cLoadContent({ url: 'Start/logout.php' });
        },

        /**
         * Closes all layers safely (with a check for unsaved data)
         * and jumps back to the start page.
         */
        home() {
            this._closeLayersTo(0, () => {
                // All layers were closed safely. Remove the hash completely.
                history.pushState(null, null, window.location.pathname + window.location.search);
                
                // Update the navigation
                this._naviRebuild();

                // Rebuild the base layer (start page)
                this.loadSilent('');
                $('article').scrollTop(0);
                $('article .c9-layer:last').css('display', 'block');
            });
        },


        // --- Breadcrumb: successive closing with dirty check ---

        /**
         * Closes layers successively from the topmost one to the target depth.
         * Checks at each step whether there are unsaved changes.
         * Aborts if the user cancels the discard dialog.
         *
         * @param {number} targetDepth  Number of layers that should still exist afterwards
         * @param {function} onDone    Called when the target is reached
         */
        _closeLayersTo(targetDepth, onDone) {
            const $layers    = $('article .c9-layer');
            const currentDepth = $layers.length;

            if (currentDepth <= targetDepth) {
                // Target reached
                if (typeof onDone === 'function') onDone();
                return;
            }

            // Check the topmost layer for unsaved changes
            const $topLayer = $layers.last();
            cApp.forms.checkLayerAndExecute($topLayer, () => {
                // User has confirmed (or no dirty state) → remove the layer
                $topLayer.remove();
                // Check the next level recursively
                this._closeLayersTo(targetDepth, onDone);
            });
        },


        // --- Animation: sliding layers (record list of a lookup field) ---

        /** Duration of the slide animation in ms */
        _slideDuration: 180,

        /** Stop the running animation immediately (function) or null */
        _slideDone: null,

        /**
         * Animation possible and desired? Not without the Web Animations API and not
         * with "Reduce motion" in the operating system.
         * @returns {boolean}
         */
        _slideOk() {
            return typeof Element.prototype.animate === 'function'
                && !(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
        },

        /** Bring the running slide animation to its end immediately */
        _slideFinish() {
            if (this._slideDone) this._slideDone();
        },

        /**
         * Move both layers at the same time; done() cleans up - at the end of the
         * animation or prematurely via _slideFinish().
         * @param {Element[]} elements
         * @param {Array[]}   offsets   per element [from, to] in % of the width
         * @param {function}  cleanup
         */
        _slide(elements, offsets, cleanup) {
            // mirrored in right-to-left languages
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
         * Opening: the new layer starts with its left edge at the right margin,
         * both layers slide to the left until the new one touches the left margin.
         * Meanwhile the new layer lies absolutely positioned at the height of the scroll position.
         * @param {jQuery} $new
         * @returns {boolean}  false: not animated (the caller switches immediately)
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
         * Closing: the ghost of the closed layer and the layer below that becomes
         * visible again slide to the right together, then the ghost disappears.
         * @param {jQuery} $ghost       closed layer (absolutely positioned)
         * @param {number} scrollFrom   scroll position at which it was visible
         */
        _slideOut($ghost, scrollFrom) {
            const $cur = $('article .c9-layer:last');
            // Offset the ghost so that it stays visible at the same place
            $ghost.css('top', ($('article').scrollTop() - scrollFrom) + 'px');
            this._slide([$cur[0], $ghost[0]], [[-100, 0], [0, 100]], () => {
                $ghost.remove();
            });
        },


        // --- Internal helper functions ---

        _showLast() {
            const $last = $('article .c9-layer:last');
            $last.css('display', 'block');
            const scrollY = parseInt($last.attr('data-scroll') || 0, 10);
            $('article').scrollTop(scrollY);
            // outdated (invalidate()): reload, keep the scroll position
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
         * Rebuilds the breadcrumb navigation based on the current hash.
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

            // Define a separate counter for the paths
            let pathIndex = 0;
            
            $('article .c9-layer').each(function() {
                const url = $(this).attr('data-url') || '';
                
                // Skip the empty base layer, since it does not occur in the paths array
                if (url === '') return; 

                const $navSpan = $(this).find('.c9-navtext').first();
                if ($navSpan.length) {
                    $('#c9-navi .c9-navitem-link, #c9-navi .c9-navitem-text')
                        .eq(pathIndex + 1) // +1 because of the home icon on the far left
                        .text($navSpan.text());
                }
                pathIndex++;
            });
        }
    };


    // ---------------------------------------------------------------------------
    // Breadcrumb click handler
    // Successive closing with dirty check per layer
    // ---------------------------------------------------------------------------
    $(document).on('click', '#c9-navi a', function (e) {
        e.preventDefault();

        const clickedIndex = $(this).index('#c9-navi a'); // 0 = Home
        const targetDepth  = clickedIndex; // this many hash paths should remain

        // Check whether an empty base layer ('') exists
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
                
                // (_showLast(): an outdated start page reloads)
                cApp.layers._showLast();
                $('article').scrollTop(0);
            } else {
                cApp.layers._showLast();
            }
        });
    });

}(window.cApp));
