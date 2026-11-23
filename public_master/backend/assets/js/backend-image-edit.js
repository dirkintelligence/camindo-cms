/*
 * camindo backend – image editing on the info page of a record (cApp.imageEdit)
 *
 * Layer of RecordBackendImageEdit (Record/record_image_edit.php), two modes:
 *
 *   edit  upload field: rotate clockwise, midtones, section with optional target
 *         size. Every step goes to the server (function=preview), which calculates
 *         it from the original; the image shows the result. "Apply" takes over the box:
 *         it is converted into fractions of the turned original (combined with a
 *         section already applied) and disappears.
 *   crop  thumbnail field: only the box, its aspect ratio is fixed (target size of
 *         the converter); it stays on the whole source image.
 *
 * While the layer is open the record is locked (like the input mask, without a working
 * copy): every request carries the id of the lock (data-lock); "Save" and "Cancel"
 * release it.
 *
 * Target size (edit): empty = any box; width OR height = target size of the result;
 * width AND height = fixed aspect ratio of the box. Only "Save" changes the file.
 *
 * The box: drag with the left mouse button, move inside, resize at the corners,
 * arrow keys move it (Shift: in larger steps). Coordinates are fractions (0…1)
 * of the displayed image.
 */
(function ($) {
    'use strict';

    const editors = new WeakMap();
    const MIN_SIZE = 0.01;

    /** Editor of an element in the layer (created on first use) */
    function editorOf(el) {
        const root = el && el.closest ? el.closest('.c9-imgedit') : null;
        if (!root) return null;
        return editors.get(root) || create(root);
    }

    function create(root) {
        let state = {};
        try { state = JSON.parse(root.dataset.state || '{}') || {}; } catch (e) { state = {}; }
        const ed = {
            root: root,
            mode: root.dataset.mode,
            img: root.querySelector('.c9-imgedit-stage img'),
            stage: root.querySelector('.c9-imgedit-stage'),
            selEl: root.querySelector('.c9-imgedit-sel'),
            state: state,
            fixedRatio: parseFloat(root.dataset.ratio) || 0,
            sel: null,       // {x, y, w, h} fractions of the displayed image
            auto: false,     // crop: automatic section (centered), stored as null
            drag: null
        };
        editors.set(root, ed);
        bind(ed);
        if (ed.mode === 'crop') {
            ed.auto = !state.crop;
            ed.sel = state.crop ? fitRatio(ed, state.crop) : autoBox(ed);
        }
        draw(ed);
        return ed;
    }

    // ---------------------------------------------------------------------------
    // Geometry
    // ---------------------------------------------------------------------------

    /** Aspect ratio of the displayed image (width / height) */
    function imageAspect(ed) {
        return (ed.img.naturalWidth || 1) / (ed.img.naturalHeight || 1);
    }

    /** Aspect ratio the box must have (pixels, width / height), 0 = free */
    function ratio(ed) {
        if (ed.mode === 'crop') return ed.fixedRatio;
        const w = parseInt($(ed.root).find('.c9-imgedit-width').val(), 10) || 0;
        const h = parseInt($(ed.root).find('.c9-imgedit-height').val(), 10) || 0;
        return (w > 0 && h > 0) ? w / h : 0;
    }

    /** The largest centered box with the aspect ratio */
    function autoBox(ed) {
        const r = ratio(ed);
        if (!r) return { x: 0, y: 0, w: 1, h: 1 };
        const a = imageAspect(ed);
        if (a > r) {
            const w = r / a;
            return { x: (1 - w) / 2, y: 0, w: w, h: 1 };
        }
        const h = a / r;
        return { x: 0, y: (1 - h) / 2, w: 1, h: h };
    }

    /** Bring a box to the aspect ratio: same area and center, inside the image */
    function fitRatio(ed, box) {
        const r = ratio(ed);
        if (!r || !box) return box;
        const a = imageAspect(ed);
        // in pixel units of a picture of height 1 and width a
        let pw = box.w * a, ph = box.h;
        const cx = (box.x + box.w / 2) * a, cy = box.y + box.h / 2;
        pw = Math.sqrt(pw * ph * r);
        ph = pw / r;
        if (pw > a) { pw = a; ph = pw / r; }
        if (ph > 1) { ph = 1; pw = ph * r; }
        const x = Math.min(Math.max(cx - pw / 2, 0), a - pw);
        const y = Math.min(Math.max(cy - ph / 2, 0), 1 - ph);
        return { x: x / a, y: y, w: pw / a, h: ph };
    }

    /**
     * Box between a fixed corner (ax, ay) and the pointer (px, py), with the aspect
     * ratio and inside the image.
     */
    function boxFrom(ed, ax, ay, px, py) {
        const a = imageAspect(ed);
        const r = ratio(ed);
        const dx = px >= ax ? 1 : -1, dy = py >= ay ? 1 : -1;
        let w = Math.abs(px - ax) * a, h = Math.abs(py - ay);
        const maxW = (dx > 0 ? 1 - ax : ax) * a, maxH = dy > 0 ? 1 - ay : ay;
        if (r) {
            if (w / Math.max(h, 1e-9) > r) w = h * r; else h = w / r;
            if (w > maxW) { w = maxW; h = w / r; }
            if (h > maxH) { h = maxH; w = h * r; }
        }
        else {
            w = Math.min(w, maxW);
            h = Math.min(h, maxH);
        }
        w /= a;
        return { x: dx > 0 ? ax : ax - w, y: dy > 0 ? ay : ay - h, w: w, h: h };
    }

    /** Pointer position as fractions of the image */
    function point(ed, event) {
        const rect = ed.img.getBoundingClientRect();
        return {
            x: Math.min(Math.max((event.clientX - rect.left) / rect.width, 0), 1),
            y: Math.min(Math.max((event.clientY - rect.top) / rect.height, 0), 1)
        };
    }

    // ---------------------------------------------------------------------------
    // Drawing
    // ---------------------------------------------------------------------------

    function draw(ed) {
        const $info = $(ed.root).find('.c9-imgedit-selinfo');
        if (!ed.sel) {
            ed.selEl.classList.add('d-none');
            $info.text('');
            return;
        }
        const s = ed.sel;
        Object.assign(ed.selEl.style, {
            left: (s.x * 100) + '%', top: (s.y * 100) + '%',
            width: (s.w * 100) + '%', height: (s.h * 100) + '%'
        });
        ed.selEl.classList.remove('d-none');
        // size of the box in pixels of the result shown
        const rw = parseInt(ed.root.dataset.resultWidth, 10) || 0;
        const rh = parseInt(ed.root.dataset.resultHeight, 10) || 0;
        const bw = Math.max(1, Math.round(s.w * rw)), bh = Math.max(1, Math.round(s.h * rh));
        let text = ' – ' + (ed.root.dataset.labelSelection || '') + ': ' + bw + ' × ' + bh + ' px';
        // crop: what the converter makes of the box
        const thumb = ed.mode === 'crop' ? thumbnailSize(ed, bw, bh) : null;
        if (thumb) text += ' – ' + (ed.root.dataset.labelThumbnail || '') + ': ' + thumb[0] + ' × ' + thumb[1] + ' px';
        $info.text(text);
    }

    /**
     * Size of the thumbnail from a box of bw x bh pixels - like the Magick converter
     * scales it (data-target: "width height resize_when max_pixels"; the box already has
     * the aspect ratio of the target size).
     */
    function thumbnailSize(ed, bw, bh) {
        const [tw, th, when, maxPixels] = (ed.root.dataset.target || '').split(' ');
        const w = parseInt(tw, 10) || 0, h = parseInt(th, 10) || 0, max = parseInt(maxPixels, 10) || 0;
        if (!w && !h) return null;
        const allowed = function (scale) {
            return when === 'shrink' ? scale < 1 : (when === 'enlarge' ? scale > 1 : true);
        };
        let scale = w && h ? Math.min(w / bw, h / bh) : (w ? w / bw : h / bh);
        if (!allowed(scale)) scale = 1;
        let rw = bw * scale, rh = bh * scale;
        if (max > 0) {
            const s2 = Math.sqrt(max / (rw * rh));
            if (allowed(s2)) { rw *= s2; rh *= s2; }
        }
        return [Math.max(1, Math.round(rw)), Math.max(1, Math.round(rh))];
    }

    // ---------------------------------------------------------------------------
    // Pointer
    // ---------------------------------------------------------------------------

    function bind(ed) {
        ed.stage.addEventListener('pointerdown', function (event) {
            if (event.button !== 0 || ed.busy) return;
            event.preventDefault();
            const p = point(ed, event);
            const handle = event.target.closest('.c9-imgedit-handle');
            const s = ed.sel;
            if (handle && s) {
                // resize: the opposite corner stays
                const h = handle.dataset.h;
                ed.drag = { type: 'new', ax: h.includes('w') ? s.x + s.w : s.x, ay: h.includes('n') ? s.y + s.h : s.y };
            }
            // inside the box: move it - unless it covers the whole image (nothing to
            // move, e.g. the automatic box with the aspect ratio of the image): new box
            else if (s && event.target.closest('.c9-imgedit-sel') && (s.w < 0.995 || s.h < 0.995)) {
                ed.drag = { type: 'move', px: p.x, py: p.y, sx: s.x, sy: s.y };
            }
            else {
                ed.drag = { type: 'new', ax: p.x, ay: p.y, fresh: true, prev: s };
            }
            ed.stage.setPointerCapture(event.pointerId);
        });
        ed.stage.addEventListener('pointermove', function (event) {
            const d = ed.drag;
            if (!d) return;
            const p = point(ed, event);
            if (d.type === 'move') {
                const s = ed.sel;
                s.x = Math.min(Math.max(d.sx + p.x - d.px, 0), 1 - s.w);
                s.y = Math.min(Math.max(d.sy + p.y - d.py, 0), 1 - s.h);
            }
            else {
                ed.sel = boxFrom(ed, d.ax, d.ay, p.x, p.y);
            }
            ed.auto = false;
            draw(ed);
        });
        const end = function () {
            const d = ed.drag;
            if (!d) return;
            ed.drag = null;
            // a click without dragging: no box (crop: the previous one stays)
            if (d.type === 'new' && (!ed.sel || ed.sel.w < MIN_SIZE || ed.sel.h < MIN_SIZE)) {
                ed.sel = ed.mode === 'crop' ? (d.prev || autoBox(ed)) : null;
            }
            draw(ed);
            if (ed.sel) ed.selEl.focus({ preventScroll: true });
        };
        ed.stage.addEventListener('pointerup', end);
        ed.stage.addEventListener('pointercancel', end);
    }

    // ---------------------------------------------------------------------------
    // Server
    // ---------------------------------------------------------------------------

    function post(ed, fn, edit) {
        const data = new FormData();
        data.append('function', fn);
        data.append('id', ed.root.dataset.id);
        data.append('field_id', ed.root.dataset.field);
        data.append('lang', ed.root.dataset.lang || '');
        data.append('lock', ed.root.dataset.lock || '');
        if (edit) data.append('edit', JSON.stringify(edit));
        const $spinner = cApp.system.addSpinner();
        ed.busy = true;
        return $.ajax({
            type: 'POST',
            url: cConfig.backend_path + 'Modules/Record/ajax_image_edit.php',
            data: data,
            processData: false,
            contentType: false,
            dataType: 'json',
            headers: cApp.system.csrfHeaders()
        }).always(function () {
            ed.busy = false;
            cApp.system.removeSpinner($spinner);
        }).fail(function () {
            cApp.system.balloon('danger', ed.root.dataset.msgError || 'Error');
        }).then(function (response) {
            if (!response || !response.ok) {
                cApp.system.balloon('danger', (response && response.error) || ed.root.dataset.msgError || 'Error');
                return $.Deferred().reject().promise();
            }
            return response;
        });
    }

    /** Calculate the preview anew from the original (edit) */
    function preview(ed) {
        post(ed, 'preview', ed.state).done(function (response) {
            ed.root.dataset.resultWidth = response.width;
            ed.root.dataset.resultHeight = response.height;
            $(ed.root).find('.c9-imgedit-result').text(response.width + ' × ' + response.height);
            ed.img.src = response.url;
        });
    }

    /** Take over the box: fractions of the turned original (edit) */
    function applySelection(ed) {
        const s = ed.sel, c = ed.state.crop;
        ed.state.crop = c
            ? { x: c.x + s.x * c.w, y: c.y + s.y * c.h, w: s.w * c.w, h: s.h * c.h }
            : { x: s.x, y: s.y, w: s.w, h: s.h };
        ed.state.width = parseInt($(ed.root).find('.c9-imgedit-width').val(), 10) || 0;
        ed.state.height = parseInt($(ed.root).find('.c9-imgedit-height').val(), 10) || 0;
        ed.sel = null;
    }

    // ---------------------------------------------------------------------------
    // Public
    // ---------------------------------------------------------------------------

    cApp.imageEdit = {

        /** The image (also a new preview) is loaded */
        loaded(img) {
            const ed = editorOf(img);
            if (ed) draw(ed);
        },

        /** Rotate clockwise by 90° - section and target size turn along */
        rotate(el) {
            const ed = editorOf(el);
            if (!ed || ed.busy) return;
            const st = ed.state;
            st.rotate = ((st.rotate || 0) + 90) % 360;
            if (st.crop) {
                const c = st.crop;
                st.crop = { x: 1 - c.y - c.h, y: c.x, w: c.h, h: c.w };
            }
            [st.width, st.height] = [st.height || 0, st.width || 0];
            const $w = $(ed.root).find('.c9-imgedit-width'), $h = $(ed.root).find('.c9-imgedit-height');
            const w = $w.val();
            $w.val($h.val());
            $h.val(w);
            ed.sel = null;
            draw(ed);
            preview(ed);
        },

        /** Midtones: show the value while dragging */
        gammaInput(el) {
            $(el).closest('.c9-imgedit').find('.c9-imgedit-gamma-value').text(el.value);
        },

        /** Midtones: slider released */
        gamma(el) {
            const ed = editorOf(el);
            if (!ed) return;
            ed.state.gamma = parseInt(el.value, 10) || 0;
            preview(ed);
        },

        /** Target size typed: adjust an open box to the aspect ratio */
        sizeInput(el) {
            const ed = editorOf(el);
            if (!ed || !ed.sel) return;
            ed.sel = fitRatio(ed, ed.sel);
            draw(ed);
        },

        /** "Apply": cut out the box (edit) */
        apply(el) {
            const ed = editorOf(el);
            if (!ed || ed.busy) return;
            if (!ed.sel) {
                cApp.system.balloon('warning', ed.root.dataset.msgSelect || '');
                return;
            }
            applySelection(ed);
            draw(ed);
            preview(ed);
        },

        /** "Reset": back to the original (edit) */
        reset(el) {
            const ed = editorOf(el);
            if (!ed || ed.busy) return;
            ed.state = { rotate: 0, gamma: 0, crop: null, width: 0, height: 0 };
            const $root = $(ed.root);
            $root.find('.c9-imgedit-width, .c9-imgedit-height').val('');
            $root.find('.c9-imgedit-gamma').val(0);
            $root.find('.c9-imgedit-gamma-value').text('0');
            ed.sel = null;
            draw(ed);
            preview(ed);
        },

        /** "Automatic": centered section (crop), stored as "no section" */
        auto(el) {
            const ed = editorOf(el);
            if (!ed) return;
            ed.sel = autoBox(ed);
            ed.auto = true;
            draw(ed);
        },

        /** Arrow keys move the box */
        key(el, event) {
            const ed = editorOf(el);
            if (!ed || !ed.sel) return;
            const step = event.shiftKey ? 0.05 : 0.005;
            const move = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[event.key];
            if (!move) return;
            event.preventDefault();
            const s = ed.sel;
            s.x = Math.min(Math.max(s.x + move[0], 0), 1 - s.w);
            s.y = Math.min(Math.max(s.y + move[1], 0), 1 - s.h);
            ed.auto = false;
            draw(ed);
        },

        /** "Save": calculate the file resp. store the section - then back to the info page */
        save(el) {
            const ed = editorOf(el);
            if (!ed || ed.busy) return;
            let edit;
            if (ed.mode === 'crop') {
                edit = { crop: ed.auto ? null : ed.sel };
            }
            else {
                // a box drawn but not applied counts as applied
                if (ed.sel) applySelection(ed);
                edit = ed.state;
            }
            post(ed, 'save', edit).done(function (response) {
                if (response.message) cApp.system.balloon('success', response.message);
                (response.warnings || []).forEach((msg) => cApp.system.balloon('warning', msg));
                cApp.layers.invalidate();
                cApp.layers.close();
            });
        },

        /** "Cancel": remove the temporary preview, close the layer */
        cancel(el) {
            const ed = editorOf(el);
            if (ed) post(ed, 'cancel');
            cApp.layers.close();
        }
    };

}(jQuery));
