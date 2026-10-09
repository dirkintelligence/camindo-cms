# 4 Structure: folders and content areas

In the module “Structure”, you determine which content camindo CMS manages: you set up
folders and content areas and define their input fields. The concept behind this is
described in [chapter 2](./02-concept.md).

## The area list

The module shows all folders and areas in their tree structure, grouped by instance when
there are several instances. “New” creates a folder or area, “Search” filters the list.

***Dependent areas*** appear grayed out – for example the text-image blocks of a page.
These areas are not directly accessible in the module “Content”. Their records are edited
directly in the input mask of the record to which they belong
(see [chapter 5](../editors/05-editing-records.md#embedded-content)).

## Area types

When creating an area, you first choose what it is to contain:

| Type | Symbol | Content |
|---|---|---|
| ***RECORD*** | ![draft](../_/draft.svg) | content area for records of all kinds. You add the input fields yourself |
| ***PROFILE*** | ![face](../_/face.svg) | profiles of registered frontend users – see [chapter 13](./13-community.md) |
| ***UGC*** | ![chat_bubble](../_/chat_bubble.svg) | contributions that signed-in frontend users create on the website – see [chapter 13](./13-community.md) |
| ***TEMPLATE*** | ![extension](../_/extension.svg) | template area for page layouts (HTML), PHP code and CMS commands – see [chapter 6](./06-templates.md) |
| ***FOLDER*** | ![folder](../_/folder.svg) | folder that groups several areas |

For RECORD, PROFILE, and UGC areas, the selection additionally offers ***blueprints***:
ready-made templates that create the area with suitable input fields.

| Blueprint | Type | Fields |
|---|---|---|
| Article | RECORD | Title, date, teaser, text, and image |
| Members | PROFILE | Name, e-mail (sign-in name), and password |
| Comments | UGC | Name and comment text |

The fields of a blueprint are a starting point – you can change and extend them as you
like afterwards.

### Own blueprints

You store your own blueprints as XML files in the directory `var/area_blueprints/` of the
installation; the selection when creating an area shows them together with the supplied
ones. For this, you need access to the file system of the server.

To create a blueprint:

1. Set up an area with the desired settings and input fields.
2. Export only this area with the option “Export structure”, without content
   (see [chapter 9](./09-structure-export-import.md#exporting)).
3. Unpack the ZIP file and save the XML file as
   `var/area_blueprints/{name}.xml`, e.g. `var/area_blueprints/appointments.xml`.

Rules:

- The ***file name*** without `.xml` is the name of the blueprint: letters, digits,
  underscore, and hyphen. If a file has the same name as a supplied blueprint
  (`article`, `member`, `comment`) and the same type, it replaces that blueprint.
- The type under which the blueprint appears (RECORD, PROFILE, or UGC) is determined by the
  area in the file. Other types do not appear.
- The ***label*** of the area appears as the heading in the selection and its ***note*** below
  it. Both may be a text or a snippet reference (`#label_articles`) – a reference appears in
  the language of the user. The labels of the fields are taken over in the language of the
  user when creating.
- Only the first area in the file is evaluated; records in it are ignored. Label and note of
  the new area remain empty and are entered in the mask; name, icon, and sort direction come
  from the file.
- Fields of the type ***Relation***, ***Reference*** or ***Fixed-linked content*** whose target
  area is the area itself – for example “Related articles” in an article area – point to the
  new area in the new area. Targets in ***other*** areas (for example a reference to an images
  area) cannot be resolved: the field is created without this target area; add it afterwards
  in the settings of the field.

Back up `var/area_blueprints/` together with the other project data
(see [chapter 12](./12-operation.md)).

## Settings of a content area

| Setting | Meaning |
|---|---|
| Name | internal name for the templates, e.g. `artikel` – lowercase letters, digits, and underscore, starting with a letter. Templates address areas by this name, not by their number; this way, an area can be recreated or moved without changing templates |
| Icon | symbol in the back end, selectable via a search |
| Label | name of the area in the back end |
| Instance | website to which the area belongs. Its records belong to the same instance |
| Parent folder, Preceding element | place in the area tree |
| Note | appears above the record list – keep it as short as possible. A snippet reference (`#custom.key`) appears in the language of the user, as with the label |
| Main field | field that represents the record in lists and relations (usually title or name) – selectable only after the fields have been created |
| Default sort order, sort direction | order of the records in the record list and default for templates |
| Multilingual input | “All languages on one page” or “One tab per language” – how the input mask displays multilingual fields |
| Slug prefix | optional first part of the address of all records of the area, per language (see [below](#slug-prefix)) |
| Deletion policy | rule according to which records and audit logs of this area are handled; cf. [chapter 15](./15-privacy.md#deleting-according-to-deletion-policy). The policy applies to every deletion – in the back end, via the API and with CMS:USER_DELETE in the frontend. The standard policy for the area type is preselected (“Delete record”, “Delete profile”, “Delete user-generated content”) |
| Dependent area | the records belong exclusively to one record each of another area (see [Fixed-linked and embedded content](./05-input-fields.md#fixed-linked-and-embedded-content)). The area then does not appear in the area list of the module “Content” |
| Default template | template with which each record of the area is output in the frontend, as long as no other is selected in the record |
| Selectable templates | templates that editors may choose alternatively in the record (meta field “Design template”) |
| URL override templates | templates that may be called via the address of a record instead of the assigned one – for example the print version of a content item or the output in another format (`/news,xml_rss`) |
| Overview template | template that is called when the address consists only of the slug prefix (`/en/events`) – an overview of the area without a record, for example the list of all events. Without an overview template, this address results in “Page not found”. With an overview template and slug prefix, the area receives the button “Preview” (see [Overview templates](./06-templates.md#overview-templates)) |
| List template (back end) | only for the back end: formats an additional last column of the record list for each record; for example a directly displayed RGB color value |
| Trigger templates | templates that are called on events of the records and execute PHP code – when saving, deleting, restoring, and for the actions of the deletion policy (see [Handbook for template programmers](../templateprogrammers/03-trigger-templates.md)). You can assign several; they run one after another in the displayed order, which you change with the arrow buttons ![arrow_upward](../_/arrow_upward.svg) ![arrow_downward](../_/arrow_downward.svg) |
| Access for user groups | appears only when creating a new area: which user groups may use the new area; can be changed later in the tab “Access” |

PROFILE and UGC areas have additional settings for sign-in on the website
(see [chapter 13](./13-community.md)).

### Slug prefix

A content area can prefix the addresses of its records. This allows identical slugs to
occur in different areas of an instance:

| Area | Slug prefix | Slug | Address |
|---|---|---|---|
| Colors | `colors` | `blue` | `/colors/blue` |
| Colors | `colors` | `other/red` | `/colors/other/red` |
| Places | – | `bayern/schwabing` | `/bayern/schwabing` |
| Places | – | `red` | `/red` |

- The prefix is a single part of the address – without a slash. The slugs of the records,
  on the other hand, may contain slashes.
- It is entered per language of the instance (all languages among each other); an empty
  language takes over the prefix of the main language.
- Folders and template areas have no prefix. It is not taken over when an area is copied.
- The address consisting of the prefix alone (`/en/events`) shows the ***overview template***
  of the area, if one is assigned.

The address as a whole must be unique – within the instance and within the instances that
are linked with it via “Readable instances”:

- Two areas cannot have the same prefix.
- A record of an area ***without*** a prefix must not begin with a prefix: the slug `colors/yellow`
  in the area Places is rejected when saving (“'colors/' is a reserved slug prefix of content area …”).
- camindo CMS rejects setting, changing, or removing a prefix if this would give two records
  the same address or if existing addresses would begin with the new prefix – the message
  names the affected record.

When an address is requested, camindo CMS first checks whether its first part is the prefix
of an area and searches the remainder only there; otherwise it searches the entire path in
the areas without a prefix. Templates generate links with [CMS:URL](../templateprogrammers/url.md) –
the prefix is added automatically.

## The tabs of an area

The detail view of an area has the following tabs:

- “Settings” – the settings above, with “Edit”, “Copy” and “Delete”;
- “Input fields” – the fields of the area (see [chapter 5](./05-input-fields.md));
- “Access” – which user groups may read, write, and delete the area
  (see [chapter 7](./07-users-and-rights.md#5-area-rights));
- “Records” or “Templates” – the content of the area.

## Copying an area

“Copy” creates a new area with all settings – for content areas including all input fields,
default and sort field, and template assignments.
Records and templates are not copied, and the slug prefix is not copied either.
Labels that refer to snippets of the instance remain references – when copying into another
instance, the snippets come into its set. In the input mask, you assign label, name,
instance, and place in the tree of the copy. After saving, camindo CMS shows the detail view
of the copy.

If a relation field of the original points to the area itself (for example “Related
articles”), it points to the copy in the copy. Relations to other areas remain in place;
bidirectional relations receive a new counter-field there.

To copy entire folders with areas, templates, and content, use
[XML export and import](./09-structure-export-import.md).

## Deleting areas and folders

A content area is deleted ***together with its records***, a template area together with its
templates; the confirmation prompt states the number. When deleting a folder, you choose
whether the contained areas are deleted as well or moved up one level.

Deleted areas go to the trash like records and are only permanently removed after the
retention period (see [chapter 12](./12-operation.md#trash)).
Until then, a superuser can restore them – with all subareas and records that were deleted
together with them.

The records of a deleted area go with it into the [trash](./10-modules.md#trash) and are
permanently deleted after a few days. The deletion policy of the area takes effect here
together with the blocks for the trigger “Area was deleted” (see
[chapter 15](./15-privacy.md#structure-of-a-policy)), with the periods calculated from the
deletion – with the standard policies, the history in the audit log is deleted after 1 year.
If it is a profile area, the contributions of the users are deleted immediately according to
their own policies (trigger “UGC: profile was deleted”) – this does not undo a restoration of
the area.

## Instances

If you operate several instances, each subtree belongs to one instance: an area can only
stand in a folder of the same instance. If an area changes its instance, its records,
templates, and media files move with it.
