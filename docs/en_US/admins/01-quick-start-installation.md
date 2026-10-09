# 1 Installing camindo CMS

This quick start guide summarizes what you need to get camindo CMS up and running. If you
use camindo CMS in production, please read all chapters, in particular the chapter
[Operation and maintenance](./12-operation.md).

## What you need

A server (or computer) with shell access (SSH or Terminal) and the following installed:

| What | Version | How to check it |
|---|---|---|
| PHP | 8.3 or newer, with the extensions `mysqli`, `intl`, `gd`, `json`, `mbstring` | `php -v` and `php -m` |
| MySQL or MariaDB | MySQL 8 / MariaDB 10.6 or newer | `mysql --version` |
| Apache | 2.4 with `mod_rewrite` and `AllowOverride All` | `apachectl -M \| grep rewrite` |
| Composer | 2.x | `composer --version` |
| Git | any version | `git --version` |
| optional: ImageMagick, ffmpeg, mysqldump | – | `which magick ffmpeg mysqldump` |

- ***ImageMagick*** – Image versions can also be calculated with the GD library built into
  PHP. ImageMagick, however, supports many more formats (such as HEIC, TIFF, PSD) and
  settings.
- **ffmpeg** is required to convert videos and audio files and to extract still images
  from videos.
- **mysqldump** is required for database backups – before updates and through the
  [Scheduler](./10-modules.md#scheduler).

## The steps at a glance

1. Create a database
2. Get the files – the installer starts automatically
3. Answer the installer's questions
4. Set up the web server and file permissions
5. Set up cron jobs
6. Sign in to the back end

---

## Step 1 – Create a database

Have the access data for an ***empty database*** ready. At your hosting provider, you
create it in the customer menu. On your own server, for example, like this:

```bash
mysql -u root -p
```

```sql
CREATE DATABASE camindo CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER 'camindo'@'localhost' IDENTIFIED BY 'A-SECURE-PASSWORD';
GRANT ALL PRIVILEGES ON camindo.* TO 'camindo'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

In ***MariaDB***, the collation is called `utf8mb4_uca1400_ai_ci` (from version 10.10); in
older versions, it is `utf8mb4_unicode_ci`. The installation adjusts the tables to the
server itself.

## Step 2 – Get the files

Change to the directory where the CMS is to be installed (here
`/var/www`), and let Composer create the project:

```bash
cd /var/www
composer create-project camindo/project mysite
```

`mysite` is the name of the new project directory and can be chosen freely.

Composer downloads the skeleton (`composer.json`, `public/`, empty directories), the
core into `Core/`, and the libraries into `vendor/`. The installer then starts
automatically. If it does not start, or if you cancelled it:

```bash
cd /var/www/mysite
php Core/cron/camindo.php install
```

## Step 3 – The installer

The installer first checks the requirements and marks each line with `[ok]`, `[--]`
(missing, but optional) or `[!!]` (missing – the installer aborts):

```
requirements
  [ok] PHP >= 8.3 (8.4.22)
  [ok] PHP extension mysqli
  [ok] PHP extension intl
  [ok] writable: var
  [--] magick: not found
  ...
```

Then it asks questions. The default value is shown in square brackets – press `Enter` to
accept it.

| Question | Your input |
|---|---|
| Database host `[localhost]` | usually `Enter`; at your hosting provider, the host is listed in the access data |
| Database port `[3306]` | `Enter` |
| Database name `[camindo]` | the name of your database |
| Database user `[camindo]` | the name of your database user |
| Database password | the password (not displayed) |
| Time zone `[Europe/Berlin]` | your time zone |
| Locale `[de_DE]` | country/language for date and number formats |
| Will the site be served over HTTPS? `[y]` | `Enter` if the site runs on `https://`. **`n` only for a local test without SSL** – see [Test locally without HTTPS](#test-locally-without-https) |
| Admin name `[admin]` | your sign-in name in the back end (lowercase letters, digits, `_`, `-`) |
| Admin e-mail | your e-mail address (may be left empty, but it is required for the sign-in code) |
| Admin password | according to the password policy (by default at least 12 characters with uppercase and lowercase letters and a special character), enter it twice |
| Backend language (ar_SA\|bn_BD\|de_DE\|en_US\|…) `[en_US]` | `de_DE` for a German back end. The available options are `ar_SA`, `bn_BD`, `de_DE`, `en_US`, `es_ES`, `fr_FR`, `hi_IN`, `ja_JP`, `ko_KR`, `pt_BR`, `ur_PK`, `zh_CN`; the language can be changed later in the personal settings |

After that, everything runs automatically: key file, configuration, directories, database
tables, your user account (superuser), caches.  
At the end, you see:

```
finish
  var/install.lock written
```

The installer runs only once. A repeated call reports “already installed” and does
nothing.

***Three files in the project directory are now important:***

| File | Content | If it is lost |
|---|---|---|
| `.camindo.php` | configuration (database, paths, e-mail) | recreate it with `install --step=config` |
| `.camindo.key` | key for encrypted fields and passwords | **cannot be replaced** – without it, encrypted content is lost. Please keep a copy in a safe place! |
| `.sqlpwd` | password for `mysqldump` | recreate it with `install --step=sqlpwd` |

As an alternative, the passwords and keys from these files can also be stored in
***environment variables***, if your server supports this; for more details, see
[Access data from environment variables](./12-operation.md#access-data-from-environment-variables).

## Step 4 – Set up the web server

The website is **not** located directly in the project directory, but in its
subdirectory **`public/`**. This is intentional: everything the browser must not see
(configuration, keys, code) is one level higher.

Apache virtual host (for example `/etc/apache2/sites-available/mysite.conf`):

```apache
<VirtualHost *:80>
    ServerName example.com
    DocumentRoot /var/www/mysite/public
    <Directory /var/www/mysite/public>
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog  ${APACHE_LOG_DIR}/mysite-error.log
    CustomLog ${APACHE_LOG_DIR}/mysite-access.log combined
</VirtualHost>
```

```bash
sudo a2enmod rewrite
sudo a2ensite mysite
sudo systemctl reload apache2
```

At your hosting provider, set the target directory of the domain in the customer menu
(often called “Document Root” or “Webroot”) to `mysite/public`. Set up HTTPS as usual
(Let's Encrypt, the certificate of your host) – camindo CMS does not require anything
special for this.

### Test locally without HTTPS

camindo CMS is set to HTTPS by default: the sign-in cookies for the back end and for
frontend users are only sent over encrypted connections (`cookie_secure`). The browser
discards these cookies over a plain `http://` address – ***sign-in then fails***, and the
sign-in dialog points this out.

If you only want to try out camindo CMS on your computer and do not want to set up a
certificate:

1. Answer the installer question *Will the site be served over HTTPS?* with
   **`n`** (or call the installer with `--https=n`). The installer then writes the
   following to `.camindo.php`:

   ```php
   'admin' => [
       'cookie_secure' => false,
   ],
   'user' => [
       'cookie_secure' => false,
   ],
   ```

   If you have already run the installer with `y`, enter these two values yourself in
   `.camindo.php`.

2. ***Before the installation is reachable*** – on a server, in the company network, for
   colleagues – set up HTTPS and set both values to **`true`** (or delete the two lines,
   then the default `true` applies). Without this setting, session cookies travel over
   the network unencrypted and can be read.

> Chrome and Firefox treat `http://localhost` (also `http://mysite.localhost`) like a
> secure connection. If you test locally under these addresses, you will usually manage
> with `cookie_secure = true` as well – Safari and other addresses (`http://mysite.test`,
> IP addresses on the network) do not.

***File permissions:*** The web server (usually user `www-data`) must be allowed to write
to `var/` and `public/media/`, and nowhere else:

```bash
sudo chown -R www-data:www-data /var/www/mysite/var /var/www/mysite/public/media
```

If you ran the installer as a different user, the cache files under `var/cache/` also
belong to that user – the command above corrects this.

## Step 5 – Cron jobs

Open the crontab of the web server user:

```bash
sudo crontab -u www-data -e
```

and add ***one*** line:

```
* * * * *   /usr/bin/php /var/www/mysite/Core/cron/cron.php  >/dev/null 2>&1
```

`cron.php` is the only cron job. Every minute, it checks what needs to be done: some
tasks run every minute (applying template changes, converting images and videos), all
other tasks are handled by the [Scheduler](./10-modules.md#scheduler). The installation sets up the
***maintenance tasks*** there – distributed between 2 and 4 a.m.: database backup, emptying
the trash, applying deletion policies and retention periods, cleaning up logs and the
frontend cache, updating the full text, checking the audit log, checking for updates;
temporary files every hour. You can adjust times and periods in the Scheduler (see
[Operation](./12-operation.md#cron-jobs)). Without the cron job, no maintenance takes place.

## Step 6 – Sign in

- Back end: `https://example.com/backend/` – with the name and password from step 3
  (locally without SSL: `http://…/backend/`, see [Test locally without HTTPS](#test-locally-without-https)).
- Frontend: `https://example.com/` – initially reports that no content has been set up yet.

The chapter [The concept](./02-concept.md) describes what comes next in the back end, with
a guide for the first steps.

---

## Install additional components

Additional modules, media processors and extensions are Composer packages. There are
always two steps:

* Composer stores the files,
* camindo CMS sets up the database:


```bash
cd /var/www/mysite
composer require camindo/module-pdf
php Core/cron/camindo.php component install Pdf --activate
```

`php Core/cron/camindo.php component list` shows what is installed and active – or
the module “[Components](./10-modules.md#components)” in the back end.

## If something goes wrong

| Symptom | Cause and remedy |
|---|---|
| Browser shows “camindo is not installed yet” | The installer has not completed → `php Core/cron/camindo.php install` |
| `requirements not met` | A PHP extension or a write permission is missing → the `[!!]` line names it |
| `database connection failed` | Check host, name, user and password → at a hosting provider, the host is often not `localhost` |
| Back end loads without design (CSS/JS missing) | `public/backend/assets` is missing or outdated → `php Core/cron/camindo.php upgrade` copies the files again |
| Error 500 in the frontend | `.htaccess` is missing or `AllowOverride` is not `All` → is `mod_rewrite` active? |
| “503 – Maintenance” after an update | → run `php Core/cron/camindo.php upgrade` (brings the database up to date) |
| Sign-in fails without an error message,<br>the sign-in dialog shows a note about HTTPS | You access the back end via `http://`, but `cookie_secure` is `true` → see [Test locally without HTTPS](#test-locally-without-https) |
| Sign-in code does not arrive | → set up an e-mail mailbox in `.camindo.php` (see [Operation](./12-operation.md#email-dispatch)) |
| Error 500 in the frontend and back end,<br>on the command line:<br>`CONFIGURATION ERROR:`<br>`paths.key_file -`<br>`file not found or not readable` | The key file `.camindo.key` is missing, or the user under which the web server or cron job runs is not allowed to read it → check owner and permissions (`chmod 600 .camindo.key`; if the web server can only read the file through the group on your system, `chmod 640`). If the file is missing, restore it from your [data backup](./12-operation.md#data-backup) – **do not generate a new one**: `install --step=key` would write a new key, and encrypted content and passwords would then be unusable. If the key comes from the environment variable `CAMINDO_KEY`, check that it is set for the web server **and** the cron job |
| Something else | → `php Core/cron/camindo.php status` shows the state of the system; `var/log/` contains the details |

Individual steps of the installer can be repeated without touching the database:
`php Core/cron/camindo.php install --step=dirs` (likewise `key`, `config`, `sqlpwd`,
`runtime`).

Updates are described in the chapter [Operation and maintenance](./12-operation.md#updates).
