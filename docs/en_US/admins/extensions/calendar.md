# Extension Calendar

Core extension for date and time fields (field types “Date + time”,
“Date + time, timezone-dependent” and “Time”, single-line display). Next to the
text field it adds the ***date or time picker of the browser***; its appearance and language
are therefore determined by the browser, not by camindo CMS.

Always available, no installation needed. A ***new*** date or time field receives it
automatically (“by field type”, minute step 1) – even when the AI assistant creates the
field. If you do not want an extension, choose “none” when creating the field.

## Settings per field

| Setting | Default | Meaning |
|---|---|---|
| Picker | by field type | “by field type”: time fields get a time picker, all others date and time. Can be fixed to: “date and time”, “date only”, “time only” |
| Minute step | 1 | grid of the time picker in minutes (1–60), e.g. 15 for quarter hours |

## Behavior

- A selection writes the value into the text field in international notation:
  `2030-09-26 14:30`, with “date only” `2030-09-26`, with “time only” `14:30`. The
  backend understands this form in every locale and, after saving, shows the value in
  the user's format.
- A value in international notation in the text field also appears in the picker.
  The picker does not take over values in a national format (`09/26/2030`) – the text
  field itself accepts them, though.
- The text field always remains freely editable.

If the calendar should appear in the language and date format of the user,
[Datepicker](./datepicker.md) is the alternative.
