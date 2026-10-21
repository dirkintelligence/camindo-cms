# 8 Export and import

Export and import take content out of camindo CMS and bring it back in. This lets you edit, translate
and back up large amounts of data outside the CMS, and transfer them between areas or installations.

You reach both functions from the record list via the menu ![more_vert](../_/more_vert.svg) →
“Export” or “Import”.

## The three formats

| Format | Purpose | Required group permissions |
|---|---|---|
| ***CSV*** | Tables for Excel & Co.: edit content, analyze it, take it over from other systems | additional permission “CSV export” or “CSV import” |
| ***DOCX*** | Word file for translators | additional permission “DOCX export (translation)” or “DOCX import (translation)” |
| ***XML*** | complete backup of an area with all fields, languages and files | superusers only |

## Exporting

At the top of the export form, you choose the ***scope*** – as with [batch processing](./07-batch-processing.md#step-1-scope-and-function),
either the last search result or all records of the area. Below that, you choose the format and settings.
“Export” starts the export.

The result is always a ***zip file***. It contains the export file and, if requested, the uploaded files of
the records in the folder `media`. The download link is valid for ***one*** download only: the zip file is
deleted from the server once downloaded, and at the latest after 24 hours.

Large exports (from 1000 records) run in the background. The download link then arrives as an internal message.

### CSV

One row per record, one column per field.

| Setting | Meaning |
|---|---|
| “First row contains field names” | header row with the field names – keep it for the re-import |
| “Write UTF-8 BOM (Excel)” | makes sure Excel recognizes umlauts and special characters correctly |
| “Separator”, “Enclosure”, “Line break” | default `;`, `"` and Windows line break – suitable for Excel in German-speaking regions |
| “Languages” | for multilingual websites: which language versions are exported. Multilingual fields get one column per language, such as `titel.de_DE` and `titel.en_US` |
| “Fields” | which fields are exported – shown as a table with name, label and type. “all” selects all visible fields or – if all are already selected – clears them again; collapsed subfields (“More”) are not affected |
| “Include uploaded files” | uploaded files are added to the zip file. The column then contains the relative path to the file |

Via “More” next to a field, further columns are available: for relations, next to the number
(`autor.record_id`) also fields of the linked records (`autor.name`); for files, their properties such as
file name, size or dimensions. Several linked records appear in one cell, separated by ` | `.  
![warning](../_/warning.svg) Columns from the “More” area cannot be re-imported. They serve for information only in the export file.

***Embedded content*** (such as the images of a product that you edit directly in the input form of the
product) belongs to the record. Via “More”, ***all*** of its fields are available – including files and
preview images, which then also go into the zip file. Each embedded content has one value in the cell,
separated by ` | `, in the order of the input form (`bilder.titel` = `Front | Side`). Export `bilder.record_id`
as well – on re-import, camindo CMS uses these values to match the existing content, in the order given.

Please note: encrypted fields appear in the CSV file ***in plain text***. Times are in the file as they are
stored (UTC).

### DOCX and XML

See [Translating with Word (DOCX)](#translating-with-word-docx) and
[Backup in XML format](#backup-in-xml-format).

## Editing content in Excel

The CSV export with a subsequent re-import is the tool of choice when many records are to receive
*different* new values – new prices, corrected addresses, added item numbers, translated short texts.

1. ***Narrow the selection.*** In the record list, find the records you want to edit.
2. ***Export.*** In the export, choose the scope “from the last search result”, the format CSV and the fields
   you want to edit. Make sure to keep the meta field `record_id` ticked – it assigns the rows to the records
   on re-import.
3. ***Edit.*** Unzip the zip file and open the CSV file in Excel. Change the values, but not the header row
   and not the column `record_id`. You may delete columns you do not want to change.
4. ***Save.*** Save in the format “CSV UTF-8 (comma delimited)”.
5. ***Import.*** In the record list, choose “Import”, upload the file and select the mode ***UPDATE*** (“overwrite
   the fields of the import file, keep all others; skip missing records”). camindo CMS assigns the columns
   to the fields automatically based on the header row.
6. ***Analyze and import.*** Check in the analysis what will happen, then start the import. The log shows the
   result for each record.

Notes:

- ***“Values in database format”:*** if the import recognizes a camindo header row, this option is preselected:
  the values are read the way the export wrote them – times in UTC, currency amounts in cents. Turn it off if you
  enter values the way you would type them in the input form (`19,90` instead of `1990`).
- ***Excel changes some values.*** Leading zeros (postal codes, item numbers) and long numbers are lost when Excel
  interprets them as numbers. Format such columns as text before editing.
- ***New records:*** rows without `record_id` are created as new records by the mode UPDATE_OR_INSERT. This is how
  you take over data from other systems, such as an address list.
- ***Relations:*** the numbers (record IDs) of the target records follow one another, separated by ` | `. The column
  is called `{field}.record_id`, such as `autor.record_id`; if no such column exists, camindo CMS also assigns a column
  with the bare field name (`autor`) to the relation field.
- Each record goes through the input validation on import – invalid values are not accepted but reported in the log.

## Translating with Word (DOCX)

The DOCX export prepares the content of an area for translation – a translator or translation agency needs no
access to the CMS for this.

1. ***Export.*** In the export, choose the tab “DOCX”, the source language (“Translate from”), the target language
   (“Translate to”) and the multilingual fields to be translated. Under “Files”, decide whether “All records in one
   file” or “One record per file” – for example to distribute the work among several translators. The individual files
   are named after the record ID and the designation of the record (`4367-sofa-natur.docx`) and are all contained in
   the zip file.
2. ***Translate.*** The Word file contains one table per record. The ***gray rows*** contain the mapping for the re-import
   and must not be changed. The ***white rows*** contain the text of the source language; the translator overwrites it
   with the translation. Fields that are already translated are marked “already translated”.
3. ***Import.*** Import the translated file into the same content area – with “One record per file”, each file separately.
   The import overwrites only the fields of the target language; all other fields and languages remain unchanged.

***Embedded content*** is translated along with the record: its multilingual fields appear in the field selection as
“field › field of the content” and in the table below the fields of the record, numbered per content item. Fields that
are not multilingual in the area of the embedded content do not appear. If a field embeds content from several areas,
the area of each content item decides.

For East Asian target languages, the import can convert punctuation and spaces into their ideographic forms. Passages
in Arabic or Hebrew script within a text can be marked for correct display (right to left).

## Backup in XML format

The XML format (superusers only) is the ***backup format*** of camindo CMS: it contains the content area with all field
settings and the records with all fields in all languages, exactly as they are stored – encrypted content stays encrypted,
passwords are kept only as a check value. On request, the uploaded files are included in the zip file. Preview images and
other thumbnails are not exported; they are generated anew on import.

An XML file can be imported back into the same area – for example to restore an earlier state – into a different area, or
into a different camindo installation. For how to transfer whole structures with folders, areas and templates, see the
[manual for administrators](../admins/09-structure-export-import.md).

## Import

The import reaches its goal in several steps. Nothing is changed until the last step.

1. ***Choose file.*** Upload a zip file from an export or a single XML, CSV or DOCX file. Large files can also be uploaded
   by FTP into the upload directory and then selected with the folder symbol ![folder_open](../_/folder_open.svg) to the right
   of the file field.
2. ***Settings.*** Depending on the format: mode, assignment of the columns, number of rows to import and to skip, insert
   position of new records.
3. ***Analysis.*** camindo CMS shows what the import would do with these settings: how many records would be added, changed,
   replaced or skipped. The ***extended analysis*** lists each record individually. “Back” returns to the settings.
4. ***Import.*** The ***log*** lists each record in color with its result: added, changed, replaced, skipped or failed – with the reason.

The input validation of the individual fields only runs during the import. Therefore, only the log shows how many records are
actually accepted.

### Mode

| Mode | Effect |
|---|---|
| INSERT | Content from the file is always created as new records. |
| UPDATE | Fields given in the import file are overwritten. Fields not given remain as they are. Records that do not exist are not created. |
| UPDATE_OR_INSERT | As UPDATE, but records that do not exist are created. |
| REPLACE | All fields overwrite existing content. Fields not named in the file are **emptied**. Records that do not exist are not changed. |
| REPLACE_OR_INSERT | As REPLACE, but records that do not exist are created. |

When a new record is created, camindo CMS tries to use the record_id named in the file. If it is already taken, a new one is
assigned. If records in the file are linked to one another, this is taken into account during the import: the relations are
adjusted to the new record IDs accordingly. This works only within a single import. Later, the record has no knowledge of its
former record_id.

For editing individual fields, UPDATE is almost always right. REPLACE is suitable for resetting a record completely to the state
of the file.

### Further options

| Option | Effect |
|---|---|
| “Insert position of new records” | In areas with a sort order or tree structure: after which record, or under which parent record, new records are inserted |
| “Force writing” | also overwrites records that someone is currently editing. Those changes are then lost |
| “Run as background process” | the log arrives as an internal message. From 200 records (default setting) the import always runs in the background – the option cannot be unticked then |

### Embedded content on import

CSV and DOCX imports treat a record together with its embedded content as one unit: both are saved together. If one content item
fails (for example because of a missing required field), the record is not changed either.

* ***CSV:*** the values of a cell determine the embedded content. Existing content is matched via `…record_id` (without this column:
  via its order) and changed; additional values create new content (`record_id` = `0`); missing content is removed; the order is
  adopted. Files come from the zip file, preview images are generated anew. If the file names no column for a field, the content
  remains unchanged – in mode REPLACE it is removed.
* ***DOCX:*** the translated fields of the embedded content are adopted. Nothing is created or removed.

If a text itself contains the character sequence ` | ` for several content items, or the number of values does not match, the record
is skipped with a message.

## Media import

With the media import, you turn a collection of files into many records in a single step – for example from a folder of product photos.
You find it in the menu ![more_vert](../_/more_vert.svg) above the record list as the item ![perm_media](../_/perm_media.svg) “Media import”.
Requirements: the area has an upload field, and your user group has the permission to import media.

1. ***Choose zip file.*** Upload a zip file or choose one from the upload directory of the server.
2. ***Settings.*** camindo CMS shows the files contained, grouped by file type. Select the types to be imported, the ***upload field***
   that takes the file, and optionally a field that receives the ***file name as text*** (without extension). Minus signs and underscores
   become spaces (`Filiale-Graz_2024.jpg` → “Filiale Graz 2024”). The first upload field and the main field of the area are preselected.
3. ***Import.*** Each file creates a new record. The import log lists the result for each file.

Not importable and grayed out are executable files, active content (HTML, SVG, XML, JavaScript), empty files and files whose content does not
match their extension. Hidden files (name begins with a dot) and system files such as `Thumbs.db`, `desktop.ini` or the folder `__MACOSX` are
ignored. Types that the chosen upload field does not accept cannot be selected.
