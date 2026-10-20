# Extension HTML (visual)

Core extension – HTML editor for multiline text fields (field types “Short text” and
“Long text”). It is a copy of [Markdown (visual)](./markdownvisual.md) and is
operated the same way: editors work in the ***formatted view*** as in a word
processor; the ***HTML source*** only appears via the button **<>** and can be edited
there as well. The field contains ***HTML*** instead of Markdown.

Always available, no installation needed.

## What the editor can do

The same as Markdown (visual): bold, italic, underline, inline code,
paragraph format (paragraph, heading 1–6), bulleted and numbered lists with
sub-items, quote, code block, link, horizontal line, line break, special characters,
align left, center, right, ***text direction*** for selected sections, and
tables with a header row. Images, classes and styles can be specified in the source
(see below).

When ***pasting***, the editor only takes over what it can display: formatted text
from Word, Google Docs or web pages is trimmed to the supported formats, pasted
plain text that looks like Markdown is converted. Images, colors, fonts,
classes and strikethrough are dropped; the text remains.

The ***source*** (**<>**) allows more: HTML written or changed there additionally
keeps ***own attributes*** and ***images*** (see “Classes, styles and images” below).
Everything else is trimmed in the same way when switching back to the formatted view
and when saving. Existing HTML in the field remains
unchanged until the text is changed in the editor – then the same rules apply.

How editors work with it is described in the [Manual for editors](../../editors/05-editing-records.md#text)
(Editing records → Input fields → Text).

## Settings per field

| Setting | Meaning |
|---|---|
| Toolbar | *all buttons*, *basic* (bold, italic, paragraph format, lists, link), *custom* |
| Custom buttons | only with *custom*: names separated by comma or space, `\|` as separator – `bold italic underline code heading ul ol outdent indent quote codeblock link hr br special align_left align_center align_right dir table undo redo` |
| Minimum height / Maximum height | in pixels; without a maximum, the editor grows with the content |
| Stylesheet | a `.css` file from `var/extensions/htmlvisual/` |
| CSS class | class on the editor container; empty = `c9-htmlvisual-{fieldname}` |

The button **<>** (source) is always there. Keyboard shortcuts (such as Ctrl+B) and
pasting work regardless of which buttons you select.

## HTML in the field

The editor writes only these elements – classes, styles and images only if they were
specified in the source:

| HTML | Meaning |
|---|---|
| `<p>`, `<h1>` … `<h6>` | paragraph, heading |
| `<strong>`, `<em>`, `<u>`, `<code>` | bold, italic, underlined, inline code |
| `<a href="…">` | link (only `http(s):`, `mailto:`, `tel:`, `ftp:` and relative addresses) |
| `<br>` | line break |
| `<ul>`, `<ol start="…">`, `<li>` | lists, nested for sub-items |
| `<blockquote>` | quote |
| `<pre><code class="language-…">` | code block |
| `<hr>` | horizontal line |
| `<table>` with `<thead>`/`<tbody>`, `<th>`, `<td>` | table, first row is the header row |
| `style="text-align: left\|center\|right;"` | alignment of paragraph, heading, list, quote, table; on cells as column alignment |
| `<bdi dir="rtl">` / `<bdi dir="ltr">` | section right to left or left to right |
| `<span>`, `<img>` | only from the source, see below |

### Classes, styles and images

When specified in the source, these are kept:

| What | Where |
|---|---|
| `class`, `style`, `id`, `title` | on `<p>`, `<h1>` … `<h6>`, `<ul>`, `<ol>`, `<blockquote>`, `<pre>`, `<hr>`, `<table>` and on `<span>`, `<strong>`, `<em>`, `<u>`, `<code>`, `<a>` (`<b>`, `<i>`, `<ins>` become `<strong>`, `<em>`, `<u>`) |
| `<img>` with `src`, `alt`, `title`, `width`, `height`, `loading`, `srcset`, `sizes`, `class`, `style`, `id` | in the text, also in links and table cells; `src` only `http(s):`, relative addresses and `data:image/…` |

```html
<p class="lead">Introduction with <span class="marker">highlight</span>.</p>
<p><a class="button" href="/contact">Contact</a></p>
<p><img src="/media/1/12/picture.jpg" alt="Showroom" width="600" loading="lazy"></p>
```

In the formatted view, classes and styles take effect immediately (together with the
field's stylesheet); images appear in place and can be deleted or moved like a
character. Attributes can only be changed in the source. Alignment via the buttons
adds `text-align` to `style`; a `text-align` in the source becomes the alignment of
the block.

Not kept: attributes on list items and table cells, `<div>` (becomes a paragraph,
classes are dropped), other attributes (`data-…`, events such as `onclick`) and other
elements. ***Pasted HTML*** from the clipboard still loses classes, styles and images –
copying within the editor, on the other hand, keeps them.

Invisible special characters are stored as entities in the HTML: `&nbsp;`, `&#8239;` (narrow
non-breaking space), `&ensp;`, `&thinsp;`, `&shy;`, `&#8209;` (non-breaking
hyphen). The HTML is indented line by line so that the source remains readable.

***Text direction:*** as with Markdown (visual) – the field writes in the direction of
its language, single-language fields in the direction of the backend. A selected
section in the opposite direction becomes `<bdi dir="…">`; in the editor it has a
dashed outline.

In the template, output the field without conversion:

```cms
<div class="text">(CMS:TEXT fieldname entitydecode=false lbrconvert=false)</div>
```

`entitydecode=false` leaves escaped text (`&lt;`) escaped, `lbrconvert=false`
prevents additional `<br>` at the line breaks of the source.

## Stylesheet

The editor sits directly in the backend page. Therefore always scope rules with
the CSS class of the field – otherwise they affect the whole backend. The
editing area is `.c9-htv-content`:

```css
.c9-htmlvisual-text .c9-htv-content h1 { color: #036; }
.c9-htmlvisual-text .c9-htv-content { font-family: Georgia, serif; }
```
