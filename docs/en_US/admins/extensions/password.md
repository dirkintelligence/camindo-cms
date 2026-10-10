# Extension Password (Password policy)

Core extension for fields of the type “Password”. Below the field, a bar shows the
***strength*** of the entered password (red = weak, orange = medium, green = strong);
while you type, a ***speech bubble*** with the requirements appears, and fulfilled ones are
checked off. As long as a requirement is missing, the bar stays red.

Always available, no installation needed. The passwords of the users use it
automatically.

## Check on the server

The display is only an aid. What matters is that camindo CMS checks the same rules
when saving – and it does so for a field exactly when the field has this extension.
A password that is too weak is rejected with a message naming the missing
requirements (“at least 2 special characters, …”).

## Settings per field

Each rule can be set differently on the field. Empty fields use the default
of the installation (`security.password_policy`, see
[Operation](../12-operation.md#passwords-and-sign-in-devices)) – it is shown in gray in the field.

| Setting | Default | Meaning |
|---|---|---|
| Minimum length | 12 | number of characters |
| Special characters (minimum) | 1 | anything that is neither a letter nor a digit – including spaces, hyphens, periods |
| Uppercase letters (minimum) | 1 | including umlauts and letters of other scripts |
| Lowercase letters (minimum) | 1 | |
| Digits (minimum) | 0 | |
| Uppercase letter at the start does not count | yes | “Summer2026!” does not meet the rule just because of the capital S |

`0` means: no requirement.

## In the frontend

The registration and the customer account of a website use the same script. The
template programmer copies it into the template and marks the field – see
[CMS:USER_PASSWORD_RULES](../../templateprogrammers/user-password-rules.md).
