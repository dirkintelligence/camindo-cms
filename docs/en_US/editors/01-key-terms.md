# 1 Key terms

Before you start, this chapter explains a few terms. They come up again and again
in all chapters.

## Frontend and backend

camindo CMS has two faces:

- The ***frontend*** is the website that is visible on the internet.
- The ***backend*** is the work environment in which content is maintained.  
  You also open the backend in a web browser, but it is only accessible to
  authorized people in your organization, the “users”.

## User and user group

***User*** means every person who works in the backend – regardless of whether
they act as an editor, translator, administrator or programmer.

Every user belongs to a ***user group***. The user group determines which modules,
websites, content areas and languages its members can see and edit. The user group
***Superuser*** may do everything.

Website visitors who register on the frontend are called ***frontend users***. Their
representation in the CMS is a ***profile record*** (see [chapter 10](./10-visitor-contributions.md)).

## Instance (website)

A camindo installation can run several websites – each with its own domains,
languages and content. Such a website is called an ***instance***. If you work with
several instances, the backend shows a separate main folder for each instance.

## Content

***Content*** refers to all the content managed in the CMS as a whole.

## Record

A ***record*** is a self-contained unit of content – comparable to an article that
consists of “Title”, “Text”, “Image” and “Author”. You create, change and delete
records using the ***input form***.

A record does not have to be an article in the editorial sense. Any unit of content
can be a record: a product description, an address, an appointment, an image with a
caption – or simply a keyword, a place name or a category.

A piece of content is worth storing as its own record mainly when it appears in
several places on the website.

Example: An “author” is created once and ***linked*** to all of their “articles”,
instead of being typed out again in every article. If the biography changes, you
change it in one place. The same applies to an image: stored as its own record, it
can carry additional information (caption, photographer, copyright) and be included
in any number of articles without being uploaded more than once.

When you save, the CMS automatically adds background information, the
***metadata***: when and by whom the record was created and last changed, who it
belongs to (the ***owner***), and its number (**record_id**).

## Content area

Records are stored in ***content areas*** (in the backend also called “content area”).
Each content area has its own field structure, for example:

| Content area | Input fields |
|---|---|
| Articles | Title, date, teaser, text, image, author |
| Addresses | First name, last name, phone, email |
| Images | Image file, caption, copyright |

Each content area provides an input form, a search form and a record list that match
its fields exactly. Your administrator can add further fields at any time, even while
the website is in operation.

You can group several content areas into ***folders***.

## Input field

An ***input field*** holds one part of the record – a title, a date, a file, a link to
another record. Each field has a ***type*** (text, number, date, file, relation …),
which determines what can be entered and how it is checked. One field per content
area is the ***main field***: its content stands in for the whole record, for example
in lists and relations – usually the title or name.

## Status

The ***status*** describes the editing state of a record, for example “Enabled”,
“Disabled” or “Incomplete”. Only records with the status “Enabled” can be accessed
through the frontend.  
The standard installation ships with the two statuses “Enabled” and “Disabled”. Your
administrator can add further statuses. They are then automatically available in all
content areas that have a “Status” input field.

## Timed publication

For each content area, a ***publication date*** and an ***expiration date*** can be
set. Once these are set, a record can be published on a schedule.  
Records with a future publication date cannot be accessed on the frontend yet.
Expired records are no longer listed in automatically generated lists, but can still
be accessed directly (archiving of old content).

## Workflow

camindo CMS supports flat workflows. Examples:

- Members of a user group have restricted access to the status; for example, they
  cannot enable records themselves.
- Members have restricted access to language versions; they can only translate
  content into their own language.

Records can be passed on via [internal messages](./03-work-surface.md).

## Template

***Templates*** determine how content appears on the website. They contain the design
(for web pages: HTML) and place the contents of the records within it.  
Beyond that, templates can take on complex tasks: generate lists and paging, find
linked content, evaluate forms, register and sign in visitors.  
HTML is only one publication channel. Templates can also output content in other
formats, e.g. XML, JSON or PDF.

A record is displayed automatically with the default template of its content area. If
your administrator has released further templates, you can select one in the record's
input form. The selected template then determines how the record appears on the
frontend.

For an overview, see the [manual for template programmers](../templateprogrammers/README.md).

## Working copy and edit lock

When you edit a record, camindo CMS works on a ***working copy***. The website keeps
showing the previous state until you save. As long as you are editing a record, it is
locked for other users (***edit lock***, see [chapter 5](./05-editing-records.md#edit-lock)).

## Trash

Deleted records first go to the ***trash*** and are only removed permanently after a
retention period. By default this is 7 days. Each user has their own trash and can
restore what they have deleted (see [chapter 5](./05-editing-records.md#trash)).

## Background process

Tasks with a long run time – such as a large import, batch processing or the
conversion of a video – run as a ***background process***. You can keep working in the
meantime; you receive the result as an internal message (see
[chapter 3](./03-work-surface.md#background-processes)).
