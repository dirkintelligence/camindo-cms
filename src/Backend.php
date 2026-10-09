<?php
/**
 * Building blocks of the backend UI. Instance name in the code: $backend.
 *
 * Every backend page is built by calls to this class: header and footer, tabs,
 * buttons, tables, info blocks, form fields, pagination bars. It generates HTML,
 * but contains none itself - everything visible comes from the .html templates under
 * Core/backend/html resp. Core/Modules/{module}/backend/html.
 *
 * IT IS STATEFUL
 * --------------------
 * Form, tabs, buttons and info blocks are built up step by step and only retrieved at the end.
 * Typical flow of an input mask:
 *
 *     $backend->formInit('admin.admin_edit_form');   // template with {data_*} placeholders
 *     $backend->formHidden('function', 'save');
 *     $backend->formField($admin, ['name' => 'name', 'value' => $data['name'], 'id' => $id]);
 *     echo $backend->form('edit_form_buttons');
 *
 * formField() replaces the placeholder {data_{name}} in the remembered form. Because
 * the instance is a shared service (one object per request), each new
 * block must be preceded by the matching reset: formInit(), tabBarReset(), buttonBarReset(), infoReset().
 *
 * TWO KINDS OF TEMPLATES
 * --------------------
 *     template($tpl, $replace)   HTML template from the cache. {plain} is replaced via strtr,
 *                                {#name} automatically by the language snippet of the same name.
 *     Cms::snippet($index)       plain text, multilingual.
 *
 * Both come EXCLUSIVELY from var/cache/. After every change to an .html or
 * lang/ file the cache must be rebuilt (module Tools), otherwise the old
 * version continues to be delivered.
 *
 * TWO WHITELISTS AS A DEVELOPMENT AID
 * --------------------
 *     $displayTypes      which display_type fits which field type
 *     $validationFlags   which validation flags are offered per field type
 *
 * formField() checks against these. If something does not fit, an error message
 * appears in the form in place of the field - no exception is thrown. Schema errors thus become
 * visible immediately during development, without breaking the whole page.
 *
 * ROUTING
 * --------------------
 * backendPath() is the entry point for all Ajax calls: it translates "{module}/{script}"
 * into "{module}/backend/{script}", sanitizes the path and checks session, CSRF token
 * (Session::verifyCsrfRequest(), only for POST) and module rights. It is called from
 * Core/public_master/backend/module.php.
 *
 * TEASER
 * --------------------
 * teaser($type, $id) renders a compact reference to another record
 * (icon + label + link to its info page). Which label, which icon
 * and which script apply is defined in $teaserDefaults.
 *
 * @see Camindo\Core\Cms                  snippets, queries, formatting
 * @see Camindo\Core\Dataset              schema and persistence
 * @see developer docs, chapter 05 (Backend development)     structure of a backend page
 */
namespace Camindo\Core;

/**
 * @property-read Pagination $pagination  Lazily loaded, see $lazyServices
 * @property-read AuditLog   $auditLog    Lazily loaded (tab "Changes")
 * @property-read AuditDiff  $auditDiff   Lazily loaded (tab "Changes")
 */
class Backend {

    // Lazily loadable services - filled in the constructor, see there.
    // --------------------
    use LazyServicesTrait;
    /**
     * Allowed display_type values per field type.
     * formField() rejects everything not listed here - see class comment.
     * 'meta' is a pseudo type for meta fields without their own type specification.
     *
     * 'sortable' is the sortable variant of 'checkbox': every option stands in
     * its own .c9-smartrows-row and can be moved with the arrow buttons.
     * The order of the checked options is the stored value.
     *
     * 'reltable' (content lies in its own relation table, see
     * Cms::$dataSuffix) is handled here like 'json'.
     */
    public array $displayTypes = [
        'chars'      => ['text', 'textarea', 'email', 'tel', 'select', 'radio'],
        'encrypted'  => ['text', 'textarea', 'email', 'tel', 'select', 'radio'],
        'text'       => ['text', 'textarea', 'select', 'radio'],
        'json'       => ['checkbox', 'multiselect', 'sortable'],
        'reltable'   => ['checkbox', 'multiselect', 'sortable'],
        'password'   => ['password'],
        'fulltext'   => ['text'],
        'bit'        => ['switch', 'checkbox', 'radio'],
        'integer'    => ['text', 'select', 'radio'],
        'id'         => ['select', 'radio', 'lookup'],
        'ids'        => ['lookup'],
        'currency'   => ['text', 'select', 'radio'],
        'float'      => ['text', 'select', 'radio'],
        'decimal'    => ['text', 'select', 'radio'],
        'timestampl' => ['text'],
        'datetime'   => ['text', 'select', 'radio'],
        'time'       => ['text', 'select', 'radio'],
        'relation1'   => ['lookup', 'select'],
        'relation'   => ['lookup', 'select', 'multiselect'],
        'pointer1'    => ['lookup', 'select'],
        'pointer'    => ['lookup', 'select', 'multiselect'],
        'link'       => ['lookup', 'embed'],
        'file'       => ['upload', 'thumbnail'],
        'meta'       => ['select', 'radio'], // pseudo type
    ];
    /**
     * Text input types (text, numeric, date/time) that allow selection instead of input
     * Caution: check !in_array($type, $this->cms->relationTypes) beforehand
     */
    public array $optionDisplayTypes = ['select', 'radio', 'checkbox', 'multiselect', 'sortable'];
    /**
     * Validation flags offered per field type in the backend for selection.
     * Purely for the UI (field input mask in the Area module); the actual
     * check is done by Dataset::validateFieldValue(), which knows more flags - e.g.
     * 'trim', 'strip_tags' and 'name', which are not selectable but are stored
     * fixed in the schema.
     * Reference (relation*, pointer*, link) and file fields do not offer 'unique' /
     * 'unique_in_area': Dataset::uniqueVirtualOk() does not check them there.
     */
    public array $validationFlags = [
        'chars'      => ['not_empty', 'unique_in_area', 'unique'],
        'encrypted'  => ['not_empty', 'unique_in_area', 'unique'],
        'text'       => ['not_empty', 'unique_in_area', 'unique'],
        'json'       => ['not_empty'],
        'reltable'   => ['not_empty'],
        'password'   => ['not_empty'],
        'fulltext'   => [],
        'bit'        => [],
        'integer'    => ['not_empty', 'not_zero', 'unique_in_area', 'unique'],
        'id'         => ['not_empty'],
        'ids'        => ['not_empty'],
        'currency'   => ['not_empty', 'not_zero', 'unique_in_area', 'unique'],
        'float'      => ['not_empty', 'not_zero', 'unique_in_area', 'unique'],
        'decimal'    => ['not_empty', 'not_zero', 'unique_in_area', 'unique'],
        'timestampl' => ['not_empty', 'unique_in_area', 'unique'],
        'datetime'   => ['not_empty', 'unique_in_area', 'unique'],
        'time'       => ['not_empty', 'unique_in_area', 'unique'],
        'relation1'   => ['not_empty'],
        'relation'   => ['not_empty'],
        'pointer1'    => ['not_empty'],
        'pointer'    => ['not_empty'],
        'link'       => ['not_empty'],
        'file'       => ['not_empty'],
    ];
    /** Cache of the HTML templates, loaded once per prefix from var/cache/html/ */
    private array $htmlTemplates = [];

    /**
     * Blueprint for teaser(): per record kind the column for the label, the
     * HTML template for the icon and the backend script of the info page.
     * The commented-out block below shows the naming pattern for further kinds.
     */
    private array $teaserDefaults = [
        'admin' => [
            'label' => 'name',
            'icon' => 'admin.icon_admin',
            'script' => 'Admin/admin_info.php'
        ],
        'group' => [
            'label' => 'label',
            'icon' => 'admin.icon_group',
            'script' => 'Admin/group_info.php'
        ],
        'field' => [
            'label' => 'label',
            'icon' => 'area.icon_field',
            'script' => 'Area/field_info.php'
        ],
        'template' => [
            'label' => 'name',
            'icon' => 'template.icon_template',
            'script' => 'Template/template_info.php'
        ],
        /*
        '{module}' => [
            'label' => 'label',
            'icon' => '{module}.icon_{module}',
            'script' => '{Module}/{module}_info.php'
        ],
        */
    ];
    // Buffer of the page elements under construction.
    // Empty each with tabBarReset() / buttonBarReset() / infoReset() / formInit()
    // before a new block begins - the instance lives for the whole request.
    private array $tabs = [];
    private array $buttons = [];
    private string $prevLink = '';
    private string $nextLink = '';
    private array $infoBlocks = [];
    private array $infoStack = [];  // info collections set aside, see infoPush()
    private string $form = '';
    private array $formStack = []; // forms set aside, see formPush()

    public string $emptyPlaceholder = '';

    /**
     * Records whose original file name the info page offers to edit (pencil after
     * "Original filename", RecordBackendInfo::fileRename()).
     * RecordBackendInfo sets it for the record if write permission exists.
     * @var array<int, true>
     */
    public array $fileRenameIds = [];
    private array $defaultFieldCache = [];

    private array $areaCache = [];

