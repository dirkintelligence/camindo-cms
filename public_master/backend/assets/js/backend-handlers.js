/*
 * camindo Backend – Event-Handler unter der Script-Policy (developer_docs/19-csp.md)
 *
 * Die Content-Security-Policy des Backends lässt nur Scripts aus eigenen Dateien und
 * mit der Nonce der Seite zu - Handler-Attribute (onclick="…") und javascript:-Links
 * führt der Browser nicht aus. Backend::template() und Backend::on() schreiben sie
 * deshalb als data-c9h-{nonce}-{event}="…". Dieses Script macht daraus Listener:
 * es sammelt den Code der markierten Attribute, führt ihn als ein Script mit der Nonce
 * aus (kein eval, kein 'unsafe-eval') und hängt die entstandenen Funktionen an ihre
 * Elemente.
 *
 * Nur Attribute mit der Nonce im Namen werden zu Code. Die Nonce ist je Seite neu
 * und steht nur im Code des Servers - ein onclick, das mit Inhalten aus der Datenbank
 * hereinkommt, trägt sie nicht und bleibt wirkungslos.
 *
 * Erfasst wird jedes HTML, gleich wie es ins Dokument kommt (cLoadContent, .html(),
 * .append(), geklonte Zeilen): ein MutationObserver prüft alle eingefügten Knoten.
 * cLoadContent ruft scan() zusätzlich sofort auf, damit ein Callback die Handler
 * schon vorfindet. Das Attribut bleibt stehen - eine Kopie des Elements (clone())
 * bekommt so ihre eigenen Listener.
 *
 * Außerdem hier: die Nonce geht mit jedem jQuery-Request als X-Camindo-Nonce an den
 * Server (für nachgeladene Stücke), und Verstöße gegen die Policy landen in
 * var/log/{Datum}_csp.log (Start/csp_report.php).
 */
(function ($) {
    'use strict';

    const nonce = (window.cConfig && window.cConfig.nonce) || '';

    // Nonce an jeden eigenen Ajax-Request - der Server markiert damit die Handler
    // des nachgeladenen HTML (SecurityHeaders::nonce())
    $.ajaxPrefilter(function (options, originalOptions, jqXHR) {
        if (!options.crossDomain && nonce) jqXHR.setRequestHeader('X-Camindo-Nonce', nonce);
    });

    const prefix = 'data-c9h-' + nonce + '-';
    const bound = new WeakMap();   // Element -> Set der gebundenen Ereignisse
    const registry = {};           // laufende Nummer -> Funktion (nur während bind())
    let seq = 0;

    /**
     * Code als Script mit der Nonce ausführen.
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
     * Markierte Attribute in root (einschließlich root) sammeln, die noch nicht
     * gebunden sind.
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
     * Gesammelte Handler übersetzen und anhängen. Ein Script für alle; hat es einen
     * Syntaxfehler, jeder Handler einzeln - dann fehlt nur der fehlerhafte (wie bei
     * einem onclick-Attribut).
     * @param {Array} items  aus collect()
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
            // 'js' = ehemals href="javascript:…": Klick, Standardaktion (#) nie ausführen
            const isLink = item.event === 'js';
            item.el.addEventListener(isLink ? 'click' : item.event, function (event) {
                if (isLink) event.preventDefault();
                // return false wie bei einem Handler-Attribut: Standardaktion verhindern
                if (fn.call(this, event) === false) event.preventDefault();
            });
        });
    }

    window.cApp = window.cApp || {};
    window.cApp.handlers = {
        _r: registry,

        /**
         * Markierte Handler in root binden (Standard: das ganze Dokument).
         * @param {Element} [root]
         */
        scan(root) {
            if (!nonce) return;
            bind(collect(root || document.documentElement));
        }
    };

    if (!nonce) return;

    // Alles, was später ins Dokument kommt
    new MutationObserver(function (mutations) {
        const items = [];
        mutations.forEach(function (m) {
            m.addedNodes.forEach(function (node) {
                if (node.nodeType === 1) items.push.apply(items, collect(node));
            });
        });
        bind(items);
    }).observe(document.documentElement, { childList: true, subtree: true });

    // Was schon da ist (backend/index.php)
    window.cApp.handlers.scan();

    // Verstöße gegen die Policy protokollieren - im Modus 'report' die Stellen, die
    // noch umzustellen sind; im Modus 'enforce' abgewiesene Scripts. Je Seite jede
    // Stelle einmal, nur mit Anmeldung (POST braucht das CSRF-Token).
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
