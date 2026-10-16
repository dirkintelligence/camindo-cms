/*
 * Extension HTMLVisual - edit HTML in the preview (handler for cApp.extensions)
 *
 * The same user interface as MarkdownVisual (markdownvisual.ext.js, copied from it):
 * the formatted view (contenteditable) is edited, the HTML source is shown by
 * the button <>. The field contains HTML - only the elements the editor
 * maps; the editor writes it back into the textarea on every change and before
 * submitting. No third-party library.
 *
 * STRUCTURE
 * --------------------
 *     htmlToBlocks(html)     any HTML (field, source, clipboard) ->
 *                            block list; inline parts internally as Markdown
 *     parseMarkdown(md)      Markdown -> block list (pasted plain text that looks like
 *                            Markdown, and the Markdown shortcuts while typing)
 *     renderBlocks(blocks)   block list -> HTML of the editor (only supported elements,
 *                            text escaped, link targets checked)
 *     domToBlocks(el)        DOM of the editor -> block list
 *     writeHtml(blocks)      block list -> HTML of the field (clean, indented)
 *     Editor                 user interface, commands, keyboard, paste, history
 *
 * The internal Markdown is only the exchange format between DOM and block list (as in
 * MarkdownVisual) - it is never saved. Pasted HTML runs through the same
 * block list - what it does not map (images, strikethrough, colors, fonts,
 * classes, attributes …) is dropped, the text remains. Only the HTML of the field
 * (and of the source view) keeps own attributes and images, see below.
 *
 * HTML IN THE FIELD
 * --------------------
 *     <p> <h1>…<h6> <ul>/<ol start> <li> <blockquote> <pre><code class="language-x">
 *     <hr> <table><thead><tbody><tr><th><td>  (first row = header row)
 *     <strong> <em> <u> <code> <a href> <br> <bdi dir="rtl|ltr">
 *     style="text-align: left|center|right" on paragraph, heading, list, quote,
 *     table and cell (column alignment)
 *     from the field / source view only: id, class, style, title on p, h1-h6, ul/ol,
 *     blockquote, pre, hr, table and on span, strong, em, u, code, a; <img> with
 *     src, alt, title, width, height, loading, srcset, sizes, id, class, style
 * Invisible special characters are entities in the HTML (&nbsp; &shy; &#8239; …); in the
 * editor they are marked, non-editable span.htv-ch. An &nbsp; set by the browser itself
 * (space at the end of a line), on the other hand, becomes a normal space.
 *
 * Options: toolbar (full|basic|custom), toolbar_custom, min_height, max_height (px),
 * stylesheet (file from var/extensions/htmlvisual/, delivered by
 * backendExtensions/HTMLVisual/css.php), css_class (default
 * c9-htmlvisual-{feldname}), texts (Camindo\Core\HtmlVisualOptions, snippets
 * ext.htmlvisual.ui_*; missing texts in English).
 *
 * Without the backend: C9HtmlVisual.attach(textarea, options) - window.C9HtmlVisual also
 * provides toHtml(element) (editor DOM -> HTML) and cleanHtml(html) (any HTML
 * trimmed to the supported elements).
 */
