# 7 Users and rights

Anyone who works in the back end is a ***user*** – regardless of their role: as an editor,
translator, administrator, or programmer. You manage users and their
***user groups*** in the module “Users”. Each user belongs to exactly one user group; it
determines what they may see and do. Users of the group ***Superuser*** have full rights for
everything.

The start page of the module ![person](../_/person.svg) “Users” lists all ***users*** (whom you
may see with your group rights). Superusers are marked with a hexagonal symbol
![identity_platform](../_/identity_platform.svg). Click the button “New” or select an entry from
the list. The input/edit mask for this user appears.

## Input fields

| Item | Meaning |
|---|---|
| User name | sign-in name: begins with a lowercase letter, followed by lowercase letters, digits, and `_`. New names must not begin with `admin_` – reserved for anonymized accounts |
| Password | stored only as a check value (hash) and cannot be read; leave empty = unchanged. It must meet the [password policy](./extensions/password.md) – the requirements appear while you type. When creating, you assign a ***start password***. Only a superuser can change the password of another existing user |
| Previous password,<br>your password | only for superusers when changing an existing account: anyone who changes their ***own*** password enters the previous one; anyone who changes the password of ***another*** user confirms this with their own password – an open session is therefore not enough to take over an account. When creating, this field is omitted. Everyone else changes their password in the personal settings |
| User group | user group with its rights |
| Status | “Released” or “Deactivated” – a deactivated user cannot sign in |
| Main (last) name,<br>Displayed name,<br>Sort value | names for lists and messages |
| E-mail,<br>mobile,<br>landline number | contact data, stored encrypted. The e-mail address is required for the sign-in code of the two-factor sign-in |

Each user maintains their personal settings (language, date format, time zone, widgets,
password, devices …) themselves; a superuser can also change them for others.

