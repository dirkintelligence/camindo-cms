# CMS:USER_REGISTER_CONFIRM

Activates a profile record and sets status=0.

```cmscode
(CMS:USER_REGISTER_CONFIRM [parameter=value …])
```

***Method:*** `Modules\Record\RecordUser::cmsRegisterConfirm()`  
***PHP snippet:*** `include_and_stop` (after)

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `successtemplate` | – | – | yes | Detail template that is included if the registration could be completed successfully. |
| `hash` | `hash ID` | – | yes | Hash ID created by CMS:USER_REGISTER and sent by email. |
| `message[…]` | `message` | – |  | Error message. In the index: use case; in the value: message text (see table). |

`[…]` – the parameter accepts sub-keys, e.g. `name[key]=value`.

## Usage

Redeems the token from `CMS:USER_REGISTER` and activates the profile (status 0). It stands on the
page to which the confirmation link points:

```cms
(CMS:USER_REGISTER_CONFIRM hash="{$_GET['hash']}" successtemplate=u_confirmed
  message[hash]="The link is invalid or has expired.")
(CMS:INCLUDE header)
<p class="error">(CMS:MESSAGE)</p>
```

`u_confirmed`:

```
<p>Your account has been activated – <a href="/0,u_login">sign in now</a>.</p>
```

Tokens expire after 24 hours (`user.token_expiration`). After success, `$set['user_id']` and
`$set['user']` are filled. The frontend user is not yet signed in by this.

## Parameters in detail

### `successtemplate`

```cms
(CMS:USER_REGISTER_CONFIRM hash="{$_GET['hash']}" successtemplate=u_confirmed)
```

### `hash`

Token from the link. If it is empty, unknown, expired or already redeemed, the message `hash` appears.
The command has no `submit`:

```cms
(CMS:USER_REGISTER_CONFIRM hash="{$_GET['hash']}" successtemplate=u_confirmed)
```

### `message`

```cms
(CMS:USER_REGISTER_CONFIRM hash="{$_GET['hash']}" successtemplate=u_confirmed message[hash]="This link is no longer valid.")
```

---

[← Overview](README.md)
