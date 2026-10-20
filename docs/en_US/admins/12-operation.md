# 12 Operation and maintenance

## Cron jobs

camindo CMS needs ***one*** cron job of the web server user (for the setup see [installation, step 5](./01-quick-start-installation.md#step-5--cron-jobs)):

| Cron job | Interval | Tasks |
|---|---|---|
| `Core/cron/cron.php` | every minute | background conversion of videos and images, comparison of the template files and the due tasks of the [scheduler](#scheduler) – among them all maintenance tasks |

If `cron.php` does not run, scheduled tasks stand still, template files that were changed in the file system do not reach the backend, and videos are not converted. Superusers see this on the start page: beneath the module tiles a notice then appears (see [operating notices](#operating-notices-on-the-start-page)).

## Scheduler

The ***scheduler*** performs recurring and demanding work in the background, without holding up the editors. It hangs on the cron job `cron.php`, which runs every minute: each minute this looks for the due tasks and starts each as its own background process – one long task does not hold up the others. The tasks are set up in the module “Scheduler”. Which types there are and how the time control is written is described in [chapter 10](./10-modules.md#scheduler).

***Maintenance tasks:*** The installation creates these tasks (user `scheduler`, with log). Like any other task, their execution can be scheduled, switched off or deleted. If one of the maintenance tasks is missing or switched off, an [operating notice](#operating-notices-on-the-start-page) appears.

| Task | Default | What it does |
|---|---|---|
| ***Database backup*** | daily at 2:00 | backup to `var/dbbackup/`, 7 rotating. Recommendation: additionally store it outside the server (see [data backup](#data-backup)) |
| ***Delete temporary files*** | hourly at :10 | clean up `var/tmp/` and expired export files (including personal data reports) |
| ***Empty trash*** | daily at 2:20 | permanently delete entries that have been in the trash longer than `admin.trash_retention_days` |
| ***Apply deletion policies*** | daily at 2:30 | due jobs of the [deletion policies](./15-privacy.md#deleting-according-to-deletion-policy) |
| ***Apply retention periods*** | daily at 2:45 | shorten IP addresses, delete expired entries of the audit log, old internal messages and registrations that were never confirmed ([chapter 15](./15-privacy.md)), plus the periods of installed add-on modules – such as deleted chats of the AI assistant |
| ***Clean up logs*** | daily at 3:00 | delete old log files and sign-in attempts (see [logs](#logs)) |
| ***Clean up frontend cache*** | daily at 3:10 | expired files of the output cache. If templates use `caching` with short periods, this task should be set up hourly. |
| ***Rebuild full text*** | daily at 3:20, only outdated areas | rebuilds the areas whose full text is marked as no longer current after a structural change. If full-text fields use sources behind a “link”, an additional task makes sense for these areas. Without this option, weekly or manual suffices (see [rebuild full text](./05-input-fields.md#rebuilding-the-full-text)) |
| ***Check audit log*** | daily at 3:40 | check the files of the audit log for manipulation. The start page shows the findings |
| ***Check for updates*** | daily at 3:55 | newer versions and known security vulnerabilities (see [updates](#updates)) |
| ***Summarize messages by email*** | daily at 7:00 | email to the users who switched on the summary of their new internal messages (see [messages](./10-modules.md#messages)) |

Missing maintenance tasks – for example after an accidental deletion – are created again by the command `php Core/cron/camindo.php scheduler defaults`; existing ones remain unchanged. `scheduler list` shows all tasks, `scheduler run trash` (or an ID) runs one immediately.

***Scheduling or running immediately:*** A task runs at the times of its time control, as long as it is active. “Run now” in its detail view starts it immediately as a background process – even an inactive one. So you can set up a task that is needed only occasionally (for example “Rebuild full text”) once, leave it inactive and start it by hand when needed.

***Suspending temporarily:*** “Deactivate” in the context menu of the list or in the detail view suspends a task without touching its settings – the list then shows it as “temporarily suspended”, and no next run is scheduled. “Reactivate” lets it run again according to its time control.

***Reports:*** A task of the type “URL call” or “PHP call” can be marked as a ***selectable report***; at least one user group belongs to it. Users of these groups find the report in their personal settings under “Subscribe to report”. After every successful run, the subscribers receive the output as an internal message in HTML format (active content such as scripts is removed; for a complete HTML document only the content of `<body>`; outputs over 2 MB only as a note). The detail view of the task shows the number of subscribers.

***Result and errors:*** The list and detail view show the next and last run with result and message. Errors always end up in the log `var/log/{date}_scheduler.log`, successful runs only with the setting “Write system log file”. Failed tasks are reported to superusers on the start page (see [operating notices](#operating-notices-on-the-start-page)).

***Maximum execution time:*** Each task has an upper limit in minutes. If it is exceeded, the run is aborted and counts as failed. A task that is marked as “running” for longer than this limit plus five minutes counts as aborted and starts again at the next appointment.

***Rights:*** Each task runs under one user and with that user’s rights – new ones under the user `scheduler`, which the installation creates. More in [chapter 10](./10-modules.md#scheduler).

## Trash

Deleted records, input fields and areas are first only marked as deleted; the files of deleted records move to `var/deleted/`, those of a deleted input field stay locked in place. Whether a record ends up in the trash at all is determined by the [deletion policy](./15-privacy.md#deleting-according-to-deletion-policy) of its area. Permanent deletion takes place after the ***retention period*** (`admin.trash_retention_days`, default 7 days) through the scheduler task “Empty trash” (every night) – or immediately via “Tools” → “Database” → “Empty trash”. Deleted users are always retained.

Within the period, restoring takes place – including sub-records, hard-linked content and files:

- ***Records*** every user restores from their own [trash](./10-modules.md#trash) themselves.
- ***Areas*** are restored by a superuser from the trash. Their sub-areas and all records and input fields that were deleted together with them come back with the area.
- ***Input fields*** are restored by a superuser from the trash – with their contents (see [deleting an input field](./05-input-fields.md#deleting-an-input-field)).

It also works on the command line, without the backend:

```bash
php Core/cron/mass_delete.php --table=record --id=1758 --mode=restore --withchilds=1 --admin=1
```

`--id` is the number of the record, `--admin` the number of a user with the necessary rights (for example a superuser) under whose name the restoration runs. `--withchilds=1` restores the sub-records that were deleted in the same operation. For a deleted area including its records, use `--table=area`.

## Working copies

If an editor cancels the editing without clicking “Cancel” – for example because they close the browser –, a ***working copy*** remains. It locks the record only until it has been unchanged for 90 minutes (`admin.edit_lock_sec`); after that, someone else can open the record again. “Tools” → “Database” → “Discard working copies” removes leftover working copies (default: older than 24 hours).

## Data backup

A complete backup consists of:

| What | Where |
|---|---|
| Database | via the [scheduler](./10-modules.md#scheduler) (task “Database backup”) to `var/dbbackup/`, or with your own tools |
| Configuration | `.camindo.php` |
| ***Key file*** | `.camindo.key` (or the content of the environment variable `CAMINDO_KEY`, see [access data from environment variables](#access-data-from-environment-variables)) – without it, encrypted contents and passwords are useless. Keep it in a safe place, **separate** from the database backups |
| Media files | `public/media/` |
| Project data | `var/templates/` (template sources),<br>`var/custom/` (snippets, scripts and outputs of the scheduler),<br>`var/extensions/` (stylesheets),<br>`var/modules/` (for example PDF templates and fonts),<br>`var/area_blueprints/` (own blueprints),<br>`var/deleted/` (trash),<br>`var/translation/translated` if an own backend translation has been created. |
| Composer state | `composer.json` and `composer.lock` – so that exactly the same versions can be installed again |
| Change logs | `var/audit/` – the contents of the audit log; after importing a database backup, `audit restore` reconciles them with the database |

Before every update and before changes to components, camindo CMS automatically creates a database backup in `var/dbbackup/`. Manually: `php Core/cron/camindo.php backup`; old backups are cleared with `php Core/cron/camindo.php backup prune --keep=5`.

Individual areas can additionally be backed up and selectively restored via [XML export](./09-structure-export-import.md).

## Updates

```bash
cd /var/www/mysite
php Core/cron/camindo.php maintenance on
composer update
php Core/cron/camindo.php upgrade
php Core/cron/camindo.php maintenance off
```

Composer exchanges the files of the core and add-on components; `upgrade` backs up the database, brings it to the new state and rebuilds all caches.

***Maintenance mode:*** While `maintenance on` applies, the website answers with a maintenance page (HTTP 503), background processes pause, and the backend is accessible only to superusers. You can provide your own maintenance page as `var/custom/maintenance.html`. If files and database do not match after a `composer update`, camindo CMS switches itself into maintenance mode until `upgrade` has run.

***Back to the previous state:*** Maintenance mode on, restore the database backup from `var/dbbackup/`, restore the previous `composer.lock` and run `composer install`, then `upgrade` and maintenance mode off.

### Update check

Every night, the scheduler task “Check for updates” checks with Composer whether there are newer versions of camindo CMS and the installed packages (`composer outdated`, direct packages and their dependencies) and whether ***security vulnerabilities*** are known for installed packages (`composer audit`). The result is shown in the main menu under ***“Info”*** – only for superusers, with a table of the packages and the button ***“Check now”***.

Only versions that `composer update` actually installs within the version constraints are reported. A new ***major version*** of a package does not appear – it requires a change to `composer.json`. An operating notice appears on the start page only for a new version of camindo CMS and for known security vulnerabilities; newer versions of other packages are listed under “Info” only.

The check requires network access to Packagist or the package sources and the program `composer` on the server (`paths.composer`, empty = check off). The web server and the cron job start Composer without a shell – an alias from `.bashrc` does not apply there. Enter the absolute path or the whole command line instead, for example for a `composer.phar` with its own PHP version:

```php
'composer' => '/usr/bin/php8.4 -d memory_limit=-1 /home/www/bin/composer/composer.phar',
```

Put words containing spaces in double quotes. JavaScript libraries that camindo CMS brings along as files (jQuery, Bootstrap, editors) come with the update of camindo CMS.

## The command line

All administrative commands run through one program:

```
php Core/cron/camindo.php <command> [arguments] [--options]
```

**Commands**:

```
status
```
State of the system: versions, components, pending actions, maintenance mode

```
install
```
First installation; repeat individual steps with `--step=…`

```
upgrade
```
Bring the database up to date after an update

```
component list
component install NAME [--activate]
component activate NAME
component deactivate NAME
component update NAME
component uninstall NAME [--purge]
```
Manage add-on components

```
maintenance on [--reason=…]
maintenance off
maintenance status
```
Maintenance mode

```
backup
backup prune --keep=N
```
Database backup

```
cache all
cache docs
```
Rebuild the caches for snippets and backend templates and the search index of the manuals (`docs`: only the index); `all` also empties the output cache of the templates and rewrites the buffer of the websites and group rights

```
cache include
```
Empty the output cache of the templates (`CMS:INCLUDE … caching=…`) – for example so that a content change becomes visible immediately

```
cache records [--instance=ID] [--area=ID,…]
refresh records [--instance=ID] [--area=ID,…]
```
Clear the ***record cache*** – all websites, one website (`--instance`) or single areas (`--area`, several separated by commas). Needed for example after changes made directly in the database. The entries are rebuilt on the next access, open working copies are not affected. Not part of `cache all`. The same in the backend under “Tools” → “Server” → “Clear record cache”

```
refresh
```
Compare components with the database

```
refresh snippets
refresh html
```
Rebuild the snippet cache or the cache of the backend templates; `snippets` first converts snippet sets in the earlier PHP format into `.ini` files (see [chapter 10](./10-modules.md#snippets-module))

```
admin password NAME
```
Set the password of a user – for example if the only superuser has forgotten theirs

```
key status
key rotate [--invalidate-passwords]
key retire KID [--force]
```
Change the key file `.camindo.key`, see [changing the key](#changing-the-key)

```
key rehash
```
Recalculate search values of encrypted fields – once, for data that was stored case-insensitively before the search

```
audit verify [TYPE ID]
```
Check the audit log: checksum chain of the files under `var/audit/` and comparison with the database

```
audit reseal TYPE ID [--reason=…] [--dry-run]
```
Re-chain a broken checksum chain after checking, see [security and compliance](./14-security-and-compliance.md)

```
audit anonymize-ip [--dry-run]
```
Once after the update: shorten IP addresses in the files under `var/audit/` that are still stored in full, see [chapter 15](./15-privacy.md#ip-addresses)

```
audit restore [--dry-run]
```
After restoring a database backup: remove entries under `var/audit/` that are newer than the database (copy under `var/audit-restore-…/`)

```
audit delete TYPE ID [--dry-run]
audit delete --area=ID [--force] [--dry-run]
```
Delete the entire history of an object (for example `audit delete record 4237`, `audit delete admin 34`) – in the database and under `var/audit/`; for records also their jobs of the [deletion policies](./15-privacy.md#deleting-according-to-deletion-policy). With `--area=ID`, the history of all records of an area, including those already deleted; without `--force`, the command only names the number. `--dry-run` shows what would be deleted. Only for exceptional cases – normally the retention periods and the deletion policies delete

```
audit strip-names
```
Once after the update: remove the names of the acting persons from older entries – afterwards they only appear as an ID (repeatable)

```
api login --admin=NAME [--lifetime=SEC]
api call METHOD '{…}'
api whoami
api methods
api describe METHOD
api logout
```
The API on the command line: `login` creates a session for the user and outputs its identifier, which the following commands need as `--session=…` or in the environment variable `CAMINDO_API_SESSION`. The user group of the user needs the interface “Command line (CLI)”

```
cache instances
```
Rewrite the buffer for websites, languages, areas and group rights (also part of `cache all`)

```
htaccess
htaccess renew
```
Check or renew the ***.htaccess*** – like the “Renew” button under “Tools” → “Server”. Without an argument the command reports whether the file matches the directory structure (exit code 1 if not); `renew` rewrites the section managed by camindo CMS, lines outside it are kept. `upgrade` renews the file anyway

```
template commands --lang=en_US
template commands --all --out=DIRECTORY
```
Output the ***CMS commands*** (core and active modules) with their parameters and descriptions as JSON – for editor support such as the extension for Visual Studio Code. Without `--all` in one language to standard output, with `--all` one file `commands.{language}.json` per backend language in the directory


### Changing the key

The file `.camindo.key` encrypts confidential fields (email addresses, telephone numbers, encrypted input fields, messages, secrets of the authenticator app), salts the password check values and ***signs*** accounts, user groups and rights: for each account and each right camindo CMS stores a checksum that can only be calculated with the key. Whoever changes only the database thereby produces an invalid checksum – the account or right counts as manipulated and is ignored. After a key change, all checksums must therefore be recalculated (“re-sign”).

To introduce a new key, run

```
php Core/cron/camindo.php key rotate
```

The command backs up the database, switches on maintenance mode, re-encrypts everything with the new key, re-signs (including the checksum chain of the audit log, after the old one has been checked) and signs out all users. It can be run at most once every 24 hours – a second call so soon afterwards would be a mistake; `key status` names the earliest possible time. The old key remains as a ***retired key*** in `.camindo.key.retired`: passwords cannot be converted, because camindo CMS does not know them – they remain valid and are switched automatically to the new key at the next sign-in. Recovery codes and known devices remain valid as well.

***File permissions of the key:*** The new `.camindo.key` and `.camindo.key.retired` receive the same permissions as the previous `.camindo.key` – even if you have deliberately set permissions other than `0600`, for example because the web server can read the file on your system only through the group. If there is no `.camindo.key`, because the key comes from the environment variable `CAMINDO_KEY`, the setting `permissions.key_file` in `.camindo.php` applies (default `0600`). `key status` warns if the permissions of the file deviate from it. If you run the change as `root`, both files also keep the owner of the previous `.camindo.key` – otherwise the web server could no longer read them afterwards.

`key status` shows the current key, retired keys with the deadline `security.key_password_grace_days` (default 90 days) and how many passwords still depend on the old key. Once the deadline has passed, remove the old key with `key retire KID --force`: whoever has not signed in by then needs a new password (user: `admin password NAME`, frontend user: “Forgot password”), and recovery codes from before the change no longer apply.

**Passwords publicly exposed?** Then

```
php Core/cron/camindo.php key rotate --invalidate-passwords
```

All passwords become invalid at once, all sessions, known devices and recovery codes are deleted, and the old key is removed immediately. Afterwards set the passwords of the users with `php Core/cron/camindo.php admin password NAME`; frontend users set theirs via “Forgot password”. If there is reason to suspect that the key itself has fallen into the wrong hands, also reset the authenticator apps of the users.

> Back up `.camindo.key` again after every change – separately from the database backups.

**Key in the environment variable `CAMINDO_KEY`?** `key rotate` nevertheless writes the new key into the file `.camindo.key` – it takes precedence over the variable, so the system works with the new key immediately. Afterwards enter the content of `.camindo.key` into the variable; only then may you remove the file. Until then, an [operating notice](#operating-notices-on-the-start-page) appears on the start page. The file `.camindo.key.retired` with the retired keys remains in any case.

## Configuration

The configuration is in `.camindo.php` in the project directory. The installer writes the most important details; everything else has defaults that you can override there, see the following table. The complete, commented list is in `Core/src/Config.php`.

The file contains access data: permissions `0600`, never in a version control system!

| Key | Default | Meaning |
|---|---|---|
| `system.locale` | – | locale of the installation, for example `de_DE` |
| `system.timezone` | – | time zone of the system and the cron jobs |
| `system.development_version` | `false` | development mode – never on a live system |
| `system.debugsql` | `false` | log all SQL commands with runtime – only together with `system.development_version`, purely a developer feature |
| `system.link_length` | `120` | maximum length of automatically generated slugs |
| `system.html_allowed_tags` | `[]` | optional whitelist of permitted HTML tags in contents, for example `['p','br','strong','a','h1','img']`; other tags are removed when saving and displaying. Script, iframe, object, embed, base, meta and event handlers are always removed by camindo CMS |
| `admin.2_factor` | `true` | two-factor sign-in by email code (users with an authenticator app always get its prompt) |
| `admin.2_factor_expiration` | `600` | validity of the sign-in code in seconds |
| `admin.max_inactive_sec` | `1800` | automatic sign-out after inactivity (seconds) |
| `admin.edit_lock_sec` | `5400` | expiry time of edit locks (seconds): as long as it remains unchanged, a working copy no longer locks the record. Longer than the automatic sign-out, so that an open form can still be saved after a break and renewed sign-in |
| `admin.bruteforce_max`,<br>`admin.bruteforce_delay` | `3` / `600` | permitted failed attempts per sign-in name and blocking period in seconds (0 = protection off). On a device on which the user has already signed in, their attempts count separately – a blocked name does not lock them out there |
| `admin.bruteforce_ip` | `true` | when there are too many failed attempts, note the visitor’s address in the log `security` |
| `admin.throttle.login_ip` | `[20, 600]` | at most this many failed sign-ins per visitor address in the time window (seconds), across all sign-in names – after that, sign-in from this address is blocked (0 = off). Behind a proxy, set `security.trusted_proxies` |
| `admin.backend_domain` | `''` | fixed address of the backend, for example `'cms-k7x2.example.com'` (see [fixed backend domain](#fixed-backend-domain)). Empty: backend under `/backend/` of every domain |
| `admin.cookie_secure`,<br>`admin.cookie_samesite` | `true` / `'Lax'` | send the session cookie only over HTTPS. `false` only for a local test without SSL ([quick start](./01-quick-start-installation.md#test-locally-without-https)); SameSite attribute of the cookie |
| `admin.password_retention_days` | `0` | validity of a password in days; afterwards the user must set a new, different one at sign-in (0 = unlimited). A password set by another user must always be changed at the first sign-in |
| `admin.allowed_origins` | `[]` | further addresses from which forms may be sent to the backend, for example `['https://cms.example.com']` – only needed if a proxy rewrites the host name (see [security headers](#security-headers)) |
| `admin.skiplist_default` | `20` | records per page in lists |
| `admin.list_max_datasets` | `200` | maximum entries in selection lists |
| `admin.max_include_depth` | `20` | maximum nesting depth of templates (`CMS:INCLUDE`, `CMS:SELECT`) |
| `admin.trash_retention_days` | `7` | retention period in the trash |
| `admin.mass_delete_threshold` | `200` | from this number on, mass deletion and batch processing necessarily run in the background (cannot be deselected in the backend; can be bypassed via the API with `background: no`); `0` = threshold off |
| `admin.import_background_threshold` | `200` | from this number of records on, an import runs in the background |
| `admin.export_background_threshold` | `1000` | from this number of records on, an export runs in the background |
| `admin.export_retention_hours` | `24` | retention time of export files that have not been collected |
| `admin.tmp_retention_hours` | `24` | retention of temporary files in `var/tmp` |
| `admin.include_cache` | `true` | output cache of the templates (`CMS:INCLUDE … caching=…`); `false` switches it off, all templates run again on every call |
| `admin.include_cache_lock_wait` | `30` | a page call waits at most this many seconds while another creates the same cache file; after that it creates the output itself |
| `api.max_inactive_sec`,<br>`api.max_lifetime` | `3600` / `86400` | sessions of the command line and the PHP API: automatic sign-out after inactivity and maximum runtime in seconds (also the upper limit of a runtime specified at sign-in) |
| `api.max_limit` | `200` | at most this many records per page of an API list |
| `api.base64_max_bytes` | `20971520` | maximum size of a file transferred as Base64 in bytes (0 = no limit) |
| `security.hsts_max_age` | `0` | HSTS: browsers only allow the domain via HTTPS, for this number of seconds (0 = off), see [security headers](#security-headers) |
| `security.hsts_include_subdomains` | `false` | HSTS also for all subdomains |
| `security.trusted_proxies` | `[]` | proxies or load balancers in front of the website (IP addresses or ranges such as `10.0.0.0/8`). Only from them does camindo CMS take the visitor’s address from `X-Forwarded-For` – important for blocks and throttling per visitor |
| `security.frontend_csp` | `'off'` | script policy of the websites without their own setting: `'off'`, `'report'`, `'enforce'` – see [chapter 3](./03-instances-and-languages.md#script-policy) |
| `security.form_challenge` | `''` | class of an external CAPTCHA for protected forms (developers) |
| `security.magick_allow` | `[]` | formats that ImageMagick may additionally process: `'svg'`, `'pdf'` – see [securing ImageMagick](#securing-imagemagick) |
| `security.password_policy` | 12 characters, 1 special character, 1 uppercase (not at the beginning), 1 lowercase letter | password policy, see [passwords and sign-in devices](#passwords-and-sign-in-devices) |
| `security.password_hash` | Argon2id, otherwise bcrypt | method and effort for stored passwords |
| `security.device_notify`,<br>`security.device_days`,<br>`security.device_cookie` | `true` / `180` / `'c9dev'` | note when signing in from a new device; how long an unused device stays known (at the same time the runtime of the cookie); name of the device cookie |
| `security.login_notifier` | `''` | own class for the notes (for example SMS instead of email) |
| `security.admin_2fa` | `'totp'` | authenticator app (TOTP) for users: `'totp'` selectable, `'totp_required'` mandatory for superusers, `'email'` switched off – see [two-factor sign-in](#two-factor-sign-in) |
| `security.totp_issuer` | `''` | name under which the account appears in the app (empty: `camindo`); together with the sign-in name@domain |
| `security.webauthn` | `true` | passkeys and security keys as second factor in the backend – only with a fixed backend domain (`admin.backend_domain`). `false` switches off creating and signing in with a passkey |
| `security.key_password_grace_days` | `90` | deadline after a [key change](#changing-the-key) that `key status` names for passwords on the old key |
| `security.backend_csp` | `'enforce'` | script protection of the backend: `'enforce'` allows only scripts from camindo CMS. `'report'` lets everything run and logs violations (module “Log files”, log `csp`), should an add-on no longer work. `'off'` switches it off |
| `audit.enabled` | `true` | audit log: who changed what and when, see [audit log](./10-modules.md#audit-log) |
| `audit.retention_days_changes` | `0` | retention of the entries for existing objects in days (0 = unlimited) |
| `audit.log_logins` | `false` | record successful sign-ins and sign-outs to the backend in the audit log (failed ones always), see [chapter 15](./15-privacy.md#users-employees) |
| `audit.retention_days_logins` | `90` | retention of sign-ins/sign-outs and failed attempts in days (0 = as `audit.retention_days_changes`) |
| `audit.retention_days_deleted` | `365` | retention of the history of permanently deleted objects in days, counted from the deletion (0 = unlimited) – not for records, whose history is governed by the [deletion policy](./15-privacy.md#deleting-according-to-deletion-policy) |
| `audit.page_bytes` | `1048576` | contents of the audit log are under `var/audit/`, one file per object; from this size in bytes a new page begins |
| `privacy.ip_anonymize_days` | `7` | after this many days, IP addresses in the audit log, in sign-in attempts and in the log files are shortened (0 = at the next run of the task “Apply retention periods”), see [chapter 15](./15-privacy.md#ip-addresses) |
| `privacy.message_days` | `60` | internal messages are deleted this many days after sending – for sender and recipient (0 = never) |
| `privacy.ip_v4_octets` | `1` | how many digits at the end of an IPv4 address are zeroed: `1` (203.0.113.**0**) or `2` (203.0.**0.0**). IPv6 always loses the last 80 bits |
| `ai.*` | – | add-on AI assistant: provider, access key, model, see [AI assistant](./10-modules.md#add-on-ai-assistant) |
| `email.*` | – | email dispatch, see below |
| `user.*` | – | frontend community, see [chapter 13](./13-community.md#configuration) |
| `paths.php`,<br>`paths.mysqldump`,<br>`paths.magick`,<br>`paths.ffmpeg`,<br>`paths.ffprobe`,<br>`paths.composer` | – | paths to external programs; leave empty what does not exist (`composer`: [update check](#update-check)) |
| `paths.document_root`,<br>`paths.media`,<br>`paths.backend` | `public/`,<br>`'/media'`,<br>`'/backend'` | directory to which the web server points; address paths of the media files and the backend relative to it |
| `paths.key_file`,<br>`paths.sqlpwd_file` | `.camindo.key`,<br>`.sqlpwd` | key file and access file for `mysqldump` (absolute paths, default in the project directory) |
| `paths.var_dir`,<br>`paths.tmp_dir`,<br>`paths.upload_dir` | `var/`,<br>`var/tmp/`,<br>`var/upload/` | writable main directory, temporary files and directory for large files transferred via FTP (selection via the file icon next to upload fields) |
| `permissions.dir`,<br>`permissions.file` | `0775` / `0664` | Unix permissions for directories and files that camindo CMS creates |
| `permissions.key_file` | `0600` | permissions of `.camindo.key` and `.camindo.key.retired` during a [key change](#changing-the-key), if there is no `.camindo.key` yet (key from `CAMINDO_KEY`) – otherwise the change keeps the permissions of the previous file |
| `db.*` | – | access data of the database |


### Access data from environment variables

Passwords and the key need not be in files in the project directory. If your host or your server environment lets you set ***environment variables*** (Docker, PHP-FPM, web server configuration, secret management of the platform), camindo CMS can take these values from there. The rest of the configuration – database host, mailbox, provider – remains in `.camindo.php`.

| Variable | replaces | Precedence |
|---|---|---|
| `CAMINDO_DB_PASS` | `db.pass` | variable before `.camindo.php` |
| `CAMINDO_SMTP_PASS` | `email.smtp.pass` | variable before `.camindo.php` |
| `CAMINDO_AI_API_KEY` | `ai.api_key` (add-on AI assistant) | variable before `.camindo.php` |
| `CAMINDO_KEY` | the key file `.camindo.key` (exactly its content) | **file before variable** – so that after a [key change](#changing-the-key) the new key applies until you have updated the variable. If the file exists but is not readable, camindo CMS aborts instead of taking the variable |

An empty variable counts as not set. camindo CMS does **not** read an `.env` file – the variables must reach the environment of PHP from outside, both for the web server and for the cron jobs and the command line.

If the database password comes from `CAMINDO_DB_PASS`, the backup does not need the file `.sqlpwd`: it writes a separate option file for each run and deletes it afterwards. The installer also takes over `CAMINDO_DB_PASS`, then leaves the password out of `.camindo.php` and does not create a `.sqlpwd`; if `CAMINDO_KEY` is set, it does not create a `.camindo.key`.

`php Core/cron/camindo.php status` shows which values come from the environment (“from environment”). After reading them in, camindo CMS removes the variables from the environment of PHP: programs that it calls (ImageMagick, ffmpeg, mysqldump) do not see them, nor does the PHP info in the module “Tools” – except under “Apache Environment”, if the web server sets them with `SetEnv`. Therefore prefer the environment of PHP-FPM or of the container.

### Email dispatch

camindo CMS sends emails, among other things, for the sign-in code of the two-factor sign-in.

| Key | Meaning |
|---|---|
| `email.noreply` | sender address of the system emails – prerequisite for the two-factor sign-in |
| `email.reply` | contact address that is named in system emails (see below) |
| `email.smtp.host`, `user`, `pass`, `enc`, `port` | SMTP mailbox for reliable sending (`enc`: `starttls` or `smtps`). Empty: PHP function `mail()` |

With an SMTP mailbox of the sender domain, system emails land in spam much less often. Emails that could not be sent appear in the module “[Log files](./10-modules.md#log-files)”.

The contact address (`email.reply`) is not used as the sender (`From`) – otherwise automatic replies (autoresponders) would fill your mailbox. It appears only in the text of the email.

### Fixed backend domain

Without a further setting, you reach the backend under `/backend/` of every domain of your installation – and thus so does everyone who knows your website. Better is a ***fixed backend domain***, preferably a subdomain that nobody guesses:

```php
'admin' => [
    'backend_domain' => 'edit-website.example.com',
],
```

Prerequisites: the subdomain points via DNS to the same server and, in the web server, to the same directory `public/` as the websites, and it has its own HTTPS certificate (Let’s Encrypt is sufficient).

After this, the following applies:

- Under all other domains, `/backend/` answers with “not found” – without a note of the correct address.
- The ***preview*** opens under the domain of the website. The link in it applies only as long as you are signed in to the backend, and at most eight hours.
- ***Frontend editing*** transfers the saving of personal settings to all websites.
- The address of your backend does not appear on any page of the website.

A fixed backend domain is also a prerequisite for signing in with passkeys.

### Security headers

camindo CMS sends with every page headers that the browser implements as protection:

- ***No foreign frame:*** The backend cannot be displayed in any `<iframe>`, the website only within its own domain. This prevents a foreign page from slipping clicks into the backend, sign-in or shopping cart (clickjacking).
- ***Only own scripts in the backend:*** The backend executes only JavaScript from camindo CMS itself. If a script reaches content despite all filters, it does not run. If an add-on no longer works afterwards, temporarily set `security.backend_csp` to `'report'` and pass the entries of the log `csp` on to the manufacturer.
- ***No reinterpretation of file types*** (`nosniff`) and ***economical passing on of the address*** to external sites (`Referrer-Policy`).
- ***Origin check:*** camindo CMS accepts forms and actions in the backend that change something only if the browser sends them from the address of the backend (header `Origin` or `Referer`) – in addition to the security token of each session. If the backend runs behind a proxy that rewrites the host name, enter the public address in `admin.allowed_origins`.

You need not set this up. If a page is to be embedded by partners, the template programmer changes this in the template ([CMS:HEADER](../templateprogrammers/header.md)).

***HSTS*** instructs the browser to call the domain only via HTTPS. Only switch it on when the page runs permanently and completely over `https://` – the browser remembers the rule for the set time, even if you switch it off again. Best set HSTS in the web server or with your host; if that is not possible, in `.camindo.php`:

```php
'security' => [
    'hsts_max_age' => 300,        // five minutes for testing, then 31536000 (one year)
],
```

`hsts_include_subdomains` only if really every subdomain can do HTTPS.

### Passwords and sign-in devices

***Stored*** passwords are kept with ***Argon2id*** – a deliberately slow and memory-intensive method that makes trying out stolen data expensive. If the PHP of the server does not offer Argon2id, camindo CMS uses **bcrypt**, which every PHP supports. The default is the OWASP minimum (19 MB of memory, two passes, around 30 milliseconds per sign-in). Older passwords are switched automatically at the next sign-in; nobody has to set a new password.

```php
'security' => [
    'password_hash' => ['algo' => 'auto', 'memory_cost' => 19456, 'time_cost' => 2, 'cost' => 11],
],
```

`algo`: `auto`, `argon2id` or `bcrypt`; `memory_cost` in KiB and `time_cost` for Argon2id, `cost` for bcrypt.

The ***password policy*** always applies to the passwords of the users and, for password fields in the frontend, when the field carries the extension [Password policy](./extensions/password.md). Each rule can be switched off with `0`:

```php
'security' => [
    'password_policy' => [
        'min_length'      => 12,    // minimum length
        'min_special'     => 1,     // special characters: everything except letters and digits
        'min_upper'       => 1,     // uppercase letters …
        'upper_not_first' => true,  // … an uppercase letter at the beginning does not count
        'min_lower'       => 1,     // lowercase letters
        'min_digit'       => 0,     // digits
    ],
],
```

Length protects more than special characters: a long password made of several words is stronger than a short one with punctuation. If you relax the rules, you should rather lower `min_special` than `min_length`.

***Sign-in devices:*** camindo CMS recognizes the browser with which someone signs in. If an account signs in from a ***new device***, the holder receives a note – users by email to their stored address (requires the [email dispatch](#email-dispatch)), frontend users via the templates of the website (see [chapter 13](./13-community.md#sign-in-devices-and-passwords)). The first sign-in of an account triggers no note. A device that has not been used for `device_days` days counts as new again afterwards; likewise a browser whose cookies have been deleted. The two-factor sign-in remains in force even on known devices.

### Two-factor sign-in

After the password, camindo CMS requires a second factor – one of three for each user:

| Factor | When | Strength |
|---|---|---|
| ***Passkey*** (WebAuthn) | the user has created one – requires a [fixed backend domain](#fixed-backend-domain) | highest: bound to the address of the backend, cannot be intercepted, no input on forged pages possible; plus recovery codes |
| ***Authenticator app*** (TOTP according to RFC 6238) | the user has set it up in their personal settings and `security.admin_2fa` is not `'email'` | high: the code is generated on the phone; plus ten recovery codes that can each be used once |
| ***Code by email*** | otherwise, if `admin.2_factor` is on | as secure as the mailbox |

`security.admin_2fa` controls the app:

```php
'security' => [
    'admin_2fa' => 'totp_required',  // 'email' | 'totp' (default) | 'totp_required'
],
```

With **`'totp_required'`**, all superusers must use the app. Whoever does not yet have it sets it up at the next sign-in directly in the sign-in dialog; a superuser can then no longer switch it off. For the other user groups, the app remains voluntary. With **`'email'`**, the app cannot be set up, and existing ones are suspended – the email code applies again.

***Passkeys*** are open to every user as soon as `admin.backend_domain` is entered, independently of `security.admin_2fa`. A passkey also fulfills the requirement of `'totp_required'`. If a user has passkeys **and** the app, the sign-in dialog takes both. Passkeys can be switched off with `'security' => ['webauthn' => false]` – accounts that had only passkeys then sign in with a recovery code. The binding to the domain means: if the backend domain changes, all passkeys must be created again.

A wrong code counts like a wrong password (`admin.bruteforce_max`), and a correct password does not reset the counter – only the complete sign-in does. In this way the six-digit code cannot be tried out. Every app code applies only once.

When a user sets up the app, switches it off or signs in with a recovery code, they receive an email (or a message via the `login_notifier`), and the process appears in the audit log. Lost phones are reset by a superuser ([chapter 7](./07-users-and-rights.md#sign-in-protection)).

Instead of an email, an ***SMS*** or another message can be sent: a developer writes a small PHP class for this that fulfills the interface `Camindo\Core\LoginNotifierInterface`, and you enter it:

```php
'security' => [
    'login_notifier' => 'App\SmsNotifier',
],
```

The class receives the events “sign-in from a new device” (backend and frontend) and “password changed” (frontend) with account, device, IP address and time. If it cannot send, this is recorded in the log `security`.

### Securing ImageMagick

Image files are a popular attack route: a manipulated file exploits a vulnerability in ImageMagick or in a program that calls ImageMagick. camindo CMS protects itself on three levels, without you having to set up anything:

- ***Own security policy:*** Before each call, a policy applies that camindo CMS creates in `var/config/magick/policy.xml`. It blocks script and special formats, foreign helper programs (Ghostscript, curl, LibreOffice …) and PostScript, and limits memory, runtime and image size. It supplements the policy of your server, it cannot release anything that the server blocks. This also works on shared hosting.
- ***Format from the content:*** ImageMagick reads each file only with the format that camindo CMS recognized when uploading. If the extension and content do not match, the upload is rejected.
- ***Time limits:*** Each conversion is aborted after two minutes at most.

You release SVG and PDF with `security.magick_allow` (see [media](./08-media.md#setting-up-converters)). The detail view of a converter shows the installed ImageMagick version, whether it is outdated, and whether SVG and PDF actually work.

***If you operate the server yourself***, you can further isolate ImageMagick:

- Keep ImageMagick up to date (from version 7.1.1). Older versions work, but contain known vulnerabilities.
- Do not install Ghostscript if you do not convert PDFs.
- Let ImageMagick run under its own user or in its own container, which may only read and write the media directory and `var/tmp`. Then enter in `paths.magick` a small script that passes on the call and sets limits. It must be called `magick` and lie in its own directory; this is how camindo CMS recognizes ImageMagick 7:

  ```sh
  #!/bin/sh
  # /opt/camindo-magick/magick  -> paths.magick
  ulimit -t 150 -v 1048576          # CPU seconds, memory in KB
  exec sudo --preserve-env=MAGICK_CONFIGURE_PATH -u imagick /usr/bin/magick "$@"
  ```

  `sudo` needs an entry in the sudoers file for the web server user. It is important to use `--preserve-env`: without the variable `MAGICK_CONFIGURE_PATH`, the camindo policy is missing. The user `imagick` must be able to read `var/config/magick/`.

  For ImageMagick 6, place two such scripts, `convert` and `identify`, in the directory and enter the path to `convert`.
- If you process only JPEG, PNG and GIF, the GD processor is sufficient. It needs no ImageMagick.

## Logs

All logs are in `var/log/`, one file per day and type (for example `2026-09-22_php_errors.log`, `…_scheduler.log`, `…_security.log`). Superusers see them in the module “[Log files](./10-modules.md#log-files)”.  
`php Core/cron/camindo.php status` summarizes the state of the system.

The log files grow until they are deleted. The scheduler task ***“Clean up logs”*** (created by the installation, see [scheduler](#scheduler)) deletes old log files and stored sign-in attempts with IP address; you set the periods in the task. Independently of this, camindo CMS shortens the IP addresses in the log files after `privacy.ip_anonymize_days` days (see [chapter 15](./15-privacy.md#ip-addresses)).

Who changed what in the backend is not stated in the log files, but in the [audit log](./10-modules.md#audit-log).

## Operating notices on the start page

Superusers see a block ***“Operational notices”*** beneath the module tiles on the start page as soon as something is wrong in operation. If everything is in order, it does not appear.

| Notice | Meaning |
|---|---|
| Cron job not running | `cron.php` has not reported for more than 15 minutes. The scheduler (including all maintenance tasks) and thumbnails stand still |
| Temporary files too old | `var/tmp/` contains entries that are older than `admin.tmp_retention_hours` (plus two hours). The task “Delete temporary files” does not take effect |
| Maintenance tasks missing | For one of the maintenance tasks (trash, temporary files, deletion policies, retention periods, check audit log, updates) there is no switched-on task in the scheduler |
| No database backup | There is no switched-on task “Database backup” |
| Database backup failed / too old / unusable | The last run reported an error, `backup-1.sql` is older than two days or unusable: smaller than 10 KB (then usually only an error message is in it) or without the final line `-- Dump completed` (dump aborted) |
| Tasks with errors | Other scheduler tasks whose last run failed |
| Logs are not cleaned up | There is no task “Clean up logs” |
| Frontend cache is not cleaned up | Templates use the output cache, but there is no switched-on task “Clean up frontend cache” |
| Little free storage | Less than 2 GB free for `var/` (less than 512 MB: alarm) |
| Not writable | `var/tmp`, `var/log` or the media directory |
| Email missing | two-factor sign-in is on, but no sender address is set up |
| Development mode | `system.development_version` is switched on |
| Cookie without HTTPS | `admin.cookie_secure` is `false` |
| Key in CAMINDO_KEY outdated | The environment variable `CAMINDO_KEY` does not contain the current key – usually after a [key change](#changing-the-key). `.camindo.key` is used; enter its content into the variable |
| security.txt missing | There is no reporting channel for security vulnerabilities (see [chapter 14](./14-security-and-compliance.md#reporting-security-vulnerabilities)) |
| Audit log damaged | The daily check has found a broken checksum chain in `var/audit/`, missing entries or entries of the database that do not match the files; details with `php Core/cron/camindo.php audit verify` |
| Security vulnerabilities / new version / updates | Result of the daily [update check](#update-check): known vulnerabilities in installed packages (alarm), a new version of camindo CMS (warning); “Open” leads to “Info” |

A click on “Open” leads, where possible, directly to the place where the notice can be remedied.

## Checklist for live operation

- [ ] HTTPS set up, `admin.cookie_secure` and `user.cookie_secure` set to `true` (default – `false` may no longer stand in `.camindo.php`)
- [ ] `system.development_version` is `false`
- [ ] ImageMagick up to date and, in the detail view of the converters, no warning about version and security policy
- [ ] HSTS switched on (web server or `security.hsts_max_age`), as soon as HTTPS runs permanently
- [ ] Email dispatch set up, two-factor sign-in switched on, all users with an email address (sign-in code and notice of new devices)
- [ ] Superusers with an authenticator app – on live systems `security.admin_2fa = 'totp_required'`
- [ ] Password fields in the frontend with the extension “Password policy”
- [ ] Shops and member areas: second factor in the profile area at least “Code on a new device” ([chapter 13](./13-community.md#second-factor))
- [ ] Script policy of the website first “Report only”, after a clean log “Enforce” ([chapter 3](./03-instances-and-languages.md#script-policy))
- [ ] fixed backend domain entered (`admin.backend_domain`), own subdomain with HTTPS
- [ ] behind a proxy or load balancer: `security.trusted_proxies` set
- [ ] the cron job `cron.php` runs (`php Core/cron/camindo.php status`)
- [ ] maintenance tasks present and switched on in the scheduler (`php Core/cron/camindo.php scheduler list`), times suitable
- [ ] database backups additionally stored outside the server
- [ ] templates use `caching`: task “Clean up frontend cache” set up in the scheduler
- [ ] periods of the audit log (`audit.*`) coordinated with the data protection concept
- [ ] `public/.well-known/security.txt` created
- [ ] no operating notices on the start page
- [ ] `.camindo.key` secured in a safe place (or the content of `CAMINDO_KEY`)
- [ ] write permissions of the web server only on `var/` and `public/media/`
- [ ] instances with “Canonical requests” for search engines

## License

camindo CMS is free software under the ***GNU AGPL*** version 3 or later. You find the license text in `Core/LICENSE`, the licenses of the bundled libraries in `Core/THIRD-PARTY-NOTICES.md`.

For operation, this means:

* An **unchanged** installation you may operate without further obligations, also for customers and for a fee.
* Your ***templates*** and all ***contents*** belong to you and may be under any license – an additional permission in `Core/LICENSING.md` expressly excludes them.
* If you have **changed the core or an AGPL component** and the website is publicly reachable, you must offer visitors the source code of your modified version, for example with a link to a public repository.
