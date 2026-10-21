/*
 * cApp.a11y – Accessibility of the backend (WCAG 2.1 AA / BITV 2.0)
 *
 * What the templates cannot do on their own:
 *
 *     Skip links       move the focus instead of changing the hash (the hash is the
 *                      layer routing, backend-layers.js)
 *     focusLayer()     focus on the heading of a newly shown layer (WCAG 2.4.3)
 *     Keyboard         Enter/Space on elements with role="button" that are no <button>;
 *                      list rows (.c9-ctx-row): Enter opens the row, Shift+F10 or the
 *                      context menu key opens its context menu (WCAG 2.1.1)
 *     Context menus    menu semantics and arrow keys (.c9-ctx-menu, backend-ctx-menu.js)
 *     Popups           Escape closes the main menu and the background processes and
 *                      returns the focus to their button
 *     Forms            after sending: focus on the first invalid field (afterSubmit())
 *     Icons            .icon (ligature font) without aria-hidden: the screen reader would
 *                      read the ligature name ("more_vert") - marked as hidden when the
 *                      surrounding element has a name of its own
 *     Page title       backend-layers.js _updateTitle(): current level of the breadcrumb
 *
 * Loaded after backend-handlers.js, before the other cApp modules.
 */
(function ($) {
    'use strict';

    window.cApp = window.cApp || {};

    const NATIVE_BUTTON = 'button, input, select, textarea, summary, a[href]';

    cApp.a11y = {

        init() {
            this._bindSkipLinks();
            this._bindRoleButton();
            this._bindRows();
            this._bindMenus();
            this._bindEscape();
            this._observeIcons();
        },

        /**
         * Focus the visible layer: its first heading (h1-h3), otherwise the layer itself.
         * The element only receives tabindex="-1" (reachable by script, not in the tab order).
         * @param {jQuery} [$layer]  default: the visible topmost layer
         */
        focusLayer($layer) {
            $layer = ($layer && $layer.length) ? $layer : $('article .c9-layer:visible').last();
            if (!$layer.length) {
                $('#c9-content').trigger('focus');
                return;
            }
            // cLoadContent() has already focused the first input field: keep it
            if ($layer[0].contains(document.activeElement)) return;
            // h3: info pages start with headline3 (e.g. Template/template_info.php)
            let $target = $layer.find('h1, h2, h3').filter(':visible').first();
            if (!$target.length) $target = $layer;
            if (!$target.is('[tabindex]')) $target.attr({ tabindex: '-1', 'data-c9-focus-target': '' });
            $target[0].focus({ preventScroll: true });
        },

        /**
         * Remember the element that had the focus when a layer was opened - closing
         * the layer returns the focus there (backend-layers.js, _showLast()).
         * @param {jQuery} $layer  the layer that stays below the new one
         */
        rememberFocus($layer) {
            const active = document.activeElement;
            if ($layer && $layer.length && active && active !== document.body && $layer[0].contains(active)) {
                $layer.data('c9-return-focus', active);
            }
        },

        /**
         * Return the focus to the remembered element of the layer (rememberFocus()),
         * otherwise to its heading.
         * @param {jQuery} $layer
         */
        restoreFocus($layer) {
            const el = $layer && $layer.data('c9-return-focus');
            if ($layer) $layer.removeData('c9-return-focus');
            if (el && document.contains(el) && $(el).is(':visible')) {
                el.focus({ preventScroll: true });
                return;
            }
            this.focusLayer($layer);
        },

        /**
         * Identify the focused element of a layer whose content is about to be reloaded
         * (cApp.layers.replace(): paging, sorting, opening a folder): its handler
         * attribute (backend-handlers.js), otherwise its id.
         * @param {jQuery} $layer
         * @returns {object|null}  for refocus()
         */
        focusKey($layer) {
            const el = document.activeElement;
            if (!el || !$layer || !$layer.length || !$layer[0].contains(el)) return null;
            // the text without icons: a folder link keeps its name when it toggles
            const key = { tag: el.tagName, text: this._text(el) };
            for (const attr of el.attributes) {
                if (attr.name.indexOf('data-c9h-') === 0) return Object.assign(key, { attr: attr.name, value: attr.value });
            }
            if (el.id) return Object.assign(key, { attr: 'id', value: el.id });
            return key.text !== '' ? key : null;
        },

        _text(el) {
            const copy = el.cloneNode(true);
            copy.querySelectorAll('[aria-hidden="true"], .icon').forEach((n) => n.remove());
            return copy.textContent.replace(/\s+/g, ' ').trim();
        },

        /**
         * After reloading: focus the element with the same key again - otherwise the
         * heading of the layer, if the focus was lost.
         * @param {jQuery} $layer
         * @param {object|null} key  from focusKey()
         */
        refocus($layer, key) {
            if (key) {
                let match = key.attr ? Array.from($layer[0].querySelectorAll('[' + CSS.escape(key.attr) + ']'))
                    .find((el) => el.getAttribute(key.attr) === key.value) : null;
                if (!match && key.text !== '') {
                    match = Array.from($layer[0].getElementsByTagName(key.tag))
                        .find((el) => this._text(el) === key.text);
                }
                if (match) {
                    match.focus({ preventScroll: true });
                    return;
                }
            }
            const active = document.activeElement;
            if (!active || active === document.body || !document.contains(active)) this.focusLayer($layer);
        },

        /**
         * After a form was sent and its response replaced the layer (backend-forms.js):
         * focus on the first field with an error - the screen reader announces its label
         * and, via aria-describedby, the message. Without errors the response is
         * a new page: focus on its heading, if the focus got lost.
         * @param {jQuery} $target  layer or modal dialog
         */
        afterSubmit($target) {
            const apply = () => {
                const active = document.activeElement;
                const lost = !active || active === document.body || !document.contains(active);
                const invalid = $target.find('[aria-invalid="true"]').filter(':visible').get(0);
                if (invalid && (lost || !invalid.contains(active))) {
                    invalid.focus();
                    return;
                }
                if (lost && $target.hasClass('c9-layer')) this.focusLayer($target);
            };
            apply();
            // field extensions build their editor asynchronously and may replace the
            // focused field - check once more when they are done
            setTimeout(() => {
                const active = document.activeElement;
                if (!active || active === document.body || !document.contains(active)) apply();
            }, 600);
        },

        // --- Skip links ---

        _bindSkipLinks() {
            $(document).on('click', '.c9-skip-link', (e) => {
                e.preventDefault();
                const target = $(e.currentTarget).attr('data-c9-skip');
                if (target === '#c9-navi') {
                    const $first = $('#c9-navi a').first();
                    if ($first.length) $first.trigger('focus');
                    else $('#c9-navi')[0].focus();
                    return;
                }
                this.focusLayer();
            });
        },

        // --- Enter and Space on role="button" ---

        _bindRoleButton() {
            // also column headers that toggle the checkboxes of their column
            // (field list, permission table: backend-forms.js _bindColumnToggle())
            $(document).on('keydown', '[role="button"], th.c9-toggle-col-checkboxes, table.c9-access-table thead tr:last-child th[tabindex]', function (e) {
                if (e.key !== 'Enter' && e.key !== ' ') return;
                if (e.target !== this || $(this).is(NATIVE_BUTTON) || $(this).is('tr.c9-ctx-row')) return;
                e.preventDefault();
                this.click();
            });
        },

        // --- List rows (.c9-ctx-row) ---

        _bindRows() {
            $(document).on('keydown', 'tr.c9-ctx-row', function (e) {
                // only the row itself, not a checkbox or link inside it
                if (e.target !== this) return;
                if (e.key === 'Enter' || e.key === ' ') {
                    if ($(this).hasClass('c9-click-disabled')) return;
                    const cell = $(this).children('td:not(.c9-click-disabled)').get(0);
                    if (!cell) return;
                    e.preventDefault();
                    cell.click();
                    return;
                }
                if (e.key === 'ContextMenu' || (e.key === 'F10' && e.shiftKey)) {
                    e.preventDefault();
                    // context menu at the start of the row - the handler in
                    // backend-ctx-menu.js positions it at pageX/pageY
                    const rect = this.getBoundingClientRect();
                    const rtl = document.documentElement.dir === 'rtl';
                    const ev = $.Event('contextmenu', {
                        pageX: (rtl ? rect.right - 24 : rect.left + 24) + window.scrollX,
                        pageY: rect.top + rect.height / 2 + window.scrollY,
                        target: this,
                        c9Keyboard: true
                    });
                    $(this).trigger(ev);
                }
            });
        },

        // --- Context menus (.c9-ctx-menu) ---

        /**
         * Called by backend-ctx-menu.js after opening: menu roles, focus on the
         * first visible entry, remember the row for Escape.
         * @param {jQuery}  $menu
         * @param {Element} opener  the row
         */
        menuOpened($menu, opener) {
            $menu.attr('role', 'menu').data('c9-opener', opener);
            $menu.find('.dropdown-item').attr({ role: 'menuitem', tabindex: '-1' });
            $menu.find('.dropdown-divider').attr('role', 'separator');
            // after the fade-in: an element with display:none cannot take the focus
            // (the timer in case the animation does not run, e.g. in a background tab)
            let focused = false;
            const focusFirst = () => {
                if (focused || !$menu.is(':visible')) return;
                const $first = this._menuItems($menu).first();
                if ($first.length) $first[0].focus({ preventScroll: true });
                focused = true;
            };
            $menu.promise().done(focusFirst);
            setTimeout(focusFirst, 150);
        },

        /**
         * Close a context menu, focus back to the row.
         * @param {jQuery} $menu
         */
        menuClose($menu) {
            const opener = $menu.data('c9-opener');
            $menu.fadeOut(100).removeData('c9-opener');
            if (opener && document.contains(opener)) opener.focus({ preventScroll: true });
        },

        _menuItems($menu) {
            return $menu.find('.dropdown-item').filter(function () {
                return $(this).css('display') !== 'none' && !$(this).hasClass('disabled');
            });
        },

        _bindMenus() {
            // Capture phase on window: the menus carry .dropdown-menu, and Bootstrap's
            // keyboard handler for dropdowns listens in the capture phase of the document -
            // it would otherwise move the focus into another dropdown of the page
            window.addEventListener('keydown', (e) => {
                const menu = e.target.closest && e.target.closest('.c9-ctx-menu');
                if (!menu) return;
                const $menu = $(menu);
                const $items = this._menuItems($menu);
                const index = $items.index(document.activeElement);
                let next = null;
                switch (e.key) {
                    case 'ArrowDown': next = (index + 1) % $items.length; break;
                    case 'ArrowUp':   next = (index - 1 + $items.length) % $items.length; break;
                    case 'Home':      next = 0; break;
                    case 'End':       next = $items.length - 1; break;
                    case 'Escape':
                        e.preventDefault();
                        e.stopPropagation();
                        this.menuClose($menu);
                        return;
                    case 'Tab':
                        this.menuClose($menu);
                        return;
                    case 'Enter':
                    case ' ':
                        if (index >= 0) {
                            e.preventDefault();
                            e.stopPropagation();
                            $items.get(index).click();
                        }
                        return;
                    default:
                        return;
                }
                e.preventDefault();
                e.stopPropagation();
                if ($items.length) $items.get(next).focus();
            }, true);
        },

        // --- Escape for the popups in the header ---

        _bindEscape() {
            $(document).on('keydown', (e) => {
                if (e.key !== 'Escape') return;
                // Tooltips can be dismissed without moving the focus (WCAG 1.4.13)
                document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
                    const tip = window.bootstrap && bootstrap.Tooltip.getInstance(el);
                    if (tip) tip.hide();
                });
                [['#c9-menu-container', '#c9-hamburger'], ['#c9-bgproc-container', '#c9-bgproc-btn']].forEach(([popup, button]) => {
                    const $popup = $(popup);
                    if (!$popup.is(':visible')) return;
                    $popup.hide();
                    $(button).attr('aria-expanded', 'false');
                    if ($popup[0].contains(document.activeElement) || document.activeElement === document.body) {
                        $(button).trigger('focus');
                    }
                });
            });
        },

        // --- Icons, column headers of the permission table ---

        /**
         * Hide icons without aria-hidden from the screen reader, if the surrounding
         * element has a name of its own (text, aria-label, title) - otherwise it
         * would read e.g. "delete Delete". Icons that are the only content of an
         * element without a name stay readable; these are errors in the template.
         */
        _observeIcons() {
            const fix = function (root) {
                if (!root || root.nodeType !== 1) return;
                // Permission table in edit mode: the column headers toggle their column -
                // reachable with the keyboard
                root.querySelectorAll('table.c9-access-table thead tr:last-child th').forEach(function (th) {
                    if (th.hasAttribute('tabindex') || th.textContent.trim() === '') return;
                    if (th.closest('table').querySelector('tbody input[type=checkbox]')) th.setAttribute('tabindex', '0');
                });
                const icons = root.matches('.icon') ? [root] : root.querySelectorAll('.icon:not([aria-hidden])');
                icons.forEach(function (icon) {
                    if (icon.hasAttribute('aria-hidden') || icon.getAttribute('role') === 'img') return;
                    const parent = icon.closest('button, a, [role="button"], label, th, td, li, div') || icon.parentElement;
                    if (!parent) return;
                    const named = parent.hasAttribute('aria-label') || parent.hasAttribute('title')
                        || parent.hasAttribute('aria-labelledby');
                    const text = (parent.textContent || '').replace(icon.textContent || '', '').trim();
                    if (named || text !== '') icon.setAttribute('aria-hidden', 'true');
                });
            };
            fix(document.body);
            new MutationObserver(function (mutations) {
                mutations.forEach(function (m) {
                    m.addedNodes.forEach(fix);
                });
            }).observe(document.body, { childList: true, subtree: true });
        }
    };

}(jQuery));
