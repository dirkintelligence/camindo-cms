# Threat model

The scanner reads this file before it starts. It covers the camindo CMS core (`camindo/cms`).
The developer documentation (<https://github.com/dirkintelligence/camindo-developer-docs>,
`en_US/`) describes architecture, permissions and security in detail; chapters 11
(Permissions), 12 (Frontend community), 13 and 14 (Security) are the most relevant.

## What this project does and where untrusted input enters

camindo is a PHP content management system with several sites (instances) per installation,
a backend for editors and a frontend rendered from templates. Untrusted input enters:

- **Frontend** (`public/index.php`, `public/file.php`, `public/csp_report.php`): paths, query
  strings, cookies, headers, and the frontend community – registration, login, second factor,
  password reset, profile and contribution forms (`CMS:USER_*` commands, `RecordUser`,
  `UserSession`), protected media (`MediaDelivery`, `MediaAccess`) and uploads from visitors.
- **Backend** (`public/backend/…`, scripts under `Modules/*/backend/`): the login (password,
  e-mail code, TOTP, passkeys/WebAuthn), and everything a logged-in backend user with limited
  rights sends: record forms, list filters and sorting, imports (CSV, DOCX, media, ZIP),
  uploads, batch processing, search.
- **PHP API / CLI API** (`src/Api`, `camindo.php api …`) for users whose group has the
  interface `php` or `cli`.
- **Stored content** that one user enters and others see: record fields (rich text goes
  through `HtmlSanitizer`), file names, labels, snippets, messages.

### Where the code is

| Path | What it is |
| --- | --- |
| `/src` | The core checkout. Patches belong here. |
| `/opt/camindo` | A working installation built from the project skeleton (`install/draft`), served by Apache with `.htaccess` enabled, on MariaDB. `/opt/camindo/Core` is a **copy** of `/src`; run `camindo-sync` after changing `/src` to copy it there again. |
| `/opt/camindo/var` | Runtime data: caches, logs (`var/log/`, mails in `*_mail-debug.log`), uploads, templates (`var/templates/{instance}/…`). |

### Trust model

- **Anonymous visitors** and **frontend users** (community accounts) are untrusted.
- **Backend users** must stay within their user group's rights: interfaces, modules and
  their extra rights, instances, languages, statuses and area rights (`Access`,
  `Dataset::accessCheck()`, `Backend::backendPath()` → `Access::script()`). Bypassing any of
  these is a finding.
