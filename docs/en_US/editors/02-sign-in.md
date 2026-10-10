# 2 Signing in and signing out

You reach the backend of camindo CMS via the path `/backend` on your website, for
example:

```
https://www.your-website.com/backend/
```

Often the backend is reachable under a separate domain or a subdomain of your website,
for example:

```
https://subdomain.your-website.com/backend/
```

Ask your administrator for the correct address.  
Access always requires signing in. Enter your user name and password and click
“Submit”.

## Two-factor sign-in

According to current standards, your administrator should enable two-factor sign-in.
Without a second factor you have no access to the backend, even with the correct
password.  
camindo CMS offers four ways to provide the second factor:

- Code by email
- Code from an authenticator app (TOTP)
- Passkey (WebAuthn)
- Known device

## Sign-in code by email

After you enter your password, camindo CMS sends a code to the email address of your
user account. Enter this code in the next step. The code is valid for only a few minutes
(ten by default). If it has expired, simply sign in again.

## Code from the authenticator app

If you have set up an ***authenticator app*** (see below), camindo CMS asks for the
six-digit code that the app currently displays after your password. The code changes
every 30 seconds. Each code is valid for only one sign-in.

If you do not have your phone at hand, enter one of your ***recovery codes*** instead
(format `ABCDE-FGHJK`). Each one can be used exactly once. You then receive an email
telling you how many are still left. If the phone is lost or no code is left, your
administrator can reset the app for your account. You then sign in again with the email
code and set up the app anew.