(function (window, document) {
    'use strict';

    // =====================================================================
    // Constants and small helpers
    // =====================================================================

    const ALIGNS = ['left', 'center', 'right'];

    // Invisible or confusable characters - marked in the editor
    const INVISIBLE = {
        ' ': 'nbsp',
        ' ': 'nnbsp',
        ' ': 'ensp',
        ' ': 'thinsp',
        '­': 'shy',
        '‑': 'nbhy'
    };
    const INVISIBLE_RE = /[    ­‑]/g;
    const ZW_RE = /[​﻿]/g;

    // Writing direction (Unicode isolates): right to left, left to right, end
    const RLI = '\u2067';
    const LRI = '\u2066';
    const PDI = '\u2069';

    const ASCII_PUNCT = /[!-\/:-@\[-`{-~]/;
    const PUNCT = /[!-\/:-@\[-`{-~\p{P}\p{S}]/u;
    const ENTITY_RE = /^&(#[0-9]{1,7}|#[xX][0-9a-fA-F]{1,6}|[a-zA-Z][a-zA-Z0-9]{1,31});/;

    const BLOCK_TAGS = /^(ADDRESS|ARTICLE|ASIDE|BLOCKQUOTE|BODY|CAPTION|CENTER|DD|DETAILS|DIALOG|DIV|DL|DT|FIELDSET|FIGCAPTION|FIGURE|FOOTER|FORM|H[1-6]|HEADER|HGROUP|HR|HTML|LI|MAIN|NAV|OL|P|PRE|SECTION|SUMMARY|TABLE|TBODY|TD|TFOOT|TH|THEAD|TR|UL)$/;
    const SKIP_TAGS = /^(AREA|AUDIO|BUTTON|CANVAS|DATALIST|EMBED|HEAD|IFRAME|IMG|INPUT|LINK|MAP|META|METER|NOSCRIPT|OBJECT|OPTION|OUTPUT|PICTURE|PROGRESS|SCRIPT|SELECT|SOURCE|STYLE|SVG|TEMPLATE|TEXTAREA|TITLE|TRACK|VIDEO)$/;
    const BLOCK_SELECTOR = 'address,article,aside,blockquote,dd,div,dl,dt,figure,footer,form,h1,h2,h3,h4,h5,h6,header,hr,li,main,nav,ol,p,pre,section,table,ul';

    // Text blocks of the editor: that is where the caret is
    const TEXTBLOCK = /^(P|DIV|H[1-6]|LI|PRE|TD|TH)$/;

    function tagOf(n) {
        return n.nodeName.toUpperCase();
    }

    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // Text for the editor: escaped, invisible characters marked
    function escText(s) {
        return esc(s).replace(INVISIBLE_RE, (c) => specialHtml(c));
    }

    function specialHtml(c) {
        return '<span class="htv-ch" data-ch="' + INVISIBLE[c] + '" contenteditable="false">' + c + '</span>';
    }

    let entityBox = null;
    function decodeEntity(entity) {
        // innerHTML of a textarea: entities are resolved, tags remain text
        if (!entityBox) entityBox = document.createElement('textarea');
        entityBox.innerHTML = entity;
        const value = entityBox.value;
        return value === entity ? null : value;
    }

    // Link targets: http(s), mailto, tel, ftp and relative addresses - otherwise ''
    function safeUrl(url) {
        url = String(url || '').trim();
        if (url === '' || /[\u0000-\u001F\u007F]/.test(url)) return '';
        const scheme = url.match(/^([a-zA-Z][a-zA-Z0-9+.-]*):/);
        if (scheme && !/^(https?|mailto|tel|ftp)$/i.test(scheme[1])) return '';
        return url;
    }

    // Image sources: http(s), relative addresses and data:image/… - otherwise ''
    function safeSrc(url) {
        url = String(url || '').trim();
        if (/[\u0000-\u001F\u007F]/.test(url)) return '';
        if (/^data:image\/(png|gif|jpe?g|webp|avif|bmp)[;,]/i.test(url)) return url;
        const scheme = url.match(/^([a-zA-Z][a-zA-Z0-9+.-]*):/);
        return scheme && !/^https?$/i.test(scheme[1]) ? '' : url;
    }

    // ---- Own attributes (only from the HTML of the field, i.e. the source view) ----
    //
    // id, class, style and title on blocks and inline elements, <img> with its
    // attributes. Pasted HTML loses them. In the editor the element carries them
    // as real attributes (preview with the stylesheet; id only in the JSON) and as
    // JSON in data-htv-a - only the JSON counts when reading, the browser changes
    // style attributes itself. Inline they travel through the internal Markdown as
    // EL_OPEN id EL_MID … EL_CLOSE and EL_IMG id EL_MID (entries in elRegistry).

    const OWN_ATTRS = ['id', 'class', 'style', 'title'];
    const IMG_ATTRS = ['src', 'alt', 'title', 'width', 'height', 'loading', 'srcset', 'sizes', 'id', 'class', 'style'];
    // Inline elements that keep own attributes (b/i/ins as strong/em/u)
    const ATTR_INLINE = { SPAN: 'span', STRONG: 'strong', B: 'strong', EM: 'em', I: 'em', U: 'u', INS: 'u', CODE: 'code', A: 'a' };
    const EL_OPEN = '';
    const EL_MID = '';
    const EL_CLOSE = '';
    const EL_IMG = '';
    const EL_RE = /[-]/g;
    const elRegistry = [];
    const elIds = new Map();

    function elRef(entry) {
        const key = JSON.stringify(entry);
        let id = elIds.get(key);
        if (id === undefined) {
            id = String(elRegistry.length);
            elRegistry.push(entry);
            elIds.set(key, id);
        }
        return id;
    }

    function cleanAttrs(src, names, block) {
        const out = {};
        names.forEach((k) => {
            let v = src[k];
            if (typeof v !== 'string') return;
            v = v.replace(/[\u0000-\u001F\u007F]+/g, ' ').trim();
            if (k === 'class') v = v.split(/\s+/).filter((c) => c && !/^(c9-)?htv-/.test(c)).join(' ');
            // the alignment of a block is its own setting (data-align)
            else if (k === 'style' && block) v = v.replace(/(^|;)\s*text-align\s*:[^;]*/gi, '$1').replace(/;(\s*;)+/g, ';').replace(/^\s*;\s*/, '').trim();
            else if (k === 'src') v = safeSrc(v);
            else if (k === 'srcset' && v.split(',').some((c) => c.trim() !== '' && !safeSrc(c.trim().split(/\s+/)[0]))) v = '';
            if (v !== '' || (k === 'alt' && names === IMG_ATTRS)) out[k] = v;
        });
        return out;
    }

    // Own attributes of el or null; block: element of a block (text-align is dropped)
    function ownAttrs(el, names, block) {
        let src;
        const data = el.getAttribute('data-htv-a');
        if (data !== null) {
            try { src = JSON.parse(data); } catch (e) { src = null; }
            if (!src || typeof src !== 'object') src = {};
        }
        else if (ownHtml) {
            src = {};
            names.forEach((k) => { if (el.hasAttribute(k)) src[k] = el.getAttribute(k); });
        }
        else return null;
        const out = cleanAttrs(src, names, block);
        return Object.keys(out).length ? out : null;
    }

    // Attributes for the editor HTML
    function attrsHtml(attrs) {
        if (!attrs) return '';
        return Object.keys(attrs).filter((k) => k !== 'id').map((k) => ' ' + k + '="' + esc(attrs[k]) + '"').join('')
            + ' data-htv-a="' + esc(JSON.stringify(attrs)) + '"';
    }

    function isWS(c) {
        return c === undefined || /\s/.test(c);
    }

    function isPunct(c) {
        return c !== undefined && PUNCT.test(c);
    }

    // =====================================================================
    // Markdown -> block list
    //
    // Blocks: {t:'p', inl}, {t:'h', level, inl}, {t:'hr'}, {t:'code', lang, text},
    // {t:'quote', children}, {t:'list', ordered, start, items:[{children}]},
    // {t:'table', aligns, head:[inl], rows:[[inl]]}; each block optionally with align.
    // =====================================================================

    function splitLines(md) {
        return String(md || '').replace(/\r\n?/g, '\n').split('\n');
    }

    function isBlank(line) {
        return /^[ \t]*$/.test(line);
    }

    // Indentation in columns (tab = up to the next multiple of four)
    function indentWidth(line) {
        let w = 0;
        for (const c of line) {
            if (c === ' ') w++;
            else if (c === '\t') w += 4 - (w % 4);
            else break;
        }
        return w;
    }

    // Remove n columns of indentation
    function stripIndent(line, n) {
        let w = 0;
        let i = 0;
        while (i < line.length && w < n) {
            const c = line[i];
            if (c === ' ') { w++; i++; }
            else if (c === '\t') {
                const tw = 4 - (w % 4);
                if (w + tw > n) return ' '.repeat(w + tw - n) + line.slice(i + 1);
                w += tw;
                i++;
            }
            else break;
        }
        return line.slice(i);
    }

    function isHr(s) {
        return /^([-*_])([ \t]*\1){2,}[ \t]*$/.test(s);
    }

    // Does the line start a block that interrupts a paragraph?
    function startsBlock(line) {
        if (indentWidth(line) > 3) return false;
        const s = line.replace(/^[ \t]+/, '');
        return /^#{1,6}([ \t]|$)/.test(s) || s[0] === '>' || /^(`{3,}|~{3,})/.test(s) || isHr(s)
            || /^[-*+][ \t]+\S/.test(s) || /^1[.)][ \t]+\S/.test(s) || /^:::[ \t]*[A-Za-z-]+[ \t]*$/.test(line);
    }

    function listMarker(line) {
        const ind = indentWidth(line);
        if (ind > 3) return null;
        const s = line.replace(/^[ \t]+/, '');
        const m = s.match(/^([-*+]|(\d{1,9})([.)]))([ \t]+|$)/);
        if (!m) return null;
        let rest = s.slice(m[0].length);
        let spaces = m[4].length;
        if (rest === '') spaces = 1;
        else if (spaces > 4) { spaces = 1; rest = s.slice(m[1].length + 1); }
        return {
            ordered: !!m[2],
            start: m[2] ? parseInt(m[2], 10) : 1,
            bullet: m[2] ? m[3] : m[1],
            contentIndent: ind + m[1].length + spaces,
            first: rest
        };
    }

    function splitRow(line) {
        let s = line.trim();
        if (s[0] === '|') s = s.slice(1);
        if (s.endsWith('|') && !s.endsWith('\\|')) s = s.slice(0, -1);
        const cells = [];
        let cur = '';
        for (let k = 0; k < s.length; k++) {
            const c = s[k];
            if (c === '\\' && s[k + 1] === '|') { cur += '\\|'; k++; continue; }
            if (c === '|') { cells.push(cur.trim()); cur = ''; continue; }
            cur += c;
        }
        cells.push(cur.trim());
        return cells;
    }

    function isDelimRow(line) {
        if (!line || !line.includes('|') || !line.includes('-')) return false;
        return splitRow(line).every((c) => /^:?-+:?$/.test(c));
    }

    function parseTable(lines, i) {
        const head = splitRow(lines[i]);
        const delims = splitRow(lines[i + 1]);
        if (head.length !== delims.length) return null;
        const aligns = delims.map((d) => {
            const l = d[0] === ':';
            const r = d[d.length - 1] === ':';
            return l && r ? 'center' : (r ? 'right' : (l ? 'left' : ''));
        });
        const rows = [];
        i += 2;
        while (i < lines.length && !isBlank(lines[i]) && !startsBlock(lines[i])) {
            const cells = splitRow(lines[i]).slice(0, head.length);
            while (cells.length < head.length) cells.push('');
            rows.push(cells);
            i++;
        }
        return { block: { t: 'table', aligns: aligns, head: head, rows: rows }, next: i };
    }

    function parseList(lines, i) {
        const first = listMarker(lines[i]);
        const block = { t: 'list', ordered: first.ordered, start: first.start, items: [] };
        while (i < lines.length) {
            const mk = listMarker(lines[i]);
            if (!mk || mk.ordered !== first.ordered || mk.bullet !== first.bullet) break;
            const body = [mk.first];
            i++;
            let blank = false;
            while (i < lines.length) {
                const l = lines[i];
                if (isBlank(l)) { body.push(''); blank = true; i++; continue; }
                if (indentWidth(l) >= mk.contentIndent) { body.push(stripIndent(l, mk.contentIndent)); blank = false; i++; continue; }
                // Continuation line without indentation ("lazy")
                if (!blank && !listMarker(l) && !startsBlock(l)) { body.push(l.replace(/^[ \t]+/, '')); i++; continue; }
                break;
            }
            while (body.length && body[body.length - 1] === '') body.pop();
            block.items.push({ children: parseBlocks(body) });
        }
        return { block: block, next: i };
    }

    function parseBlocks(lines) {
        const blocks = [];
        let i = 0;
        while (i < lines.length) {
            const line = lines[i];
            if (isBlank(line)) { i++; continue; }
            const ind = indentWidth(line);
            const s = line.replace(/^[ \t]+/, '');
            let m;
            if (ind >= 4) {
                // Indented code
                const body = [];
                while (i < lines.length && (indentWidth(lines[i]) >= 4 || isBlank(lines[i]))) {
                    body.push(stripIndent(lines[i], 4));
                    i++;
                }
                while (body.length && isBlank(body[body.length - 1])) body.pop();
                blocks.push({ t: 'code', lang: '', text: body.join('\n') });
                continue;
            }
            if ((m = s.match(/^(`{3,}|~{3,})[ \t]*([^`\s]*)[^`]*$/))) {
                const fence = m[1];
                const close = new RegExp('^[ \\t]{0,3}' + (fence[0] === '`' ? '`' : '~') + '{' + fence.length + ',}[ \\t]*$');
                const body = [];
                i++;
                while (i < lines.length && !close.test(lines[i])) { body.push(stripIndent(lines[i], ind)); i++; }
                i++;
                blocks.push({ t: 'code', lang: m[2], text: body.join('\n') });
                continue;
            }
            if ((m = line.match(/^:::[ \t]*([A-Za-z-]+)[ \t]*$/))) {
                const word = m[1].toLowerCase();
                const body = [];
                let depth = 0;
                i++;
                while (i < lines.length) {
                    const mm = lines[i].match(/^:::[ \t]*([A-Za-z-]*)[ \t]*$/);
                    if (mm) {
                        if (mm[1]) depth++;
                        else if (depth) depth--;
                        else break;
                    }
                    body.push(lines[i]);
                    i++;
                }
                i++;
                const align = ALIGNS.includes(word) ? word : '';
                parseBlocks(body).forEach((b) => {
                    if (!b.align && align && b.t !== 'hr' && b.t !== 'code') b.align = align;
                    blocks.push(b);
                });
                continue;
            }
            if ((m = s.match(/^(#{1,6})(?:[ \t]+(.*?))?[ \t]*$/))) {
                const text = (m[2] || '').replace(/(^|[ \t]+)#+[ \t]*$/, '');
                blocks.push({ t: 'h', level: m[1].length, inl: text });
                i++;
                continue;
            }
            if (isHr(s)) { blocks.push({ t: 'hr' }); i++; continue; }
            if (s[0] === '>') {
                const body = [];
                while (i < lines.length) {
                    const l = lines[i];
                    const ls = l.replace(/^[ \t]+/, '');
                    if (indentWidth(l) <= 3 && ls[0] === '>') { body.push(ls.slice(1).replace(/^ /, '')); i++; }
                    else if (!isBlank(l) && body.length && !isBlank(body[body.length - 1]) && !startsBlock(l)) { body.push(l); i++; }
                    else break;
                }
                blocks.push({ t: 'quote', children: parseBlocks(body) });
                continue;
            }
            if (listMarker(line)) {
                const r = parseList(lines, i);
                blocks.push(r.block);
                i = r.next;
                continue;
            }
            if (s.includes('|') && isDelimRow(lines[i + 1])) {
                const r = parseTable(lines, i);
                if (r) { blocks.push(r.block); i = r.next; continue; }
            }
            // Paragraph, possibly setext heading
            const para = [s];
            let level = 0;
            i++;
            while (i < lines.length) {
                const l = lines[i];
                if (isBlank(l)) break;
                const ls = l.replace(/^[ \t]+/, '');
                if (indentWidth(l) <= 3 && /^=+[ \t]*$/.test(ls)) { level = 1; i++; break; }
                if (indentWidth(l) <= 3 && /^-+[ \t]*$/.test(ls)) { level = 2; i++; break; }
                if (startsBlock(l)) break;
                if (ls.includes('|') && isDelimRow(lines[i + 1])) break;
                para.push(ls);
                i++;
            }
            const inl = para.join('\n').replace(/[ \t]+$/, '');
            blocks.push(level ? { t: 'h', level: level, inl: inl.replace(/\n/g, ' ') } : { t: 'p', inl: inl });
        }
        return blocks;
    }

    function parseMarkdown(md) {
        return parseBlocks(splitLines(md));
    }

    // =====================================================================
    // Inline Markdown -> HTML
    // =====================================================================

    function findBacktickRun(src, from, run) {
        let k = from;
        while ((k = src.indexOf('`', k)) >= 0) {
            let e = k;
            while (src[e] === '`') e++;
            if (e - k === run) return k;
            k = e;
        }
        return -1;
    }

    // [Text](target "title") starting at src[i] === '['
    function parseLinkAt(src, i) {
        const n = src.length;
        let depth = 0;
        let j = i;
        for (; j < n; j++) {
            const c = src[j];
            if (c === '\\') { j++; continue; }
            if (c === '`') {
                let k = j;
                while (src[k] === '`') k++;
                const close = findBacktickRun(src, k, k - j);
                if (close >= 0) j = close + (k - j) - 1;
                else j = k - 1;
                continue;
            }
            if (c === '[') depth++;
            else if (c === ']') { depth--; if (depth === 0) break; }
        }
        if (j >= n || src[j + 1] !== '(') return null;
        let k = j + 2;
        while (src[k] === ' ' || src[k] === '\n') k++;
        let dest = '';
        if (src[k] === '<') {
            const e = src.indexOf('>', k);
            if (e < 0 || src.slice(k, e).includes('\n')) return null;
            dest = src.slice(k + 1, e);
            k = e + 1;
        }
        else {
            let par = 0;
            const s0 = k;
            while (k < n) {
                const c = src[k];
                if (c === '\\' && k + 1 < n) { k += 2; continue; }
                if (/\s/.test(c)) break;
                if (c === '(') par++;
                else if (c === ')') { if (par === 0) break; par--; }
                k++;
            }
            dest = src.slice(s0, k);
        }
        while (src[k] === ' ' || src[k] === '\n') k++;
        if (src[k] === '"' || src[k] === "'" || src[k] === '(') {
            const close = src[k] === '(' ? ')' : src[k];
            const e = src.indexOf(close, k + 1);
            if (e < 0) return null;
            k = e + 1;
            while (src[k] === ' ' || src[k] === '\n') k++;
        }
        if (src[k] !== ')') return null;
        return { text: src.slice(i + 1, j), dest: dest.replace(/\\([!-\/:-@\[-`{-~])/g, '$1'), end: k + 1 };
    }

    const INLINE_TAGS = { u: 'u', ins: 'u', b: 'strong', strong: 'strong', i: 'em', em: 'em' };

    function tokenizeInline(src) {
        const nodes = [];
        let text = '';
        const flush = () => { if (text) { nodes.push({ type: 'text', v: text }); text = ''; } };
        const push = (node) => { flush(); nodes.push(node); };
        const n = src.length;
        let i = 0;
        while (i < n) {
            const c = src[i];
            if (c === '\\') {
                const d = src[i + 1];
                if (d === '\n') {
                    text = text.replace(/ +$/, '');
                    push({ type: 'br' });
                    i += 2;
                    while (src[i] === ' ' || src[i] === '\t') i++;
                    continue;
                }
                if (d !== undefined && ASCII_PUNCT.test(d)) { text += d; i += 2; continue; }
                text += c;
                i++;
                continue;
            }
            if (c === '`') {
                let k = i;
                while (src[k] === '`') k++;
                const run = k - i;
                const close = findBacktickRun(src, k, run);
                if (close < 0) { text += src.slice(i, k); i = k; continue; }
                let code = src.slice(k, close).replace(/\n/g, ' ');
                if (code.length > 1 && code[0] === ' ' && code[code.length - 1] === ' ' && code.trim() !== '') code = code.slice(1, -1);
                push({ type: 'code', v: code });
                i = close + run;
                continue;
            }
            if (c === '!' && src[i + 1] === '[') {
                // Images are not supported - they are dropped entirely
                const link = parseLinkAt(src, i + 1);
                if (link) { i = link.end; continue; }
                text += c;
                i++;
                continue;
            }
            if (c === '[') {
                const link = parseLinkAt(src, i);
                if (link) {
                    const inner = tokenizeInline(link.text);
                    pairIsolates(inner);
                    processEmphasis(inner);
                    push({ type: 'link', href: safeUrl(link.dest), children: inner });
                    i = link.end;
                    continue;
                }
                text += c;
                i++;
                continue;
            }
            if (c === '<') {
                const rest = src.slice(i, i + 2048);
                let m;
                if ((m = rest.match(/^<([a-zA-Z][a-zA-Z0-9+.-]{1,31}:[^\s<>]*)>/))) {
                    push({ type: 'link', href: safeUrl(m[1]), children: [{ type: 'text', v: m[1] }] });
                    i += m[0].length;
                    continue;
                }
                if ((m = rest.match(/^<([^\s@<>\\]+@[^\s@<>\\]+\.[^\s@<>\\]+)>/))) {
                    push({ type: 'link', href: 'mailto:' + m[1], children: [{ type: 'text', v: m[1] }] });
                    i += m[0].length;
                    continue;
                }
                if ((m = rest.match(/^<br[ \t]*\/?>/i))) { push({ type: 'br' }); i += m[0].length; continue; }
                if ((m = rest.match(/^<(\/?)(u|ins|b|strong|i|em)[ \t]*>/i))) {
                    push({ type: 'delim', ch: 'tag-' + INLINE_TAGS[m[2].toLowerCase()], len: 1, orig: 1, canOpen: !m[1], canClose: !!m[1], html: true });
                    i += m[0].length;
                    continue;
                }
                // other HTML is dropped, the text in between remains
                if ((m = rest.match(/^<!--[\s\S]*?-->/)) || (m = rest.match(/^<\/?[a-zA-Z][a-zA-Z0-9-]*(?:\s[^<>]*)?\/?>/))) {
                    i += m[0].length;
                    continue;
                }
                text += c;
                i++;
                continue;
            }
            if (c === RLI || c === LRI || c === PDI || c === EL_CLOSE) {
                push({ type: 'iso', ch: c });
                i++;
                continue;
            }
            if (c === EL_OPEN || c === EL_IMG) {
                const e = src.indexOf(EL_MID, i + 1);
                const id = e > i ? src.slice(i + 1, e) : '';
                if (/^\d+$/.test(id) && elRegistry[+id]) {
                    push(c === EL_IMG ? { type: 'img', id: +id } : { type: 'iso', ch: c, id: +id });
                    i = e + 1;
                    continue;
                }
                i++;
                continue;
            }
            if (c === EL_MID) { i++; continue; }
            if (c === '&') {
                const m = src.slice(i, i + 40).match(ENTITY_RE);
                if (m) {
                    const v = decodeEntity(m[0]);
                    // also written as &#x2067; … &#x2069;
                    if (v === RLI || v === LRI || v === PDI) { push({ type: 'iso', ch: v }); i += m[0].length; continue; }
                    if (v !== null) { text += v; i += m[0].length; continue; }
                }
                text += c;
                i++;
                continue;
            }
            if (c === '*' || c === '_' || c === '+' || c === '~') {
                let k = i;
                while (src[k] === c) k++;
                const len = k - i;
                if ((c === '+' || c === '~') && len < 2) { text += c; i = k; continue; }
                const before = i > 0 ? src[i - 1] : undefined;
                const after = src[k];
                const left = !isWS(after) && (!isPunct(after) || isWS(before) || isPunct(before));
                const right = !isWS(before) && (!isPunct(before) || isWS(after) || isPunct(after));
                let canOpen = left;
                let canClose = right;
                if (c === '_') {
                    canOpen = left && (!right || isPunct(before));
                    canClose = right && (!left || isPunct(after));
                }
                push({ type: 'delim', ch: c, len: len, orig: len, canOpen: canOpen, canClose: canClose });
                i = k;
                continue;
            }
            if ((c === 'h' || c === 'H') && !/[A-Za-z0-9]/.test(src[i - 1] || '')) {
                const m = src.slice(i, i + 2048).match(/^https?:\/\/[^\s<]+/i);
                if (m) {
                    const url = m[0].replace(/[.,:;!?'")\]*_~]+$/, '');
                    if (url.length > 8) {
                        push({ type: 'link', href: safeUrl(url), children: [{ type: 'text', v: url }] });
                        i += url.length;
                        continue;
                    }
                }
            }
            if (c === '\n') {
                if (/ {2,}$/.test(text)) { text = text.replace(/ +$/, ''); push({ type: 'br' }); }
                else text = text.replace(/ +$/, '') + ' ';
                i++;
                while (src[i] === ' ' || src[i] === '\t') i++;
                continue;
            }
            text += c;
            i++;
        }
        flush();
        return nodes;
    }

    // Pair isolates: RLI/LRI … PDI -> bdi[dir], EL_OPEN … EL_CLOSE -> element with own
    // attributes, each with their own emphasis inside (it does not reach across the
    // boundary); an isolate without counterpart remains text, an element marker is dropped
    function pairIsolates(nodes) {
        const open = [];
        const asText = (n) => ({ type: 'text', v: n.ch === EL_OPEN || n.ch === EL_CLOSE ? '' : n.ch });
        for (let k = 0; k < nodes.length; k++) {
            const n = nodes[k];
            if (n.type !== 'iso') continue;
            if (n.ch !== PDI && n.ch !== EL_CLOSE) { open.push(k); continue; }
            if (!open.length || (nodes[open[open.length - 1]].ch === EL_OPEN) !== (n.ch === EL_CLOSE)) { nodes[k] = asText(n); continue; }
            const o = open.pop();
            const children = nodes.slice(o + 1, k);
            processEmphasis(children);
            nodes.splice(o, k - o + 1, nodes[o].ch === EL_OPEN
                ? { type: 'elem', el: nodes[o].id, children: children }
                : { type: 'elem', tag: 'bdi', dir: nodes[o].ch === RLI ? 'rtl' : 'ltr', children: children });
            k = o;
        }
        open.forEach((k) => { nodes[k] = asText(nodes[k]); });
    }

    // Pair emphasis (simplified CommonMark algorithm)
    function processEmphasis(nodes) {
        let ci = 0;
        while (ci < nodes.length) {
            const closer = nodes[ci];
            if (closer.type !== 'delim' || !closer.canClose || closer.len === 0) { ci++; continue; }
            let found = -1;
            for (let oi = ci - 1; oi >= 0; oi--) {
                const op = nodes[oi];
                if (op.type !== 'delim' || op.ch !== closer.ch || !op.canOpen || op.len === 0) continue;
                if (closer.ch === '*' || closer.ch === '_') {
                    if ((op.canClose || closer.canOpen) && (op.orig + closer.orig) % 3 === 0
                        && !(op.orig % 3 === 0 && closer.orig % 3 === 0)) continue;
                }
                else if (!closer.html && (op.len < 2 || closer.len < 2)) continue;
                found = oi;
                break;
            }
            if (found < 0) { ci++; continue; }
            const op = nodes[found];
            let use = 2;
            let tag = '';
            if (closer.html) { use = 1; tag = closer.ch.slice(4); }
            else if (closer.ch === '*' || closer.ch === '_') { use = op.len >= 2 && closer.len >= 2 ? 2 : 1; tag = use === 2 ? 'strong' : 'em'; }
            else if (closer.ch === '+') tag = 'u';
            // '~~' (strikethrough) is not supported: text without format
            const el = { type: 'elem', tag: tag, children: nodes.slice(found + 1, ci) };
            op.len -= use;
            closer.len -= use;
            nodes.splice(found + 1, ci - found - 1, el);
            ci = found + 2;
            if (op.len === 0) { nodes.splice(found, 1); ci--; }
            if (closer.len === 0) nodes.splice(ci, 1);
        }
    }

    function renderInlineNodes(nodes, inLink) {
        let out = '';
        nodes.forEach((n) => {
            switch (n.type) {
                case 'text': out += escText(n.v); break;
                case 'code': out += '<code>' + esc(n.v) + '</code>'; break;
                case 'br': out += '<br>'; break;
                case 'delim': if (!n.html) out += escText(n.ch.repeat(n.len)); break;
                case 'link':
                    out += inLink || !n.href
                        ? renderInlineNodes(n.children, inLink)
                        : '<a href="' + esc(n.href) + '">' + renderInlineNodes(n.children, true) + '</a>';
                    break;
                case 'img': {
                    const e = elRegistry[n.id];
                    if (e && e.tag === 'img') out += '<img' + attrsHtml(e.attrs) + '>';
                    break;
                }
                case 'elem':
                    if (n.el !== undefined) {
                        const e = elRegistry[n.el];
                        const tag = e.tag === 'a' && (inLink || !e.href) ? 'span' : e.tag;
                        out += '<' + tag + (tag === 'a' ? ' href="' + esc(e.href) + '"' : '') + attrsHtml(e.attrs) + '>'
                            + renderInlineNodes(n.children, inLink || tag === 'a') + '</' + tag + '>';
                        break;
                    }
                    out += n.tag
                        ? '<' + n.tag + (n.dir ? ' dir="' + n.dir + '"' : '') + '>' + renderInlineNodes(n.children, inLink) + '</' + n.tag + '>'
                        : renderInlineNodes(n.children, inLink);
                    break;
            }
        });
        return out;
    }

    function parseInline(src) {
        const nodes = tokenizeInline(String(src || ''));
        pairIsolates(nodes);
        processEmphasis(nodes);
        return renderInlineNodes(nodes, false).replace(/^(?:<br>)+|(?:<br>)+$/g, '');
    }

    // =====================================================================
    // Block list -> HTML of the editor
    // =====================================================================

    function alignAttr(a) {
        return a ? ' data-align="' + a + '"' : '';
    }

    function renderBlocks(blocks) {
        return blocks.map(renderBlock).join('');
    }

    function renderBlock(b) {
        const al = alignAttr(b.align) + attrsHtml(b.attrs);
        switch (b.t) {
            case 'p':
                return '<p' + al + '>' + (parseInline(b.inl) || '<br>') + '</p>';
            case 'h':
                return '<h' + b.level + al + '>' + (parseInline(b.inl) || '<br>') + '</h' + b.level + '>';
            case 'hr':
                return '<hr' + attrsHtml(b.attrs) + '>';
            case 'code':
                return '<pre' + (b.lang ? ' data-lang="' + esc(b.lang) + '"' : '') + attrsHtml(b.attrs) + '>' + esc(b.text)
                    + (b.text === '' || b.text.endsWith('\n') ? '<br>' : '') + '</pre>';
            case 'quote':
                return '<blockquote' + al + '>' + (renderBlocks(b.children) || '<p><br></p>') + '</blockquote>';
            case 'list': {
                const tag = b.ordered ? 'ol' : 'ul';
                const start = b.ordered && b.start !== 1 ? ' start="' + b.start + '"' : '';
                return '<' + tag + start + al + '>' + b.items.map((it) => '<li>' + renderItem(it) + '</li>').join('') + '</' + tag + '>';
            }
            case 'table': {
                const cell = (tag, inl, k) => '<' + tag + alignAttr(b.aligns[k]) + '>'
                    + (parseInline(String(inl).replace(/\\\|/g, '|')) || '<br>') + '</' + tag + '>';
                let html = '<table' + al + '><thead><tr>' + b.head.map((c, k) => cell('th', c, k)).join('') + '</tr></thead>';
                if (b.rows.length) {
                    html += '<tbody>' + b.rows.map((r) => '<tr>' + b.head.map((x, k) => cell('td', r[k] || '', k)).join('') + '</tr>').join('') + '</tbody>';
                }
                return html + '</table>';
            }
        }
        return '';
    }

    // List item: inline content, sub-lists; other blocks become text
    function renderItem(it) {
        const parts = [];
        let inline = [];
        const flushInline = () => { if (inline.length) { parts.push(inline.join('<br>')); inline = []; } };
        it.children.forEach((c) => {
            if (c.t === 'list') { flushInline(); parts.push(renderBlock(c)); }
            else inline.push(...flattenInline(c).filter((s) => s !== ''));
        });
        flushInline();
        return parts.join('') || '<br>';
    }

    function flattenInline(b) {
        switch (b.t) {
            case 'p':
            case 'h': return [parseInline(b.inl)];
            case 'code': return b.text.split('\n').filter((l) => l.trim() !== '').map((l) => '<code>' + esc(l) + '</code>');
            case 'quote': return b.children.flatMap(flattenInline);
            case 'list': return b.items.flatMap((it) => it.children.flatMap(flattenInline));
            case 'table': return [b.head].concat(b.rows).map((r) => r.map((c) => parseInline(String(c).replace(/\\\|/g, '|'))).join(' | '));
        }
        return [];
    }

    // =====================================================================
    // DOM -> block list (editor and pasted HTML)
    // =====================================================================

    // true while the HTML of the field is being read (htmlToBlocks(html, true)) - there
    // "left" is a chosen alignment (e.g. in a right-to-left field)
    let ownHtml = false;

    function alignOf(el) {
        const data = (el.getAttribute('data-align') || '').toLowerCase();
        if (ALIGNS.includes(data)) return data;
        let a = (el.getAttribute('align') || (el.style && el.style.textAlign) || '').toLowerCase();
        if (a === 'middle') a = 'center';
        if (ownHtml && a === 'left') return a;
        // "left" from foreign HTML is the default - no alignment of its own
        return a === 'center' || a === 'right' ? a : '';
    }

    function hasBlockDescendant(el) {
        return !!(el.querySelector && el.querySelector(BLOCK_SELECTOR));
    }

    function codeLang(pre) {
        const data = pre.getAttribute('data-lang');
        if (data) return data.replace(/[^\w+#.-]/g, '');
        const code = pre.querySelector('code');
        const m = ((pre.className || '') + ' ' + ((code && code.className) || '')).match(/(?:^|\s)(?:language|lang)-([\w+#.-]+)/);
        return m ? m[1] : '';
    }

    // Text of a code block; a <br> as the last child is the editor's placeholder
    function preText(pre) {
        return nodeText(pre, pre.lastChild);
    }

    function nodeText(root, sentinel) {
        let out = '';
        const walk = (n) => {
            n.childNodes.forEach((c) => {
                if (c.nodeType === 3) out += c.data;
                else if (c.nodeType !== 1) return;
                else if (tagOf(c) === 'BR') { if (c !== sentinel) out += '\n'; }
                else if (!SKIP_TAGS.test(tagOf(c))) {
                    const block = BLOCK_TAGS.test(tagOf(c));
                    if (block && out && !out.endsWith('\n')) out += '\n';
                    walk(c);
                    if (block && out && !out.endsWith('\n')) out += '\n';
                }
            });
        };
        walk(root);
        return out.replace(ZW_RE, '').replace(/ /g, ' ');
    }

    function domToBlocks(parent, align) {
        const out = [];
        let inline = [];
        const flush = () => {
            if (!inline.length) return;
            const inl = inlineMd(inline, {});
            if (inl !== '') out.push({ t: 'p', inl: inl, align: align || '' });
            inline = [];
        };
        Array.from(parent.childNodes).forEach((n) => {
            if (n.nodeType === 3) { inline.push(n); return; }
            if (n.nodeType !== 1) return;
            const tag = tagOf(n);
            // <img> is inline content - collectRuns() decides whether it stays
            if (SKIP_TAGS.test(tag) && tag !== 'IMG') return;
            if (!BLOCK_TAGS.test(tag)) {
                // Inline element with blocks inside (Google Docs: <b id="docs-internal-guid…">)
                if (hasBlockDescendant(n)) { flush(); out.push(...domToBlocks(n, alignOf(n) || align)); }
                else inline.push(n);
                return;
            }
            flush();
            const a = alignOf(n) || align || '';
            const own = (b) => {
                const at = ownAttrs(n, OWN_ATTRS, true);
                if (at) b.attrs = at;
                return b;
            };
            const h = tag.match(/^H([1-6])$/);
            if (h) {
                const inl = inlineMd(Array.from(n.childNodes), { heading: true });
                if (inl !== '') out.push(own({ t: 'h', level: +h[1], inl: inl, align: a }));
            }
            else if (tag === 'UL' || tag === 'OL') out.push(own(listFromDom(n, a)));
            else if (tag === 'LI') out.push({ t: 'list', ordered: false, start: 1, items: [{ children: domToBlocks(n, '') }], align: a });
            else if (tag === 'BLOCKQUOTE') {
                const children = domToBlocks(n, '');
                if (children.length) out.push(own({ t: 'quote', children: children, align: a }));
            }
            else if (tag === 'PRE') {
                const text = preText(n).replace(/\n+$/, '');
                if (text.trim() !== '') out.push(own({ t: 'code', lang: codeLang(n), text: text }));
            }
            else if (tag === 'HR') out.push(own({ t: 'hr' }));
            else if (tag === 'TABLE') {
                const t = tableFromDom(n, a);
                if (t) out.push(own(t));
            }
            else if (hasBlockDescendant(n) || /^(THEAD|TBODY|TFOOT|TR|TD|TH|DL|FIELDSET|DETAILS)$/.test(tag)) out.push(...domToBlocks(n, a));
            else {
                const inl = inlineMd(Array.from(n.childNodes), {});
                const b = { t: 'p', inl: inl, align: a };
                if (inl !== '') out.push(tag === 'P' ? own(b) : b);
            }
        });
        flush();
        return out;
    }

    function listFromDom(el, align) {
        const ordered = tagOf(el) === 'OL';
        const items = [];
        Array.from(el.childNodes).forEach((n) => {
            const tag = n.nodeType === 1 ? tagOf(n) : '';
            if (tag === 'LI') items.push({ children: domToBlocks(n, '') });
            else if (tag === 'UL' || tag === 'OL') {
                // Sub-list directly in the list (execCommand indent) belongs to the item before it
                const sub = listFromDom(n, '');
                if (items.length) items[items.length - 1].children.push(sub);
                else items.push({ children: [sub] });
            }
            else if (n.nodeType === 3 || (n.nodeType === 1 && (!SKIP_TAGS.test(tag) || tag === 'IMG'))) {
                const inl = inlineMd([n], {});
                if (inl !== '') items.push({ children: [{ t: 'p', inl: inl }] });
            }
        });
        const start = parseInt(el.getAttribute('start'), 10);
        return { t: 'list', ordered: ordered, start: ordered && !isNaN(start) ? Math.max(0, start) : 1, items: items, align: align || '' };
    }

    function tableFromDom(table, align) {
        const rows = Array.from(table.rows || []);
        if (!rows.length) return null;
        const grid = rows.map((row) => {
            const cells = [];
            Array.from(row.cells).forEach((c) => {
                cells.push(c);
                for (let k = 1; k < Math.min(c.colSpan || 1, 50); k++) cells.push(null);
            });
            return cells;
        });
        const cols = Math.max(...grid.map((r) => r.length));
        if (!cols) return null;
        const md = (c) => (c ? inlineMd(Array.from(c.childNodes), { table: true }) : '');
        const pad = (r) => {
            const out = r.map(md);
            while (out.length < cols) out.push('');
            return out;
        };
        const aligns = [];
        for (let k = 0; k < cols; k++) {
            const c = grid[0][k] || (grid[1] && grid[1][k]);
            aligns.push(c ? alignOf(c) : '');
        }
        return { t: 'table', aligns: aligns, head: pad(grid[0]), rows: grid.slice(1).map(pad), align: align || '' };
    }

    // ---- Inline: DOM -> Markdown ----
    //
    // First collect "runs" (text with formats strong/em/u/code/href, line breaks), then
    // output Markdown - this way nested, overlapping or duplicate
    // formats of foreign HTML become valid Markdown, and whitespace never sits on the inside
    // of a marker.

    function inlineMd(nodes, ctx) {
        const runs = [];
        collectRuns(nodes, {}, runs);
        return emitRuns(normalizeRuns(runs), ctx || {});
    }

    function collectRuns(nodes, marks, runs) {
        nodes.forEach((n) => {
            if (n.nodeType === 3) {
                const s = n.data.replace(ZW_RE, '').replace(EL_RE, '').replace(/ /g, ' ').replace(/[ \t\r\n\f]+/g, ' ');
                if (s) runs.push({ text: s, marks: marks });
                return;
            }
            if (n.nodeType !== 1) return;
            const tag = tagOf(n);
            if (tag === 'IMG') {
                const attrs = ownAttrs(n, IMG_ATTRS, false);
                if (attrs && attrs.src) runs.push({ img: elRef({ tag: 'img', attrs: attrs }), marks: marks });
                return;
            }
            if (SKIP_TAGS.test(tag)) return;
            if (tag === 'BR') { runs.push({ br: true }); return; }
            if (n.classList && n.classList.contains('htv-ch')) {
                const s = n.textContent.replace(ZW_RE, '');
                if (s) runs.push({ text: s, marks: marks, keep: true });
                return;
            }
            if (BLOCK_TAGS.test(tag)) {
                runs.push({ br: true, soft: true });
                collectRuns(Array.from(n.childNodes), marks, runs);
                runs.push({ br: true, soft: true });
                return;
            }
            const m = Object.assign({}, marks);
            const attrs = ATTR_INLINE[tag] ? ownAttrs(n, OWN_ATTRS, false) : null;
            if (attrs) {
                // Element with own attributes: tag and attributes stay as they are
                const entry = { tag: ATTR_INLINE[tag], attrs: attrs };
                if (tag === 'A') {
                    const href = m.inLink ? '' : safeUrl(n.getAttribute('href') || '');
                    if (href) { entry.href = href; m.inLink = true; }
                    else entry.tag = 'span';
                }
                m.els = (m.els || []).concat(elRef(entry));
                collectRuns(Array.from(n.childNodes), m, runs);
                return;
            }
            if (tag === 'STRONG' || tag === 'B') m.strong = true;
            else if (tag === 'EM' || tag === 'I') m.em = true;
            else if (tag === 'U' || tag === 'INS') m.u = true;
            else if (/^(CODE|KBD|SAMP|TT)$/.test(tag)) m.code = true;
            else if (tag === 'BDI') {
                const d = (n.getAttribute('dir') || '').toLowerCase();
                if (d === 'rtl' || d === 'ltr') m.dir = d;
            }
            else if (tag === 'A') {
                const href = safeUrl(n.getAttribute('href') || '');
                if (href && !m.inLink) { m.href = href; m.inLink = true; m.els = (m.els || []).concat('h'); }
            }
            const st = n.style;
            if (st) {
                const w = st.fontWeight;
                if (/^(bold|bolder|[6-9]00)$/.test(w)) m.strong = true;
                else if (/^(normal|lighter|[1-5]00)$/.test(w)) m.strong = false;
                if (st.fontStyle === 'italic' || st.fontStyle === 'oblique') m.em = true;
                else if (st.fontStyle === 'normal') m.em = false;
                // Underlined links of foreign pages are only a link, not an underline
                if (!m.inLink && /underline/.test(st.textDecorationLine || st.textDecoration || '')) m.u = true;
            }
            collectRuns(Array.from(n.childNodes), m, runs);
        });
    }

    function normalizeRuns(runs) {
        const out = [];
        runs.forEach((r) => {
            const prev = out[out.length - 1];
            if (r.br) {
                if (r.soft) {
                    if (prev && !prev.br) out.push({ br: true, soft: true });
                    return;
                }
                if (prev && prev.br && prev.soft) { out[out.length - 1] = { br: true }; return; }
                out.push({ br: true });
                return;
            }
            out.push(Object.assign({}, r));
        });
        // Remove whitespace at the start of lines and double whitespace
        let lineStart = true;
        let space = false;
        out.forEach((r) => {
            if (r.br) { lineStart = true; space = false; return; }
            if (r.img) { lineStart = false; space = false; return; }
            if (!r.keep && (lineStart || space)) r.text = r.text.replace(/^ +/, '');
            if (r.text !== '') { lineStart = false; space = !r.keep && / $/.test(r.text); }
        });
        // … and at the end of lines
        for (let k = out.length - 1, end = true; k >= 0; k--) {
            const r = out[k];
            if (r.br) { end = true; continue; }
            if (r.img) { end = false; continue; }
            if (end && !r.keep) {
                r.text = r.text.replace(/ +$/, '');
                if (r.text === '') continue;
            }
            end = false;
        }
        const res = out.filter((r) => r.br || r.img || r.text !== '');
        // Line breaks at the beginning and end (placeholders) are dropped
        while (res.length && res[0].br) res.shift();
        while (res.length && res[res.length - 1].br) res.pop();
        return res;
    }

    // The writing direction encloses everything else, then elements with own
    // attributes and the link in their order (marks.els, 'h' = the link), then the formats
    const MARK_ORDER = ['strong', 'em', 'u'];
    const MARK_TEXT = { strong: '**', em: '*', u: '++' };
    const markOpen = (mk) => mk.key === 'href' ? '[' : (mk.key === 'dir' ? (mk.value === 'rtl' ? RLI : LRI)
        : (mk.key === 'el' ? EL_OPEN + mk.value + EL_MID : MARK_TEXT[mk.key]));

    function wantedMarks(m) {
        const want = m.dir ? [{ key: 'dir', value: m.dir }] : [];
        (m.els || []).forEach((x) => {
            if (x !== 'h') want.push({ key: 'el', value: x });
            else if (m.href) want.push({ key: 'href', value: m.href });
        });
        return want.concat(MARK_ORDER.filter((k) => m[k]).map((k) => ({ key: k, value: m[k] })));
    }

    function sameMarks(a, b) {
        return !!a.strong === !!b.strong && !!a.em === !!b.em && !!a.u === !!b.u && !!a.code === !!b.code && (a.href || '') === (b.href || '')
            && (a.dir || '') === (b.dir || '') && (a.els || []).join() === (b.els || []).join();
    }

    function emitRuns(runs, ctx) {
        const merged = [];
        runs.forEach((r) => {
            const prev = merged[merged.length - 1];
            if (!r.br && !r.img && prev && !prev.br && !prev.img && sameMarks(prev.marks, r.marks)) prev.text += r.text;
            else merged.push(Object.assign({}, r));
        });
        merged.forEach((r, k) => {
            if (r.br || r.img) return;
            // Formats on pure whitespace are dropped
            if (r.text.trim() === '' && !r.marks.code) r.marks = {};
            // Link whose text is the address: write it as an address
            const m = r.marks;
            if (m.href && m.href === r.text && /^https?:\/\//i.test(m.href) && !m.strong && !m.em && !m.u && !m.code && !m.dir
                && (m.els || []).length === 1) {
                const prev = merged[k - 1];
                const next = merged[k + 1];
                if (!(prev && !prev.br && prev.marks.href === m.href) && !(next && !next.br && next.marks.href === m.href)) {
                    r.url = !next || next.br || /^[\s.,:;!?)]/.test(next.text) ? 'raw' : 'angle';
                    r.marks = {};
                }
            }
        });
        let out = '';
        const stack = [];
        const closeTo = (n) => {
            while (stack.length > n) {
                const top = stack.pop();
                const sp = out.match(/ +$/);
                if (sp) out = out.slice(0, -sp[0].length);
                out += top.key === 'href' ? '](' + linkDest(top.value) + ')'
                    : (top.key === 'dir' ? PDI : (top.key === 'el' ? EL_CLOSE : MARK_TEXT[top.key]));
                if (sp) out += sp[0];
            }
        };
        merged.forEach((r) => {
            if (r.br) {
                closeTo(0);
                out = out.replace(/ +$/, '');
                out += ctx.table ? '<br>' : (ctx.heading ? ' ' : '  \n');
                return;
            }
            const want = wantedMarks(r.marks);
            let keep = 0;
            while (keep < stack.length && keep < want.length && stack[keep].key === want[keep].key && stack[keep].value === want[keep].value) keep++;
            closeTo(keep);
            let text = r.img ? '' : r.text;
            if (keep < want.length) {
                const lead = text.match(/^ +/);
                if (lead) { out += lead[0]; text = text.slice(lead[0].length); }
                for (let k = keep; k < want.length; k++) {
                    out += markOpen(want[k]);
                    stack.push(want[k]);
                }
            }
            if (r.img) out += EL_IMG + r.img + EL_MID;
            else if (r.url === 'raw') out += text;
            else if (r.url === 'angle') out += '<' + text + '>';
            else if (r.marks.code) out += codeSpan(text, ctx);
            else out += escapeInline(text, ctx);
        });
        closeTo(0);
        return out.replace(/ +$/, '');
    }

    function linkDest(url) {
        return url.replace(/[ ()<>]/g, (c) => ({ ' ': '%20', '(': '%28', ')': '%29', '<': '%3C', '>': '%3E' })[c]);
    }

    function codeSpan(text, ctx) {
        if (text === '') return '';
        let max = 0;
        (text.match(/`+/g) || []).forEach((m) => { max = Math.max(max, m.length); });
        const fence = '`'.repeat(max + 1);
        const pad = /^`|`$/.test(text) || (/^ /.test(text) && / $/.test(text)) ? ' ' : '';
        if (ctx.table) text = text.replace(/\|/g, '\\|');
        return fence + pad + text + pad + fence;
    }

    const URL_IN_TEXT = /\bhttps?:\/\/[^\s<]*[^\s<.,:;!?"')\]*_~]/gi;

    function escapeInline(text, ctx) {
        // Addresses remain unchanged - Parsedown and the editor link them themselves
        let out = '';
        let last = 0;
        text.replace(URL_IN_TEXT, (m, off) => {
            out += escapePlain(text.slice(last, off), ctx) + m;
            last = off + m.length;
            return m;
        });
        return out + escapePlain(text.slice(last), ctx);
    }

    function escapePlain(s, ctx) {
        s = s.replace(/[\\`*_\[\]]/g, '\\$&')
            .replace(/<(?=[A-Za-z\/!?])/g, '\\<')
            .replace(/&(?=#?[A-Za-z0-9]+;)/g, '\\&')
            .replace(/\+{2,}/g, (m) => '\\+'.repeat(m.length))
            .replace(/~{2,}/g, (m) => '\\~'.repeat(m.length));
        if (ctx.table) s = s.replace(/\|/g, '\\|');
        return s;
    }

    // Start of a paragraph line that would otherwise be read as a block
    function escapeLineStart(line) {
        if (/^#{1,6}([ \t]|$)/.test(line) || /^>/.test(line) || /^[-+]([ \t]|$)/.test(line)
            || /^(=+|-+)[ \t]*$/.test(line) || /^:::/.test(line) || /^~~~/.test(line)) return '\\' + line;
        return line.replace(/^(\d{1,9})([.)])(?=[ \t]|$)/, '$1\\$2');
    }

    // ---- Merge neighboring lists ----

    function mergeAdjacentLists(blocks) {
        const out = [];
        blocks.forEach((b) => {
            if (b.t === 'quote') b = Object.assign({}, b, { children: mergeAdjacentLists(b.children) });
            if (b.t === 'list') b = Object.assign({}, b, { items: b.items.map((it) => ({ children: mergeAdjacentLists(it.children) })) });
            const prev = out[out.length - 1];
            if (b.t === 'list' && prev && prev.t === 'list' && prev.ordered === b.ordered && (prev.align || '') === (b.align || '')
                && JSON.stringify(prev.attrs || null) === JSON.stringify(b.attrs || null)) {
                prev.items = prev.items.concat(b.items);
                return;
            }
            out.push(b);
        });
        return out;
    }

    // ---- Block list -> HTML of the field ----
    //
    // Via the HTML of the editor (renderBlocks): its peculiarities become
    // ordinary HTML - data-align -> style="text-align: …", pre[data-lang] ->
    // pre > code.language-…, span.htv-ch -> entity, placeholder <br> of empty cells
    // are dropped. Blocks are each on one line, lists, quotes and tables
    // indented; when reading, this whitespace is meaningless.

    const ENTITY_OUT = { ' ': '&nbsp;', ' ': '&#8239;', ' ': '&ensp;', ' ': '&thinsp;', '­': '&shy;', '‑': '&#8209;' };
    const CONTAINER = /^(UL|OL|BLOCKQUOTE|TABLE|THEAD|TBODY|TR)$/;

    function writeHtml(blocks) {
        const box = document.createElement('div');
        box.innerHTML = renderBlocks(blocks);
        box.querySelectorAll('.htv-ch').forEach((n) => n.replaceWith(document.createTextNode(n.textContent)));
        box.querySelectorAll('[data-htv-a]').forEach((n) => {
            const at = JSON.parse(n.getAttribute('data-htv-a'));
            if (at.id) n.setAttribute('id', at.id);
            n.removeAttribute('data-htv-a');
        });
        box.querySelectorAll('[data-align]').forEach((n) => {
            const own = n.getAttribute('style');
            if (own) n.setAttribute('style', own.replace(/[\s;]*$/, '; ') + 'text-align: ' + n.getAttribute('data-align') + ';');
            else n.style.textAlign = n.getAttribute('data-align');
            n.removeAttribute('data-align');
        });
        box.querySelectorAll('pre').forEach((pre) => {
            const code = document.createElement('code');
            const lang = pre.getAttribute('data-lang');
            if (lang) code.className = 'language-' + lang;
            code.textContent = preText(pre);
            pre.removeAttribute('data-lang');
            pre.replaceChildren(code);
        });
        box.querySelectorAll('p,h1,h2,h3,h4,h5,h6,li,td,th').forEach((n) => {
            if (n.childNodes.length === 1 && n.firstChild.nodeName === 'BR') n.firstChild.remove();
        });
        return Array.from(box.children).map((n) => blockHtml(n, ''))
            .join('\n').replace(/[    ­‑]/g, (ch) => ENTITY_OUT[ch]);
    }

    function blockHtml(el, ind) {
        const tag = el.nodeName.toLowerCase();
        const shell = el.cloneNode(false).outerHTML;
        const open = shell.slice(0, shell.length - tag.length - 3);
        const close = '</' + tag + '>';
        const inner = ind + '  ';
        if (CONTAINER.test(el.nodeName)) {
            return ind + open + '\n' + Array.from(el.children).map((c) => blockHtml(c, inner)).join('\n') + '\n' + ind + close;
        }
        const subs = tag === 'li' ? Array.from(el.children).filter((c) => /^(UL|OL)$/.test(c.nodeName)) : [];
        if (!subs.length) return ind + el.outerHTML;
        // List item with sub-list: text on the line of the item, sub-lists below
        const own = el.cloneNode(true);
        Array.from(own.children).forEach((c) => { if (/^(UL|OL)$/.test(c.nodeName)) c.remove(); });
        return ind + open + own.innerHTML + '\n' + subs.map((c) => blockHtml(c, inner)).join('\n') + '\n' + ind + close;
    }

    // Editor DOM -> HTML of the field
    function toHtml(el) {
        return writeHtml(mergeAdjacentLists(domToBlocks(el, '')));
    }

    // ---- Paste: clipboard -> block list ----

    // Word: lists are paragraphs with mso-list, the bullet is in a span
    function convertWordLists(doc) {
        const ps = Array.from(doc.querySelectorAll('p')).filter((p) => /mso-list:\s*l\d+\s+level\d+/i.test(p.getAttribute('style') || ''));
        if (!ps.length) return;
        const contiguous = ps.map((p) => ps.includes(p.previousElementSibling));
        let stack = [];
        ps.forEach((p, k) => {
            const level = +(p.getAttribute('style').match(/mso-list:\s*l\d+\s+level(\d+)/i)[1]);
            let marker = '';
            p.querySelectorAll('span').forEach((s) => {
                if (/mso-list:\s*Ignore/i.test(s.getAttribute('style') || '')) { marker += s.textContent; s.remove(); }
            });
            const ordered = /^\s*(\d+|[a-zA-Z]{1,4})[.)]/.test(marker);
            if (!contiguous[k]) stack = [];
            while (stack.length && stack[stack.length - 1].level > level) stack.pop();
            let top = stack[stack.length - 1];
            if (!top || top.level < level) {
                const list = doc.createElement(ordered ? 'ol' : 'ul');
                if (top) (top.list.lastElementChild || top.list.appendChild(doc.createElement('li'))).appendChild(list);
                else p.before(list);
                stack.push(top = { level: level, list: list });
            }
            const li = doc.createElement('li');
            while (p.firstChild) li.appendChild(p.firstChild);
            top.list.appendChild(li);
            p.remove();
        });
    }

    // own: HTML of the field (not from the clipboard) - invisible characters and
    // "left" are intended there
    function htmlToBlocks(html, own) {
        const doc = new DOMParser().parseFromString(String(html || ''), 'text/html');
        convertWordLists(doc);
        if (own) markInvisible(doc.body);
        ownHtml = !!own;
        try { return mergeAdjacentLists(domToBlocks(doc.body, '')); }
        finally { ownHtml = false; }
    }

    // Invisible characters in the text as span.htv-ch, as in the editor - otherwise a
    // non-breaking space would become a normal one (except code, as in the editor)
    const INVISIBLE_SPLIT = new RegExp('([' + Object.keys(INVISIBLE).join('') + '])');

    function markInvisible(root) {
        const doc = root.ownerDocument;
        const walker = doc.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        const texts = [];
        while (walker.nextNode()) texts.push(walker.currentNode);
        texts.forEach((t) => {
            if (t.data.search(INVISIBLE_RE) < 0 || t.parentNode.closest('pre,code,kbd,samp,tt')) return;
            const frag = doc.createDocumentFragment();
            t.data.split(INVISIBLE_SPLIT).forEach((part) => {
                if (part === '') return;
                if (!INVISIBLE[part]) { frag.appendChild(doc.createTextNode(part)); return; }
                const span = doc.createElement('span');
                span.className = 'htv-ch';
                span.textContent = part;
                frag.appendChild(span);
            });
            t.replaceWith(frag);
        });
    }

    // Any HTML trimmed to what the editor maps
    function cleanHtml(html) {
        return writeHtml(htmlToBlocks(html, true));
    }

    // HTML of the field -> HTML of the editor
    function editorHtml(html) {
        return renderBlocks(htmlToBlocks(html, true));
    }

    function looksLikeMarkdown(text) {
        return /^ {0,3}#{1,6}[ \t]+\S/m.test(text) || /^ {0,3}[-*+][ \t]+\S/m.test(text) || /^ {0,3}\d{1,9}[.)][ \t]+\S/m.test(text)
            || /^ {0,3}>/m.test(text) || /^ {0,3}(`{3,}|~{3,})/m.test(text) || /\*\*[^*\n]+\*\*/.test(text) || /__[^_\n]+__/.test(text)
            || /(^|[\s(])\*[^*\s][^*\n]*\*(?=[\s).,:;!?]|$)/m.test(text) || /\[[^\]\n]+\]\([^)\s]+\)/.test(text)
            || /`[^`\n]+`/.test(text) || /^ {0,3}(-{3,}|\*{3,}|_{3,})[ \t]*$/m.test(text) || /^:::[ \t]*[A-Za-z]+/m.test(text)
            || /\+\+\S[^+\n]*\+\+/.test(text) || /^\|.*\|[ \t]*\n[ \t]*\|?[ \t]*:?-+/m.test(text);
    }

    // Copies from code editors (VS Code …) arrive as HTML without formatting
    function isUnformattedHtml(html) {
        return !/<(b|strong|i|em|u|h[1-6]|ul|ol|li|table|a|blockquote|hr)[\s>]/i.test(html);
    }

    function plainBlocks(text) {
        return String(text).replace(/\r\n?/g, '\n').split(/\n[ \t]*\n+/).map((para) => {
            const lines = para.split('\n').map((l) => escapeInline(l.trim(), {})).filter((l) => l !== '');
            return { t: 'p', inl: lines.map(escapeLineStart).join('  \n') };
        }).filter((b) => b.inl !== '');
    }

    function clipboardBlocks(html, text) {
        if (html && !(isUnformattedHtml(html) && text && looksLikeMarkdown(text))) return htmlToBlocks(html);
        if (!text) return [];
        return looksLikeMarkdown(text) ? parseMarkdown(text) : plainBlocks(text);
    }

    // =====================================================================
    // Selection and DOM helpers of the editor
    // =====================================================================

    function getRange(root) {
        const sel = window.getSelection();
        if (!sel || !sel.rangeCount) return null;
        const r = sel.getRangeAt(0);
        return root.contains(r.startContainer) && root.contains(r.endContainer) ? r : null;
    }

    function setRange(r) {
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(r);
    }

    function caretAt(node, offset) {
        const r = document.createRange();
        r.setStart(node, offset);
        r.collapse(true);
        setRange(r);
    }

    function isLeaf(n) {
        return !n.firstChild || (n.nodeType === 1 && n.classList.contains('htv-ch'));
    }

    function edgeLeaf(el, last) {
        let n = el;
        while (n && !isLeaf(n)) {
            let c = last ? n.lastChild : n.firstChild;
            // a sub-list does not belong to the text of the item
            if (tagOf(n) === 'LI') {
                const own = Array.from(n.childNodes).filter((x) => !/^(UL|OL)$/.test(x.nodeName));
                c = own.length ? own[last ? own.length - 1 : 0] : null;
            }
            if (!c) break;
            n = c;
        }
        return n;
    }

    // Caret at the beginning or end of the text of el
    function caretInto(el, atEnd) {
        const r = document.createRange();
        const leaf = edgeLeaf(el, !!atEnd);
        if (leaf && leaf.nodeType === 3) r.setStart(leaf, atEnd ? leaf.length : 0);
        else if (leaf && leaf !== el) {
            if (atEnd && leaf.nodeName !== 'BR') r.setStartAfter(leaf);
            else r.setStartBefore(leaf);
        }
        else r.setStart(el, 0);
        r.collapse(true);
        setRange(r);
    }

    function closestIn(root, node, selector) {
        let el = node && node.nodeType === 1 ? node : (node ? node.parentNode : null);
        while (el && el !== root) {
            if (el.nodeType === 1 && el.matches(selector)) return el;
            el = el.parentNode;
        }
        return null;
    }

    function textBlockOf(root, node) {
        while (node && node !== root) {
            if (node.nodeType === 1 && TEXTBLOCK.test(node.nodeName)) return node;
            node = node.parentNode;
        }
        return null;
    }

    // Children of a block without sub-lists and sub-blocks
    function ownNodes(el) {
        return Array.from(el.childNodes).filter((n) => !(n.nodeType === 1 && (/^(UL|OL|BLOCKQUOTE|TABLE|PRE)$/.test(n.nodeName) || TEXTBLOCK.test(n.nodeName))));
    }

    function hasText(n) {
        if (n.nodeType === 3) return n.data.replace(ZW_RE, '') !== '';
        if (n.nodeType !== 1) return false;
        if (n.classList.contains('htv-ch') || n.nodeName === 'HR' || n.nodeName === 'IMG' || n.querySelector('img')) return true;
        return n.textContent.replace(ZW_RE, '') !== '';
    }

    function isEmptyBlock(el) {
        return !ownNodes(el).some(hasText);
    }

    function newBr() {
        return document.createElement('br');
    }

    function newP() {
        const p = document.createElement('p');
        p.appendChild(newBr());
        return p;
    }

    function fixEmpty(el) {
        if (!isEmptyBlock(el)) return;
        const own = ownNodes(el);
        if (own.some((n) => n.nodeName === 'BR')) return;
        const sub = Array.from(el.childNodes).find((n) => !own.includes(n));
        el.insertBefore(newBr(), sub || null);
    }

    function renameEl(el, tag) {
        const n = document.createElement(tag);
        ['data-align', 'data-htv-a', 'class', 'style', 'title'].forEach((k) => { if (el.hasAttribute(k)) n.setAttribute(k, el.getAttribute(k)); });
        while (el.firstChild) n.appendChild(el.firstChild);
        el.replaceWith(n);
        return n;
    }

    function unwrapEl(el) {
        const parent = el.parentNode;
        while (el.firstChild) parent.insertBefore(el.firstChild, el);
        el.remove();
    }

    // Remove empty inline leftovers after splitting (<strong></strong>)
    function cleanupEmptyInline(el) {
        Array.from(el.querySelectorAll('strong,b,em,i,u,code,a,span:not(.htv-ch)')).reverse().forEach((n) => {
            if (!n.textContent.replace(ZW_RE, '') && !n.querySelector('br,.htv-ch,img')) n.remove();
        });
    }

    function isAtStartOf(el, c, o) {
        const r = document.createRange();
        r.setStart(el, 0);
        try { r.setEnd(c, o); } catch (e) { return false; }
        if (r.toString().replace(ZW_RE, '') !== '') return false;
        return !r.cloneContents().querySelector('br,.htv-ch');
    }

    function isAtEndOf(el, c, o) {
        const r = document.createRange();
        try { r.setStart(c, o); } catch (e) { return false; }
        const sub = el.nodeName === 'LI' ? Array.from(el.children).find((n) => /^(UL|OL)$/.test(n.nodeName)) : null;
        if (sub) r.setEndBefore(sub);
        else r.setEnd(el, el.childNodes.length);
        if (r.toString().replace(ZW_RE, '') !== '') return false;
        return !r.cloneContents().querySelector('.htv-ch');
    }

    function saveSel(root) {
        const r = getRange(root);
        return r ? { sc: r.startContainer, so: r.startOffset, ec: r.endContainer, eo: r.endOffset } : null;
    }

    function maxOffset(n) {
        return n.nodeType === 3 ? n.length : n.childNodes.length;
    }

    function restoreSel(root, s, fallback) {
        if (s && root.contains(s.sc) && root.contains(s.ec)) {
            try {
                const r = document.createRange();
                r.setStart(s.sc, Math.min(s.so, maxOffset(s.sc)));
                r.setEnd(s.ec, Math.min(s.eo, maxOffset(s.ec)));
                setRange(r);
                return;
            }
            catch (e) { /* continue with fallback */ }
        }
        if (fallback && root.contains(fallback)) caretInto(fallback, true);
    }

    function nodePath(root, node) {
        const path = [];
        while (node && node !== root) {
            const p = node.parentNode;
            if (!p) return null;
            path.unshift(Array.prototype.indexOf.call(p.childNodes, node));
            node = p;
        }
        return node === root ? path : null;
    }

    function nodeAt(root, path) {
        let n = root;
        for (const i of path || []) {
            n = n.childNodes[i];
            if (!n) return null;
        }
        return n;
    }

    function isNested(li) {
        return !!(li.parentNode && li.parentNode.parentNode && li.parentNode.parentNode.nodeName === 'LI');
    }

    function topList(li) {
        let list = li.parentNode;
        while (list.parentNode && list.parentNode.nodeName === 'LI') list = list.parentNode.parentNode;
        return list;
    }

    function fragmentOf(html) {
        const t = document.createElement('template');
        t.innerHTML = html;
        return t.content;
    }

    function clamp(v, min, max, def) {
        v = parseInt(v, 10);
        return isNaN(v) ? def : Math.max(min, Math.min(max, v));
    }

    // =====================================================================
    // Toolbar
    // =====================================================================

    const isMac = /Mac|iPhone|iPad|iPod/.test(navigator.platform || navigator.userAgent || '');

    const BUTTONS = {
        bold:         { icon: 'format_bold', key: 'Mod+B' },
        italic:       { icon: 'format_italic', key: 'Mod+I' },
        underline:    { icon: 'format_underlined', key: 'Mod+U' },
        code:         { icon: 'data_object' },
        heading:      { icon: 'title', menu: true },
        ul:           { icon: 'format_list_bulleted' },
        ol:           { icon: 'format_list_numbered' },
        outdent:      { icon: 'format_indent_decrease', key: 'Shift+Tab' },
        indent:       { icon: 'format_indent_increase', key: 'Tab' },
        quote:        { icon: 'format_quote' },
        codeblock:    { icon: 'code_blocks' },
        link:         { icon: 'link', key: 'Mod+K' },
        hr:           { icon: 'horizontal_rule' },
        br:           { icon: 'keyboard_return', key: 'Shift+Enter' },
        special:      { icon: 'special_character', menu: true },
        align_left:   { icon: 'format_align_left' },
        align_center: { icon: 'format_align_center' },
        align_right:  { icon: 'format_align_right' },
        dir:          { icon: 'format_textdirection_l_to_r' },
        table:        { icon: 'table', menu: true },
        undo:         { icon: 'undo', key: 'Mod+Z' },
        redo:         { icon: 'redo', key: isMac ? 'Mod+Shift+Z' : 'Mod+Y' }
    };

    const PRESETS = {
        full: ['bold', 'italic', 'underline', 'code', '|', 'heading', '|', 'ul', 'ol', 'outdent', 'indent', '|',
            'quote', 'codeblock', '|', 'link', 'hr', 'br', 'special', '|', 'align_left', 'align_center', 'align_right', 'dir', '|',
            'table', '|', 'undo', 'redo'],
        basic: ['bold', 'italic', '|', 'heading', '|', 'ul', 'ol', '|', 'link', '|', 'undo', 'redo']
    };

    const SPECIAL = [
        { group: 'currency', chars: '€$£¥¢₹₽₺₩₪₿' },
        { group: 'symbols', chars: '©®™§¶†°±×÷≈≠≤≥‰µ½¼¾¹²³•…→←↑↓✓' },
        { group: 'quotes', chars: '„“”‚‘’»«›‹' },
        { group: 'dashes', named: [['–', 'ndash'], ['—', 'mdash'], ['‑', 'nbhy'], ['−', 'minus']] },
        { group: 'spaces', named: [[' ', 'nbsp'], [' ', 'nnbsp'], [' ', 'ensp'], [' ', 'thinsp'], ['­', 'shy']] }
    ];

    // English defaults - in the backend the texts come from the snippets
    const TEXTS = {
        editor: 'Text editor', mod: 'Ctrl',
        bold: 'Bold', italic: 'Italic', underline: 'Underline', code: 'Inline code', heading: 'Paragraph format',
        paragraph: 'Paragraph', heading_n: 'Heading {n}', ul: 'Bulleted list', ol: 'Numbered list',
        indent: 'Increase indent', outdent: 'Decrease indent', quote: 'Quote', codeblock: 'Code block', link: 'Link',
        hr: 'Horizontal line', br: 'Line break', special: 'Special characters', align_left: 'Align left',
        align_center: 'Center', align_right: 'Align right', dir: 'Text direction', dir_ltr: 'left to right',
        dir_rtl: 'right to left', table: 'Table', undo: 'Undo', redo: 'Redo',
        source: 'HTML source', link_url: 'Address (URL)', link_text: 'Text', link_apply: 'Apply',
        link_remove: 'Remove link', link_invalid: 'This address is not allowed.', cancel: 'Cancel',
        table_cols: 'Columns', table_rows: 'Rows (without header row)', table_insert: 'Insert table',
        table_note: 'The first row is the header row.', col_left: 'Insert column left', col_right: 'Insert column right',
        col_delete: 'Delete column', row_above: 'Insert row above', row_below: 'Insert row below',
        row_delete: 'Delete row', table_delete: 'Delete table',
        group_currency: 'Currency', group_symbols: 'Symbols', group_quotes: 'Quotation marks', group_dashes: 'Dashes',
        group_spaces: 'Spaces and hyphenation', ch_ndash: 'En dash', ch_mdash: 'Em dash', ch_nbhy: 'Non-breaking hyphen',
        ch_minus: 'Minus sign', ch_nbsp: 'Non-breaking space', ch_nnbsp: 'Narrow non-breaking space', ch_ensp: 'En space',
        ch_thinsp: 'Thin space', ch_shy: 'Soft hyphen'
    };

    function toolbarNames(options) {
        const preset = String(options.toolbar || 'full');
        let items;
        if (preset === 'custom') {
            items = String(options.toolbar_custom || '').split(/[\s,]+/).filter((n) => n === '|' || BUTTONS[n]);
            if (!items.some((n) => n !== '|')) items = PRESETS.full;
        }
        else items = PRESETS[preset] || PRESETS.full;
        return items.filter((n, k, a) => n !== '|' || (k > 0 && k < a.length - 1 && a[k - 1] !== '|'));
    }

    // =====================================================================
    // Editor
    // =====================================================================

    class Editor {

        constructor(el, options, onChange) {
            this.el = el;
            this.options = options || {};
            this.onChange = onChange || (() => {});
            this.texts = {};
            const texts = this.options.texts || {};
            Object.keys(TEXTS).forEach((k) => { this.texts[k] = texts[k] || TEXTS[k]; });
            this.readonly = el.disabled || el.readOnly;
            this.history = [];
            this.hIndex = -1;
            this.sourceMode = false;
            this.build();
            this.setHtml(el.value, true);
            // Toolbar right away in the state of the field (e.g. writing direction rtl)
            this.updateState();
        }

        t(key) {
            return this.texts[key] || key;
        }

        keyLabel(key) {
            if (isMac) return key.replace('Mod+', '⌘').replace('Shift+', '⇧').replace('Alt+', '⌥').replace('Enter', '↵');
            return key.replace('Mod', this.t('mod'));
        }

        // ---- Structure ----

        build() {
            const el = this.el;
            const o = this.options;
            const field = (String(el.name || '').match(/\[([^\]]+)\]/) || [null, el.name || 'field'])[1].replace(/[^A-Za-z0-9_-]/g, '_');
            const cssClass = String(o.css_class || '').trim() || 'c9-htmlvisual-' + field;
            this.minHeight = Math.max(50, parseInt(o.min_height || '200', 10) || 200);
            this.maxHeight = parseInt(o.max_height || '0', 10) || 0;

            const wrap = this.wrap = document.createElement('div');
            wrap.className = 'c9-htv ' + cssClass;
            const bar = this.bar = document.createElement('div');
            bar.className = 'c9-htv-toolbar';
            bar.setAttribute('role', 'toolbar');
            const content = this.content = document.createElement('div');
            content.className = 'c9-htv-content';
            content.contentEditable = this.readonly ? 'false' : 'true';
            // Writing direction like the field (dir per language, Backend::fieldDirection())
            content.dir = getComputedStyle(el).direction === 'rtl' ? 'rtl' : 'ltr';
            content.setAttribute('role', 'textbox');
            content.setAttribute('aria-multiline', 'true');
            content.setAttribute('aria-label', el.getAttribute('aria-label') || this.t('editor'));
            content.spellcheck = true;
            content.dataset.placeholder = el.getAttribute('placeholder') || '';
            content.style.minHeight = this.minHeight + 'px';
            if (this.maxHeight > 0) content.style.maxHeight = this.maxHeight + 'px';
            this.pop = document.createElement('div');
            this.pop.className = 'c9-htv-pop';
            this.pop.hidden = true;

            this.elStyle = el.getAttribute('style');
            el.parentNode.insertBefore(wrap, el);
            wrap.appendChild(bar);
            wrap.appendChild(content);
            wrap.appendChild(el);
            wrap.appendChild(this.pop);
            el.classList.add('c9-htv-source');
            el.style.display = 'none';

            this.buildToolbar();

            this.handlers = {
                keydown: (e) => this.onKeydown(e),
                beforeinput: (e) => this.onBeforeInput(e),
                input: (e) => this.onInput(e),
                paste: (e) => this.onPaste(e),
                drop: (e) => this.onDrop(e),
                dragstart: () => { this.dragging = true; },
                dragend: () => { this.dragging = false; }
            };
            Object.keys(this.handlers).forEach((k) => content.addEventListener(k, this.handlers[k]));
            this.onSelection = () => {
                if (this.stateFrame) return;
                this.stateFrame = window.requestAnimationFrame(() => { this.stateFrame = 0; this.updateState(); });
            };
            document.addEventListener('selectionchange', this.onSelection);
            this.onDocDown = (e) => {
                if (!this.pop.hidden && !this.pop.contains(e.target) && !this.bar.contains(e.target)) this.closePop();
            };
            document.addEventListener('mousedown', this.onDocDown);
            this.onSourceInput = () => this.onChange();
            el.addEventListener('input', this.onSourceInput);
            try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) { /* older browsers */ }
        }

        buildToolbar() {
            const bar = this.bar;
            this.buttons = {};
            // Keep the selection in the text: no focus change when clicking in the toolbar
            bar.addEventListener('mousedown', (e) => {
                if (!e.target.closest('input,select,textarea')) e.preventDefault();
            });
            const add = (name, icon, title, menu) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'c9-htv-btn';
                b.dataset.cmd = name;
                b.title = title;
                b.setAttribute('aria-label', title);
                b.innerHTML = '<span class="icon" aria-hidden="true">' + icon + '</span>'
                    + (menu ? '<span class="icon c9-htv-caret" aria-hidden="true">arrow_drop_down</span>' : '');
                b.addEventListener('click', (e) => { e.preventDefault(); this.onButton(name, b); });
                bar.appendChild(b);
                this.buttons[name] = b;
                return b;
            };
            if (!this.readonly) {
                toolbarNames(this.options).forEach((name) => {
                    if (name === '|') {
                        const s = document.createElement('span');
                        s.className = 'c9-htv-sep';
                        bar.appendChild(s);
                        return;
                    }
                    const def = BUTTONS[name];
                    add(name, def.icon, this.t(name) + (def.key ? ' (' + this.keyLabel(def.key) + ')' : ''), def.menu);
                });
            }
            const spacer = document.createElement('span');
            spacer.className = 'c9-htv-spacer';
            bar.appendChild(spacer);
            add('source', 'code', this.t('source'), false).setAttribute('aria-pressed', 'false');
        }

        // ---- Content, synchronization, history ----

        setHtml(html, initial) {
            this.content.innerHTML = editorHtml(html) || '<p><br></p>';
            this.ensureTrailing();
            if (initial) {
                this.lastHtml = toHtml(this.content);
                this.content.classList.toggle('is-empty', this.lastHtml === '');
                this.history = [];
                this.hIndex = -1;
                this.snapshot();
            }
            else {
                this.syncNow();
                this.snapshot();
            }
        }

        syncNow() {
            clearTimeout(this.syncTimer);
            this.syncTimer = null;
            if (this.sourceMode) return;
            const html = toHtml(this.content);
            this.content.classList.toggle('is-empty', html === '');
            if (html !== this.lastHtml) {
                this.lastHtml = html;
                this.el.value = html;
                this.onChange();
            }
        }

        scheduleSync() {
            clearTimeout(this.syncTimer);
            this.syncTimer = setTimeout(() => this.syncNow(), 200);
        }

        snapshotState() {
            const r = getRange(this.content);
            return {
                html: this.content.innerHTML,
                sel: r ? { s: nodePath(this.content, r.startContainer), so: r.startOffset, e: nodePath(this.content, r.endContainer), eo: r.endOffset } : null
            };
        }

        snapshot() {
            const st = this.snapshotState();
            const cur = this.history[this.hIndex];
            if (cur && cur.html === st.html) { if (st.sel) cur.sel = st.sel; return; }
            this.history = this.history.slice(0, this.hIndex + 1);
            this.history.push(st);
            if (this.history.length > 200) this.history.shift();
            this.hIndex = this.history.length - 1;
        }

        commitTyping() {
            if (this.typingTimer) {
                clearTimeout(this.typingTimer);
                this.typingTimer = null;
                this.snapshot();
            }
        }

        restoreState(st) {
            this.content.innerHTML = st.html;
            if (st.sel) {
                const sc = nodeAt(this.content, st.sel.s);
                const ec = nodeAt(this.content, st.sel.e);
                if (sc && ec) {
                    try {
                        const r = document.createRange();
                        r.setStart(sc, Math.min(st.sel.so, maxOffset(sc)));
                        r.setEnd(ec, Math.min(st.sel.eo, maxOffset(ec)));
                        setRange(r);
                    }
                    catch (e) { caretInto(this.content.lastElementChild || this.content, true); }
                }
            }
            else caretInto(this.content.firstElementChild || this.content, false);
            this.syncNow();
            this.updateState();
        }

        undo() {
            if (this.sourceMode) return;
            this.commitTyping();
            if (this.hIndex <= 0) return;
            this.hIndex--;
            this.restoreState(this.history[this.hIndex]);
        }

        redo() {
            if (this.sourceMode) return;
            this.commitTyping();
            if (this.hIndex >= this.history.length - 1) return;
            this.hIndex++;
            this.restoreState(this.history[this.hIndex]);
        }

        // Execute a command: finish typing, command, clean up, save, history
        exec(fn) {
            if (this.sourceMode || this.readonly) return;
            this.commitTyping();
            if (!getRange(this.content)) this.focusEnd();
            this.busy = true;
            try { fn(); }
            finally { this.busy = false; }
            this.normalize();
            this.ensureTrailing();
            this.syncNow();
            this.snapshot();
            this.updateState();
        }

        focusEnd() {
            this.content.focus({ preventScroll: true });
            const last = this.content.lastElementChild;
            if (last) caretInto(last, true);
        }

        // After input: text directly in the editor into paragraphs, the browser's style spans gone
        normalize() {
            const root = this.content;
            if (!root.firstChild || (root.childNodes.length === 1 && root.firstChild.nodeName === 'BR')) {
                root.innerHTML = '<p><br></p>';
                caretInto(root.firstChild, false);
                return;
            }
            // Only touch the selection if something changes - setting it again discards
            // the browser's typing mode (bold toggle without selected text)
            const stray = Array.from(root.childNodes).some((n) => n.nodeType === 3 || (n.nodeType === 1 && !BLOCK_TAGS.test(n.nodeName)));
            const styled = root.querySelector('font, [style]:not(.htv-ch):not([data-htv-a])');
            if (!stray && !styled) return;
            const s = saveSel(root);
            let p = null;
            Array.from(root.childNodes).forEach((n) => {
                const inline = n.nodeType === 3 || (n.nodeType === 1 && !BLOCK_TAGS.test(n.nodeName));
                if (!inline) { p = null; return; }
                if (n.nodeType === 3 && !n.data.trim() && !p) { n.remove(); return; }
                if (!p) { p = document.createElement('p'); root.insertBefore(p, n); }
                p.appendChild(n);
            });
            // elements with own attributes (data-htv-a) keep their style
            root.querySelectorAll('span[style]:not([data-htv-a]), font').forEach((n) => { if (!n.classList.contains('htv-ch')) unwrapEl(n); });
            root.querySelectorAll('[style]:not([data-htv-a])').forEach((n) => { if (!n.classList.contains('htv-ch')) n.removeAttribute('style'); });
            restoreSel(root, s);
        }

        // After a table, code, rule, list or quote at the end: a paragraph to keep writing
        ensureTrailing() {
            const last = this.content.lastElementChild;
            if (!last || /^(TABLE|PRE|HR|BLOCKQUOTE|UL|OL)$/.test(last.nodeName)) this.content.appendChild(newP());
        }

        // ---- State of the toolbar ----

        updateState() {
            if (!this.buttons) return;
            const root = this.content;
            const r = this.sourceMode ? null : getRange(root);
            const node = r ? r.startContainer : null;
            const tb = node ? textBlockOf(root, node) : null;
            const inPre = !!(tb && tb.nodeName === 'PRE');
            const cell = node ? closestIn(root, node, 'td,th') : null;
            const li = node ? closestIn(root, node, 'li') : null;
            const qs = (cmd) => {
                if (!r || inPre) return false;
                try { return document.queryCommandState(cmd); } catch (e) { return false; }
            };
            let align = '';
            if (cell) align = cell.getAttribute('data-align') || '';
            else if (li) align = topList(li).getAttribute('data-align') || '';
            else if (tb) align = tb.getAttribute('data-align') || '';
            const active = {
                bold: qs('bold'),
                italic: qs('italic'),
                underline: qs('underline'),
                code: !!(node && closestIn(root, node, 'code')),
                heading: !!(tb && /^H[1-6]$/.test(tb.nodeName)),
                ul: !!(li && li.parentNode.nodeName === 'UL'),
                ol: !!(li && li.parentNode.nodeName === 'OL'),
                quote: !!(node && closestIn(root, node, 'blockquote')),
                codeblock: inPre,
                link: !!(node && closestIn(root, node, 'a')),
                align_left: !!tb && !inPre && (align === '' || align === 'left'),
                align_center: align === 'center',
                align_right: align === 'right',
                table: !!cell,
                source: this.sourceMode
            };
            const disabled = {
                bold: inPre, italic: inPre, underline: inPre, code: inPre, link: inPre,
                heading: inPre || !!cell || !!li,
                ul: inPre || !!cell, ol: inPre || !!cell,
                quote: !!cell, codeblock: !!cell || !!li,
                indent: !li, outdent: !li,
                align_left: inPre, align_center: inPre, align_right: inPre,
                undo: this.hIndex <= 0 && !this.typingTimer,
                redo: this.hIndex >= this.history.length - 1
            };
            // Writing direction: the icon shows the direction at the caret - that of the
            // field (dir per language) or of an enclosing bdi
            if (this.buttons.dir) {
                const at = node ? (node.nodeType === 1 ? node : node.parentNode) : root;
                const rtl = getComputedStyle(at || root).direction === 'rtl';
                const b = this.buttons.dir;
                b.querySelector('.icon').textContent = rtl ? 'format_textdirection_r_to_l' : 'format_textdirection_l_to_r';
                b.title = this.t('dir') + ': ' + this.t(rtl ? 'dir_rtl' : 'dir_ltr');
                b.setAttribute('aria-label', b.title);
                active.dir = !!(node && closestIn(root, node, 'bdi'));
                disabled.dir = inPre;
            }
            Object.keys(this.buttons).forEach((name) => {
                const b = this.buttons[name];
                b.classList.toggle('active', !!active[name]);
                b.classList.toggle('is-disabled', !this.sourceMode && !!disabled[name]);
                b.setAttribute('aria-disabled', !this.sourceMode && disabled[name] ? 'true' : 'false');
                if (name === 'source') b.setAttribute('aria-pressed', this.sourceMode ? 'true' : 'false');
            });
        }

        onButton(name, btn) {
            if (name === 'source') { this.toggleSource(); return; }
            if (this.sourceMode || this.readonly || btn.classList.contains('is-disabled')) return;
            if (!getRange(this.content)) this.focusEnd();
            switch (name) {
                case 'bold':
                case 'italic':
                case 'underline': this.exec(() => this.inline(name)); break;
                case 'code': this.exec(() => this.toggleCode()); break;
                case 'heading': this.menuHeading(btn); break;
                case 'ul': this.exec(() => this.toggleList('UL')); break;
                case 'ol': this.exec(() => this.toggleList('OL')); break;
                case 'indent': this.exec(() => this.indent(false)); break;
                case 'outdent': this.exec(() => this.indent(true)); break;
                case 'quote': this.exec(() => this.toggleQuote()); break;
                case 'codeblock': this.exec(() => this.toggleCodeBlock()); break;
                case 'link': this.openLink(btn); break;
                case 'hr': this.exec(() => this.insertHr()); break;
                case 'br': this.exec(() => this.insertBreak()); break;
                case 'special': this.menuSpecial(btn); break;
                case 'align_left': this.exec(() => this.setAlign('')); break;
                case 'align_center': this.exec(() => this.setAlign('center')); break;
                case 'align_right': this.exec(() => this.setAlign('right')); break;
                case 'dir': this.exec(() => this.toggleDir()); break;
                case 'table': this.menuTable(btn); break;
                case 'undo': this.undo(); break;
                case 'redo': this.redo(); break;
            }
        }

        // ---- Events ----

        onKeydown(e) {
            if (e.isComposing || e.keyCode === 229) return;
            if (e.key === 'Escape' && !this.pop.hidden) { e.preventDefault(); this.closePop(); return; }
            const mod = isMac ? e.metaKey : e.ctrlKey;
            if (mod && !e.altKey) {
                const k = e.key.toLowerCase();
                if (k === 'z') { e.preventDefault(); if (e.shiftKey) this.redo(); else this.undo(); return; }
                if (k === 'y' && !isMac) { e.preventDefault(); this.redo(); return; }
                if (k === 'b' || k === 'i' || k === 'u') {
                    e.preventDefault();
                    if (!this.inPre()) this.exec(() => this.inline(k === 'b' ? 'bold' : (k === 'i' ? 'italic' : 'underline')));
                    return;
                }
                if (k === 'k') { e.preventDefault(); if (!this.inPre()) this.openLink(this.buttons.link); return; }
            }
            if (mod && e.altKey && /^Digit[0-6]$/.test(e.code)) {
                e.preventDefault();
                const n = +e.code.slice(5);
                this.exec(() => this.setBlockType(n ? 'H' + n : 'P'));
                return;
            }
            switch (e.key) {
                case 'Enter':
                    e.preventDefault();
                    this.exec(() => this.enter(e.shiftKey));
                    break;
                case 'Backspace': this.onBackspace(e); break;
                case 'Delete': this.onDelete(e); break;
                case 'Tab': this.onTab(e); break;
                case 'ArrowRight': this.onArrowRight(e); break;
            }
        }

        onBeforeInput(e) {
            if (this.busy) return;
            switch (e.inputType) {
                case 'historyUndo': e.preventDefault(); this.undo(); break;
                case 'historyRedo': e.preventDefault(); this.redo(); break;
                case 'insertParagraph': e.preventDefault(); this.exec(() => this.enter(false)); break;
                case 'insertLineBreak': e.preventDefault(); this.exec(() => this.enter(true)); break;
                case 'formatBold': e.preventDefault(); this.exec(() => this.inline('bold')); break;
                case 'formatItalic': e.preventDefault(); this.exec(() => this.inline('italic')); break;
                case 'formatUnderline': e.preventDefault(); this.exec(() => this.inline('underline')); break;
                case 'formatIndent': e.preventDefault(); this.exec(() => this.indent(false)); break;
                case 'formatOutdent': e.preventDefault(); this.exec(() => this.indent(true)); break;
                case 'formatJustifyLeft': e.preventDefault(); this.exec(() => this.setAlign('')); break;
                case 'formatJustifyCenter': e.preventDefault(); this.exec(() => this.setAlign('center')); break;
                case 'formatJustifyRight': e.preventDefault(); this.exec(() => this.setAlign('right')); break;
                default:
                    // other formats (strikethrough, colors, fonts …) do not exist
                    if (/^format/.test(e.inputType)) e.preventDefault();
            }
        }

        onInput(e) {
            if (this.busy) return;
            this.normalize();
            if (e.inputType === 'insertText' && (e.data === ' ' || e.data === ' ')) this.markdownShortcut();
            this.scheduleSync();
            clearTimeout(this.typingTimer);
            this.typingTimer = setTimeout(() => { this.typingTimer = null; this.snapshot(); this.updateState(); }, 400);
        }

        inPre() {
            const r = getRange(this.content);
            return !!(r && closestIn(this.content, r.startContainer, 'pre'));
        }

        // "# ", "- ", "1. ", "> " at the start of an empty paragraph
        markdownShortcut() {
            const r = getRange(this.content);
            if (!r || !r.collapsed) return;
            const tb = textBlockOf(this.content, r.startContainer);
            if (!tb || !/^(P|DIV)$/.test(tb.nodeName) || closestIn(this.content, tb, 'li,td,th')) return;
            if (tb.querySelector('*:not(br)')) return;
            const m = tb.textContent.match(/^(#{1,6}|[-*+]|(\d{1,9})[.)]|>)[  ]$/);
            if (!m) return;
            clearTimeout(this.typingTimer);
            this.typingTimer = null;
            this.snapshot();
            this.exec(() => {
                tb.innerHTML = '<br>';
                caretInto(tb, false);
                const marker = m[1];
                if (marker[0] === '#') this.setBlockType('H' + marker.length);
                else if (marker === '>') this.toggleQuote();
                else if (m[2]) {
                    this.toggleList('OL');
                    const list = closestIn(this.content, getRange(this.content).startContainer, 'ol');
                    if (list && +m[2] !== 1) list.setAttribute('start', String(+m[2]));
                }
                else this.toggleList('UL');
            });
        }

        onBackspace(e) {
            const root = this.content;
            const r = getRange(root);
            if (!r || !r.collapsed) return;
            const tb = textBlockOf(root, r.startContainer);
            if (!tb || !isAtStartOf(tb, r.startContainer, r.startOffset)) return;
            const tag = tb.nodeName;
            if (tag === 'TD' || tag === 'TH') { e.preventDefault(); return; }
            if (tag === 'PRE') {
                if (preText(tb) === '') {
                    e.preventDefault();
                    this.exec(() => { const p = renameEl(tb, 'P'); p.innerHTML = '<br>'; caretInto(p, false); });
                }
                return;
            }
            if (tag === 'LI') {
                e.preventDefault();
                this.exec(() => {
                    if (isNested(tb)) { this.outdentItem(tb); caretInto(tb, false); }
                    else caretInto(this.liftItem(tb), false);
                });
                return;
            }
            if (/^H[1-6]$/.test(tag)) {
                e.preventDefault();
                this.exec(() => caretInto(renameEl(tb, 'P'), false));
                return;
            }
            const parent = tb.parentNode;
            if (parent !== root && parent.nodeName === 'BLOCKQUOTE' && tb === parent.firstElementChild) {
                e.preventDefault();
                this.exec(() => { this.liftFromQuote(tb); caretInto(tb, false); });
                return;
            }
            const prev = this.unitOf(tb).previousElementSibling;
            if (!prev) return;
            if (prev.nodeName === 'HR') {
                e.preventDefault();
                this.exec(() => { prev.remove(); caretInto(tb, false); });
            }
            else if (prev.nodeName === 'TABLE' || prev.nodeName === 'PRE') {
                // do not merge with a table or code - only jump into it
                e.preventDefault();
                this.exec(() => {
                    const target = prev.nodeName === 'TABLE' ? prev.rows[prev.rows.length - 1].cells[prev.rows[prev.rows.length - 1].cells.length - 1] : prev;
                    if (isEmptyBlock(tb)) tb.remove();
                    caretInto(target, true);
                });
            }
        }

        onDelete(e) {
            const root = this.content;
            const r = getRange(root);
            if (!r || !r.collapsed) return;
            const tb = textBlockOf(root, r.startContainer);
            if (!tb || !isAtEndOf(tb, r.startContainer, r.startOffset)) return;
            if (tb.nodeName === 'TD' || tb.nodeName === 'TH') { e.preventDefault(); return; }
            if (tb.nodeName === 'PRE' || tb.nodeName === 'LI') return;
            const next = this.unitOf(tb).nextElementSibling;
            if (!next) return;
            if (next.nodeName === 'HR') {
                e.preventDefault();
                this.exec(() => { next.remove(); caretInto(tb, true); });
            }
            else if (next.nodeName === 'TABLE' || next.nodeName === 'PRE') {
                e.preventDefault();
                this.exec(() => {
                    const target = next.nodeName === 'TABLE' ? next.rows[0].cells[0] : next;
                    if (isEmptyBlock(tb)) tb.remove();
                    caretInto(target, false);
                });
            }
        }

        onTab(e) {
            const r = getRange(this.content);
            if (!r) return;
            const tb = textBlockOf(this.content, r.startContainer);
            if (!tb) return;
            if (tb.nodeName === 'LI') { e.preventDefault(); this.exec(() => this.indent(e.shiftKey)); return; }
            if (tb.nodeName === 'TD' || tb.nodeName === 'TH') { e.preventDefault(); this.exec(() => this.moveCell(tb, e.shiftKey)); return; }
            if (tb.nodeName === 'PRE' && !e.shiftKey) { e.preventDefault(); this.exec(() => this.preInsert(tb, '\t')); }
        }

        // At the end of inline code: out of the code
        onArrowRight(e) {
            if (e.shiftKey || e.altKey || e.metaKey || e.ctrlKey) return;
            const r = getRange(this.content);
            if (!r || !r.collapsed) return;
            const code = closestIn(this.content, r.startContainer, 'code');
            if (!code || !isAtEndOf(code, r.startContainer, r.startOffset)) return;
            const next = code.nextSibling;
            if (next && !(next.nodeName === 'BR') && !(next.nodeType === 3 && next.data === '')) return;
            e.preventDefault();
            const t = document.createTextNode('​');
            code.after(t);
            caretAt(t, 1);
        }

        onPaste(e) {
            if (this.readonly) return;
            e.preventDefault();
            const cd = e.clipboardData;
            if (!cd) return;
            this.insertExternal(cd.getData('text/html'), cd.getData('text/plain'));
        }

        onDrop(e) {
            if (this.dragging || this.readonly) return;
            e.preventDefault();
            const dt = e.dataTransfer;
            if (!dt) return;
            const html = dt.getData('text/html');
            const text = dt.getData('text/plain');
            if (!html && !text) return;
            let r = null;
            if (document.caretRangeFromPoint) r = document.caretRangeFromPoint(e.clientX, e.clientY);
            else if (document.caretPositionFromPoint) {
                const p = document.caretPositionFromPoint(e.clientX, e.clientY);
                if (p) { r = document.createRange(); r.setStart(p.offsetNode, p.offset); r.collapse(true); }
            }
            if (r && this.content.contains(r.startContainer)) { this.content.focus({ preventScroll: true }); setRange(r); }
            this.insertExternal(html, text);
        }

        // ---- Blocks ----

        // Flow area (editor or quote) and the unit inside it that contains el
        containerOf(el) {
            let p = el.parentNode;
            while (p !== this.content && p.nodeName !== 'BLOCKQUOTE') p = p.parentNode;
            return p;
        }

        unitOf(el) {
            const c = this.containerOf(el);
            while (el.parentNode !== c) el = el.parentNode;
            return el;
        }

        // Text blocks of the selection in document order
        selectedBlocks() {
            const root = this.content;
            const r = getRange(root);
            if (!r) return [];
            if (r.collapsed) {
                const b = textBlockOf(root, r.startContainer);
                return b ? [b] : [];
            }
            const out = [];
            const walker = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT, {
                acceptNode: (el) => (TEXTBLOCK.test(el.nodeName) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_SKIP)
            });
            while (walker.nextNode()) {
                const el = walker.currentNode;
                if (!r.intersectsNode(el)) continue;
                const own = ownNodes(el);
                if (own.length !== el.childNodes.length && !own.some((n) => r.intersectsNode(n))) continue;
                out.push(el);
            }
            // A triple click selects up to the start of the next block - that one does not count
            if (out.length > 1 && isAtStartOf(out[out.length - 1], r.endContainer, r.endOffset)) out.pop();
            if (!out.length) {
                const b = textBlockOf(root, r.startContainer);
                if (b) out.push(b);
            }
            return out;
        }

        // Units from the first to the last selected one in the common flow area
        unitsRange(blocks) {
            let c = this.containerOf(blocks[0]);
            while (c !== this.content && !blocks.every((b) => c.contains(b))) c = this.containerOf(c);
            const units = [];
            blocks.forEach((b) => {
                let u = b;
                while (u.parentNode !== c) u = u.parentNode;
                if (!units.includes(u)) units.push(u);
            });
            const first = units[0];
            const last = units[units.length - 1];
            const range = [];
            for (let n = first; n; n = n.nextElementSibling) {
                range.push(n);
                if (n === last) break;
            }
            return range;
        }

        setBlockType(tag) {
            const blocks = this.selectedBlocks().filter((b) => /^(P|DIV|H[1-6])$/.test(b.nodeName) && !closestIn(this.content, b, 'li,td,th'));
            if (!blocks.length) return;
            const s = saveSel(this.content);
            let last = null;
            blocks.forEach((b) => { last = b.nodeName === tag ? b : renameEl(b, tag); });
            restoreSel(this.content, s, last);
        }

        toggleQuote() {
            const root = this.content;
            const blocks = this.selectedBlocks().filter((b) => !closestIn(root, b, 'td,th'));
            if (!blocks.length) return;
            const s = saveSel(root);
            const quotes = blocks.map((b) => closestIn(root, b, 'blockquote'));
            if (quotes.every((q) => q)) {
                quotes.filter((q, k) => quotes.indexOf(q) === k).forEach(unwrapEl);
            }
            else {
                const units = this.unitsRange(blocks);
                const q = document.createElement('blockquote');
                units[0].before(q);
                units.forEach((u) => q.appendChild(u));
            }
            restoreSel(root, s, blocks[blocks.length - 1]);
        }

        liftFromQuote(block) {
            const q = block.parentNode;
            const rest = q.cloneNode(false);
            while (block.nextSibling) rest.appendChild(block.nextSibling);
            q.after(block);
            if (rest.firstChild) block.after(rest);
            if (!q.firstElementChild && !q.textContent.trim()) q.remove();
        }

        toggleList(tag) {
            const root = this.content;
            const blocks = this.selectedBlocks().filter((b) => !closestIn(root, b, 'td,th') && b.nodeName !== 'PRE');
            if (!blocks.length) return;
            const s = saveSel(root);
            const lis = blocks.filter((b) => b.nodeName === 'LI');
            let fallback = null;
            if (lis.length === blocks.length && lis.every((li) => li.parentNode.nodeName === tag)) {
                lis.forEach((li) => { fallback = this.liftItem(li); });
            }
            else {
                lis.forEach((li) => {
                    const list = li.parentNode;
                    if (list.nodeName !== tag) {
                        const n = renameEl(list, tag);
                        if (tag === 'OL' && list.getAttribute('start')) n.setAttribute('start', list.getAttribute('start'));
                    }
                });
                const others = blocks.filter((b) => b.nodeName !== 'LI' && !closestIn(root, b, 'li'));
                if (others.length) fallback = this.wrapInList(others, tag);
            }
            restoreSel(root, s, fallback);
        }

        wrapInList(blocks, tag) {
            let li = null;
            blocks.forEach((b) => {
                let list = b.previousElementSibling;
                if (!list || list.nodeName !== tag) {
                    list = document.createElement(tag);
                    if (b.getAttribute('data-align')) list.setAttribute('data-align', b.getAttribute('data-align'));
                    b.before(list);
                }
                li = document.createElement('li');
                while (b.firstChild) li.appendChild(b.firstChild);
                list.appendChild(li);
                b.remove();
                fixEmpty(li);
                const next = list.nextElementSibling;
                if (next && next.nodeName === tag) {
                    while (next.firstChild) list.appendChild(next.firstChild);
                    next.remove();
                }
            });
            return li;
        }

        // Detach the item from the list entirely - it becomes a paragraph; sub-lists remain lists
        liftItem(li) {
            while (isNested(li)) this.outdentItem(li);
            const list = li.parentNode;
            const index = Array.prototype.indexOf.call(list.children, li);
            const after = list.cloneNode(false);
            while (li.nextSibling) after.appendChild(li.nextSibling);
            if (after.nodeName === 'OL') {
                const start = (parseInt(list.getAttribute('start'), 10) || 1) + index;
                if (start !== 1) after.setAttribute('start', String(start));
                else after.removeAttribute('start');
            }
            const p = document.createElement('p');
            if (list.getAttribute('data-align')) p.setAttribute('data-align', list.getAttribute('data-align'));
            const subs = [];
            Array.from(li.childNodes).forEach((c) => {
                if (c.nodeType === 1 && /^(UL|OL)$/.test(c.nodeName)) subs.push(c);
                else p.appendChild(c);
            });
            fixEmpty(p);
            list.after(p);
            let ref = p;
            subs.forEach((sub) => { ref.after(sub); ref = sub; });
            if (after.firstChild) {
                if (ref !== p && ref.nodeName === after.nodeName) { while (after.firstChild) ref.appendChild(after.firstChild); }
                else ref.after(after);
            }
            li.remove();
            if (!list.firstElementChild) list.remove();
            return p;
        }

        indentItem(li) {
            const prev = li.previousElementSibling;
            if (!prev || prev.nodeName !== 'LI') return;
            const tag = li.parentNode.nodeName;
            let sub = prev.lastElementChild;
            if (!sub || sub.nodeName !== tag || sub !== prev.lastChild) {
                sub = document.createElement(tag);
                prev.appendChild(sub);
            }
            sub.appendChild(li);
        }

        outdentItem(li) {
            const list = li.parentNode;
            const parentLi = list.parentNode;
            if (!parentLi || parentLi.nodeName !== 'LI') return;
            // following items become sub-items of the outdented one
            if (li.nextElementSibling) {
                const lastSub = li.lastElementChild;
                let sub = lastSub && lastSub.nodeName === list.nodeName ? lastSub : null;
                if (!sub) { sub = document.createElement(list.nodeName); li.appendChild(sub); }
                while (li.nextSibling) sub.appendChild(li.nextSibling);
            }
            parentLi.after(li);
            if (!list.firstElementChild) list.remove();
        }

        indent(out) {
            const lis = this.selectedBlocks().filter((b) => b.nodeName === 'LI');
            const top = lis.filter((li) => !lis.some((o) => o !== li && o.contains(li)));
            if (!top.length) return;
            const s = saveSel(this.content);
            let fallback = top[0];
            top.forEach((li) => {
                if (!out) this.indentItem(li);
                else if (isNested(li)) this.outdentItem(li);
                else fallback = this.liftItem(li);
            });
            restoreSel(this.content, s, fallback);
        }

        toggleCodeBlock() {
            const root = this.content;
            const r = getRange(root);
            const tb = r ? textBlockOf(root, r.startContainer) : null;
            if (tb && tb.nodeName === 'PRE') {
                const lines = preText(tb).replace(/\n+$/, '').split('\n').filter((l) => l.trim() !== '');
                const ps = (lines.length ? lines : ['']).map((l) => {
                    const p = document.createElement('p');
                    if (l) p.textContent = l;
                    else p.appendChild(newBr());
                    return p;
                });
                ps.forEach((p) => tb.before(p));
                tb.remove();
                caretInto(ps[0], false);
                return;
            }
            const blocks = this.selectedBlocks().filter((b) => /^(P|DIV|H[1-6])$/.test(b.nodeName) && !closestIn(root, b, 'li,td,th'));
            if (!blocks.length) return;
            const pre = document.createElement('pre');
            const text = blocks.map((b) => nodeText(b, null).replace(/\n+$/, '')).join('\n');
            pre.textContent = text;
            if (text === '' || text.endsWith('\n')) pre.appendChild(newBr());
            blocks[0].before(pre);
            blocks.forEach((b) => b.remove());
            caretInto(pre, true);
        }

        // New block at the caret: split the paragraph or replace an empty paragraph
        placeBlock(node) {
            const root = this.content;
            const r = getRange(root);
            const tb = r ? textBlockOf(root, r.startContainer) : null;
            if (!tb) { root.appendChild(node); }
            else {
                const unit = this.unitOf(tb);
                if (unit === tb && /^(P|DIV|H[1-6])$/.test(tb.nodeName)) {
                    if (isEmptyBlock(tb)) tb.before(node);
                    else {
                        this.splitAtCaret(tb);
                        tb.after(node);
                        if (isEmptyBlock(tb)) tb.remove();
                    }
                }
                else unit.after(node);
            }
            const next = node.nextElementSibling;
            if (!next || !/^(P|DIV|H[1-6]|UL|OL|BLOCKQUOTE)$/.test(next.nodeName)) node.after(newP());
        }

        insertHr() {
            const hr = document.createElement('hr');
            this.placeBlock(hr);
            caretInto(hr.nextElementSibling, false);
        }

        // The caret splits the text block; the rear part becomes a new block after it
        splitAtCaret(tb) {
            const r = getRange(this.content);
            const tail = document.createRange();
            tail.setStart(r.startContainer, r.startOffset);
            tail.setEnd(tb, tb.childNodes.length);
            const frag = tail.extractContents();
            const n = tb.cloneNode(false);
            n.appendChild(frag);
            tb.after(n);
            cleanupEmptyInline(tb);
            cleanupEmptyInline(n);
            fixEmpty(tb);
            fixEmpty(n);
            return n;
        }

        enter(shift) {
            const root = this.content;
            let r = getRange(root);
            if (!r) return;
            if (!r.collapsed) {
                const pre = closestIn(root, r.startContainer, 'pre');
                if (pre && pre === closestIn(root, r.endContainer, 'pre')) { this.preEnter(pre); return; }
                document.execCommand('delete');
                r = getRange(root);
                if (!r) return;
            }
            let tb = textBlockOf(root, r.startContainer);
            if (!tb) {
                this.normalize();
                r = getRange(root);
                tb = r ? textBlockOf(root, r.startContainer) : null;
                if (!tb) return;
            }
            const tag = tb.nodeName;
            if (tag === 'PRE') { if (shift) this.preInsert(tb, '\n'); else this.preEnter(tb); return; }
            if (tag === 'TD' || tag === 'TH') {
                if (shift) this.insertBreak();
                else caretInto(this.addRow(tb.parentNode, 'below').cells[0], false);
                return;
            }
            if (shift) { this.insertBreak(); return; }
            if (tag === 'LI') {
                if (isEmptyBlock(tb)) {
                    if (isNested(tb)) { this.outdentItem(tb); caretInto(tb, false); }
                    else caretInto(this.liftItem(tb), false);
                    return;
                }
                caretInto(this.splitAtCaret(tb), false);
                return;
            }
            if (/^(P|DIV)$/.test(tag) && !tb.querySelector('*:not(br)')) {
                const text = tb.textContent;
                const fence = text.match(/^```([\w+#.-]*)[  ]*$/);
                if (fence) {
                    const pre = renameEl(tb, 'PRE');
                    pre.removeAttribute('data-align');
                    if (fence[1]) pre.setAttribute('data-lang', fence[1]);
                    pre.innerHTML = '<br>';
                    caretAt(pre, 0);
                    return;
                }
                if (/^(---|\*\*\*|___)[  ]*$/.test(text)) {
                    tb.before(document.createElement('hr'));
                    tb.innerHTML = '<br>';
                    caretInto(tb, false);
                    return;
                }
            }
            // Enter in an empty aligned paragraph ends the alignment (as with lists)
            if (isEmptyBlock(tb) && /^(P|DIV)$/.test(tag) && tb.getAttribute('data-align')) {
                tb.removeAttribute('data-align');
                caretInto(tb, false);
                return;
            }
            if (isEmptyBlock(tb) && tb.parentNode !== root && tb.parentNode.nodeName === 'BLOCKQUOTE') {
                this.liftFromQuote(tb);
                caretInto(tb, false);
                return;
            }
            let n = this.splitAtCaret(tb);
            if (/^H[1-6]$/.test(tag)) {
                if (isEmptyBlock(n)) n = renameEl(n, 'P');
                if (isEmptyBlock(tb)) renameEl(tb, 'P');
            }
            else if (tag === 'DIV') n = renameEl(n, 'P');
            caretInto(n, false);
        }

        insertBreak() {
            const r = getRange(this.content);
            if (!r) return;
            r.deleteContents();
            const br = newBr();
            r.insertNode(br);
            const tb = textBlockOf(this.content, br);
            // a <br> at the end of a block shows no new line - placeholder after it
            if (tb && this.isLastInBlock(br, tb)) br.after(newBr());
            const nr = document.createRange();
            nr.setStartAfter(br);
            nr.collapse(true);
            setRange(nr);
        }

        isLastInBlock(node, tb) {
            for (let n = node; n && n !== tb; n = n.parentNode) {
                for (let s = n.nextSibling; s; s = s.nextSibling) {
                    if (s.nodeType === 1 && /^(UL|OL)$/.test(s.nodeName)) return true;
                    if (s.nodeName === 'BR' || hasText(s)) return false;
                }
            }
            return true;
        }

        setAlign(align) {
            const root = this.content;
            const r = getRange(root);
            if (!r) return;
            const set = (el) => { if (align) el.setAttribute('data-align', align); else el.removeAttribute('data-align'); };
            const cell = closestIn(root, r.startContainer, 'td,th');
            if (cell) {
                // in a table: the whole column
                const table = closestIn(root, cell, 'table');
                const col = cell.cellIndex;
                Array.from(table.rows).forEach((row) => { if (row.cells[col]) set(row.cells[col]); });
                return;
            }
            const units = [];
            this.selectedBlocks().forEach((b) => {
                const u = b.nodeName === 'LI' ? topList(b) : (b.nodeName === 'PRE' ? null : b);
                if (u && !units.includes(u)) units.push(u);
            });
            units.forEach(set);
        }

        // ---- Inline ----

        inline(cmd) {
            try { document.execCommand('styleWithCSS', false, false); } catch (e) { /* does not matter */ }
            document.execCommand(cmd, false, null);
        }

        toggleCode() {
            const root = this.content;
            const r = getRange(root);
            if (!r || closestIn(root, r.startContainer, 'pre')) return;
            const code = closestIn(root, r.commonAncestorContainer, 'code');
            if (code) { this.leaveCode(code, r); return; }
            if (r.collapsed) {
                const c = document.createElement('code');
                c.textContent = '​';
                r.insertNode(c);
                caretAt(c.firstChild, 1);
                return;
            }
            const sb = textBlockOf(root, r.startContainer);
            if (!sb || sb !== textBlockOf(root, r.endContainer)) return;
            const text = r.toString().replace(/\n/g, ' ').replace(ZW_RE, '');
            if (!text) return;
            r.deleteContents();
            const c = document.createElement('code');
            c.textContent = text;
            r.insertNode(c);
            cleanupEmptyInline(sb);
            const nr = document.createRange();
            nr.selectNodeContents(c);
            setRange(nr);
        }

        // Toggle the writing direction: the selection (or from the caret on) gets the
        // opposite direction of the position as bdi[dir] - likewise in the HTML of the field. Inside a bdi
        // the toggle removes it again, like bold inside bold.
        toggleDir() {
            const root = this.content;
            const r = getRange(root);
            if (!r || closestIn(root, r.startContainer, 'pre')) return;
            const bdi = closestIn(root, r.commonAncestorContainer, 'bdi');
            if (bdi) {
                const s = saveSel(root);
                unwrapEl(bdi);
                restoreSel(root, s);
                return;
            }
            const at = r.startContainer.nodeType === 1 ? r.startContainer : r.startContainer.parentNode;
            const dir = getComputedStyle(at).direction === 'rtl' ? 'ltr' : 'rtl';
            const el = document.createElement('bdi');
            el.setAttribute('dir', dir);
            if (r.collapsed) {
                el.textContent = '​';
                r.insertNode(el);
                caretAt(el.firstChild, 1);
                return;
            }
            const sb = textBlockOf(root, r.startContainer);
            if (!sb || sb !== textBlockOf(root, r.endContainer)) return;
            el.appendChild(r.extractContents());
            r.insertNode(el);
            // nested isolates of the selection are dropped - the new direction applies
            el.querySelectorAll('bdi').forEach(unwrapEl);
            cleanupEmptyInline(sb);
            const nr = document.createRange();
            nr.selectNodeContents(el);
            setRange(nr);
        }

        // Code toggle inside code: without a selection continue writing normally from the caret,
        // with a selection turn only the selected part back into normal text
        leaveCode(code, r) {
            const text = code.textContent;
            const off = (c, o) => {
                const rr = document.createRange();
                rr.setStart(code, 0);
                try { rr.setEnd(c, o); } catch (e) { return text.length; }
                return Math.min(rr.toString().length, text.length);
            };
            const s = off(r.startContainer, r.startOffset);
            const e = Math.max(s, off(r.endContainer, r.endOffset));
            const part = (t) => {
                if (t.replace(ZW_RE, '') === '') return null;
                const c = document.createElement('code');
                c.textContent = t;
                return c;
            };
            const before = part(text.slice(0, s));
            const after = part(text.slice(e));
            const mid = document.createTextNode(r.collapsed ? '​' : text.slice(s, e).replace(ZW_RE, ''));
            const frag = document.createDocumentFragment();
            if (before) frag.appendChild(before);
            frag.appendChild(mid);
            if (after) frag.appendChild(after);
            code.replaceWith(frag);
            const nr = document.createRange();
            if (r.collapsed) { nr.setStart(mid, 1); nr.collapse(true); }
            else nr.selectNodeContents(mid);
            setRange(nr);
        }

        insertChar(ch) {
            const root = this.content;
            const r = getRange(root);
            if (!r) return;
            const pre = closestIn(root, r.startContainer, 'pre');
            if (pre) { this.preInsert(pre, ch); return; }
            r.deleteContents();
            let node;
            if (INVISIBLE[ch]) node = fragmentOf(specialHtml(ch)).firstChild;
            else node = document.createTextNode(ch);
            r.insertNode(node);
            let after = node;
            if (node.nodeType === 1 && (!node.nextSibling || node.nextSibling.nodeName === 'BR')) {
                // after a non-editable span the caret needs text
                after = document.createTextNode('​');
                node.after(after);
            }
            const nr = document.createRange();
            if (after.nodeType === 3 && after !== node) nr.setStart(after, 1);
            else nr.setStartAfter(after);
            nr.collapse(true);
            setRange(nr);
        }

        // ---- Code block ----

        preState(pre) {
            const r = getRange(this.content);
            const text = preText(pre);
            const off = (c, o) => {
                const rr = document.createRange();
                rr.setStart(pre, 0);
                try { rr.setEnd(c, o); } catch (e) { return text.length; }
                const box = document.createElement('div');
                box.appendChild(rr.cloneContents());
                return nodeText(box, null).length;
            };
            const s = r ? Math.min(off(r.startContainer, r.startOffset), text.length) : text.length;
            const e = r ? Math.min(Math.max(s, off(r.endContainer, r.endOffset)), text.length) : s;
            return { text: text, s: s, e: e };
        }

        setPre(pre, text, pos) {
            pre.textContent = text;
            if (text === '' || text.endsWith('\n')) pre.appendChild(newBr());
            if (text === '') caretAt(pre, 0);
            else caretAt(pre.firstChild, Math.min(pos, text.length));
        }

        preInsert(pre, str) {
            const st = this.preState(pre);
            this.setPre(pre, st.text.slice(0, st.s) + str + st.text.slice(st.e), st.s + str.length);
        }

        preEnter(pre) {
            const st = this.preState(pre);
            const before = st.text.slice(0, st.s);
            const after = st.text.slice(st.e);
            // Enter on the empty last line: out of the code block
            if (after === '' && before.endsWith('\n')) {
                this.setPre(pre, before.slice(0, -1), before.length - 1);
                let p = pre.nextElementSibling;
                if (!p || p.nodeName !== 'P' || !isEmptyBlock(p)) { p = newP(); pre.after(p); }
                caretInto(p, false);
                return;
            }
            // Enter at the start of the first block: paragraph before it
            if (st.s === 0 && st.e === 0 && st.text !== '' && !pre.previousElementSibling) {
                const p = newP();
                pre.before(p);
                caretInto(p, false);
                return;
            }
            this.setPre(pre, before + '\n' + after, st.s + 1);
        }

        // ---- Tables ----

        tableCtx() {
            const r = getRange(this.content);
            const cell = r ? closestIn(this.content, r.startContainer, 'td,th') : null;
            if (!cell) return null;
            const row = cell.parentNode;
            return { cell: cell, row: row, table: closestIn(this.content, row, 'table'), col: cell.cellIndex };
        }

        cols(table) {
            return Math.max(...Array.from(table.rows).map((r) => r.cells.length));
        }

        newCell(tag, align) {
            const c = document.createElement(tag);
            if (align) c.setAttribute('data-align', align);
            c.appendChild(newBr());
            return c;
        }

        colAlign(table, k) {
            const c = table.rows[0] && table.rows[0].cells[k];
            return c ? c.getAttribute('data-align') || '' : '';
        }

        convertRow(row, tag) {
            Array.from(row.cells).forEach((c) => { if (c.nodeName !== tag) renameEl(c, tag); });
            return row;
        }

        body(table) {
            return table.tBodies[0] || table.createTBody();
        }

        addRow(row, where) {
            const table = closestIn(this.content, row, 'table');
            const cols = this.cols(table);
            const make = (tag) => {
                const tr = document.createElement('tr');
                for (let k = 0; k < cols; k++) tr.appendChild(this.newCell(tag, this.colAlign(table, k)));
                return tr;
            };
            const inHead = row.parentNode.nodeName === 'THEAD';
            if (where === 'below') {
                const tr = make('TD');
                if (inHead) { const body = this.body(table); body.insertBefore(tr, body.firstChild); }
                else row.after(tr);
                return tr;
            }
            if (inHead) {
                // new header row, the previous one becomes the first data row
                const tr = make('TH');
                const body = this.body(table);
                const head = row.parentNode;
                body.insertBefore(this.convertRow(row, 'TD'), body.firstChild);
                head.appendChild(tr);
                return tr;
            }
            const tr = make('TD');
            row.before(tr);
            return tr;
        }

        deleteRow(ctx) {
            const table = ctx.table;
            const rows = Array.from(table.rows);
            if (rows.length <= 1) { this.deleteTable(ctx); return; }
            let target;
            if (ctx.row.parentNode.nodeName === 'THEAD') {
                // the first data row becomes the header row
                target = rows[1];
                ctx.row.parentNode.appendChild(this.convertRow(target, 'TH'));
                ctx.row.remove();
            }
            else {
                const idx = rows.indexOf(ctx.row);
                ctx.row.remove();
                target = table.rows[Math.min(idx, table.rows.length - 1)];
            }
            Array.from(table.tBodies).forEach((b) => { if (!b.rows.length) b.remove(); });
            caretInto(target.cells[Math.min(ctx.col, target.cells.length - 1)], true);
        }

        addCol(ctx, where) {
            Array.from(ctx.table.rows).forEach((tr) => {
                const ref = tr.cells[ctx.col];
                const c = this.newCell(tr.parentNode.nodeName === 'THEAD' ? 'TH' : 'TD', '');
                if (!ref) tr.appendChild(c);
                else if (where === 'right') ref.after(c);
                else ref.before(c);
            });
            caretInto(ctx.row.cells[where === 'right' ? ctx.col + 1 : ctx.col], false);
        }

        deleteCol(ctx) {
            if (this.cols(ctx.table) <= 1) { this.deleteTable(ctx); return; }
            Array.from(ctx.table.rows).forEach((tr) => { if (tr.cells[ctx.col]) tr.cells[ctx.col].remove(); });
            caretInto(ctx.row.cells[Math.min(ctx.col, ctx.row.cells.length - 1)], true);
        }

        deleteTable(ctx) {
            const t = ctx.table;
            let p = t.nextElementSibling;
            if (!p || p.nodeName !== 'P') { p = newP(); t.after(p); }
            t.remove();
            caretInto(p, false);
        }

        insertTable(cols, rows) {
            cols = clamp(cols, 1, 20, 3);
            rows = clamp(rows, 0, 100, 2);
            const table = document.createElement('table');
            const head = table.createTHead().insertRow();
            for (let k = 0; k < cols; k++) head.appendChild(this.newCell('TH', ''));
            if (rows) {
                const body = table.createTBody();
                for (let n = 0; n < rows; n++) {
                    const tr = body.insertRow();
                    for (let k = 0; k < cols; k++) tr.appendChild(this.newCell('TD', ''));
                }
            }
            this.placeBlock(table);
            caretInto(table.rows[0].cells[0], false);
        }

        moveCell(cell, back) {
            const table = closestIn(this.content, cell, 'table');
            const cells = Array.from(table.querySelectorAll('th,td')).filter((c) => c.closest('table') === table);
            let target = cells[cells.indexOf(cell) + (back ? -1 : 1)];
            if (!target && !back) target = this.addRow(table.rows[table.rows.length - 1], 'below').cells[0];
            if (target) caretInto(target, true);
        }

        // ---- Paste (clipboard, drag & drop) ----

        insertExternal(html, text) {
            this.exec(() => {
                const root = this.content;
                let r = getRange(root);
                if (!r) return;
                const pre = closestIn(root, r.startContainer, 'pre');
                if (pre) {
                    let plain = text;
                    if (!plain && html) plain = nodeText(new DOMParser().parseFromString(html, 'text/html').body, null);
                    if (plain) this.preInsert(pre, String(plain).replace(/\r\n?/g, '\n'));
                    return;
                }
                const blocks = clipboardBlocks(html, text);
                if (!blocks.length) return;
                if (!r.collapsed) {
                    document.execCommand('delete');
                    r = getRange(root);
                    if (!r) return;
                }
                this.insertBlocks(blocks);
            });
        }

        insertBlocks(blocks) {
            const root = this.content;
            const r = getRange(root);
            let tb = textBlockOf(root, r.startContainer);
            const cell = tb && /^(TD|TH)$/.test(tb.nodeName) ? tb : null;
            if (cell || (blocks.length === 1 && blocks[0].t === 'p' && !blocks[0].align)) {
                // Inline at the caret; in table cells only inline with line breaks
                const html = cell ? blocks.flatMap(flattenInline).filter((s) => s !== '').join('<br>') : parseInline(blocks[0].inl);
                const frag = fragmentOf(html);
                const last = frag.lastChild;
                if (!last) return;
                r.insertNode(frag);
                const nr = document.createRange();
                nr.setStartAfter(last);
                nr.collapse(true);
                setRange(nr);
                return;
            }
            const frag = fragmentOf(renderBlocks(blocks));
            if (!tb) {
                root.appendChild(frag);
                caretInto(root.lastElementChild, true);
                return;
            }
            if (tb.nodeName === 'LI') { this.insertIntoList(tb, frag); return; }
            if (tb.nodeName === 'DIV' && tb.parentNode !== root && tb.parentNode.nodeName !== 'BLOCKQUOTE') tb = this.unitOf(tb);
            const tail = this.splitAtCaret(tb);
            const nodes = Array.from(frag.childNodes);
            tail.before(frag);
            this.mergeEdges(tb, nodes, tail);
        }

        // Join the edge paragraphs of the pasted content with the split paragraph, caret at the end
        mergeEdges(head, nodes, tail) {
            const marker = document.createElement('span');
            marker.className = 'c9-htv-mark';
            const last = nodes[nodes.length - 1];
            const endBlock = this.endPoint(last);
            if (endBlock) {
                const own = ownNodes(endBlock).filter((n) => n.nodeName !== 'BR' || hasText(endBlock));
                const sub = Array.from(endBlock.childNodes).find((n) => !ownNodes(endBlock).includes(n));
                endBlock.insertBefore(marker, sub || null);
                if (!own.length) Array.from(endBlock.querySelectorAll('br')).forEach((b) => b.remove());
            }
            const first = nodes[0];
            const plain = (n) => n && n.nodeName === 'P' && !n.getAttribute('data-align');
            if (plain(first) && /^(P|DIV|H[1-6])$/.test(head.nodeName) && !isEmptyBlock(head)) {
                Array.from(head.childNodes).forEach((n) => { if (n.nodeName === 'BR' && !n.nextSibling) n.remove(); });
                while (first.firstChild) head.appendChild(first.firstChild);
                first.remove();
            }
            if (!isEmptyBlock(tail) && plain(last) && last.isConnected) {
                Array.from(tail.childNodes).forEach((n) => { if (n.nodeName === 'BR' && !hasText(tail)) n.remove(); });
                while (tail.firstChild) last.appendChild(tail.firstChild);
                tail.remove();
            }
            if (head.isConnected && isEmptyBlock(head) && head.parentNode) head.remove();
            if (tail.isConnected && isEmptyBlock(tail) && tail.nextElementSibling) tail.remove();
            if (marker.isConnected) {
                const r = document.createRange();
                r.setStartBefore(marker);
                r.collapse(true);
                const parent = marker.parentNode;
                marker.remove();
                fixEmpty(parent);
                setRange(r);
            }
            else if (tail.isConnected) caretInto(tail, false);
        }

        endPoint(node) {
            if (!node || node.nodeType !== 1) return null;
            if (/^(P|DIV|H[1-6]|PRE)$/.test(node.nodeName)) return node;
            if (/^(UL|OL)$/.test(node.nodeName)) {
                let li = node.lastElementChild;
                while (li) {
                    const sub = Array.from(li.children).reverse().find((c) => /^(UL|OL)$/.test(c.nodeName));
                    if (!sub || !sub.lastElementChild) break;
                    li = sub.lastElementChild;
                }
                return li;
            }
            if (node.nodeName === 'BLOCKQUOTE') return this.endPoint(node.lastElementChild);
            if (node.nodeName === 'TABLE') {
                const row = node.rows[node.rows.length - 1];
                return row ? row.cells[row.cells.length - 1] : null;
            }
            return null;
        }

        insertIntoList(li, frag) {
            const items = [];
            Array.from(frag.childNodes).forEach((n) => {
                if (/^(UL|OL)$/.test(n.nodeName)) items.push(...Array.from(n.children));
                else {
                    const box = document.createElement('div');
                    box.appendChild(n);
                    const html = domToBlocks(box, '').flatMap(flattenInline).filter((s) => s !== '').join('<br>');
                    if (html) {
                        const it = document.createElement('li');
                        it.innerHTML = html;
                        items.push(it);
                    }
                }
            });
            if (!items.length) return;
            const tail = this.splitAtCaret(li);
            items.forEach((it) => tail.before(it));
            this.mergeListEdges(li, items, tail);
        }

        mergeListEdges(head, items, tail) {
            const marker = document.createElement('span');
            const last = items[items.length - 1];
            const sub = Array.from(last.children).find((c) => /^(UL|OL)$/.test(c.nodeName));
            last.insertBefore(marker, sub || null);
            const first = items[0];
            if (!isEmptyBlock(head)) {
                const firstSub = Array.from(first.children).find((c) => /^(UL|OL)$/.test(c.nodeName));
                if (!firstSub) {
                    Array.from(head.childNodes).forEach((n) => { if (n.nodeName === 'BR' && !n.nextSibling) n.remove(); });
                    const headSub = Array.from(head.children).find((c) => /^(UL|OL)$/.test(c.nodeName));
                    while (first.firstChild) head.insertBefore(first.firstChild, headSub || null);
                    first.remove();
                }
            }
            else head.remove();
            const mli = marker.parentNode;
            if (!isEmptyBlock(tail) && mli) {
                ownNodes(tail).forEach((n) => { if (n.nodeName === 'BR' && !hasText(tail)) n.remove(); });
                const msub = Array.from(mli.children).find((c) => /^(UL|OL)$/.test(c.nodeName));
                while (tail.firstChild) mli.insertBefore(tail.firstChild, msub || null);
                tail.remove();
            }
            else if (isEmptyBlock(tail) && !Array.from(tail.children).some((c) => /^(UL|OL)$/.test(c.nodeName))) tail.remove();
            const r = document.createRange();
            r.setStartBefore(marker);
            r.collapse(true);
            marker.remove();
            setRange(r);
        }

        // ---- Menus and dialogs ----

        openPop(btn, build) {
            if (btn && this.popFor === btn && !this.pop.hidden) { this.closePop(); return; }
            this.closePop();
            const r = getRange(this.content);
            this.savedRange = r ? r.cloneRange() : null;
            // a new element every time - listeners of earlier dialogs must not run along
            const fresh = document.createElement('div');
            fresh.className = 'c9-htv-pop';
            fresh.hidden = true;
            this.pop.replaceWith(fresh);
            this.pop = fresh;
            build(this.pop);
            this.pop.hidden = false;
            this.popFor = btn || null;
            if (btn) btn.classList.add('open');
            const wr = this.wrap.getBoundingClientRect();
            const br = (btn || this.bar).getBoundingClientRect();
            this.pop.style.top = (br.bottom - wr.top + 2) + 'px';
            this.pop.style.left = '0px';
            const left = Math.max(0, Math.min(br.left - wr.left, this.wrap.clientWidth - this.pop.offsetWidth));
            this.pop.style.left = left + 'px';
        }

        closePop() {
            if (this.pop.hidden) return;
            this.pop.hidden = true;
            this.pop.innerHTML = '';
            if (this.popFor) this.popFor.classList.remove('open');
            this.popFor = null;
        }

        restoreRange() {
            this.content.focus({ preventScroll: true });
            if (this.savedRange && this.content.contains(this.savedRange.startContainer)) setRange(this.savedRange);
        }

        menuItem(label, action, icon) {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'c9-htv-item';
            b.innerHTML = (icon ? '<span class="icon" aria-hidden="true">' + icon + '</span>' : '') + '<span>' + esc(label) + '</span>';
            b.addEventListener('mousedown', (e) => e.preventDefault());
            b.addEventListener('click', (e) => { e.preventDefault(); action(); });
            return b;
        }

        field(pop, label, type, value, attrs) {
            const id = 'c9htv' + (++Editor.uid);
            const l = document.createElement('label');
            l.htmlFor = id;
            l.textContent = label;
            const i = document.createElement('input');
            i.type = type;
            i.id = id;
            i.className = 'form-control form-control-sm';
            i.value = value;
            Object.keys(attrs || {}).forEach((k) => i.setAttribute(k, attrs[k]));
            pop.appendChild(l);
            pop.appendChild(i);
            return i;
        }

        actions(pop, list) {
            const box = document.createElement('div');
            box.className = 'c9-htv-actions';
            list.forEach(([label, cls, fn]) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'btn btn-sm ' + cls;
                b.textContent = label;
                b.addEventListener('click', (e) => { e.preventDefault(); fn(); });
                box.appendChild(b);
            });
            pop.appendChild(box);
        }

        // Enter and Escape in the input fields of a dialog (otherwise Enter submits the form)
        dialogKeys(pop, apply) {
            pop.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); apply(); }
                else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); this.closePop(); this.restoreRange(); }
            });
        }

        currentTag() {
            const r = getRange(this.content);
            const tb = r ? textBlockOf(this.content, r.startContainer) : null;
            return tb ? tb.nodeName : '';
        }

        menuHeading(btn) {
            const cur = this.currentTag();
            this.openPop(btn, (pop) => {
                pop.classList.add('c9-htv-menu');
                ['P', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6'].forEach((tag) => {
                    const n = tag === 'P' ? 0 : +tag[1];
                    const item = this.menuItem(n ? this.t('heading_n').replace('{n}', n) : this.t('paragraph'), () => {
                        this.closePop();
                        this.exec(() => this.setBlockType(tag));
                    });
                    item.classList.add('c9-htv-h-' + tag.toLowerCase());
                    item.title = this.keyLabel('Mod+Alt+' + n);
                    if (tag === cur || (tag === 'P' && cur === 'DIV')) item.classList.add('active');
                    pop.appendChild(item);
                });
            });
        }

        menuSpecial(btn) {
            this.openPop(btn, (pop) => {
                pop.classList.add('c9-htv-special');
                const insert = (ch) => { this.closePop(); this.exec(() => this.insertChar(ch)); };
                SPECIAL.forEach((g) => {
                    const h = document.createElement('div');
                    h.className = 'c9-htv-group';
                    h.textContent = this.t('group_' + g.group);
                    pop.appendChild(h);
                    if (g.chars) {
                        const grid = document.createElement('div');
                        grid.className = 'c9-htv-grid';
                        Array.from(g.chars).forEach((ch) => {
                            const b = document.createElement('button');
                            b.type = 'button';
                            b.textContent = ch;
                            b.title = 'U+' + ch.codePointAt(0).toString(16).toUpperCase().padStart(4, '0');
                            b.addEventListener('mousedown', (e) => e.preventDefault());
                            b.addEventListener('click', (e) => { e.preventDefault(); insert(ch); });
                            grid.appendChild(b);
                        });
                        pop.appendChild(grid);
                    }
                    else {
                        g.named.forEach(([ch, name]) => {
                            const item = this.menuItem(this.t('ch_' + name), () => insert(ch));
                            const prev = document.createElement('span');
                            prev.className = 'c9-htv-chprev';
                            prev.textContent = INVISIBLE[ch] ? INVISIBLE[ch] : ch;
                            item.insertBefore(prev, item.firstChild);
                            pop.appendChild(item);
                        });
                    }
                });
            });
        }

        menuTable(btn) {
            const ctx = this.tableCtx();
            this.openPop(btn, (pop) => {
                if (ctx) {
                    pop.classList.add('c9-htv-menu');
                    const op = (label, icon, fn) => pop.appendChild(this.menuItem(label, () => {
                        this.closePop();
                        this.exec(() => { const c = this.tableCtx(); if (c) fn(c); });
                    }, icon));
                    op(this.t('row_above'), 'add_row_above', (c) => caretInto(this.addRow(c.row, 'above').cells[c.col] || c.cell, false));
                    op(this.t('row_below'), 'add_row_below', (c) => caretInto(this.addRow(c.row, 'below').cells[c.col] || c.cell, false));
                    op(this.t('row_delete'), 'variable_remove', (c) => this.deleteRow(c));
                    pop.appendChild(Object.assign(document.createElement('div'), { className: 'c9-htv-divider' }));
                    op(this.t('col_left'), 'add_column_left', (c) => this.addCol(c, 'left'));
                    op(this.t('col_right'), 'add_column_right', (c) => this.addCol(c, 'right'));
                    op(this.t('col_delete'), 'variable_remove', (c) => this.deleteCol(c));
                    pop.appendChild(Object.assign(document.createElement('div'), { className: 'c9-htv-divider' }));
                    op(this.t('table_delete'), 'delete', (c) => this.deleteTable(c));
                    return;
                }
                pop.classList.add('c9-htv-form');
                const cols = this.field(pop, this.t('table_cols'), 'number', '3', { min: '1', max: '20' });
                const rows = this.field(pop, this.t('table_rows'), 'number', '2', { min: '0', max: '100' });
                const note = document.createElement('div');
                note.className = 'form-text mb-2';
                note.textContent = this.t('table_note');
                pop.appendChild(note);
                const apply = () => {
                    const c = cols.value;
                    const n = rows.value;
                    this.closePop();
                    this.restoreRange();
                    this.exec(() => this.insertTable(c, n));
                };
                this.dialogKeys(pop, apply);
                this.actions(pop, [
                    [this.t('cancel'), 'btn-outline-secondary', () => { this.closePop(); this.restoreRange(); }],
                    [this.t('table_insert'), 'btn-primary', apply]
                ]);
                setTimeout(() => { cols.focus(); cols.select(); }, 0);
            });
        }

        openLink(btn) {
            if (this.sourceMode || this.readonly) return;
            if (!getRange(this.content)) this.focusEnd();
            const r = getRange(this.content);
            if (!r) return;
            const a = closestIn(this.content, r.startContainer, 'a') || closestIn(this.content, r.endContainer, 'a');
            const needText = r.collapsed && !a;
            this.openPop(btn, (pop) => {
                pop.classList.add('c9-htv-form');
                const url = this.field(pop, this.t('link_url'), 'text', a ? a.getAttribute('href') || '' : '', { placeholder: 'https://', autocomplete: 'off' });
                const text = needText ? this.field(pop, this.t('link_text'), 'text', '', { autocomplete: 'off' }) : null;
                const err = document.createElement('div');
                err.className = 'c9-htv-error';
                err.hidden = true;
                pop.appendChild(err);
                const apply = () => {
                    let href = url.value.trim();
                    if (/^www\./i.test(href)) href = 'https://' + href;
                    if (href && !safeUrl(href)) { err.textContent = this.t('link_invalid'); err.hidden = false; return; }
                    const label = text ? text.value.trim() : '';
                    this.closePop();
                    this.restoreRange();
                    this.exec(() => {
                        if (a && this.content.contains(a)) {
                            if (href) a.setAttribute('href', href);
                            else unwrapEl(a);
                            return;
                        }
                        if (!href) return;
                        const rr = getRange(this.content);
                        if (!rr) return;
                        if (needText) {
                            const link = document.createElement('a');
                            link.setAttribute('href', href);
                            link.textContent = label || href;
                            rr.deleteContents();
                            rr.insertNode(link);
                            const nr = document.createRange();
                            nr.setStartAfter(link);
                            nr.collapse(true);
                            setRange(nr);
                        }
                        else document.execCommand('createLink', false, href);
                    });
                };
                this.dialogKeys(pop, apply);
                const list = [[this.t('cancel'), 'btn-outline-secondary', () => { this.closePop(); this.restoreRange(); }]];
                if (a) list.unshift([this.t('link_remove'), 'btn-outline-danger', () => {
                    this.closePop();
                    this.restoreRange();
                    this.exec(() => { if (this.content.contains(a)) unwrapEl(a); });
                }]);
                list.push([this.t('link_apply'), 'btn-primary', apply]);
                this.actions(pop, list);
                setTimeout(() => { url.focus(); url.select(); }, 0);
            });
        }

        // ---- Source code ----

        toggleSource() {
            this.closePop();
            if (!this.sourceMode) {
                this.commitTyping();
                this.syncNow();
                this.sourceMode = true;
                this.sourceStart = this.el.value;
                this.el.style.display = '';
                this.el.style.minHeight = this.minHeight + 'px';
                this.el.style.height = Math.max(this.content.offsetHeight, this.minHeight) + 'px';
                this.content.hidden = true;
                this.wrap.classList.add('is-source');
                this.el.focus();
            }
            else {
                this.sourceMode = false;
                if (this.el.value !== this.sourceStart) this.setHtml(this.el.value, false);
                this.el.style.display = 'none';
                this.content.hidden = false;
                this.wrap.classList.remove('is-source');
                this.content.focus({ preventScroll: true });
                caretInto(this.content.firstElementChild || this.content, false);
            }
            this.updateState();
        }

        // ---- Life cycle ----

        beforeSubmit() {
            if (this.sourceMode) {
                if (this.el.value !== this.sourceStart) this.el.value = cleanHtml(this.el.value);
            }
            else {
                this.commitTyping();
                this.syncNow();
            }
        }

        destroy() {
            this.beforeSubmit();
            this.closePop();
            clearTimeout(this.syncTimer);
            clearTimeout(this.typingTimer);
            Object.keys(this.handlers).forEach((k) => this.content.removeEventListener(k, this.handlers[k]));
            document.removeEventListener('selectionchange', this.onSelection);
            document.removeEventListener('mousedown', this.onDocDown);
            this.el.removeEventListener('input', this.onSourceInput);
            this.el.classList.remove('c9-htv-source');
            if (this.elStyle === null) this.el.removeAttribute('style');
            else this.el.setAttribute('style', this.elStyle);
            this.wrap.before(this.el);
            this.wrap.remove();
        }
    }

    Editor.uid = 0;

    // =====================================================================
    // Binding
    // =====================================================================

    function loadStylesheet(file) {
        const href = window.cConfig.backend_path + 'Extensions/HTMLVisual/css.php?file=' + encodeURIComponent(file);
        if (document.querySelector('link[href="' + href + '"]')) return;
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        document.head.appendChild(link);
    }

    window.C9HtmlVisual = {
        attach: (el, options, onChange) => new Editor(el, options, onChange),
        toHtml: toHtml,
        cleanHtml: cleanHtml
    };

    if (window.cApp && window.cApp.extensions) {
        window.cApp.extensions.register('htmlvisual', {
            init(el, options) {
                if (options.stylesheet && window.cConfig) loadStylesheet(String(options.stylesheet));
                el._c9Htv = new Editor(el, options, () => window.cApp.forms.markChanged(el));
            },
            beforeSubmit(el) {
                if (el._c9Htv) el._c9Htv.beforeSubmit();
            },
            destroy(el) {
                if (el._c9Htv) {
                    el._c9Htv.destroy();
                    delete el._c9Htv;
                }
            }
        });
    }

}(window, document));
