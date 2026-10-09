/*
 * camindo backend – event handlers under the script policy (developer docs, chapter 14 (Security: web))
 *
 * The content security policy of the backend only allows scripts from our own files and
 * with the nonce of the page - handler attributes (onclick="…") and javascript: links
 * are not executed by the browser. Backend::template() and Backend::on() therefore write
 * them as data-c9h-{nonce}-{event}="…". This script turns them into listeners:
 * it collects the code of the marked attributes, executes it as one script with the nonce
 * (no eval, no 'unsafe-eval') and attaches the resulting functions to their
 * elements.
 *
 * Only attributes with the nonce in their name become code. The nonce is new per page
 * and only exists in the server's code - an onclick that comes in with content from the
 * database does not carry it and has no effect.
 *
 * Any HTML is covered, no matter how it gets into the document (cLoadContent, .html(),
 * .append(), cloned rows): a MutationObserver checks all inserted nodes.
 * cLoadContent additionally calls scan() immediately, so that a callback already finds
 * the handlers. The attribute stays in place - a copy of the element (clone())
 * thus gets its own listeners.
 *
 * Also here: the nonce goes to the server with every jQuery request as X-Camindo-Nonce
 * (for content loaded later), and violations of the policy end up in
 * var/log/{date}_csp.log (Start/csp_report.php).
 */
(function ($) {
    'use strict';

    const nonce = (window.cConfig && window.cConfig.nonce) || '';

    // Nonce with every own Ajax request - the server uses it to mark the handlers
    // of the HTML loaded later (SecurityHeaders::nonce())
    $.ajaxPrefilter(function (options, originalOptions, jqXHR) {
        if (!options.crossDomain && nonce) jqXHR.setRequestHeader('X-Camindo-Nonce', nonce);
    });

    const prefix = 'data-c9h-' + nonce + '-';
    const bound = new WeakMap();   // Element -> set of bound events
    const registry = {};           // sequence number -> function (only during bind())
    let seq = 0;

    /**
     * Execute code as a script with the nonce.
     * @param {string} source
     */
    function run(source) {
        const script = document.createElement('script');
        script.nonce = nonce;
        script.textContent = source;
        (document.head || document.documentElement).appendChild(script);
        script.remove();
    }

    /**
     * Collect marked attributes in root (including root) that are not yet
     * bound.
     * @param {Element} root
     * @returns {Array<{el: Element, event: string, code: string}>}
     */
    function collect(root) {
        const found = [];
        if (!root || root.nodeType !== 1) return found;
        const check = function (el) {
            const attrs = el.attributes;
            for (let i = 0; i < attrs.length; i++) {
                const name = attrs[i].name;
                if (name.indexOf(prefix) !== 0) continue;
                const event = name.substring(prefix.length);
                const done = bound.get(el);
                if (done && done.has(event)) continue;
                found.push({ el: el, event: event, code: attrs[i].value });
            }
        };
        check(root);
        const all = root.getElementsByTagName('*');
        for (let i = 0; i < all.length; i++) check(all[i]);
        return found;
    }

    /**
     * Compile and attach the collected handlers. One script for all; if it has a
     * syntax error, each handler individually - then only the faulty one is missing (as with
     * an onclick attribute).
     * @param {Array} items  from collect()
     */
    function bind(items) {
        if (items.length === 0) return;
        let source = '';
        items.forEach(function (item) {
            item.id = ++seq;
            item.source = 'window.cApp.handlers._r[' + item.id + ']=function(event){\n' + item.code + '\n};\n';
            source += item.source;
        });
        run(source);
        if (!(items[0].id in registry)) {
            items.forEach(function (item) { run(item.source); });
        }
        items.forEach(function (item) {
            const fn = registry[item.id];
            delete registry[item.id];
            let done = bound.get(item.el);
            if (!done) bound.set(item.el, done = new Set());
            done.add(item.event);
            if (typeof fn !== 'function') {
                console.error('camindo: handler not compiled', item.event, item.code);
                return;
            }
            // 'js' = formerly href="javascript:…": click, never execute the default action (#)
            const isLink = item.event === 'js';
            item.el.addEventListener(isLink ? 'click' : item.event, function (event) {
                if (isLink) event.preventDefault();
                // return false as with a handler attribute: prevent the default action
                if (fn.call(this, event) === false) event.preventDefault();
            });
        });
    }

    window.cApp = window.cApp || {};
    window.cApp.handlers = {
        _r: registry,

        /**
         * Bind marked handlers in root (default: the whole document).
         * @param {Element} [root]
         */
        scan(root) {
            if (!nonce) return;
            bind(collect(root || document.documentElement));
        }
    };

    if (!nonce) return;

    // Everything that comes into the document later
    new MutationObserver(function (mutations) {
        const items = [];
        mutations.forEach(function (m) {
            m.addedNodes.forEach(function (node) {
                if (node.nodeType === 1) items.push.apply(items, collect(node));
            });
        });
        bind(items);
    }).observe(document.documentElement, { childList: true, subtree: true });

    // What is already there (backend/index.php)
    window.cApp.handlers.scan();

    // Log violations of the policy - in mode 'report' the places that still
    // have to be converted; in mode 'enforce' rejected scripts. Each place once
    // per page, only when logged in (POST needs the CSRF token).
    const reported = new Set();
    document.addEventListener('securitypolicyviolation', function (e) {
        const key = [e.effectiveDirective, e.blockedURI, e.sourceFile, e.lineNumber, e.sample].join('|');
        if (reported.has(key) || reported.size >= 50) return;
        reported.add(key);
        console.warn('CSP ' + (e.disposition || '') + ': ' + e.effectiveDirective
            + ' ' + (e.blockedURI || '') + ' ' + (e.sourceFile || '') + ':' + (e.lineNumber || '')
            + ' ' + (e.sample || ''));
        if (!window.cConfig || !window.cConfig.csrf_token) return;
        const data = new FormData();
        data.append('disposition', e.disposition || '');
        data.append('directive', e.effectiveDirective || '');
        data.append('blocked', e.blockedURI || '');
        data.append('source', (e.sourceFile || '') + ':' + (e.lineNumber || 0) + ':' + (e.columnNumber || 0));
        data.append('sample', e.sample || '');
        data.append('page', location.hash || '');
        $.ajax({
            type: 'POST',
            url: window.cConfig.backend_path + 'Modules/Start/csp_report.php',
            data: data,
            processData: false,
            contentType: false,
            headers: { 'X-Camindo-Token': window.cConfig.csrf_token }
        });
    });

}(jQuery));