A wrong code counts like a wrong password (see [Blocked sign-in](#blocked-sign-in)).
After a failed attempt you start again at the password.

## Confirm with passkey

If you have set up a ***passkey*** (see below), the sign-in dialog shows the button
***“Confirm with passkey”*** after the password. Your browser or device then asks for
your fingerprint, face, device PIN or security key – nothing more is needed. Without the
device, enter a recovery code in the field below (or the code from your authenticator
app, if you have set that up as well).

## Set up a passkey

A ***passkey*** is a convenient and secure form of authentication. Because the passkey is
bound to the domain of the backend, it is resistant to phishing and to the interception
of sign-in data. It is stored directly on your device (for example via Windows Hello,
Touch ID/Face ID or Android), in a password manager or on a hardware security key (for
example a YubiKey).

1. Open your personal settings and click the button next to
   ***“Two-factor sign-in”***.
2. Under ***“Passkeys”*** click ***“Add passkey”***, give it a name (for example
   “Office computer”) and confirm with your password.
3. Click ***“Create passkey now”*** and follow the instructions of your browser.
4. If this is your first passkey and you do not yet have recovery codes, ten of them
   appear. Store them in a safe place.

It is best to set up ***two passkeys***, for example on your computer and on your
phone. On the same page you can remove a passkey again (with your password). You receive
an email for every new and every removed passkey.  
Note for administrators: passkeys are only available if the backend runs under a fixed
address of its own – otherwise the page shows a notice.

## Set up an authenticator app

An authenticator app is much more secure than the code by email: the code is generated
on your phone and does not pass through any mailbox. Any common app is suitable, for
example Google Authenticator, Microsoft Authenticator, 2FAS (iOS & Android) or Aegis
(Android).

1. Open your [personal settings](./03-work-surface.md#personal-settings) (gear
   icon ![settings](../_/settings.svg)) and click “Authenticator app” next to
   ***“Two-factor sign-in”***.
2. Click ***“Set up authenticator app”*** and scan the QR code with the app. If that does
   not work, type in the key shown below it.
3. Enter the six-digit code that the app now displays and click ***“Activate”***. Only
   then is the app switched on.
4. Ten ***recovery codes*** appear. Store them safely – in a password manager or printed
   out, not on the phone with the app. They are shown only once.

On the same page you can later generate a ***new set of recovery codes*** (the old ones
expire), ***switch back to the code by email*** (“Switch to code by email”) or ***switch
off the app***. You confirm all of these with a code from the app or a recovery code. You
receive an email about every change – if you receive one you did not expect, change
your password immediately.

## Turn on the email code yourself

If two-factor sign-in is disabled (not recommended), you can choose the email code for
your account yourself: in the personal settings, under “Two-factor sign-in”, click
“Authenticator app” and then ***“Turn on code by email”***. camindo CMS sends you a
five-digit code; the choice only takes effect once you enter it. You switch it off the
same way.

If your administrator has made the app **mandatory**, you set it up directly at sign-in:
after the password (and the email code, if applicable), the sign-in dialog shows the QR
code and then your recovery codes. In that case it cannot be switched off.

## Notice for a new device

If you sign in on a computer, phone or browser that camindo CMS does not yet know from
you, you receive an email “New sign-in” with the device, IP address and time. If it was
you, nothing needs to be done. If it was not, change your password immediately and inform
your administrator.  
By the way: even after deleting the browser cookies or in a private window, the browser
counts as a new device.

## Change password

You change your own password in your [personal settings](./03-work-surface.md#personal-settings)
(gear icon ![settings](../_/settings.svg)) under ***“Password”*** with “Change password”:
enter your ***“Current password”*** to confirm, then enter the new password twice. The new
password must differ from the current one. After saving, your sessions on other devices
end, and you receive an email. While you type, a speech bubble shows what the password
requires (by default at least 12 characters, one special character, an uppercase letter
that is not the first character, and a lowercase letter). A bar below shows the password
strength.  
Tip: several words separated by punctuation marks are long, strong and easy to remember.

### New password at sign-in

In two cases the sign-in dialog asks for a **new password** – together with the password
and the code – before it continues:

- ***Initial password:*** another user has created your account or reset your password.
  Now choose your own password. Nobody but you should know it.
- ***Expired password:*** your administrator has set a validity period, and it has run
  out. The new password must differ from the previous one.

## Devices and sessions

The personal settings under ***“Devices and sessions”*** show the devices you have
signed in from and how many sessions are currently running on each. The device you are
using is marked “this device”. For each device, the following is stored: browser and
operating system, IP address, and the time of the first and last sign-in.

- ***“Remove”*** deletes a device you do not know or no longer use – its sessions end
  immediately. If someone signs in from there later, it counts as new again, and you
  receive an email. If you remove your own device, you are signed out.
- ***“End all other sessions”*** signs you out everywhere else – for example if you
  forgot to sign out on a computer you do not own. Input forms still open there are
  discarded, just as when you sign out on all devices, and the records are released for
  others immediately. The forms in this window stay open.

## Blocked sign-in

After several failed attempts (by default three within ten minutes), camindo CMS
temporarily blocks sign-in for this user name. The message states the waiting time.
Before the last permitted attempt you receive a warning.

## Settings in the sign-in form

Below the credentials there are three settings. They take effect immediately and can be
changed at any time later in the [personal settings](./03-work-surface.md#personal-settings).

### Language

The language of the backend user interface is independent of the languages of your
website.  
The following backend languages are available after installation (more can be installed
later):

- Arabic
- Bengali
- Chinese (Mainland)
- German
- English
- French
- Hindi
- Japanese
- Korean
- Portuguese (Brazil)
- Spanish
- Urdu

### Date format

Choose the ***locale*** whose notation you are familiar with. Date, time and number
values follow it – both in the display and when you enter them.

Examples of date, time and number formats can be found in the section
[Date and time](./05-editing-records.md#date-and-time).

### Time zone

camindo CMS stores points in time in a uniform world time (***UTC***). If you enter
“18:00”, the system needs to know which time zone this refers to. Conversely, it converts
stored points in time into your time zone for display. This way, colleagues in different
time zones each see the same point in time in their local time.

Example:

|  | Date format | Time zone | Value |
|---|---|---|---|
| Input | en_US | America/New_York (UTC−5) | `12/31/2026 9:00 PM` |
| stored | | UTC | `2027-01-01 02:00` |
| displayed | de_DE | Europe/Berlin (UTC+1) | `01.01.2027 03:00` |

Converted date-time values get the time zone appended, for example:

```
27.09.2026, 15:06:59 Europe/Berlin
```

Not converted are plain times and fields of the type “Date + time” without a time zone
reference – for example opening hours, which apply everywhere in the same way.

## Automatic sign-out

After a period without activity (30 minutes by default), camindo CMS signs you out
automatically. Background checks for new messages do not count as activity. The sign-in
window then appears over the work surface. After you sign in again, you continue at the
same place – ***unsaved input remains in the input form.***

## Logout

Click the icon ![logout](../_/logout.svg) in the sidebar and confirm the prompt. Only this
browser is signed out. Input forms that you have open on another device remain there.
Frontend editing that you switched on “until sign-out” ends with it.

What the checkbox ***“Sign out on all devices”*** is good for:  
It makes sure that all your sessions are ended and that records which were locked by
mistake (an input form that was not closed properly) are released for others at once. If
you find an open input form later and want to save it, this is no longer possible.

You end individual devices under [Devices and sessions](#devices-and-sessions).
