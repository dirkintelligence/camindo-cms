# 3 The work surface

## Start page

After signing in you land on the ***start page***. It is divided into four slots. The
module buttons are at the top left; you can fill the other three slots with
***widgets*** (see [below](#start-page-widgets)).

The module tiles are sorted alphabetically, in the selected backend language. Which
ones you see depends on the rights of your user group.

## The modules

### For editors

| Module | Task | Manual |
|---|---|---|
| ![menu](../_/draft.svg) Contents | Search, create and edit records of all content areas | Chapters [4](./04-finding-content.md) to [8](./08-export-import.md) |
| ![menu](../_/person.svg) Users | Manage users and user groups | [Administrators](../admins/07-users-and-rights.md) |
| ![menu](../_/delete.svg) Trash | Records previously deleted by the signed-in user | [Administrators](../admins/10-modules.md#trash) |
| ![menu](../_/smart_toy.svg) AI assistant | Get support from AI | Add-on module |

### For website administrators

| Module | Task | Manual |
|---|---|---|
| ![menu](../_/language.svg) Instances | Set up websites with their domains and languages | [Administrators](../admins/03-instances-and-languages.md) |
| ![menu](../_/flag.svg) Languages | Set up the languages of the websites | [Administrators](../admins/03-instances-and-languages.md#languages) |
| ![menu](../_/translate.svg) Snippets | Maintain the text snippets of the website in all backend languages | [Administrators](../admins/10-modules.md#snippets) |
| ![menu](../_/transition_dissolve.svg) Structure | Set up content areas and their input fields | [Administrators](../admins/04-structure.md) |
| ![menu](../_/check.svg) Status | Define the editing states for records | [Administrators](../admins/10-modules.md#status) |
| ![menu](../_/manufacturing.svg) Media converter | Set up the conversion of images, videos and audio files | [Administrators](../admins/08-media.md) |

### For programmers

| Module | Task | Manual |
|---|---|---|
| ![menu](../_/extension.svg) Templates | Edit the templates of the website | [Template programmers](../templateprogrammers/README.md) |

### For system administrators

| Module | Task | Manual |
|---|---|---|
| ![menu](../_/schedule.svg) Scheduler | Scheduled tasks, e.g. database backups | [Administrators](../admins/10-modules.md#scheduler) |
| ![menu](../_/build.svg) Tools | Maintenance of database, data and server | [Administrators](../admins/10-modules.md#tools) |
| ![menu](../_/box.svg) Components | Install and update add-on modules | [Administrators](../admins/10-modules.md#components) |
| ![menu](../_/receipt_long.svg) Log files | Review the system logs | [Administrators](../admins/10-modules.md#log-files) |
| ![menu](../_/manage_history.svg) Audit log | Review changes to all records | [Administrators](../admins/10-modules.md#audit-log) |

## Sidebar

The sidebar can be reached from every page.

| Symbol | Meaning |
|---|---|
| ![menu](../_/menu.svg) | ***Main menu*** – at the top “![menu_book](../_/info.svg) Info” (version of camindo CMS) and “![menu_book](../_/menu_book.svg) Manuals”, below them the same modules as on the start page; this lets you switch to another module from any page |
| Logo | back to the start page |
| ![hourglass](../_/hourglass.svg) | ***Background processes*** – see below |
| ![mail](../_/mail.svg) | ***Messages*** – the number on the symbol shows the unread messages |
| ![settings](../_/settings.svg) | ***personal settings*** |
| ![logout](../_/logout.svg) | ***Sign out*** |

## Manuals

In the main menu ![menu](../_/menu.svg) the entry ***“Manuals”*** opens these manuals
directly in the backend – in the language of the user interface, as long as they exist
in it; otherwise in English.  

A manual opens as its own [layer](#layers) above the overview; the path bar then shows
for example “Manuals › Manual for editors”. Clicking “Manuals” closes the manual and
returns you to the overview.

Above the text you will find:

- a ***search field*** – it finds sections in which all the words you enter occur,
- the selection of the ***manual*** (editors, administrators, template programmers and
  the manuals of installed add-on components) – it also limits the search and the index,
- the ***index*** – all headings and the bold-italic terms, from A to Z, each with a link
  to the place,
- the ***print view*** ![print](../_/print.svg) – as soon as a manual is selected.

At the end of each page, “Back” and “Next” lead to the previous and the next chapter of
the same manual. Add-on components (such as the AI assistant) come with their own manuals;
the overview of manuals lists them under “Manuals of add-on components”.

The ***“Print view”*** opens the manual as a whole on one long page in a new window: first
its overview, then all chapters in the order of the table of contents. Links within the
manual jump to the place in the document; links to other manuals remain plain text.
“Print” opens the print dialog of the browser – there you can also ***save the manual as
a PDF***. On paper, each chapter starts on a new page.

## Layers

Every page you open – whether a record list, a detail view or an input form – is placed as
a new ***layer*** over the previous one. A breadcrumb trail above the page shows which
layers are open. Clicking an entry in it closes all layers up to the one you want.

The browser's Back and Forward buttons work inside the backend, but they can lead to
misunderstandings and may cause data loss (an open input form closes unintentionally).
It is better not to use them at all.

## Lists and context menus

In all lists of the backend, the following applies:

- ***Clicking a row*** opens the detail view.
- ***Right-clicking a row*** opens a context menu with the most common actions, such as
  ![edit](../_/edit.svg) “Edit”, ![content_copy](../_/content_copy.svg) “Copy” and
  ![delete](../_/delete.svg) “Delete”. Actions for which you lack the right do not appear.
- ***Clicking a column heading*** sorts by this column; a second click reverses the
  direction.  
  A sort order that differs from the default sort order of the area is highlighted in
  yellow and named, for example “Title ascending”. Clicking the X at the far right resets
  the sort order.
- The menu ![more_vert](../_/more_vert.svg) at the top right of a list contains further
  functions – in the record list, for example, “Export”, “Import” and “Batch processing”.

## Keyboard operation

The backend can be used entirely without a mouse and with a screen reader. The
***Tab key*** moves from element to element, Shift+Tab moves back; the selected element
has a frame.

- ***Skip to content:*** The first press of the Tab key shows “Skip to content” at the top
  left and – on the second press – “Skip to path navigation”. Press Enter to skip the
  header bar with the main menu and the symbols.
- ***Main menu:*** The button ![menu](../_/menu.svg) opens it with the first entry
  selected. The Esc key closes it again; the same applies to the list of background
  processes.
- ***Lists:*** Every row can be reached with the Tab key. Enter opens the detail view,
  Shift+F10 or the context menu key opens the context menu. In the menu you choose with
  the arrow keys, Enter carries out the action and Esc closes the menu.
- ***Column headings*** also sort with the Enter key.
- ***Layers:*** When you open a new layer, the selection is on its heading. When you close
  it, the selection returns to where you opened the layer – for example to the row of the
  list.
- ***Forms:*** If saving reports an error, the selection is on the first field with an
  error; the error message belongs to the field and screen readers read it out with it.
- ***Notes*** on a field (the question mark next to the label) also appear when you reach
  them with the Tab key; Esc hides them.

Short messages such as “Saved” remain visible for a few seconds, error messages longer.
While the mouse pointer or the selection is on a message, it stays visible. If your
operating system has “reduce motion” switched on, the backend does without animations; you
can also switch off the slide animation of lookup fields in your
[personal settings](#personal-settings) under ***“Animation”***.

## Background processes

Functions with a long run time – batch processing, import, export, deleting many records,
the conversion of videos – run as a ***background process***. For many of these functions
you can choose yourself:

- ***In the foreground*** you wait until the task is finished and see the result right
  away. This is convenient for smaller tasks.
- ***In the background*** you keep working right away. The result – a log or a download
  link – arrives as an internal message in your inbox.

From a certain number of records onward, a task always runs in the background.

While a process is running, the hourglass ![hourglass](../_/hourglass.svg) spins. When the
process has finished, a short message appears in the foreground.  
Clicking the hourglass opens the list of your running processes with a progress display.
There you can also abort a process. Failed processes remain in the list until you remove
them.

## Messages

The envelope symbol ![mail](../_/mail.svg) gives you access to the ***internal messages***.
You can use them to write to other users. They receive a message only in the backend, not
at their email address. On request, camindo CMS can also send you a daily
[summary by email](#summarize-messages-by-email).

- “Inbox” and “Outbox” are on separate tabs.
- You can mark messages as read or unread and as important, reply to them and forward
  them.
- ***Message about a record:*** in the detail view of a record, the menu
  ![more_vert](../_/more_vert.svg) contains the entry “Message”. The message then contains
  a link to the record – practical for queries or corrections for colleagues.
- camindo CMS itself also sends you messages: the results of background processes, import
  logs and download links for exports.

### Summarize messages by email

If you do not look into the backend every day, it is easy to miss a message. In your
personal settings, select ***“Summarize messages by email”*** – “daily”. If new messages
have arrived for you since the last summary, you receive an email to your stored address
in the morning, containing

- the number of all unread messages, grouped by sender, and
- the new messages with date, sender and subject.

The email contains ***no message texts*** and no contents from records – these remain in
the system. You can read the messages only after signing in to the backend; the email
deliberately contains no link there. Without new messages, no email is sent. A prerequisite
is an email address in your account.

Messages are deleted automatically after a fixed period – typically 60 days after sending,
although your administrator can set a different period. Anything you need for longer does
not belong in a message but in a record.

If you delete a message, it disappears only from your list; the recipient or sender keeps
the version that was sent.

## Personal settings

The gear ![settings](../_/settings.svg) opens your personal settings.

| Setting | Effect |
|---|---|
| Backend language | Language of the user interface (as with [sign-in](./02-sign-in.md#language)) |
| Date and number format | Locale for date, time and number values |
| Time zone | Time zone in which you see and enter points in time |
| Preferred language version | For multilingual content, the input form opens this language first (see [chapter 6](./06-multilingual-content.md)) |
| Frontend editing | Shows edit links directly on the website (see [chapter 9](./09-frontend-editing.md)) |
| Dark mode | Switch for the ***dark color scheme*** of the user interface; switched off (default), the light one applies. The sign-in page uses the color scheme last used in this browser |
| Animation | “on” (default): when a lookup field opens the list of the linked area, it slides in from the side. “off”: the list appears immediately |
| Dashboard widgets | see below |
| Subscribe to reports | Reports that your administrator has released for your user group – after every run, the result arrives as a message in your inbox. This entry only appears if such reports exist |
| Summarize messages by email | “daily”: an email with the unread messages per sender and the new ones with their subject, only if new messages have arrived (see [Summarize messages by email](#summarize-messages-by-email)) |
| Two-factor sign-in | Shows how you sign in (email code, authenticator app or passkey); the button next to it sets up the app and passkeys (see [chapter 2](./02-sign-in.md#set-up-a-passkey)) |

### Start page widgets

The start page has four slots. The module tiles always occupy the top left slot; on the
other three slots (“top right”, “bottom left”, “bottom right”) you can place
***widgets*** – small overviews provided by modules. Several widgets may share a slot;
they then appear one below the other.

In the settings, select a widget and a slot and click “Add”. “Remove” takes it out
again. Please note: both changes only take effect when you click “Save”. Only widgets of
the modules to which you have access are offered.

#### For editors

| Widget | Content |
|---|---|
| Unreviewed content of frontend users | Contributions and profile records of frontend users that still need to be reviewed (see [chapter 10](./10-visitor-contributions.md)) |
| Trash | Number of entries in your [trash](./05-editing-records.md#trash); a click opens it |

#### For administrators

| Widget | Content |
|---|---|
| Log files | the most important messages of the system logs from the last few days (superusers only) |

For superusers, important system messages are also shown below the module tiles, without
the need for a widget.

#### For template programmers

| Widget | Content |
|---|---|
| Template messages | List of templates with errors (parsing errors) |

Add-on modules can bring further widgets.

## AI assistant (add-on module)

If the add-on module ***AI assistant*** ![smart_toy](../_/smart_toy.svg) is installed and
your user group has access to the module, ***Ctrl+K*** opens the assistant in a side panel
– from every page of the backend. On a Mac it is ***⌘+K*** (Command key). A second Ctrl+K
or ⌘+K in the input field closes the panel again. Only in a text editor that assigns the
keys itself (for example for “Insert link”) does its function apply there.

Give the assistant an instruction or ask a question. Create line breaks with
`Shift+Enter`. A plain `Enter` sends the message.  
The assistant searches, reads, creates and changes records.  
While it works, the chat shows ***every step*** as a short text reply in italics. The final
answer is more detailed.

- ***Stop:*** next to the display “The assistant is working …”, “Stop” ends the work after
  the current step. If you send a new message, it continues.
- ***Confirm:*** before the assistant changes, deletes or moves something, or edits many
  records at once, it asks. The prompt shows the record and, for changes, ***before*** and
  ***after*** for each field, as well as the result of a ***test run***. You can allow
  ***only this action*** or ***this and similar actions*** of the same kind until the end of
  the chat – or decline. Reading and creating run without a prompt.
- ***Show:*** ask the assistant to show you something – a record, a filtered list, an input
  form or another view you are allowed to open – and it opens it as a layer next to the
  chat. What it cannot do itself, it also opens and tells you step by step what to do.
- ***What you have open:*** with every message the assistant learns which layers you have
  open – for example the list of an area and a record above it – and for the top layer
  also your search criteria. So you can simply write “Help me with this article” or
  “Why don’t I find article X here?”. Labels and ids are passed on, no contents; search
  values in encrypted fields appear only as `[encrypted]`. What you have typed in an open
  input form but not saved yet is not visible to it.
- ***Questions about operation:*** “How do I change one word efficiently in 100 records?” –
  the assistant looks it up in the manuals and, on request, opens the matching page for you.
- ***Open input forms:*** the assistant does not change a record you currently have open in
  an input form; it asks you to save or close the form first.
- ***New chat:*** start a new chat with the symbol ![add_comment](../_/add_comment.svg)
  “New chat”.
- ***Previous chats:*** you find earlier chats with the symbol
  ![view_headline](../_/view_headline.svg) “Previous chats” and on the start page of the module
  in the tab “Previous chats”. Clicking a row continues the chat in the side panel; you delete it
  via the context menu (right mouse button). Depending on the settings, a deleted chat stays
  readable for superusers for a few days.
- ***Close the side panel:*** the X symbol at the far right closes the side panel.

Below the input field you see the model and the ***tokens*** used so far in the chat,
broken down into input, input retrieved from the cache, and output.  
If the assistant was interrupted in the middle of its work (for example because the browser
was closed prematurely), the chat offers “Continue” or “Stop” the next time you open it.

![warning](../_/warning.svg) The assistant is a language model of an external provider.
Its answers can be wrong or incomplete – check the results, and confirm only what you
understand. The assistant works with your user rights and has no access beyond them. If it
changes content, the audit log shows your user name – but with the origin “llm” (for large
language model). Your own activities carry the origin “backend”.
