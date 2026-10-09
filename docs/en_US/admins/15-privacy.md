# 15 Data protection

This chapter describes in detail which personal data camindo CMS stores, where they lie and how long they remain – separated by ***users*** (your staff in the backend), ***website users*** (frontend community) and ***visitors*** without an account. It is the basis for your record of processing activities and your privacy statement. The overview for decision-makers is in [chapter 14](./14-security-and-compliance.md#data-protection-gdpr).

- [Users (employees)](#users-employees)
- [Website users (community)](#website-users-community)
- [Visitors without an account](#visitors-without-an-account)
- [IP addresses](#ip-addresses)
- [Deleting according to deletion policy](#deleting-according-to-deletion-policy)
- [Exporting personal data](#exporting-personal-data)
- [What lies outside camindo CMS](#what-lies-outside-camindo-cms)
- [Checklist](#checklist)

All periods are defaults; the keys are in `.camindo.php` (see [chapter 12](./12-operation.md#configuration)). Deleted data live on in ***database backups*** until the backup itself expires – this belongs in your deletion concept.

## Users (employees)

| | Data | Where | How long |
|---|---|---|---|
| Unencrypted user data | sign-in name, names (first, display and sort name), user group, status (enabled/blocked/deleted) | table `admin` | as long as the account exists; deleted users remain, so that “created by/changed by” in contents and in the audit log remains valid – until they are [anonymized](#anonymizing-deleted-users) |
| Encrypted user data | email, mobile, telephone | `admin`, encrypted | as above |
| Password | hash (not reversible)<br>day of the last change (without time) | `admin` | as above |
| Two-factor data | authenticator app, passkeys (designation, time), recovery codes | `admin` (key encrypted), `admin_passkey`, `admin_recovery_code` (hashes only) | until removal; passkeys and recovery codes with the deletion of the account; the key of the app when anonymizing |
| Personal settings | language, time zone, widgets, reports … | `admin.settings` | as long as the account exists |
| Session data | interface, last access | `admin_session` | until sign-out or `admin.max_inactive_sec` (30 minutes) of inactivity; with the deletion of the account immediately |
| Known devices | browser/operating system, ***shortened*** IP address, time of first and last sign-in | `login_device` | `security.device_days` (180 days) after last use; with the deletion of the account immediately |
| Last sign-in | day (without time) | `admin.login_date` | overwritten at every sign-in |
| Ongoing sign-in | time at which the two-factor sign-in began (sending of the code) | `admin.uid_date` | only until the sign-in is completed, then emptied |
| Activities | what the user changed when, with IP address | audit log: `audit_log` (metadata), `var/audit/` (contents) | `audit.retention_days_changes` (unlimited); entries on records according to the deletion policy of their area; the IP address is shortened after `privacy.ip_anonymize_days` (7 days) |
| Failed sign-ins (audit log) | number or entered name, IP address, time, interface (backend, PHP API, command line)<br>(under the account’s number if the account is recognized, otherwise with the entered name) | audit log | `audit.retention_days_logins` (90 days) |
| “Created by”,<br>“Changed by”,<br>owner | times | table `record` | as long as the record exists |
| History | recently opened records and templates, time | `history` | only the most recent entries; older ones drop out automatically; with the deletion of the account all of them |
| Internal messages | sender, recipient, subject, text encrypted, time | `message` | `privacy.message_days` (60 days) after sending – for both sides, also unread; earlier, as soon as sender and recipient have deleted them |
| Successful sign-ins and sign-outs | time, IP address, interface | audit log – only with `audit.log_logins = true` | default: not recorded; if switched on `audit.retention_days_logins` (90 days) |
| Failed sign-ins (block) | time, entered name, IP address | `admin_login_attempt`; for a block, a line in the log `security` | in the table only for the blocking period `admin.bruteforce_delay` (10 minutes), in the log after the task “Clean up logs”; IP address shortened after `privacy.ip_anonymize_days` |
| Notification emails | new device, password changed – with the full IP address | mailbox of the user | not stored in camindo CMS |

User names that begin with `admin_` are reserved for anonymized accounts and cannot be assigned again. The data export of a user is created by whoever has the right to do so in the module “Privacy” (see [exporting](#exporting-personal-data)).

Failed sign-ins always stand in the audit log, after several failed attempts additionally in the log `security`. Both are deleted after `audit.retention_days_logins`; in the data export, each entry shows the day of its deletion.

***Sign-in times:*** Mere sign-ins without activity say nothing about changes, but they do say something about the working hours of your staff. Therefore camindo CMS records successful sign-ins and sign-outs only if you switch on `audit.log_logins`; the default setting is `false`.

***What you as employer should consider:*** The audit log shows who changed what, and when. It is meant for traceability and security, but it can be used for performance and behavior monitoring. Regulate purpose and access (research only for superusers) in a works agreement or an internal policy, and inform your staff.

### Former employees

A user account continues to exist after deletion, but no longer appears in the user list. Sign-in is no longer possible. Purpose: traceability – records, templates and the audit log refer to it.

A superuser can delete the messages of a “deleted” user: open the user → tab “Settings” → ***Internal messages*** → “Delete all messages”. Received messages are deleted permanently; sent ones disappear from their outbox, the recipients keep their version. The process appears in the log `security`. You remove devices and sessions in the tab “Devices and sessions”.

#### Anonymizing deleted users

As soon as this traceability is no longer needed (or the former employee demands the deletion), remove the person with ***Anonymize***: module “Privacy” → tab “Deleted users” → click on the user → on their info page “Anonymize”. The button is visible to superusers and to user groups with the additional right “Anonymize deleted users” (for users of the groups that they see in the module “Users”).

| Data | Anonymized form |
|---|---|
| User name | `admin_{Id}`, for example `admin_42` – everywhere the name appears, also at “created by/changed by” |
| First, display and sort name, email, telephone, mobile, personal settings | deleted |
| Password, authenticator app, passkeys, recovery codes, sessions, devices | deleted – the account can no longer sign in |
| Audit log: entries in which they acted | appear with `admin_{Id}` – the audit log stores acting persons only as a number and shows the current name; the changes themselves remain |
| Audit log of their account | designation `admin_{Id}`, the values of the personal fields in before/after deleted |
| Failed sign-ins under their name | `admin_{Id}` instead of the name |

Because the audit log stores acting persons only as a number, anonymizing takes only a moment even for users with a long history. If it nevertheless aborts, simply start it again – only the name `admin_{Id}` shows that it has been completed.

This cannot be undone. The process appears in the audit log of the account (“Privacy”) and in the log `security`. The list shows anonymized users with the note “anonymized”.

## Website users (community)

| | Data | Where | How long |
|---|---|---|---|
| Personal data encrypted | all fields of the profile that are set up as encrypted fields (usually all with personal data, including user name, email address) | profile area (tables `record`, `data_enc`) | until the profile is deleted, then according to its [deletion policy](#deleting-according-to-deletion-policy) – with the standard policy: `admin.trash_retention_days` (7 days) in the trash, then permanently including files |
| Personal data unencrypted | all fields of the profile that are set up as unencrypted fields, times of creation and last change | profile area (tables `record`, `data_*`), `media/` | as above |
| Password | hash | profile area (tables `record`, `data_pwd`) | as above |
| Contributions | comments, ratings … and uploaded files, times of creation and last change | contribution areas (tables `record`, `data_*`), `media/` | according to the deletion policy of the contribution area; with the standard policies like the profile, as soon as it is deleted |
| Records that refer to the profile | links | respective area | according to your own rules (for example commercial and tax-law periods) |
| Known devices | device, ***shortened*** IP address, time of first and last sign-in | `login_device` | `security.device_days` (180 days) after last use; with the deletion of the profile immediately, regardless of which policy |
| Session data | session ID, last access | `user_session` | `user.max_inactive_sec` (24 hours) of inactivity; with the deletion of the profile immediately |
| Two-factor data | type, time of first use | `user_second_factor` | until removal or deletion of the profile |
| Open confirmation and password links | token, time | `user_token` | until use or expiry (`user.token_expiration`, `user.password_token_expiration`) |
| Registrations never confirmed | details from the registration process, time | profile area | `user.unconfirmed_days` (7 days) |
| Shopping cart | record IDs (products, subscriptions …), time of last change | `user_basket` | signed in: with the session; anonymous: `user.basket_days` (30 days) |
| Change log | audit log of the profile record and contributions, with IP address, time | audit log | according to the deletion policy; with the standard policies for the profile after 7 days all fields emptied, after 1 year deleted. The user appears in it only with the number of their profile ([anonymous](#anonymous-via-the-profile), as soon as the profile is deleted). IP address shortened after `privacy.ip_anonymize_days` |
| Failed sign-ins | sign-in name, time, IP only with `user.bruteforce_ip` | `user_login_attempt` | for the blocking period `user.bruteforce_delay` |

Sign-in names that begin with `user_` are reserved for anonymized profiles: they are rejected at registration and in the backend – only `user_{record_id}` of the profile itself is permitted, as a deletion policy uses it.

The data export of a website user is created by whoever has the right to do so in the module “Privacy” (see [exporting](#exporting-personal-data) and the [manual for editors](../editors/10-visitor-contributions.md#exporting-personal-data)).

## Visitors without an account

camindo CMS sets in the frontend only technically necessary cookies (session, frontend editing, device recognition at sign-in) and does not embed third-party services. What your templates additionally load (fonts, maps, statistics) is your responsibility.

| | Data | Where | How long |
|---|---|---|---|
| Throttling<br>(forms,<br>sign-in,<br>CSP reports) | IP addresses only as a ***hash***, never in plain text | table of the RateLimiter | a few minutes to hours (time window of the throttling) |
| Blocked sign-ins,<br>rejected forms | sign-in name, IP address, time | log `security` in `var/log/` | task “Clean up logs” (default 365 days); IP addresses shortened after `privacy.ip_anonymize_days` |
| Script violations (CSP reports) | script URL, time, (no IP address) | log `csp` | as above |
| Form entries | partly personal data | depending on the template: as record or email, depending on the area encrypted / unencrypted | according to the deletion rules of the area, or of the mailbox |

## IP addresses

IP addresses are personal data. The legitimate interest in the ***complete*** address – recognizing and warding off attacks – according to widespread opinion lasts only a few days. camindo CMS therefore handles it as follows:

| | Where | What is stored |
|---|---|---|
| Known devices | `login_device` | from the start only the shortened address |
| Contents of the audit log | `var/audit/` | from the start only the shortened address – the files are protected against forgery and are only appended to; shortening them afterwards would not work |
| Metadata of the audit log | `audit_log` | the full address, shortened after `privacy.ip_anonymize_days` (7 days) |
| Failed sign-ins | `admin_login_attempt`, `user_login_attempt` | the full address for the blocking period; shortened after the same period, if they stand longer |
| Log files | `var/log/` | the full address; every log file whose day is older than the period is shortened |
| Throttling | RateLimiter table | only a hash, never the address |

Which address is “the visitor’s address” is determined at all points by the same rule: `REMOTE_ADDR`, behind a registered proxy (`security.trusted_proxies`) the address from `X-Forwarded-For`. Without this entry, behind a proxy everywhere stands the address of the proxy.

***How shortening works:***

- ***IPv4:*** the last octet is zeroed (`203.0.113.45` → `203.0.113.0`); with `privacy.ip_v4_octets = 2` the last two (`203.0.0.0`).
- ***IPv6:*** the last 80 bits are zeroed; the first 48 bits remain – the network of the provider, not the individual connection (`2001:db8:abcd:12::1` → `2001:db8:abcd::`).

***Changing the setting later:*** If you raise `privacy.ip_v4_octets` from 1 to 2, the next run of the task “Apply retention periods” shortens all addresses that are already shortened further – in the database, the log files and the contents of the audit log (`203.0.113.0` → `203.0.0.0`, displayed as `203.0.xxx.xxx`). Lowering does not bring anything back: what has been zeroed remains so; the display continues to mark both digits.

***Displayed*** are the shortened digits as `xxx` – in the data disclosure, in the research and in the tab “Changes” of the audit log and in the device list (`203.0.113.xxx`, `2001:db8:abcd:xxxx:xxxx:xxxx:xxxx:xxxx`). In this way nobody takes the zero for part of the address. Stored remains the address with zeros: only in this way is it still a valid IP address that can be compared and – after a change of `privacy.ip_v4_octets` – shortened further.

The shortening is handled by the scheduler task ***“Apply retention periods”*** (set up by the installation, every night). Each run takes only the entries since the previous run and uses the indexes of the time columns. Log files are rewritten line by line; this keeps it fast even with large data holdings – under Linux as well as under Windows. The first run after the update passes once over the entire holdings, as does the first run after a change of `privacy.ip_v4_octets`.

***Once after the update:*** Entries in `var/audit/` that were written before this update still contain the full address. Shorten them, ideally in maintenance mode, with

```
php Core/cron/camindo.php audit anonymize-ip --dry-run   # only count
php Core/cron/camindo.php audit anonymize-ip
```

The command writes the files with a newly calculated checksum chain and notes this as a control point “anonymize”. Files whose chain was already broken beforehand, it leaves unchanged and names them; check them with `audit verify` (see [chapter 14](./14-security-and-compliance.md#accountability-the-audit-log)).

***In the case of a concrete attack:*** If the full addresses are urgently needed for the further pursuit of an attack that has already been detected, temporarily raise `privacy.ip_anonymize_days`. This only stops the shortening – what has already been shortened does not come back. Secure the necessary entries (for example an export from the audit log, a copy of the log file) at a protected place, document the reason and duration, and set the period back afterwards.

The 7-day period is a default, not legal advice. Coordinate it with your data protection officer.

## Deleting according to deletion policy

What “delete” means for a record is determined by the ***deletion policy*** of its content area. Every content area (records, profiles, contributions) has exactly one; you select it in the settings of the area (see [chapter 4](./04-structure.md)) and manage it in the module “Privacy” ![shield](../_/shield.svg) → “Deletion policies”.

The list shows for each policy the ***instance***, the ***label*** and the ***categories*** of its blocks. A click opens the ***info page*** (with the areas that use the policy, and the tab “Changes”), a right-click the context menu with “Info”, “Edit” and “Delete”.

### Structure of a policy

A policy has a ***label*** (text or snippet), applies to an instance (or to all) and consists of any number of ***blocks***. Each block states: at which ***trigger***, at which ***time***, which ***action***. For this you choose a ***category***, which classifies the action – it serves only for overview and has no effect:

| Category | Typical for |
|---|---|
| Move to the trash | the trash |
| Remove data no longer strictly needed | overwriting individual fields (data minimization) |
| Fully anonymize entry | overwriting fields in the audit log |
| Delete permanently | permanent deletion, with the audit log; permanent deletion of the audit log |

| Trigger | When |
|---|---|
| Record is deleted | regardless of the way: deletion dialog of the input form, batch processing, API `record.delete` or [CMS:USER_DELETE](../templateprogrammers/user-delete.md) in the frontend – also for the [sub-records](#sub-records) that are deleted with it |
| UGC: profile was deleted | a profile was deleted – individually, by batch processing or with its whole profile area. This ***always and immediately*** triggers the policies of all records of this user (contributions, ratings …), each the policy of its own area. If it has no block with this trigger, the records remain. This cannot be undone: if the profile (or its area) is restored, you must retrieve contributions in the trash by hand |
| Area was deleted | the whole content area was deleted; its records then already lie in the trash together with it. Therefore not for “Move to the trash” and “Overwrite individual fields”. Blocks that are due immediately also run only with the scheduler task “Apply deletion policies”; if the area is restored, they lapse |

***Time:*** immediately (0) or after a number of days or years, counted from the triggering.

The actions are grouped in the selection by what they concern:

| Group | Action | What happens |
|---|---|---|
| Record | Move to the trash (permanently deleted after … days) | the record goes into the trash of the person deleting, and is permanently deleted after `admin.trash_retention_days` (default 7 days) – including media files. The selection names the set period |
| Record | Overwrite individual fields | fields of the record receive new values, for example `status_id = "4"`, `username = "user_{record_id}"`, `account_number = ""`. Multilingual fields receive the value in all languages. An upload field accepts only `""`: the file is removed from the server immediately, together with its thumbnails (also for multilingual uploads in all languages) |
| Record | Apply trigger template to the record | the method `onPrivacyAction()` in the [trigger template](../templateprogrammers/03-trigger-templates.md#onprivacyaction-deletion-policies) of the area receives the record – multilingual fields per language – and can change it; changes are saved. For everything that “Overwrite individual fields” cannot express: linked records, multilingual texts per language, own rules |
| Audit log | Overwrite fields in the audit log | in the history of the record (database and `var/audit/`), before and after of the named fields are replaced, for multilingual fields in all languages. Additionally: `_label` (designation of the entry) and `*` (all other fields – regardless of where the line stands). Later entries as well – for example the last state at permanent deletion – are written this way |
| Audit log | Apply trigger template to the audit log | `onPrivacyAction()` in the trigger template receives each entry in the history of the record and can change the designation and the values before/after; changes are written back |
| Audit log | Delete audit log permanently | the entire history of the record |
| All | Delete permanently, including audit log | without trash: the record, its sub-records, their files and their entire history in the audit log are gone |

You write the fields per line as `field = "value"`. Placeholders in the value: `{record_id}` (the record) and `{user_id}` (the user to whom the record belongs). A field that does not exist in the area is skipped; a thumbnail field alone cannot be emptied – it disappears with its upload field.

The two trigger actions require an ***identifier*** (lowercase letters, digits, `_`), which the template receives as `$action` – so it distinguishes several blocks. If the area has several trigger templates, `onPrivacyAction()` runs in each one that contains the method, one after another. If none has the method, the job fails and appears in the log `privacy`.
camindo CMS does not check whether blocks contradict each other or duplicate each other – that is the responsibility of the person who sets up the policy.

Regardless of any policy, when a ***profile*** is deleted – also with its area –, its known devices and sessions are deleted immediately, and media files always disappear with the permanent deletion of a record.

### Sub-records

Deleting a record also deletes its ***sub-records***: the ***children*** in the tree, if you delete “with sub-records” (otherwise they move up one level and remain untouched), and always the ***firmly linked contents*** (fields of the type “link”), also those of the children. Each sub-record receives the blocks of the policy of ***its own*** area for “Record is deleted” – with one exception: “Move to the trash” and “Delete permanently, including audit log” are handled for everyone by the main record. A sub-record for which a deletion is already running keeps its jobs.

### Anonymous via the profile

The audit log stores who acted only as a ***number*** – for a website user the number of their profile record, never their name. This link ***always*** remains; no action removes it. Entries are anonymized via the ***profile***: if it is permanently deleted, or if its personal fields have been emptied with “Overwrite individual fields”, the number leads to nobody – also not in the entries on contributions and other records on which the user worked. What is personal in the entries themselves (values before/after, designation) you overwrite with “Overwrite fields in the audit log”.

### The standard policies

During installation, three policies arise for all instances. You can change them – as policies for all instances only by a superuser –, but not delete them; a new area receives the one of its type pre-assigned.

| Policy | Area type | Blocks |
|---|---|---|
| ***Delete record*** | records | immediately into the trash (permanently after 7 days), also when the profile of the user is deleted; delete the audit log permanently after 1 year |
| ***Delete profile*** | profiles | immediately into the trash (permanently after 7 days); in the audit log after 7 days replace `_label` with `user_{record_id}` and empty all other fields; delete the audit log permanently after 1 year |
| ***Delete user-generated content*** | contributions | immediately into the trash (permanently after 7 days), also when the profile of the user is deleted; delete the audit log permanently after 1 year |

The blocks in the audit log apply in all three standard policies also to the trigger “Area was deleted”.

With the standard policies, a deleted website user is anonymous after 7 days: the profile is permanently deleted, its own history emptied, and the entries on its contributions name only a number that no longer belongs to any profile.

### Example: anonymizing a profile instead of deleting it

If a profile (for example because of orders) is to remain, but no longer reveal any person:

| Trigger | Time | Action | Fields |
|---|---|---|---|
| Record is deleted | immediately | Overwrite individual fields | `status_id = "4"` |
| Record is deleted | 7 days | Overwrite individual fields | `username = "user_{record_id}"`, `name = ""`, `account_number = ""` |
| Record is deleted | 7 days | Overwrite fields in the audit log | `_label = "user_{record_id}"`, `* = ""` |
| Record is deleted | 1 year | Delete permanently, including audit log | |

The contributions of the user are deleted in this process according to the policy of their area with the trigger “UGC: profile was deleted”. If they are to remain, remove this trigger from their policy.

### Note in the deletion dialog

Whoever deletes a record in the backend sees beforehand what the policy does with the ***database*** – immediately and after periods, for a profile also with its contributions:

> The profile record remains, but is adjusted as follows:
> – Status: “Deleted”
>
> After 7 days:
> – User name: “user_{record_id}” · – Account number: “”
>
> After 1 year: – Permanent deletion
>
> 72 contributions of the user (“Comments”) remain.

Batch processing shows the same per area. After the deletion, the message is, depending on the policy, “deleted”, “adjusted” or “scheduled”.

### How it is carried out

Each block becomes, when deleting, a ***job*** (table `privacy_job`) with a due date; the immediately due ones run at once, the others with the scheduler task ***“Apply deletion policies”*** (default: every night; for periods in hours, schedule it more often). A job takes over the values of its block – a later change to the policy affects only future deletions. If a job fails (for example because someone is just editing the record), there are up to 20 attempts; everything appears in the log `privacy`. Actions of a policy count as system actions: mandatory fields and group rights are not checked, and in the audit log the change appears as “adjusted by deletion policy” without the previous values.

If a record is ***restored*** from the trash, its still open jobs lapse. A record for which a deletion is already running cannot be deleted again.

When an entire ***area*** is deleted, its records go into the trash together with the area, and camindo CMS schedules for each the blocks of the area policy with the trigger “Area was deleted” – with the standard policies: delete the audit log permanently after 1 year. If the policy has no block for this trigger, the history remains. Records for which there were never any jobs (for example deleted before the introduction of the deletion policies) receive at permanent deletion at least the audit log blocks of this trigger, with the periods counted from the deletion. The history of permanently deleted records in the audit log follows only the policies, not `audit.retention_days_deleted` (which continues to apply to areas, fields, templates and other objects).

### Emptying a website user’s trash immediately

If a website user requests immediate deletion, a user with the right ***“Permanently empty a website user’s trash”*** (module “Tools”) empties their trash: “Tools” → “Trash of a user”. You select the profile via the search across all profile areas that you may read or – if it is itself already deleted – from the list of profiles in the trash. Profile and contributions that lie in the trash are permanently deleted at once. Users empty their own trash as before, themselves.

### Deleting the audit log manually

The permanent deletion – also via “Tools” → “Database” → “Empty trash” – leaves the history of a record in the audit log; the audit log blocks of the deletion policy remove it after their period. If a history must disappear earlier – for example because a website user insists on it or test data were involved –, delete it on the command line:

```bash
php Core/cron/camindo.php audit delete record 4237 --dry-run   # only count
php Core/cron/camindo.php audit delete record 4237
php Core/cron/camindo.php audit delete --area=141 --force      # all records of an area
```

The entire history is deleted: the entries of the database, the files under `var/audit/` and the jobs of the deletion policy for this record. Open jobs remain as long as the record exists – otherwise it would never be fully deleted; later they also remove the entry of the permanent deletion. `--area` covers every record that lies in the area or, according to the audit log, lay there, including deleted ones; without `--force` the command only names the number. Instead of `record`, any other object type of the audit log works, for example `admin 34` for the history of a user account. The process is recorded in the log `security` with the count, but without contents. Entries in which a website user acted on other records are not covered by `audit delete`; they name them only with the number of their profile (see [anonymous via the profile](#anonymous-via-the-profile)).

## Exporting personal data

For a request for disclosure (Art. 15 GDPR) or for data portability (Art. 20), camindo CMS creates an ***export***: a zip file with `auskunft.html` for reading and `daten.json` machine-readable; for website users additionally `dateien/` with their uploaded files. It is a ***partial extract*** – before passing it on to the data subject, you complete it with everything that camindo CMS does not know: legal bases, server log files outside the CMS, data in accounting, email and other systems.

The export is created by whoever has the additional right ***“Export personal data”*** in the module “Privacy” (superusers always), under “Privacy” → “Data export”:

| Person | Who may | How |
|---|---|---|
| ***Website user*** | whoever may read the profile area | search for the profile (all readable profile areas) or choose from the profiles in the trash – or on the profile record in the menu ⋮ “Export personal data” |
| ***User*** | users of the groups whose users the group may see in the module “Users” (own group with “Read user details: own user group”, other groups with “Read user details: other user groups”) | choose the user from the list |

Users cannot download their export themselves.

***Before creating***, camindo CMS checks the [templates](#the-templates-for-data-disclosure):

- If there is ***no template for website users*** for the instance of the profile – neither for its profile area nor for all areas – (for a user: neither for their group nor for all groups), a notice appears and no button for creating – with the right to do so, you create one immediately via ***“Create template”***.
- If there is ***exactly one***, it is used.
- If there are templates in ***several languages***, you choose the ***language of the disclosure***. For users you choose among the user templates of all your instances.

Below this, a warning notice appears: a data disclosure may only be created by an authorized person – as a rule the data protection officer. And: every data disclosure is logged; in every later disclosure, the data subject learns that one was created about them today. Only when you tick ***“I am authorized to create the data disclosure.”*** does the export come into being with ***“Create data disclosure”***.

The export of a ***website user*** contains:

- all fields of the profile in all languages (encrypted ones in plain text, passwords only as a note),
- their ***contributions, sorted by area***, including files – contributions in the trash are marked as “deleted, still in the trash”,
- ***permanently deleted contributions*** that the audit log still links to them, and what the deletion policy still plans for them (for example “delete audit log permanently: 2027-10-08”),
- records related to the profile (for example orders),
- known devices and sessions – ***directly below them*** their storage period –, second factor, open links, shopping cart,
- ***failed sign-in attempts*** under their sign-in name or their account, each with the day of deletion,
- ***entries in log files*** that clearly belong to them: blocks after too many failed attempts (log `security`) and lines of the mail log with their email address, for example emails that could not be delivered,
- the change log, the storage periods and the deletion policy of their profile area.

***Names of other persons*** do not appear in the disclosure of a website user: who acted appears only as ***“You yourself”***, ***“Admin”***, ***“User”*** (another website user) or “System”. ***Shortened IP addresses*** appear with `xxx` instead of the zeroed digits (`203.0.113.xxx`), so that nobody takes the zero for part of the address.

The export of a ***user*** contains: account data (contact data in plain text, of the password only the day of the last change), devices and sessions with their storage period, second factor (without keys or codes), ***sign-ins*** (only with `audit.log_logins`) and failed sign-ins under their name – each with the day of their deletion –, their activities in the audit log with IP address (the latest 1000), changes to their account, internal messages to them (date, sender, subject), the number of records with their name and the storage periods.

Every export is recorded in the audit log. The zip file lies in `var/export/` until it is downloaded, is deleted during the download, and is removed after `admin.export_retention_hours` (24 hours) at the latest.

### The templates for data disclosure

What `disclosure.html` looks like and which text surrounds the data is determined by an HTML template that you design yourself – for example with your logo and the section “Storage period” of your privacy statement. There are separate templates for ***website users*** and for ***users***, each ***per instance and per language*** – and, if you wish, ***per profile area*** or ***per user group***, for example:

| Type | Instance | Applies to | Language |
|---|---|---|---|
| Website user | Shop | Customers | English |
| Website user | Shop | Community | English |
| Website user | Shop | All profile areas | German |
| User | Shop | Editorial team | English |
| User | Shop | All user groups | English |

On export, the ***specific template wins***: for a profile in the area “Customers”, the templates for “Customers” apply; if there are none, the ones for all profile areas. For a user, the templates for their user group apply (from all your instances), otherwise those for all groups. You maintain the templates under “Privacy” → “Templates”; whoever has the additional right ***“Maintain data disclosure templates”*** and may edit the instance (superusers always) may create, change and delete them.

With ***“New”*** you first choose ***type (website user or user) and instance***; in the form then ***“Applies to”*** (profile area or user group or all) and the ***language***. A second template for the same combination cannot be saved. The new template is pre-filled with the template of the core for this type (`Core/install/privacy/privacy_user_en_US.html` or `privacy_admin_en_US.html`, in English; they list all placeholders at the beginning). Without a template for website users, there is no data export for the website users of an instance; for a user you choose among the user templates of all your instances.

The disclosure is created in the ***language of the template***: snippets and the labels of the tables appear in this language, regardless of your backend language. Because types, profile areas and user groups have separate templates, no distinction is needed within a template.

| Notation | Effect |
|---|---|
| `{name}` | placeholder – values are inserted masked, blocks as finished HTML tables |
| `<!-- if:name -->…<!-- endif:name -->` | section only if the condition applies; can be nested |
| `{#module.key}` | language snippet in the language of the template, for example `{#record.gdpr_col_device}` |
| `<!-- comment -->` | explanation for you – dropped in the export |

***Conditions:*** `audit`, `audit_changes_limited` / `audit_changes_unlimited`, `audit_deleted_limited`, `logcleanup` / `no_logcleanup` (task “Clean up logs” set up), `dbbackup` / `no_dbbackup` (task “Database backup”), `messages_limited`, `ip_v4_one` / `ip_v4_two`.

***Periods*** (in both types): `{trash_days}`, `{device_days}`, `{ip_days}`, `{ip_v4_octets}`, `{message_days}`, `{audit_changes_days}`, `{audit_deleted_days}`, `{session_hours}` (website users), `{session_minutes}` (users), `{basket_days}`, `{unconfirmed_days}`, `{export_hours}`, `{backups}` (retained database backups), `{log_days}`, `{security_days}`, `{attempt_days}` (from the task “Clean up logs”). In this way the text always matches the settings – if you change a period, the export changes with it.

***Header:*** `{subject}`, `{subject_id}`, `{domain}` (main domain of the instance), `{instance}` (designation of the instance), `{created}`, `{lang}`, `{dir}`.

***Data in the template for website users:*** `{profile}`, `{contents}` (sorted by area), `{purged}` (permanently deleted contributions that the audit log still knows), `{references}`, `{devices}`, `{sessions}` (each with the storage period below), `{second_factor}`, `{tokens}`, `{basket}`, `{login_attempts}`, `{log_lines}`, `{files}`, `{audit}`, `{retention}` (the periods as a finished list), `{delete_policy}` (the deletion policy of the profile area, one sentence per block).

***Data in the template for users:*** `{account}`, `{devices}`, `{sessions}`, `{second_factor}`, `{logins}`, `{activity}`, `{activity_note}`, `{account_changes}`, `{messages}`, `{content_counts}`, `{retention}`.

If you leave out a data block, it is missing from the export – but the disclosure must be complete under Art. 15 GDPR. Therefore check a changed template with an export for a test account. `daten.json` always contains all data, regardless of the template.

## What lies outside camindo CMS

camindo CMS can shorten and delete only what it stores itself. On your list there are also:

- ***Access logs of the web server*** (Apache, nginx, IIS) – they contain the full IP address of every call. Shorten them in the web server (for example with an anonymizing log format) or delete them after a few days via log rotation.
- ***PHP and database logs*** outside `var/log/`.
- ***Database backups*** and server backups – they contain the state at the time of the backup, therefore also the unshortened addresses of the last days.
- ***Emails*** that camindo CMS has sent (notes about new devices, forms).
- ***Proxies, load balancers, CDNs and firewalls*** in front of the website.

## Checklist

- [ ] periods (`privacy.ip_anonymize_days`, `security.device_days`, `audit.*`, `admin.trash_retention_days`, task “Clean up logs”) coordinated with the data protection officer and entered in the record of processing activities
- [ ] scheduler task ***“Clean up logs”*** set up
- [ ] `audit anonymize-ip` run once after the update
- [ ] deletion policies checked: each content area has the appropriate one; data that must be retained (orders, invoices) not in the trash, but anonymized
- [ ] templates of the data disclosure created (“Privacy” → “Templates”): for website users per instance with community (if needed per profile area), for users at least one (if needed per user group) – adjusted and checked with a test account
- [ ] rights granted: “Export personal data”, “Create and change deletion policies”, “Maintain data disclosure templates” (module “Privacy”), “Permanently empty a website user’s trash” (module “Tools”)
- [ ] decided whether successful sign-ins are recorded (`audit.log_logins`, default no) and how long sign-ins remain (`audit.retention_days_logins`)
- [ ] period of the internal messages (`privacy.message_days`) determined; for former employees, messages deleted
- [ ] access logs of the web server shortened or deleted at short notice
- [ ] behind a proxy: `security.trusted_proxies` set – otherwise everywhere stands the address of the proxy
- [ ] staff informed about the audit log, works agreement or policy in place
- [ ] privacy statement of the website names cookies, forms, community features
- [ ] process for requests for disclosure and erasure defined (who, deadline one month)
