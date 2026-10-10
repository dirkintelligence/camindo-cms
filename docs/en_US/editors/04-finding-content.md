# 4 Finding content

You maintain all records in the module “Contents”.

## The folder tree

The start page of the module shows, on the left, the tree of ***folders*** and
***content areas*** to which you have access. Clicking a folder expands or collapses it;
clicking a content area opens its record list. If your installation runs several websites
(instances), each one appears as its own main folder in the tree.

Above the tree are the buttons ![search](../_/search.svg) “Search” (for the search across
several areas, see [below](#cross-area-search)) and ![refresh](../_/refresh.svg) “Refresh”.

## Recently changed

To the right of the area tree, ***“Recently changed”*** lists the ten records that you
yourself last saved in the input form – the most recent one at the top. Each entry shows
the fields that also appear in the record list of its area, one below the other (the main
field in bold), followed by the time of your last save. Clicking opens the detail view.

The list is personal: changes made by other users do not appear here. Nor do records that
were changed through an import or batch processing. Records in the trash and records to
which you no longer have access are hidden. If you have not saved anything yet, the area
stays empty.

The same list exists in the module “Templates” for the most recently saved templates: name,
label and template area.

## The record list

The record list shows the records of a content area, 20 per page; you page through the
rest below it. Which columns it shows is determined by the administrator for each content
area. Above the list there is a note about the area (if one has been entered) and the
following buttons:

| Button | Effect |
|---|---|
| ![add](../_/add.svg) “New” | create a new record |
| ![search](../_/search.svg) “Search” | open the search form of the area |
| ![refresh](../_/refresh.svg) “Refresh” | reload the list |
| ![more_vert](../_/more_vert.svg) Menu | [Export](./08-export-import.md), [Import](./08-export-import.md#import), [Media import](./08-export-import.md#media-import), [Batch processing](./07-batch-processing.md), settings of the area (depending on your rights) |

- ***Sorting:*** click a column heading. Without a selection, the default sort order of the
  area applies.
- ***Filtering by status:*** the heading of the status column has its own menu. It shows
  only records in a certain state, for example only disabled records or only contributions
  from website visitors that still need review (“Unreviewed”).
- ***Hierarchy:*** in areas with a tree structure (for example pages with subpages), the list
  shows the records in their order and indented.
- Clicking a row opens the ***detail view***, right-clicking opens the context menu
  (“Edit”, “Copy”, “Delete”).

While a search is active, the list shows above it what was searched for. The list remembers
the search, the sort order and the page position as long as you stay in this layer.
Clicking the X at the far right resets the filters to the defaults of the area.

## The search form

The search form of a content area offers all fields that the administrator has released for
search, plus always the fields “ID, slug or URL” and “Full text”, the publication and
expiration date, the creation and change dates, and the sort order of the result. In areas
with a tree structure, the [depth](#depth-in-the-tree) is added to the parent record; in
areas for user contributions, the [frontend users](#frontend-users) are added. Other fields
can be [added temporarily](#more-fields).

The basic rules:

- ***Search terms in different fields:*** all filled-in fields must match (AND).
- ***Search terms in the same field:*** it is enough if one of them matches (OR). Different
  terms can be separated by a comma or `OR`, for example `Hamburg, Bad Homburg`.
- Upper and lower case and accents do not matter; `ß` and `ss` are the same.
- The ***rules of the language*** in which you search apply: in German, `ä` equals `ae`
  (`Mueller` finds “Müller”); in Swedish, `ö` is a letter of its own (`o` does not find `ö`).

### Wildcards

The asterisk `*` stands for any sequence of characters:

| Input | Finds |
|---|---|
| `Smi*` | Smith, Smithers, Smiley |
| `*son` | Johnson, Anderson, Wilson |
| `*ford*` | Stratford, Ashford, Fordham |

Without an asterisk, camindo CMS searches for exactly this value.

### Comparison operators

A character in front of the search value changes the comparison:

| Input | Meaning |
|---|---|
| `Berlin` or `=Berlin` | equal to |
| `!Berlin` | not equal – everything except Berlin |
| `!*son` | everything that does **not** end in “son” |
| `==Star*` | exactly equal – the asterisk counts here as an ordinary character |
| `>100`, `>=100` | greater than, greater than or equal to |
| `<100`, `<=100` | less than, less than or equal to |
| `=` | field is empty |

A search value that begins with an HTML tag is not a comparison: `<section>` searches for
exactly this value. To find texts or template code that ***contain*** a tag, use asterisks:
`*<section>*` – or `*<section*` if the tag may have attributes.

### Several conditions in one field

With `AND` and `OR` (surrounded by spaces) you combine conditions within the same field:

| Input | Finds |
|---|---|
| `Hen* OR Her*` | begins with “Hen” or with “Her” |
| `>2 AND <4` | values between 2 and 4 |
| `!2 AND !3` | neither 2 nor 3 |

Please note: the comma and `AND`/`OR` cannot be mixed within the same field.

### Date and time

You may enter dates in your usual notation (`15.06.2023`) or in the international format
(`2023-06-15`). The CMS completes incomplete entries sensibly:

| Input | Finds |
|---|---|
| `2023` | everything in 2023 |
| `>=2023-06` | from 1 June 2023 onward |
| `<2023-06-15` | before 15 June 2023 |
| `>2012 AND <2014` | everything in 2013 |
| `today` | everything from today |
| `>=today-7d` | from the day one week ago |
| `<now-2h` | older than two hours |
| `utc_now`, `utc_today` | like `now`/`today`, but in world time |

`now` (the current moment) and `today` (today, 0:00) refer to your time zone; they may be
followed by an offset in seconds (`s`), minutes (`m`), hours (`h`) or days (`d`).

### Not yet translated: `(empty)`

The search value `(empty)` finds records that have ***no content of their own*** in a field –
either because the field is empty or because the content of the main language was
automatically used for this language (incomplete translation). For a ***file field***,
`(empty)` finds records without an uploaded file. `!(empty)` finds the opposite.  
More on this in [chapter 6](./06-multilingual-content.md#finding-missing-translations).

### ID, slug or URL

In “ID, slug or URL” you can paste whatever you have at hand: the record number (`4902`), the
slug (`gt`, with slug prefix `models/gt`), or a complete address of the website
(`https://www.example.com/en_US/models/gt`). The language in the address does not matter;
camindo CMS shows the record in your [preferred language version](./03-work-surface.md#personal-settings).

### Full-text search

Each content area has the search field ***“Full text”***: it searches all text fields of the
area and the file names of uploaded files at once – including the texts of hard-linked
content (for example the image title of a product) – in the language you are currently
editing. Encrypted fields and passwords are not included. The system administrator can set up
further full-text fields from selected fields – also from linked records, for example the
keywords of a product. Different rules apply to all full-text fields:

| Input | Finds records that … |
|---|---|
| `müller meier` | contain “müller” or “meier” |
| `+müller +meier` | contain both words |
| `+müller -disabled` | contain “müller”, but not “disabled” |
| `sofa-white` or `sofa_white` | contain the words “sofa white” directly one after the other |

Words joined by a hyphen or underscore are found separately and as one word: “sofa-white” can
be found with `sofa-white`, `sofawhite` and `white`; “E-mail” also with `EMail`. The period only
separates words – including in file names: `sofa-white_front.jpg` is found with `front`,
`sofa-white`, `sofawhitefront` or `jpg`.

The full-text search also follows the ***rules of the language***: in German, `Mueller` finds
“Müller” and vice versa, but `Muller` does not; in Swedish, `forvaring` does not find
“förvaring”.

There are no wildcards in the full-text search. Very short words (fewer than three letters)
are not found, and neither are HTML markup: searching for `<section>` in the full-text search
finds nothing – for that, use the search with asterisks in a text field (see above).

### Encrypted fields

Fields that are stored encrypted (for example phone numbers or email addresses) can only be
searched by their ***complete*** value – without wildcards and without greater-than/less-than.
Upper and lower case, spaces and punctuation do not matter in this case:

| Input | Finds the stored value |
|---|---|
| `jane.doe@example.com` | “Jane.Doe@Example.com” |
| `+49 171 123456` | not “0171/12 34 56”, but “49 171-123-456” |

Parts of a value (for example only `doe` or only the domain) cannot be found. The digits
themselves must match – `0171…` and `+49 171…` are different numbers for the search.

### Linked records

There are several ways to create links, which your administrator can set up. If very many
records can be selected, direct selection becomes confusing. In that case a lookup field is
used for the selection. It works like the [lookup field](./05-editing-records.md#relations)
in the input form – only the ![add](../_/add.svg) button is missing. Several selected values
are combined with OR.

### Files

The search field of an upload field searches the ***stored file name*** – with wildcards, like
any text field (`*holiday*`, `*.pdf`). Below it, expand ***“File properties”*** to search for
further details of the file:

| Field | Content | Examples |
|---|---|---|
| Width, height | dimensions of an image or video in pixels | `>=1920`, `<800`, `>1000 AND <2000` |
| File size | size in bytes; with the multipliers `k` (×1024), `M` (×1024²), `G` (×1024³), a trailing `B` is allowed | `>500k`, `<2.5M`, `>1M AND <5MB`, `>=1G` |
| MIME type | file type as recognized by the server | `image/jpeg`, `image/*`, `video/*`, `application/pdf` |
| File extension | extension, with or without a period | `pdf`, `.jpg, png`, `!webp` |

Filled-in properties must apply ***together*** to the same file (AND): `Width >=1920` and
`File size <500k` finds large images with a small memory footprint. Several values in one
field (`jpg, png`) count as OR, as everywhere. For the file size, the period is the decimal
separator (`2.5M`) – the comma separates search values. If properties have a value, the
section is already expanded the next time you open the search form.

### Depth in the tree

In areas with a tree structure (for example pages with subpages), the field ***“Depth”*** appears
directly below the field for the ***parent record***. It refers to the record selected there –
like `filter[depth]` in [CMS:SELECT](../templateprogrammers/select.md):

| Depth | Finds under the selected parent record |
|---|---|
| empty | all descendants |
| `1` | only the direct children |
| `2` | children and grandchildren |

Without a selected parent record, the depth has no effect.

### Frontend users

If visitors of the website are allowed to create content in an area (an area for user
contributions), the search form shows the lookup field ***“Frontend user”***: select one or
more user profiles to find only their contributions.

### More fields

Below the “Search” button is the selection ***“More fields”***. It contains the fields of the
area that the administrator has not released for the search form. “Add” reloads the search
form with the selected field – search terms already entered remain. In this way you can add
several fields one after another; they are highlighted in yellow and apply only to this search
(also when you open the search form again from the list of results). The released fields of the
area do not change as a result.

## Cross-area search

The “Search” button on the start page of the module searches several content areas at once.
The form shows only fields that can be searched in all areas. The most useful is the full-text
field, which stores the contents of all text fields of a record (except encrypted values and
passwords).

Via ***“More fields”*** (see [above](#more-fields)) you can add properties here that all records
have:

| Field | Searches for |
|---|---|
| Status | the state of the record |
| Frontend user | the user profile that created the contribution on the website |
| Owner | the user to whom the record belongs in the backend |
| Instance | the website to which the record belongs (only with several websites) |
| Needs review | contributions from website visitors that still need review |
| Template | the template with which the record is output |
