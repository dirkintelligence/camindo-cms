# 10 Further modules

In addition to the modules for contents, structure, templates, instances, languages, users and media (chapters 3 to 8), camindo CMS brings further modules. You determine which of them a user group sees in the [user group](./07-users-and-rights.md#1-modules-and-additional-rights) – every user has the [trash](#trash) and [messages](#messages).

## Status

In the module “Status” you define the processing states that records can have. Each status has a label, an icon, a color and a position in the selection list. The following are prepared:

| Status | Meaning |
|---|---|
| Enabled | appears on the website |
| Disabled | does not appear on the website |
| Unconfirmed | for registrations of frontend users, until the confirmation link has been clicked |

On the website, by default only records with the status “Enabled” appear. The default for blocked records is the status “Disabled”. All further statuses correspond to this – they only serve to map editorial workflows, for example “Draft”, “For review”, “Ready to enable”, “To be revised” …

[CMS:SELECT](../templateprogrammers/select.md#state) only finds enabled records. Only for internal views is it possible to pass the parameter `filter[state]` to the command, in order to list records with a particular or any status.

In the input form of a status, you define under ***“May be set by”*** which user groups may give records this status (multiple selection; superusers may always set all statuses). A new status can initially be set by all user groups. The same setting can be found in the form of each user group – see [users and rights](./07-users-and-rights.md#4-set-status).

When you delete a status, you choose a ***replacement status***; all records with the previous status are switched over. The prepared statuses “Enabled” and “Disabled” cannot be deleted.

## Snippets

camindo CMS knows ***two snippet systems*** – two ways to maintain fixed texts separately from the templates. They differ in where the texts are stored and in which languages they exist:

| | Frontend snippets | Backend snippets |
|---|---|---|
| Languages | the language versions of the instance (for example `de_DE`, `sv_SE`) | the backend languages (for example `de_DE`, `en_US`) |
| Maintained by | editors, like any content – also via frontend editing | developers (language files) or users (module “Snippets”) |
| Where they are stored | records of a content area with a multilingual text field | language files `lang/{language}.ini` and own snippets in the module “Snippets” |
| How they are included | in the template `(CMS:TEXT id=slug)` or `(CMS:TEXT id=record_id)` | `#prefix.key` in the fields “Label”, “Note” and “Tooltip” |

For texts that appear on the website, the ***frontend snippets*** are the intended way: they follow exactly the languages of the website.

### Frontend snippets

A ***frontend snippet*** is an ordinary record – for example in a content area “Text snippets” with a field `slug` and a multilingual text field `text`. The template fetches the text via the slug or the record_id:

```cms
<p class="hint">(CMS:TEXT id=shipping-note)</p>
<p class="contact">(CMS:TEXT id=3109) (CMS:FED id=3109)</p>
```

Without `field`, `CMS:TEXT` reads the field `text` of the given record, in the language of the page called; if a text is not translated, the text of the primary language appears. With `CMS:FED id=…`, each text block receives its own pencil symbol for frontend editing. The editorial team maintains these texts like all content – with the status, publication date and rights of the content area. The area does not need a default template.

### Backend snippets

***Backend snippets*** are the texts of the user interface. They come from three sources and are addressed by a prefix:

| Source | Prefix | Example |
|---|---|---|
| Own snippets of the instance (module “Snippets”, see below) | `custom.{instance_id}` | `#custom.12.label_price` |
| Core: `Core/lang/{language}.ini` | none | `#button_save` |
| Modules: `lang/` and `backend/lang/` in the directory of the module | name of the module in lowercase letters | `#area.field_area_slug_prefix` |

In the input fields “Label”, “Note” and “Tooltip” (content areas and their fields), instead of a single-language text the reference to a multilingual snippet is inserted – form: `#prefix.key` (see [chapter 5](./05-input-fields.md#name-label-and-help-texts)). When displaying, the backend inserts the text of the backend language of the signed-in user (internally via `Cms::textOrSnippet()`); the extension ![translate](../_/translate.svg) [Snippets](./extensions/snippets.md) offers a search there.

Backend snippets are also available ***in the frontend***: the core uses them, for example, for the messages of the `CMS:USER_*` commands or for the ellipsis of `CMS:TEXT limit=…`. Because backend snippets exist only in the backend languages, each language version is assigned a ***backend language*** (setting “Backend language” in the module “[Languages](./03-instances-and-languages.md#languages)”): `de_DE` and `de_AT` both read the snippets of `de_DE`. A language version for which there is no backend translation shows the one in `en_US`. Templates can include backend snippets using `$this->cms->snippet('custom.key')`.

#### Installing additional languages

Further backend languages come as ***translation packages*** in the form of `.ini` files. The language is at the beginning of the file name: `sv_SE.ini`, `sv_SE_core.ini`, `sv_SE_ext_tinymce.ini`. These are stored in `var/translation/translated/`. A package takes effect after the snippet cache has been rebuilt (module “Tools”, tab “Developer”, or on the command line with `php Core/cron/camindo.php refresh snippets`).

#### Developing your own language pack

To translate the backend of camindo CMS itself into a further language, copy the translation templates from the directory `var/translation/blueprint/`.

`en_US_core.ini` becomes `{language}_core.ini`, `en_US_en_US.ini` becomes `en_US_{language}.ini`. `{language}_core.ini` contains all translations, `en_US_{language}.ini` registers the new language in the English language version and thus for all other languages.

Example for an `en_US_no_NO.ini`:

```ini
[backend]
backend_language_no_NO = "Norsk"
```

Example for a `no_NO_core.ini`:

```ini
[frontend]
core.yes = "Ja"

[backend]
core.button_save = "Lagre"
ext.datepicker.option_mode = "Modus"
```

Anything that a package does not translate appears in English.

### Snippets module

In the module “Snippets” you maintain your own backend snippets – labels such as “Read more”, “Search” or a note in the footer – without access to the server or templates, in all backend languages.

***Each instance has its own snippet set.*** The module lists the instances to which your user group has access, with the ***state of the translations***: which languages are complete, which are partially translated (with the number) and which have no texts yet. A click opens the set as a table: one row per ***key***, one column per displayed language. You select which languages appear as columns via “Languages” above the table (tick the boxes, click “Apply”); without a selection, you only see English. The selection applies to all sets and remains until you sign out.

A click on a row opens the form with all backend languages one below the other. A right-click opens the ***context menu***: “Edit” like the click; “Copy” opens a new form with the texts of the snippet – you only enter the key again; “Delete” shows the snippet with all texts and removes it in all languages after confirmation. “Add snippet” creates a new one. Missing translations receive the English text. Keys consist of lowercase letters, digits and underscores and cannot be changed after creation – templates and label fields refer to the snippet via them.

| Use | Notation |
|---|---|
| in the templates of your own instance | `custom.{key}` |
| in templates of other instances | `custom.{instance_id}.{key}` |
| in label fields of the backend (Label, Note, Tooltip) | `#custom.{instance_id}.{key}` – the extension “Snippets” offers a search there |

Which snippet language applies in the frontend is determined by the setting “Backend language” of the respective [language version](./03-instances-and-languages.md#languages).

The sets are stored under `var/custom/snippets/{instance_id}/lang/{language}.ini`; if the instance is deleted, its set is deleted as well. Sets in the earlier PHP format (`{language}.php`, even older `de.php`) are converted into `.ini` files by the rebuild of the snippet cache – in the module “Tools” (tab “Developer”, “Snippets”), with `php Core/cron/camindo.php refresh snippets` and at every `upgrade`. If a language already has an `.ini` file, both are merged; for the same key, the `.ini` file applies. The old file remains as `{language}.php.bak` and can be deleted once the snippets are correct again.

## Scheduler

The ***scheduler*** runs tasks on a time schedule. It requires the cron job `cron.php`, which runs every minute (see [Cron jobs](./12-operation.md#cron-jobs)). The maintenance tasks – backup, trash, temporary files, deadlines, logs, checks – are set up by the installation (see [operation](./12-operation.md#scheduler)). You can adjust the time of execution.

| Task | Effect |
|---|---|
| ***URL call*** | calls an address (optionally with user name and password) and saves the response under `var/custom/html/…` or discards it – for example to collect an export from another application or to warm up a cache. File names with a PHP extension are not allowed |
| ***PHP call*** | runs a PHP script from `var/custom/php/` on the command line – for project-specific automation. The output is saved under `var/custom/html/…` or discarded, as with the URL call |
| ***Database backup*** | writes a complete backup of the database to `var/dbbackup/`. `backup-1.sql` is always the newest; older ones move up (`backup-2.sql` …), beyond the set number they are deleted. It runs at most once a day. Every new backup is checked: if it is smaller than 10 KB or the final line `-- Dump completed` is missing, it is discarded and the run counts as failed. The detail view of the task marks unusable files |
| ***Clean up logs*** | deletes log files under `var/log/` that are older than the set period (default 90 days). Security logs (`security`, `csp`) have their own, usually longer period (default 365 days). In addition, failed sign-in attempts with name and IP address are deleted from the database (default after 30 days). Recommended: every night. The [audit log](#audit-log) has its own periods |
| ***Clean up frontend cache*** | deletes expired files of the output cache under `var/cache/include/`, which templates create with `CMS:INCLUDE … caching=…`, and files for deleted or since changed templates. Files with unlimited period remain until their template is changed. No settings. Recommended: hourly, for example at minute `15`, all others `*` |
| ***Rebuild full text*** | rebuilds the [full text](./05-input-fields.md#rebuilding-the-full-text) of the selected areas (without a selection: all areas) from the source fields – after a change to the source fields of a full-text field. The records themselves remain unchanged, the audit log receives no entries. If the maximum execution time is not sufficient, the run is aborted and counts as failed; then increase it |
| ***Empty trash*** | permanently deletes records and content areas that have been in the trash for longer than `admin.trash_retention_days` (default 7 days). No settings |
| ***Delete temporary files*** | cleans up `var/tmp/` (`admin.tmp_retention_hours`) and deletes expired export files (`admin.export_retention_hours`). No settings. Recommended: hourly |
| ***Apply deletion policies*** | carries out the due jobs of the [deletion policies](./15-privacy.md#deleting-according-to-deletion-policy). No settings |
| ***Apply retention periods*** | shortens IP addresses, deletes expired entries of the audit log, old internal messages and registrations that were never confirmed – according to the periods of the configuration; plus the periods of installed add-on modules, such as deleted chats of the AI assistant. No settings |
| ***Check audit log*** | checks the files of the [audit log](#audit-log) for manipulation; findings appear on the start page. No settings |
| ***Check for updates*** | looks for newer versions and known security vulnerabilities (see [updates](./12-operation.md#updates)). No settings |
| ***Summarize messages by email*** | sends users who have chosen it in their personal settings a summary of their new internal messages (see [messages](#messages)). New are the messages since the previous run – run it once a day. No settings |
| ***Template*** | runs a frontend template with the use “Scheduled”, in one language of its website – for example for emails about the expiry of a blocking period, reminders or a feed. The template learns which period has passed since the last successful run; if a run fails, the next one covers that period. The output is saved or discarded as with the URL call. If the template reports an error (for example an email without a valid recipient), the run counts as failed |

The ***time control*** follows the cron notation with six values: minute, hour, day, month, year, weekday (0 = Sunday). For each value: `*` for every value, a number, a range `1-5`, a step `*/10` or a list `1,15,30`. The task runs when all six values match. Example for daily at 3:30 a.m.: minute `30`, hour `3`, all others `*`. The times apply in the time zone of the system.

***Outputs and scripts separated:*** Fetched or generated contents end up only under `var/custom/html/`, executable scripts lie only under `var/custom/php/`. In this way, a response that a URL call fetches can never be executed as a PHP script. Path specifications must not lead out of the respective directory (no `..`, no links to the outside). When you update, move existing scripts to `var/custom/php/`.

***Run as:*** Each task runs under one user and with that user’s rights; in the [audit log](#audit-log) this user appears with the origin “scheduler”. New tasks run under the user `scheduler`, which the installation creates (user group superuser, without sign-in to the backend). To choose from are users whose user group may use the interface “Command line (CLI)”. If the user is deleted or blocked, or loses this right through its user group, the task fails with a notice until you assign another user to it. A PHP script works via the API under this user (see the developer documentation).

Further settings: active/inactive, maximum execution time, and whether every run is written to the system log (errors always are). The list shows the next and last run with its result; “Run now” starts a task immediately as a background process.

## Tools

The module “Tools” has five tabs; each has its own additional right.

| Tab | Tools |
|---|---|
| “Server” | versions of camindo CMS, operating system, web server, database and PHP; check and renewal of the `.htaccess` file; “Clear record cache” – for all websites, one website or one area; MySQL configuration; availability of ImageMagick and ffmpeg; PHP info |
| “Database” | “Empty trash” – permanent deletion of records and areas that have been in the trash for more than *n* days (0 = everything); “Discard working copies” – remove leftover working copies older than *n* hours and release the originals again |
| “Data” | “Regenerate thumbnails” for all records of an area (see [chapter 8](./08-media.md#regenerating-thumbnails)); “Normalize tree” – checks and repairs the order in areas with a tree structure, for example if it has become mixed up through direct database changes; the existing order is retained |
| “Trash of a user” | immediately delete permanently the profile and contributions of a website user that are in the trash – for example at the user’s request (see [chapter 15](./15-privacy.md#emptying-a-website-users-trash-immediately)) |
| “Developer” | renew caches (snippets, backend templates, instances); translate template files again and compare them with `var/templates` (including the parser messages – on the command line `php Core/cron/camindo.php cache templates`, automatically at `upgrade`); renew the access rules of the media directory; show session data |

camindo CMS renews the caches itself after changes. The tools on the tab “Developer” are only needed after interventions in the file system or for troubleshooting.

## Template messages (widget)

The module “Templates” brings a ***widget*** for the start page (personal settings, “Dashboard widgets”): it lists every template with its parser messages – errors in red, notes in yellow – and opens it with a click. This is helpful above all when templates are edited in the file system (`var/templates`) and nobody sees the editor. The notice always says that every script in a template needs `<script(CMS:NONCE)>` so that it runs under the script policy of the website ([chapter 3](./03-instances-and-languages.md#script-policy)). It is visible to user groups with access to the module “Templates”; only template areas that the user group may read are shown.

## Log files

The module “Log files” (superusers only) makes the logs under `var/log/` searchable in the backend. You can filter by log, day, type and search text. The result list shows the newest entries first, 100 per page; ***“Older entries”*** and ***“Newer entries”*** page on, the filters are kept.

The entries are sorted by ***types***, in the order of their urgency:

| Type | Examples |
|---|---|
| Blocked sign-ins | too many failed attempts at signing in |
| Security | signs of manipulation |
| Unsent emails | errors when sending email |
| PHP errors | aborted programs |
| Database errors | faulty database queries |
| Background processes | errors in import, export, batch processing, thumbnails |
| PHP warnings, other | notes |

The ***widget*** “Log files” summarizes the most important messages of the last few days on the start page.

## Audit log

The ***audit log*** records who changed what and when – with the state before and after. The following are logged:

- every creation, change, deletion (into the trash), restoration and permanent deletion of records, areas, fields, templates, users, user groups, instances, languages, statuses, converters, scheduler tasks and components, regardless of whether it happens via the input form, the frontend (contributions of website users), an import, batch processing or the scheduler task “Empty trash”
- failed sign-ins to the backend; successful sign-ins and sign-outs only with `audit.log_logins = true` (default: not recorded)
- every [export of personal data](./15-privacy.md#exporting-personal-data)
- every change made by a [deletion policy](./15-privacy.md#deleting-according-to-deletion-policy) (action “Privacy”)

When changes are made, ***all fields*** are compared, including meta fields such as status, publication date or owner; for multilingual fields, each language individually. Only what has actually changed is saved; saving without a change creates no entry. Passwords never appear in plain text, only as “changed”. Encrypted fields remain encrypted in the log and are decrypted only when displayed. Private messages between users are not logged, nor is the [full text](./05-input-fields.md#full-text): it is derived from the text fields, and the scheduler task “Rebuild full text” leaves no entries.

***Tab “Changes”:*** In the detail view of each record, the tab “Changes” shows the last ten entries of its history, newest change first; the complete history is shown by the module “Audit log”. The tab does not exist in selection windows (relation fields). For each field, before and after are displayed side by side. Texts are compared paragraph by paragraph: unchanged paragraphs are gray, removed ones red, new ones green; in changed paragraphs the individual words are marked. Everyone who may read the area can see the tab; the IP address only superusers.

The same tab ***“Changes”*** is available in the detail views of areas, input fields, templates, statuses, instances, languages, converters, scheduler tasks, components and user groups – in each case for everyone who may open the detail view. For users, only superusers see it; it shows what was changed on the account (including sign-ins), while the tab “Activity” beside it shows what the user did themselves. For templates, the source text is compared; the colored view that results from it does not appear in the log.

***Module “Audit log”*** (superusers only): searches the entire log by period, user, action, object type, ID, designation, name or IP address and by changed fields (for example `status_id`), page by page like the other lists. A click on an entry opens the comparison; “Full history” shows all entries for this object.

***Research via the API and the AI assistant:*** The same entries can be searched and read via the programming interface – so the [AI assistant](#add-on-ai-assistant) can also answer questions such as “Who deleted article X yesterday?” or “How has this record changed since it was created?”, even if the record is in the trash or has been permanently deleted. Superusers see the entire log; everyone else – as in the tab “Changes” – only the entries for records in areas they may read, and no IP addresses.

***Tab “Activity”:*** In the detail view of a user (module “Users”), the tab “Activity” shows only superusers what this user has left in the audit log: at the top the ***recently edited records*** – for each record the last action with the time and the number of its entries, a click on it opens the history – below, ***all actions*** page by page, including sign-ins and changes to areas or fields. “Open in audit log” leads into the module, pre-filtered for this user.

***Retention:*** Settings in `.camindo.php` (see [configuration](./12-operation.md#configuration)):

| Key | Default | Meaning |
|---|---|---|
| `audit.retention_days_changes` | `0` | Entries for existing objects, in days. `0` = unlimited: every record remains traceable back to its creation |
| `audit.log_logins` | `false` | Record successful sign-ins and sign-outs to the backend. They say nothing about changes, but they do say something about working hours – therefore off |
| `audit.retention_days_logins` | `90` | Sign-ins and sign-outs as well as failed sign-ins, in days. `0` = as `audit.retention_days_changes` |
| `audit.retention_days_deleted` | `365` | History of permanently deleted objects, counted from the permanent deletion (after the trash). `0` = unlimited. Does not apply to records: their history is overwritten or deleted by the [deletion policy](./15-privacy.md#deleting-according-to-deletion-policy) of the area |
| `audit.enabled` | `true` | switch the audit log on or off |
| `audit.page_bytes` | `1048576` | size in bytes from which the file of an object under `var/audit/` begins a new page |

The scheduler task “Apply retention periods” removes expired entries (default: every night).

## Privacy

The module “Privacy” has these tabs:

| Tab | Content | Additional right |
|---|---|---|
| “Deletion policies” | define what happens when a record is deleted – put into the trash, permanently delete immediately or with a delay, overwrite individual fields, overwrite or delete the history in the audit log, run your own rules in the trigger template – also for sub-records, the contributions of a deleted profile and the records of a deleted area. Every content area has one policy | “Create and change deletion policies” – for the instances to which the user group has access |
| “Templates” | templates of the personal data report for each instance, type (website user or user) and language – see [chapter 15](./15-privacy.md#templates-of-the-personal-data-report) | “Maintain templates of the personal data report” – for the instances to which the user group has access |
| “Data export” | export of personal data of a website user or a user as a zip file | “Export personal data” – for profiles of the readable areas and users of the visible user groups |
| “Deleted users” | list of deleted users with user group and deletion date – only from the user groups whose users you may see in the module “Users”. A click leads to the info page of the user in the module “Users”; if the user has not yet been anonymized, the button “Anonymize” appears there. See [chapter 15](./15-privacy.md#anonymizing-deleted-users) | tab: access to the module “Users”. Anonymize: “Anonymize deleted users” – for users of the visible user groups |

Everyone with access to the module may read the policies. Only a superuser changes the three standard policies and the policies for all instances. In detail: [chapter 15](./15-privacy.md#deleting-according-to-deletion-policy).

## Components

The module “Components” shows all modules, media processors and extensions with origin (core or add-on), version and state:

| State | Meaning |
|---|---|
| Core | part of the core, always active |
| discovered – not installed | the files are present, but the component is not yet set up |
| installed, inactive | set up, but switched off |
| active | available in the backend and frontend |
| update pending | newer files are present; “Update” brings the database up to date |
| orphaned – files missing | the files were removed without uninstalling the component |

The actions “Install”, “Activate”, “Deactivate”, “Update” and “Uninstall” explain in their form what they do. When you uninstall, tables and data are retained unless you explicitly choose “Delete tables and data”; if you wish, the database is backed up beforehand. “Compare” compares the existing files with the database.

The ***files*** of a component are always fetched and removed by Composer on the command line (`composer require …` / `composer remove …`, see [installation](./01-quick-start-installation.md#install-additional-components)); the module sets them up in the database.

## Trash

Every user has the module “Trash”, regardless of their user group; it appears as a button on the start page and as a widget that shows the number of entries. How editors work with it is described in the [manual for editors](../editors/05-editing-records.md#trash).

Each entry stands for ***one deletion operation***: if a record is deleted together with its sub-records, only the topmost appears; hard-linked content and the records of a deleted area do not appear individually at all. “Restore” always brings back everything that was deleted in the same operation – recognized by the identical deletion time. What was already deleted in an operation of its own before remains in the trash.

| Who | Sees |
|---|---|
| every user | “Records deleted by me” – from areas to which they have access, and not from deleted areas |
| superusers | additionally “Deleted areas” (with instance, scope and who deleted them) and, via ![group](../_/group.svg) “All users”, the deleted records of all users |

Restoring requires the right to delete in the area (see [area rights](./07-users-and-rights.md#5-area-rights)); if it is missing, the entry shows “no permission”. How long entries stay in the trash is determined by the [retention period](./12-operation.md#trash). Large operations (more records than `admin.mass_delete_threshold`) run as a background process, as with deletion.

## Messages

The internal ***messages*** between users are reached via the envelope symbol in the sidebar. They are described in the [manual for editors](../editors/03-work-surface.md#messages). Messages are deleted automatically `privacy.message_days` days (default 60) after sending; a superuser deletes all messages of a user in that user’s settings (see [chapter 15](./15-privacy.md#users-employees)).

Each user can have a daily ***summary by email*** sent in their personal settings: unread messages per sender and the new ones with date, sender and subject – without message texts, without contents from records and without a link into the backend. It is sent by the scheduler task “Summarize messages by email” (default daily at 7:00), and only if new messages have arrived since the previous run. Prerequisites are the [email dispatch](./12-operation.md#email-dispatch) and an email address in the user’s account. Bear in mind that the sender and the subject thereby leave the house.

## Add-on modules

***Add-on modules*** are separate packages that you fetch via Composer and install as a component (see [installing add-on components](./01-quick-start-installation.md#install-additional-components)). Each brings its own manual: after installation it appears in the overview of the manuals under “Manuals of add-on components” – with setup, settings and all details.

### Add-on PDF

The add-on ***PDF*** (`camindo/module-pdf`) creates PDF documents directly from templates – invoices, tickets, data sheets, certificates – on request on a PDF template such as letterhead, with texts, HTML, images, lines and tables, in RGB or CMYK.

```bash
composer require camindo/module-pdf
php Core/cron/camindo.php component install Pdf --activate
```

### Add-on AI assistant

The add-on ***AI assistant*** (`camindo/module-ai`) brings a language model into the backend: users write in the side panel what they need, and the assistant searches, reads, creates and changes – with the rights of the user and only after confirmation. How editors work with it is described in the [manual for editors](../editors/03-work-surface.md#ai-assistant-add-on-module).

```bash
composer require camindo/module-ai
php Core/cron/camindo.php component install Ai --activate
```

***Who may use it*** is set in the module “AI assistant” in the [user group](./07-users-and-rights.md#1-modules-and-additional-rights). Provider and access key are in the [configuration](./12-operation.md#configuration) (`ai.*`).
