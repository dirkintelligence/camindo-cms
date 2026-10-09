# 2 The concept

This chapter explains the ideas on which camindo CMS is built. Once you know them, you
set up a website faster and more cleanly – and you use possibilities that other systems
do not offer.

## Content areas instead of database tables

In many content management systems and web applications, every new kind of content –
products, events, branches, job advertisements – requires its own database table, its own
input masks, and its own program code. Every change to the structure is a job for a
developer.

camindo CMS takes a different approach. In the back end, you create ***content areas***
and give them ***input fields*** – as many as you like, in any combination, extensible at
any time, even while the website is in operation. The database does not change in the
process, and not a single line of code is written.

Each content area automatically receives everything it needs to maintain its content:

- an ***input mask*** with input validation, required fields, and help texts,
- a ***search form*** with wildcards, comparison operators, and full-text search,
- a sortable ***record list***,
- ***batch processing***, ***CSV, DOCX and XML export and import***,
- ***multilingualism*** per field, ***working copies***, ***edit locks*** and the ***trash***,
- ***access rights*** per user group,
- and access from the templates with filters, sorting, and paging.

The fields are not mere text snippets: each field has a ***type*** – text, integer,
currency amount, date with time zone, file, relation, and many more. The type determines
how an input is validated, stored, sorted, and searched. Internally, each type is stored in
its own indexed table; filtering and sorting therefore remain fast even with large amounts
of data.

***Example – an event calendar:***

| Content area | Input fields |
|---|---|
| Events | Title, start, end, description, image, admission (currency amount), location (relation), categories (relation) |
| Locations | Name, address, directions, map (file) |
| Categories | Label, color |

Three content areas, half an hour of setup – and the editors can get to work.

## One field name, one meaning: filtering across areas

Field names apply **system-wide** in camindo CMS: a name is always bound to the same type.
If you create a field `datum` of the type “Date + time” in the areas “News”, “Events” and
“Blog”, then `datum` is the same attribute everywhere.

This opens up a special possibility: templates can query, filter, and sort **several
content areas together**, as if they were one table. A timeline of news items, events, and
blog posts is a single command:

```cms
(CMS:SELECT zeitleiste_eintrag
  filter[area]=meldungen,veranstaltungen,blog
  filter[datum] >= 2026-01-01
  sort=datum
  order=desc
  limit=20
)
```

In the same way, a search for `filter[schlagwort]=…` finds the matching records in all
areas that have a field `schlagwort`. In addition, each record has a ***main field***
(usually a title or name), through which lists and relations can name it across areas –
without knowing what the field is called in the individual area.

***Recommendation:*** Give fields with the same meaning the same name in all areas
(`titel`, `datum`, `text`, `bild`, `slug`), and fields with different meanings different
names. This naming convention pays off as soon as templates combine content.

## Reserved name prefixes

The following prefixes are reserved for a field type. Using them can help avoid type
conflicts, but makes it harder to change the type of a field later (the field must then
be renamed first):

| Prefix | Type |
| --- | --- |
| bit_ | bit |
| chr_ | chars |
| cur_ | currency |
| dat_ | datetime |
| dec_ | decimal |
| enc_ | encrypted |
| fil_ | file |
| flt_ | float |
| ftx_ | fulltext |
| rid_ | id |
| ids_ | ids |
| int_ | integer |
| jsn_ | json |
| pwd_ | password |
| rel_ | pointer, relation, link |
| txt_ | text |
| tim_ | time |
| tsl_ | timestampl |

## Linking instead of maintaining several copies

Anything that appears in several places is created once as its own record and
**linked**: the author with their photo, the location with its address. A single change
is sufficient – all pages to which the record is linked are updated.

camindo CMS offers several kinds of links for this:

| Kind | Example |
|---|---|
| ***Relation, bidirectional (n:m)*** | article ↔ tags: the article knows its tags, the tag knows its articles |
| ***Relation, bidirectional (n:1)*** | article → main category: exactly one category per article |
| ***Reference, unidirectional (n:m or n:1)*** | “Related articles”, also from several areas |
| ***Fixed-linked content (1:n)*** | text-image blocks of a page: they belong exclusively to their record and are copied, moved, and deleted with it |
| ***Embedded content*** | like fixed-linked content, but edited directly in the input mask of the record – a flexible page construction kit |

Templates can query records via their links and sort by fields of linked records – for
example, articles by the name of their author.

