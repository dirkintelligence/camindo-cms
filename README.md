# camindo CMS

camindo CMS manages all kinds of content – articles, products, addresses, images,
appointments – in freely definable **content areas** and outputs it via **templates** on
one or several websites, in as many languages as you need.

This repository contains the core of camindo CMS 9 (Composer package `camindo/cms`).
A new installation is created from the project skeleton `camindo/project`, which pulls in
the core.

> Deutsch: camindo CMS verwaltet Inhalte aller Art in frei definierbaren
> Inhaltsbereichen und gibt sie über Templates auf einer oder mehreren Websites aus.
> Die Handbücher gibt es auf [Deutsch](docs/de_DE/README.md) und
> [Englisch](docs/en_US/README.md).

## Features

- **Data model built in the back end, not programmed:** content areas with input fields
  of many types (text, rich text, Markdown, numbers, dates, files, links between
  records, trees), each with its own input mask, search mask and list.
- **Multi-site and multilingual:** several websites (instances) from one installation,
  content per language with fallback, right-to-left languages, collation per language.
- **Template language** with `(CMS:…)` commands, compiled to PHP; include caching,
  trigger templates, scheduled templates, PDF and e-mail output.
- **Editorial work:** working copies and edit locks, history, trash, batch processing,
  CSV and DOCX export/import (for translation agencies), frontend editing.
- **Media processing** with GD, ImageMagick and ffmpeg (image versions, video, audio).
- **Community:** frontend users, registration, profiles, user contributions, second
  factor (TOTP, e-mail codes), passkeys.
- **Users and rights:** user groups with rights per module, area, instance and record.
- **Security and compliance:** CSRF protection, content security policy, Argon2id
  password hashes, TOTP and passkeys for back-end users, signed rights, audit log with
  hash chain, privacy module (deletion policies, data export), throttling.
- **PHP API and command line** (`Core/cron/camindo.php`) for installation, updates,
  caches, backups and scripted content changes.
- **Framework-free:** few dependencies (PHPMailer, Parsedown; jQuery and Bootstrap in
  the back end).

## Requirements

- PHP 8.3 or newer with `mysqli`, `intl`, `gd`, `json`, `mbstring`
- MySQL 8 or MariaDB 10.6 or newer
- Apache 2.4 with `mod_rewrite`
- Composer 2, shell access
- optional: ImageMagick, ffmpeg, mysqldump

## Installation

```bash
composer create-project camindo/project mysite
```

Composer fetches the skeleton, the core into `Core/` and the libraries into `vendor/`,
then the installer starts. Web server, file permissions and cron jobs are described in
the [quick start guide](docs/en_US/admins/01-quick-start-installation.md)
([Deutsch](docs/de_DE/admins/01-quickstart-installation.md)).

## Update

```bash
php Core/cron/camindo.php maintenance on
composer update camindo/cms
php Core/cron/camindo.php upgrade
```

See [Operation and maintenance](docs/en_US/admins/12-operation.md).

## Documentation

| Manual | English | Deutsch |
|---|---|---|
| Editors | [editors](docs/en_US/editors/README.md) | [Anwender](docs/de_DE/editors/README.md) |
| Administrators | [admins](docs/en_US/admins/README.md) | [Administratoren](docs/de_DE/admins/README.md) |
| Template programmers | [templateprogrammers](docs/en_US/templateprogrammers/README.md) | [Template-Programmierer](docs/de_DE/templateprogrammers/README.md) |

The manuals are also available in the back end (main menu → Manuals).
For extending camindo CMS – modules, media processors, extensions, `(CMS:…)` commands –
see the [developer documentation](https://github.com/dirkintelligence/camindo-developer-docs).

## Related packages

| Package | Purpose |
|---|---|
| [`camindo/project`](https://packagist.org/packages/camindo/project) | project skeleton for `composer create-project` |
| [`camindo/composer-plugin`](https://github.com/dirkintelligence/camindo-composer-plugin) | installs camindo packages into `Core/`, `Modules/`, `Processors/`, `Extensions/` |

## Versioning

camindo CMS follows [semantic versioning](https://semver.org/). Releases are Git tags
(`v9.0.0`); a published tag is never moved. Database changes come as migrations and
are applied by `camindo.php upgrade`.

## Security

Please report vulnerabilities **privately** – see [SECURITY.md](SECURITY.md).

## License

camindo CMS is free software under the
[GNU Affero General Public License v3.0 or later](LICENSE), with an additional
permission: templates, the files they deliver and the content managed with camindo CMS
may be licensed under terms of your choice. Details in [LICENSING.md](LICENSING.md),
third-party components in [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).
