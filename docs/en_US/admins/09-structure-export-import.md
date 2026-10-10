# 9 Exporting and importing structures

With the ***XML export*** you write whole structure trees into one file: folders, content areas with all input fields and settings, template areas with their templates and, if you wish, all records including their files. The ***XML import*** rebuilds this structure in the same or in another camindo installation.

Typical uses:

| Task | Procedure |
|---|---|
| ***Duplicating a website structure for a new instance*** | Export the structure and templates, import them into the new instance, rename the areas |
| ***Transferring development to the live website*** | Export the areas, fields and templates developed on the test system and import them on the live system – existing areas are updated |
| ***Passing on template sets*** | Provide a function with its content areas and templates as one package (see [below](#passing-on-template-sets)) |
| ***Backing up areas*** | Save the structure and contents before a major rebuild |

Exporting and importing whole structures is reserved for superusers. The export and import of the records of *one* area is described in the [manual for editors](../editors/08-export-import.md).

## Exporting

Open the module ![draft](../_/draft.svg) “Contents” and choose “Export” in the menu ![more_vert](../_/more_vert.svg) on the start page. The form shows the tree of all folders and areas of all types with check boxes, grouped by ***instances***: each instance appears like a folder with its name, below it its folders and areas. A folder or an instance selects the elements it contains; individual elements can be deselected again.

| Option | Effect |
|---|---|
| Export structure | Folders, content areas and input fields with all settings (including the slug prefix and the deletion policy), template assignments (including the trigger templates in their order), the converters of the thumbnail fields and the snippets of the instance that the labels refer to (`#custom.…`) |
| Export contents | The records of the checked content areas (all fields, all languages) and the templates of the checked template areas – together with the snippets of the instance that these templates use (`custom.…`) |
| Export upload files | The files of the records are included in the zip file |
| Optimize templates for import into a foreign database | Marks references in the templates so that they are adjusted when imported into another installation (see [below](#passing-on-template-sets)) |
| Run as a background process | The download link comes as an internal message |

The result is a zip file with the XML file and, if applicable, the files. The download link works once; files that are not collected are deleted after 24 hours.

The file contains encrypted contents in encrypted form and passwords only as a check value. Both depend on the key file `.camindo.key`: in an installation with a different key, encrypted contents cannot be read, and imported passwords (for example those of members) no longer match – the members then have to request a new password.

## Importing

In the module “Contents”, choose “Import” in the menu on the start page, upload the zip or XML file (or select it from the upload directory). The form then shows:

| Setting | Meaning |
|---|---|
| Instance | Instance for newly created areas. Within the same installation, the instance from the file can also apply |
| Target level for new folders and areas | Folder under which newly created areas end up. Areas whose folder is also in the file end up under that folder. Only the folders of the selected instance are offered (with “from the import file”, all folders) |
| Converters of the thumbnail fields | For each converter in the file: use an existing one, create a new one or ignore it (see below) |
| For each area in the file | Name (editable), mode and matching criterion – separately for structure, records and templates |

The areas of the file are listed indented according to their hierarchy. A selection in the column header sets the mode for a whole column.

If labels of areas and fields refer to snippets of the instance (module “Snippets”, `#custom.31.title`), the import takes these snippets into the set of the ***target instance*** and rewrites the references to them (`#custom.32.title`). Existing snippets of the target instance remain unchanged; the log names the snippets that were taken over and the keys that were kept.

The same applies to the snippets that imported ***templates*** output – for example `(CMS:SNIPPET custom.weiterlesen)`. They end up in the set of the instance of the template area; the templates themselves remain unchanged (the short form `custom.…` always means the set of the instance in which the page runs). Composed keys such as `"custom.status_{$cms['status']}"` bring all snippets that start with this prefix.

The slug prefix of an area is assigned to the languages of the target instance by their language codes. If it is not permitted there – for example because another area already uses the same prefix – it is dropped with a note in the log.

### Mode and matching

For each area, choose a mode separately for “Structure”, “Records” and “Templates” – INSERT, UPDATE, UPDATE_OR_INSERT, REPLACE, REPLACE_OR_INSERT or IGNORE (for the meaning, see the [manual for editors](../editors/08-export-import.md#mode)) – and the criterion by which an existing element is recognized:

| Element | Matched by |
|---|---|
| Area (structure) | Name or area number |
| Records | Slug or record number |
| Templates | Name or template number |

If the file comes from the ***same installation***, UPDATE_OR_INSERT is preselected: existing elements are updated, missing ones are created. If it comes from a **different installation**, INSERT is preselected, and numbers never count as a criterion – there they belong to completely different elements. Matching then only takes place by names and slugs; new records receive new numbers. An area that does not yet exist in the target knows only INSERT and IGNORE.

With REPLACE, input fields that the file does not name are removed from the area.

### What happens during the import

1. Areas are created or updated in the order of the file, parents before children.
2. The ***converters*** of the thumbnail fields are assigned: within the same installation by their number, otherwise by their settings. If there is no matching converter, one is created – provided its processor is installed. A thumbnail field without a converter is skipped.
3. Input fields, then templates (they are translated again in the process), then the settings of the areas: default and sort field, default template, template assignments, trigger templates, sign-in fields. The import takes over the ***deletion policy*** only from the same installation. If the file comes from another installation, a newly created area receives the default policy of its type (“Delete record”, “Delete profile” or “Delete user-generated content”), and an existing area keeps its own.
4. The records in two passes: first the contents, then the links – using the actual numbers of the target database. Links between imported records are thus preserved, even if all numbers change.
5. Thumbnails and full texts are created again.

As with the import of a single area, an ***analysis*** beforehand shows what will happen, and a ***log*** afterwards shows what has happened – per area, field, template, converter and, if requested, per record.

The creation date of the records is retained. The import takes over the creator only from the same installation; otherwise the importing user applies.

## Duplicating a structure

Example: you run the website of an association and want to use the same structure for a second association.

1. Create the new instance (module “[Instances](./03-instances-and-languages.md)”).
2. Export the folder of the first website with “Export structure” and “Export content” – this way the templates come along. To exclude records that should not be taken over, set their areas to IGNORE for the records when importing.
3. Import the file: instance = the new instance, mode INSERT. Area names only need to be unique within an instance and the instances that it may read or that read it. If the two websites are independent of each other, the areas keep their names, and the templates work unchanged. Otherwise, enter new names in the import form, for example `association2_dates` instead of `dates`.
4. In the new instance, enter the start record and the start template.

## Passing on template sets

A ***template set*** consists of the templates of one function and the content areas that it needs (see [chapter 2](./02-concept.md#template-sets)). Templates refer in their CMS commands to other templates, to areas and sometimes to individual records:

```cms
(CMS:INCLUDE header)
(CMS:SELECT news_list filter[area]=announcements src_id=1758 …)
```

In another installation – or after renaming during the import – these names and numbers no longer match. The export option “Optimize templates for import into a foreign database” marks such references in the export file. The import replaces them with the names and numbers of the imported elements:

- An area that is renamed during the import is also named that way in the templates.
- A reference to a record points to the imported record; if it was not imported, to the record with the same address (slug prefix and slug) in the instance of the template.
- What cannot be matched keeps the value from the file; the log counts these cases.

To provide a template set:

1. Create the content areas and the template area of the set in a folder of their own. Follow the [naming conventions](./02-concept.md#one-field-name-one-meaning-filtering-across-areas).
2. If necessary, create a few sample records – after the import, they show immediately how the set works.
3. Export the folder with structure, contents, files and the option “Optimize templates for import into a foreign database”.
4. In the target installation: import, check the default templates, adjust the design (HTML, CSS) to the website.

References that a template computes only at runtime (for example from PHP variables) cannot be recognized by the import. Therefore always test an imported template set.
