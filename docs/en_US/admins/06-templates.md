# 6 Managing templates

How to write templates is explained in the [handbook for template programmers](../templateprogrammers/README.md).
This chapter describes how templates are organized and assigned
to the content.

## Template areas

Templates are kept in ***template areas*** (area type TEMPLATE, created in the module
“[Structure](./04-structure.md)”). Like content areas, they belong to an instance and can be
grouped in folders. It has proven useful to have one template area per function or template
set – for example “Layout” for the page frame, header and footer, “News” for the templates of
the news area.

In the module “Templates”, you open a template area and edit its templates there.

| Setting | Meaning |
|---|---|
| Name (internal) | name under which templates and addresses refer to the template. It only has to be unique within the template area; if it exists more than once, it is referred to as `areaname.name` |
| Label | caption that appears in the record when a template is selected (optional) |
| Standalone | the template may be called without a record, via the address `/0,name` – for example for a search page, a sitemap, or a contact form |
| Usage | “Scheduled (scheduler)”: the template only runs as a task of the [Scheduler](./10-modules.md#scheduler) and can never be called via an address (not even as a standalone template). Setting in the tab “Access” |
| Code | HTML with CMS commands and PHP |

The detail view shows the code with color highlighting. ***Errors*** in the code – an unknown
command, a wrong parameter, a missing bracket – do not prevent saving, but are displayed with
the line number above the code. References to other templates are clickable.

A template can be ***moved*** to another template area; its assignments are retained.

## Assigning templates to content areas

Which template a record receives in the frontend is decided by camindo CMS in this order:

1. a ***URL override*** template in the address (`/birthday-party,print`) – only if it is
   released for the content area,
2. the template selected in the record (meta field “Design template”) – only from the
   **selectable** templates,
3. the ***default template*** of the content area.

You maintain the assignment at two equivalent places: in the
[settings of the area](./04-structure.md#settings-of-a-content-area) or on the tab “Access”
of the template (“Add content area”, then for each area “Default”, “Selectable” and
“URL override”).

A template that is requested via the address without being released for it is not delivered.
In this way, no internal templates can be called from outside.

## Templates in the file system

Each template is also stored as a file on the server:

```
var/templates/{instance_id}/{area_name}/{template_name}.cms.php
```

This makes it possible to work with a familiar code editor, a version control system
(Git), or a deployment tool. camindo CMS synchronizes the files with the back end every
minute (and immediately via the button “Refresh” of the template lists):

| In the file system | Effect in the back end |
|---|---|
| new file | new template is created |
| file changed | the template content is read in again |
| file renamed | the old template is deleted, a new one is created |
| file deleted | template is deleted – including its assignments |
| new directory | new template area is created |

Conversely, the back end writes every change back to the file.  
![warning](../_/warning.svg) It is better to rename templates in the back end: in the file system,
renaming means “delete and create anew”, and the assignments are lost.

## Overview templates

If a content area uses a slug prefix (for example `events`), each record has an address
such as `/en/events/company-party`. The address consisting of the prefix alone – `/en/events`
– shows the ***overview template*** of the area: typically the list of its records. It runs
without a record and is selected in the settings of the area under “Template assignment”.
Because the prefix is translated for each language, the overview also has its address in
each language (`/en/events`). Without an overview template, this address results in “Page not found”.

Instead of a record, the overview template provides the details of the area – the ID
(`area_id`), the name, the label, the instance, and the slug prefix in the requested
language. This way, the template queries the records of its area without having to enter
the area explicitly (see the
[handbook for template programmers](../templateprogrammers/01-basics.md#overview-page-of-an-area)).

If you have assigned an overview template ***and*** a slug prefix to an area, the button “Preview”
appears in the settings of the area and above its record list. It shows the overview as it
appears in the frontend – with several languages as a selection with one entry per language,
each under the slug prefix of that language.

## List templates for the back end

A content area can receive a ***list template***. It is not used in the frontend, but in the back
end: it designs an additional column of the record list for each record and the detailed
preview in lookup fields – for example, a color field instead of the entered RGB color value,
or an entire text-image block in its final layout.

## Template sets

How you pass on templates together with their content areas as a template set, and use them
in other installations, is described in [chapter 9](./09-structure-export-import.md#passing-on-template-sets).