***Deleting users:*** Deleted users are retained as “deleted”, so that records and the audit
log continue to show who created and edited them. When deleting, camindo CMS removes
everything with which the account could still sign in: its sessions (it is signed out
immediately), the saved devices, passkeys and recovery codes, as well as the list of recently
edited records and templates. The entries in the audit log remain. Your own account cannot be
deleted. To remove the name and contact data of a deleted user, use ***Anonymize*** (see
[module “Privacy”, tab “Deleted users”](./10-modules.md#privacy)).

***Must change password:*** A password that another user has set must be changed at the first
sign-in – for example the start password when creating the account, or one reset by a
superuser. The configuration setting `admin.password_retention_days` (0 = unlimited) likewise
forces a password change after the set number of days. A new password must then differ from the
previous one. The detail view shows under “Password” when it was last changed and whether a
change is pending.

## User groups

Only superusers see the tab ***“User groups”***. The list shows all existing ![group](../_/group.svg)
user groups. After installation, there is only one group, “Superuser”.

***Creating a user group:*** For daily work, a separate group with restricted rights should be
created, for example the group “Translators”, “Editors” or “Interns”. To do so, click the button “New”.

***Deleting a user group:*** If users are still assigned to the user group, choose a
_replacement group_ when deleting, to which these users are assigned. Deleted users of the
group lose their group assignment in the process – without a prompt; if the group has only
deleted users, no selection appears. The group “Superuser” is not available for selection.

### Interfaces

Under ***“Interfaces”***, you determine through which channels the users of the user group may sign in:

| Interface | Purpose |
|---|---|
| Back end | the familiar work surface in the browser |
| Command line (CLI) | programs on the server, for example `php Core/cron/camindo.php api …` (see [Operation](./12-operation.md#the-command-line)) – without a password, because anyone who can operate the command line of the server has full access in any case |
| PHP API | programs that sign in with name and password |

The same rights of the user group apply on every interface; the audit log shows the user
together with the channel (for example `cli`). A new user group has only the back end. If you
withdraw an interface from a user group, ongoing sessions there end at the next access. For
programs, it is recommended to use a separate user in a separate user group that has only the
necessary interfaces and rights.

The rights of a user group have four levels:

### 1. Modules and additional rights

Which modules the user group may open. Some modules have ***additional rights*** for individual
parts, indented below them – without an additional right, the part remains blocked:

| Module | Additional rights |
|---|---|
| Users | “Read user details: own user group” · “Read user details: other user groups” · “Create users: own user group (with start password)” · “Change and lock users: own user group (without password)” |
| Privacy | “Create and change deletion policies” (only for accessible instances) · “Export personal data” (profiles from readable areas, users from visible user groups) · “Maintain templates for data disclosure” (accessible instances) · “Anonymize deleted users” (accessible user groups) – see [chapter 15](./15-privacy.md) |
| Content | “CSV import”, “Media import (ZIP file)”, “CSV export”, “DOCX import (translation)”, “DOCX export (translation)”, “Batch processing” |
| Tools | one right each for the tabs “Server”, “Database”, “Data”, “Developer” and “Trash of a user” (“Permanently empty the trash of a website user”) |

Reserved exclusively for superusers are: the management of user groups, the area rights, the
XML export and import (backup and structure transfer), and the module “Log files”.

Without an entry in the user group, every user has access to internal messages, personal
settings, and the [trash](./10-modules.md#trash). In the trash, a user may restore only what they
would be allowed to delete in that area.

### 2. Instances

Which instances (websites) the user group sees. If you assign an instance to a user group, it
initially receives all areas of this instance with all rights (except “Adjust owner”). You can
restrict this afterwards in the tab “[Area rights](#5-area-rights)”.

### 3. Languages

Two check boxes per language: ***read*** and ***write***. Anyone who may write may also read – a
check mark for “write” also sets “read”. A click on the column heading “read” or “write” ticks the
whole column – or unticks it, if all check marks are already set there. Without check marks, the
user group has no access to the language.

The setting applies to all instances and to the content in the module “Content”: only readable
languages appear in the input mask, only writable ones can be changed there. In this way, a
translator can read all languages but edit only French.

### 4. Set status

One check box per status: the ticked statuses may be assigned to a record by users of the group.
***Reading*** is permitted for all statuses – only ***setting*** is restricted. In this way, you
map a ***flat workflow***, for example: the editorial team may create posts and set them to
“Pending review”, but may not release them – that is done by the editor-in-chief. Forwarding happens
via internal messages. There are two ways to set access rights here:
(here) in the user group, you select all statuses for a group; in the module “Status”, you select
all user groups for a status.

Status in the record input mask:

- The input field “Status” shows only statuses that the user may set.
- A record whose current status cannot be set by the user cannot be ***edited*** by them: the
  button “Edit” is grayed out, and the entry is missing from the context menu of the list. Reading,
  copying, and – depending on the area right – deleting remain possible.

### 5. Area rights

After a user group has been created, its info page shows the tab “Area rights”.
Here it is listed what the users of this group may do with the records of different areas.
Click the button “Edit” to make adjustments.

| Right | Meaning |
|---|---|
| Read | the area is visible for the user group – without this right, it does not exist for them |
| Own user group: write / delete | edit or delete records whose [owner](#the-owner-principle) belongs to the own user group |
| Other user group: write / delete | the same for records whose owner belongs to another user group |
| Advanced (adjust owner) | change the owner of a record (selection at the end of the input mask) |

Template areas know only read and write. Folders have no rights of their own.

You maintain the area rights at two equivalent places:

- (here) in the module “Users”, tab “Area rights”: all areas for one user group,
- in the module “Structure”, tab “Access”: all user groups for one area.

## The owner principle

Each record belongs to a user, its ***owner*** – initially the one who created it. Via the user
group of the owner, the area rights distinguish between own and foreign content. Examples:

- ***Two editorial teams, one area:*** the user groups “Editorial team North” and “Editorial team South”
  may read all news items, but only write and delete those of their own user group.
- ***Release by the editor-in-chief:*** the user group “Authors” may write their own articles but
  not delete them; the “Editor-in-chief” may write and delete all articles.
- ***Handover:*** when an employee leaves the editorial team, a user with the right “Adjust owner” changes
  the owner of their records.

Records that frontend users create receive the owner that the template or the configuration
specifies (see [chapter 13](./13-community.md)).

## What the rights do

The interface shows only what is released for the user group: modules, areas, buttons, menu
entries, languages. Regardless of this, camindo CMS checks every action also when saving and
deleting – a right therefore cannot be bypassed by a manipulated request. Background processes
(import, export, batch processing) run with the rights of the user who started them.

Relation fields whose target areas the user group may not read do not appear in the mask; their
relations remain untouched when saving.
Likewise, multilingual content that the user group may edit only in selected languages: the
other content remains intact when saving.

## Sign-in protection

- ***Two-factor sign-in:*** After the password, a code is sent to the user's e-mail address.
  Switch it on in the configuration (see [chapter 12](./12-operation.md#configuration)).
- ***Authenticator app (TOTP):*** Each user can set up an authenticator app in their personal
  settings; then the sign-in asks for its code instead of the e-mail code. Ten recovery codes are
  also provided (instructions in the [handbook for editors](../editors/02-sign-in.md#set-up-an-authenticator-app)).  
  With `security.admin_2fa = 'totp_required'`, it is ***mandatory for superusers*** – anyone who does
  not yet have it must set it up at the next sign-in in the sign-in dialog. With `'email'`, it is
  switched off (see [chapter 12](./12-operation.md#two-factor-sign-in)).
- ***Passkeys:*** With a fixed back-end domain, each user can create passkeys (fingerprint, face,
  device PIN, security key) – the most secure form of the second factor
  ([handbook for editors](../editors/02-sign-in.md#set-up-a-passkey)).
  Resetting as described below also removes them.
- ***Reset the second factor:*** If a user has lost their phone or passkeys and their recovery codes,
  a superuser opens the user's detail view, tab ***“Two-factor sign-in”***, and clicks “Reset second
  factor” (removes the app and passkeys). The user then signs in again with the e-mail code (or sets up
  the app again if it is mandatory) and receives an e-mail about it. The reset is recorded in the
  audit log with the name of the superuser. Make sure beforehand – for example by phone – that the
  request really comes from the account holder.
- ***Switch back to the e-mail code:*** Anyone who no longer wants to use the authenticator app switches
  back in their settings with “Switch back to the code by e-mail” (confirmed with a code from the app).
  If the e-mail code is not switched on for everyone, each user may switch it on for themselves.  
  (If the app is mandatory for all users by configuration, it cannot be switched back to the e-mail
  code.)
- ***Notice for a new device:*** If a user signs in from a browser that camindo CMS does not yet know,
  an e-mail is sent to their address – including the device, IP address, and time (see
  [chapter 12](./12-operation.md#passwords-and-sign-in-devices)).
- ***Devices and sessions:*** Each user sees their sign-in devices with the running sessions in their
  personal settings, can remove individual devices (their sessions end immediately), and end all
  other sessions. A superuser can, in the detail view of another user, tab “Devices and sessions”,
  end all of their devices and sessions with “Remove all devices” – for example after a lost laptop.
  Both are recorded in the audit log. “Sign out” ends only the session in one's own browser.
- ***Activities:*** A superuser sees what a user has changed in their detail view, tab “Activities” –
  the recently edited records and all their entries in the [audit log](./10-modules.md#audit-log).
- ***A new password ends other sessions:*** When a password is changed (by the user themselves or by the
  superuser), all other sessions of the account end.
- ***Protection against password guessing:*** After several failed attempts (default: 3 in 10 minutes),
  sign-in is blocked for the user name. Blocks are recorded in the security log.
- ***Automatic sign-out*** after 30 minutes without activity (configurable).
- Rights and sessions are signed directly in the database to prevent manipulation: anyone who has only
  access to the database cannot use it to acquire rights.
