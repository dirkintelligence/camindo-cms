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
sections, and tables with a header row. There is no button for ***images***, so that editors do not
build their own layout; written in the source (**<>**), they are kept, though:
`![alternative text](/media/1/12/image.jpg)` – as the address `http(s):`, relative paths and
`data:image/…`. In the editor the image appears in place and can be deleted or moved like a
character.

Between two tables, code blocks or lines, where the cursor otherwise has no place,
editors insert a paragraph with the arrow keys, with a **+** at the edge of the block or
from the table menu.

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

The editor writes common Markdown and four extensions. ***Line breaks and empty lines***
count as in a plain text field:

| In the Markdown | Result |
|---|---|
| 1 line break | line break (also without two spaces at the end of the line) |
| 2 line breaks (one blank line) | new paragraph |
| *x* line breaks | new paragraph, preceded by *x* – 2 empty lines |
| a line `&nbsp;` | empty line |

Empty lines that editors create in the editor (empty paragraphs, several breaks in a row, also
at the start of the text) are stored as a line `&nbsp;` – in the editor itself they appear as
empty lines. Unlike `<br>`, `&nbsp;` also survives `strip_tags`. Empty lines at the end of the text are dropped. Leading ***spaces*** of a line are kept
as non-breaking spaces (highlighted in gray in the editor). The editor writes angle brackets
in the text as `&lt;` and `&gt;` – pasted HTML code such as `<p>` stays text that way.

The extensions:

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
dashed outline.

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
