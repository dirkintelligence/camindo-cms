/*
 * Extension Password - Passwort-Richtlinie und Stärke-Anzeige (16-sicherheit.md §12)
 *
 * Unter dem Passwort-Feld ein Balken (rot = schwach, orange = mittel, grün = stark),
 * beim Tippen eine Sprechblase mit den Anforderungen, erfüllte abgehakt. Solange eine
 * Anforderung fehlt, bleibt der Balken rot - geprüft wird ohnehin auf dem Server
 * (Camindo\Core\PasswordPolicy); die Zählung hier muss mit PasswordPolicy::count()
 * übereinstimmen.
 *
 * Läuft ohne Backend: im Frontend das Script und password.ext.css inline ins Template
 * setzen und das Feld markieren -
 *     <input type="password" name="data[passwort]" data-c9-password='{"rules":{…},"texts":{…}}'>
 * Felder mit data-c9-password werden beim Laden der Seite ausgestattet, andere per
 * C9Password.attach(input, options). Im Backend übernimmt cApp.extensions
 * (Backend::formField(), Optionen aus PasswordPolicy::clientOptions()).
 *
 * options = {
 *     rules: { min_length, min_special, min_upper, upper_not_first, min_lower, min_digit },
 *     texts: { title, rules: { min_length: 'mindestens 12 Zeichen', … }, strength: [schwach, mittel, stark] }
 * }
 * Fehlende Regeln: die Vorgaben unten (= security.password_policy); fehlende Texte:
 * Englisch, {n} = Anzahl.
 */
