# CMS:USER_DELETE

Deletes a record that belongs to the signed-in frontend user.

```cmscode
(CMS:USER_DELETE id [parameter=value …])
```

***Method:*** `Modules\Record\RecordUser::cmsDelete()`  
***Without a key:*** the first value without `=` counts as `id`  
***PHP snippet:*** `include_and_stop` (after)

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `id` ¹ | `record ID` | – | yes | Record to be deleted. |
| `ignoreuser` | `true / false` | `false` |  | true: The record to be deleted need not belong to the signed-in frontend user (caution: observe the security notes!). |
| `successtemplate` | `template name` | – | yes | Template that is included when the entry has been deleted successfully. |
| `message_error` | `text` | `Access denied!` |  | Error message if the user has no access to the record. |
| `csrf` | `true / false` | `true` |  | true: The request must contain a valid token from [CMS:USER_TOKEN](user-token.md) (protection against CSRF). false switches off the check for this command – only with good reason. |

¹ can be given without a key as the first value.

## Usage

Deletes a record that belongs to the signed-in frontend user (`record.user_id`) – or the user's own
profile. In the latter case, all sessions and known devices of the user are removed as well.

What “delete” means is determined by the ***deletion policy*** of the area, with its blocks for the
trigger “record is deleted”. This is the same as when deleting in the back end: into the trash,
permanently, or overwriting individual fields (for example setting the status to “Disabled” and
the sign-in name to `user_{record_id}`), also with a time delay. If the user deletes their profile,
all of their contributions are deleted at the same time according to their policy (trigger “UGC:
profile was deleted”). If the policy has no block for this trigger, the command outputs an error
message. See [deletion policies](../admins/15-privacy.md#deleting-according-to-deletion-policy).

A record that is already marked for deletion cannot be deleted again.

```cms
<?php $id = (int)($_POST['id'] ?? 0); if ($id > 0) { ?>
(CMS:USER_DELETE id="$id" successtemplate=u_deleted message_error="This entry does not belong to you.")
<?php } ?>
(CMS:MESSAGE)
<form method="post">
  (CMS:USER_TOKEN)
  <input type="hidden" name="id" value="<?php echo (int)$_GET['id']; ?>">
  <button>Permanently delete entry</button>
</form>
```

The command has no `submit`: it runs on every call and reports an error if there is no valid `id`.
That is why the PHP condition surrounds the command (CMS commands are turned into PHP code, so an
enclosing `if` is allowed). After success, `successtemplate` is included and the page ends (except
with `CMS:CONTINUE`).

## Parameters in detail

### `id`

The record ID; it may stand without a key. Without a valid ID, the message from `message_error`
appears:

```cms
(CMS:USER_DELETE "{$_POST['id']}" successtemplate=u_deleted)
(CMS:USER_DELETE id="{$set['user_id']}" successtemplate=u_account_closed)     delete own profile
```

### `ignoreuser`

Skips the ownership check and the sign-in. The template is then responsible (for example for
deletion via a secret link):

```cms
(CMS:USER_DELETE id="{$id}" ignoreuser=true successtemplate=u_deleted)
```

### `successtemplate`

```cms
(CMS:USER_DELETE id="$id" successtemplate=u_deleted)
```

`u_deleted` can, for example, output “Entry deleted” and show the navigation via `CMS:INCLUDE`.

### `message_error`

Text shown if the record does not exist, does not belong to the frontend user, or nobody is signed
in (output via `CMS:MESSAGE`). If the token from `CMS:USER_TOKEN` is missing, the standard message
for an expired form appears instead:

```cms
(CMS:USER_DELETE id="$id" successtemplate=u_deleted message_error="Deletion is not possible.")
```

---

[← Overview](README.md)
