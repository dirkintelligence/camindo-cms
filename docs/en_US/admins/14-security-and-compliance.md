# 14 Security, data protection and operation – for decision-makers

This chapter is aimed at everyone who decides on the use of camindo CMS or is responsible for it: IT management, data protection officers, information security and project managers. It describes **what** the system provides and ***which organizational tasks*** remain with the operator. The technical details are in the linked chapters.

- [Security at a glance](#security-at-a-glance)
- [Data backup and recovery](#data-backup-and-recovery)
- [Updates and security updates](#updates-and-security-updates)
- [Reporting security vulnerabilities](#reporting-security-vulnerabilities)
- [Data protection (GDPR)](#data-protection-gdpr)
- [Accountability: the audit log](#accountability-the-audit-log)
- [Operational monitoring](#operational-monitoring)
- [Tasks of the operator](#tasks-of-the-operator)

## Security at a glance

| Area | What camindo CMS provides | More about it |
|---|---|---|
| Sign-in to the backend | password policy (default: at least 12 characters), storage with Argon2id, two-factor sign-in with authenticator app (can be made mandatory for superusers) or email code, block after repeated failed attempts, note by email when signing in from a new device, automatic sign-out after inactivity | [chapter 12](./12-operation.md#passwords-and-sign-in-devices) |
| Rights | user groups with rights per module, website (instance), language and content area; records with owner; superuser modules (log files, audit log) for no one else | [chapter 7](./07-users-and-rights.md) |
| Protection against manipulation | rights and user accounts are signed; if someone changes them directly in the database, they do not take effect | [chapter 12](./12-operation.md#logs) |
| Sign-in to the website | password policy, block after failed attempts per account and per visitor, second factor per profile area (authenticator app or code by email, also only on new devices), throttling and form protection for “Forgot password” and registration | [chapter 13](./13-community.md#second-factor) |
| Attacks via the browser | protection against cross-site request forgery (token) for backend and frontend forms; script policy (Content Security Policy) in the backend, in the frontend switchable per website; cleaning of HTML in contents; security headers | [chapter 3](./03-instances-and-languages.md#script-policy), [chapter 12](./12-operation.md#security-headers) |
| Encryption | fields of the type “encrypted” are stored encrypted in the database (AES-256-GCM), likewise email address and telephone numbers of the users. The key is kept outside the database (`.camindo.key`) | [chapter 5](./05-input-fields.md) |
| Files | protected files (“Only for signed-in frontend users”) are blocked on the server and are delivered only after the session has been checked; image processing with a restricted ImageMagick policy | [chapter 8](./08-media.md#storage-and-delivery) |
| Traceability | audit log of all changes with before/after | [below](#accountability-the-audit-log) |

***Limits you should know:***

- The ***two-factor sign-in by email code*** is only as secure as the email mailbox of the users. The authenticator app is stronger; for superusers it can be made mandatory (`security.admin_2fa = 'totp_required'`, [chapter 12](./12-operation.md#two-factor-sign-in)).
- The ***domain lock for media*** prevents files from being embedded via foreign websites. It is not access protection. Confidential files are protected only by the field option “Only for signed-in frontend users”. Clients who must not reach each other’s files need ***separate installations*** ([chapter 8](./08-media.md#storage-and-delivery)).
- Superusers have unrestricted access. Assign this role sparingly and only to named individuals, and never share access credentials.

## Data backup and recovery

### What must be backed up

| Component | Location | Frequency (recommendation) |
|---|---|---|
| Database | per scheduler to `var/dbbackup/` | daily |
| Media files | `public/media/` | daily (incremental) |
| Project data | `var/templates/`, `var/custom/`, `var/extensions/`, `var/modules/`, `var/deleted/` | daily |
| Audit log (contents) | `var/audit/` – belongs to the database backup of the same point in time | daily |
| Configuration | `.camindo.php`, `composer.json`, `composer.lock` | with every change |
| ***Key file*** | `.camindo.key` | once, **separately** from all other backups |

`var/cache/`, `var/php/`, `var/tmp/`, `var/export/` and `vendor/` need not be backed up – they are created anew.

Without the key file, encrypted contents and all signatures are unusable after a restoration. Whoever captures the key file **together** with a database backup can read the encrypted fields. Therefore keep it separately, for example in the IT password vault.

### How to back up

camindo CMS creates the ***database backup*** itself: a task “Database backup” in the [scheduler](./10-modules.md#scheduler) writes a complete dump to `var/dbbackup/` every day and keeps the set number. In addition, camindo CMS backs up the database automatically before every update.

These backups lie on ***the same server***. They protect against operating errors and failed updates, not against the loss of the server. For operation, the **3-2-1 rule** applies: three copies, on two different media, one of them outside the data center. This is handled by the backup of your host or your IT, which regularly fetches `var/dbbackup/` and the other directories from the server.

Every backup is checked after writing: if it is smaller than 10 KB (then it usually contains only an error message) or lacks the final line with which every complete dump ends, it is discarded and the run counts as failed. If a backup fails, superusers see this on the start page ([operating notices](./12-operation.md#operating-notices-on-the-start-page)): no backup task, failed run, backup older than two days or unusable.

### Recovery

***Individual contents*** editors recover themselves from the [trash](./10-modules.md#trash) within the retention period (`admin.trash_retention_days`, default 7 days) – including files. How a record looked earlier is shown by the tab “Changes” (audit log); from it, individual values can be written back by hand.

***Whole installation*** (for example after a server failure):

1. Provide the server, install the code with `composer install` from the backed-up `composer.lock`.
2. Restore `.camindo.php` and `.camindo.key`.
3. Switch on maintenance mode: `php Core/cron/camindo.php maintenance on`.
4. Create the database and import the backup, for example `mysql camindo < var/dbbackup/backup-1.sql`.
5. Restore `public/media/` and the project directories under `var/`.
6. `php Core/cron/camindo.php upgrade` – reconciles components and rebuilds all caches.
7. Set up the cron jobs ([chapter 1](./01-quick-start-installation.md)), switch maintenance mode off, check the start page of the backend for operating notices.

***Individual areas*** can additionally be backed up and restored selectively via [XML export](./09-structure-export-import.md).

### Practicing recovery

A backup whose recovery has never been practiced is a promise. Determine

- how much data loss is acceptable at most (recovery point objective – with a daily backup up to 24 hours) and
- how long the website may be down at most (recovery time objective),

and restore a backup on a test system according to the instructions above at least once a year.

## Updates and security updates

### Versions

camindo CMS follows semantic versioning `MAJOR.MINOR.PATCH`:

| Level | Meaning | Effort |
|---|---|---|
| ***PATCH*** (9.0.**1**) | only bug fixes, among them security corrections; no change to the database | harmless, install promptly |
| ***MINOR*** (9.**1**.0) | new functions; existing things continue to work; may extend the database | install after a short check |
| ***MAJOR*** (**10**.0.0) | changes that may require add-on components or templates to be adapted | plan, check on a test system |

Security corrections appear as a ***patch version*** for the current major version. The release notes of each version state whether it contains security-relevant corrections.

### Installing updates

```bash
php Core/cron/camindo.php maintenance on
composer update
php Core/cron/camindo.php upgrade
php Core/cron/camindo.php maintenance off
```

`upgrade` backs up the database automatically beforehand. The way back is described in [chapter 12](./12-operation.md#updates).

### Organizational

- Determine who follows release notes and who installs updates (including deputies).
- Install security corrections within a few days, other updates in the regular maintenance window.
- Server software (PHP, database, web server, ImageMagick, ffmpeg) belongs to this as well. It is updated via the operating system or the host, not via camindo CMS.
- Add-on components and Composer packages come along with `composer update`. Whether there are newer versions and whether security notices exist for installed packages, camindo CMS checks itself daily (`composer outdated` / `composer audit`); the result appears under “Info” in the main menu and as an operating notice on the start page ([chapter 12](./12-operation.md#update-check)).

## Reporting security vulnerabilities

### Vulnerabilities in camindo CMS

The procedure – reporting route, deadlines, supported versions – is described in the file `Core/SECURITY.md`. In short: please report security vulnerabilities in camindo CMS **confidentially** to [camindo@camindo.de](mailto:camindo@camindo.de), not via public channels such as forums or issue trackers. Describe which version is affected and how the vulnerability can be reproduced.

### A reporting route for your website: security.txt

For the websites that you run with camindo CMS, you are yourself the point of contact. The standard [RFC 9116](https://www.rfc-editor.org/rfc/rfc9116) provides for this a file `/.well-known/security.txt`. Create it under `public/.well-known/security.txt`, for example like this:

```
Contact: mailto:security@your-company.example
Expires: 2027-09-30T23:00:00.000Z
Preferred-Languages: en, de
Policy: https://www.your-company.example/security
```

- `Contact` – an address that is read regularly (not a personal mailbox).
- `Expires` – at most one year in the future; extend it in good time.
- `Policy` – optional: how you handle reports, whether you name the persons reporting them.

As long as the file is missing, the start page of the backend reminds superusers of it.

## Data protection (GDPR)

### What personal data camindo CMS stores

The complete list – what, where and how long – is in [chapter 15 – Data protection](./15-privacy.md); here is the overview.

| Persons | Data | Where |
|---|---|---|
| Users | name, sign-in name, email, telephone (encrypted), password (as hash), personal settings, sessions, known devices with shortened IP address, activities (and, if switched on, sign-ins) in the audit log | tables `admin`, `admin_session`, `login_device`, `audit_log` |
| Website users (community) | all fields of the profile area that you define; their contributions; sessions, devices with shortened IP address, shopping cart | profile and contribution areas, `user_session`, `login_device`, `user_basket` |
| Persons in contents | what editors enter in content areas (for example contact persons, teams) | content areas |
| All visitors | IP address with failed sign-ins; technical logs | `admin_login_attempt`, `user_login_attempt`, `var/log/` |

camindo CMS sets in the frontend only technically necessary cookies (session, frontend editing, device recognition at sign-in) and does not embed third-party services. What your templates additionally load (fonts, maps, statistics) is your responsibility.

### Retention periods

| Data | Period | Setting |
|---|---|---|
| Deleted contents in the trash | 7 days, then permanently including files | `admin.trash_retention_days` |
| Deleted records, profiles and contributions | as the deletion policy of the area determines (default: trash, after 7 days permanently; for a profile after 7 days all fields in the audit log emptied; after 1 year history deleted) | module “Privacy” ([chapter 15](./15-privacy.md#deleting-according-to-deletion-policy)) |
| Audit log on existing contents | unlimited (traceable back to creation) | `audit.retention_days_changes` |
| Audit log on permanently deleted objects (except records) | 365 days after deletion | `audit.retention_days_deleted` |
| Known devices | 180 days after last use | `security.device_days` |
| Internal messages between users | 60 days after sending | `privacy.message_days` |
| Full IP addresses (audit log, sign-in attempts, log files) | 7 days, then shortened; devices and audit files store them shortened from the start | `privacy.ip_anonymize_days`, `privacy.ip_v4_octets` ([chapter 15](./15-privacy.md#ip-addresses)) |
| Sessions of website users | 24 hours of inactivity | `user.max_inactive_sec` |
| Anonymous shopping carts | 30 days | `user.basket_days` |
| Log files, security logs, failed sign-ins | according to the setting of the task “Clean up logs” (default 90 / 365 / 30 days) | [scheduler](./10-modules.md#scheduler) |
| Database backups | according to the set number | scheduler, task “Database backup” |

Align these periods with your record of processing activities. Keep in mind that deleted data lives on in ***database backups*** until the backup itself expires. This is permissible, but belongs in your deletion concept.

### Rights of data subjects

| Right | Implementation in camindo CMS |
|---|---|
| ***Access*** (Art. 15) and ***data portability*** (Art. 20) | “Export of personal data” in the module “Privacy” or at the profile record: a zip file with readable information, machine-readable JSON and the files, including contributions, devices, sessions, change log and retention periods. A partial extract that you complete with data outside the CMS ([chapter 15](./15-privacy.md#exporting-personal-data)) |
| ***Rectification*** (Art. 16) | editing the profile in the backend or by the frontend user themselves, if the template offers it |
| ***Erasure*** (Art. 17) | delete the profile – what then happens to profile, contributions, files and audit log is determined by the deletion policy; the trash of a user can be emptied immediately (module “Tools”). The deletion route for frontend users themselves is offered by `CMS:USER_DELETE` |
| ***Restriction*** (Art. 18) | set the status of the profile to “Disabled”: sign-in is blocked, the data remain |

The same applies analogously to users: their data are in the user management; deleted users remain as records so that the statement “created by” and “changed by” in contents and in the audit log remains valid. Their ***data export*** is created by whoever has the right to do so in the module “Privacy” ([chapter 15](./15-privacy.md#exporting-personal-data)).

### Technical and organizational measures

For your documentation of technical and organizational measures (Art. 32) you can draw on the overview [Security at a glance](#security-at-a-glance). The operator remains responsible for: hosting with a data processing agreement, HTTPS, access control to the server, backup outside the server, update process, role and rights concept.

## Accountability: the audit log

The [audit log](./10-modules.md#audit-log) answers the question “Who changed what, and when?” – for contents as well as for structure, templates, users and rights:

- Every change with time, acting person (user, website user or system), origin (backend, frontend, API, AI assistant, import, batch processing, scheduler) and IP address.
- Before and after of **all** changed fields, with texts compared paragraph by paragraph.
- Failed sign-ins and data disclosures; successful sign-ins and sign-outs only if you switch them on (`audit.log_logins`).

Entries cannot be changed or deleted via the interface; they disappear only according to the set periods.

***Storage and protection against forgery:*** The database contains only who did what, when, to which object, and which fields were affected. The contents – before and after – are in the file system under `var/audit/`, one file per object, to which only appending takes place (above 1 MB a further page begins). Every line carries a checksum that includes the previous one (***HMAC chain***): if a line is changed, removed or inserted, this is noticed – only someone with the key `.camindo.key` can forge the chain. Whoever has access only to the database can neither read nor change the contents; deleted, added or altered entries of the database are recognized by the comparison with the files – even if only the time, action, actor or designation has been rewritten. The check is performed every night by the scheduler task “Check audit log” (operating notice on the start page in case of findings), in the detail view of each entry, and at any time with `php Core/cron/camindo.php audit verify`. Its result replaces that of the nightly check; after `audit reseal` or the check of an individual object, the notice on the start page for this object is updated.

***Restoring a database backup:*** The files under `var/audit/` are not part of the database dump. After the restoration they contain entries that no longer exist in the database, and the database assigns their numbers anew. Therefore run immediately afterwards – before anyone works again – `php Core/cron/camindo.php audit restore` (with `--dry-run` only count). The command removes all entries that are newer than the state of the database, records this in the file as a control point, and first stores the affected files unchanged under `var/audit-restore-{time}/`.

***Re-chaining a broken chain:*** If `audit verify` reports only “chain” for an object – the entries themselves are genuine, only their chaining is wrong, for example because a file was replaced or restored from a backup –, check the process and then re-chain:
`php Core/cron/camindo.php audit reseal template 433 --reason="…"` (with `--dry-run` only display). The entries up to the break remain unchanged, the rest is re-chained behind a noted control point; the old files then lie under `var/audit-reseal-{time}/`. If an entry itself has been altered (“hmac”), the command refuses – with `--force` it works nevertheless, but the content of this entry is then considered unverified.

The audit log is thus tamper-detecting – “revision-proof” in the sense of GoBD it would only be with an additional, unchangeable storage. Editors see the latest changes of the records that they may read in the tab “Changes”; the complete research is reserved for superusers. Data exports are created only by users with the additional right “Export personal data” (module “Privacy”).

The audit log itself contains personal data (numbers of the acting persons, IP addresses, earlier contents). Name it in your record of processing activities, with the purpose “traceability and security” and the periods chosen.

## Operational monitoring

- ***Operating notices on the start page:*** Superusers see beneath the module tiles whether the cron job has stopped, temporary files are not being deleted, the database backup is missing or failed, storage space is running short, directories are not writable, the configuration does not suit live operation, or the update check reports security vulnerabilities or new versions ([chapter 12](./12-operation.md#operating-notices-on-the-start-page)).
- ***Log files:*** errors, blocked sign-ins, emails that could not be delivered and security events in the module “[Log files](./10-modules.md#log-files)”, with a widget for the start page.
- ***Command line:*** `php Core/cron/camindo.php status` for external monitoring.

The notices appear only when someone opens the start page. For a notification without sign-in, integrate the server into your existing monitoring (availability of the website, age of `var/dbbackup/backup-1.sql`, free storage space).

## Tasks of the operator

- ![checkbox](../_/check_box_outline_blank.svg) Responsible persons named: operation, updates, data protection, security reports
- ![checkbox](../_/check_box_outline_blank.svg) Hosting with a data processing agreement, HTTPS, current server software
- ![checkbox](../_/check_box_outline_blank.svg) Daily database backup in the scheduler, collection of all backups outside the server, `.camindo.key` stored separately
- ![checkbox](../_/check_box_outline_blank.svg) Recovery practiced once a year
- ![checkbox](../_/check_box_outline_blank.svg) Update process defined; security corrections within a few days
- ![checkbox](../_/check_box_outline_blank.svg) `public/.well-known/security.txt` created and extended every year
- ![checkbox](../_/check_box_outline_blank.svg) Task “Clean up logs” set up, periods documented in the deletion concept
- ![checkbox](../_/check_box_outline_blank.svg) Periods of the audit log determined and stated in the record of processing activities
- ![checkbox](../_/check_box_outline_blank.svg) Process for requests for disclosure and erasure defined (who, how quickly, by which route the information is transmitted)
- ![checkbox](../_/check_box_outline_blank.svg) Rights concept: few superusers, named; group rights following the principle of least privilege; access of departed staff blocked immediately
- ![checkbox](../_/check_box_outline_blank.svg) Checklist for [live operation](./12-operation.md#checklist-for-live-operation) worked through
