# Extension IconPicker (Icon picker)

Core extension for single-line fields of all field types that store the name of a
***Material Symbol*** (e.g. `menu_book`). Default for the icon of a content area
(module “Structure” → area → Icon). Always available, no installation needed, no
settings.

## Operation

To the right of the field, a ***preview*** shows the entered icon; if it stays empty,
the name is unknown or the field is empty. The button ![add](../../_/add.svg) opens the
picker:

- Without a search term, the window shows the icons of one ***category*** (Action, Alert,
  Audio & video, Communication, Content, Device, Editor, File, Hardware, Home,
  Image, Maps, Navigation, Notification, Places, Search, Social, Toggle);
  ![chevron_left](../../_/chevron_left.svg) ![chevron_right](../../_/chevron_right.svg)
  page through the categories.
- The ***search field*** searches all icons by name and ***tags*** (English, from the
  search index of Google Fonts, e.g. `book`, `calendar`, `person`). If you enter several
  terms, all of them must match. The Enter key takes the search term into the field: an
  exact icon name, otherwise the only hit, otherwise the text itself (spaces become `_`).
- A click on an icon writes its name into the field and closes the window.
  ![block](../../_/block.svg) “No icon” empties the field.
- ![close](../../_/close.svg), the Esc key or a click outside close the window without
  any change.

You can also type the name directly; the preview follows as you type. The names
and all icons are listed at [fonts.google.com/icons](https://fonts.google.com/icons)
(style “Material Symbols Outlined”).
