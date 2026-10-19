# 7 Batch processing

***Batch processing*** changes, moves or deletes many records in a single operation – for example:

- assign a new category to all products of a series,
- deactivate all news items from last year or move them to the archive,
- set an expiry date for all job advertisements of a department,
- lock all articles by a certain author.

You reach batch processing from the record list via the menu ![more_vert](../_/more_vert.svg) →
“Batch processing”. The function requires the matching permission of your user group.

## Step 1: Scope and function

***Scope*** – which records should be processed?

- “from the last search result”: Only the records that your current search lists are processed.
  Narrow the selection beforehand with the [search form](./04-finding-content.md#the-search-form) –
  that is the usual way.
- “All”: all records of the content area.

The form shows the number of affected records in each case.

## Step 2: The form of the chosen function

### Edit

The form looks like the input form of the area, but with a **check box in front of each field**.
Tick the fields that should be changed and enter the new values. Only ticked fields are written.
As soon as you enter something in a field, camindo CMS ticks it automatically.

- ***Clearing a field:*** tick the field and leave it empty.
- ***Multilingual fields:*** each language has its own check box. Only the ticked languages are
  changed – so you can clear or replace only the English titles and leave the German ones untouched.
- ***Relations:*** the relations selected here ***replace*** the existing relations of the field in
  every record.
- ***Files:*** a file uploaded here is applied to every record. If an upload field is ticked but
  empty, the file is removed from every record.
- ***Parent record:*** the records are placed under the selected record – as its last child
  records, in their current order.
- ***Position:*** select the preceding record. The records are placed after it in their current
  order; “(at the start)” places them at the beginning. The list shows the records under the
  selected parent record – in areas with a tree structure the records therefore also end up under
  this parent record. If the preceding record itself is part of the scope, it stays where it is.

***Current order*** means the order in the tree structure or the manual sorting, not the sorting
of the list. Child records that are not part of the scope themselves move along with their parent
record. A record cannot be placed under itself or one of its child records – it then appears as
failed in the result.

Fields whose value must be unique are not offered – the same value in many records could never be
unique – nor are fields whose value is calculated automatically: full-text fields and thumbnails.
Also 1:1 relation fields (“embedded content”) are not offered here.

### Move

Select the target area and assign the fields – as when [moving a single record](./05-editing-records.md#moving).
Records that fail the input validation of the target area stay where they are and are listed in the result.

### Delete

After a confirmation prompt, all records in the scope are deleted – as with single deletion, first
into the [trash](./05-editing-records.md#trash). There, each record appears individually and can be
restored one by one.

## Options

| Option | Effect |
|---|---|
| “Simulation” | Check only: each record goes through the input validation, but nothing is changed. This shows in advance which records would fail – for example because of a required field |
| “Run as background process” | You continue working straight away; the result arrives as an internal message in your inbox. From 200 records (default setting) batch processing always runs in the background – the option cannot be unticked then |

## The result

Each record is checked and saved exactly as if you had edited it by hand: with input validation,
[edit lock](./05-editing-records.md#edit-lock) and all automatic steps. The result states how many
records were processed and lists every failed record with the reason – for example “Locked: being
edited by … since …” or a message from the input validation. You then edit these records individually
or start batch processing again.

## Bulk change via CSV export and re-import

For changes where each record receives a *different* value – for example new prices for a hundred
products – the [CSV export with re-import](./08-export-import.md#editing-content-in-excel) is the
right tool.