## Hierarchies

If a content area receives the meta field “Parent record”, its records become a
***tree***: pages with subpages, categories with subcategories. Templates can query entire
subtrees or individual levels – the basis for navigation and breadcrumb trails.
Independently of this, ***folders*** arrange the content areas themselves clearly in the
back end.

## Multiple websites and languages

One installation can operate any number of websites, each as its own ***instance***, with
domains, languages, and a start page. Each content area belongs to an instance.

Instances can receive read access to other instances, and can thus use shared content on
different websites, while other content remains strictly bound to one domain – useful, for
example, for projects with several subdomains or branches of a company.

Each instance has a main language and any number of additional languages. Only what has to
be translated is multilingual: you decide on multilingualism _per field_. If a translation
is missing, the main language steps in – field by field. This way, the website is complete
in every language while the translation is still in progress.

## Templates: encapsulated functionality

***Templates*** output the content. They consist of the design (HTML, CSS, JavaScript)
and of ***CMS commands***, which insert content and perform tasks: query and filter
records, generate paging, create image versions, evaluate forms and save records from them,
register and sign in visitors, manage shopping carts, or generate PDF documents.  
When saving, camindo CMS translates each template into PHP. At runtime, there is no
interpretation overhead. CMS commands and PHP can be combined optimally.

A template can include other templates. This creates self-contained building blocks with a
clear task: an article list with paging, a form that saves submissions as records, a
member area with registration, sign-in, and password reset.

### Template sets

A ***template set*** is such a building block on a larger scale: the templates of a
function *together with the content areas* they need – for example, a news module with the
areas “News” and “Categories” and the templates for overview, detail page, archive, and
RSS feed.

A template set is imported and is ***immediately ready for use***. You can customize
everything.

Template sets are generated via XML export. The option “Optimize templates for import into
a foreign database” ensures that links between templates, areas, and records are adjusted
when importing into another installation (see [chapter 9](./09-structure-export-import.md#passing-on-template-sets)).

## Copying and passing on structures

Because content areas are settings and not programming, they can be copied and transferred:

- ***Copy area*** – a copy of a content area with all input fields and template
  assignments, but without records.
- ***Blueprints*** – prepared templates for new areas (for example “Articles”, “Members”,
  “Comments”) create the area and its fields in one step.
- ***XML export and import*** – entire structure trees with folders, content areas, input
  fields, templates, and, if desired, all records and files. With it, you transfer a
  development from the test environment to the live website, duplicate a proven website
  structure for a new instance, pass on template sets, or back up individual areas.

Details in [chapter 9](./09-structure-export-import.md).

## Security in everyday editorial work

- ***Working copies:*** Editing always happens on a copy. The website sees changes only after
  saving, complete and all at once.
- ***Edit locks*** prevent two people from changing the same record at the same time.
- ***Trash:*** Deleted items can be restored (usually up to 7 days after deletion). Later,
  changes can be reconstructed via the audit log.
- ***Rights:*** Users see only the modules, websites, areas and languages that are released
  for their user group. Write and delete rights can be controlled down to individual
  records.
- ***Encrypted fields:*** Password fields cannot be viewed by anyone; encrypted content can
  only be viewed in the back end or, if released, in protected areas of the website.
- ***Sign-in:*** Two-factor sign-in and the prevention of password guessing protect both the
  back end and the website.

---

## Guide: the first steps

After the [installation](./01-quick-start-installation.md), set up a website in this
order:

1. Check the ***languages*** and create new ones if needed – module “[Languages](./03-instances-and-languages.md#languages)”.
2. Create an ***instance***: name, main domain, main language, and additional languages –
   module “[Instances](./03-instances-and-languages.md#instances)”.
3. Create ***content areas***, preferably grouped in folders, and define their
   ***input fields*** – module “[Structure](./04-structure.md)”.
4. Create a ***template area*** and write the ***templates*** or import a template set;
   assign their default template to the content areas – see [chapter 6](./06-templates.md).
5. Set the ***start page*** in the instance (start record and start template).
6. Set up ***media converters*** for image versions and preview images and add thumbnail
   fields to the upload fields – [chapter 8](./08-media.md).
7. Create ***user groups*** and ***users*** for the editorial team –
   [chapter 7](./07-users-and-rights.md).
8. Secure the [operation](./12-operation.md): cron jobs, database backup, e-mail dispatch.
