# Extension Markdown (visual)

Core extension – Markdown editor for multiline text fields (field types “Short text”
and “Long text”): editors work in the ***formatted view*** as in a word processor; the
***Markdown source*** only appears via the button **<>** and can be edited there as well.
The field still contains Markdown.

Always available, no installation needed.

## What the editor can do

Bold, italic, underline, inline code, paragraph format (paragraph, heading 1–6),
bulleted and numbered lists with sub-items, quote, code block, link, horizontal
line, line break, special characters (currencies, ®, ™, dashes, non-breaking and narrow
spaces, soft hyphen …), align left, center, right, ***text direction*** for selected
sections, and tables with a header row. It does not support images.

When ***pasting***, the editor only takes over what it can display: formatted text
from Word, Google Docs or web pages is converted to Markdown, pasted Markdown is
reduced to the supported formats. Images, colors, fonts and strikethrough are dropped;
the text remains.

How editors work with it is described in the [Manual for editors](../../editors/05-editing-records.md#text)
(Editing records → Input fields → Text).

## Settings per field

| Setting | Meaning |
|---|---|
| Toolbar | *all buttons*, *basic* (bold, italic, paragraph format, lists, link), *custom* |
| Custom buttons | only with *custom*: names separated by comma or space, `\|` as separator – `bold italic underline code heading ul ol outdent indent quote codeblock link hr br special align_left align_center align_right dir table undo redo` |
| Minimum height / Maximum height | in pixels; without a maximum, the editor grows with the content |
| Stylesheet | a `.css` file from `var/extensions/markdownvisual/` |
| CSS class | class on the editor container; empty = `c9-markdownvisual-{fieldname}` |

The button **<>** (source) is always there. Keyboard shortcuts (such as Ctrl+B) and
pasting work regardless of which buttons you select.

## Markdown in the field

The editor writes common Markdown and four extensions:

| Markdown | Meaning |
|---|---|
| `++Text++` | underlined |
| `::: center` … `:::` | alignment of the enclosed paragraphs (`left`, `center`, `right`) |
| `<br>` | line break in a table cell |
| `U+2067` … `U+2069` or `U+2066` … `U+2069` | section right to left or left to right (Unicode isolates, invisible characters; also written as `&#x2067;` … `&#x2069;`) |

Invisible special characters (non-breaking space, soft hyphen …) are stored as
characters in the text.

***Text direction:*** The field writes in the direction of its language (Arabic,
Hebrew, Persian, Urdu … from right to left), single-language fields in the direction
of the backend. The button `dir` shows the direction at the cursor and switches
a selected section to the opposite direction – in Markdown enclosed by the
Unicode characters RLI (`U+2067`, right to left) or LRI (`U+2066`, left to
right) and PDI (`U+2069`, end). Browsers evaluate these characters themselves; the
output in the template needs nothing special. In the editor, such a section has a
dashed outline. A hard line break is two spaces at the end of the line.

In the template, output the field with `(CMS:TEXT fieldname markdown=true)` – the
parameter knows the extensions (see Manual for template programmers, CMS:TEXT).
Other Markdown converters show `++` and `:::` as text.

## Stylesheet

The editor sits directly in the backend page. Therefore always scope rules with
the CSS class of the field – otherwise they affect the whole backend. The
editing area is `.c9-mdv-content`:

```css
.c9-markdownvisual-text .c9-mdv-content h1 { color: #036; }
.c9-markdownvisual-text .c9-mdv-content { font-family: Georgia, serif; }
```