- **Trusted, by design able to run code or reconfigure the system** – an issue that needs one
  of these is not a vulnerability unless it gives more than the right already does:
  - the **superuser** group (group 1) and every superuser-only module (Admin group management,
    Area, Component, Converter, Instance, Language, Scheduler, Status, Audit, Logfiles),
  - the **Template** module: templates are PHP files (`*.cms.php`) and run as code, so a
    user who may edit templates is trusted; the HTML filter deliberately does not apply to
    the `template` table,
  - the **Tools** module's extra rights `server`, `database`, `developer`,
  - a template that uses `ignoreuser=true` or prints values without escaping (the template
    programmer's responsibility).
- `.camindo.php`, `.camindo.key`, the environment and the file system are trusted operator
  input. The attacker models of developer docs chapter 13 apply: a **DB writer** without
  the file system must not be able to log in, escalate rights or forge signed rows
  (`Integrity` signatures, `AuditStore` HMAC chain); a **script in the browser** must not get
  past the CSRF token, the backend script policy (nonces) or `HttpOnly` cookies.

## Components that matter most / least

Highest priority:

1. Authentication: backend login and second factors, sessions (`Session`, `UserSession`),
   login throttling (`LoginGuard`, `RateLimiter`), password reset, passkeys (`WebAuthn`),
   the frontend handover and preview tokens (`FrontendHandover`, backend domain).
2. Access bypass in the backend and the API: group rights, area rights, record owner and
   status rules, language rights, `shared`/`personal` scripts, the PHP API (`src/Api`).
3. SQL injection through `Dataset`, `Cms::select()` / `CMS:SELECT` filters and sorting,
   full-text search, collation handling.
4. XSS: `HtmlSanitizer` (write and read filter), backend output, the CSP nonce handling,
   `jsString`/attribute escaping, file names and labels in lists.
5. Uploads and media: active content detection (`MediaAccess::isActiveContent()`),
   `.htaccess` written by `HtaccessWriter`/`MediaAccess`, path traversal in file names,
   `public/file.php`, image/video processing (GD, ImageMagick policy, FFmpeg), ZIP import.
6. CSRF, open redirects, cache poisoning (`HtmlCache`, `IncludeCache`), mail header injection
   (`Email`), unserialize or similar object injection.

Lower priority or out of scope:

- `vendor/` and bundled third-party libraries (`public_master/backend/assets/`: Bootstrap,
  jQuery, qrcode …) – report upstream, unless camindo uses them in a way that creates a hole.
- The self-tests in `cron/check_*.php` and `.github/`.
- Components that are not part of this repository (modules such as Ai or Pdf).

## How to exercise it

- Run `camindo-start` first. It starts MariaDB and Apache (no init system) and is safe to run
  again; `camindo-stop` stops both. The backend is at `http://127.0.0.1/backend/index.php`.
- The installation has:
  - `admin` / `Scanner-Admin-2026!x`: superuser, fully trusted
  - `editor` / `Scanner-Editor-2026!x`: group "Editor" – module Record only, instance
    "Scanner", its language read and write. Useful for access-control reproductions.
  - anonymous: no login
  The frontend has no templates yet: create an area and a template (as `admin`, or with the
  PHP API) to exercise `CMS:*` commands and the community.
- `camindo …` runs `Core/cron/camindo.php` as `www-data`, e.g. `camindo status`,
  `camindo cache all`, `camindo api login --admin=admin --lifetime=600`, then
  `camindo api call METHOD '{…}' --session=…`.
- Headless backend scripts: in PHP, `define('CAMINDO_STARTED', true); define('CAMINDO_BACKEND', true);
  require '/opt/camindo/load_camindo.php'; $app->get(\Camindo\Core\Session::class)->setCliAdmin($id);`
  then require a script from `Core/Modules/*/backend/`. For HTTP tests with a real session:
  `Session::create($adminId)` returns a session id (cookie `sessid`), `Session::csrfToken()`
  the token (header `X-Camindo-Token`).
- The database is `camindo` (user `camindo`, password `camindo`) on 127.0.0.1;
  `mariadb camindo` as root opens a client. PHP errors go to stderr, not into responses.
- Self-tests: `php Core/cron/check_*.php` in `/opt/camindo` (as `www-data`).

## How you rate severity

Rate with CVSS 3.1 (or 4.0) and give the vector. Rules of thumb:

- Unauthenticated SQL injection, remote code execution or authentication bypass: critical.
- Privilege escalation of a restricted backend user to superuser, or to the Template module
  (= code execution): critical.
- Stored XSS that a frontend user or anonymous visitor can place and that runs in the backend:
  high. Stored XSS by a restricted backend user against other backend users: medium to high.
  Reflected XSS against anonymous visitors: medium to high, depending on the CSP.
- Access bypass between groups, instances, areas or languages (read or write): medium to high
  depending on the data; reading another frontend user's profile or protected media: high.
- A DB writer (no file system) who can log in or forge a signed row: high.
- CSRF on a state-changing request: medium; open redirect: low to medium.
- Denial of service with a single small request: low to medium; volumetric DoS is out of scope.

## How reports and patches should look

- Name the version (`camindo status`), file and function, the least privileged role that can
  trigger it, the CVSS vector, and a self-contained reproducer (curl against
  `http://127.0.0.1/`, a CLI/API call, or a short PHP script like the headless recipe above).
- Patches as `git diff` against `/src`, code style as in the surrounding code (comments and
  identifiers in US English), using the existing helpers (`HtmlSanitizer`, `Integrity`,
  `Access`, prepared statements via `Database::query()`) rather than ad hoc fixes.

## Anything to leave alone

- Missing hardening that the documentation leaves to the operator: HTTPS, HSTS (opt-in),
  `cookie_secure` (off in this image because it runs on http://), the backend domain,
  server configuration, updates of PHP, the database or ImageMagick.
- Version disclosure, self-XSS, and issues that need `display_errors` or other `.camindo.php`
  changes.
- Findings that need one of the trusted rights listed above.