    /**
     * Formats a browser can display directly. Anything else gets a placeholder image
     * from media/default/ in the backend preview (see defaultImg()).
     */
    public $webImageFormats = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp',
    ];

    public function __construct(
        private Config $config,
        private Cms $cms,
        private Database $db,
        private Crypt $crypt,
        private Session $session,
        private System $system,
        protected ContainerInterface $container
    )
    {
        // Lazily loadable services
        // --------------------
        // Only whoever outputs a list needs pagination bars - paginationTop() and
        // paginationBottom(). Info and form pages do not load Pagination at all.
        //
        // (The assignment is here and not as a start value on the property, because PHP
        // does not allow overriding a trait property with a different start value
        // within the same class.)
        //
        // @see Camindo\Core\LazyServicesTrait
        $this->lazyServices = [
            'pagination' => Pagination::class,
            'auditLog' => AuditLog::class,
            'auditDiff' => AuditDiff::class,
        ];

        $this->emptyPlaceholder = $this->cms->snippet('backend_empty_value');
    }

    /**
     * Load a backend page - path: "module name/script name"
     * -> resolved to {module directory}/backend/script name
     *
     * The module directory comes from the runtime list (Cms::$modules, 'path'):
     * Core/Modules/{Name} or Modules/{Name}. A module missing there (not
     * installed, inactive, update pending) is not reachable in the backend.
     *
     * @param string $file  _path from the Apache RewriteRule
     * @return string       absolute path to the backend script to include
     */
    public function backendPath(string $path, string $kind = 'modules'):string
    {
        $path = $this->sanitizePath($path);

        // Insert "backend" after the component name:
        $parts = explode('/', $path);
        array_splice($parts, 1, 0, 'backend');

        // absolute path to the script via the runtime list (only active components)
        $moduleDir = in_array($kind, ['modules', 'extensions'], true) ? $this->cms->componentPath($kind, $parts[0]) : null;
        if ($moduleDir === null) {
            die($this->template('alert_danger', [
                '{text}' => ($kind === 'extensions' ? 'Extension' : 'Module') . ' not available: ' . $this->escape($parts[0]),
            ]));
        }
        $file = $moduleDir . '/' . implode('/', array_slice($parts, 1));

        if (!is_file($file)) {
            die($this->template('alert_danger', ['{text}' => 'File not found: ' . $this->escape($path)]));
        };

        // Check the session - the only exception is the login itself. The caches are built
        // by the installer resp. `camindo.php upgrade|cache`, the first admin by the
        // installer; the backend no longer needs a way without login.
        $exempt = str_starts_with($path, 'Start/login.php');
        $hasSession = (bool)$this->session->load();
        // Origin: a modifying request must - if the browser says so - come from
        // this page (developer docs, chapter 14 (Security: web)). Also applies to the login itself.
        if (!$this->originAllowed()) {
            die('##accessdenied##');
        }
        if (!$exempt && !$hasSession) {
            die('##accessdenied##');
        }
        // CSRF: every modifying request (everything except GET/HEAD) must bring the token of the
        // session - developer docs, chapter 14 (Security: web). The same response as with a missing
        // session: the JavaScript shows the login dialog over it, and an attacker
        // does not learn what the cause was.
        // Exception: the login form of a second window, which was loaded without a session
        // (and thus without a token) while the user had already logged in elsewhere -
        // StartBackend::login() then only returns the existing session
        // and closes the dialog, so it changes nothing.
        if (!($exempt && $hasSession) && !$this->session->verifyCsrfRequest()) {
            die('##accessdenied##');
        }
        // Module access of the user group (Access::script(): module allowed and, if applicable,
        // additional right of the script, or a 'shared' script in the lookup call).
        // The message appears in the layer.
        // Extensions have no module rights (developer docs, chapter 08 (Components and extensions)): session + active is enough.
        if ($kind === 'modules' && $hasSession && !$this->access()->script($parts[0], $parts[2] ?? '')) {
            $this->access()->denied();
        }

        return $file;
    }

    /**
     * Origin check of modifying requests (developer docs, chapter 14 (Security: web)) - third layer
     * alongside the CSRF token and POST binding.
     *
     * Modern browsers send the header Origin with POST, otherwise usually a Referer.
     * If it comes from a different host than the backend (HTTP_HOST, behind a
     * proxy also X-Forwarded-Host) and is not in admin.allowed_origins, it is
     * rejected. "Origin: null" (sandbox, file://, certain redirects) likewise.
     * If both headers are missing - privacy extensions, Referrer-Policy no-referrer -,
     * the token remains the check: rejecting would lock out such browsers.
     *
     * GET and HEAD are not checked; they have no side effects (requirePost()).
     */
    public function originAllowed(): bool
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'GET' || $method === 'HEAD' || PHP_SAPI === 'cli') return true;

        $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
        $source = 'origin';
        if ($origin === '') {
            $origin = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
            $source = 'referer';
            if ($origin === '') return true;
        }
        if ($origin !== 'null') {
            $parts = parse_url($origin);
            $host = strtolower((string)($parts['host'] ?? ''));
            if ($host !== '') {
                $hostPort = $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
                $normalized = strtolower((string)($parts['scheme'] ?? '')) . '://' . $hostPort;
                $ownHosts = array_filter([
                    strtolower((string)($_SERVER['HTTP_HOST'] ?? '')),
                    strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''))[0])),
                ]);
                if (in_array($hostPort, $ownHosts, true)) return true;
                foreach ((array)$this->config->get('admin.allowed_origins') as $allowed) {
                    if (rtrim(strtolower((string)$allowed), '/') === $normalized) return true;
                }
            }
        }
        $this->system->log('security', "Foreign {$source} rejected: {$method} "
            . (string)($_SERVER['REQUEST_URI'] ?? '') . ' ' . mb_substr($origin, 0, 200)
            . ' admin=' . (int)$this->session->get('admin_id'));
        return false;
    }

    /**
     * Modifying action: the request MUST be a POST (developer docs, chapter 14 (Security: web)).
     *
     * Second half of the CSRF protection. The token (Session::verifyCsrfRequest())
     * is only required for POST - and could therefore accomplish nothing as long as
     * the same action also runs via GET: `SameSite=Lax` sends the cookie along with
     * top-level GET navigation. Only the method binding closes the gap.
     *
     * The caller aborts on false - with Access::denied() (small Ajax endpoints)
     * or Access::deniedHtml() plus container_footer (full pages).
     */
    public function requirePost(): bool
    {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') return true;
        $this->system->log('security', 'Action requires POST: '
            . (string)($_SERVER['REQUEST_URI'] ?? '')
            . ' admin=' . (int)$this->session->get('admin_id'));
        return false;
    }

    /**
     * Extension of a field, if it has one and it is active:
     * ['name' => 'calendar' (lowercase like data-c9-ext and the ext/ directory),
     *  'options' => …]. Virtual fields: details.extension / details.extension_options
     * (field.details is still the JSON string in the column definition); meta fields
     * from the PHP schema: 'extension' => 'Calendar', 'extension_options' => [].
     */
    public function fieldExtension(array $colDef): ?array
    {
        $name = (string)($colDef['extension'] ?? '');
        $options = (array)($colDef['extension_options'] ?? []);
        if ($name === '' && isset($colDef['details'])) {
            $details = is_array($colDef['details']) ? $colDef['details'] : (json_decode((string)$colDef['details'], true) ?: []);
            $name = (string)($details['extension'] ?? '');
            $options = (array)($details['extension_options'] ?? []);
        }
        if ($name === '' || !isset($this->cms->extensions[$name])) return null;
        // settings.php 'options_provider': class whose clientOptions() adds the options
        // for the browser (extension Password: effective rules + texts)
        $provider = (string)($this->cms->extensions[$name]['options_provider'] ?? '');
        if ($provider !== '' && method_exists($provider, 'clientOptions')) {
            $options = $this->container->get($provider)->clientOptions($options);
        }
        return ['name' => preg_replace('/[^a-z0-9_]/', '', strtolower($name)), 'options' => $options];
    }

    /**
     * Storage of project-specific files of an extension: var/extensions/{name}/ with
     * {name} in lowercase like the ext/ directory and data-c9-ext (developer docs, chapter 08 (Components and extensions)).
     * Stylesheets for editors live there; the extension delivers them via its own
     * endpoint (backendExtensions/{Name}/css.php), because var/ is not in the
     * docroot.
     */
    public function extensionVarDir(string $extension): string
    {
        return (string)$this->config->get('paths.var_dir') . '/extensions/'
            . preg_replace('/[^a-z0-9_]/', '', strtolower($extension));
    }

    /**
     * Files in var/extensions/{name}/ with one extension, sorted - e.g. the
     * selectable stylesheets in backend/form.php of an extension.
     *
     * @return string[]  file names without path
     */
    public function extensionVarFiles(string $extension, string $ext = 'css'): array
    {
        $dir = $this->extensionVarDir($extension);
        if (!is_dir($dir)) return [];
        $files = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name[0] !== '.' && is_file("$dir/$name") && strtolower(pathinfo($name, PATHINFO_EXTENSION)) === $ext) {
                $files[] = $name;
            }
        }
        return $files;
    }

    /**
     * Active extensions that match the field type and display type (settings.php
     * display_types / field_types) - for the selection in the field input mask.
     *
     * @return array  Name => ['label' => …, 'display_types' => [...]]
     */
    public function extensionsFor(string $type, ?string $displayType = null): array
    {
        $list = [];
        foreach ($this->cms->extensions as $name => $settings) {
            $displayTypes = array_map('strval', (array)($settings['display_types'] ?? []));
            $fieldTypes = array_map('strval', (array)($settings['field_types'] ?? []));
            if ($fieldTypes && !in_array($type, $fieldTypes, true)) continue;
            if ($displayType !== null && !in_array($displayType, $displayTypes, true)) continue;
            $list[(string)$name] = [
                'label'         => $this->cms->textOrSnippet((string)($settings['label'] ?? $name)),
                'display_types' => $displayTypes,
            ];
        }
        return $list;
    }

    /**
     * Backend languages: codes for which snippets exist (Cms::backendLanguages()).
     * Basis of the selection lists in Language::backend_code, in the personal
     * settings (AdminBackend::settings()) and in the login form.
     *
     * @return string[]  z. B. ['de', 'en']
     */
    public function backendLanguages(): array
    {
        return $this->cms->backendLanguages();
    }

    /**
     * Selection list of the backend languages: code => label.
     * The label comes from the snippet backend_language_{code}, otherwise the code.
     *
     * @return array  code => label
     */
    public function backendLanguageOptions(): array
    {
        $options = [];
        foreach ($this->backendLanguages() as $code) {
            $label = $this->cms->snippet('backend_language_' . $code);
            $options[$code] = ($label === 'backend_language_' . $code) ? $code : $label;
        }
        return $options;
    }

    /**
     * Selection list of the locales (date, time and number formats): ICU identifier
     * => "German (Austria) - de_AT", named in the backend language and then
     * sorted. Only locales with a region (Cms::localeIdentifiers()).
     *
     * @return array  locale => label
     */
    public function localeOptions(): array
    {
        $options = [];
        $lang = $this->cms->lang;
        foreach (Cms::localeIdentifiers() as $locale) {
            $name = class_exists(\Locale::class) ? (string)\Locale::getDisplayName($locale, $lang) : $locale;
            $options[$locale] = ($name === '' || $name === $locale) ? $locale : "$name - $locale";
        }
        $collator = class_exists(\Collator::class) ? new \Collator($lang) : null;
        uasort($options, fn($a, $b) => $collator ? $collator->compare($a, $b) : strcmp($a, $b));
        return $options;
    }

    /**
     * Selection list of the time zones: PHP identifier => "identifier (UTC+02:00)".
     * The offset is the current one (summer/winter time) - for orientation only.
     *
     * @return array  timezone => label
     */
    public function timezoneOptions(): array
    {
        $options = [];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        foreach (\DateTimeZone::listIdentifiers() as $identifier) {
            $offset = (new \DateTimeZone($identifier))->getOffset($now);
            $sign = $offset < 0 ? '-' : '+';
            $offset = abs($offset);
            $label = sprintf('%s (UTC%s%02d:%02d)', $identifier, $sign, intdiv($offset, 3600), intdiv($offset % 3600, 60));
            $options[$identifier] = $label;
        }
        return $options;
    }

    /**
     * Set the cookie of the frontend editing (Cms::FED_COOKIE, see Cms::fedActive()).
     *
     *     0  delete cookie
     *     1  session cookie - until the browser is closed (at most 12 h);
     *        Start/logout.php deletes it on logout
     *     2  one year - survives logout and the end of the browser
     *
     * The value is signed (FrontendHandover::fedCookieValue()). Under the domain
     * of a website the preview sets it when the backend domain is fixed.
     *
     * Set from the Ajax response (AdminBackend::settings(), login): the
     * Set-Cookie header works there like with any other response of the same domain.
     *
     * @param int $mode  0 | 1 | 2
     */
    public function setFedCookie(int $mode): void
    {
        // signed value, see FrontendHandover
        $this->container->get(FrontendHandover::class)
            ->setFedCookie((int)$this->session->get('admin_id'), $mode);
    }

    /**
     * Is the logged-in admin a superuser (group 1)?
     *
     * From the loaded group rights (Access, Session::applyAccess()); if none are
     * loaded (CLI with Session::setCliAdmin()), from the group in the session details.
     */
    public function isSuperuser(): bool
    {
        $access = $this->container->get(Access::class);
        if ($access->isLoaded()) return $access->isSuperuser();
        $details = (array)($this->session->get('details') ?? []);
        return (int)($details['group_id'] ?? 0) === InstanceCache::SUPERUSER_GROUP_ID;
    }

    /**
     * Rights of the logged-in group - short form for the modules.
     */
    public function access(): Access
    {
        return $this->container->get(Access::class);
    }

    /**
     * SQL addition for area lists of the modules (selection lists, trees), which restricts the
     * areas to those of the user group: " AND `area_id` IN (...)",
     * empty for superusers. The ids come from the group file, not from input.
     *
     * @param bool   $writable  true: only areas with write permission (target areas
     *                          when moving/importing), otherwise read permission
     * @param string $column    column expression, e.g. "`a`.`area_id`"
     */
    public function areaAccessSql(bool $writable = false, string $column = '`area_id`'): string
    {
        $ids = $writable ? $this->access()->creatableAreaFilter() : $this->access()->areaFilter();
        if ($ids === null) return '';
        $list = implode(',', array_map('intval', $ids));
        return " AND {$column} IN (" . ($list === '' ? '0' : $list) . ")";
    }

    /**
     * Personal settings of the logged-in admin (`admin`.`settings`), as
     * Session::create() has taken them into the session details.
     *
     * @return array  backend_code, timezone, language_id, fed - missing keys = empty
     */
    public function adminSettings(): array
    {
        $details = (array)($this->session->get('details') ?? []);
        return (array)($details['settings'] ?? []);
    }

    // ====================
    // Backend page elements

    /**
     * Rename the breadcrumb navigation
     */
    public function setNaviLabel(string $label):void
    {
        echo '<span class="c9-navtext">' . $this->cms->textOrSnippet($label) . '</span>';
    }

    /**
     * Headline 1
     */
    public function headline(string $label): string
    {
        return $this->template('headline1', [
            '{text}' => $this->cms->textOrSnippet($label)
        ]);
    }

    /**
     * Empty the tab collection
     */
    public function tabBarReset(): void
    {
        $this->tabs = [];
    }

    /**
     * Add a tab to the collection
     * @param string $label     text or #snippet id
     * @param string $onclick   script for the onclick handler
     * @param bool   $active    true: tab is the active tab
     */
    public function tabAdd(string $label, string $onclick, bool $active = false): void
    {
        $tpl = $active ? 'tab_active' : 'tab';
        $this->tabs[] = $this->template($tpl, [
            '{label}' => $this->cms->textOrSnippet($label),
            '{onclick}' => $onclick
        ]);
    }

    // Tab "Changes" (audit log) of the info pages
    // ====================
    // Every info page of a dataset with an audit log (area, field, template, status,
    // instance, language, converter, task, component, group, admin) attaches it to
    // its tab bar: auditTabAdd() with its own address, auditTabActive()
    // decides whether auditHistory() appears instead of the content. Whoever sees the
    // info page may see it; IP addresses and "All in the audit log" only superusers
    // (like the tab of the records, RecordBackendInfo).

    /** Is the tab "Changes" selected? (request tab=audit) */
    public function auditTabActive(): bool
    {
        return (string)($_REQUEST['tab'] ?? '') === 'audit' && $this->auditLog->enabled();
    }

    /**
     * Attach the tab "Changes" to the collection - only when the audit log is switched on.
     *
     * @param string $url  info page without tab parameter, e.g. "Status/status_info.php?id=3"
     */
    public function auditTabAdd(string $url): void
    {
        if (!$this->auditLog->enabled()) return;
        $active = $this->auditTabActive();
        $this->tabAdd('#tab_audit', $active ? '' : "cApp.layers.replace('{$url}&tab=audit')", $active);
    }

    /**
     * Tab bar "Info | Changes" for info pages without their own tabs. If
     * "Changes" is selected, it outputs the history including the page end (container_footer)
     * and returns true - the caller is then done.
     *
     * @param string $url         info page without tab parameter
     * @param string $objectType  table of the dataset
     */
    public function infoAuditTabs(string $url, string $objectType, int $objectId): bool
    {
        if (!$this->auditLog->enabled()) return false;
        $active = $this->auditTabActive();
        $this->tabBarReset();
        $this->tabAdd('#button_info', $active ? "cApp.layers.replace('{$url}')" : '', !$active);
        $this->auditTabAdd($url);
        echo $this->tabBar();
        if (!$active) return false;
        echo $this->auditHistory($objectType, $objectId);
        echo $this->template('container_footer');
        return true;
    }

    /**
     * Content of the tab: the most recent entries of an object with before/after
     * (AuditDiff::entry()), newest first.
     *
     * @param string $objectType  Tabelle des Datasets (`audit_log`.`object_type`)
     */
    public function auditHistory(string $objectType, int $objectId, int $limit = 10): string
    {
        $rows = $this->auditLog->forObject($objectType, $objectId, $limit + 1);
        if (!$rows) {
            return $this->template('alert_success', ['{text}' => '{#note_audit_empty}']);
        }
        $superuser = $this->isSuperuser();
        $html = '';
        foreach (array_slice($rows, 0, $limit) as $row) {
            $html .= $this->auditDiff->entry($row, $superuser);
        }
        if (count($rows) > $limit) {
            $link = $superuser
                ? ' <a href="#"' . $this->on('js', "cApp.layers.add('Audit/index.php?object_type=" . rawurlencode($objectType) . "&object_id=" . $objectId . "');")
                    . '>' . htmlspecialchars($this->cms->snippet('link_audit_all')) . '</a>'
                : '';
            $html .= $this->template('alert_warning', ['{text}' => str_replace('{limit}', (string)$limit, $this->cms->snippet('note_audit_limit')) . $link]);
        }
        return $html;
    }

    /**
     * Render the tabs of the collection
     */
    public function tabBar(): string
    {
        $tabContainerParts = explode('{tabs}', $this->template('tab_container'));
        return $tabContainerParts[0] . implode ("\n", $this->tabs) . $tabContainerParts[1];
    }

    /**
     * Empty the button collection
     */
    public function buttonBarReset():void
    {
        $this->buttons = [];
    }

    /**
     * Add a button to the collection
     * @param string $tpl       template id
     * @param string $onclick   script for the onclick handler, replaces {onclick}
     * @param string $label     replaces {label}
     */
    public function buttonAdd(string $tpl, string $onclick, string $label = '', bool $alignRight = false):void
    {
        $replace = [
            '{onclick}' => $onclick,
            '{label}' => $label
        ];
        if ($alignRight) {
            // bootstrap only
            $replace['class="btn'] = 'class="ms-auto btn';
        }
        $this->buttons[] = $this->template($tpl, $replace);
    }

    /**
     * Replace placeholders in the buttons already collected - for templates with
     * their own placeholders (e.g. the menu of the record list with {area_id}).
     */
    public function buttonBarReplace(string $from, string $to): void
    {
        foreach ($this->buttons as &$button) {
            $button = str_replace($from, $to, $button);
        }
        unset($button);
    }

    /**
     * Preview button - one per language of the instance.
     *
     * The preview does not run via the frontend, but via
     * public/backend/preview.php (Page::resolvePreview()): only with admin session,
     * under the domain of the backend, and also for records that are not yet or
     * no longer published. The frontend path travels along as the parameter
     * `request`, the instance as `instance` - the frontend determines it from
     * the domain, the preview cannot do that.
     *
     * If the instance runs only one language, it is the ordinary button. With
     * several languages it becomes a drop-down list "Preview" with one entry
     * per language (parameter `lang`, see Page::resolveLanguage() - without a code
     * Accept-Language would decide).
     *
     * If the path differs per language (overview of an area: the translated
     * slug prefix, buttonAddAreaPreview()), it comes as a list language_id => path;
     * languages without an entry are then missing from the drop-down list.
     *
     * @param string|array $path  frontend path without leading slash, e.g. "42" or
     *                            "0,start" - or language_id => path
     * @param int    $instanceId  instance of the record resp. template
     */
    public function buttonAddPreview(string|array $path, int $instanceId): void
    {
        $base = $this->config->get('paths.backend') . '/preview.php?instance=' . $instanceId . '&request=';
        $languages = $this->container->get(InstanceCache::class)->languages($instanceId);
        if (is_array($path)) {
            $path = array_filter($path, fn($p) => (string)$p !== '');
            if (empty($path)) return;
        }
        if (count($languages) < 2) {
            $single = is_array($path) ? (string)reset($path) : $path;
            // (attribute value: the & between the parameters must be escaped)
            $this->buttonAdd('button_preview', htmlspecialchars($base . rawurlencode($single)));
            return;
        }
        $items = '';
        foreach ($languages as $languageId => $language) {
            $code = (string)($language['code'] ?? '');
            if ($code === '') continue;
            $languagePath = is_array($path) ? (string)($path[(int)($language['language_id'] ?? $languageId)] ?? '') : $path;
            if ($languagePath === '') continue;
            $label = $this->cms->textOrSnippet((string)($language['label'] ?? $code)) . ' (' . $code . ')';
            $items .= $this->template('button_preview_dropdown_item', [
                '{href}' => htmlspecialchars($base . rawurlencode($languagePath) . '&lang=' . rawurlencode($code)),
                '{label}' => htmlspecialchars($label),
            ]) . "\n";
        }
        if ($items === '') return;
        $this->buttons[] = $this->template('button_preview_dropdown', ['{items}' => $items]);
    }

    /**
     * Preview of the overview of an area (prefix address /{lang}/{prefix}, Page::
     * resolveRequest()) - only if the area has an overview template and a
     * slug prefix. The prefix is translated per language, so the drop-down list
     * therefore has its own address per language.
     */
    public function buttonAddAreaPreview(int $areaId): void
    {
        $instanceCache = $this->container->get(InstanceCache::class);
        $info = $instanceCache->areaInfo($areaId);
        if (empty($info['overview_template_id'])) return;
        $instanceId = (int)$info['instance_id'];
        $paths = [];
        foreach ($instanceCache->languages($instanceId) as $languageId => $language) {
            $languageId = (int)($language['language_id'] ?? $languageId);
            $paths[$languageId] = $instanceCache->slugPrefix($areaId, $languageId);
        }
        $this->buttonAddPreview($paths, $instanceId);
    }

    /**
     * Render the buttons of the collection
     * @param string $tpl     optional: template
     */
    public function buttonBar(string $tpl = ''): string
    {
        $tplButtonBar = explode('{buttons}', $this->template($tpl ?: 'button_bar'));
        return $tplButtonBar[0] . implode("\n", $this->buttons) . $tplButtonBar[1];
    }

    /**
     * Pagination top - short version (no page links)
     */

    public function paginationTopShort(string $snippetId = ''): string
    {
        if (empty($snippetId)) $snippetId = 'listed_records';
        return $this->template('result_bar_top', [
            '{left}' => '',
            '{center}' => $this->cms->snippet($snippetId, [
                'count' => $this->db->num_rows
            ]),
            '{right}' => ''
        ]);        
    }

    /**
     * Pagination top
     * @param string $snippetId  snippet id for the number of records, optional
     */
    public function paginationTop(string $snippetId = ''): string
    {
        if ($this->db->num_rows_unlimited <= $this->cms->list_rows) {
            return $this->paginationTopShort($snippetId);
        }
        else {
            // Display with skip buttons
            // Clean up skip: points to first entry of the page
            $skip = (int)(abs((int)$_REQUEST['skip']) / $this->cms->list_rows) * $this->cms->list_rows;

            $tplPaginationBack = $this->template('pagination_back');
            $tplPaginationBackInactive = $this->template('pagination_back_inactive');
            $tplPaginationForward = $this->template('pagination_forward');
            $tplPaginationForwardInactive = $this->template('pagination_forward_inactive');
            $tplPagination = $this->template('pagination');
            
            // CMS:JUMP_PREV
            $this->prevLink = $this->pagination->jumpPrev([
                'tpl_link' => $tplPaginationBack,
                'tpl_inactive' => $tplPaginationBackInactive,
                'replace' => "cApp.layers.replace('/backendModules?cApp.layers.replace('"
            ]);

            // CMS:JUMP_NEXT
            $this->nextLink = $this->pagination->jumpNext([
                'tpl_link' => $tplPaginationForward,
                'tpl_inactive' => $tplPaginationForwardInactive,
                'replace' => "cApp.layers.replace('/backendModules?cApp.layers.replace('"
            ]);

            // {from} - {to} of {total}
            $resultNumber = strtr($this->cms->snippet('number_of_records_total'), [
                '{from}' => (string) ($skip + 1),
                '{to}' => (string) ($skip + $this->cms->list_rows),
                '{total}' => '<strong>' . (string) ($this->db->num_rows_unlimited) . '</strong>'
            ]);

            // Pagination bar
            return strtr($tplPagination, [
                '{left}' => $this->prevLink,
                '{center}' => $this->template('pagination_center', ['{text}' => $resultNumber]),
                '{right}' => $this->nextLink
            ]);
        }
    }

    /**
     * Pagination bottom
     */
    public function paginationBottom(): string
    {
        if ($this->db->num_rows_unlimited <= $this->cms->list_rows) {
            return '';
        }

        $tplPaginationEllipsis = $this->template('pagination_ellipsis');
        $tplPaginationLink = $this->template('pagination_link');
        $tplPaginationCurrent = $this->template('pagination_current');
        $tplPagination = $this->template('pagination');

        // CMS:JUMP_LIST
        $pages = $this->pagination->jumpList([
            'tpl_link' => $tplPaginationLink,
            'tpl_active' => $tplPaginationCurrent,
            'prefix' => "",
            'suffix' => "",
            'ellipsis' => $tplPaginationEllipsis,
            'replace' => "cApp.layers.replace('/backendModules?cApp.layers.replace('"
        ]);

        // Pagination bar
        return strtr($tplPagination, [
            '{left}' => $this->prevLink,
            '{center}' => $pages,
            '{right}' => $this->nextLink
        ]);
    }

    /**
     * Render the column header, with a click handler for sorting by this column
     *
     * @param $colDef           column definition from the $schema
     * @param array $f          result array from $dataset->select()
     * @param string $colName   column name (input field name)
     */
    public function tableHeadColumn(array $colDef, array $f, string $colName): string
    {
        $colLabel = $this->cms->textOrSnippet($colDef['label'] ?? $colName);
        $type = $colDef['type'] ?? 'text';
        if ($type === 'relation' || $type == 'pointer' || $type === 'link') {
            // No sorting for n:m relations (and 1:n links)
            return $colLabel;
        }

        $sort = $f['sort'] ?? [];
        $order = $f['order'] ?? [];

        $sortName = $colName;
        if ($type === 'relation1' || $type == 'pointer1') {
            $sortName .= '._default';
        }
        elseif (str_starts_with($type, 'file')) {
            $sortName .= '.' . 'filename';
        }

        $sortIcon = '';
        $newOrder = 'ASC';
        if ($sort[0] == $sortName || $sort[1] == $sortName) {
            // sorting is by this column
            $sortIcon = 'sort_icon_' . strtolower($order[0]);
            if ($sort[0] == $sortName) {
                // even as the first
                $sortIcon .= '_primary';
            }

            // A new sort on clicking the column name is not a multi-column sort
            if ($order[0] == 'ASC') $newOrder = 'DESC';
        }
        else {
            // otherwise a neutral icon
            $sortIcon = 'sort_icon_select';
        }
        $tplSortIcon = $this->template($sortIcon);
        $linkWithoutSort = substr(
            $this->cms->sys_reduce_uri(['sort', 'order', 'cache', 'skip', 'limit']),
            strlen('/backend/Modules')
        );
        // The URL comes from the request - a ' in it (browsers do not encode it in the
        // query string) would otherwise end the JS string
        $script = 'cApp.layers.replace(' . $this->jsString("{$linkWithoutSort}sort={$sortName}&order={$newOrder}") . ')';
        return '<a' . $this->on('click', $script) . ">{$tplSortIcon}{$colLabel}</a>";
    }

    /**
     * Cell "Original filename" of the info page: the name, with write permission
     * ($fileRenameIds) with a pencil for editing - the extension stays fixed
     * (cApp.ui.fileRename(), RecordBackendInfo::fileRename()).
     *
     * @param array    $value           file metadata (filename, extension)
     * @param int      $id              record id
     * @param int      $fieldId         field_id of the file field
     * @param int|null $fileLanguageId  language of the file (null: single-language field)
     */
    public function fileNameCell(array $value, int $id, int $fieldId, ?int $fileLanguageId): string
    {
        $filename = (string)($value['filename'] ?? '');
        if (!isset($this->fileRenameIds[$id])) return htmlspecialchars($filename);

        $extension = (string)($value['extension'] ?? '');
        $baseName = $filename;
        if ($extension !== '' && str_ends_with(strtolower($filename), '.' . strtolower($extension))) {
            $baseName = substr($filename, 0, -strlen($extension) - 1);
        }
        return $this->template('record.file_filename_cell', [
            '{filename}'  => htmlspecialchars($filename),
            '{basename}'  => htmlspecialchars($baseName),
            '{extension}' => htmlspecialchars($extension),
            '{id}'        => (string)$id,
            '{field_id}'  => (string)$fieldId,
            '{lang}'      => $fileLanguageId === null ? '' : (string)$fileLanguageId
        ]);
    }

    /**
     * Initialize the info table
     */
    public function infoReset(): void
    {
        $this->infoBlocks = [];
    }

    /**
     * Set the collected info rows aside and continue with an empty collection -
     * for an info table within a row (the firmly linked contents of a 'link' field,
     * RecordBackendInfo::_infoLinkBlocks()).
     * infoPop() returns the rows collected since then as a table and restores the
     * outer collection. Counterpart of formPush()/formPop().
     */
    public function infoPush(): void
    {
        $this->infoStack[] = $this->infoBlocks;
        $this->infoBlocks = [];
    }

    /**
     * Counterpart to infoPush(): the inner rows as a table ($tpl as
     * infoTable()) and back to the outer collection.
     */
    public function infoPop(string $tpl = 'info_table_part'): string
    {
        $html = $this->infoTable($tpl);
        $this->infoBlocks = array_pop($this->infoStack) ?? [];
        return $html;
    }

    /**
     * Writing direction of a field value for the dir attribute (Cms::textDirection()).
     *
     * Texts (field types text, chars, encrypted as a single-line field or textarea, also
     * with editor extension) follow the language of their content: in a multilingual
     * field the language of the respective input - "ar_SA" right to left, "de_DE" left
     * to right, regardless of the language the backend runs in. Technical values (email,
     * phone, password, names a-z) are always left to right.
     *
     * @param string $lang  language of the content; '' = no specification
     * @return string       'ltr' | 'rtl' | '' (set nothing - the field inherits
     *                      the direction of the backend)
     */
    public function fieldDirection(array $colDef, string $lang = '', string $displayType = ''): string
    {
        if ($displayType === '') $displayType = (string)($colDef['display_type'] ?? 'text');
        if (in_array($displayType, ['email', 'tel', 'password'], true)
            || in_array('name', (array)($colDef['flags'] ?? []), true)) {
            return 'ltr';
        }
        if ($lang === '' || !in_array((string)($colDef['type'] ?? ''), ['text', 'chars', 'encrypted'], true)
            || !in_array($displayType, ['text', 'textarea'], true)) {
            return '';
        }
        return $this->cms->textDirection($lang);
    }

    /**
     * Create a block for the info table
     *
     * @param Dataset $dataset                   dataset subclass
     * @param string $name                       input field name
     * @param string|int|float|bool|null $value  field content
     * @param array $flags = []                  'ifnotempty', 'monospace', 'primary', 'html', 'expandable', ''fullsize'
     * @param ?array &$schemaVirtual             schema with virtual fields
     * @param int $id                            record id for determining relations
     * @param string $langSuffix                 language code after the label (multilingual field)
     */
    public function infoAdd(Dataset $dataset, string $name, string|int|float|bool|array|null $value, array $flags = [], ?array &$schemaVirtual = null, $id = null, string $langSuffix = ''):void
    {
        if (in_array('ifnotempty', $flags, true) && (string)$value === '') return;

        $schema = $schemaVirtual ?? $dataset->getSchema();
        $colDef = $schema[$name] ?? [];
        $label = $this->cms->textOrSnippet($colDef['label'] ?? $name);

        // Lock: the value is stored encrypted (as formField())
        if (($colDef['type'] ?? '') === 'encrypted') {
            $label .= $this->template('encrypted_field_indicator');
        }
        // Language code after the label of a multilingual field
        if ($langSuffix !== '') {
            $label .= $this->template('label_lang_suffix', ['{lang}' => htmlspecialchars($langSuffix)]);
        }
        $tpl = 'info_row';
        if (in_array('grey', $flags, true))$tpl .= '_grey';
        if (in_array('fullsize', $flags, true)) $tpl = 'info_row_fullsize';

        $expanded = $_GET['expanded'] ?? '';

        if (!in_array('html', $flags, true)) {
            
            $format = 'medium';
            if (in_array('expandable', $flags, true) && $name == $expanded) {
                $format = 'long';
            }
            $value = $this->backendValue($value, $colDef, $format, $id);
            if (in_array('primary', $flags, true)) $value = '<strong>' . $value . '</strong>';
            // Text of a multilingual field in the direction of its language
            $dir = $langSuffix !== '' ? $this->fieldDirection($colDef, $langSuffix) : '';
            if ($dir !== '' && $dir !== $this->cms->textDirection()) $value = '<div dir="' . $dir . '">' . $value . '</div>';

            if (in_array('expandable', $flags, true) && $name != $expanded
                && str_ends_with($value, $this->cms->snippet('ellipsis'))
            ) {
                $href = str_replace('/backendModules/Record/', '/Record/',
                    $this->cms->sys_reduce_uri(['expanded'],$_SERVER['REQUEST_URI']) . 'expanded=' . $name
                );
                $value .= '&nbsp;' . $this->template('link_replace', [
                    '{href}' => $href,
                    '{text}' => '{#more}'
                ]);
            }
        }
        elseif ((string)$value === '') {
            // Finished HTML without content (e.g. 'link' field without linked content):
            // show the placeholder as backendValue() does, instead of an empty block
            $value = $this->emptyPlaceholder;
        }
        $classes = [];
        if (in_array('name', $flags, true) || in_array('monospace', $flags, true)) {
            $classes[] = 'c9-monospace';
        }
        $class = $classes ? ' ' . implode(' ', $classes) : '';
        $this->infoBlocks[] = $this->template($tpl, [
            '{col1}' => $label,
            '{col2}' => $value,
            '{class}' => $class
        ]);
    }

    /**
     * Info row with static content (independent of a virtual field)
     */
    public function infoStatic(string $col1, string $col2, array $flags = []): void
    {
        $class = [];
        if (in_array('monospace', $flags, true)) $class[] = 'c9-monospace';
        if (in_array('fullsize', $flags, true)) $tpl = 'info_row_colspan2';
        else $tpl = 'info_row';
        $this->infoBlocks[] = $this->template('info_row', [
            '{col1}' => $this->cms->textOrSnippet($col1),
            '{col2}' => $col2,
            // leading space: {class} is appended directly to "pb-1" in the template
            '{class}' => $class ? ' ' . implode(' ', $class) : ''
        ]);
    }

    /**
     * Render the info table
     */
    public function infoTable($tpl = 'info_table'): string
    {
        return $this->template($tpl,[
            '{table_rows}' => implode('', $this->infoBlocks)
        ]);
    }

    /**
     * Prepare a new form
     *
     * @param string $tpl  : template for the form with placeholders, e.g. {data_...}
     */
    public function formInit(string $tpl, $replace = []): void
    {
        $this->form = $this->template($tpl, $replace);
    }

    /**
     * Add a hidden field to the form
     *
     * @param string $name   field name
     * @param string|int $value  field value - is HTML-encoded
     */
    public function formHidden(string $name, string|int $value): void
    {
        $this->form .= '<input type="hidden" name="' . $name . '" value="' . htmlspecialchars((string)$value) . '">' . "\n";
    }

    /**
     * Show static content instead of a form field
     *
     * @param Dataset $dataset  dataset subclass
     * @param string $name      field name; sub-fields such as 'settings.widgets' become -
     *                          as in formField() - the placeholder
     *                          {data_settings_widgets}
     * @param string $html      HTML
     */
    public function formStatic(Dataset $dataset, string $name, string $html, $append = false): void
    {
        $schema = $dataset->getSchema();
        $label = $schema[$name]['label'] ?? $name;
        $placeholder = '{data_' . str_replace('.', '_', $name) . '}';
        $append = (bool)($param['append'] ?? false);
        if ($append && strpos($this->form, $placeholder) === false) {
            $this->form .= "\n" . $placeholder;
        }
        $formBlock = $this->template('edit_form_static', [
            '{label}' => $this->cms->textOrSnippet($label),
            '{html}' => $html
        ]);
        $this->form = str_replace($placeholder, $formBlock, $this->form);
    }

    /**
     * Return the form
     *
     * @param string $tpl               template for the submit buttons
     * @param array $replace            replacements in the template
     * @param bool $removePlaceholders  remove all remaining placeholders
     */
    public function form(string $tpl = '', array $replace = [], bool $removePlaceholders = false): string
    {
        if (!empty($tpl)) {
            $this->form .= $this->template($tpl, $replace);
        }
        if ($removePlaceholders) {
            $this->form = preg_replace('/\\{[a-z0-9_]+\\}/', '', $this->form);
        }
        return $this->form;
    }


    // ====================
    // 

    /**
     * All user groups as group_id => label in the backend language: the
     * superuser group first, the others sorted by label (selection lists in the
     * admin form, in the search, tab "Access").
     */
    public function getGroups(): array
    {
        $groups = [];
        $this->db->pushResult();
        $this->db->query("SELECT `group_id`, `label` FROM `group` WHERE `group_id` > ?", 'i', [InstanceCache::SUPERUSER_GROUP_ID]);
        foreach ($this->db->fetchPairs() as $groupId => $label) {
            $groups[(int)$groupId] = $this->cms->textOrSnippet((string)$label);
        }
        $this->db->popResult();
        asort($groups);
        return $groups + [InstanceCache::SUPERUSER_GROUP_ID => $this->cms->snippet('group_label_superuser')];
    }

    /** Backend::attributePlaceholders(): template => placeholders in attribute values */
    private array $attributePlaceholderCache = [];

    /** Backend::markHandlers(): template => HTML with marked event handlers */
    private array $markedTemplateCache = [];

    /**
     * Loads an HTML template and replaces placeholders in the format {#label} with language entries.
     * Simple placeholders in the format {label} are reserved for text replacements (see parameter replace)
     *
     * @param string $tpl       template name in the format module-name.tpl-name without .html extension
     * @param array $replace    text replacements $key => $val
     * @return string|array
     */
    public function template(string $tpl, array $replace = []): string|array
    {
        $tpl = strtolower($tpl);
        // Error message for .html suffix
        if (str_ends_with($tpl, '.html')) {
            return "Error template() call with .html suffix.";
        }

        // Get template filename
        $lastDotPosition = strrpos($tpl, '.');
        if ($lastDotPosition === false) {
            $prefix = '';
            $suffix = $tpl;
            $fileName = 'cache.php';
        }
        else {
            $prefix = substr($tpl, 0, $lastDotPosition);
            $suffix = substr($tpl, $lastDotPosition + 1);
            $fileName = 'cache.' . $prefix . '.php';
        }

        // Cache-File geladen?
        if (!isset($this->htmlTemplates[$prefix])) {
            $varDir = $this->config->get('paths.var_dir');
            $cacheFile = $varDir . "/cache/html/$fileName";
            if (is_file($cacheFile)) {
                include_once $cacheFile;
            }
        }
        
        $html = $this->htmlTemplates[$prefix][$suffix] ?? "Error: HTML template $tpl not found.";
        // Mark the event handlers of the template for the script policy (developer docs, chapter 14 (Security: web))
        $html = $this->markedTemplateCache[$tpl] ??= $this->markHandlers($html);

        // Ersetzungen
        if (count($replace) > 0) {
            // Placeholder in an attribute value ("…{x}…"): a " in the inserted value
            // ended the attribute ('" onmouseover="…' - attribute injection without "<",
            // which no HTML filter sees). In an attribute a raw " is always
            // wrong, &quot; always right - and an already escaped value contains
            // no " any more, so it is not escaped twice (developer docs, chapter 14 (Security: web)).
            foreach ($this->attributePlaceholders($tpl, $html) as $placeholder) {
                if (isset($replace[$placeholder]) && is_string($replace[$placeholder])
                    && str_contains($replace[$placeholder], '"')) {
                    $replace[$placeholder] = str_replace('"', '&quot;', $replace[$placeholder]);
                }
            }
            $html = strtr($html, $replace);
        }

        // Replace text passages marked with {#label} by the language entry
        $html = preg_replace_callback('/\{#([a-z][a-z0-9_.]+)\}/', function($matches) {
            return $this->cms->snippet($matches[1]);
        }, $html);

        return $html;
    }

    /**
     * Mark the event handlers of a template (developer docs, chapter 14 (Security: web)): from
     * onclick="…" becomes data-c9h-{nonce}-click="…", from href="javascript:…"
     * href="#" data-c9h-{nonce}-js="…", a <script> gets the nonce. The code stays
     * unchanged in the attribute, also with its placeholders - template() or the
     * caller replaces those as before.
     *
     * The script policy of the backend forbids handler attributes. backend-handlers.js
     * turns the marked attributes into listeners, via a script with the nonce of the
     * page. The marker is the nonce in the attribute name: it only appears in the code
     * (templates, Backend::on()), never in content from the database - an onclick
     * injected there stays what it is, and the browser does not run it.
     */
    private function markHandlers(string $html): string
    {
        if (!str_contains($html, 'on') && stripos($html, 'javascript:') === false && !str_contains($html, '<script')) return $html;
        $mark = 'data-c9h-' . $this->securityHeaders()->nonce() . '-';
        // <script> of the template itself: nonce of the page
        $html = preg_replace('~<script(?=[\s>])(?![^>]*\snonce=)~i', '<script' . $this->nonceAttr(), $html);
        $html = preg_replace('~(?<=\s)href="javascript:~i', 'href="#" ' . $mark . 'js="', $html);
        return preg_replace_callback('~(?<=\s)on([a-z]+)(?==")~i',
            fn($m) => $mark . strtolower($m[1]), $html);
    }

    /**
     * Event handler for HTML that does not come from a template (developer docs, chapter 14 (Security: web)) - instead of
     * onclick="…" in the PHP code:
     *
     *     '<a href="#"' . $backend->on('click', "cApp.layers.add('X/y.php')") . '>'
     *
     * @param string $event  click, change, keydown … or 'js' (like href="javascript:…":
     *                       click, default action always prevented)
     * @param string $code   JavaScript as in an onclick attribute (this, event,
     *                       return false); escaped here for the attribute
     * @return string        ' data-c9h-{nonce}-{event}="…"'
     */
    public function on(string $event, string $code): string
    {
        return ' data-c9h-' . $this->securityHeaders()->nonce() . '-' . preg_replace('/[^a-z]/', '', strtolower($event))
            . '="' . htmlspecialchars($code, ENT_QUOTES) . '"';
    }

    /**
     * Text for a placeholder of template(): HTML-escaped and '{' as an entity.
     * After insertion template() still replaces {#label} markers - also in the
     * inserted values. A value with "{#…}" (manual, snippet text, user input)
     * would otherwise be replaced by a snippet.
     */
    public function escape(string $text): string
    {
        return $this->protect(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE));
    }

    /**
     * <option> list from the template option.
     *
     * @param array $options              value => label (text, is escaped)
     * @param string|int|null $selected   selected value (compared as string), null = none
     */
    public function options(array $options, string|int|null $selected = null): string
    {
        $html = '';
        foreach ($options as $value => $label) {
            $html .= $this->template('option', [
                '{value}' => $this->escape((string)$value),
                '{selected}' => $selected !== null && (string)$selected === (string)$value ? ' selected' : '',
                '{label}' => $this->escape((string)$label),
            ]);
        }
        return $html;
    }

    /** Finished HTML for a placeholder of template() - '{' as an entity, see escape() */
    public function protect(string $html): string
    {
        return str_replace('{', '&#123;', $html);
    }

    /** ' nonce="…"' for a <script> tag (script policy, developer docs, chapter 14 (Security: web)) */
    public function nonceAttr(): string
    {
        return $this->securityHeaders()->nonceAttr();
    }

    private function securityHeaders(): SecurityHeaders
    {
        return $this->container->get(SecurityHeaders::class);
    }

    /**
     * Placeholders of a template that stand in an attribute value with double
     * quotes - determined once per template.
     *
     * @return string[]  e.g. ['{value}', '{title}']
     */
    private function attributePlaceholders(string $tpl, string $html): array
    {
        if (isset($this->attributePlaceholderCache[$tpl])) return $this->attributePlaceholderCache[$tpl];
        $found = [];
        if (str_contains($html, '{')) {
            // Tags quote-aware, with the attribute values in "…"
            preg_match_all('~<[a-z][a-z0-9-]*(?:[^>"\']|"[^"]*"|\'[^\']*\')*>~i', $html, $tags);
            foreach ($tags[0] as $tag) {
                preg_match_all('~=\s*"([^"]*)"~', $tag, $values);
                foreach ($values[1] as $value) {
                    preg_match_all('~\{[a-z0-9_]+\}~', $value, $ph);
                    foreach ($ph[0] as $p) $found[$p] = true;
                }
            }
        }
        return $this->attributePlaceholderCache[$tpl] = array_keys($found);
    }

    /**
     * Sanitizes a user-defined path
     *
     * @param string $arg     the path to sanitize
     * @param array  $options Possible keys: 'allowAbsolute' (bool)
     * @return string
     */
    public function sanitizePath(string $arg, array $options = []): string
    {
        // Extract default values
        $allowAbsolute = $options['allowAbsolute'] ?? false;

        if ($arg !== '') {
            // Normalize the slashes
            $arg = str_replace('\\', '/', $arg);
            
            // Remove forbidden characters
            $arg = preg_replace('/[\x00<>:"\'|?*%\\\\]/u', '', $arg);
            
            // Clean up double slashes and attempts to climb (..)
            $arg = preg_replace('/\/+/u', '/', $arg);
            $arg = preg_replace('/^\.+\//u', '', $arg);
            $arg = rtrim($arg, '/');
            $arg = preg_replace('/\.+/u', '.', $arg);
            $arg = preg_replace('/(\/\.)+\//u', '/', $arg);
            
            // Handling of the leading slash
            if (!$allowAbsolute) {
                $arg = ltrim($arg, '/');
            }
        }
        return $arg;
    }

    /**
     * Create a balloon message
     * @string $type    Bootstrap alert class 'success', 'danger', ...
     * @string $msg     message text
     */
    public function balloon(string $type, string $msg): void
    {
        echo '<script' . $this->nonceAttr() . '>cApp.system.balloon(' . $this->jsString($type) . ',' . $this->jsString($msg) . ");</script>\n";
    }

    /**
     * Value as a JavaScript string literal in single quotes: ' " < > &
     * as \u00.. - thus safe in <script> AND in an attribute (onclick="…"),
     * without further escaping. For every value that does not come from the code itself
     * (request, database, translations with apostrophe).
     */
    public function jsString(string $value): string
    {
        $json = json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
            | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return "'" . substr((string)$json, 1, -1) . "'";
    }

    /**
     * Wrapper to write JavaScript, e.g. in the Ajax load
     * @string $code    JS code
     */
    public function script(string $code): void
    {
        echo '<script' . $this->nonceAttr() . '>requestAnimationFrame(() => {setTimeout(function(){' . $code . "}, 50);});</script>\n";
    }

    /**
     * Return an object in readable format
     * Objects:
     *   status, admin, group
     *
     * @param string $type  object type (table name)
     * @param int $id       primary key
     * @param bool $linked  true (default): linked to the info page of the object
     * @param bool $icon    true (default): with icon
     * @return string       label or ''
     */
    public function teaser(string $type, int $id, bool $linked = true, bool $icon = true, $label = true): string {
        // Link only if the group may open the module of the info page - otherwise
        // only the text (developer docs, chapter 11 (Permissions))
        if ($linked) {
            $script = match ($type) {
                'area'   => 'Area/area_info.php',
                'record' => 'Record/record_info.php',
                default  => $this->teaserDefaults[$type]['script'] ?? (ucfirst($type) . "/{$type}_info.php"),
            };
            $linked = $this->access()->module(explode('/', $script)[0]);
        }
        switch($type) {
            case 'status':
                // SPECIAL CASE: status === 0 is defined
                // $linked is not computed
                if ($id > 1) {
                    $this->db->pushResult();
                    $sql = "SELECT `label`,`icon`, `color_class` FROM `status` WHERE `status_id` = ?";
                    $this->db->query($sql, 'i', [$id]);
                    $status = $this->db->fetchNum();
                    $this->db->popResult();
                    if ($status) {
                        [$keySnippet, $keyIcon, $keyClass] = $status;
                    }
                    else {
                        $keySnippet = '#status.status_disabled';
                        $keyIcon    = 'block';
                        $keyClass   = 'text-danger';
                    }
                }
                else {
                    [$keySnippet, $keyIcon, $keyClass] = match (true) {
                        $id === 0 => ['#status.status_enabled',    'check'           , 'text-success'],
                        $id === 1 => ['#status.status_disabled',   'block'           , 'text-danger'],
                        $id < 0   => ['#status.status_deleted',    'delete_forever'  , 'text-danger'],
                        default   => ['', ''],
                    };
                }
                $labelText = '';
                if (empty($iconOnly)) {
                    $labelText = $this->cms->textOrSnippet($keySnippet);
                }
                if (!empty($icon)) {
                    $iconHtml = $this->template('status.icon_status', [
                        '{color_class}' => $keyClass,
                        '{icon}' => $keyIcon
                    ]);
                    return $label ? $iconHtml . ' ' . $labelText : $iconHtml;
                }
                // else
                return $labelText;

            case 'area':
                if ($id === 0) return '';
                $this->db->pushResult();
                $sql = "SELECT `label`,`icon` FROM `area` WHERE `area_id` =? AND `deleted`=0";
                $this->db->query($sql, 'i', [$id]);
                $areaData = $this->db->fetch();
                $this->db->popResult();
                if ($areaData === null) return '';

                $labelText = $this->cms->textOrSnippet($areaData['label']);
                $iconHtml = $this->template('icon', [
                    '{name}' => $areaData['icon']
                ]);
                $labelHtml = $iconHtml . ' ' . $labelText;
                if ($linked) {
                    $href = "Area/area_info.php?id={$id}&back=true";
                    return $this->template('link_replace', [
                        '{href}' => $href,
                        '{text}' => $labelHtml
                    ]);
                }
                // else
                return $labelHtml;

            case 'record':
                if ($id === 0) return '';
                $this->db->pushResult();
                $sql = "SELECT * FROM `record` WHERE `record_id` = ? AND `tmp` = 0 AND `deleted` = 0";
                $this->db->query($sql, 'i', [$id]);
                $data = $this->cms->fetch();
                $this->db->popResult();
                if ($data === null) return '';

                // Determine the default field, sorting and icon of the area
                $areaId = (int)$data['area_id'];
                $areaData = $this->areaData($areaId);
                $defaultFieldName = (string)$areaData['default_field_name'];
                $defaultFieldType = (string)$areaData['default_field_type'];
                $colDef = ['type' => $defaultFieldType];
                $labelHtml = $this->backendValue($data[$defaultFieldName] ?? '', $colDef);
                
                if ($icon) {
                    // Icon + label
                    $iconHtml = $this->template('icon', [
                        '{name}' => $areaData['icon']]
                    );
                    $labelHtml = $iconHtml . ' ' . $labelHtml; 
                }

                if ($linked) {
                    $href = 'Record/record_info.php?id=' . $id . '&back=true';
                    return $this->template('link_replace', [
                        '{href}' => $href,
                        '{text}' => $labelHtml
                    ]);
                }
                // else
                return $labelHtml;

            default:
                if ($id === 0) return '';
                if (isset($this->teaserDefaults[$type])) {
                    // individual structure
                    $t = $this->teaserDefaults[$type];
                }
                else {
                    // standard structure
                    $t = [
                        'label' => 'label',
                        'icon' => "{$type}.icon_{$type}",
                        'script' => ucfirst($type) . "/{$type}_info.php"
                    ];
                }   
                $labelField = $t['label'];
                $this->db->pushResult();
                $sql = "SELECT `{$labelField}` FROM `{$type}` WHERE `{$type}_id` =?";
                $this->db->query($sql, 'i', [$id]);
                $label = ($this->db->fetchNum() ?? [])[0] ?? null;
                $this->db->popResult();
                if ($label === null) return '';

                // Label
                $labelHtml = $this->cms->textOrSnippet($label);
                if ($icon && !empty($t['icon'])) {
                    // Icon + label
                    $labelHtml = $this->template($t['icon']) . ' ' . $labelHtml; 
                }
                // (There used to be a second popResult() here - called from a pushResult()
                //  context, it would have taken the result of the CALLER off the stack
                //  and closed it; the query above is already finished with its own
                //  push/pop pair.)
                if ($linked) {
                    $href = $t['script'] . '?id=' . $id . '&back=true';
                    return $this->template('link_replace', [
                        '{href}' => $href,
                        '{text}' => $labelHtml
                    ]);
                }
                return $labelHtml;
        }
    }

    /**
     * Insert a search field into the form, in place of the placeholder {filter_...}
     *
     * @param Dataset $dataset
     * @param array   $param [
     *      string $name       field name
     *      array  $colDef     schema of the field
     * ]
     */
    public function searchField(Dataset $dataset, array $param): void {
        $param['varName'] = 'filter';
        $this->formField($dataset, $param);
    }

    /**
     * Replace placeholders in the form, workaround for add-on components
     * var string $from
     * var string $to
     */
    public function formReplace(string $from,string  $to) {
        $this->form = str_replace($from, $to, $this->form);
    }

    /**
     * Place finished HTML into the form at the position of a placeholder - if the
     * placeholder is missing, append it at the end (as formField() with 'append').
     *
     * @param string $placeholder  z. B. '{data_feld}'
     * @param string $html
     */
    public function formAppend(string $placeholder, string $html): void
    {
        if ($placeholder !== '' && strpos($this->form, $placeholder) !== false) {
            $this->form = str_replace($placeholder, $html, $this->form);
        }
        else {
            $this->form .= "\n" . $html;
        }
    }

    /**
     * Set the form under construction aside and continue with an empty
     * buffer - for partial forms that are inserted into another form
     * (the embedded records of a 'link' field, RecordBackendEdit::_embedCell()):
     * formField() etc. then work on the empty buffer, formPop() returns its content and
     * restores the form. Nesting is allowed.
     */
    public function formPush(): void
    {
        $this->formStack[] = $this->form;
        $this->form = '';
    }

    /**
     * Counterpart to formPush(): return the buffer built since formPush()
     * and restore the form that was set aside.
     */
    public function formPop(): string
    {
        $html = $this->form;
        $this->form = array_pop($this->formStack) ?? '';
        return $html;
    }

    /**
     * Insert a sort field into the form, in place of the placeholder {sort_order}
     *
     * @param array $sortOptions        field names => field label
     * @param string|null $sortValue    value from $_REQUEST['sort']
     * @param string|null $orderValue   value from $_REQUEST['order']
     * @param bool $append              if true: the field is appended to the form if the placeholder {data_fieldname} is missing (default is false)
     */
    public function searchFieldSortOrder(array $sortOptions, ?string $sortValue = null, ?string $orderValue = null, $append = false): void
    {
        if ($sortValue == null) $sortValue = $_REQUEST['sort'] ?? '';
        if ($orderValue == null) $orderValue = $_REQUEST['order'] ?? '';
        $sortOptionsHtml = '';
        $sortOptions = ['' => $this->emptyPlaceholder] + $sortOptions;
        foreach ($sortOptions as $key => $val) {
            $selectedAttr = (string)$sortValue === (string)$key ? ' selected' : '';
            $sortOptionsHtml .= "<option value=\"{$key}\"" . $selectedAttr . ">" . $this->cms->_strip_tags($val) . "</option>\n";
        }
        $oderOptions = [
            '' => $this->emptyPlaceholder,
            'asc' => $this->cms->snippet('label_sort_asc'),
            'desc' => $this->cms->snippet('label_sort_desc')
        ];
        $orderOptionsHtml = '';
        foreach ($oderOptions as $key => $val) {
            $selectedAttr = (string)$orderValue === (string)$key ? ' selected' : '';
            $orderOptionsHtml .= "<option value=\"{$key}\"" . $selectedAttr . ">" . $this->cms->_strip_tags($val) . "</option>\n";
        }
        $html = $this->template('search_form_sort_order', [
            '{sort_options}' => $sortOptionsHtml,
            '{order_options}' => $orderOptionsHtml,
            // links <label for> with the <select> - without the replacement the
            // placeholder would remain as an id in the markup
            '{uid_sort}' => $this->crypt->randomKey(16)
        ]);

        if ($append) {
            $this->form .= $html;
        }
        else {
            $this->form = str_replace('{sort_order}', $html, $this->form);
        }
    }

    /**
     * Insert an input field into the form, in place of the placeholder {data_...}
     *
     * @param Dataset $dataset
     * @param array $param          array with the following values
     *      bool   $append          if true: the field is appended to the form if the placeholder {data_fieldname} is missing (default is false)
     *      array  $colDef          definition (of the field only!) - see Dataset::hookDefinition()
     *      string $name            field name
     *      int    $id              > 0 : id of the record, < 1 : record is created anew
     *      mixed  $value           value from $data
     *      string $varName         default: 'data', changed to 'filter' by searchField()
     *      string $note            note under the label (text or #snippet),
     *                              also applies to search fields
     *      string $placeholder     placeholder in the form that is replaced, default: data_{$name}
     *      string $display_type    replace the display type from the colDef
     *      array  $options         for type 'select', selection options key => value
     *                              keys starting with 'disabled' are not selectable
     *      bool   $selectPlease    true: "-- please select --" option at the start (different defaults for data and filter)
     *      string $template        overrides the template chosen by display_type
     *      array  $lookupOptions   options for lookup field [
     *                                (string) $source_table
     *                                (string) $destination_table
     *                                (string) $relation_table
     *                                (bool) $button_new
     *                              ]
     *      string $contextMenu     only display_type 'lookup': template of the context menu;
     *                              '' suppresses it (default: {module}.lookup_context_menu)
     *      array $attr             additional attributes such as 'onchange' => "..."
     *                              (also 'class' and 'style' are allowed and
     *                              are added to existing ones, but this contradicts the MVC logic via backend templates)
     *      bool $fullWidth         takes the full width
     *      int  $langId            multilingual field: language of this input field.
     *                              The field name becomes data[name][{langId}], the
     *                              label receives $langSuffix, errors are searched under
     *                              "name.{langId}" (Dataset::validateFieldValue())
     *      string $langSuffix      addition to the label, e.g. "de" - appears as
     *                              "| de" in muted colour (only with $langId)
     *      string $textLang        language of the content (code or locale, e.g. "ar_SA"):
     *                              determines the writing direction (fieldDirection()).
     *                              Default with $langId: $langSuffix; without specification
     *                              the field inherits the direction of the backend
     *      string $dir             'ltr' | 'rtl' | 'auto' - force the writing direction
     *      string $labelPrefix     HTML that is placed BEFORE the label (e.g. the
     *                              selection checkbox of batch processing)
     *      bool $primary           only <input type="text">: bold (for the main field)
     *      bool   $flatName        name with a dot not as a sub-array (data[a][b]),
     *                              but literally (data[a.b])
     *      string $blockClass      additional CSS class(es) of the field block (column with
     *                              the input field), e.g. 'warning'
     *      string $errorPrefix     prefix under which field errors are stored in Cms::errors -
     *                              e.g. "embed.{record_id}." for the embedded
     *                              records of a 'link' field (RecordBackendEdit)
     */
    public function formField(
            Dataset $dataset,
            array $param,
        ): void
    {
        // Parameters
        $name = (string)($param['name'] ?? '');
        $paramName = $name;
        $value = $param['value'] ?? null; // mixed
        $id = (int)$param['id'];
        $langId = isset($param['langId']) ? (int)$param['langId'] : null;
        $langSuffix = (string)($param['langSuffix'] ?? '');

        $colDef = $param['colDef'] ?? [];
        if (empty($colDef)) {
            // If the field schema is not given explicitly, take it from the schema of the dataset subclass
            $colDef = $dataset->getSchema()[$paramName] ?? [];
        }

        $varName = $param['varName'] ?? 'data'; // 'data' or 'filter'
        $type = (string)($colDef['type'] ?? '');
        $displayType = $param['display_type'] ?? $colDef['display_type'] ?? 'text';
        // passed options take precedence over the schema; labels as #snippet
        $options = array_map(fn($label) => is_string($label) ? $this->cms->textOrSnippet($label) : $label,
            (array)($param['options'] ?? $colDef['options'] ?? []));
        $flags = (array)($colDef['flags'] ?? []);

        // No input field for full text (computed from other text fields)
        if ($type == 'fulltext' && $varName == 'data') return;
        
        $placeholder = $param['placeholder'] ?? '{' . $varName . '_' .str_replace('.', '_', $name) . '}';
        $append = (bool)($param['append'] ?? false);
        if ($append && strpos($this->form, $placeholder) === false) {
            $this->form .= "\n" . $placeholder;
        }

        // Check parameters and schema
        if (empty($colDef)) {
            $html = 'Error: schema for field "' . htmlspecialchars($name) . '" is not defined.<br>';
            $this->form = str_replace($placeholder, $html, $this->form);
            return;
        }
        if (empty($name)) {
            $html = 'Error: param[name] not defined.<br>';
            $this->form = str_replace($placeholder, $html, $this->form);
            return;
        }
        if ($type == '') {
            $html = 'Error: type of field "' . htmlspecialchars($name) . '" is empty.<br>';
            $this->form = str_replace($placeholder, $html, $this->form);
            return;
        }
        if (!in_array($type, $this->cms->types, true)) {
            $html = 'Error: Unknown type "' . htmlspecialchars($type) . '".<br>';
            $this->form = str_replace($placeholder, $html, $this->form);
            return;
        }
        if (!in_array($displayType, $this->displayTypes[$type], true)) {
            $html = 'Error: display_type "' . htmlspecialchars($displayType)
                . '" not allowed for field "' . htmlspecialchars($name) . " (type=$type)<br>";
            $this->form = str_replace($placeholder, $html, $this->form);
            return;
        }

        // name format name(.subname) ? - flatName: the dot stays in the field name
        // (filter[bild.width] for the sub-fields of a file, Cms::buildFileExists())
        if (strpos($name, '.') === false || !empty($param['flatName'])) {
            $inputName = $name;
        }
        else {
            [$name, $subname] = explode('.', $name);
            $inputName = $name . '][' . $subname; // -> data[name][subname]
        }
        // multilingual field: one array level per language -> data[name][{langId}]
        if ($langId !== null) {
            $inputName .= '][' . $langId;
        }

        // textarea rows="..."
        $height = (int)$colDef['height'] ?? 0;

        // Collect additional classes and attributes for the input field

        $class = [];
        $style = [];
        $attr = [];

        foreach ((array)($param['attr'] ?? []) as $key => $val) {
                if ($key === 'style') $style[] = "$val";
                elseif ($key === 'class') $class[] = "$val";
                // Event handler ('onchange' => '…', raw code): marked (developer docs, chapter 14 (Security: web))
                elseif (preg_match('/^on([a-z]+)$/i', (string)$key, $m)) $attr[] = ltrim($this->on($m[1], (string)$val));
                else $attr[] = "$key=\"$val\"";
        }
        
        if (!empty($param['primary'])) $class[] = 'c9-input-primary';

        // Writing direction: forced ('dir') or according to the language of the content - an
        // Arabic text is also right-to-left in the German backend
        // (fieldDirection()). Only in the input mask; search fields inherit.
        $dir = (string)($param['dir'] ?? '');
        if (!in_array($dir, ['ltr', 'rtl', 'auto'], true)) {
            $textLang = (string)($param['textLang'] ?? ($langId !== null ? $langSuffix : ''));
            $dir = $varName === 'data' ? $this->fieldDirection($colDef, $textLang, $displayType) : '';
        }
        if ($dir !== '') $attr[] = 'dir="' . $dir . '"';

        // unique ID for=..., aria-labeled-by=...
        $uid = $this->crypt->randomKey(16);

        // Prefix, Suffix
        $inputGroup = '{group_content}';
        $idPrefix = '';
        $idSuffix = '';
        $prefix = (string) ($colDef['prefix'] ?? '');
        if ($name === 'instance_id') {
            $prefix = $this->template('instance.icon_instance');
        }
        if ($type == 'timestampl') {
            // Time zone
            $suffix = $this->cms->userTZ;
        }
        else {
            $suffix = (string)($colDef['suffix'] ?? '');
        }
        if ($prefix !== '' || $suffix !== '') {
            if ($prefix !== '' && $suffix !== '') {
                $templateId = 'input_group_prefix_suffix';
                $idPrefix = $this->crypt->randomKey(16);
                $idSuffix = $this->crypt->randomKey(16);
                $attr[] = "aria-describedby=\"$idPrefix $idSuffix\"";
                
            }
            elseif ($prefix !== '') {
                // Create the id first, then insert it: written as a placeholder
                // it would never arrive. {attr} is set further below via strtr() into the
                // field template - strtr replaces in ONE pass and does not look at
                // the freshly inserted text again, so a placeholder inside it would
                // remain as aria-describedby="{id_prefix}".
                $templateId = 'input_group_prefix';
                $idPrefix = $this->crypt->randomKey(16);
                $attr[] = "aria-describedby=\"$idPrefix\"";
            }
            else {
                $templateId = 'input_group_suffix';
                $idSuffix = $this->crypt->randomKey(16);
                $attr[] = "aria-describedby=\"$idSuffix\"";
            }
            $inputGroup = $this->template($templateId, [
                '{prefix}' => $this->cms->textOrSnippet($prefix),
                '{suffix}' => $this->cms->textOrSnippet($suffix)
            ]);
        }    

        // label, note and tooltip
        // --------------------
        $label = $this->cms->textOrSnippet($colDef['label'] ?? $name);

        $note = '';
        $tooltip = '';
        $pattern = '';        
        
        if ($varName == 'filter') {
            if (in_array($type, $this->cms->dateTypes, true)) {
                $note = date('Y') . '-12-31 23:59';
            }
            elseif ($type == 'time') {
                $note = '23:59';
            }
        }
        else {
            if ($displayType == 'text') {
                if (in_array($type, ['float','decimal','currency'], true)) {
                    $note = $this->cms->_formatFloat(1000.00, 2);
                }
                elseif ($type === 'integer') {
                    $note = $this->cms->_formatFloat(1000, 0);
                }
                elseif (in_array('name', $flags, true)) {
                    // Note under the label
                    $note = 'a..z 0..9 _';
                    // Tooltip on hover over the label
                    $tooltip = $this->cms->snippet('tooltip_name_format');
                    $pattern = '[a-z0-9_]+';
                    $class[] = 'c9-monospace';
                }
            }

            if ($displayType == 'email') {
                $pattern = '[^@]+@[^@]+\\.[^@]+';
            }

            // Passed values override automatically determined values
            if ((string)($colDef['note'] ?? '') != '') $note = $this->cms->textOrSnippet($colDef['note']);
            if ((string)($colDef['tooltip'] ?? '') != '') $tooltip = $this->cms->textOrSnippet($colDef['tooltip']);
            if ((string)($colDef['pattern'] ?? '') != '') $pattern = (string) $colDef['pattern'];
            
            $tooltip = $this->_escapeParam($tooltip);

            if (!empty($pattern)) {
                $attr[] = 'pattern="' . $pattern . '"';
            }

            if (in_array('not_empty', $flags, true)) {
                $label .= $this->template('mandatory_field_indicator');
            }
            // Lock: the value is stored encrypted
            if ($type === 'encrypted') {
                $label .= $this->template('encrypted_field_indicator');
            }
        }
        // Language code after the label of a multilingual field
        if ($langId !== null && $langSuffix !== '') {
            $label .= $this->template('label_lang_suffix', [
                '{lang}' => htmlspecialchars($langSuffix)
            ]);
        }

        // Note from the caller - also in search masks, where the one from the field definition
        // (colDef['note']) does not apply
        if ((string)($param['note'] ?? '') !== '') $note = $this->cms->textOrSnippet((string)$param['note']);

        if (in_array($displayType, ['checkbox', 'radio', 'sortable'], true)) {
            // Label in the left column gets no for=""
            $templateId = match (true) {
                $note != '' && $tooltip != '' => 'group_label_with_note_tt',
                $tooltip != ''                => 'group_label_tt',
                $note != ''                   => 'group_label_with_note',
                default                       => 'group_label'
            };
        }
        else {
            $templateId = match (true) {
                $note != '' && $tooltip != '' => 'input_label_with_note_tt',
                $tooltip != ''                => 'input_label_tt',
                $note != ''                   => 'input_label_with_note',
                default                       => 'input_label'
            };
        }
        if (!empty($param['primary'])) $labelClass = ' c9-label-primary';
        else $labelClass = '';
        $labelHtml = $this->template($templateId, [
            "{id}" => $uid,
            '{label}' => $label,
            '{note}' => $note,
            '{tooltip}' => $tooltip, // HTML-Formatierung erlaubt
            '{class}' => $labelClass
        ]);
        if ((string)($param['labelPrefix'] ?? '') !== '') {
            $labelHtml = $param['labelPrefix'] . $labelHtml;
        }
        
        // Template(s) laden
        // --------------------
        switch ($displayType) {
            case 'email':
                $tplInput = $this->template('edit_form_input_email');
                break;
            case 'tel':
                $tplInput = $this->template('edit_form_input_tel');
                break;
            case 'password':
                $tplInput = $this->template('edit_form_input_password');
                break;
            case 'select':
                $tplInput = $this->template('edit_form_select');
                break;
            case 'multiselect':
                $tplInput = $this->template('edit_form_multiselect');
                break;
            case 'radio':
                $tplRadioWithLabel = $this->template('edit_form_radio_with_label');
                $tplInput = $this->template('group_container');
                break;
            case 'switch':
                $tplCheckbox = $this->template('edit_form_switch');
                break;
            case 'checkbox':
                $tplCheckboxWithLabel = $this->template('edit_form_checkbox_with_label');
                $tplInput = $this->template('group_container');
                break;
            case 'sortable':
                $tplSortableWithLabel = $this->template('edit_form_sortable_with_label');
                $tplInput = $this->template('edit_form_sortable_container');
                break;
            case 'textarea':
                if ($varName == 'filter') {
                    $tplInput = $this->template('edit_form_input_text');
                }
                else {
                    if ($height < 1) $tplInput = $this->template('edit_form_textarea_growing');
                    else $tplInput = $this->template('edit_form_textarea');
                }
                break;
            case 'lookup':
                $tplInput = ($varName == 'filter') ?
                    $this->template('search_form_lookup') : $this->template('edit_form_lookup');
                break;
            case 'upload':
                $tplInput = ($varName == 'filter') ?
                    $tplInput = $this->template('edit_form_input_text') : $tplInput = $this->template('edit_form_upload');
                break;
            case 'multiupload':
                $tplInput = $this->template('edit_form_multiupload');
                break;
            default:
                // 'text'
                $tplInput = $this->template('edit_form_input_text');
        }

        // Spezielles Template angebenen
        $paramTemplate = (string) ($param['template'] ?? '');
        if ($paramTemplate !== '') {
            $tplInput = $this->template($paramTemplate);
        }

        $max = 0;
        if ($varName != 'filter') {
            // only in the input mask, not in the search mask...

            // min
            // --------------------
            $min = (string)($colDef['min'] ?? '');
            if ($min !== '') {
                if (in_array($type, ['chars','text'], true)) {
                    if ($displayType == 'password' && $id > 0) {
                        // Exception: password may stay empty when a record is changed
                    }
                    else {
                        // Character count
                        $min = (int)$min;
                        $attr[] = 'minlength="' . (string)$min . '"';
                    }
                }
            }

            // max
            // --------------------
            $max = (string)($colDef['max'] ?? '');
            if ($max !== '') {
                if (in_array($type, ['chars','text'], true)) {
                    // Character count
                    $max = (int)$max;
                    $attr[] = 'maxlength="' . (string)$max . '"';
                }
            }

            // flags
            // --------------------
            // not_empty
            if (in_array('not_empty', $flags, true)) {
                if ($displayType == 'password' && $id > 0) {
                    // Exception: password may stay empty when a record is changed
                }
                elseif ($displayType === 'upload') {
                    // Upload field: required only as long as no file is present - otherwise
                    // the browser would demand a new file on every save.
                    // $value is the file entry (metadata) of the displayed language;
                    // the actual check is done by VirtualFieldsTrait::validateFileRequired()
                    $file = is_array($value) ? $value : [];
                    if (empty($file['size']) || !empty($file['deleted'])) {
                        // $attr[] = 'required';
                        // Disabled because the real file selection and the selection via
                        // the server-file button currently cannot be combined;
                        // input checking happens via PHP anyway
                    }
                }
                else {
                    $attr[] = 'required';
                }
            }
        }

        // display_type 'select'
        // --------------------
        if ($displayType == 'select') {
            $optionsHTML = '';

            // "Please select" option at the start
            if ($varName == 'filter') {
                // for a filter field the default is: true
                $selectPlease = (bool)($param['selectPlease'] ?? true);
                $selectMessage = $this->emptyPlaceholder;
            }
            else {
                // otherwise the default is: false
                $selectPlease = (bool)($param['selectPlease'] ?? false);
                $selectMessage = $this->cms->snippet('please_select');
            }
            // For a search field: always show
            if ($selectPlease) {
                $optionsHTML .= '<option value="">'. $selectMessage . "</option>\n";
            }

            // Options; a group as label => [value => label] (<optgroup>)
            $optionHtml = function (string|int $val, string $label) use ($value): string {
                $optAttr = '';
                if (str_starts_with((string)$val, 'disabled')) {
                    $optAttr = ' disabled';
                    $val = '';
                }
                elseif ((string)$val == (string)$value) {
                    $optAttr = ' selected';
                }
                return '<option value="' . htmlspecialchars((string)$val) . "\"{$optAttr}>" . $this->cms->_strip_tags($label) . "</option>\n";
            };
            foreach ($options as $val => $label) {
                if (is_array($label)) {
                    $group = '';
                    foreach ($label as $groupVal => $groupLabel) $group .= $optionHtml($groupVal, (string)$groupLabel);
                    $optionsHTML .= $this->template('optgroup', ['{label}' => $this->escape((string)$val), '{options}' => $group]);
                    continue;
                }
                $optionsHTML .= $optionHtml($val, (string)$label);
            }
            $tplInput = strtr($tplInput, [
                '{options}' => $optionsHTML
            ]);
        }


        // display_type 'radio'
        // --------------------
        elseif ($displayType === 'radio') {
            if (empty($options)) {
                $options = [
                    0 => $this->cms->snippet('no'),
                    1 => $this->cms->snippet('yes')
                ];
            }
            $optionsHTML = '';
            foreach ($options as $val => $label) {
                if ((string)$val === (string)$value) $checked = ' checked';
                else $checked = '';
                $optionsHTML .= strtr($tplRadioWithLabel, [
                    '{id}' => $this->crypt->randomKey(16),
                    '{name}' => $varName . '[' . $inputName . ']',
                    '{checked}' => $checked,
                    '{value}' => htmlspecialchars($val),
                    '{label}' => $label
                ]);
            }
            $tplInput = strtr($tplInput, [
                '{content}' => $optionsHTML
            ]);
        }

        // display_type 'switch'
        // --------------------
        elseif ($displayType === 'switch') {
            // No strict type : allow field type to be integer
            if ($value == 1) $checked = ' checked';
            else $checked = '';
            $tplInput = strtr($tplCheckbox, [
                '{name}' => $varName . '[' . $inputName . ']',
                '{checked}' => $checked,
                '{value}' => 1,
            ]);
        }

        // display_type 'checkbox'
        // --------------------
        // always creates an array. An option with the label ['group' => HTML] is not a
        // selection but a subheading (key arbitrary, unique).
        elseif ($displayType === 'checkbox') {
            if (empty($options)) {
                $options =  ['1' => $this->cms->snippet('yes')];
            }
            $optionsHTML = '';
            foreach ($options as $val => $label) {
                if (is_array($label)) {
                    $optionsHTML .= $this->template('edit_form_checkbox_group', ['{label}' => (string)($label['group'] ?? '')]);
                    continue;
                }
                // No strict type : allow field type to be integer
                if (in_array((string)$val, (array) $value, false)) $checked = ' checked';
                else $checked = '';
                $optionsHTML .= strtr($tplCheckboxWithLabel, [
                    '{id}' => $this->crypt->randomKey(16),
                    '{name}' => $varName . '[' . $inputName . '][]',
                    '{checked}' => $checked,
                    '{value}' => htmlspecialchars($val),
                    '{label}' => $label
                ]);
                // type bit: allows only 1 option
                if ($type == 'bit') break;
            }
            $tplInput = strtr($tplInput, [
                '{content}' => $optionsHTML
            ]);
        }

        // display_type 'sortable'
        // --------------------
        // always creates an array
        //
        // Like 'checkbox', but every option stands in its own .c9-smartrows-row
        // and can be moved with the arrow buttons (Core/public_master/backend/
        // assets/js/backend-smartrows.js). Unlike the domain rows there is
        // NO adding and removing: the selection is fixed, only its
        // ORDER is free.
        //
        // A form submits only the checked checkboxes, in the order in which their
        // rows stand in the document. That is exactly the stored sort order here. To make
        // it visible, the selected options stand at the top - in the order of $value -,
        // the others below in the order of $options.
        elseif ($displayType === 'sortable') {
            // Selected options in the order of the stored value
            $selected = [];
            foreach ((array)$value as $val) {
                $val = (string)$val;
                // Skip unknown values (option no longer exists)
                if (!array_key_exists($val, $options)) continue;
                $selected[$val] = $options[$val];
            }
            // Rest in the order of the options
            $rows = [];
            foreach ($selected as $val => $label) {
                $rows[] = [$val, $label, ' checked'];
            }
            foreach ($options as $val => $label) {
                if (array_key_exists((string)$val, $selected)) continue;
                $rows[] = [$val, $label, ''];
            }

            $optionsHTML = '';
            foreach ($rows as [$val, $label, $checked]) {
                $optionsHTML .= strtr($tplSortableWithLabel, [
                    '{id}' => $this->crypt->randomKey(16),
                    '{name}' => $varName . '[' . $inputName . '][]',
                    '{checked}' => $checked,
                    '{value}' => htmlspecialchars((string)$val),
                    '{label}' => $this->cms->_strip_tags($label)
                ]);
            }
            $tplInput = strtr($tplInput, [
                '{content}' => $optionsHTML
            ]);
        }

        // display_type 'multiselect'
        // --------------------
        // erzeugt immer Array
        elseif ($displayType == 'multiselect') {
            $optionsHTML = '';
            foreach ($options as $val => $label) {
                // as with 'select': keys with prefix 'disabled' are not selectable
                if (str_starts_with((string)$val, 'disabled')) {
                    $selected = ' disabled';
                    $val = '';
                }
                elseif (in_array((string)$val, (array)$value, false)) $selected = ' selected';
                else $selected = '';
                $valEscaped = htmlspecialchars($val);
                $labelEscaped = $this->cms->_strip_tags($label);
                $optionsHTML .= "<option value=\"$valEscaped\"{$selected}>" . $labelEscaped . "</option>\n";
            };

            // height == auto? -> max 12
            $count = count($options);
            if ($height < 1) {
                $height = max($count, 2);
                $height = min($height, 12);
            }

            $tplInput = strtr($tplInput, [
                '{options}' => $optionsHTML,
                '{name}' => $varName . '[' . $inputName . '][]'
            ]);
        }

        // display_type 'text', 'textarea', 'password', 'email', 'tel'
        // --------------------
        // no special adjustments

        if ($varName != 'filter') {
                
            // styles
            // --------------------
            // Do not make the input field unnecessarily wide when the character length is limited
            if ($displayType == 'text') {
                if (in_array($type, $this->cms->textTypes, true)) {
                    if ($max > 0 && $max < 64) {
                        $maxRem = max(ceil((0.6 * $max + 2) / 10) * 10, 10);
                        $style[] = "max-width:{$maxRem}rem";
                    }
                }
                elseif (in_array($type, $this->cms->numericTypes, true)) {
                        $style[] = "max-width:10rem";
                }
            }
            elseif ($name == 'status_id') {
                $style[] = "max-width:15rem";
            }
            // style in attr integrieren
            if (!empty($style)) {
                $attr[] = 'style="' . implode(';', $style) . '"';
            }
        }

        // Extension (developer docs, chapter 08 (Components and extensions)): only in the input mask, only if it is active -
        // one class and two data attributes, cApp.extensions does the rest
        if ($varName === 'data') {
            $ext = $this->fieldExtension($colDef);
            if ($ext !== null) {
                $class[] = 'c9-ext';
                $attr[] = 'data-c9-ext="' . htmlspecialchars($ext['name']) . '"';
                $attr[] = 'data-c9-ext-type="' . htmlspecialchars($type) . '"';
                $attr[] = "data-c9-ext-options='" . htmlspecialchars(json_encode($ext['options'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES) . "'";
            }
        }

        // value
        if (is_array($value)) $valueHtml = implode("\n", $value);
        else $valueHtml = (string)$value;
        // textarea: a leading line break is ignored according to the HTML specification,
        // so output \n first, so that user input with a leading break stays possible
        if ($displayType == 'textarea') $valueHtml = "\n" . $valueHtml;
        
        // Replacements - all types
        // --------------------
        $replacements = [
            '{name}' => $varName . '[' . $inputName . ']',
            '{id}' => $uid,
            '{class}' => !empty($class) ? ' ' . implode(' ', $class) : '',
            '{attr}' => !empty($attr) ? ' ' . implode(' ', $attr) : '',
            '{height}' => $height,
            '{value}' => htmlspecialchars($valueHtml),
        ];
        // Lookup field replacements are performed afterwards in the backend script
        // {src_id}
        // {src_field}
        // {search_url}
        // ...
        $fieldHtml = strtr($tplInput, $replacements);

        $fullWidth = (bool)($param['fullWidth'] ?? false);

        // Input group
        // --------------------
        $groupHtml = strtr($inputGroup, [
            '{group_content}' => $fieldHtml,
            '{id_prefix}' => $idPrefix,
            '{id_suffix}' => $idSuffix,
        ]);

        // Lookup field: append the context menu
        // A lookup field that writes no links (e.g. the selection of the
        // parent record) has nothing to offer for the context menu - the caller can
        // suppress or replace it with contextMenu = ''.
        if ($displayType == 'lookup') {
            $moduleName = $dataset->getModuleName();
            $ctxTemplate = (string)($param['contextMenu'] ?? $moduleName . '.lookup_context_menu');
            if ($ctxTemplate !== '') {
                $groupHtml .= $this->template($ctxTemplate, [
                    '{src_field}' => (int)$colDef['field_id'],
                    '{src_id}' => (int)$id,
                    '{id}' => $uid, // data-target=preview-{id}
                ]);
            }
        }

        // c9-col1 + 2
        // --------------------
        // Error message (from class Dataset)
        // For multilingual fields Dataset::validateFieldValue() registers the
        // error under "name.{langId}" - only the input field of this language is
        // highlighted
        $errorFields = $this->cms->getErrorFields();
        $errorKey = ($langId !== null) ? $paramName . '.' . $langId : $paramName;
        $errorKey = (string)($param['errorPrefix'] ?? '') . $errorKey;
        if (in_array($errorKey, $errorFields, true)) {
            $groupClass = ' c9-invalid';
            $errorMsg = $this->template('form_error', [
                '{text}' => $this->cms->errors[$errorKey]['text'] ?? ''
            ]);
        }
        else {
            $groupClass = '';
            $errorMsg = '';
        }
        
        // additional class of the field block (e.g. 'warning' for a temporarily
        // added search field, RecordBackendSearch)
        $blockClass = preg_replace('/[^a-z0-9_ -]/i', '', (string)($param['blockClass'] ?? ''));
        if ($blockClass !== '') $groupClass .= ' ' . trim($blockClass);

        if (!$fullWidth) $tplEditBlock = 'edit_form_block';
        else $tplEditBlock = 'edit_form_block_full_width';
        $html = $this->template($tplEditBlock, [
            '{label}' => $labelHtml,
            '{field}' => $groupHtml . $errorMsg,
            '{class}' => $groupClass,
        ]);

        // Replace the placeholder
        $this->form = str_replace($placeholder, $html, $this->form);
    }

    /**
     * Flags by field type
     * @param string $type  field type
     * @return array        possible flags
     */
    public function processingFlags(string $type): array {
        $flags = [
            'chars'     => ['trim', 'strip_tags'],
            'text'      => ['trim', 'strip_tags'],
            'encrypted' => ['trim', 'strip_tags'],
        ];
        return $flags[$type] ?? [];
    }

    /**
     * Prepare the results of Dataset::select
     * Including close button
     *
     * @param array $f  return values of Dataset::select
     */
    public function filterText(array $f): string
    {
        $filterText = (string) $f['filter_text'] ?? '';
        $defaultSort = (string) $f['default_sort'] ?? '';
        $usedSort = (string) $f['used_sort'] ?? '';
        $sortingChanged = ($defaultSort != $usedSort);

        $textParts = [];
        if (!empty($filterText) || $sortingChanged) {
            if (!empty($filterText)) {
                $prefixSearchFor = $this->template('prefix_search_for');
                $textParts[] = $prefixSearchFor . ' ' . $filterText;
            }
            if ($sortingChanged) {
                $sortText = (string) $f['sort_text'] ?? '';
                $prefixSortBy = $this->template('prefix_sort_by');
                $textParts[] = $prefixSortBy . ' ' . $sortText;
            }
        }
        if (!empty($textParts)) {
            return $this->template('search_where_clause', [
                    '{text}' => implode('<br>', $textParts)
            ]);
        }
        else return '';
    }

    /**
     * Prepares content from a Dataset::fetch() row for display in the backend
     *
     * @param array|string|bool|int|null &$value  content
     * @param array &$colDef                      associated column definition from the schema, 'type' required<
     * @param string $format                      'short' (default), 'medium', 'long'
     * @param string $id                          record id, for determining relations
     * @return string
     */
    public function backendValue(array|string|bool|int|null $value, array $colDef, $format = 'short', $id = null): string {
        $type = $colDef['type'] ?? 'text';
        $displayType = $colDef['display_type'] ?? 'text';
        $name = $colDef['name'] ?? '';
        // Not every branch sets $text (e.g. an empty json field) - at the end
        // the placeholder for "no content" is then used.
        $text = '';
        if ($type === 'currency') {
            $text = $this->cms->_formatFloat((float)($value ?? 0) / 100, 2);
        }
        elseif (in_array($type, ['float','decimal'])) {
            $text = $this->cms->_formatFloat((float)($value ?? 0));
        }
        elseif ($type == 'timestampl') {
            $text = $this->cms->_formatDate((string)$value, true);
        }
        elseif (in_array($type, $this->cms->dateTypes, true)) {
            $text = $this->cms->_formatDate((string)$value, false);
        }
        elseif ($type == 'time') {
            $text = $this->cms->_formatTime((string)$value);
        }
        elseif ($type == 'json' || $type == 'reltable') {
            // json / reltable (same display - list of entries)
            // --------------------
            if (!empty($value)) {
                // Truncate long lists: in the record list after 5 entries,
                // on the info page after 10. The ellipsis is appended ONCE,
                // then the loop ends - anything further would be behind the three dots anyway.
                $limit = match ($format) {
                    'short'  => 5,
                    'medium' => 10,
                    default  => 0, // 'long': everything
                };
                $items = [];
                foreach ((array)$value as $item) {
                    if ($limit > 0 && count($items) >= $limit) {
                        $items[] = $this->cms->snippet('ellipsis');
                        break;
                    }
                    $items[] = $this->cms->_strip_tags((string)$item);
                }
                $text = $this->template('ul', [
                    '{items}' => '<li>' . implode("</li>\n<li>", $items) . '</li>'
                ]);
            }
        }
        elseif (in_array($type, $this->cms->relationTypes, true)) {
            // relation, pointer
            // --------------------
            if (!is_int($id)) return '(error)';
            if (empty($name)) return '(error)';
            if ($datasets = $this->relatedDatasets($id, $colDef, $format)) {
                $text = $this->template('ul', [
                    '{items}' => '<li>' . implode("</li>\n<li>", $datasets) . '</li>'
                ]);
                unset($dataset);
            }
        }
        elseif ($type === 'file') {
            // upload, thumbnail
            // --------------------
            if (!empty($value['size'])) {
                // preview link
                // Never directly into the media directory: the backend fetches every file via
                // backend/file.php (RecordBackendServerFile::filePreview()). Only that
                // script knows the storage {instance_id}/{field_id}, checks the
                // login and decides whether it may redirect to the file or
                // reads it itself (domain lock of the instance, user_auth of the field).
                // For multilingual file fields the file of the language as third
                // path part. `version` as cache buster: a new upload gets
                // a new address.
                $mediaPath = $this->config->get('paths.media');
                $fieldId = (int)$colDef['field_id'];
                $extension = $value['extension'] ?? '';
                $fileLanguageId = $this->cms->fileLanguageId((array)$value);
                $filePath = $this->config->get('paths.backend') . "/file.php?file={$fieldId}/{$id}"
                    . ($fileLanguageId === null ? '' : '/' . $fileLanguageId)
                    . '&v=' . (int)($value['version'] ?? 0);
                // file attributes
                $width = (int)($value['width'] ?? 0);
                $height = (int)($value['height'] ?? 0);
                $value['dimensions'] = null;
                if ($width > 0 && $height > 0) {
                    $value['dimensions'] = $width . $this->cms->snippet('dimension_x') . $height;
                }
                $value['size'] = $this->system->convertSize((int)$value['size']);
                if ($format === 'short') {
                    // File field in list display
                    // --------------------
                    $mimeType = $value['mime_type'] ?? '';
                    if ($displayType === 'thumbnail') {
                        // Thumbnail-Feld
                        if (in_array($mimeType, $this->webImageFormats, true)) {
                            // Web-capable (and safe) preview possible -> img tag
                            $text = $this->template('img_thumbnail', [
                                '{src}' => $filePath,
                                '{width}' => (string)$width,
                                '{height}' => (string)$height,
                            ]);
                        }
                        else {
                            $icon = $this->defaultImg($mimeType, $extension);
                            $placeholderUrl = $mediaPath . $icon;
                            // No preview possible -> icon
                            $text = $this->template('record.file_placeholder', [
                                '{url}' => $placeholderUrl,
                                '{extension}' => $extension
                            ]);
                        }
                    }
                    else {
                        // Upload field
                        $shorts = [
                            $value['mime_type'] ?? 'unknown_type',
                            $value['size']
                        ];
                        if (!empty($value['dimensions'])) $shorts[] = $value['dimensions'];
                        $text = implode(', ', $shorts);
                    }
                }
    
                else {
                    // File field on the info page
                    // --------------------
                    $text = $this->template('a_blank', [
                        '{url}' => $filePath,
                        '{text}' => $filePath
                    ]);
                    // first row
                    $tableRow = $this->template('record.file_info_table_row', [
                        '{label}' => $this->cms->snippet('record.file_path'),
                        '{text}' => $text
                    ]);
                    $tableRows = [$tableRow];
                    // next rows
                    $subFields = $this->cms->fileSortCols;
                    $subFields[] = 'dimensions';
                    foreach ($subFields as $subFieldName) {
                        if ($subFieldName === 'width' || $subFieldName === 'height') continue;
                        // File name: with write permission also empty, so that it can be entered
                        if (!empty($value[$subFieldName])
                            || ($subFieldName === 'filename' && isset($this->fileRenameIds[(int)$id]))
                        ) {
                            $text = htmlspecialchars((string)$value[$subFieldName]);
                            if ($subFieldName === 'filename') {
                                $text = $this->fileNameCell($value, (int)$id, $fieldId, $fileLanguageId);
                            }
                            $tableRows[] = $this->template('record.file_info_table_row', [
                                '{label}' => $this->cms->snippet('record.file_' . $subFieldName),
                                '{text}' => $text
                            ]);
                        }
                    }
                    $fileData = (array)(json_decode($value['data'] ?? '{}', true) ?? []);
                    foreach ($fileData as $key => $val) {
                        if (in_array($key, $subFields)) continue;

                        if ($format === 'medium') {
                            $text = $this->cms->_nl2br($this->cms->_strlimit(htmlspecialchars((string)($val ?? '')), '400s'));
                        }
                        else {
                            $text = $this->cms->_nl2br(htmlspecialchars((string)($val ?? '')));
                        }
                        $tableRows[] = $this->template('record.file_info_table_row', [
                            '{label}' => $this->cms->snippet('record.file_' . $key),
                            '{text}' => $text
                        ]);
                    }
                    $text = $this->template('record.file_info_table', [
                        '{table_rows}' => implode("\n", $tableRows)
                    ]);
                }
            }
            elseif (!empty($value['token'])) {
                // File is currently being created by a background process
                // Create a container with metadata that is updated when the background process finishes
                if ($format === 'short') {
                    $url = $this->config->get('paths.backend') . "/file.php?file={$colDef['field_id']}/{$id}";
                }
                else {
                    $url = "Record/ajax_field_details.php?id={$id}&name={$name}";
                }
                $text = $this->template('bgproc_media_container', [
                    '{token}' => $value['token'],
                    '{url}' => $url
                ]);
            }
        }
        else {
            // text
            // --------------------
            if ($format === 'short') {
                $text = $this->cms->_strlimit($this->cms->_strip_tags((string)($value ?? '')), '60s');
            } elseif ($format === 'medium') {
                $text = $this->cms->_nl2br($this->cms->_strlimit(htmlspecialchars((string)($value ?? '')), '400s'));
            }
            else {
                // Full text (info page after "more"): fields with editor extension
                // as HTML - Markdown via Camindo\Core\Markdown (HTML in the text
                // masked), HTML of the extension HTMLVisual through the HtmlSanitizer
                $text = match ($this->editorExtension($colDef)) {
                    'markdownvisual' => $this->template('rich_text', ['{html}' => (new Markdown())->toHtml((string)($value ?? ''))]),
                    'htmlvisual'     => $this->template('rich_text', ['{html}' => $this->cms->_sanitize_html((string)($value ?? ''))]),
                    default          => $this->cms->_nl2br(htmlspecialchars((string)($value ?? ''))),
                };
            }
        }
        if ($text == '') return $this->emptyPlaceholder;
        return $text;
    }

    /**
     * Editor extension of a field (details.extension) in lowercase, '' = none.
     * Meta fields from the PHP schema carry it directly as 'extension'.
     */
    public function editorExtension(array $colDef): string
    {
        $name = (string)($colDef['extension'] ?? '');
        if ($name === '' && isset($colDef['details'])) {
            $details = is_array($colDef['details']) ? $colDef['details'] : (json_decode((string)$colDef['details'], true) ?: []);
            $name = (string)($details['extension'] ?? '');
        }
        return strtolower($name);
    }

    /**
     * Query the records stored in a link field (Cms::select()
     * with src_id + relation, $limit + 1 rows - the one extra shows that
     * there are more). Sorting: manual order of the field, otherwise for one
     * target area its default sorting, for several by change date descending.
     * The result then stands in Database (fetch()).
     *
     * @param int  $skip   offset (paging, relatedPage())
     * @param bool $count  count the total (Database::$num_rows_unlimited)
     * @return bool  false: not a link field resp. no target area (no query)
     */
    private function relatedSelect(int $id, array $colDef, int $limit, int $skip = 0, bool $count = false): bool
    {
        $type = (string)($colDef['type'] ?? '');
        $name = (string)($colDef['name'] ?? '');
        $fieldId = (int)($colDef['field_id'] ?? 0);
        if ($name === '' || !in_array($type, $this->cms->relationTypes, true)) return false;

        // area_id
        // --------------------
        if (str_starts_with($type, 'relation')) {
            // relation
            $areaIds = [$colDef['rel_area_id'] ?? 0];
        }
        else {
            // pointer, pointer1
            $this->db->pushResult();
            $this->db->query('SELECT `area_id` FROM `rel_field_area` WHERE `field_id`=?', 'i', [$fieldId]);
            $areaIds = $this->db->fetchColumn();
            $this->db->popResult();
        }
        $areaIds = $this->system->sanitizeIds((array)$areaIds, true);
        if (empty($areaIds)) return false;

        // Sorting
        // --------------------
        if ((int)($colDef['custom_order'] ?? 0) > 0) {
            // Manual sorting
            $sort = '_custom';
            $order = 'ASC';
        }
        elseif (count($areaIds) === 1) {
            // One area -> its default sorting
            $areaData = $this->areaData((int)$areaIds[0]) ?: [];
            $sort = (string)($areaData['sort_field_name'] ?? '') ?: 'changed_at';
            $order = (string)($areaData['sort_order'] ?? '') ?: 'DESC';
        }
        else {
            // Several areas without manual sorting -> change date descending
            $sort = 'changed_at';
            $order = 'DESC';
        }

        $this->cms->select([
            'src_id' => $id,
            'relation' => $name,
            'skip' => $skip,
            'limit' => $limit + 1,
            'sort' => $sort,
            'order' => $order,
            'count' => $count ? 'true' : 'false',
        ]);
        return true;
    }

    /**
     * One page of the records stored in a link field (order
     * as relatedDatasets()) including total count - for paging (API, RecordApi::fieldValue()).
     *
     * @return array{0:int[],1:int}  [record_ids dieser Seite, Gesamtzahl]
     */
    public function relatedPage(int $id, array $colDef, int $skip, int $limit): array
    {
        $this->db->pushResult();
        $ids = [];
        $total = 0;
        if ($this->relatedSelect($id, $colDef, $limit, $skip, true)) {
            $total = (int)$this->db->num_rows_unlimited;
            while ($row = $this->db->fetch()) {
                if (count($ids) >= $limit) break;
                $ids[] = (int)$row['record_id'];
            }
        }
        $this->db->popResult();
        return [$ids, $total];
    }

    /**
     * Ids of the records stored in a link field, at most $limit
     * (order as relatedDatasets()).
     *
     * @return array{0:int[],1:bool}  [record_ids, es gibt mehr]
     */
    public function relatedIds(int $id, array $colDef, int $limit = 20): array
    {
        $this->db->pushResult();
        $ids = [];
        $more = false;
        if ($this->relatedSelect($id, $colDef, $limit)) {
            while ($row = $this->db->fetch()) {
                if (count($ids) >= $limit) {
                    $more = true;
                    break;
                }
                $ids[] = (int)$row['record_id'];
            }
        }
        $this->db->popResult();
        return [$ids, $more];
    }

    /**
     * Determine the records stored in field $name and build an array from them
     * [record_id => default field]
     * If the relation field supports manual sorting, then
     *     sorted by manual order
     * Otherwise
     *     - for max 1 area by its default sorting
     *     - for several areas by change date descending
     * Helper function for Backend::backendValue(), but also RecordBackendEdit (lookup preview area)
     *
     * @param int $id         record id
     * @param array $colDef   column definition of the relation field
     * @param string $format  'short' => 10 records (default),
     *                        'long' => admin.list_max_datasets from config
     * @return array          records as a flat array
     */
    public function relatedDatasets(int $id, array $colDef, $format = 'short'): array
    {
        if (!in_array($colDef['type'] ?? '', $this->cms->relationTypes, true)) {
            return [];
        }

        // maximum number of records displayed
        // --------------------
        if ($format === 'short') $limit = 10;
        else $limit = (int)($this->config->get('admin.list_max_datasets') ?? 200);

        // Collect the records (result stays for the loop below)
        // --------------------
        $this->db->pushResult();
        if (!$this->relatedSelect($id, $colDef, $limit)) {
            $this->db->popResult();
            return [];
        }

        // Build the array
        // --------------------
        $displayNumber = min($this->db->num_rows, $limit);
        $isCutted = ($this->db->num_rows > $displayNumber);
        $datasets = [];
        for ($i = 0; $i < $displayNumber; $i++) {
            $data = $this->cms->fetch();
            $areaId = (int)$data['area_id'];
            $areaData = $this->areaData($areaId);
            $defaultFieldName = (string)$areaData['default_field_name'];
            $defaultFieldType =  (string)$areaData['default_field_type'];

            if (in_array($defaultFieldType, $this->cms->relationTypes, true)) {
                // do not load links from the links
                $value = '(relations)';
            }
            else {
                $value = $this->backendValue(
                    value: $data[$defaultFieldName] ?? '',
                    colDef: ['type' => $defaultFieldType],
                    id: (int)$data['record_id']
                );
                $datasets[(string)$data['record_id']] = $value;
            }
        }
        $this->db->popResult();

        // Append the ellipsis entry
        if ($isCutted) {
            $datasets[''] = $this->cms->snippet('ellipsis');
        }
        return $datasets;
    }


    /**
     * Helper function: determine the default sorting of an area
     *
     * @param int $areaId  area id
     * @return array       [$fieldName, $sort]
     */
    public function getDefaultSort(int $areaId): array {
        $defaultReturn = ['id', 'DESC'];
        if (empty($areaId)) {
            return $defaultReturn;
        }

        $sql = "SELECT `field`.`name`, `a`.`sort_order` FROM `area` `a`"
        ." LEFT JOIN `field`"
        ." ON `a`.`sort_field_id`=`field`.`field_id`"
        ." WHERE `a`.`area_id`=? AND `a`.`deleted`=0";
        $this->db->query($sql, 'i', [$areaId]);

        if ($this->db->num_rows < 1) {
            return $defaultReturn;
        }

        [$fieldName, $order] = $this->db->fetchNum();
        if (!$fieldName) {
            // fieldName is null
            return $defaultReturn;
        }

        return [$fieldName, $order];
    }

    private function defaultImg(string $mimeType, string $extension): string
    {
        $parts = explode('/', $mimeType, 2);
        $mainType = $parts[0] ?? '';
        $subType = $parts[1] ?? '';

        $icon = match (strtolower($extension)) {
            'pdf' => 'pdf',
            'ppt', 'pptx', 'odp' => 'ppt',
            'xls', 'xlsx', 'ods', 'csv' => 'xls',
            'doc', 'docx', 'odt', 'txt', 'rtf' => 'txt',
            'zip', 'tar', 'gz', '7z', 'rar' => 'zip',
            'xml', 'html', 'htm', 'xhtml' => 'xml',
            default => null
        };

        if ($icon === null) {
            $icon = match ($mainType) {
                'image' => 'img',
                'audio' => 'mp3',
                'video' => 'mpg',
                'text'  => 'txt',
                default => null
            };
        }

        if ($icon === null) {
            if (str_contains($mimeType, 'presentation') || str_contains($mimeType, 'powerpoint')) {
                $icon = 'ppt';
            } elseif (str_contains($mimeType, 'sheet') || str_contains($mimeType, 'excel') || str_contains($mimeType, 'csv')) {
                $icon = 'xls';
            } elseif (str_contains($mimeType, 'compressed') || str_contains($mimeType, 'zip') || str_contains($mimeType, 'archive')) {
                $icon = 'zip';
            } elseif (str_contains($mimeType, 'xml') || str_contains($mimeType, 'html')) {
                $icon = 'xml';
            } elseif (str_contains($mimeType, 'word') || str_contains($mimeType, 'document')) {
                $icon = 'txt';
            } else {
                $icon = 'oth'; // final fallback
            }
        }
        return "/default/file_{$icon}.png";
    }
    /**
     * Helper function: determine the main field (for compact lists) of an area
     * Caching, because access to the same area_id is frequent in lists.
     *
     * @param int $areaId  area id
     * @return array       Spaltendefinition des Feldes
     */
    public function getDefaultField(int $areaId): array {

        if (isset($this->defaultFieldCache[$areaId])) {
            return $this->defaultFieldCache[$areaId];
        }
        $defaultReturn = $this->cms->schema['record_id'];
        $defaultReturn['name'] = 'record_id';

        if (empty($areaId)) {
            return $defaultReturn;
        }

        $this->db->pushResult();
        $sql = "SELECT * FROM `field` WHERE `default_field`=1 AND `area_id`=?";
        $this->db->query($sql, 'i', [$areaId]);

        if ($this->db->num_rows < 1) {
            $this->db->popResult();
            return $defaultReturn;
        }

        $field = $this->db->fetch();
        $this->db->popResult();
        $this->defaultFieldCache[$areaId] = $field;
        return $field;
    }

    /**
     * Helper function - clean text inside node parameters, also inside click handlers
     */
    public function _escapeParam($s, $br = '<br>') {
        return $this->cms->_nl2br(
            strtr($s, [
                '<br>' => "\n",
                '"' => '&quot;',
                "'" => '&#39;'
            ]), $br
        );
    }

    /**
     * Converts PHP ini size values (e.g. "8M", "1G", "500K") into bytes.
     */
    public function parseSize(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $unit = strtolower(substr($value, -1));
        $size = (int) $value;
        switch ($unit) {
            case 'g':
                $size *= 1024;
                // Fall-through intended
            case 'm':
                $size *= 1024;
                // Fall-through intended
            case 'k':
                $size *= 1024;
                break;
        }
        return $size;
    }

    /**
     * Load the data of a content area and keep it in the cache
     * For lists that process results from different areas in a mixed way.
     * (Deleted content areas are not returned)
     *
     * @param int $areaId     area_id
     * @return array|null
     */
    public function areaData(int $areaId): ?array
    {
        if (isset($this->areaCache[$areaId])) {
            // already in the cache
            return $this->areaCache[$areaId];
        }

        $this->db->pushResult();
        $sql = "SELECT `a`.*,"
            . "     COALESCE(`f1`.`name`, '') AS `default_field_name`,"
            . "     COALESCE(`f1`.`type`, 'text') AS `default_field_type`,"
            . "     COALESCE(`f2`.`name`, 'changed_at') AS `sort_field_name`"
            . " FROM `area` `a`"
            . "     LEFT JOIN `field` `f1` ON `a`.`default_field_id` = `f1`.`field_id`"
            . "     LEFT JOIN `field` `f2` ON `a`.`sort_field_id` = `f2`.`field_id`"
            . " WHERE `a`.`area_id` = ? AND `a`.`deleted` = 0";
        $this->db->query($sql, 'i', [$areaId]);
        if ($data = $this->db->fetch()) {
            $this->areaCache[(int)$data['area_id']] = $data;
        }
        $this->db->popResult();
        return $data;
    }

    /**
     * Profile areas (type 'profile') whose users may be logged in from the point of view of an instance:
     * those of the instance itself and those of its readable
     * instances (`readable_instances`, see InstanceCache) - only their records
     * are visible to the frontend of this instance.
     *
     * For the checkbox lists "profile areas" of a UGC area
     * (AreaBackend::_editForm()) and of an upload field with user_auth
     * (FieldBackend::_editForm()).
     *
     * @param int $instanceId  instance of the area resp. field
     * @return array  area_id => "Label (Instanz)"
     */
    public function profileAreaOptions(int $instanceId): array
    {
        $instanceCache = $this->container->get(InstanceCache::class);
        $instanceIds = [$instanceId];
        foreach ((array)($instanceCache->instance($instanceId)['readable_instances'] ?? []) as $readableId) {
            $readableId = (int)$readableId;
            if ($readableId > 0 && !in_array($readableId, $instanceIds, true)) $instanceIds[] = $readableId;
        }
        $placeholders = implode(',', array_fill(0, count($instanceIds), '?'));
        $this->db->pushResult();
        $this->db->query(
            "SELECT `a`.`area_id`, `a`.`label`, `a`.`instance_id`, `i`.`label` AS `instance_label` FROM `area` `a`"
            . " LEFT JOIN `instance` `i` ON `i`.`instance_id` = `a`.`instance_id`"
            . " WHERE `a`.`type` = 'profile' AND `a`.`deleted` = 0 AND `a`.`instance_id` IN ($placeholders)"
            . " ORDER BY " . \Camindo\Core\Modules\Instance\Instance::orderSql('i') . ", `a`.`lft` ASC",
            str_repeat('i', count($instanceIds)),
            $instanceIds
        );
        $options = [];
        while ($row = $this->db->fetch()) {
            $label = $this->cms->textOrSnippet((string)$row['label']);
            if ((int)$row['instance_id'] !== $instanceId) {
                $label .= ' ' . strtr($this->cms->snippet('parenthetical_note'), [
                    '{text}' => $this->cms->textOrSnippet((string)($row['instance_label'] ?? ''))
                ]);
            }
            $options[(int)$row['area_id']] = $label;
        }
        $this->db->popResult();
        return $options;
    }

}