# camindo/project

Project skeleton for [camindo CMS](https://github.com/dirkintelligence/camindo-cms).

```bash
composer create-project camindo/project mysite
```

Composer creates `mysite/` from this skeleton, installs the core (`camindo/cms`) into
`Core/` and the libraries into `vendor/`, then starts the installer. Web server, file
permissions and cron jobs: see the
[quick start guide](https://github.com/dirkintelligence/camindo-cms/blob/main/docs/en_US/admins/01-quick-start-installation.md)
([Deutsch](https://github.com/dirkintelligence/camindo-cms/blob/main/docs/de_DE/admins/01-quickstart-installation.md)).

> Deutsch: Grundgerüst für ein neues camindo-CMS-Projekt – `composer create-project
> camindo/project mysite` legt das Projekt an und startet den Installer.

## What is in the skeleton

| Path | Purpose |
|---|---|
| `composer.json` | requires `camindo/cms` and `camindo/composer-plugin`; Composer hooks for install and status |
| `load_camindo.php` | loads configuration and bootstrap |
| `public/` | document root with stubs that call into `Core/public_master/` |
| `Modules/`, `Processors/`, `Extensions/` | add-on components installed by Composer |
| `var/` | runtime data (cache, logs, uploads) – outside the document root |
| `.gitignore` | what does not belong in your project's Git repository |

The `"license"` in `composer.json` is the license of **your** project – change it as
you see fit. Commit `composer.lock`: it is your way back if an update fails.

camindo CMS is licensed under the AGPL v3 or later with an additional permission for
templates and content, see
[LICENSING.md](https://github.com/dirkintelligence/camindo-cms/blob/main/LICENSING.md).
