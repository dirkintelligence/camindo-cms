# Security Policy

camindo CMS is open-source software provided **"as is"**, without warranty of any kind
(see the license). The maintainers handle security reports on a best-effort basis.
The response times below are **goals, not guarantees**.

> Deutsch: Sicherheitslücken bitte **vertraulich** an camindo@camindo.de melden, nicht
> öffentlich. Die unten genannten Fristen sind Ziele, keine Zusagen.

## Supported versions

| Version | Security fixes |
|---|---|
| 9.x (current major) | yes – as a patch release of the latest minor version |
| previous major | for 12 months after the release of the next major (goal) |
| older | no |

Security fixes are published as PATCH releases (`9.x.Y`). Always run the latest
patch release of your minor version.

## Reporting a vulnerability

Please **do not** open a public issue, pull request or forum post.

- E-mail: **camindo@camindo.de** (subject: `[security] …`)
- GitHub: [*Report a vulnerability*](https://github.com/dirkintelligence/camindo-cms/security/advisories/new)
  (private vulnerability reporting) on `dirkintelligence/camindo-cms`

Please include:

- affected version(s) (`php Core/cron/camindo.php status`) and component (core,
  module, extension, processor),
- a description of the issue and its impact,
- steps to reproduce or a proof of concept (no data of third parties),
- whether and when you plan to publish your findings.

Reports in German or English are welcome.

## What happens next (goals)

| Step | Goal |
|---|---|
| Acknowledgement of receipt | within 3 working days |
| First assessment (confirmed / not reproducible, severity per CVSS 3.1/4.0) | within 10 working days |
| Fix released – critical | within 7 days after confirmation |
| Fix released – high | within 30 days |
| Fix released – medium / low | within 90 days, or with the next regular release |
| Public disclosure | coordinated with the reporter, at the latest 90 days after the report, or earlier once a fix is available |

We keep you informed about the progress and credit you in the advisory unless you
prefer to stay anonymous.

## Publication

- a PATCH release of every supported version,
- a security advisory (GitHub Security Advisory, CVE requested for relevant issues)
  with affected versions, severity, fix version and workarounds,
- a note in the release notes.

camindo CMS checks daily for newer versions and known vulnerabilities of installed
Composer packages (`composer outdated` / `composer audit`, backend: main menu →
Info). Advisories for `camindo/*` packages (core: `camindo/cms`) reach
installations through this check.

## Safe harbor

We will not take legal action against research carried out in good faith that

- respects this policy and reports findings privately,
- avoids privacy violations, data destruction and service interruption,
- only uses accounts and data of your own or with explicit permission,
- does not use social engineering, physical attacks or denial of service.

## Out of scope

- vulnerabilities in websites operated with camindo CMS (contact the operator – see
  its `/.well-known/security.txt`) and in their templates,
- missing hardening that is documented as the operator's responsibility (HTTPS,
  HSTS, server configuration, updates of PHP, database, ImageMagick …),
- reports from automated scanners without a demonstrated impact.
