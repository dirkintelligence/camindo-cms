# 3 Trigger templates

A ***trigger template*** contains PHP methods that camindo CMS calls on events of the records of a
content area – when creating, saving, deleting, restoring, and for the actions of a deletion
policy. In this way, contents can be prepared on saving (combine several fields into one, check
values), linked data can be updated, or personal data can be anonymized according to your own rules.

The methods run regardless of where the change comes from: input form, frontend (`CMS:SAVEDATA`,
`CMS:USER_…`), API, import, batch processing, the trash cleanup job, and deletion policies.

## Assignment

A trigger template is an ordinary template in a template area. You assign it in the settings of the
content area under ***“Trigger templates”*** (see [Handbook for administrators](../admins/04-structure.md#settings-of-a-content-area)).
An area can have ***several trigger templates***, and the same template may be assigned to several
areas. In this way, reusable building blocks can be formed – for example a template that maintains
slugs in all areas, and alongside it one with the rules of a single area.

### Order

The templates of an area run ***one after another in the displayed order*** for each event; you
change the order with the arrow buttons ![arrow_upward](../_/arrow_upward.svg) ![arrow_downward](../_/arrow_downward.svg)
next to each entry.

- A template without the method of the event is skipped.
- All of them receive the same variables: what one template changes in `$data` or `$affectedFields`,
  the next one already sees.
- If a template throws an exception, the following ones no longer run, and the process is cancelled
  (see [Cancelling](#cancelling)).
- Each template is an object of its own: properties and helper methods with the same name in
  different templates do not interfere with each other. Exchange data between templates via `$data`.

## Structure

The template contains ***method definitions only*** – no output, no statements outside the methods.
camindo CMS places the compiled code into the body of a class; each method thus becomes a method of
an object:

```php
<?php
// Trigger template "members_trigger" for the area Members

function onSave(array &$affectedFields, array &$data, array &$previousData): void
{
    // Display name from first and last name
    $data['name'] = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));
    if (!in_array('name', $affectedFields, true)) $affectedFields[] = 'name';
}

private function normalize(string $text): string
{
    return trim(preg_replace('/\s+/', ' ', $text));
}
```

- All event methods are optional. If one is missing, nothing happens for that event.
- Your own helper methods (`private function …`), properties and constants are allowed.
- CMS:SELECT is allowed to determine contents of linked records (note the remarks below!).
- Address classes by their full name (`\Camindo\Core\Cms`, `\DateTimeImmutable`) – `use` does not
  work, because the code sits in the middle of a class.
- A template that cannot be loaded (syntax error, output outside the methods) aborts the process
  with an error message. Saving records without the intended processing would be worse.

### What is available in the template

| | |
|---|---|
| `$this->cms` | Cms – `snippet()`, `error()`, `select()`, `fetch()` … |
| `$this->db` | Database – your own queries |
| `$this->record` | Record – the record that triggers the event (`load()`, `save()` …) |
| `$this->areaId` | Area for which the template was loaded. If it is assigned to several areas, take the area from `$data['area_id']` |
| `$this->temporary` | `true` when a working copy is saved temporarily (tab change in the input form) |
| `$this->email`, `$this->pagination` … | the services that templates also know |
| `$cms['record_id']` | number of the triggering record – only this, for the CMS commands; in `onSave()` that of its working copy (see [Querying linked contents](#querying-linked-contents)) |

## Events

| Method | When |
|---|---|
| `onCreate(array &$data): void` | a new record is prefilled – values in `$data` appear in the empty mask |
| `onLoaded(array &$data): void` | after each loading of a record (input form, state before saving …) – not with `CMS:SELECT` in the frontend |
| `onSave(array &$affectedFields, array &$data, array &$previousData): void` | before the check and the writing. A field that the method sets anew must be added to `$affectedFields` |
| `onSaved(array &$affectedFields, array &$data, array &$previousData): void` | after writing, before the transaction is completed |
| `onSoftDelete(array $data): void` | the record goes into the trash |
| `onRestore(array $data): void` | the record comes back from the trash |
| `onDelete(array $data): void` | permanent deletion, before the record is deleted in the database |
| `onDeleted(array $data): void` | permanent deletion, after it |
| `onPrivacyAction(string $action, array &$data, array $context): void` | action of a deletion policy – see [below](#onprivacyaction-deletion-policies) |

`$previousData` is the saved state before the change (empty for a new record).

### Cancelling

All events except `onCreate` and `onLoaded` run in the transaction of the process. An exception
cancels it and reverts it completely; the message appears in the input form or as an error of the
API:

```php
function onSave(array &$affectedFields, array &$data, array &$previousData): void
{
    if (($data['end'] ?? '') !== '' && $data['end'] < ($data['start'] ?? '')) {
        throw new \Exception('The end is before the start.');
    }
}
```

### Temporary saving

If an editor switches tabs in the input form, camindo CMS saves the interim state of the working
copy – `onSave` and `onSaved` also run then, with `$this->temporary = true`. Time-consuming or
outward-facing steps (emails, interfaces) belong after `if ($this->temporary) return;`.

### Moving

If a record is moved to another area, the trigger templates of the ***target area*** apply.
`$data` already contains the fields of the target area there, `$previousData` the state in the
source area with its field names.

### Re-entry

If a template saves the same record again in `onSaved()`, it would run endlessly. An event is
therefore not nested for the same record within the same template: when saving again, the other
trigger templates of the area run, but the one currently running does not. Other records – also of
the same area – trigger their triggers normally.

## Querying linked contents

The contents of the triggering record are in `$data` (new state) and `$previousData` (previous
state) – multilingual fields with all languages. Read and change them there, not via `CMS:TEXT`.

For other records, such as the linked ones, you can use [CMS:SELECT](select.md) as in any template.
While a method runs, `$cms` contains only `record_id`, the number of the triggering record. A
`CMS:SELECT` with `relation` therefore needs no `src_id` – and should not have one (see below):

```cms
function onSave(array &$affectedFields, array &$data, array &$previousData): void
{
    $isVariant = false;
?>
(CMS:SELECT
  relation=variant_of
  state=any archive=true future=true
  resulttemplate=shop.trigger_variant_found
)
<?php
    $data['is_variant'] = $isVariant ? 'yes' : '';
    if (!in_array('is_variant', $affectedFields, true)) $affectedFields[] = 'is_variant';
}
```

The result template (`shop.trigger_variant_found`) runs in the body of the method: it sees the
variables of the method and `$this`, and here only sets `$isVariant = true;`. It must not output
anything.

Observe the limits:

- ***New state of the links via `$cms['record_id']`.*** While an existing record is being edited, its
  links – outgoing as well as incoming – hang on a ***working copy***; only after saving do they pass
  over to the record. In `onSave()` – and in `onSaved()` during temporary saving – `$cms['record_id']`
  therefore contains the number of the working copy: `CMS:SELECT relation=…` and
  [CMS:RELATION](relation.md) without a source specification already see the changed links, and so
  does the reverse direction (“Who refers to this record?”) with `dest_id="{$cms['record_id']}"` or
  `filter[field]="{$cms['record_id']}"`. `$data['record_id']`, on the other hand, is always the number
  of the record itself – with it you get the previous state in `onSave()`. In `onSaved()` after the
  final saving, and in all other methods, both numbers are the same.
- ***Embedded contents during temporary saving:*** Links of a field with embedded contents point, during
  temporary saving, to their working copies, which `CMS:SELECT` does not find. On final saving these
  contents are already taken over and are found.
- ***Publication:*** `CMS:SELECT` finds here only what the website would show – released, publication
  date reached, not expired. If the question concerns the structure (“Is any record linked at all?”),
  set `state=any archive=true future=true`; otherwise blocked, pre-dated and expired records are missing.
  `CMS:SELECT` never finds records in the trash.
- ***Rights:*** The query sees what the triggering user may see. The result can therefore differ depending
  on who saves:

  | Trigger | What `CMS:SELECT` finds |
  |---|---|
  | User in the back end or via the API | only contents of the instances, areas and languages that their user group may read (superusers: all) |
  | Website (`CMS:SAVEDATA`, `CMS:USER_…`) | contents of the instance and its readable instances |
  | Scheduler (also due blocks of a deletion policy later on) | all |

- ***Language:*** Multilingual fields of the records found appear in one language – on the website in the
  language of the page, in the back end in the content language of the user. All languages are returned
  by `$this->record->load($id)`.

## onPrivacyAction: deletion policies

A [deletion policy](../admins/15-privacy.md#deleting-according-to-deletion-policy) determines what
happens when a record is deleted. What cannot be expressed with “Overwrite individual fields” – linked
records, texts that are only partly personal, multilingual contents per language – is handled by the
trigger template with two actions:

| Action of the policy | Call |
|---|---|
| “Apply trigger template to the record” | once, `$data` is the record |
| “Apply trigger template to the audit log” | once for each entry in the audit log of the record, `$data` is the entry |

Each such block has an ***identifier*** (lowercase letters, digits, `_`). It arrives as `$action` –
the template uses it to tell the blocks apart, for example an immediate step from one after a year.

```php
function onPrivacyAction(string $action, array &$data, array $context): void
```

| `$context` | |
|---|---|
| `target` | `record` or `audit` |
| `record_id` | the deleted record |
| `user_id` | the website user to whom it belongs (`record.user_id`), otherwise 0 |
| `area_id` | its area |
| `source` | trigger: `record` (record is being deleted), `profile` (UGC: profile was deleted) or `area` (area was deleted) |
| `policy_id`, `job_id` | policy and job |
| `deleted` | only with `record`: `true` if the record lies in the trash at that time |

### On the record

`$data` contains all fields of the record – ***multilingual fields as an array `language_id => value`***,
file fields with their metadata. camindo CMS then saves what the method changes in `$data`:

- in ***policy mode***: without required-field and format checks and without the rights of the user
  group – “unique” still applies;
- not file fields, `record_id`, `area_id`, `user_id` and the other identifiers. To remove a file, use
  “Overwrite individual fields” (`image = ""`) in the same or a later block;
- in the audit log as the action “adjusted by deletion policy” (`privacy`), without the previous values.

If the record lies in the trash (`$context['deleted']`), the method runs as well – for example to tidy up
linked data – but nothing is saved on the record then. Other records the method processes itself via
`$this->record` or `$this->db`.

### On the audit log

For each entry in the history of the record, the method is called with the entry:

| Key | |
|---|---|
| `id`, `t` | number and time – remain unchanged |
| `action` | `create`, `update`, `delete`, `restore`, `purge`, `privacy` … |
| `actor` | `type` (`admin`, `user`, `system`), `admin_id`, `user_id`, `ip`, `origin` – acting persons only as numbers, without names |
| `label` | designation of the record at that time |
| `changes` | list of the changes: `k` field name (multilingual `field@en_US`), `l` label, `t` field type, `g` language, `b` before, `a` after |

camindo CMS writes changed entries back – into the file under `var/audit/` with a new checkpoint of the
checksum chain, and `label` also into the database. Entries that arise only later (for example during
permanent deletion) do not pass through the method; plan a later block for them.

If the area has several trigger templates with `onPrivacyAction()`, they run one after another with the
same identifier; each checks itself whether `$action` concerns it.

### Errors

If none of the trigger templates of the area has `onPrivacyAction()`, or if the method throws an exception,
the job fails: it appears in the log `privacy` with the message and is retried at the next run of the
scheduler task “Apply deletion policies” – at most 20 times. If a block that is due immediately fails,
the deletion does not abort: the remaining blocks run, and the failed one follows with the next run.
