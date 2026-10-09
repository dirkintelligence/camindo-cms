# 6 Multilingual content

## Language versions

Each website (instance) has a ***primary language*** and can offer further languages –
for example German as the primary language, plus English and French. Each language is a
***language version*** of the website with its own address, such as `www.example.com/en/…`.

Not every field is multilingual.

- ***Multilingual fields*** (title, text, caption …) have their own content for each language.
- ***Monolingual fields*** (price, date, item number …) apply to all languages equally.

Files can be multilingual, too – for example a PDF brochure for each language, or an image
with embedded text.

A multilingual field marked as required does not have to be filled in in every language.
The requirement then only means an entry in the primary language (usually English).  
See also “[Missing translations](#missing-translations-the-primary-language-steps-in)”.

## The input form

How the input form shows multilingual fields is set for each content area:

- ***All languages on one page:*** each multilingual field appears once per language, one below
  the other; the label names the language code, such as “en_US”.
- ***One tab per language:*** the form has one tab per language and a tab “All languages”. A
  language tab shows the multilingual fields in that language and the monolingual fields. When
  you switch tabs, your entries are kept; everything is saved together with “Save”.

In the detail view of a record, there is only the tab “Contents” even with “One tab per
language”. You choose the language there in the selection menu ![flag](../_/flag.svg) below the
tabs. The selection “All languages” shows multilingual fields in all languages directly one
below the other.

The language in which the form starts is set in your [personal settings](./03-work-surface.md#personal-settings)
under “Preferred language version”. The same setting determines the language in which the record
list, search and detail view show the content. As a translator, select your target language here.

Which languages you can see and edit depends on your user group. Languages you may only read
appear as plain text without an input field.

***Writing direction:*** texts of a multilingual field follow the direction of their language –
Arabic, Hebrew, Persian or Urdu texts run from right to left, German or English texts from left
to right, regardless of the language in which you use the backend. Monolingual fields follow the
language of the backend. Technical details such as email addresses, phone numbers and passwords
always run from left to right.

## Missing translations: the primary language steps in

If a translation is missing in a multilingual field, the website shows the content of the
primary language at that place. This applies field by field: an article with a German title but
an English text that is not yet translated shows the German title and the English text in the
German language version.

The website therefore stays complete during translation. As soon as you enter a translation, it
appears. If you empty the field again, the primary language steps in again.

To really show no text, a single space is enough as an entry. However, such content is not
found in the backend when you search for `(empty)`.

## Finding missing translations

In fact, the content of the primary language is stored in the multilingual field several times
when it is not filled in in every language. Searching for the German title “History” therefore
also finds the record whose title “History” is shown on the website, although it was only entered
on the “en_US” tab.

To find all records whose multilingual content has ***no content of its own*** in the language
you are looking for, use the placeholder `(empty)`. In short:  
`(empty)` finds records without their own translation.  
`!(empty)` conversely finds all records with their own translation.

1. In your personal settings, set the “[preferred language version](./03-work-surface.md#personal-settings)”
   to the language you want to check.
2. Open the search form of the area and enter `(empty)` in a field, such as the title.
3. The list shows all records whose title has not been translated yet.

## Translating

Larger translations do not require translators to work in the CMS:

- The ***DOCX export*** creates a Word file with all texts to be translated. The translator
  overwrites them in Word; the ***DOCX import*** transfers the translation back into the CMS.
  For more, see the section [Translating with Word (DOCX)](./08-export-import.md#translating-with-word-docx).
- The ***CSV export*** provides multilingual fields in one column per language, such as
  `titel.de_DE` and `titel.en_US`. This is handy for translating many records at once in a
  spreadsheet (see [chapter 8](./08-export-import.md#editing-content-in-excel)).
- With [batch processing](./07-batch-processing.md) you write one content into many records at
  once – including multilingual fields.