(function (window, document) {
    'use strict';

    const RULES = { min_length: 12, min_special: 2, min_upper: 1, upper_not_first: true, min_lower: 1, min_digit: 0 };
    const TEXTS = {
        title: 'Your password needs:',
        rules: {
            min_length: 'at least {n} characters',
            min_special: 'at least {n} special characters',
            min_upper: 'at least {n} uppercase letters',
            min_upper_inner: 'at least {n} uppercase letters (not counting the first character)',
            min_lower: 'at least {n} lowercase letters',
            min_digit: 'at least {n} digits'
        },
        strength: ['weak', 'medium', 'strong']
    };
    // Häufige Bestandteile schwacher Passwörter - drücken die Stärke, sind aber keine Regel
    const COMMON = /(passwor[dt]|qwert[yz]|asdf|letmein|welcome|willkommen|hallo|admin|login|secret|geheim|abc123|iloveyou|123456|111111|000000)/i;

    let uid = 0;

    // Zeichen je Klasse - wie PasswordPolicy::count()
    function count(pw) {
        const chars = Array.from(String(pw));
        const n = (re) => chars.filter((c) => re.test(c)).length;
        const upper = n(/\p{Lu}/u);
        return {
            length: chars.length,
            special: n(/[^\p{L}\p{N}]/u),
            upper: upper,
            upper_inner: upper - (chars.length && /\p{Lu}/u.test(chars[0]) ? 1 : 0),
            lower: n(/\p{Ll}/u),
            digit: n(/\p{N}/u)
        };
    }

    function rulesOf(options) {
        const rules = Object.assign({}, RULES, (options && options.rules) || {});
        Object.keys(RULES).forEach((key) => {
            rules[key] = key === 'upper_not_first' ? !!Number(rules[key]) : Math.max(0, parseInt(rules[key], 10) || 0);
        });
        return rules;
    }

    // Nicht erfüllte Regeln - wie PasswordPolicy::failed()
    function check(pw, rules) {
        rules = rulesOf({ rules: rules });
        const c = count(pw);
        const failed = [];
        if (rules.min_length && c.length < rules.min_length) failed.push('min_length');
        if (rules.min_special && c.special < rules.min_special) failed.push('min_special');
        if (rules.min_upper && (rules.upper_not_first ? c.upper_inner : c.upper) < rules.min_upper) failed.push('min_upper');
        if (rules.min_lower && c.lower < rules.min_lower) failed.push('min_lower');
        if (rules.min_digit && c.digit < rules.min_digit) failed.push('min_digit');
        return failed;
    }

    // Stärke 0 (leer) bis 3: geschätzte Entropie aus Länge und Zeichenvorrat, abzüglich
    // Wiederholungen, Folgen (abc, 123) und häufiger Bestandteile
    function strength(pw, rules) {
        pw = String(pw);
        if (pw === '') return 0;
        const c = count(pw);
        let pool = 0;
        if (c.lower) pool += 26;
        if (c.upper) pool += 26;
        if (c.digit) pool += 10;
        if (c.special) pool += 33;
        const chars = Array.from(pw);
        const unique = new Set(chars.map((ch) => ch.toLowerCase())).size;
        let bits = chars.length * Math.log2(Math.max(pool, 2)) * Math.min(1, unique / Math.max(chars.length * 0.6, 1));
        let runs = 0;
        for (let i = 2; i < chars.length; i++) {
            const a = chars[i - 2].toLowerCase().charCodeAt(0), b = chars[i - 1].toLowerCase().charCodeAt(0), d = chars[i].toLowerCase().charCodeAt(0);
            if ((b - a === 1 && d - b === 1) || (a - b === 1 && b - d === 1) || (a === b && b === d)) runs++;
        }
        bits -= runs * 4;
        if (COMMON.test(pw)) bits -= 25;
        let level = bits < 45 ? 1 : (bits < 75 ? 2 : 3);
        if (rules && check(pw, rules).length) level = 1;
        return level;
    }

    function ruleText(key, rules, texts) {
        const own = texts.rules || {};
        if (own[key]) return own[key];
        const fallbackKey = key === 'min_upper' && rules.upper_not_first ? 'min_upper_inner' : key;
        return (TEXTS.rules[fallbackKey] || key).replace('{n}', rules[key]);
    }

    function attach(input, options) {
        if (!input || input._c9Password) return;
        options = options || {};
        const rules = rulesOf(options);
        const texts = Object.assign({}, TEXTS, options.texts || {});
        const labels = Array.isArray(texts.strength) && texts.strength.length === 3 ? texts.strength : TEXTS.strength;
        const host = input.closest('.input-group') || input;
        const anchor = host.parentNode;
        const id = 'c9-pw-' + (++uid);

        anchor.classList.add('c9-pw-anchor');
        if (!input.getAttribute('autocomplete')) input.setAttribute('autocomplete', 'new-password');

        // Balken + Stufe
        const meter = document.createElement('div');
        meter.className = 'c9-pw-meter';
        meter.hidden = true;
        const bar = document.createElement('div');
        bar.className = 'c9-pw-bar';
        bar.appendChild(document.createElement('span'));
        const label = document.createElement('span');
        label.className = 'c9-pw-label';
        label.setAttribute('aria-live', 'polite');
        meter.append(bar, label);
        host.insertAdjacentElement('afterend', meter);

        // Sprechblase mit den Anforderungen
        const bubble = document.createElement('div');
        bubble.className = 'c9-pw-bubble';
        bubble.id = id;
        bubble.setAttribute('role', 'tooltip');
        bubble.hidden = true;
        const title = document.createElement('div');
        title.className = 'c9-pw-title';
        title.textContent = texts.title;
        const list = document.createElement('ul');
        const items = {};
        ['min_length', 'min_special', 'min_upper', 'min_lower', 'min_digit'].forEach((key) => {
            if (!rules[key]) return;
            const li = document.createElement('li');
            li.textContent = ruleText(key, rules, texts);
            list.appendChild(li);
            items[key] = li;
        });
        bubble.append(title, list);
        meter.insertAdjacentElement('afterend', bubble);
        if (Object.keys(items).length) {
            input.setAttribute('aria-describedby', ((input.getAttribute('aria-describedby') || '') + ' ' + id).trim());
        }

        function update() {
            const value = input.value;
            const failed = check(value, rules);
            Object.keys(items).forEach((key) => items[key].classList.toggle('ok', value !== '' && failed.indexOf(key) < 0));
            const level = strength(value, rules);
            meter.hidden = value === '';
            meter.className = 'c9-pw-meter c9-pw-s' + level;
            label.textContent = level ? labels[level - 1] : '';
        }
        function show() {
            if (!Object.keys(items).length) return;
            // unter Balken bzw. Feld, Pfeil zeigt nach oben
            const below = meter.hidden ? host : meter;
            bubble.style.top = (below.offsetTop + below.offsetHeight + 10) + 'px';
            bubble.hidden = false;
            // bündig mit dem Feldanfang: links, bei dir="rtl" rechts (der Pfeil folgt
            // per inset-inline-start in password.ext.css)
            const parent = bubble.offsetParent;
            if (parent && getComputedStyle(host).direction === 'rtl') {
                bubble.style.left = '';
                bubble.style.right = (parent.clientWidth - host.offsetLeft - host.offsetWidth) + 'px';
            }
            else {
                bubble.style.right = '';
                bubble.style.left = host.offsetLeft + 'px';
            }
        }
        function hide() { bubble.hidden = true; }
        function onInput() { update(); if (document.activeElement === input) show(); }

        input.addEventListener('input', onInput);
        input.addEventListener('focus', show);
        input.addEventListener('blur', hide);
        input._c9Password = { meter: meter, bubble: bubble, anchor: anchor, onInput: onInput, show: show, hide: hide };
        update();
    }

    function detach(input) {
        const state = input && input._c9Password;
        if (!state) return;
        input.removeEventListener('input', state.onInput);
        input.removeEventListener('focus', state.show);
        input.removeEventListener('blur', state.hide);
        state.meter.remove();
        state.bubble.remove();
        state.anchor.classList.remove('c9-pw-anchor');
        delete input._c9Password;
    }

    function init(root) {
        (root || document).querySelectorAll('input[data-c9-password]').forEach((input) => {
            let options = {};
            try { options = JSON.parse(input.getAttribute('data-c9-password') || '{}'); } catch (e) { /* Vorgaben */ }
            attach(input, options);
        });
    }

    window.C9Password = { attach: attach, detach: detach, init: init, check: check, strength: strength, count: count };

    // Backend: Handler für cApp.extensions (13 §3.4)
    if (window.cApp && window.cApp.extensions) {
        window.cApp.extensions.register('password', {
            init(el, options) { attach(el, options); },
            destroy(el) { detach(el); }
        });
    }

    // Frontend: markierte Felder beim Laden
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => init());
    else init();

}(window, document));
