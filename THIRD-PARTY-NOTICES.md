# Third-party notices

camindo CMS itself is licensed under the GNU AGPL v3 or later (see `LICENSING.md`).
It contains or uses the following third-party components. Each component
remains under its own license; the full license texts are shipped next to the
component (path in the table) or, for Composer packages, in `vendor/{package}/`.

## Bundled with the core

| Component | Version | License | Copyright | Location / license text |
|---|---|---|---|---|
| [Bootstrap](https://getbootstrap.com/) | 5.3.8 | MIT | 2011-2025 The Bootstrap Authors | `public_master/backend/assets/bootstrap/` (license header in each file) |
| [jQuery](https://jquery.com/) | 3.7.1 | MIT | OpenJS Foundation and other contributors | `public_master/backend/assets/jquery/` (license header) |
| [qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator) | 1.4.4 | MIT | 2009 Kazuhiko Arase | `public_master/backend/assets/qrcode/LICENSE.txt` |
| [Material Symbols](https://github.com/google/material-design-icons) (font) | - | Apache-2.0 | Google LLC | `public_master/backend/assets/font/MaterialSymbols/LICENSE.txt` |
| [Material Symbols](https://fonts.google.com/icons) (SVG icons in the manuals) | - | Apache-2.0 | Google LLC | `docs/de_DE/_/LICENSE.txt` |
| [Source Sans 3](https://github.com/adobe-fonts/source-sans) (font) | - | OFL-1.1 | 2010-2020 Adobe, Reserved Font Name "Source" | `public_master/backend/assets/font/SourceSans3/OFL.txt` |
| [vanillajs-datepicker](https://github.com/mymth/vanillajs-datepicker) (extension Datepicker) | - | MIT | 2019 Hidenao Miyamoto | `Extensions/Datepicker/LICENSE.vanillajs-datepicker` |

The fonts are served as separate files, converted to WOFF2 with fonttools.

## Composer dependencies of the core

Installed by Composer into `vendor/`, not modified.

| Package | License | Copyright |
|---|---|---|
| [erusev/parsedown](https://github.com/erusev/parsedown) | MIT | 2013-2018 Emanuil Rusev |
| [phpmailer/phpmailer](https://github.com/PHPMailer/PHPMailer) | LGPL-2.1-only | Marcus Bointon, Jim Jagielski, Andy Prevost, Brent R. Matzelle |

PHPMailer is used as a library without modification; it can be replaced by any
other version via Composer (LGPL-2.1 section 6).

## Optional components (distributed separately)

These components are not part of the core. They are installed only on request
(`composer require …`) and are licensed under their own terms.

| Component | License | Uses | License of the used library |
|---|---|---|---|
| `camindo/extension-tinymce` (`Extensions/TinyMce/`) | GPL-2.0-or-later | [TinyMCE](https://www.tiny.cloud/) 8, Ephox Corporation DBA Tiny Technologies, Inc. | GPL-2.0-or-later or commercial (`vendor/tinymce/tinymce/license.md`) |
| `camindo/module-pdf` (`Modules/Pdf/`) | LGPL-3.0-or-later | [tc-lib-pdf](https://github.com/tecnickcom/tc-lib-pdf) and its `tc-lib-*` dependencies, Nicola Asuni / Tecnick.com LTD | LGPL-3.0-or-later |
| `camindo/module-pdf`: standard font metrics (`Modules/Pdf/fonts/core/`) | see `fonts/core/LICENSE` | derived from the Adobe Core 14 AFM files, Adobe Systems Incorporated | Adobe AFM license |
| `camindo/module-ai` (`Modules/Ai/`) | AGPL-3.0-or-later | [anthropic-ai/sdk](https://github.com/anthropics/anthropic-sdk-php), Anthropic, PBC; [Guzzle](https://github.com/guzzle/guzzle) with its PSR and Symfony helper packages | MIT |

## Trademarks

Bootstrap, jQuery, TinyMCE, Material Symbols, Source and all other product names are
trademarks of their respective owners. Their mention only identifies the component.
