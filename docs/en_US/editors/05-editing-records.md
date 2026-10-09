# 5 Editing records

## The detail view

Clicking a row in the record list opens the ***detail view*** of the record. It shows the
tabs “Contents”, “Meta data” and “Changes”.

***“Contents” tab:*** here you find the values entered and uploaded files, including the
automatically generated file versions (for example preview images). Linked records are
listed one below the other, each with its symbol and as a link to its detail view. If there are
more than 20 links, clicking “more…” opens the list of all linked records.  
Long texts appear shortened; ***“more…”*** after them shows the full text. If the field is edited
with an editor for Markdown or HTML (extensions MarkdownVisual, HTMLVisual), the full text appears
formatted – with headings, emphasis, lists and links.  
Multilingual content is shown in your [preferred language version](./03-work-surface.md#personal-settings).
Use the selection ![flag](../_/flag.svg) below the tab to switch the language.  
The button ***“Show full text”*** displays what the full-text search searches for this record –
per language, from its text fields and file names. For multilingual content there is one tab per
language.

***“Meta data” tab:*** here you find the background information, such as the record number, area,
instance, status, publication period, design template, owner, and who created and last changed the
record, and when. In the section ***“Used by”***, the button “All references to this record”
lists all records that point to this one via a relation field (unidirectional or bidirectional).

***“Changes” tab*** shows the history of the record, with the newest change first: when, by whom and
from where (backend, website, import …) it was created, changed, deleted or restored. For each change
the changed fields are shown with their state ***before*** and ***after*** side by side. For texts,
unchanged paragraphs are gray, removed ones red and new ones green. In a changed paragraph, the
changed words are highlighted. This way you can also find a text that was overwritten by mistake and
restore it by hand.

| Button | Effect |
|---|---|
| ![edit](../_/edit.svg) “Edit” | open the input form |
| ![content_copy](../_/content_copy.svg) “Copy” | open the input form with a copy of the record |
| ![delete](../_/delete.svg) “Delete” | delete the record (after a confirmation prompt) |
| ![visibility](../_/visibility.svg) “Preview” | show the record the way the website displays it – even if it is not yet published (see [chapter 9](./09-frontend-editing.md#preview)) |
| ![more_vert](../_/more_vert.svg) Menu | ![drive_file_move](../_/drive_file_move.svg) “Move” to another content area, ![mail](../_/mail.svg) “Message” about this record to a colleague; for profile records of the website, ![shield](../_/shield.svg) “Export of personal data” (see [the manual for administrators](../admins/15-privacy.md#exporting-personal-data)) |

## Creating, editing, copying

- ***Creating:*** in the record list, click ![add](../_/add.svg) “New”.
- ***Editing:*** in the detail view, click ![edit](../_/edit.svg) “Edit”, or choose “Edit” from the
  right-click menu in the list.
- ***Copying:*** ![content_copy](../_/content_copy.svg) “Copy” opens the input form with all contents of
  the original, including files and relations. The copy is placed directly after the original. Firmly
  linked content (such as text-image blocks) is copied along with it. In manually sorted lists, the
  copy goes to the end.  
  Important: other records that point to the original will point to the copy afterwards as well. Relations
  that allow only one linked record are excluded.

In the input form, ***required fields*** are marked with `*`. A question mark or a note below the field label
explains what to enter. “Save” applies the entries, “Cancel” discards all changes. Always use one of these
two buttons to close the input form – otherwise the edit lock remains in place by mistake, and other users
cannot edit the record; see [Edit lock](#edit-lock).

If the input check finds errors, the form appears again; the affected fields are highlighted and name the
error. Your entries are kept – including files that have already been uploaded.

## Working copy

As long as you are editing a record, you work on a ***working copy***. The website continues to show the
previous state. Only “Save” applies your changes – completely and in one step: texts, files, relations and
all language versions. “Cancel” discards the working copy; the record stays as it was.

Switching between the language tabs of the form also saves your entries only in the working copy.

## Edit lock

If a colleague opens a record that you are currently editing, they receive a note: “This record is currently
being edited by …”. They can override the lock – but then you can no longer save your changes. Therefore
override a lock only if you are sure the colleague is no longer working on the record (for example because
they left the form open and cannot be reached).

A lock expires by itself if nothing has been changed in the working copy for a longer period (90 minutes by
default). Your own locks do not hinder you – for example if you open the same record in a second browser
window.

## Input fields

Which fields an input form has is determined by the administrator. Most of them are self-explanatory; here
are the special features.

### Text

Single-line and multi-line text fields. Some text fields offer an ***editor*** (WYSIWYG editor) with a toolbar
for headings, emphasis, lists and links. They work like a word processor.

#### Editor with formatted view

| Symbol | Key | Effect |
|---|---|---|
| ![format_list_bulleted](../_/format_list_bulleted.svg) | | bulleted list |
| ![format_list_numbered](../_/format_list_numbered.svg) | | numbered list |
| ![format_align_left](../_/format_align_left.svg) ![format_align_center](../_/format_align_center.svg) ![format_align_right](../_/format_align_right.svg) | | align text left, center, right |
| ![format_indent_increase](../_/format_indent_increase.svg) ![format_indent_decrease](../_/format_indent_decrease.svg) | Tab / Shift+Tab | in a list item: increase or decrease the indent |
| ![format_bold](../_/format_bold.svg) | Ctrl+B (Mac: ⌘B) | bold |
| ![format_italic](../_/format_italic.svg) | Ctrl+I (Mac: ⌘I) | italic |
| ![format_underlined](../_/format_underlined.svg) | Ctrl+U (Mac: ⌘U) | underlined |
| ![link](../_/link.svg) | Ctrl+K (Mac: ⌘K) | link |
| ![title](../_/title.svg) | Ctrl+Alt+1 … 6, Ctrl+Alt+0 | heading 1–6 or paragraph |
| ![undo](../_/undo.svg) ![redo](../_/redo.svg) | Ctrl+Z, Ctrl+Y (Mac: ⌘Z, ⌘⇧Z) | undo, redo |
| ![horizontal_rule](../_/horizontal_rule.svg) | — | horizontal line |
| ![special_character](../_/special_character.svg) | — | special character |
| ![table](../_/table.svg) | — | create or change a table |
| ![format_textdirection_l_to_r](../_/format_textdirection_l_to_r.svg) ![format_textdirection_r_to_l](../_/format_textdirection_r_to_l.svg) | — | the symbol shows the current ***writing direction***; clicking it reverses the direction |
| ![code](../_/code.svg) | — | view the source code – Markdown or HTML, depending on the editor |
|  | ⇧⏎ | `Shift`+`Return` creates a line break within the paragraph, list item or table cell |
|  | ⏎ | `Return` creates a new paragraph, list item or table row |
|  | ⏎ ⏎ | two × `Return` ends text alignment, a list or a table |

***Writing direction:*** a field writes in the direction of its language – an Arabic text from right to
left, a German one from left to right. If an Arabic text contains a German word or a formula (or vice
versa), select that part and click the writing-direction button. The part then appears with a dashed
border and is also displayed in its direction on the website.

***Tables*** are inserted with the table button; you specify the number of columns and rows. The first row
is always the header row. The appearance on the website depends on the stylesheet that your template
programmer includes. If the cursor is in a cell, the table button ![table](../_/table.svg) offers additional
options: insert or remove a row, insert or remove a column. Changing the text alignment always affects the
whole column.  
`Shift+Return` creates a line break within the cell. `Return` starts a new row.

***Special characters*** such as non-breaking or narrow spaces and the soft hyphen are highlighted in gray
in the editor, so that you can see them.

When ***pasting***, the editor takes over only what it can display: formatting from Word, Google Docs or web
pages is converted into supported formatting; images, colors and fonts are removed.

### Selection

Drop-down lists, radio buttons, check boxes and switches. For some check-box lists, the order of the selected
entries can be set with arrow buttons.

### Numbers and amounts

Enter numbers in the notation of your locale (`1.234,50` or `1,234.50`). Currency amounts require exactly two
decimal places.

### Date and time

Date fields offer a calendar to choose from. You can also type the date – in your usual notation. Points in
time are entered and displayed in your time zone (see [chapter 2](./02-sign-in.md#time-zone)).

The backend displays date, time and numbers in the notation of your locale (example: 26 September 2030,
2:30 PM, 1234.5 with two decimal places):

| Locale | Date | Time | Number |
|---|---|---|---|
| `ar_SA` (Arabic) | `٢٦ سبتمبر ٢٠٣٠ م` | `٢:٣٠ م` | `١٬٢٣٤٫٥٠` |
| `bn_BD` (Bengali) | `২৬ সেপ, ২০৩০` | `২:৩০ PM` | `১,২৩৪.৫০` |
| `de_DE` (German) | `26.09.2030` | `14:30` | `1.234,50` |
| `en_US` (English) | `Sep 26, 2030` | `2:30 PM` | `1,234.50` |
| `es_ES` (Spanish) | `26 sept 2030` | `14:30` | `1.234,50` |
| `fr_FR` (French) | `26 sept. 2030` | `14:30` | `1 234,50` |
| `hi_IN` (Hindi) | `26 सित॰ 2030` | `2:30 pm` | `1,234.50` |
| `ja_JP` (Japanese) | `2030/09/26` | `14:30` | `1,234.50` |
| `ko_KR` (Korean) | `2030. 9. 26.` | `오후 2:30` | `1,234.50` |
| `pt_BR` (Portuguese, Brazil) | `26 de set. de 2030` | `14:30` | `1.234,50` |
| `ur_PK` (Urdu) | `26 ستمبر، 2030` | `2:30 PM` | `1,234.50` |
| `zh_CN` (Chinese, Mainland) | `2030年9月26日` | `14:30` | `1,234.50` |

Date and time appear one after the other, usually separated by a comma (`26.09.2030, 14:30`). Seconds
appear only if they are not 0 (`14:30:15`).

When entering values, camindo CMS is more lenient. Besides the displayed form, you can enter a date in a
short form (`26.09.30`, `9/26/30`) or in a long form with the month spelled out (`26 September 2030`); the
international notation `2030-09-26` (also `2030-09-26 14:30`) works in every locale. Times are understood as
`14:30`, `14.30`, `14h30`, `14` or `2:30 pm` – also after a date (`9/26/2030 14:30`). In date, time and number
fields, the digits of Arabic and Bengali script count just like 0–9: with `ar_SA` you can therefore type
`26/09/2030` or `1234.50`, even though the backend displays `٢٦ سبتمبر ٢٠٣٠ م` or `١٬٢٣٤٫٥٠`.

### Password

A password field never displays the stored value. If you leave it empty, the previous password is kept.

### Files

Upload fields let you upload a file from your computer. Your administrator determines which file types are
allowed; a file whose content does not match its extension is rejected. The form shows a preview or an icon for
the file type and the properties of the file (name, size, dimensions).

Very large files, such as videos, are often too large for an upload through the browser. Have them transferred
to the ***upload directory*** of the server via FTP and select them with “![folder_open](../_/folder_open.svg)
Select file on the server”. With the switch ***“Delete after successful save”*** below the selected file, it
disappears from the upload directory as soon as the record is saved; if you cancel the editing, it remains there.

camindo CMS automatically generates further versions from an uploaded file – for example scaled-down images,
preview images or web-compatible videos (***thumbnails***). This happens when you save; time-consuming
conversions such as videos run in the background. Until they are finished, the form shows a placeholder image.

Please note: the ***original file name*** appears on the website in the address of the file (for example
`/media/…/1977,beach-north.jpg`). You change it in the detail view via the pencil icon next to “Original
filename” – without uploading the file again. The file extension remains unchanged. This is not possible while
the record is being edited.

### Relations

Relation fields connect the record with records from other areas – an article with its author, its keywords or
its images. Depending on the setup, a relation field appears as a selection list or as a ***lookup field*** with a
search window:

- The search button ![Lupe](../_/search.svg) opens the record list of the target area with check boxes. Searching,
  sorting and paging work there as usual.  
  If you have entered a text in the search field, only records whose main field contains this text are listed.
  The filter rules from the section [“The search form”](./04-finding-content.md#the-search-form) apply here as
  well – for example `*sofa*` for all records whose main field contains “sofa” or “Sofa”.  
  If ***exactly one*** record is found, it is immediately linked and appears in the preview list below the lookup field.
- ![check](../_/check.svg) Tick the records you want. If a record is still missing, create it directly with “New” –
  it is linked immediately.
- “Done” closes the search window. The linked records appear in the preview list below the lookup field.
- The button ![add](../_/add.svg) “New” opens the input form for a new record in the target area directly.
- The button ![refresh](../_/refresh.svg) “Refresh” reloads the preview list with linked entries below the search
  field, in case an entry does not appear by itself.
- The button ![link_off](../_/link_off.svg) “Unlink” next to each entry in the preview list removes a single link.
- The button ![delete](../_/delete.svg) “Clear” removes all links in this field.
- With the arrow buttons ![arrow_upward](../_/arrow_upward.svg) ![arrow_downward](../_/arrow_downward.svg) you change
  the order of the links in this field, provided the field allows ***manual sorting***.

Relations are made on the working copy. Only when you save are the changes transferred to the original record.

Some relations are **mutual** (bidirectional): if you link an article with a category, the article also appears on
the category's information page. Some allow only **one** linked record.

### Embedded content

Some records are assembled from building blocks – for example a page with text-image blocks. These blocks are
separate records in their own right, but they belong firmly to their main record and are edited directly in its
input form:

![add](../_/add.svg) “Add content” (or “Add …” with the name of the block) creates a new block. The input fields of
the block appear in the input form.

The menu ![more_vert](../_/more_vert.svg) “Edit content” in the upper left corner of each block offers the
following options:

- ![vertical_align_top](../_/vertical_align_top.svg) “Insert before”
- ![vertical_align_bottom](../_/vertical_align_bottom.svg) “Insert after”
- ![arrow_upward](../_/arrow_upward.svg) “Move up”
- ![arrow_downward](../_/arrow_downward.svg) “Move down”
- ![delete](../_/delete.svg) “Delete”

All changes to the blocks take effect together only when the record is saved. Copying, moving and deleting the
record include its blocks.

## Publication and other metadata

Depending on the setup, the input form contains some of the following fields:

| Field | Meaning |
|---|---|
| Status | editing state. Only **enabled** records appear on the website |
| Publication date | the record appears on the website only from this point in time |
| Expiration date | from this point in time the record no longer appears in the lists of the website; it remains reachable via its address |
| Design template | template for this record, if it is to be displayed differently than usual. “(Standard)” uses the default template of the area |
| Parent dataset | parent record in areas with a tree structure, for example the page above a subpage |
| Position after... | allows manual sorting: the preceding record is selected |
| Needs review | marks content that still needs to be reviewed |
| Owner | the user to whom the record belongs (only with the right “Adjust owner”) |

If the area has a field for the ***web address*** (slug) and it is left empty, camindo CMS fills it in when
saving, from the main field (for example the title). From “Our company party 2026” the slug becomes
`our-company-party-2026`. If you change the title later, the web address deliberately stays the same – otherwise
links that have already been shared would break. If the slug should be generated anew, empty the field before
saving. You can also deliberately enter a text that differs from the main field; it is simplified on saving just
like a slug generated from the main field (lowercase letters; spaces and special characters become hyphens): “My
article!” becomes `my-article`. A pure sequence of digits cannot be used as a slug, because it would conflict with
the ID. When saving, a minus sign is added in front of it.

## Moving

A record can move to another content area – for example a message from “News” into the “Archive”. In the detail
view, choose ![more_vert](../_/more_vert.svg) the entry ![drive_file_move](../_/drive_file_move.svg) “Move” and then
the target area.  
Because each area has its own fields, you then assign each field of the previous area to the field of the target area
that is to take over its content. Only matching fields are offered. “Delete” discards the content of a field.

When moving, the input check of the target area applies. If required information is missing there, the input form of
the target area opens, and you complete the missing fields. “Save” completes the move; “Cancel” leaves the record
unchanged in its previous area.

Records in a tree structure with subordinate records, and records that someone else is currently editing, cannot be
moved. To move many records at once, use [batch processing](./07-batch-processing.md).

## Deleting

![delete](../_/delete.svg) “Delete” removes a record after a confirmation prompt. If it has subordinate records, you
choose whether these are deleted as well or moved up one level. Firmly linked content (embedded blocks) is always
deleted along with the record.

If the record is linked to other records, the confirmation prompt states their number (***linked records***, for
example “This record is linked to 3 other records”). All links are counted – including those you do not see in the
input form, such as the articles of a category. “Details” shows these records across areas in a list of results; check
there before deleting whether the links are still needed.

What exactly happens when deleting is determined by the ***delete policy*** of the content area; the confirmation prompt
tells you beforehand, for example:

> Delete policy “Delete record” – The record is moved to your trash.
> After 7 days: permanent deletion

Usually, deleted records first go to the ***trash*** and are only removed permanently after the retention period
(7 days by default). Until then you can restore an accidentally deleted record yourself (see [Trash](#trash)). A policy
can also leave a record in place and only overwrite fields (for example anonymize a customer profile) or delete it
permanently right away – the prompt tells you which. When deleting a profile, the prompt also states what happens to
the user's contributions.

## Trash

Each user has their own ***trash***. You open it with the ![delete](../_/delete.svg) “Trash” button on the start page,
or via the [widget](./03-work-surface.md#start-page-widgets) of the same name, which shows the number of deleted
entries.

The list “Records deleted by me” contains everything that ***you*** have deleted – the most recently deleted record is at
the top. For each entry you see:

| Column | Content |
|---|---|
| Deleted on | time of deletion |
| Record | title and number (ID) of the record; “with n subordinate records” if subordinate records were deleted along with it |
| Area | content area from which the record comes |
| Permanently deleted from | from this point on, the nightly cleanup run removes the entry irrevocably |

![restore_from_trash](../_/restore_from_trash.svg) “Restore” brings the record back after a confirmation prompt – with
its files, firmly linked content and relations, to its old place.

***Branches are restored as a whole.*** If you deleted a record together with its subordinate records, only the top record
of the branch appears in the list. When restoring, all subordinate records that were deleted together with it come back as
well. A subordinate record that you had **already** deleted individually beforehand stays in the trash; it appears in the list
as soon as its parent record has been restored.

Not shown in your trash:

- records from a content area that was itself deleted; only a superuser can restore that, and with it its records
- records from areas to which you no longer have access
- records that another user has deleted

If an entry shows “no permission”, you no longer have the right to delete in this area and therefore also to restore. In that
case, contact your administrator.
