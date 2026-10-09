/*
 * Extension Datepicker - Handler für cApp.extensions (13 §3.4)
 *
 * Stellt neben das Textfeld ein Kalenderfeld (vanillajs-datepicker) und, bei Datum
 * mit Uhrzeit, ein natives Zeitfeld. Sprache des Kalenders und Datumsformat folgen
 * der Locale des Admins (cConfig.locale, z. B. de_AT): der Kalender nimmt die
 * passende Übersetzung aus Datepicker.locales, geschrieben wird der Wert so, wie ihn
 * das Backend selbst anzeigt - Intl.DateTimeFormat mit dateStyle 'medium' (und
 * timeStyle 'short', wenn eine Uhrzeit ungleich 00:00 gesetzt ist), also derselbe
 * ICU-Stil wie Cms::_formatDate(); Dataset::dateInputToIso() liest ihn zurück.
 * Ein ISO-Wert oder ein Wert im Anzeigeformat im Textfeld wird beim Öffnen in
 * Kalender und Zeitfeld übernommen. Optionen: mode (auto|datetime|date),
 * buttons (0|1: "Heute"/"Löschen"), week_numbers (0|1).
 */
(function (cApp) {
    'use strict';

    const ISO = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/;
    // Uhrzeit am Ende des Anzeigeformats: "14:30", "2:30 PM", "2:30 p.m." (auch mit
    // geschütztem oder schmalem Leerzeichen vor AM/PM)
    const TIME_TAIL = /[\s,\u00a0\u202f]*(\d{1,2}):(\d{2})(?::(\d{2}))?(?:[\s\u00a0\u202f]*([AaPp])\.?\s?[Mm]\.?)?[\s.]*$/;

    const icuLocale = String((window.cConfig || {}).locale || '');
    const bcp47 = icuLocale.replace(/_/g, '-') || 'en';

    // Sprache des Kalenders: de_AT -> de-AT, sonst de; zh_Hans_CN -> zh-CN; unbekannt -> en
    function pickerLanguage() {
        const known = {};
        Object.keys(Datepicker.locales).forEach((key) => { known[key.toLowerCase()] = key; });
        const parts = icuLocale.split(/[-_]/).filter(Boolean);
        if (!parts.length) return 'en';
        const lang = parts[0];
        const candidates = [];
        if (parts.length > 1) candidates.push(lang + '-' + parts[parts.length - 1]);
        if (parts.length > 2) candidates.push(lang + '-' + parts[1]);
        candidates.push(lang);
        for (const candidate of candidates) {
            const key = known[candidate.toLowerCase()];
            if (key) return key;
        }
        return 'en';
    }

    // Format-String für Datepicker.parseDate(), abgeleitet aus dem mittleren
    // Datumsformat der Locale (Intl.formatToParts). null, wenn sich das Format nicht
    // in Tokens fassen lässt (Wochentag, Buchstaben zwischen den Teilen, fremde
    // Ziffern) - dann wird nur ISO erkannt.
    function parseFormat() {
        let parts;
        try {
            parts = new Intl.DateTimeFormat(bcp47, { dateStyle: 'medium' }).formatToParts(new Date(2001, 2, 5));
        } catch (e) { return null; }
        let format = '';
        for (const part of parts) {
            switch (part.type) {
                case 'day':
                    if (!/^\d+$/.test(part.value)) return null;
                    format += part.value.length > 1 ? 'dd' : 'd';
                    break;
                case 'month':
                    if (/^\d+$/.test(part.value)) format += part.value.length > 1 ? 'mm' : 'm';
                    else format += part.value.length > 4 ? 'MM' : 'M';
                    break;
                case 'year':
                    if (!/^\d+$/.test(part.value)) return null;
                    format += part.value.length > 2 ? 'yyyy' : 'yy';
                    break;
                case 'literal':
                    if (/[a-z]/i.test(part.value)) return null;
                    format += part.value;
                    break;
                default:
                    return null;
            }
        }
        return format;
    }

    let language = null;
    let format = null;

    function setup() {
        if (language === null) {
            language = pickerLanguage();
            format = parseFormat();
        }
    }

    // Textfeld lesen: {date: Date|null, time: 'HH:MM'|''}
    function parseValue(value) {
        value = String(value || '').trim();
        const result = { date: null, time: '' };
        if (value === '') return result;
        const iso = value.match(ISO);
        if (iso) {
            result.date = new Date(+iso[1], +iso[2] - 1, +iso[3]);
            if (iso[4] !== undefined) result.time = iso[4] + ':' + iso[5];
            return result;
        }
        let datePart = value;
        const tail = value.match(TIME_TAIL);
        if (tail) {
            let hours = +tail[1];
            const ampm = (tail[4] || '').toUpperCase();
            if (ampm === 'P' && hours < 12) hours += 12;
            if (ampm === 'A' && hours === 12) hours = 0;
            result.time = String(hours).padStart(2, '0') + ':' + tail[2];
            datePart = value.slice(0, tail.index).trim();
        }
        if (format && datePart !== '') {
            const parsed = Datepicker.parseDate(datePart, format, language);
            if (parsed !== undefined && !isNaN(parsed)) result.date = new Date(parsed);
        }
        return result;
    }

    // Wert für das Textfeld im Anzeigeformat des Backends
    function formatValue(date, time) {
        if (!date) return '';
        const value = new Date(date.getFullYear(), date.getMonth(), date.getDate());
        const options = { dateStyle: 'medium' };
        const [hours, minutes] = (time || '').split(':').map(Number);
        if (hours || minutes) {
            value.setHours(hours, minutes);
            options.timeStyle = 'short';
        }
        return new Intl.DateTimeFormat(bcp47, options).format(value);
    }

    cApp.extensions.register('datepicker', {

        init(el, options) {
            setup();
            const withTime = (options.mode || 'auto') !== 'date';
            const buttons = String(options.buttons ?? '1') === '1';

            const dateInput = document.createElement('input');
            dateInput.type = 'text';
            dateInput.className = 'c9-datepicker-picker';
            dateInput.readOnly = true;
            dateInput.setAttribute('aria-label', el.getAttribute('aria-label') || '');
            el.insertAdjacentElement('afterend', dateInput);

            let timeInput = null;
            if (withTime) {
                timeInput = document.createElement('input');
                timeInput.type = 'time';
                timeInput.className = 'c9-datepicker-time';
                timeInput.step = '60';
                dateInput.insertAdjacentElement('afterend', timeInput);
            }

            const picker = new Datepicker(dateInput, {
                language: language,
                format: {
                    toValue: (str) => parseValue(str).date || undefined,
                    toDisplay: (date) => formatValue(date, ''),
                },
                autohide: true,
                buttonClass: 'btn btn-light border-0', // randlose Schaltflächen
                // Chevrons statt « »; icon-dir spiegelt sie bei RTL-Sprachen
                prevArrow: '<span class="icon icon-dir" aria-hidden="true">chevron_left</span>',
                nextArrow: '<span class="icon icon-dir" aria-hidden="true">chevron_right</span>',
                todayButton: buttons,
                todayButtonMode: 1,
                clearButton: buttons,
                todayHighlight: true,
                weekNumbers: String(options.week_numbers ?? '0') === '1' ? 1 : 0,
                enableOnReadonly: true,
                showOnClick: true,
                showOnFocus: false,
                updateOnBlur: false,
            });

            let syncing = false;
            const fromField = () => {
                syncing = true;
                const parsed = parseValue(el.value);
                if (parsed.date) picker.setDate(parsed.date);
                else picker.setDate({ clear: true });
                if (timeInput) timeInput.value = parsed.time;
                syncing = false;
            };
            const toField = () => {
                if (syncing) return;
                const date = picker.getDate();
                el.value = date ? formatValue(date, timeInput ? timeInput.value : '') : '';
                cApp.forms.markChanged(el);
            };

            fromField();
            dateInput.addEventListener('changeDate', toField);
            if (timeInput) timeInput.addEventListener('change', toField);
            el.addEventListener('change', fromField);

            el.classList.add('c9-datepicker-input');
            el._c9Datepicker = { picker, dateInput, timeInput, fromField };
        },

        destroy(el) {
            const state = el._c9Datepicker;
            if (state) {
                el.removeEventListener('change', state.fromField);
                state.picker.destroy();
                state.dateInput.remove();
                if (state.timeInput) state.timeInput.remove();
                delete el._c9Datepicker;
            }
            el.classList.remove('c9-datepicker-input');
        }
    });

}(window.cApp));
