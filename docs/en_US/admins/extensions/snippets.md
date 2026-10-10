# Extension Snippets (Snippet picker)

Core extension for single-line fields of all field types. Instead of a fixed text, it puts
a ***reference to a snippet*** into the field – `#prefix.key` – which appears everywhere
in the backend in the language of the respective user. Default for the label fields
(“Label”) of the modules, such as the name of an area or field. Always available,
no installation needed, no settings.

## Operation

- If the value starts with `#`, a ***preview*** to the right of the field shows the
  resolved text in your own backend language – or “Snippet not found”.
- The button ![translate](../../_/translate.svg) opens the search window. If the field
  already contains a reference, it is preset as the search term.
- The search covers the ***id and text*** of all snippets, case-insensitively; if you
  enter several terms, all of them must match. At most 50 hits are shown – if there
  are more, narrow down the search.
- A click on a hit writes `#` + id into the field. ![block](../../_/block.svg)
  “Text only (no snippet)” removes the `#`; the rest remains as normal text.
- ![close](../../_/close.svg), the Esc key or a click outside close the window.

Labels on the hits: ***backend only*** – the snippet exists only in the backend
texts; ***ICU*** – a text with placeholders (plural, numbers), shown in the preview
with empty values.

## Which snippets appear

All snippets in the user's backend language: those of the core (id without prefix), the
modules, extensions and processors, as well as the custom snippets of a website from the
module “Snippets” (`custom.{instance_id}.…`, see [chapter 10](../10-modules.md#snippets-module)) –
the latter only for websites the user group has access to.

For your own labels, first create the snippet in the module “Snippets” (missing
translations receive the English text) and then select it here. A reference to a
snippet that does not exist appears in the backend as its id.
