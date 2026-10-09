# 13 Community features

With the community features, frontend users can register, sign in, maintain their profile and create contents themselves – comments, ratings, classified ads, images. Protected areas and files are open only to signed-in frontend users; a shopping cart remembers selected items.

Registered visitors are called ***frontend users*** in camindo CMS – in contrast to the ***users*** in the backend. A frontend user has no user account in the backend: their digital image in the CMS is a ***profile record***, an ordinary record like their contributions. As a result, all tools of the editorial team apply to them – search, batch processing, export, rights.

## Profile areas

An area of the type ***PROFILE*** contains one record per frontend user. The blueprint “Members” creates such an area with the fields name, email and password. Further profile fields – avatar, address, interests – you add as usual.

Under ***“Sign-in to the website”*** you define:

| Setting | Meaning |
|---|---|
| Name | the field with the sign-in name, type “Short text” (also encrypted), for example the email address |
| Password | the field of the type “Password”, against which the sign-in is checked |
| Second factor | whether the sign-in additionally requires a code – see [below](#second-factor) |

Both fields can be selected only after they have been created.

Assign the extension “Password policy” to the password field (section “Extension” of the field definition). Only then does camindo CMS reject weak passwords at registration and when changing the password. The rules are those from [chapter 12](./12-operation.md#passwords-and-sign-in-devices); you can set individual values differently on the field. The template programmer displays the same rules in the form ([CMS:USER_PASSWORD_RULES](../templateprogrammers/user-password-rules.md)).

Without the extension, the password strength can be controlled only by minimum length (minimum) and pattern (RegEx) in the settings of the input field.

## Contribution areas

An area of the type ***UGC*** (“user-generated content”) takes in contributions of signed-in frontend users. The blueprint “Comments” creates such an area with a name and comment text. Under ***“Authentication”*** you select the ***profile containers*** whose frontend users may create contributions here. Each contribution is linked to the profile of its author. A frontend user may change and delete only their own contributions.

## The templates

Registration, sign-in, password reset and the saving of contributions are handled by templates with the commands `CMS:USER_*` – see the [manual for template programmers](../templateprogrammers/README.md#community).

The sequence of a registration:

1. The visitor fills in the registration form. camindo CMS creates their profile with the status ***“Unconfirmed”*** and generates a one-time code; the template sends it as a confirmation link by email (command [CMS:EMAIL](../templateprogrammers/email.md)).
2. A click on the link enables the profile; the frontend user can sign in.
3. If they forget their password, they request a link for resetting it in the same way.

The links are valid for a limited time (default: 24 hours for the confirmation, one hour for the password reset) and can be used only once.

### Sign-in devices and passwords

- ***New password only with the current one.*** In the customer account, a frontend user changes their password only if they enter the current one. Whoever gains access to an open session cannot take over the account in this way. The exception is the link from “Forgot password”: it counts as proof, the current password is no longer required.
- ***“Forgot password” works on every device*** – common and sensible, because people often forget their password precisely on a new computer or phone. The security lies in the link: it goes only to the stored email address, is valid for one hour and only once.
- ***After a new password*** all other sign-ins of the user are ended. If the user is to be informed about the password change (if they did not make it themselves), the template that is displayed after saving the profile must take care of the notification, for example by means of CMS:EMAIL.
- ***Sign-in from a new device:*** The website can send the frontend user a note when their account signs in from an unknown browser. The template sends the email; alternatively a separate notification route, for example by SMS ([chapter 12](./12-operation.md#passwords-and-sign-in-devices)).

### Second factor

A customer account should not depend on the password alone. With the setting ***“Second factor”*** in the profile area, sign-in additionally requires a six-digit code:

| Setting | Effect |
|---|---|
| Off | as before, only the password |
| Optional | frontend users can switch on an authenticator app or the code by email themselves in the customer account |
| Code for new device | as “Optional”. In addition, the website sends a code by email if someone signs in from an ***unknown device*** (browser). On the usual devices, the password remains sufficient. Recommended for shops |
| Always | every sign-in requires a code: by email or – if set up – from the app |

The frontend user sets up an ***authenticator app*** (Google Authenticator, Microsoft Authenticator, 1Password …) in the customer account by scanning a QR code, typing in the displayed key or opening a link on the phone. They receive ten ***recovery codes*** in case they lose their phone. If they no longer have these either, reset the second factor in the backend: open the profile record, in the menu on the right choose ***“Second factor”*** → “Reset second factor” (write permission for the area required; the process appears in the audit log). Check beforehand whether the request really comes from the holder.

If an app is set up, the link from “Forgot password” also requires the code – otherwise access to the email mailbox would have sufficed to take over the account.

The code is sent by email – like all emails of the community features – by the template. If it is to go by ***SMS***, the template programmer calls an SMS service there instead of the email.

The templates for this ([CMS:USER_LOGIN_CODE](../templateprogrammers/user-login-code.md), [CMS:USER_2FA](../templateprogrammers/user-2fa.md)) must be present before you switch on the setting – otherwise every sign-in that would need a code fails.

### Protecting the forms

“Forgot password” and registration send emails to addresses that someone has typed in. So that nobody floods foreign mailboxes via your website, camindo CMS limits the requests (***throttling***) – per entered address, per visitor (IP address) and per website. In addition there is a ***form protection*** against robots, with a block per IP address against the mass trying out of passwords. The limits are set in the configuration (below). The defaults suit most websites.

***Registrations never confirmed*** are deleted after seven days – whoever enters someone else’s address blocks it only briefly in this way.

> If the website runs behind a ***proxy or load balancer***, enter it under `security.trusted_proxies` ([chapter 12](./12-operation.md#configuration)) – otherwise camindo CMS sees all visitors with the address of the proxy and blocks all of them at once during throttling.

## Review and owner

Everything that a frontend user creates or changes is marked with ***“Review required”*** and appears in the widget “Unchecked content of frontend users” of the editorial team (see the [manual for editors](../editors/10-visitor-contributions.md)). Create the meta field “Review required” in the profile and contribution areas, so that the editorial team can remove the marking after the review.

Each record needs an ***owner*** in the backend (see [chapter 7](./07-users-and-rights.md#the-owner-principle)). For records of frontend users, the template names it (parameter `owner`) or the configuration (`user.default_owner_id`). If neither exists, the command cannot save. Via the user group of this owner you control which editorial team is responsible for the contents of the frontend users.

## Protected files

Upload and thumbnail fields have a section ***“Authentication”***. With ***“Only for signed-in frontend users”*** and the selection of the eligible profile containers, the directory of the field is blocked on the server. The files are then delivered only to signed-in frontend users from these profile areas – for example member images, documents for course participants or downloads for customers. Everyone else receives a “not found” response.

## Data export and deletion

If a website user requests information about their stored data (Art. 15 GDPR) or a copy to take along (Art. 20 GDPR), you create an ***export of personal data***: at the profile record in the menu on the right ![more_vert](../_/more_vert.svg) or in the module “Privacy” (see [chapter 15](./15-privacy.md#exporting-personal-data)). The export is a partial extract that you complete before passing it on. Website users cannot download their data themselves.

***Deleting (Art. 17 GDPR):*** What happens when a profile and its contributions are deleted is determined by the ***deletion policy*** of the area – put into the trash, delete permanently, overwrite individual fields, also with a delay (see [chapter 15](./15-privacy.md#deleting-according-to-deletion-policy)). The contributions of the user are always deleted according to the policy of their own area (trigger “UGC: profile was deleted”). The deletion dialog shows what the policy does with the record. If the trash of a user must be emptied immediately: “Tools” → “Trash of a user” ([chapter 15](./15-privacy.md#emptying-a-website-users-trash-immediately)). If all data including the change history (audit log) must be removed: [`camindo.php audit delete`](./12-operation.md#the-command-line).

What camindo CMS stores in total about website users is described in [chapter 15](./15-privacy.md#website-users-community).

## Configuration

The community settings are in `.camindo.php` under `user` (see [chapter 12](./12-operation.md#configuration)):

| Key | Default | Meaning |
|---|---|---|
| `user.max_inactive_sec` | `86400` | automatic sign-out of a user after inactivity (seconds) |
| `user.basket_days` | `30` | retention of the shopping cart of visitors who are not signed in (days) |
| `user.token_expiration` | `86400` | validity of the confirmation link after registration (seconds) |
| `user.password_token_expiration` | `3600` | validity of the link “Forgot password” and of the period afterwards in which the new password can be set without the current one (seconds) |
| `user.bruteforce_max` / `bruteforce_delay` | `5` / `600` | permitted failed attempts at sign-in and blocking period (seconds) |
| `user.cookie_secure` | `true` | session cookie only over HTTPS. `false` only for a local test without SSL |
| `user.default_owner_id` | `0` | user to whom records of frontend users belong if the template names no owner |
| `user.throttle` | see below | throttling: per rule `[at most, seconds]`, at most `0` switches it off |
| `user.formguard_min_seconds` / `formguard_max_seconds` | `3` / `3600` | form protection: at the earliest or at the latest this many seconds after the call may a protected form be sent. A template can set the minimum time differently per form |
| `user.unconfirmed_days` | `7` | delete registrations never confirmed after this many days (`0` = never) |
| `user.login_code_expiration` | `600` | validity of the sign-in code (seconds) |

Defaults of the throttling (`user.throttle`):

| Rule | Default | counts |
|---|---|---|
| `pw_value` | 3 per hour | “Forgot password” per entered address |
| `pw_ip` | 10 per hour | “Forgot password” per visitor (IP) |
| `register_ip` | 5 per hour | registrations per visitor |
| `register_value` | 3 per day | registrations per email address |
| `mail_instance` | 200 per hour | both together per website |
| `login_ip` | 30 per 10 minutes | wrong passwords per visitor, across all accounts |
| `login_code` | 3 per 10 minutes | “Send new code” per account |
| `email_ip` | 5 per hour | contact forms with throttling ([CMS:EMAIL](../templateprogrammers/email.md) `throttle=true`) |
| `csp_report` | 60 per 10 minutes | reports of the script policy per visitor |
| `zip_ip` | 30 per hour | newly created ZIP downloads ([CMS:ZIP](../templateprogrammers/zip.md)) per visitor |

Change one rule individually, for example `'throttle' => ['pw_ip' => [20, 3600]]`.
