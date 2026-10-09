# Manual for template programmers

camindo CMS delivers content by means of templates, for example HTML on a website.
***CMS commands*** prepare the content and insert it dynamically into the template.
Example of a greatly simplified page template:

```cms
<html>
<body>
<h1>(CMS:TEXT title)</h1>
<div>(CMS:TEXT text)</div>
<div>(CMS:MEDIA field=image attr[id]=image1)</div>
<div>created: (CMS:DATE created_at format="dd.MM.y")</div>
</body>
</html>
```

CMS commands are not merely placeholders. They represent complex
***encapsulated functionality*** that can be adapted with parameters and combined
with one another. This saves a lot of development time.
Moreover, they are proven and guarantee stability.

Examples of what CMS commands can do:

- determine content matching certain criteria from the database
- process content
- check, save or send user input
- registration of website visitors, sign-in and sign-out
- include further templates

***Output formats***: In addition to websites with HTML pages, further output media and
publication channels are possible. Content can, for example, be formatted as XML files, PDF files, emails …
and made available for retrieval via a URL, written as files to the file system, or
pushed over a network.

***Template set***: Nobody needs to reinvent the wheel. Content areas (their configuration incl.
input fields, optionally with content) and the associated templates can be exported in XML format.
When imported into another camindo installation, the relations between areas and templates
remain intact. The complete application part is immediately ready for use. This enables the easy integration
of ***ready-made kits*** (forum, news feed, ...) or the export of self-developed applications
for reuse in later projects.

## Contents

1. [Basics](01-basics.md) – how a page is created, addresses, syntax,
   lists, links, the concept “every record has a page”, filters,
   multilingualism, security headers (delivering CSS/JS/JSON, embedding in
   partner websites), time-controlled templates, templates in the file system
2. [Template sets](02-template-sets.md) – encapsulating functions, keeping them portable,
   passing them on and adapting them
3. [Trigger templates](03-trigger-templates.md) – PHP methods for the events of the
   records of an area (saving, deleting …) and for the actions of the
   deletion policies
4. Command reference – one page per command (below)

How templates are organized in the back end and assigned to content areas is
described in the [Manual for administrators](../admins/06-templates.md).

## Command reference

```cmscode
(CMS:COMMAND value parameter=value parameter[key]="value" …)
```

Command names and parameter names are not case-sensitive. The first value without `=`
counts as the ***main parameter*** of the command (`(CMS:TEXT title)` =
`(CMS:TEXT field=title)`). Values in quotation marks may contain PHP expressions,
as is customary in a PHP string (`"{$set['x']}"`).

The output parameters are the same for most commands:  
`ifempty` (replacement for an empty value),  
`instead` (replacement for a non-empty value),  
`prefix` / `suffix` (only for a non-empty value),  
`set` (result is written to the variable `$set[…]` instead of to the page),  
`id` (another record as the source),  
`replace` (replace partial texts) for TEXT, DATE, DATETIME, TIME and NUMBER.

### Output

| Command | |
|---|---|
| [CMS:DATE](date.md) | Output date |
| [CMS:DATETIME](datetime.md) | Output date and time |
| [CMS:EMAIL](email.md) | Send email, check required form fields |
| [CMS:MEDIA](media.md) | Output image or media file |
| [CMS:MESSAGE](message.md) | Output error messages |
| [CMS:NUMBER](number.md) | Output numeric content |
| [CMS:RAW](raw.md) | Output content unformatted |
| [CMS:SNIPPET](snippet.md) | Output snippet (text module) in the language of the call |
| [CMS:TEXT](text.md) | Output text content |
| [CMS:TIME](time.md) | Output time |
| [CMS:URL](url.md) | Create the URL of a record |
| [CMS:XML](xml.md) | Output the XML declaration |
| [CMS:ZIP](zip.md) | Download the files of several records as a ZIP archive |

### Relations

| Command | |
|---|---|
| [CMS:RELATION](relation.md) | Output the main field of linked records (for internal preview only!) |

### Queries

| Command | |
|---|---|
| [CMS:SELECT](select.md) | Query records |
| [CMS:SELECT_LANGVERSION](select-langversion.md) | Languages of the instance as a result set |
| [CMS:RESULT_START](result-start.md) | Start of the result loop |
| [CMS:RESULT_END](result-end.md) | End of the result loop |

### Navigation

| Command | |
|---|---|
| [CMS:JUMP_LIST](jump-list.md) | Create page navigation |
| [CMS:JUMP_NEXT](jump-next.md) | Create a link to the next page |
| [CMS:JUMP_PREV](jump-prev.md) | Create a link to the previous page |

### Control

| Command | |
|---|---|
| [CMS:CONTINUE](continue.md) | Continue after a success template |
| [CMS:CSP](csp.md) | Adapt the script policy (CSP) of the page |
| [CMS:HEADER](header.md) | Send HTTP headers |
| [CMS:INCLUDE](include.md) | Include a template, optionally with output cache |
| [CMS:NONCE](nonce.md) | Nonce for scripts generated by PHP |
| [CMS:SAVEDATA](savedata.md) | Store a record in a PHP variable |

### Frontend editing

| Command | |
|---|---|
| [CMS:FED](fed.md) | Edit link for frontend editing |
| [CMS:FED_CODE](fed-code.md) | Output HTML only with frontend editing |
| [CMS:FED_SCRIPT](fed-script.md) | Script for the edit links (once per page) |

### Community

| Command | |
|---|---|
| [CMS:USER_2FA](user-2fa.md) | Manage the second factor |
| [CMS:USER_AUTH](user-auth.md) | Check the session of the frontend user |
| [CMS:USER_BASKET](user-basket.md) | Edit the shopping basket |
| [CMS:USER_DELETE](user-delete.md) | Delete the user's record |
| [CMS:USER_INPUT](user-input.md) | Save a record from user input |
| [CMS:USER_LOGIN](user-login.md) | Sign in the frontend user |
| [CMS:USER_LOGIN_CODE](user-login-code.md) | Complete sign-in with the second factor |
| [CMS:USER_LOGOUT](user-logout.md) | Sign out the frontend user |
| [CMS:USER_PASSWORD_RULES](user-password-rules.md) | Password rules for display in the form |
| [CMS:USER_REGISTER](user-register.md) | Register a frontend user (create a profile record) |
| [CMS:USER_REGISTER_CONFIRM](user-register-confirm.md) | Activate registration |
| [CMS:USER_PROTECT](user-protect.md) | Lock a template for visitors who are not signed in |
| [CMS:USER_PW](user-pw.md) | Prepare password reset |
| [CMS:USER_TOKEN](user-token.md) | Security token for forms |

The administrator sets up the community functions (see
[Manual for administrators](../admins/13-community.md)).

### PDF add-on module

The PDF add-on module (`camindo/module-pdf`) supplements the commands `(CMS:PDF_…)`, with
which templates create PDF documents – page by page, if desired on a
PDF template such as letterhead paper, with texts, HTML, images, lines and tables:

```cms
(CMS:PDF_INIT margins=25,20,30,20)
(CMS:PDF_PAGE letterhead.pdf)
(CMS:PDF_FONT helvetica style=B size=16)
(CMS:PDF_TEXT "Invoice {$cms['number']}" y=50)
(CMS:PDF_PRINT invoice.pdf)
```

The complete command list is part of the documentation of the module.
