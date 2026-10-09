/*
 * Extension Calendar - Handler für cApp.extensions (13 §3.4)
 *
 * Stellt neben das Textfeld einen nativen Picker (<input type="datetime-local|date|time">).
 * Eine Auswahl im Picker schreibt den Wert im ISO-Format in das Textfeld (das
 * Backend versteht ISO neben dem Landesformat); ein ISO-Wert im Textfeld wird beim
 * Öffnen in den Picker übernommen. Optionen: mode (auto|datetime|date|time), step (Minuten).
 */
(function (cApp) {
    'use strict';

    const ISO = /^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2})(?::\d{2})?)?$/;
    const TIME = /^(\d{2}:\d{2})(?::\d{2})?$/;

    function modeFor(el, options) {
        if (options.mode && options.mode !== 'auto') return options.mode;
        // Feldtyp aus data-c9-ext-type (Backend::formField): time -> time, sonst Datum + Zeit
        return el.dataset.c9ExtType === 'time' ? 'time' : 'datetime';
    }

    function toPicker(value, mode) {
        value = String(value || '').trim();
        if (mode === 'time') {
            const t = value.match(TIME);
            return t ? t[1] : '';
        }
        const m = value.match(ISO);
        if (!m) return '';
        return mode === 'date' ? m[1] : m[1] + 'T' + (m[2] || '00:00');
    }

    function fromPicker(value, mode) {
        if (!value) return '';
        return mode === 'datetime' ? value.replace('T', ' ') : value;
    }

    cApp.extensions.register('calendar', {

        init(el, options) {
            const mode = modeFor(el, options);
            const picker = document.createElement('input');
            picker.type = mode === 'datetime' ? 'datetime-local' : mode;
            picker.className = 'c9-calendar-picker';
            picker.step = String(Math.max(1, parseInt(options.step || '1', 10)) * 60);
            picker.setAttribute('aria-label', el.getAttribute('aria-label') || '');
            picker.value = toPicker(el.value, mode);
            picker.addEventListener('change', () => {
                el.value = fromPicker(picker.value, mode);
                cApp.forms.markChanged(el);
            });
            el.addEventListener('change', () => { picker.value = toPicker(el.value, mode); });
            el.classList.add('c9-calendar-input');
            el.insertAdjacentElement('afterend', picker);
            el._c9Calendar = picker;
        },

        destroy(el) {
            if (el._c9Calendar) {
                el._c9Calendar.remove();
                delete el._c9Calendar;
            }
            el.classList.remove('c9-calendar-input');
        }
    });

}(window.cApp));
