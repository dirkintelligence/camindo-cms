# 11 Extensions

An ***extension*** extends an ***input field*** in the backend with additional behavior – a text editor, a calendar, a symbol selection. It is assigned in the field definition (module “Structure” → content area → edit input field → section “Extension”). The settings of the extension are below it, in the section “Settings”. A field has at most one extension, and only those that match the field and display type are offered.

Each core extension has a page of its own (links in the table). They follow as subpages of this chapter – “Next” leads through them first and then to chapter 12.

## Core extensions

They are installed with the core and are always active.

| Extension | Field types / display | What it does |
|---|---|---|
| [Calendar](./extensions/calendar.md) (“Calendar”) | Date/time, time / single line | the date and time selection of the browser next to the field; preselected for new date fields (also those created by the AI assistant) – “by field type”, minute step 1 |
| [Datepicker](./extensions/datepicker.md) | Date/time / single line | calendar in the language and date format of the user |
| [IconPicker](./extensions/iconpicker.md) (“Icon picker”) | all / single line | selection of a symbol with preview, browsable categories and search by name and keywords; default for the icon of an area |
| [Markdown (visual)](./extensions/markdownvisual.md) (“MarkdownVisual”) | Short/long text / multiline | Markdown editor that edits in the formatted view; source text via the button <> |
| [HTML (visual)](./extensions/htmlvisual.md) (“HTMLVisual”) | Short/long text / multiline | HTML editor that edits in the formatted view; source text via the button <>. Like Markdown (visual), but stores HTML |
| [Password](./extensions/password.md) (“Password policy”) | Password / password | requirements as a speech bubble, strength as a bar; the server checks the same rules. Default for the passwords of the users |
| [Snippets](./extensions/snippets.md) (“Snippet picker”) | all / single line | selection of a text block (snippet) via a search window; writes a reference `#prefix.key` into the field, which appears in the respective backend language. Also used in the backend for “Label” fields |

## Additional extensions

Additional extensions are separate packages. They are fetched via Composer and then installed as a component (see [installing additional components](./01-quick-start-installation.md#install-additional-components)):

```bash
composer require camindo/extension-{name}
php Core/cron/camindo.php component install {Name} --activate
```

| Extension | Package | Field types / display | What it does |
|---|---|---|---|
| TinyMCE | `camindo/extension-tinymce` | Short/long text / multiline | WYSIWYG editor, writes HTML |

Each additional component brings its own manual. After installation, you find it in the overview of the manuals under “Manuals of add-on components”.

## Custom stylesheets for editors

Editor extensions offer a ***stylesheet*** in their settings, with which you can match the display in the editor to the website. You store the files yourself under `var/extensions/{name}/` (name in lowercase letters, so `var/extensions/markdownvisual/` or `var/extensions/htmlvisual/`) – all `.css` files there appear in the selection.

So that one stylesheet can design several fields differently, each editor carries a CSS class that you can set for each field (default: `c9-{name}-{fieldname}`, for example `c9-tinymce-teaser`). Examples are given in the description of the respective extension.
