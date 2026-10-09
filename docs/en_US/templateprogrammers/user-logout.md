# CMS:USER_LOGOUT

Ends and deletes the session of a signed-in frontend user.

```cmscode
(CMS:USER_LOGOUT [parameter=value …])
```

***Method:*** `Modules\Record\RecordUser::cmsLogout()`

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `submit` | `text value` | – |  | If the parameter is given, the command signs out only if it contains a text (e.g. `submit="$logout"` from the sign-out form). If it is empty, nothing happens – not even a `token` message. Without the parameter, the command signs out on every call (a valid token is assumed). |
| `deletecookie` | `true / false` | `true` |  | false: Only the server-side session is deleted. true: The browser cookie is deleted as well. |
| `cookiename` | `cookie name` | `usess` |  | Name of the session cookie [a-z0-9]. |
| `csrf` | `true / false` | `true` |  | true: The request must contain a valid token from [CMS:USER_TOKEN](user-token.md) (protection against CSRF). false switches off the check for this command – only with good reason. |

## Usage

Ends the session of the signed-in user (including the shopping basket of the session) and deletes
the cookie. Afterwards, `$set['user_id']` = 0:

```cms
(CMS:USER_LOGOUT)
(CMS:INCLUDE header)
<p>You have been signed out. <a href="/0,u_login">Sign in again</a></p>
```

The sign-out is triggered by a POST form with a token – a simple link is not enough, because
otherwise any foreign page could sign out your visitors. Without a valid token, the command does
not sign out and sets the message `token` ([CMS:MESSAGE](message.md)):

```cms
<form method="post" action="/0,u_logout">
  (CMS:USER_TOKEN)
  <button name="logout" value="1">Sign out</button>
</form>
```

So that a direct call of the sign-out page creates no `token` message, specify `submit`. If it is
empty (the page was only called), the command does nothing:

```cms
<?php $logout = $_POST['logout'] ?? ''; ?>
(CMS:USER_LOGOUT submit="$logout")
```

This command, too, belongs before the first output, so that the cookie can be deleted.

## Parameters in detail

### `submit`

Sign out only if the sign-out form has been submitted:

```cms
<?php $logout = $_POST['logout'] ?? ''; ?>
(CMS:USER_LOGOUT submit="$logout")
```

### `deletecookie`

Delete only the server-side session and leave the cookie in place:

```cms
(CMS:USER_LOGOUT deletecookie=false)
```

### `cookiename`

```cms
(CMS:USER_LOGOUT cookiename=shop)
```

---

[← Overview](README.md)
