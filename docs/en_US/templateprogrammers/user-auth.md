# CMS:USER_AUTH

Checks a session ID for validity.

```cmscode
(CMS:USER_AUTH [parameter=value …])
```

***Method:*** `Modules\Record\RecordUser::cmsAuth()`

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `sessionid` | `session ID` | – |  | Resumes the session of the frontend user with the given session ID instead of using a cookie. |
| `filter[…][…]` | `see parameter "filter[]" of CMS:SELECT` | – |  | Filter criteria for the profile record that the signed-in frontend user must meet. |
| `mode[…]` | `AND / OR` | `OR` |  | Mode in which values that concern the same field are combined. |
| `cookiename` | `cookie name` | `usess` |  | Name of the session cookie [a-z0-9]. |

`[…]` – the parameter accepts sub-keys, e.g. `name[key]=value`.

## Usage

Takes up the frontend session and provides the signed-in frontend user, ***without*** blocking the
page – for pages that show different content to signed-in and anonymous visitors. Result in
`$set`:

| `$set[…]` | Content |
|---|---|
| `user_id` | record_id of the profile; `0` = not signed in |
| `user` | the profile record as an array (`[]` if not signed in) |
| `user_session_id` | session ID; `''` = no session |
| `password_reset` | `1` after a sign-in via the link from [CMS:USER_PW](user-pw.md), as long as a new password may be set without the previous one; otherwise `0` |

```cms
(CMS:USER_AUTH)
<?php if ($set['user_id']) { ?>
  Hello <?php echo htmlspecialchars($set['user']['nickname']); ?> – <a href="/0,u_logout">sign out</a>
<?php } else { ?>
  <a href="/0,u_login">sign in</a>
<?php } ?>
```

The command belongs at the ***start*** of the template (before any output), so that the session
cookie can be set. For pages that only signed-in frontend users may see,
`CMS:USER_PROTECT` is the appropriate command.

## Parameters in detail

### `sessionid`

Takes up the session by parameter instead of by cookie – for calls from apps or from other
domains:

```cms
(CMS:USER_AUTH sessionid="{$_GET['sid']}")
```

### `filter`

An additional condition on the profile, with the same syntax as `CMS:SELECT`. If it does not
apply, the frontend user counts as not signed in:

```cms
(CMS:USER_AUTH filter[role]=editor)
(CMS:USER_AUTH filter[status_id]=0 filter[newsletter]=1)
```

### `mode`

Combines several values of the same field in `filter` (default OR):

```cms
(CMS:USER_AUTH filter[role]=editor filter[role]=admin mode[role]=OR)
```

### `cookiename`

Name of the session cookie (default `usess`), for example for two separate sign-ins on one
domain:

```cms
(CMS:USER_AUTH cookiename=shop)
```

Must be the same for all `USER_*` commands.

---

[← Overview](README.md)
