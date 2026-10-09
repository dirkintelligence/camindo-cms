# 2 Template sets

## Encapsulated functionality

A ***template set*** bundles one function of the website: templates, content areas,
possibly records and snippets.
Examples:

| Template set | Content areas | Templates |
|---|---|---|
| News | News items, categories | Overview with paging, detail page, category page, archive, teaser for the start page, RSS feed |
| Events | Dates, venues | Calendar, detail page with directions, upcoming events preview |
| Members area | Members (profile), posts (UGC) | Registration, confirmation, sign-in, forgotten password, edit profile, protected pages |
| Page builder | Pages, text block, image block, gallery (dependent areas) | Page, one building block per block type |

A good template set is ***ready to use right away***: import it, enter contents, done. And it is
***adaptable*** to the individual structure of a website without the function having to be
developed anew. It is passed on by XML export with the option “Optimize templates for import into
a foreign database” (see [Handbook for administrators](../admins/09-structure-export-import.md#passing-on-template-sets)).

## Rules for portable template sets

### One folder, one template area

Place the content areas and the template area of a set together in one folder. In this way, one
click exports the whole set, and on import it is clear what belongs together.

### Names and IDs directly in the command parameter

Templates, content areas and records are addressed in the templates by their ID or their name
(or slug). In a foreign database these may already be taken; then the importer assigns new ones
and adjusts the templates accordingly. The adjustment succeeds only if IDs, names and slugs stand
directly in the command parameters. If a parameter contains a PHP variable that receives its value
only at runtime, the adjustment fails.

### Consistent field names

For your own template sets: use the same field names as in the rest of the website (`title`,
`date`, `text`, `image`, `slug`). Then cross-area lists – for example a timeline of news and
events – work without adjustment, and a template set can easily be combined with existing areas.

For template sets in other databases: use the name prefixes, so that the import does not produce
type conflicts (see [Reserved name prefixes](../admins/05-input-fields.md#reserved-name-prefixes)).

### Separate logic and design

Keep the logic (queries, filters, paging, form evaluation) together in a few templates, and the
design in separate, small building blocks:

```cms
news_overview     (CMS:SELECT news_list filter[area]=news … count=true)
news_list         (CMS:RESULT_START) (CMS:INCLUDE news_teaser) (CMS:RESULT_END)
                  (CMS:JUMP_LIST …)
news_teaser       <article class="teaser"> … (CMS:TEXT title) … </article>
```

Whoever adapts the set to a different design changes only `news_teaser` and the other design
building blocks; the function remains untouched.

### Including the website layout

Use fixed header and footer templates; these can easily be replaced by existing ones.

### Texts in a separate content area

Fixed labels (“Read more”, “Back to overview”, error messages from forms) belong in a separate
content area (for example “Labels”). They are included with CMS:TEXT, for example
`(CMS:TEXT id=labels.readmore)`. In this way they can easily be adapted and translated for the
later website.

### Labels as custom snippets

To keep area names and input field labels multilingual as well, custom snippets can be created
(see [Snippets module](../admins/10-modules.md#snippets-module)). The snippets used in the template
set are automatically included in the export file.

### Settings as a record

If the editorial team is to control the behavior of a set – number of entries per page, title of
the overview page, an introductory text – create for this a small content area “Settings” with a
single record and read it via its slug:

```cms
(CMS:TEXT intro id=news-settings)
(CMS:NUMBER entries_per_page id=news-settings set=limit)
(CMS:SELECT news_list filter[area]=news limit="{$set['limit']}" count=true)
```

### Shipping sample content

A few sample records show right after the import how the set looks and works. Export them along
with their images.

### Documenting

Add a short description to the set – as a note in the folder or as a record: which templates are
to be assigned as default templates, which layout templates are expected, which snippets and
converters the set uses.

### CSS as a template

Include the required CSS as a template – in this way the template set appears immediately in the
intended design. The user can move the CSS into a file at any time. Note, however, that this
template needs special header instructions; see
[Templates that deliver CSS, JavaScript or JSON](./01-basics.md#templates-that-deliver-css-javascript-or-json).

## Using a template set

1. Import (module “Contents” on the start page → menu ⋮ above the record list → “Import”), preferably first in a test environment.
2. Test with the preview – in particular links that the import log reports as “not assigned”.
3. Adjust the header and footer, or replace them with your own.
4. If the template set is a whole website: enter the start page ID or start page template in the
   instance.
5. Clarify legal aspects; adjust texts and deletion policies if necessary.
