# CMS:USER_PROTECT

Protects a template from access by visitors who are not signed in.

```cmscode
(CMS:USER_PROTECT [parameter=value …])
```

***Method:*** `Modules\Record\RecordUser::cmsProtect()`  
***PHP snippet:*** `include_and_stop` (after)

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `errortemplate` | `template name` | – | yes | Detail template that is included if the frontend user has no access to the container or is not signed in. |
| `filter[…][…]` | `see parameter "filter[]" of CMS:SELECT` | – |  | Filter criteria for the profile record that the signed-in frontend user must meet. |
| `sessionid` | `session ID` | – |  | Resumes the session of the frontend user with the given session ID instead of using a cookie. |
| `cookiename` | `cookie name` | `usess` |  | Name of the session cookie [a-z0-9]. |

`[…]` – the parameter accepts sub-keys, e.g. `name[key]=value`.

## Usage

Blocks a template for visitors who are not signed in. If nobody is signed in (or `filter` does not
apply), `errortemplate` is included and the page ends. Otherwise the template continues, and
`$set['user_id']` and `$set['user']` are filled:

```cms
(CMS:USER_PROTECT errortemplate=u_login)
(CMS:INCLUDE header)
<h1>My account</h1>
<p>Signed in as <?php echo htmlspecialchars($set['user']['nickname']); ?></p>
```

Only for a group of frontend users (profile field `role`):

```cms
(CMS:USER_PROTECT errortemplate=u_denied filter[role]=editor)
```

The `errortemplate` is typically the sign-in form itself – so that the visitor lands back on the
protected page after signing in.

## Parameters in detail

### `errortemplate`

Template for visitors who are not signed in (or when `filter` does not apply):

```cms
(CMS:USER_PROTECT errortemplate=u_login)
(CMS:USER_PROTECT errortemplate=templates.u_denied filter[role]=editor)
```

### `filter`

An additional condition on the profile, with the same syntax as `CMS:SELECT`:

```cms
(CMS:USER_PROTECT errortemplate=u_denied filter[role]=editor)
(CMS:USER_PROTECT errortemplate=u_denied filter[paid_until] >= "{$today}")
```

### `sessionid`

```cms
(CMS:USER_PROTECT errortemplate=api_denied sessionid="{$_GET['sid']}")
```

### `cookiename`

```cms
(CMS:USER_PROTECT errortemplate=u_login cookiename=shop)
```

---

[← Overview](README.md)
