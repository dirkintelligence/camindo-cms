# 3 Instances and languages

## Instances

An ***instance*** is a website: a frontend with its own domains, languages, and content.
One camindo installation can operate any number of instances – for example different
branches of a head office, or several websites of a group.
Content and templates belong to an instance and are thereby bound to its domains.
In addition, ***shared areas*** can be defined, with content that is accessible to several
instances and may appear under all of their domains.

## No multi-tenancy

The separation of content has limits: camindo CMS does not offer true multi-tenancy.
A secure separation of tenants (for example competing company websites) is only achieved
with separate camindo installations, each with its own database and its own `public/` and
`var/` directories.

### The default instance

After installation, there is a ***default instance*** in the module “Instances”. It catches
all requests whose domain is not assigned to any other instance. Its main language after installation
is English (en_US). It can be changed, but
not deleted. If you manage only one website with camindo CMS, you can use this instance
for it. If you manage several websites, create a separate instance for each website.

Because it catches all remaining domains, the default instance always stands ***last*** in
all lists and selections of the back end – regardless of its position.

### Settings of an instance

| Setting | Meaning |
|---|---|
| Label | name of the instance in the back end |
| Position after... | The order in the back end is changed by selecting the preceding instance. The default instance always stands at the end and cannot be selected as a predecessor |
| ***Frontend languages*** | |
| Primary language | language that steps in when a translation is missing; en_US is preselected for a new instance |
| Additional languages | additional language versions; the order is kept in the back end and can also be used in the frontend |
| ***Frontend domains*** | |
| Main domain | the actual address of the website, without protocol, e.g. `www.example.com` |
| Frontend protocol | `https` or `http` |
| Canonical requests | permanently (301) redirects every request that does not come via the main domain and the valid address of a record to that domain. Recommended for search engines |
| Additional domains | requests via these domains also end up in this instance, e.g. `example.com` without `www`. With the switch “RegEx”, an entry counts as a regular expression, for example to catch all subdomains: `*\.camindo\.de` |
| ***Access*** | |
| Readable other instances | content of the selected instances can be retrieved in the frontend of this instance – for example a shared product catalog for several websites |
| ***Start page*** | |
| Start record | record whose content is available on the start page |
| Start template | template with which the start page is output |
| ***Script policy (CSP)*** | |
| Script policy | “Default” (setting `security.frontend_csp`, otherwise off), “Off”, “Report only” or “Enforce”. Details see below |
| Additional script sources | addresses of external scripts that may run, one per line, e.g. `https://www.googletagmanager.com` or `*.example.com` |
| Allow scripts loaded by allowed scripts | scripts that an allowed script loads (tag manager, widgets) also run |
| Restrict form targets / Additional form targets | forms may only send to the own website and the stated addresses (e.g. the payment provider) |

The start page can only be selected after the first save – before that, the instance has
no content.

Media files of an instance are only delivered via its own domains and the domains of the
instances it may read. This prevents images of one website from being retrieved via the
address of another.

### Script policy

The ***script policy*** (Content Security Policy) is a barrier in the browser: only scripts
from the own domain and from the entered external sources run.
If script code gets into a page via content – a comment, an imported text – the browser
does not execute it.

Proceed in two steps:

1. “Report only”: for testing – all scripts run, but the browser reports what it would
   block. The reports are in the module “Log files” under `csp`.
   Call up the most important pages (start page, product, shopping cart, checkout, customer
   account) and check the log.
2. “Enforce”: for productive operation, once all reports have been resolved.
   If the log reports external scripts that you need (payment provider, maps, statistics),
   enter their address as a script source. Every script in the template needs the nonce of
   the page (`<script(CMS:NONCE)>`); scripts directly in the HTML (`onclick="…"`) must be
   rebuilt by the template programmer. The template editor and the widget “Template
   messages” on the start page point out scripts without a nonce.

New instances are set to “Default”: they take over the setting `security.frontend_csp` of
the configuration; without it, “off” applies. The configuration is only this default – an
instance for which you have chosen “Off”, “Report only” or “Enforce” keeps its mode. For a
staging environment, `security.frontend_csp = 'report'` is suitable if the instances there
are set to “Default”.

### Addresses in the frontend

The address of a record consists of the domain, for multilingual websites the language
code, and the ***slug*** or the number of the record (record_id):

```
https://www.example.com/company-party-2026
https://www.example.com/en/summer-party-2026
https://www.example.com/1758
```

If the language code is missing, camindo CMS selects the language according to the
browser's language setting (Accept-Language). If this is not set up as a frontend
language, the main language of the instance is displayed.
The address consisting of the language code only (without slug or record_id) leads to the
start page (for example `https://www.example.com/en`). If there is no record at an address,
the website responds with status 404. Records only have a slug if the field `slug` is set
up in the content area; see [chapter 5](./05-input-fields.md#special-field-names).
Each content area can provide an additional ***slug prefix***. It then stands in front of
the slug of its records (`/en/events/summer-party-2026`) and can be different for each
language. More on this in [chapter 4](./04-structure.md#slug-prefix).

### Copying and deleting an instance

“Copy” opens an input mask in which the details of the instance to be copied are already
filled in – without domains, start page, and content.
Content areas, templates, and records are not copied.
You can subsequently transfer these via [XML export and import](./09-structure-export-import.md)
if desired.

> **Caution:** When an instance is deleted, **all** of its content areas, input fields,
> records, and templates are permanently deleted with it. Instances have no trash.
> The confirmation prompt states how many elements are affected.

## Languages

In the module “Languages”, you create the languages that are available to the instances.
After installation, these languages are already created:

* ‫العربية‬ (ar_SA)
* বাংলা (bn_BD)
* 简体中文 (zh_CN)
* Deutsch (de_DE)
* English (en_US)
* Español (es_ES)
* Français (fr_FR)
* हिन्दी (hi_IN)
* 日本語 (ja_JP)
* 한국어 (ko_KR)
* Português (pt_BR)
* ‫اردو‬ (ur_PK)

Additional languages can be installed later – see below.

| Setting | Meaning |
|---|---|
| Language or locale code | code of the language version, appears in the address, e.g. `de`, `de_DE` or `de_AT`. A variant such as `de_AT` also matches browsers that only request `de` |
| Backend language | language of the snippets (text modules) of this language version, e.g. `de_DE` – it also applies to messages of the CMS commands in the frontend. `de_DE` and `de_AT` as frontend languages thus share the same text modules (see [Backend snippets](./10-modules.md#backend-snippets)) |
| Label / Label (native language) | name of the language, once in the language of the back end (“German”), once in its own language (“Deutsch”) – for language switchers |
| Time zone | time zone in which date and time information of this language version appears on the website. Empty: the time zone of the configuration |
| Position after... | order |

### Changing the languages of an instance

- ***Adding a new language:*** Multilingual content of the instance receives the new language
  version. Until it is translated, the main language steps in.
- ***Removing a language from an instance:*** All content of the instance in this language is
  permanently deleted: texts, numbers, dates, full texts, privacy templates, and the files
  of multilingual fields. Relations and the content of the other languages remain intact.
  When saving, the mask first appears again with a ***warning***, which names the languages
  being removed – only a second click on “Save” removes them. If you change the main
  language at the same time, files that are not translated show the file of the new main
  language afterwards.
- ***Deleting a language:*** The content of the language is deleted in ***all*** instances,
  just as when removing it from an instance. Before deleting, camindo CMS names the
  instances that use the language. If it is the ***main language*** anywhere, it cannot be
  deleted – first choose a different main language there.

How fields become multilingual is described in [chapter 5](./05-input-fields.md#multilingual-fields).
