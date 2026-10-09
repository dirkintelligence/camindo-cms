/*
 * Extension Calendar - handler for cApp.extensions (developer docs, chapter 08 (Components and extensions))
 *
 * Places a native picker (<input type="datetime-local|date|time">) next to the text field.
 * A selection in the picker writes the value in ISO format into the text field (the
 * backend understands ISO besides the national format); an ISO value in the text field is
 * taken over into the picker on opening. Options: mode (auto|datetime|date|time), step (minutes).
 */
(function (cApp) {
    'use strict';

    const ISO = /^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2})(?::\d{2})?)?$/;
    const TIME = /^(\d{2}:\d{2})(?::\d{2})?$/;

    function modeFor(el, options) {
        if (options.mode && options.mode !== 'auto') return options.mode;
        // Field type from data-c9-ext-type (Backend::formField): time -> time, otherwise date + time
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
