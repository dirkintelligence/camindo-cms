# 5 Input fields

You maintain the input fields of a content area in the module “Structure”. Select an area
and switch to the tab “Input fields”. The list shows each field with its name, label, type,
and three switches for the ***visibility***. The visibility can be switched directly in the
list:

| Switch | The field appears … |
|---|---|
| Input | in the input mask |
| Search | in the search form |
| List | as a column of the record list |

The order of the fields in the list is also their order in the mask.
“New” creates a field. Clicking a field opens its detail view with “Edit”, “Copy”, “Delete”
and “Change type”.

## Field types

When creating, you choose what the field is to store:

| Group | Type | Stores |
|---|---|---|
| ***Text*** | Short text | up to 255 characters; optimal for sorting and searching (titles, names, identifiers) |
| | Long text | running text up to 16 million characters |
| | Text with multiple selection | several values from a fixed list (check boxes) |
| | Full text | collects the text of other fields for the full-text search (see [below](#full-text)) |
| | Password | stored encrypted irreversibly (hashed) |
| ***Date / time*** | Date + time, time-zone dependent | point in time with conversion between time zones (appointments, publications) |
| | Date + time | date and time without conversion (birthdays, reference dates) |
| | Time | time without conversion (opening hours) |
| ***Number*** | Integer | whole numbers |
| | Bit | two states: 0 or 1 (switch) |
| | Currency amount | amount with exactly 2 decimal places, exact without rounding losses |
| | Floating-point number | high precision, minimal rounding differences possible |
| | Exact decimal number | up to 10 digits before and 10 after the decimal point, exact |
| ***Relation*** | Relation (bidirectional, n:1) | exactly one record of another area, e.g. the main category |
| | Relation (bidirectional, n:m) | several records of another area, e.g. tags |
| | Reference (unidirectional, n:1) | one-sided reference to exactly one record |
| | Reference (unidirectional, n:m) | one-sided reference to several records, e.g. “Related articles” |
| | Fixed-linked content (1:n) | exclusively assigned content, e.g. text-image blocks |
| | Embedded content (1:n) | as above, edited in the mask of the record |
| ***File*** | File upload | upload field for files |
| | File version (thumbnail) | automatically generated version of an uploaded file – generated preview image, reduced video … (see [chapter 8](./08-media.md)) |
| ***Meta information*** | Publication date, expiry date, design template, status, review required, parent record, position | meta fields, see [below](#meta-fields) |

Choose the type with care – it determines how inputs are validated, sorted, and searched.
Numbers in a text field sort alphabetically (`10` before `9`), and a date in a text field
cannot be filtered by periods.

The following list of input options varies depending on the field type:

## Name, label and help texts

| Setting | Meaning |
|---|---|
| Name | internal name, required for the templates, e.g. `titel`. Begins with a lowercase letter, followed by lowercase letters, digits, and `_` (see [naming rules](#naming-rules)) |
| Display type | [see below](#display-type) |
| Position after... | selection of the preceding field (order) |
| Label | caption in the input, search form, and list |
| Note | appears below the label |
| Tooltip | appears when hovering over the label |
| Prefix, suffix | appear to the left or right of the input field, e.g. “€” or “cm” |
| Width, height | size of the input field |
| Visibility | setting whether the field appears in the input mask, search form, and record list. |
| Main field | the field represents the record in lists and relations (one per area) |
| Multilingual | see [below](#multilingual-fields) |

Button “Snippets” ![translate](../_/translate.svg): In the input fields “Label”, “Note”,
and “Tooltip”, you can enter the name of a [snippet](./10-modules.md#snippets) instead of a
text, prefixed with `#`, for example `#label_file`. The text then appears in the respective
language of the back-end user. A click on the button allows a search across all registered
snippets.

For common field and area names, camindo CMS already includes the translations in all
back-end languages – a custom snippet is not necessary for these. Examples:

`#label_customers`, `#label_profiles`, `#label_comments`, `#label_products`, `#label_articles`, `#label_shopitems`, `#label_categories`, `#label_tags`, `#label_pages`, `#label_news`, `#label_events`, `#label_contacts`, `#label_text_image_blocks`, `#label_documents`, `#label_downloads`, `#label_files`, `#label_images`, `#label_media`, `#label_videos`, `#label_title`, `#label_subtitle`, `#label_teaser`, `#label_text`, `#label_description`, `#label_author`, `#label_date`, `#label_price`, `#label_document`, `#label_file`, `#label_image`, `#label_link`, `#label_icon`, `#label_url`

For example, `#label_customers` as the label of the area “Customers”: the counter-field of a
relation takes over the label of the area and then also appears translated.

## Field content

| Setting | Meaning |
|---|---|
| Default value | value that is pre-filled in the input mask for new records (default value). For date fields, date functions are also allowed, [see below](#date-functions) |
| Processing | the value entered in the record is processed according to the ticked rules (“Trim whitespace” removes spaces at the beginning and end; “Remove HTML tags” leaves only plain text) |

### Date functions

For date and time fields, the default value may be a ***date function***. It is
evaluated when the record is saved:

| Default value | yields |
|---|---|
| `now`, `today` | now, or today at 0:00 |
| `now+2h`, `today+7d`, `today-1d` | with an offset in seconds (`s`), minutes (`m`), hours (`h`), or days (`d`) |
| `UTC_TIMESTAMP()`, `UTC_DATE()`, `UTC_TIME()` | current point in time, day, or time in world time |
| `NOW()`, `CURRENT_TIMESTAMP()`, `CURDATE()`, `CURTIME()` | the same in the time zone of the database |

## Display type

The ***display type*** determines how the field appears in the mask:

| Display type | for |
|---|---|
| Single-line / multi-line text input | text, number, date |
| E-mail, phone, password input | short text or password |
| Dropdown (single selection), radio buttons | relations, text, number |
| Dropdown (multiple selection), check boxes | relations, texts (JSON array) |
| Switch (on/off) | bit (0 or 1) |
| Lookup field | relations – once the target area has many records |
| Embedded | content that belongs exclusively to the record |
| Upload field | files |
| File version | preview images, reduced videos |

For selection lists (dropdown, radio buttons, check boxes), enter the ***options*** in the
field “Default value” – one option per line. The first option is the default value. If you
do not want to enter a default value, insert a line break at the beginning (an empty line).

## Input validation

| Setting | Effect |
|---|---|
| Required field | the field must not remain empty |
| Not zero | number fields must not be 0 |
| Minimum / maximum | for texts the length, for numbers and dates the value, for relations and multiple selection the number. The comparison operator determines whether the limit itself is allowed (`>=`) or not (`>`) |
| RegEx | your own check pattern as a regular expression, e.g. `[A-Z]{2}-[0-9]{4}` for article numbers such as “AB-1234” |
| Value unique in the content area | no other record of the area may have the same value |
| Value unique in the database | no other record of the installation may have the same value in this field |
| Store content encrypted | see [below](#encrypted-fields) |

## Multilingual fields

A ***multilingual*** field has its own content for each language of the instance. If a
translation is missing, the content of the main language applies (fallback). Single-language
fields have the same value in every language.
Fields that can be multilingual: text (including “Encrypted text” and “Text with multiple
selection”), number, bit, date, time, and file upload.
***Thumbnail fields*** take over the setting of their source: with a multilingual source,
a separate thumbnail is created for each language; in languages without their own file, the
thumbnail of the main language applies.
A ***full text*** has no “Multilingual” switch, but is always created ***per language*** – from the
content of its source fields in this language (for fields that have not been translated, from
the fallback of the main language).
Relation, password, and meta field are always single-language.

## Relations

| Setting | Meaning |
|---|---|
| Related content area | target area of a bidirectional relation |
| Related content areas | target areas of a unidirectional reference or of fixed-linked content – also several |
| Counter-field | For bidirectional relations, camindo CMS automatically creates the counter-field in the target area: if an article is linked with a tag, the tag knows the article |
| Manual sorting | the editorial team determines the order of the linked records (for example the images of a gallery) |
| Detailed list | in the lookup field, the preview of the linked records shows all fields that are also visible in the record list of the area instead of only the main field, but here one below the other |

Target areas must belong to the same instance or to an instance that may read this one.
If you deselect a target area in which relations still exist, camindo CMS asks whether these
relations should be deleted.

A relation “n:1” is not merely a restriction to one record: templates can sort and filter
very quickly by fields of the linked record. Choose this relation type where only one target
makes sense in terms of content, for example a category for the article.

### Fixed-linked and embedded content

Fixed-linked content forms a ***page construction kit***: a page consists of blocks – text
block, image block, quote, gallery –, each block type is its own content area with its own
fields.

1. For each block type, create a content area and enable ***“Dependent area”*** there. Only
   dependent areas can be selected as a target.
2. Give the page area a field “Embedded content” (or “Fixed-linked content” with a search
   window) and select the block areas as the target.

Each block belongs to exactly one page. It is saved, copied, moved, and deleted together
with it. With the type “Embedded”, the editorial team edits all blocks directly in the input
mask of the page: blocks can be added, re-sorted, and removed.

## Files

An ***upload field*** accepts a file. Under “Allowed file types”, you restrict the permitted
formats – as a MIME type (`image/jpeg`, `image/*`) or extension (`.pdf`), one entry per line.
Executable files such as `.php` are always forbidden, and camindo CMS checks whether the
content of a file matches its extension.

Under “Authentication”, the content of a file can be protected from access by unauthorized
website visitors (see [chapter 13](./13-community.md#protected-files)).

***Thumbnail fields*** automatically generate further versions from the file of an upload
field: reduced images, preview images, web-compatible videos, still images from videos. You
select the ***source*** (the upload field or another thumbnail) and one or more
***converters***. The converters are processed in the set order; the first converter that
matches the file type creates the version.
Details in [chapter 8](./08-media.md).

## Full text

A ***full-text field*** collects the content of several text fields in its own search index
(in addition to `fulltext`, which is automatically available in every content area).
Under “Source fields”, you select the fields; for upload fields, the ***file name*** counts
(`slant-front.jpg` becomes “slant front slantfront jpg”: binding and underscore words are
stored separately and together in the index). Encrypted fields and thumbnails are not
available for selection. Below the fields of the area follow – each with the name of the
target area and of the relation field as a heading – the fields of the target areas of its
fields of the type:

- ***“Fixed-linked content”***: this way, the search finds a product, for example, via the titles
  of its images. If a linked content item changes, the full text of its record is updated
  immediately – the content belongs to the record after all.
- ***“Relation”*** (bidirectional): this way, the search finds a product, for example, via the
  names of its tags. However, if a linked record changes – for example the name of a tag –
  this only reaches the full text of the product the next time the product is saved. For such
  areas, therefore, run the task [“Rebuild full text”](#rebuilding-the-full-text) regularly,
  for example at night.

Fields behind a ***“Reference”*** are not available for selection: a reference is a loose,
one-sided reference to external content, often in several areas – whose texts do not
describe the record itself. The full-text field is updated automatically with every save and
has no input of its own; it appears only in the search form. The content is created ***per
language*** of the website: in the English version, the search finds the English texts. HTML
markup from formatted texts is not included. The search supports the syntax `+must -mustnot`
(see [editors handbook](../editors/04-finding-content.md#full-text-search)).

### The standard full text

Each record area automatically has a ***full text*** across ***all*** fields of the type “Short
text”, “Long text” and JSON as well as the file names of the upload fields – without you
creating an input field for it. In addition, the same fields of the target areas of its fields
of the type “Fixed-linked content” (one level deep) are included. New fields are added
automatically, deleted fields are removed; encrypted fields and passwords are never included.
It therefore does not appear in the field list of the area, but in every search form as the
search field “Full text” – also in the [cross-area search](../editors/04-finding-content.md#cross-area-search).
Internally, it is called `fulltext`.

What the full text of a record contains is shown in its detail view in the tab “Metadata”
under “Show full text” – together with the self-created full-text fields, for each language.
Full-text fields do not appear in the tab “Content”.

### Rebuilding the full text

When saving a record, its full text is updated automatically. After certain changes to the
***structure***, however, the full text of ***all*** existing records no longer matches the
source fields:

- You create a full-text field or change its source fields,
- a source field is deleted, receives another type, or changes between single-language and
  multilingual,
- the target areas of a field of the type “Fixed-linked content” change or the field is deleted,
- an instance receives a language with different sorting rules, or the code of a language
  changes (this affects all areas of the instance).

camindo CMS does ***not rebuild*** the full text immediately in these cases – for large areas,
this would hold up ongoing operation, and with larger restructurings, it would be pointless
after every step. Instead, the detail view and the field list of the area show the note
***“The full text of this area is no longer up to date since …”***. Until it is updated, the
search there may still find old content.

- ***“Update now”*** in the note starts the rebuild as a background process. While it runs,
  ![hourglass](../_/hourglass.svg) “Background process ‘Update full text’ started” appears there;
  a second rebuild of the same area does not start.
- Or leave it to the [Scheduler](./12-operation.md#scheduler): The task
  ***“Rebuild full text”*** with the option ***“Only outdated areas”*** – set up during
  installation, every night – processes all marked areas. Without the option, it rebuilds the
  selected areas (without a selection: all) in any case. It skips an area that another process
  is currently rebuilding.

After a complete rebuild, the note disappears.

***Exception:*** If you convert a source field to “Encrypted text”, camindo CMS rebuilds the full
text immediately – otherwise the plain text would remain in the search index until then.

Content changes to records behind a “Relation” do not mark the full text as outdated. To
pick them up, run the Scheduler task “Rebuild full text” ***without*** “Only outdated areas”.

## Encrypted fields

With “Store content encrypted”, the content is stored encrypted in the database –
suitable for personal data such as phone numbers or e-mail addresses. Even someone who has
access to the database or a database backup cannot read it without the key file
`.camindo.key`.

The price: encrypted fields can only be searched by their exact value, not with wildcards,
and cannot be sorted meaningfully. In the CSV export, they appear in plain text.

## Meta fields

camindo CMS stores some information for each record, independently of its area. So that the
editorial team can edit it, you add it to the mask as a ***meta field***:

| Meta field | Meaning |
|---|---|
| Publication date | the record appears on the website on a time-controlled basis |
| Expiry date | the record disappears from the lists of the website at the specified time |
| Design template | selection of one of the selectable templates |
| Status | processing state; only “Released” appears on the website |
| Review required | marker for content that still needs to be reviewed |
| Parent record | turns the area into a ***tree*** (pages with subpages) |
| Position | order of the records (“Sort by …”) |

Without the meta field “Status”, new records are released immediately; without publication
and expiry date, they are visible without limit.

## Special field names

| Name | Effect |
|---|---|
| `slug` | A field “Short text” with this name provides the ***web address*** of the record (`/summer-party-2026` instead of `/1758`). If it remains empty, camindo CMS fills it from the main field when saving. A slug, once set, does not change with the title – links already shared remain valid. It may contain slashes (`products/shoes`) and can be multilingual. The address including the [slug prefix](./04-structure.md#slug-prefix) of the area must be unique – in areas with different prefixes, the same slug may occur |

## Changing the type

The type and multilingualism of an existing field cannot be changed in the editing mask, but
only via ***“Change type”*** in the detail view. camindo CMS converts all existing content
in the process:

- between text and number types, for example “Short text” ↔ “Integer” or “Currency amount”,
- between “n:m” and “n:1” for relations and references,
- between single-language and multilingual.

If a conversion can lose content (a text that is not a number; when switching to “n:1”, all
relations except the first; when switching to single-language, all translations), the mask
warns beforehand. If the field name is also used in other areas, the field must be renamed
first – the type is bound to the name.

When converting to ***“Encrypted text”***, the field is removed from all full-text fields and
the standard full text; camindo CMS rebuilds these for all records of the area (also for
deleted ones in the trash), so that no plain text remains in the search index. If the field is
the ***main field*** of the area, lists and relations then show “ID …” instead of the content.

If records of the area are currently ***open for editing***, the confirmation names their number
and the editors. The conversion does not respect the edit lock. The cached working copy of the
open records is converted as well – including newly uploaded files that have not been saved
yet. When a file field is changed to ***single-language***, only the file of the main language
remains. However, the open input mask still knows the old field type: unsaved input in it may
then no longer be saveable (for example after the change to single-language); in that case,
the only option is to cancel the editing and reopen the record. Therefore, ask the editors to
save or cancel beforehand.

## Naming rules

- Field names begin with a lowercase letter `a-z` and consist thereafter of lowercase letters,
  digits, and the underscore `_`. Names that begin with a digit or an underscore are not
  allowed (camindo CMS itself uses names beginning with `_`, such as `_default`). Minimum length
  for names: 2 characters.
- The name ***`fulltext`*** is reserved for the [standard full text](#the-standard-full-text).
- A field name is ***bound system-wide to one type***: once `titel` has been created as “Short
  text”, `titel` cannot be a number in any other area. This is the basis for the
  [cross-area query](./02-concept.md#one-field-name-one-meaning-filtering-across-areas).

### Reserved name prefixes

To avoid type conflicts, the name prefixes listed below are reserved. They are particularly
suitable for [template sets](./02-concept.md#template-sets): when content areas are exported to
foreign databases, the name prefixes ensure that the names in the foreign database are not
already assigned to other types.

| Field type | Prefix |
| --- | --- |
| bit | bit_ |
| chars | chr_ |
| currency | cur_ |
| datetime | dat_ |
| decimal | dec_ |
| encrypted | enc_ |
| file | fil_ |
| float | flt_ |
| fulltext | ftx_ |
| id | rid_ |
| ids | ids_ |
| integer | int_ |
| json | jsn_ |
| password | pwd_ |
| pointer, relation, link | rel_ |
| text | txt_ |
| time | tim_ |
| timestampl | tsl_ |

## Extensions

An ***extension*** adds additional behavior to an input field – a text editor, a calendar, a
symbol selection. You select it in the field under “Extension”; only extensions that match the
field and display type are offered. The available extensions are described in [chapter 11](./11-extensions.md).
