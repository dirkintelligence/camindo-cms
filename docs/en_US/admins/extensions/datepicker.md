# Extension Datepicker

Core extension for date fields (field types “Date + time” and “Date + time,
timezone-dependent”, single-line display – not for “Time”). Next to the text field it adds
a ***calendar*** in the language and date format of the user and – depending on the
setting – a ***time field***. It is based on the library vanillajs-datepicker.

Always available, no installation needed. Unlike [Calendar](./calendar.md), it is
not preselected automatically.

## Settings per field

| Setting | Default | Meaning |
|---|---|---|
| Picker | by field type | “by field type” and “date and time”: calendar and time field; “date only”: only the calendar |
| “Today” and “Clear” buttons | on | “Today” selects today's date, “Clear” empties the field |
| Show week numbers | off | column with the calendar weeks on the left of the calendar |

## Operation

- A click into the calendar field opens the calendar; a click on a day takes it over
  and closes the calendar.
- ![chevron_left](../../_/chevron_left.svg) and ![chevron_right](../../_/chevron_right.svg)
  go back or forward one month; a click on month and year in the header switches to
  the month, year and decade overview.
- The time field takes hours and minutes.
- Month names, weekdays and the first day of the week follow the locale of the user
  (personal settings, “Date and number format”); if a translation is missing, the
  calendar appears in English.

## What is written into the field

The value appears in the text field the way the backend displays date values – in the
user's medium date format, e.g. `26.09.2030, 14:30` (de_DE) or
`Sep 26, 2030, 2:30 PM` (en_US). The time is only appended if it is not 00:00.
Conversely, when opening, the calendar takes over a value in the display format or in
international notation (`2030-09-26 14:30`) from the text field. The text field remains
freely editable; changes there appear immediately in the calendar and time field.
