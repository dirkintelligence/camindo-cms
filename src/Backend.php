<?php
/**
 * Bausteine der Backend-Oberfläche. Instanzname im Code: $backend.
 *
 * Jede Backend-Seite entsteht durch Aufrufe an diese Klasse: Kopf- und Fußzeile, Tabs,
 * Buttons, Tabellen, Info-Blöcke, Formularfelder, Blätterleisten. Sie erzeugt HTML,
 * enthält aber selbst keins - alles Sichtbare kommt aus den .html-Vorlagen unter
 * Core/backend/html bzw. Core/Modules/{Modul}/backend/html.
 *
 * SIE IST ZUSTANDSBEHAFTET
 * --------------------
 * Formular, Tabs, Buttons und Info-Blöcke werden schrittweise aufgebaut und erst am Ende
 * abgerufen. Typischer Ablauf einer Eingabemaske:
 *
 *     $backend->formInit('admin.admin_edit_form');   // Vorlage mit {data_*}-Platzhaltern
 *     $backend->formHidden('function', 'save');
 *     $backend->formField($admin, ['name' => 'name', 'value' => $data['name'], 'id' => $id]);
 *     echo $backend->form('edit_form_buttons');
 *
 * formField() ersetzt dabei den Platzhalter {data_{name}} im gemerkten Formular. Weil
 * die Instanz ein Shared Service ist (ein Objekt pro Request), gehört vor jeden neuen
 * Block der passende Reset: formInit(), tabBarReset(), buttonBarReset(), infoReset().
 *
 * ZWEI VORLAGEN-ARTEN
 * --------------------
 *     template($tpl, $replace)   HTML-Vorlage aus dem Cache. {einfach} wird per strtr
 *                                ersetzt, {#name} automatisch durch das gleichnamige
 *                                Sprach-Snippet.
 *     Cms::snippet($index)       reiner Text, mehrsprachig.
 *
 * Beides kommt AUSSCHLIESSLICH aus var/cache/. Nach jeder Änderung an einer .html- oder
 * lang/-Datei muss der Cache neu gebaut werden (Modul Tools), sonst wird weiter die alte
 * Fassung ausgeliefert.
 *
 * ZWEI WHITELISTS ALS ENTWICKLUNGSHILFE
 * --------------------
 *     $displayTypes      welcher display_type zu welchem Feldtyp passt
 *     $validationFlags   welche Validierungs-Flags je Feldtyp angeboten werden
 *
 * formField() prüft dagegen. Passt etwas nicht, erscheint an Stelle des Feldes eine
 * Fehlermeldung im Formular - kein Exception-Wurf. Schema-Fehler werden dadurch beim
 * Entwickeln sofort sichtbar, ohne die ganze Seite zu zerlegen.
 *
 * ROUTING
 * --------------------
 * backendPath() ist der Einstieg für alle Ajax-Aufrufe: Es übersetzt "{Modul}/{script}"
 * in "{Modul}/backend/{script}", bereinigt den Pfad und prüft Session, CSRF-Token
 * (Session::verifyCsrfRequest(), nur bei POST) und Modulrecht. Aufgerufen wird es aus
 * Core/public_master/backend/module.php.
 *
 * TEASER
 * --------------------
 * teaser($type, $id) rendert eine kompakte Referenz auf einen anderen Datensatz
 * (Icon + Beschriftung + Link auf dessen Info-Seite). Welche Beschriftung, welches Icon
 * und welches Script dafür gelten, steht in $teaserDefaults.
 *
 * @see Camindo\Core\Cms                  Snippets, Abfragen, Formatierung
 * @see Camindo\Core\Dataset              Schema und Persistenz
 * @see docs/developers/03-backend-entwicklung.md     Aufbau einer Backend-Seite
 */
namespace Camindo\Core;

/**
 * @property-read Pagination $pagination  Nachgeladen, siehe $lazyServices
 * @property-read AuditLog   $auditLog    Nachgeladen (Reiter "Änderungen")
 * @property-read AuditDiff  $auditDiff   Nachgeladen (Reiter "Änderungen")
 */
class Backend {

    // Nachladbare Dienste - gefüllt im Konstruktor, siehe dort.
    // --------------------
    use LazyServicesTrait;
    /**
     * Erlaubte display_type-Werte je Feldtyp.
     * formField() weist alles zurück, was hier nicht steht - siehe Klassenkommentar.
     * 'meta' ist ein Pseudo-Typ für Meta-Felder ohne eigene Typangabe.
     *
     * 'sortable' ist die sortierbare Variante von 'checkbox': jede Option steht in
     * einer eigenen .c9-smartrows-row und lässt sich mit den Pfeil-Buttons
     * verschieben. Die Reihenfolge der angehakten Optionen ist der gespeicherte Wert.
     *
     * 'reltable' (Inhalt liegt in einer eigenen Relation-Tabelle, siehe
     * Cms::$dataSuffix) wird hier wie 'json' geführt.
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
     * Texteingabetypen (Text, Numersich, Datum/Zeit), die Auswahl statt Eingabe erlauben
     * Achtung vorher auf !in_array($type, $this->cms->relationTypes) prüfen
     */
    public array $optionDisplayTypes = ['select', 'radio', 'checkbox', 'multiselect', 'sortable'];
    /**
     * Validierungs-Flags, die je Feldtyp im Backend zur Auswahl stehen.
     * Rein für die Oberfläche (Feld-Eingabemaske im Modul Area); die eigentliche
     * Prüfung macht Dataset::validateFieldValue(), das mehr Flags kennt - u. a.
     * 'trim', 'strip_tags' und 'name', die nicht auswählbar, sondern fest im
     * Schema hinterlegt sind.
     * Verweis- (relation*, pointer*, link) und Dateifelder bieten 'unique' /
     * 'unique_in_area' nicht an: Dataset::uniqueVirtualOk() prüft sie dort nicht.
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
    /** Cache der HTML-Vorlagen, je Prefix einmal aus var/cache/html/ geladen */
    private array $htmlTemplates = [];

    /**
     * Bauplan für teaser(): je Datensatz-Art die Spalte für die Beschriftung, die
     * HTML-Vorlage für das Icon und das Backend-Script der Info-Seite.
     * Der auskommentierte Block unten zeigt das Namensmuster für weitere Arten.
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
    // Zwischenspeicher der im Aufbau befindlichen Seitenelemente.
    // Jeweils mit tabBarReset() / buttonBarReset() / infoReset() / formInit() leeren,
    // bevor ein neuer Block beginnt - die Instanz lebt den ganzen Request.
    private array $tabs = [];
    private array $buttons = [];
    private string $prevLink = '';
    private string $nextLink = '';
    private array $infoBlocks = [];
    private array $infoStack = [];  // beiseitegelegte Info-Sammlungen, siehe infoPush()
    private string $form = '';
    private array $formStack = []; // beiseitegelegte Formulare, siehe formPush()

    public string $emptyPlaceholder = '';

    /**
     * Datensätze, deren ursprünglichen Dateinamen die Info-Seite zum Bearbeiten
     * anbietet (Stift hinter "Original filename", RecordBackendInfo::fileRename()).
     * Setzt RecordBackendInfo bei Schreibrecht auf den Datensatz.
     * @var array<int, true>
     */
    public array $fileRenameIds = [];
    private array $defaultFieldCache = [];

    private array $areaCache = [];

    /**
     * Formate, die ein Browser direkt anzeigen kann. Alles andere bekommt in der
     * Backend-Vorschau ein Platzhalterbild aus media/default/ (siehe defaultImg()).
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
        // Nachladbare Dienste
        // --------------------
        // Blätterleisten braucht nur, wer eine Liste ausgibt - paginationTop() und
        // paginationBottom(). Info- und Formularseiten laden Pagination gar nicht.
        //
        // (Die Zuweisung steht hier und nicht als Startwert am Property, weil PHP
        // das Überschreiben einer Trait-Eigenschaft mit abweichendem Startwert
        // innerhalb derselben Klasse nicht erlaubt.)
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
     * Backend-Seite laden - Pfadangabe: "Modulname/Scriptname"
     * -> aufgelöst in {Modulverzeichnis}/backend/Scriptname
     *
     * Das Modulverzeichnis kommt aus der Laufzeitliste (Cms::$modules, 'path'):
     * Core/Modules/{Name} oder Modules/{Name}. Ein Modul, das dort fehlt (nicht
     * installiert, inaktiv, Update wartet), ist im Backend nicht erreichbar.
     *
     * @param string $file  _path aus der Apache RewriteRule
     * @return string       aboluter Pfad auf das einzubindene Backend-Script
     */
    public function backendPath(string $path, string $kind = 'modules'):string
    {
        $path = $this->sanitizePath($path);

        // Schiebe "backend" nach Komponentenname ein:
        $parts = explode('/', $path);
        array_splice($parts, 1, 0, 'backend');

        // absoluter Pfad zum Script über die Laufzeitliste (nur aktive Komponenten)
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

        // Session prüfen - einzige Ausnahme ist der Login selbst. Die Caches baut
        // der Installer bzw. `camindo.php upgrade|cache`, den ersten Admin der
        // Installer; einen Weg ohne Anmeldung braucht das Backend nicht mehr.
        $exempt = str_starts_with($path, 'Start/login.php');
        $hasSession = (bool)$this->session->load();
        // Herkunft: ein verändernder Request muss - sofern der Browser es sagt - von
        // dieser Seite kommen (17-csrf.md §2.6). Gilt auch für die Anmeldung selbst.
        if (!$this->originAllowed()) {
            die('##accessdenied##');
        }
        if (!$exempt && !$hasSession) {
            die('##accessdenied##');
        }
        // CSRF: jeder verändernde Request (alles außer GET/HEAD) muss das Token der
        // Session mitbringen - 17-csrf.md §2.4. Dieselbe Antwort wie bei fehlender
        // Session: das Javascript legt den Anmeldedialog darüber, und ein Angreifer
        // erfährt nicht, woran es lag.
        // Ausnahme: das Anmeldeformular eines zweiten Fensters, das noch ohne Session
        // (also ohne Token) geladen wurde, während sich der Benutzer anderswo schon
        // angemeldet hat - StartBackend::login() meldet dann nur die bestehende
        // Session zurück und schließt den Dialog, ändert also nichts.
        if (!($exempt && $hasSession) && !$this->session->verifyCsrfRequest()) {
            die('##accessdenied##');
        }
        // Modulzugriff der Benutzergruppe (Access::script(): Modul erlaubt und ggf.
        // Zusatzrecht des Scripts, oder ein 'shared'-Script im Lookup-Aufruf).
        // Die Meldung erscheint im Layer.
        // Extensions haben keine Modulrechte (13 §3.6): Session + aktiv genügt.
        if ($kind === 'modules' && $hasSession && !$this->access()->script($parts[0], $parts[2] ?? '')) {
            $this->access()->denied();
        }

        return $file;
    }

    /**
     * Herkunftsprüfung verändernder Requests (17-csrf.md §2.6) - dritte Schicht
     * neben CSRF-Token und POST-Bindung.
     *
     * Moderne Browser schicken bei POST den Header Origin, sonst meist einen Referer.
     * Stammt er von einem anderen Host als dem des Backends (HTTP_HOST, hinter einem
     * Proxy auch X-Forwarded-Host) und steht er nicht in admin.allowed_origins, wird
     * abgelehnt. "Origin: null" (Sandbox, file://, bestimmte Weiterleitungen) ebenso.
     * Fehlen beide Header - Datenschutz-Erweiterungen, Referrer-Policy no-referrer -,
     * bleibt es beim Token: ablehnen hieße, solche Browser auszusperren.
     *
     * GET und HEAD prüft sie nicht; die haben keine Seiteneffekte (requirePost()).
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
     * Verändernde Aktion: der Request MUSS ein POST sein (17-csrf.md §2.7).
     *
     * Zweite Hälfte des CSRF-Schutzes. Das Token (Session::verifyCsrfRequest())
     * wird nur bei POST verlangt - und könnte deshalb nichts ausrichten, solange
     * dieselbe Aktion auch per GET läuft: `SameSite=Lax` schickt das Cookie bei
     * Top-Level-GET-Navigation mit. Erst die Methodenbindung schließt die Lücke.
     *
     * Der Aufrufer bricht bei false ab - mit Access::denied() (kleine Ajax-Endpunkte)
     * oder Access::deniedHtml() plus container_footer (ganze Seiten).
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
     * Extension eines Feldes, wenn es eine hat und sie aktiv ist:
     * ['name' => 'calendar' (Kleinschreibung wie data-c9-ext und ext/-Verzeichnis),
     *  'options' => …]. Virtuelle Felder: details.extension / details.extension_options
     * (field.details ist in der Spaltendefinition noch der JSON-String); Meta-Felder
     * aus dem PHP-Schema: 'extension' => 'Calendar', 'extension_options' => [].
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
        // settings.php 'options_provider': Klasse, deren clientOptions() die Optionen
        // für den Browser ergänzt (Extension Password: wirksame Regeln + Texte)
        $provider = (string)($this->cms->extensions[$name]['options_provider'] ?? '');
        if ($provider !== '' && method_exists($provider, 'clientOptions')) {
            $options = $this->container->get($provider)->clientOptions($options);
        }
        return ['name' => preg_replace('/[^a-z0-9_]/', '', strtolower($name)), 'options' => $options];
    }

    /**
     * Ablage projekteigener Dateien einer Extension: var/extensions/{name}/ mit
     * {name} kleingeschrieben wie das ext/-Verzeichnis und data-c9-ext (13 §3.8).
     * Stylesheets für Editoren liegen dort; die Extension liefert sie über einen
     * eigenen Endpunkt (backendExtensions/{Name}/css.php) aus, weil var/ nicht im
     * Docroot liegt.
     */
    public function extensionVarDir(string $extension): string
    {
        return (string)$this->config->get('paths.var_dir') . '/extensions/'
            . preg_replace('/[^a-z0-9_]/', '', strtolower($extension));
    }

    /**
     * Dateien in var/extensions/{name}/ mit einer Endung, sortiert - z. B. die
     * wählbaren Stylesheets in backend/form.php einer Extension.
     *
     * @return string[]  Dateinamen ohne Pfad
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
     * Aktive Extensions, die zu Feldtyp und Display-Typ passen (settings.php
     * display_types / field_types) - für die Auswahl in der Feld-Eingabemaske.
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
     * Backend-Sprachen: Kürzel, für die es Snippets gibt (Cms::backendLanguages()).
     * Grundlage der Auswahllisten in Language::backend_code, in den persönlichen
     * Einstellungen (AdminBackend::settings()) und im Anmeldeformular.
     *
     * @return string[]  z. B. ['de', 'en']
     */
    public function backendLanguages(): array
    {
        return $this->cms->backendLanguages();
    }

    /**
     * Auswahlliste der Backend-Sprachen: Kürzel => Bezeichnung.
     * Die Bezeichnung kommt aus dem Snippet backend_language_{code}, sonst das Kürzel.
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
     * Auswahlliste der Locales (Datums-, Zeit- und Zahlenformate): ICU-Bezeichner
     * => "Deutsch (Österreich) - de_AT", benannt in der Backend-Sprache und danach
     * sortiert. Nur Locales mit Region (Cms::localeIdentifiers()).
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
     * Auswahlliste der Zeitzonen: PHP-Bezeichner => "Bezeichner (UTC+02:00)".
     * Der Versatz ist der aktuelle (Sommer-/Winterzeit) - nur zur Orientierung.
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
     * Cookie des Frontend-Editings setzen (Cms::FED_COOKIE, siehe Cms::fedActive()).
     *
     *     0  Cookie löschen
     *     1  Session-Cookie - bis der Browser geschlossen wird (höchstens 12 h);
     *        Start/logout.php löscht es beim Abmelden
     *     2  ein Jahr - überdauert Abmeldung und Browser-Ende
     *
     * Der Wert ist signiert (FrontendHandover::fedCookieValue()). Unter der Domain
     * einer Website setzt ihn bei fester Backend-Domain die Vorschau.
     *
     * Gesetzt aus der Ajax-Antwort heraus (AdminBackend::settings(), Anmeldung): der
     * Set-Cookie-Header wirkt dort wie bei jeder anderen Antwort derselben Domain.
     *
     * @param int $mode  0 | 1 | 2
     */
    public function setFedCookie(int $mode): void
    {
        // signierter Wert, siehe FrontendHandover
        $this->container->get(FrontendHandover::class)
            ->setFedCookie((int)$this->session->get('admin_id'), $mode);
    }

    /**
     * Ist der angemeldete Admin Superuser (Gruppe 1)?
     *
     * Aus den geladenen Gruppenrechten (Access, Session::applyAccess()); sind keine
     * geladen (CLI mit Session::setCliAdmin()), aus der Gruppe in den Session-Details.
     */
    public function isSuperuser(): bool
    {
        $access = $this->container->get(Access::class);
        if ($access->isLoaded()) return $access->isSuperuser();
        $details = (array)($this->session->get('details') ?? []);
        return (int)($details['group_id'] ?? 0) === InstanceCache::SUPERUSER_GROUP_ID;
    }

    /**
     * Rechte der angemeldeten Gruppe - Kurzform für die Module.
     */
    public function access(): Access
    {
        return $this->container->get(Access::class);
    }

    /**
     * SQL-Zusatz für Bereichslisten der Module (Auswahllisten, Bäume), der die
     * Bereiche auf die der Benutzergruppe beschränkt: " AND `area_id` IN (...)",
     * leer für Superuser. Die Ids stammen aus der Gruppendatei, nicht aus Eingaben.
     *
     * @param bool   $writable  true: nur Bereiche mit Schreibrecht (Zielbereiche
     *                          beim Verschieben/Importieren), sonst Leserecht
     * @param string $column    Spaltenausdruck, z. B. "`a`.`area_id`"
     */
    public function areaAccessSql(bool $writable = false, string $column = '`area_id`'): string
    {
        $ids = $writable ? $this->access()->creatableAreaFilter() : $this->access()->areaFilter();
        if ($ids === null) return '';
        $list = implode(',', array_map('intval', $ids));
        return " AND {$column} IN (" . ($list === '' ? '0' : $list) . ")";
    }

    /**
     * Persönliche Einstellungen des angemeldeten Admins (`admin`.`settings`), wie sie
     * Session::create() in die Session-Details übernommen hat.
     *
     * @return array  backend_code, timezone, language_id, fed - fehlende Schlüssel = leer
     */
    public function adminSettings(): array
    {
        $details = (array)($this->session->get('details') ?? []);
        return (array)($details['settings'] ?? []);
    }

    // ====================
    // Backend page elements

    /**
     * Breadcrumb Navi umbenennen
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
     * Tab Sammlung leeren
     */
    public function tabBarReset(): void
    {
        $this->tabs = [];
    }

    /**
     * Tab zur Sammlung hinzufügen
     * @param string $label     Text oder #Snippet-ID
     * @param string $onclick   Script für onclick Handler
     * @param bool   $active    true: Tab ist der aktive Tab
     */
    public function tabAdd(string $label, string $onclick, bool $active = false): void
    {
        $tpl = $active ? 'tab_active' : 'tab';
        $this->tabs[] = $this->template($tpl, [
            '{label}' => $this->cms->textOrSnippet($label),
            '{onclick}' => $onclick
        ]);
    }

    // Reiter "Änderungen" (Audit-Log) der Info-Seiten
    // ====================
    // Jede Info-Seite eines Datasets mit Audit-Log (Bereich, Feld, Template, Status,
    // Instanz, Sprache, Konverter, Aufgabe, Komponente, Gruppe, Admin) hängt ihn an
    // ihre Reiterleiste: auditTabAdd() mit der eigenen Adresse, auditTabActive()
    // entscheidet, ob statt des Inhalts auditHistory() erscheint. Sehen darf ihn,
    // wer die Info-Seite sieht; IP-Adressen und "Alle im Audit-Log" nur Superuser
    // (wie der Reiter der Datensätze, RecordBackendInfo).

    /** Ist der Reiter "Änderungen" gewählt? (Request tab=audit) */
    public function auditTabActive(): bool
    {
        return (string)($_REQUEST['tab'] ?? '') === 'audit' && $this->auditLog->enabled();
    }

    /**
     * Reiter "Änderungen" an die Sammlung hängen - nur bei eingeschaltetem Audit-Log.
     *
     * @param string $url  Info-Seite ohne tab-Parameter, z. B. "Status/status_info.php?id=3"
     */
    public function auditTabAdd(string $url): void
    {
        if (!$this->auditLog->enabled()) return;
        $active = $this->auditTabActive();
        $this->tabAdd('#tab_audit', $active ? '' : "cApp.layers.replace('{$url}&tab=audit')", $active);
    }

    /**
     * Reiterleiste "Info | Änderungen" für Info-Seiten ohne eigene Reiter. Ist
     * "Änderungen" gewählt, gibt sie den Verlauf samt Seitenende (container_footer)
     * aus und liefert true - der Aufrufer ist dann fertig.
     *
     * @param string $url         Info-Seite ohne tab-Parameter
     * @param string $objectType  Tabelle des Datasets
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
     * Inhalt des Reiters: die letzten Einträge eines Objekts mit Vorher/Nachher
     * (AuditDiff::entry()), neueste zuerst.
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
     * Tabs der Sammlung rendern
     */
    public function tabBar(): string
    {
        $tabContainerParts = explode('{tabs}', $this->template('tab_container'));
        return $tabContainerParts[0] . implode ("\n", $this->tabs) . $tabContainerParts[1];
    }

    /**
     * Button-Sammlung leeren
     */
    public function buttonBarReset():void
    {
        $this->buttons = [];
    }

    /**
     * Button zur Sammlung hinzufügen
     * @param string $tpl       Template-ID
     * @param string $onclick   Script für onclick Handler ersetzt {onclick}
     * @param string $label     ersetzt {label}
     */
    public function buttonAdd(string $tpl, string $onclick, string $label = '', bool $alignRight = false):void
    {
        $replace = [
            '{onclick}' => $onclick,
            '{label}' => $label
        ];
        if ($alignRight) {
            // bootstap only
            $replace['class="btn'] = 'class="ms-auto btn';
        }
        $this->buttons[] = $this->template($tpl, $replace);
    }

    /**
     * Platzhalter in den bereits gesammelten Buttons ersetzen - für Vorlagen mit
     * eigenen Platzhaltern (z. B. das Menü der Datensatzliste mit {area_id}).
     */
    public function buttonBarReplace(string $from, string $to): void
    {
        foreach ($this->buttons as &$button) {
            $button = str_replace($from, $to, $button);
        }
        unset($button);
    }

    /**
     * Vorschau-Button - je Sprache der Instanz einer.
     *
     * Die Vorschau läuft nicht über das Frontend, sondern über
     * public/backend/preview.php (Page::resolvePreview()): nur mit Admin-Session,
     * unter der Domain des Backends, und auch für Datensätze, die noch nicht oder
     * nicht mehr veröffentlicht sind. Der Frontend-Pfad wandert als Parameter
     * `request` mit, die Instanz als `instance` - das Frontend ermittelt sie aus
     * der Domain, die Vorschau kann das nicht.
     *
     * Führt die Instanz nur eine Sprache, ist es der gewöhnliche Button. Bei
     * mehreren Sprachen wird daraus eine Pulldownliste "Vorschau" mit einem Eintrag
     * je Sprache (Parameter `lang`, siehe Page::resolveLanguage() - ohne Kürzel
     * entschiede Accept-Language).
     *
     * Ist der Pfad je Sprache verschieden (Übersicht eines Bereichs: der übersetzte
     * Slug-Präfix, buttonAddAreaPreview()), kommt er als Liste language_id => Pfad;
     * Sprachen ohne Eintrag fehlen dann in der Pulldownliste.
     *
     * @param string|array $path  Frontend-Pfad ohne führenden Slash, z. B. "42" oder
     *                            "0,start" - oder language_id => Pfad
     * @param int    $instanceId  Instanz des Datensatzes bzw. Templates
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
            // (Attributwert: das & zwischen den Parametern gehört maskiert)
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
     * Vorschau der Übersicht eines Bereichs (Präfix-Adresse /{lang}/{präfix}, Page::
     * resolveRequest()) - nur wenn der Bereich ein Übersichts-Template und einen
     * Slug-Präfix hat. Der Präfix ist je Sprache übersetzt, die Pulldownliste führt
     * deshalb je Sprache ihre eigene Adresse.
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
     * Buttons der Sammlung rendern
     * @param string $tpl     optional: template
     */
    public function buttonBar(string $tpl = ''): string
    {
        $tplButtonBar = explode('{buttons}', $this->template($tpl ?: 'button_bar'));
        return $tplButtonBar[0] . implode("\n", $this->buttons) . $tplButtonBar[1];
    }

    /**
     * Pagination Top - short version (no page links)
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
     * Pagination Top
     * @param string $snippetId  Snippet-Id für Anzahl Datensätze, optional
     */
    public function paginationTop(string $snippetId = ''): string
    {
        if ($this->db->num_rows_unlimited <= $this->cms->list_rows) {
            return $this->paginationTopShort($snippetId);
        }
        else {
            // Darstellung mit Skip-Buttons
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
     * Pagination Bottom
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
     * Spaltenkopf rendern, mit Klick-Handler für Sortierung nach dieser Spalte
     * 
     * @param $colDef           Spaltendefinition aus dem $schema
     * @param array $f          Ergebnis-Array aus $dataset->select()
     * @param string $colName   Spaltenname (Eingabefeldname)
     */
    public function tableHeadColumn(array $colDef, array $f, string $colName): string
    {
        $colLabel = $this->cms->textOrSnippet($colDef['label'] ?? $colName);
        $type = $colDef['type'] ?? 'text';
        if ($type === 'relation' || $type == 'pointer' || $type === 'link') {
            // Keine Sortierung bei n:m Relations (und 1:n-Verknüpfungen)
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
            // nach dieser Spalte wird sortiert
            $sortIcon = 'sort_icon_' . strtolower($order[0]);
            if ($sort[0] == $sortName) {
                // sogar als erstes
                $sortIcon .= '_primary';
            }

            // Neue Sortierung bei Klick auf Spaltenname ist keine Mehrfachsortierung
            if ($order[0] == 'ASC') $newOrder = 'DESC';
        }
        else {
            // ansonsten neutrales Icon
            $sortIcon = 'sort_icon_select';
        }
        $tplSortIcon = $this->template($sortIcon);
        $linkWithoutSort = substr(
            $this->cms->sys_reduce_uri(['sort', 'order', 'cache', 'skip', 'limit']),
            strlen('/backend/Modules')
        );
        // Die URL stammt aus dem Request - ein ' darin (Browser kodieren es im
        // Query-String nicht) beendete sonst den JS-String
        $script = 'cApp.layers.replace(' . $this->jsString("{$linkWithoutSort}sort={$sortName}&order={$newOrder}") . ')';
        return '<a' . $this->on('click', $script) . ">{$tplSortIcon}{$colLabel}</a>";
    }

    /**
     * Zelle "Original filename" der Info-Seite: der Name, bei Schreibrecht
     * ($fileRenameIds) mit Stift zum Bearbeiten - die Endung bleibt fest
     * (cApp.ui.fileRename(), RecordBackendInfo::fileRename()).
     *
     * @param array    $value           Datei-Metadaten (filename, extension)
     * @param int      $id              Datensatz-Id
     * @param int      $fieldId         field_id des Datei-Feldes
     * @param int|null $fileLanguageId  Sprache der Datei (null: einsprachiges Feld)
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
     * Info-Tabelle initialisieren
     */
    public function infoReset(): void
    {
        $this->infoBlocks = [];
    }

    /**
     * Die gesammelten Info-Zeilen beiseitelegen und mit einer leeren Sammlung
     * weitermachen - für eine Info-Tabelle innerhalb einer Zeile (die fest
     * verknüpften Inhalte eines 'link'-Feldes, RecordBackendInfo::_infoLinkBlocks()).
     * infoPop() liefert die seither gesammelten Zeilen als Tabelle und stellt die
     * äußere Sammlung wieder her. Gegenstück zu formPush()/formPop().
     */
    public function infoPush(): void
    {
        $this->infoStack[] = $this->infoBlocks;
        $this->infoBlocks = [];
    }

    /**
     * Gegenstück zu infoPush(): die inneren Zeilen als Tabelle ($tpl wie
     * infoTable()) und zurück zur äußeren Sammlung.
     */
    public function infoPop(string $tpl = 'info_table_part'): string
    {
        $html = $this->infoTable($tpl);
        $this->infoBlocks = array_pop($this->infoStack) ?? [];
        return $html;
    }

    /**
     * Schreibrichtung eines Feldwerts für das dir-Attribut (Cms::textDirection()).
     *
     * Texte (Feldtypen text, chars, encrypted als einzeiliges Feld oder Textarea, auch
     * mit Editor-Extension) folgen der Sprache ihres Inhalts: in einem mehrsprachigen
     * Feld die Sprache der jeweiligen Eingabe - "ar_SA" rechts nach links, "de_DE" links
     * nach rechts, gleich in welcher Sprache das Backend läuft. Technische Werte (E-Mail,
     * Telefon, Passwort, Namen a-z) stehen immer links nach rechts.
     *
     * @param string $lang  Sprache des Inhalts; '' = keine Angabe
     * @return string       'ltr' | 'rtl' | '' (nichts setzen - das Feld erbt die
     *                      Richtung des Backends)
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
     * Block für Info-Tabelle erstellen
     * 
     * @param Dataset $dataset                   Dataset-Kindklasse
     * @param string $name                       Eingabefeld-Name
     * @param string|int|float|bool|null $value  Feldinhalt
     * @param array $flags = []                  'ifnotempty', 'monospace', 'primary', 'html', 'expandable', ''fullsize'
     * @param ?array &$schemaVirtual             Schema mit virtuellen Feldern
     * @param int $id                            Datensatz-Id für die Ermittlung von Relations
     * @param string $langSuffix                 Sprachkürzel hinter der Beschriftung (mehrsprachiges Feld)
     */
    public function infoAdd(Dataset $dataset, string $name, string|int|float|bool|array|null $value, array $flags = [], ?array &$schemaVirtual = null, $id = null, string $langSuffix = ''):void
    {
        if (in_array('ifnotempty', $flags, true) && (string)$value === '') return;

        $schema = $schemaVirtual ?? $dataset->getSchema();
        $colDef = $schema[$name] ?? [];
        $label = $this->cms->textOrSnippet($colDef['label'] ?? $name);

        // Schloss: der Wert wird verschlüsselt gespeichert (wie formField())
        if (($colDef['type'] ?? '') === 'encrypted') {
            $label .= $this->template('encrypted_field_indicator');
        }
        // Sprachkürzel hinter der Beschriftung eines mehrsprachigen Feldes
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
            // Text eines mehrsprachigen Feldes in der Richtung seiner Sprache
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
            // Fertiges HTML ohne Inhalt (z. B. 'link'-Feld ohne verknüpften Inhalt):
            // wie backendValue() den Platzhalter zeigen statt eines leeren Blocks
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
     * Info-Row mit statischem Inhalt (unabhängig von virtuellem Feld)
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
            // führendes Leerzeichen: {class} hängt im Template direkt an "pb-1"
            '{class}' => $class ? ' ' . implode(' ', $class) : ''
        ]);
    }

    /**
     * Info-Tabelle rendern
     */
    public function infoTable($tpl = 'info_table'): string
    {
        return $this->template($tpl,[
            '{table_rows}' => implode('', $this->infoBlocks)
        ]);
    }

    /**
     * Neues Formular vorbereiten
     * 
     * @param string $tpl  : Template für das Formular mit Platzhaltern z. B. {data_...}
     */
    public function formInit(string $tpl, $replace = []): void
    {
        $this->form = $this->template($tpl, $replace);
    }

    /**
     * Hiddenfeld zum Formular hinzufügen
     * 
     * @param string $name   Feldname
     * @param string|int $value  Feldwert - wird html-Kodiert
     */
    public function formHidden(string $name, string|int $value): void
    {
        $this->form .= '<input type="hidden" name="' . $name . '" value="' . htmlspecialchars((string)$value) . '">' . "\n";
    }

    /**
     * Statischen Content anstatt Formularfeld anzeigen
     * 
     * @param Dataset $dataset  Dataset-Kind-Klasse
     * @param string $name      Feldname; Unterfelder wie 'settings.widgets' werden -
     *                          wie in formField() - zum Platzhalter
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
     * Formular liefern
     * 
     * @param string $tpl               Template für die Submit-Buttons
     * @param array $replace            Ersetzungen im Template
     * @param bool $removePlaceholders  Alle übrig gebliebenen Placeholder entfernen
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
     * Alle Benutzergruppen als group_id => Label in der Backend-Sprache: die
     * Superuser-Gruppe zuerst, die übrigen nach Label sortiert (Auswahllisten im
     * Admin-Formular, in der Suche, Reiter "Zugriff").
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

    /** Backend::attributePlaceholders(): Vorlage => Platzhalter in Attributwerten */
    private array $attributePlaceholderCache = [];

    /** Backend::markHandlers(): Vorlage => HTML mit markierten Event-Handlern */
    private array $markedTemplateCache = [];

    /**
     * Lädt ein HTML-Template und ersetzt Platzhalter im Format {#label} durch Sprach-Einträge.
     * Einfache Platzhalter im Format {label} sind für Text-Ersetzungn reserviert (siehe Parameter replace)
     *
     * @param string $tpl       Template-Name im Format module-name.tpl-name ohne .html-Extension
     * @param array $replace    Text-Ersetzungen $key => $val
     * @return string|array
     */
    public function template(string $tpl, array $replace = []): string|array
    {
        $tpl = strtolower($tpl);
        // Fehlermeldung bei .html-Suffix
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
        // Event-Handler der Vorlage für die Script-Policy markieren (19-csp.md)
        $html = $this->markedTemplateCache[$tpl] ??= $this->markHandlers($html);

        // Ersetzungen
        if (count($replace) > 0) {
            // Platzhalter in einem Attributwert ("…{x}…"): ein " im eingesetzten Wert
            // beendete das Attribut ('" onmouseover="…' - Attribut-Injektion ohne "<",
            // die kein HTML-Filter sieht). In einem Attribut ist ein rohes " immer
            // falsch, &quot; immer richtig - und ein bereits maskierter Wert enthält
            // kein " mehr, wird also nicht doppelt maskiert (16-sicherheit.md §6).
            foreach ($this->attributePlaceholders($tpl, $html) as $placeholder) {
                if (isset($replace[$placeholder]) && is_string($replace[$placeholder])
                    && str_contains($replace[$placeholder], '"')) {
                    $replace[$placeholder] = str_replace('"', '&quot;', $replace[$placeholder]);
                }
            }
            $html = strtr($html, $replace);
        }

        // Ersetze mit {#label} markierten Textstellen durch Sprach-Eintrag
        $html = preg_replace_callback('/\{#([a-z][a-z0-9_.]+)\}/', function($matches) {
            return $this->cms->snippet($matches[1]);
        }, $html);

        return $html;
    }

    /**
     * Event-Handler einer Vorlage markieren (developer_docs/19-csp.md): aus
     * onclick="…" wird data-c9h-{nonce}-click="…", aus href="javascript:…"
     * href="#" data-c9h-{nonce}-js="…", ein <script> bekommt die Nonce. Der Code bleibt
     * unverändert im Attribut stehen, auch mit seinen Platzhaltern - die ersetzt
     * template() bzw. der Aufrufer wie bisher.
     *
     * Die Script-Policy des Backends verbietet Handler-Attribute. backend-handlers.js
     * macht aus den markierten Attributen Listener, über ein Script mit der Nonce der
     * Seite. Das Kennzeichen ist die Nonce im Attributnamen: sie steht nur im Code
     * (Vorlagen, Backend::on()), nie in Inhalten aus der Datenbank - ein dort
     * eingeschleustes onclick bleibt, was es ist, und der Browser führt es nicht aus.
     */
    private function markHandlers(string $html): string
    {
        if (!str_contains($html, 'on') && stripos($html, 'javascript:') === false && !str_contains($html, '<script')) return $html;
        $mark = 'data-c9h-' . $this->securityHeaders()->nonce() . '-';
        // <script> der Vorlage selbst: Nonce der Seite
        $html = preg_replace('~<script(?=[\s>])(?![^>]*\snonce=)~i', '<script' . $this->nonceAttr(), $html);
        $html = preg_replace('~(?<=\s)href="javascript:~i', 'href="#" ' . $mark . 'js="', $html);
        return preg_replace_callback('~(?<=\s)on([a-z]+)(?==")~i',
            fn($m) => $mark . strtolower($m[1]), $html);
    }

    /**
     * Event-Handler für HTML, das nicht aus einer Vorlage kommt (19-csp.md) - statt
     * onclick="…" im PHP-Code:
     *
     *     '<a href="#"' . $backend->on('click', "cApp.layers.add('X/y.php')") . '>'
     *
     * @param string $event  click, change, keydown … oder 'js' (wie href="javascript:…":
     *                       Klick, Standardaktion immer verhindert)
     * @param string $code   Javascript wie in einem onclick-Attribut (this, event,
     *                       return false); wird hier fürs Attribut maskiert
     * @return string        ' data-c9h-{nonce}-{event}="…"'
     */
    public function on(string $event, string $code): string
    {
        return ' data-c9h-' . $this->securityHeaders()->nonce() . '-' . preg_replace('/[^a-z]/', '', strtolower($event))
            . '="' . htmlspecialchars($code, ENT_QUOTES) . '"';
    }

    /**
     * Text für einen Platzhalter von template(): HTML-escaped und '{' als Entity.
     * template() ersetzt nach dem Einsetzen noch {#label}-Marken - auch in den
     * eingesetzten Werten. Ein Wert mit "{#…}" (Handbuch, Snippet-Text, Eingabe eines
     * Users) würde sonst durch ein Snippet ersetzt.
     */
    public function escape(string $text): string
    {
        return $this->protect(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE));
    }

    /**
     * <option>-Liste aus der Vorlage option.
     *
     * @param array $options              Wert => Beschriftung (Text, wird maskiert)
     * @param string|int|null $selected   ausgewählter Wert (Vergleich als String), null = keiner
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

    /** Fertiges HTML für einen Platzhalter von template() - '{' als Entity, siehe escape() */
    public function protect(string $html): string
    {
        return str_replace('{', '&#123;', $html);
    }

    /** ' nonce="…"' für ein <script>-Tag (Script-Policy, 19-csp.md) */
    public function nonceAttr(): string
    {
        return $this->securityHeaders()->nonceAttr();
    }

    private function securityHeaders(): SecurityHeaders
    {
        return $this->container->get(SecurityHeaders::class);
    }

    /**
     * Platzhalter einer Vorlage, die in einem Attributwert mit doppelten
     * Anführungszeichen stehen - je Vorlage einmal ermittelt.
     *
     * @return string[]  z. B. ['{value}', '{title}']
     */
    private function attributePlaceholders(string $tpl, string $html): array
    {
        if (isset($this->attributePlaceholderCache[$tpl])) return $this->attributePlaceholderCache[$tpl];
        $found = [];
        if (str_contains($html, '{')) {
            // Tags quote-bewusst, darin die Attributwerte in "…"
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
     * Bereinigt einen user-definierten Pfad
     *
     * @param string $arg     Der zu sichernde Pfad
     * @param array  $options Mögliche Keys: 'allowAbsolute' (bool)
     * @return string
     */
    public function sanitizePath(string $arg, array $options = []): string
    {
        // Standardwerte extrahieren
        $allowAbsolute = $options['allowAbsolute'] ?? false;

        if ($arg !== '') {
            // Normalisierung der Slashes
            $arg = str_replace('\\', '/', $arg);
            
            // Entfernen verbotener Zeichen
            $arg = preg_replace('/[\x00<>:"\'|?*%\\\\]/u', '', $arg);
            
            // Bereinigung von Doppelslashes und Aufstiegsversuchen (..)
            $arg = preg_replace('/\/+/u', '/', $arg);
            $arg = preg_replace('/^\.+\//u', '', $arg);
            $arg = rtrim($arg, '/');
            $arg = preg_replace('/\.+/u', '.', $arg);
            $arg = preg_replace('/(\/\.)+\//u', '/', $arg);
            
            // Behandlung des führenden Slashes
            if (!$allowAbsolute) {
                $arg = ltrim($arg, '/');
            }
        }
        return $arg;
    }

    /**
     * Balloon-Message erzeugen
     * @string $type    Bootstrap Alert-Klasse 'success', 'danger', ...
     * @string $msg     Message-Text
     */
    public function balloon(string $type, string $msg): void
    {
        echo '<script' . $this->nonceAttr() . '>cApp.system.balloon(' . $this->jsString($type) . ',' . $this->jsString($msg) . ");</script>\n";
    }

    /**
     * Wert als Javascript-String-Literal in einfachen Anführungszeichen: ' " < > &
     * als \u00.. - damit sicher in <script> UND in einem Attribut (onclick="…"),
     * ohne weiteres Maskieren. Für jeden Wert, der nicht aus dem Code selbst stammt
     * (Request, Datenbank, Übersetzungen mit Apostroph).
     */
    public function jsString(string $value): string
    {
        $json = json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
            | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return "'" . substr((string)$json, 1, -1) . "'";
    }

    /**
     * Wrapper, um JavaScript zu schreiben, z. B. im Ajax Load
     * @string $code    JS-Code
     */
    public function script(string $code): void
    {
        echo '<script' . $this->nonceAttr() . '>requestAnimationFrame(() => {setTimeout(function(){' . $code . "}, 50);});</script>\n";
    }

    /**
     * Liefere Objekt in lesbarem Format
     * Objects:
     *   status, admin, group
     * 
     * @param string $type  Objekttyp (Tabellen-Name)
     * @param int $id       Primary key
     * @param bool $linked  true (default): verlinkt auf infoseite des Objektes
     * @param bool $icon    true (default): mit Icon
     * @return string       Label oder ''
     */
    public function teaser(string $type, int $id, bool $linked = true, bool $icon = true, $label = true): string {
        // Verweis nur, wenn die Gruppe das Modul der Info-Seite öffnen darf - sonst
        // nur der Text (12-group-rights.md)
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
                // BESONDERHEIT: status === 0 ist definiert
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

                // Default-Feld, Sortierung und Icon des Breichs ermitteln
                $areaId = (int)$data['area_id'];
                $areaData = $this->areaData($areaId);
                $defaultFieldName = (string)$areaData['default_field_name'];
                $defaultFieldType = (string)$areaData['default_field_type'];
                $colDef = ['type' => $defaultFieldType];
                $labelHtml = $this->backendValue($data[$defaultFieldName] ?? '', $colDef);
                
                if ($icon) {
                    // Icon + Label
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
                    // Icon + Label
                    $labelHtml = $this->template($t['icon']) . ' ' . $labelHtml; 
                }
                // (Hier stand ein zweites popResult() - es hätte, aus einem
                //  pushResult()-Kontext heraus aufgerufen, das Ergebnis des AUFRUFERS
                //  vom Stapel geholt und geschlossen; die Abfrage oben ist bereits
                //  mit ihrem eigenen Paar push/pop abgeschlossen.)
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
     * Suchfeld in Formular einfügen, anstelle von Platzhalter {filter_...}
     * 
     * @param Dataset $dataset
     * @param array   $param [
     *      string $name       Feldname
     *      array  $colDef     Schema des Feldes
     * ]
     */
    public function searchField(Dataset $dataset, array $param): void {
        $param['varName'] = 'filter';
        $this->formField($dataset, $param);
    }

    /**
     * Platzhalter im Formular ersetzen, Workaround für Zusatzkomponenten
     * var string $from
     * var string $to
     */
    public function formReplace(string $from,string  $to) {
        $this->form = str_replace($from, $to, $this->form);
    }

    /**
     * Fertiges HTML an der Stelle eines Platzhalters ins Formular setzen - fehlt
     * der Platzhalter, ans Ende anhängen (wie formField() mit 'append').
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
     * Das im Aufbau befindliche Formular beiseitelegen und mit einem leeren
     * Puffer weitermachen - für Teilformulare, die in ein anderes Formular
     * eingesetzt werden (die eingebetteten Datensätze eines 'link'-Feldes,
     * RecordBackendEdit::_embedCell()): formField() usw. arbeiten danach auf dem
     * leeren Puffer, formPop() liefert dessen Inhalt und stellt das Formular wieder
     * her. Verschachtelung ist erlaubt.
     */
    public function formPush(): void
    {
        $this->formStack[] = $this->form;
        $this->form = '';
    }

    /**
     * Gegenstück zu formPush(): den seit formPush() aufgebauten Puffer liefern
     * und das beiseitegelegte Formular wiederherstellen.
     */
    public function formPop(): string
    {
        $html = $this->form;
        $this->form = array_pop($this->formStack) ?? '';
        return $html;
    }

    /**
     * Sortierfeld in Formular einfügen, anstelle von Platzhalter {sort_order}
     * 
     * @param array $sortOptions        Feldnamen => Feldlabel
     * @param string|null $sortValue    Wert aus $_REQUEST['sort']
     * @param string|null $orderValue   Wert aus $_REQUEST['order']
     * @param bool $append              wenn true: Das Feld wird ans Formular angehängt, wenn der Platzhalter {data_feldname} fehlt, (default ist false)
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
            // verbindet <label for> mit dem <select> - ohne die Ersetzung blieb der
            // Platzhalter als Id im Markup stehen
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
     * Eingabefeld in Formular einfügen, anstelle von Platzhalter {data_...}
     * 
     * @param Dataset $dataset
     * @param array $param          Array mit folgenden Werten
     *      bool   $append          wenn true: Das Feld wird ans Formular angehängt, wenn der Platzhalter {data_feldname} fehlt, (default ist false)
     *      array  $colDef          Definition (nur des Feldes!) - siehe Dataset::hookDefinition()
     *      string $name            FeldName
     *      int    $id              > 0 : Id des Datensatzes, < 1 : Datensatz wird neu erstellt
     *      mixed  $value           Wert aus $data
     *      string $varName         default: 'data', wird durch searchField() in 'filter' geändert
     *      string $note            Hinweis unter der Beschriftung (Text oder #snippet),
     *                              gilt auch für Suchfelder
     *      string $placeholder     Platzhalter im Formualar der ersetzt wird, default: data_{$name}
     *      string $display_type    Display Type aus der colDef ersetzen
     *      array  $options         bei type 'select', Auswahl-Optionen key => value
     *                              keys, die mit 'disabled' beginnen, sind nicht auswählbar
     *      bool   $selectPlease    true: "-- bitte wählen --" Option am Anfang, (unterschiedliche Defaults für data und filter)
     *      string $template        Überschreibt das entsprechend display_type gewählte Template
     *      array  $lookupOptions   Optionen für Lookupfeld [
     *                                (string) $source_table
     *                                (string) $destination_table
     *                                (string) $relation_table
     *                                (bool) $button_new
     *                              ]
     *      string $contextMenu     nur display_type 'lookup': Template des Kontextmenüs;
     *                              '' unterdrückt es (default: {modul}.lookup_context_menu)
     *      array $attr             zusätzliche Attribute wie 'onchange' => "..."
     *                              (Auch 'class' und 'style' sind erlaubt und
     *                              werden zwar zu vorhandenen hinzugefügt
     *                              das widerspricht aber der MCV-Logik über Backend-Tempaltes)
     *      bool $fullWidth         Ganze breite einnehmend
     *      int  $langId            mehrsprachiges Feld: Sprache dieses Eingabefeldes.
     *                              Der Feldname wird zu data[name][{langId}], die
     *                              Beschriftung erhält $langSuffix, Fehler werden unter
     *                              "name.{langId}" gesucht (Dataset::validateFieldValue())
     *      string $langSuffix      Zusatz zur Beschriftung, z. B. "de" - erscheint als
     *                              "| de" in gedämpfter Farbe (nur mit $langId)
     *      string $textLang        Sprache des Inhalts (Code bzw. Locale, z. B. "ar_SA"):
     *                              legt die Schreibrichtung fest (fieldDirection()).
     *                              Vorgabe bei $langId: $langSuffix; ohne Angabe erbt
     *                              das Feld die Richtung des Backends
     *      string $dir             'ltr' | 'rtl' | 'auto' - Schreibrichtung erzwingen
     *      string $labelPrefix     HTML, das VOR die Beschriftung gesetzt wird (z. B. die
     *                              Auswahl-Checkbox der Stapelverarbeitung)
     *      bool $primary           Nur <input type="text">: Fett (für Hauptfeld)
     *      bool   $flatName        Name mit Punkt nicht als Unterarray (data[a][b]),
     *                              sondern wörtlich (data[a.b])
     *      string $blockClass      zusätzliche CSS-Klasse(n) des Feldblocks (Spalte mit
     *                              dem Eingabefeld), z. B. 'warning'
     *      string $errorPrefix     Präfix, unter dem Feldfehler in Cms::errors stehen -
     *                              z. B. "embed.{record_id}." für die eingebetteten
     *                              Datensätze eines 'link'-Feldes (RecordBackendEdit)
     */
    public function formField(
            Dataset $dataset,
            array $param,
        ): void
    {
        // Parameter
        $name = (string)($param['name'] ?? '');
        $paramName = $name;
        $value = $param['value'] ?? null; // mixed
        $id = (int)$param['id'];
        $langId = isset($param['langId']) ? (int)$param['langId'] : null;
        $langSuffix = (string)($param['langSuffix'] ?? '');

        $colDef = $param['colDef'] ?? [];
        if (empty($colDef)) {
            // Wenn Feld-Schema nicht explizit angegeben, dann ziehe Feld-Schema aus Schema der Dataset-Kindklasse
            $colDef = $dataset->getSchema()[$paramName] ?? [];
        }

        $varName = $param['varName'] ?? 'data'; // 'data' or 'filter'
        $type = (string)($colDef['type'] ?? '');
        $displayType = $param['display_type'] ?? $colDef['display_type'] ?? 'text';
        // übergebene Optionen gehen dem Schema vor; Beschriftungen als #snippet
        $options = array_map(fn($label) => is_string($label) ? $this->cms->textOrSnippet($label) : $label,
            (array)($param['options'] ?? $colDef['options'] ?? []));
        $flags = (array)($colDef['flags'] ?? []);

        // Kein Eingabefeld für Volltext (berechnet sich aus anderen Textfeldern)
        if ($type == 'fulltext' && $varName == 'data') return;
        
        $placeholder = $param['placeholder'] ?? '{' . $varName . '_' .str_replace('.', '_', $name) . '}';
        $append = (bool)($param['append'] ?? false);
        if ($append && strpos($this->form, $placeholder) === false) {
            $this->form .= "\n" . $placeholder;
        }

        // Parameter und Schema prüfen
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

        // name format name(.subname) ? - flatName: der Punkt bleibt im Feldnamen
        // (filter[bild.width] für die Unterfelder einer Datei, Cms::buildFileExists())
        if (strpos($name, '.') === false || !empty($param['flatName'])) {
            $inputName = $name;
        }
        else {
            [$name, $subname] = explode('.', $name);
            $inputName = $name . '][' . $subname; // -> data[name][subname]
        }
        // mehrsprachiges Feld: eine Array-Ebene je Sprache -> data[name][{langId}]
        if ($langId !== null) {
            $inputName .= '][' . $langId;
        }

        // textarea rows="..."
        $height = (int)$colDef['height'] ?? 0;

        // Zusätzliche Klassen und Attribute für das Eingabefeld sammeln

        $class = [];
        $style = [];
        $attr = [];

        foreach ((array)($param['attr'] ?? []) as $key => $val) {
                if ($key === 'style') $style[] = "$val";
                elseif ($key === 'class') $class[] = "$val";
                // Event-Handler ('onchange' => '…', Code roh): markiert (19-csp.md)
                elseif (preg_match('/^on([a-z]+)$/i', (string)$key, $m)) $attr[] = ltrim($this->on($m[1], (string)$val));
                else $attr[] = "$key=\"$val\"";
        }
        
        if (!empty($param['primary'])) $class[] = 'c9-input-primary';

        // Schreibrichtung: erzwungen ('dir') oder nach der Sprache des Inhalts - ein
        // arabischer Text steht auch im deutschen Backend rechts nach links
        // (fieldDirection()). Nur in der Eingabemaske; Suchfelder erben.
        $dir = (string)($param['dir'] ?? '');
        if (!in_array($dir, ['ltr', 'rtl', 'auto'], true)) {
            $textLang = (string)($param['textLang'] ?? ($langId !== null ? $langSuffix : ''));
            $dir = $varName === 'data' ? $this->fieldDirection($colDef, $textLang, $displayType) : '';
        }
        if ($dir !== '') $attr[] = 'dir="' . $dir . '"';

        // uniqueID for=..., aria-labeled-by=...
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
            // Zeitzone
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
                // Erst die Id erzeugen, dann eintragen: als Platzhalter geschrieben
                // käme sie nie an. {attr} wird weiter unten per strtr() in die
                // Feldvorlage gesetzt - strtr ersetzt in EINEM Durchlauf und sieht
                // den frisch eingesetzten Text nicht noch einmal an, ein Platzhalter
                // darin bliebe also als aria-describedby="{id_prefix}" stehen.
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
                    // Anmerkung unter Label
                    $note = 'a..z 0..9 _';
                    // Tooltip bei Hover über Label
                    $tooltip = $this->cms->snippet('tooltip_name_format');
                    $pattern = '[a-z0-9_]+';
                    $class[] = 'c9-monospace';
                }
            }

            if ($displayType == 'email') {
                $pattern = '[^@]+@[^@]+\\.[^@]+';
            }

            // Übergabewerte überschreiben automatisch ermittelte Werte
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
            // Schloss: der Wert wird verschlüsselt gespeichert
            if ($type === 'encrypted') {
                $label .= $this->template('encrypted_field_indicator');
            }
        }
        // Sprachkürzel hinter der Beschriftung eines mehrsprachigen Feldes
        if ($langId !== null && $langSuffix !== '') {
            $label .= $this->template('label_lang_suffix', [
                '{lang}' => htmlspecialchars($langSuffix)
            ]);
        }

        // Hinweis des Aufrufers - auch in Suchmasken, wo der aus der Felddefinition
        // (colDef['note']) nicht gilt
        if ((string)($param['note'] ?? '') !== '') $note = $this->cms->textOrSnippet((string)$param['note']);

        if (in_array($displayType, ['checkbox', 'radio', 'sortable'], true)) {
            // Label linke Spalte erhält kein for="" 
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
            // nur in der Eingabemaske, nicht in der Suchmaske...

            // min
            // --------------------
            $min = (string)($colDef['min'] ?? '');
            if ($min !== '') {
                if (in_array($type, ['chars','text'], true)) {
                    if ($displayType == 'password' && $id > 0) {
                        // Ausnahme: Passwort darf bei Datensatz-Änderung leer bleiben
                    }
                    else {
                        // Zeichenanzahl
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
                    // Zeichenanzahl
                    $max = (int)$max;
                    $attr[] = 'maxlength="' . (string)$max . '"';
                }
            }

            // flags
            // --------------------
            // not_empty
            if (in_array('not_empty', $flags, true)) {
                if ($displayType == 'password' && $id > 0) {
                    // Ausnahme: Passwort darf bei Datensatz-Änderung leer bleiben
                }
                elseif ($displayType === 'upload') {
                    // Upload-Feld: required nur, solange keine Datei vorliegt - sonst
                    // verlangte der Browser bei jedem Speichern eine neue Datei.
                    // $value ist der Datei-Eintrag (Metadaten) der angezeigten Sprache;
                    // die eigentliche Prüfung macht VirtualFieldsTrait::validateFileRequired()
                    $file = is_array($value) ? $value : [];
                    if (empty($file['size']) || !empty($file['deleted'])) {
                        // $attr[] = 'required';
                        // Deaktiviert weil sich derzeit die echte File-Auswahl und
                        // die Auswahl über den Serverfile-Button nicht kombinieren lässt
                        // Eingabeprüfung geschieht ohnehin über PHP
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

            // "Bitte auswählen"-Option am Anfang
            if ($varName == 'filter') {
                // bei filter-Feld ist default: true
                $selectPlease = (bool)($param['selectPlease'] ?? true);
                $selectMessage = $this->emptyPlaceholder;
            }
            else {
                // ansonsten ist default: false
                $selectPlease = (bool)($param['selectPlease'] ?? false);
                $selectMessage = $this->cms->snippet('please_select');
            }
            // Bei Suchfeld: immer anzeigen
            if ($selectPlease) {
                $optionsHTML .= '<option value="">'. $selectMessage . "</option>\n";
            }

            // Optionen; eine Gruppe als Beschriftung => [Wert => Beschriftung] (<optgroup>)
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
        // erzeugt immer Array. Eine Option mit dem Label ['group' => HTML] ist keine
        // Auswahl, sondern eine Zwischenüberschrift (Schlüssel beliebig, eindeutig).
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
        // erzeugt immer Array
        //
        // Wie 'checkbox', aber jede Option steht in einer eigenen .c9-smartrows-row
        // und lässt sich mit den Pfeil-Buttons verschieben (Core/public_master/backend/
        // assets/js/backend-smartrows.js). Anders als bei den Domain-Zeilen gibt es
        // KEIN Anfügen und Entfernen: die Auswahl steht fest, frei ist nur ihre
        // REIHENFOLGE.
        //
        // Ein Formular übermittelt nur die angehakten Checkboxen, und zwar in der
        // Reihenfolge, in der ihre Zeilen im Dokument stehen. Genau das ist hier die
        // gespeicherte Sortierung. Damit sie sichtbar wird, stehen die ausgewählten
        // Optionen oben - in der Reihenfolge von $value -, die übrigen darunter in
        // der Reihenfolge von $options.
        elseif ($displayType === 'sortable') {
            // Ausgewählte Optionen in der Reihenfolge des gespeicherten Wertes
            $selected = [];
            foreach ((array)$value as $val) {
                $val = (string)$val;
                // Unbekannte Werte (Option zwischenzeitlich entfallen) übergehen
                if (!array_key_exists($val, $options)) continue;
                $selected[$val] = $options[$val];
            }
            // Rest in der Reihenfolge der Optionen
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
                // wie bei 'select': Schlüssel mit Präfix 'disabled' sind nicht wählbar
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
        // keine besonderen Anpassungen

        if ($varName != 'filter') {
                
            // styles
            // --------------------
            // Eingabefeld nicht überflüssig breit machen, wenn Zeichenlänge begrenzt ist
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

        // Extension (13 §3.4): nur in der Eingabemaske, nur wenn sie aktiv ist -
        // eine Klasse und zwei data-Attribute, cApp.extensions macht den Rest
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
        // textarea : führender Zeilenumbruch wird laut HTML Spezifikation ignoriert
        // gebe daher erst \n aus, damit User-Input mit führendem Umbruch möglich bleibt
        if ($displayType == 'textarea') $valueHtml = "\n" . $valueHtml;
        
        // Ersetzungen - alle Typen
        // --------------------
        $replacements = [
            '{name}' => $varName . '[' . $inputName . ']',
            '{id}' => $uid,
            '{class}' => !empty($class) ? ' ' . implode(' ', $class) : '',
            '{attr}' => !empty($attr) ? ' ' . implode(' ', $attr) : '',
            '{height}' => $height,
            '{value}' => htmlspecialchars($valueHtml),
        ];
        // Ersetzungen Lookup-Feld werden nachträglich im BackendScript durchgeführt
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

        // Lookup-Feld: ctx Menu dahinter packen
        // Ein Lookup-Feld, das keine Verknüpfungen schreibt (z. B. die Auswahl des
        // Vaterdatensatzes), hat für das Kontextmenü nichts anzubieten - dafür kann
        // der Aufrufer es mit contextMenu = '' unterdrücken oder ersetzen.
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
        // Fehlermeldung (aus Klasse Dataset)
        // Bei mehrsprachigen Feldern registriert Dataset::validateFieldValue() den
        // Fehler unter "name.{langId}" - nur das Eingabefeld dieser Sprache wird
        // hervorgehoben
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
        
        // zusätzliche Klasse des Feldblocks (z. B. 'warning' für ein temporär
        // hinzugefügtes Suchfeld, RecordBackendSearch)
        $blockClass = preg_replace('/[^a-z0-9_ -]/i', '', (string)($param['blockClass'] ?? ''));
        if ($blockClass !== '') $groupClass .= ' ' . trim($blockClass);

        if (!$fullWidth) $tplEditBlock = 'edit_form_block';
        else $tplEditBlock = 'edit_form_block_full_width';
        $html = $this->template($tplEditBlock, [
            '{label}' => $labelHtml,
            '{field}' => $groupHtml . $errorMsg,
            '{class}' => $groupClass,
        ]);

        // Platzhalter ersetzen
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
     * Ergebnisse aus Dataset::select aufbereiten
     * Inkl. Close-Button
     * 
     * @param array $f  Rückgabewerte von Dataset::select
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
     * Bereitet Inhalt aus einer Dataset::fetch() Zeile für die Darstellung im Backend auf
     * 
     * @param array|string|bool|int|null &$value  Inhalt
     * @param array &$colDef                      Zugehörige Spaltendefinition aus dem Schema, 'type' benötigt<
     * @param string $format                      'short' (default), 'medium', 'long'
     * @param string $id                          Datensatz-Id, für die Ermittlung von Relations
     * @return string
     */
    public function backendValue(array|string|bool|int|null $value, array $colDef, $format = 'short', $id = null): string {
        $type = $colDef['type'] ?? 'text';
        $displayType = $colDef['display_type'] ?? 'text';
        $name = $colDef['name'] ?? '';
        // Nicht jeder Zweig setzt $text (z. B. ein leeres json-Feld) - am Ende steht
        // dann der Platzhalter für "kein Inhalt".
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
            // json / reltable (gleiche Darstellung - Liste der Einträge)
            // --------------------
            if (!empty($value)) {
                // Lange Listen abschneiden: in der Datensatzliste nach 5 Einträgen,
                // auf der Info-Seite nach 10. Die Auslassung wird EINMAL angehängt,
                // danach ist die Schleife zu Ende - alles Weitere stünde ohnehin
                // hinter den drei Punkten.
                $limit = match ($format) {
                    'short'  => 5,
                    'medium' => 10,
                    default  => 0, // 'long': alles
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
                // preview-Link
                // Nie direkt ins Medienverzeichnis: das Backend holt jede Datei über
                // backend/file.php (RecordBackendServerFile::filePreview()). Nur das
                // Script kennt die Ablage {instance_id}/{field_id}, prüft die
                // Anmeldung und entscheidet, ob es auf die Datei umleiten darf oder
                // sie selbst liest (Domain-Sperre der Instanz, user_auth des Feldes).
                // Bei mehrsprachigen Datei-Feldern die Datei der Sprache als dritter
                // Pfadteil. `version` als Cache-Brecher: ein neuer Upload bekommt
                // eine neue Adresse.
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
                    // File-Feld in Listendarstellung
                    // --------------------
                    $mimeType = $value['mime_type'] ?? '';
                    if ($displayType === 'thumbnail') {
                        // Thumbnail-Feld
                        if (in_array($mimeType, $this->webImageFormats, true)) {
                            // Webfähige (und sichere) Vorschau möglich -> Img-Tag
                            $text = $this->template('img_thumbnail', [
                                '{src}' => $filePath,
                                '{width}' => (string)$width,
                                '{height}' => (string)$height,
                            ]);
                        }
                        else {
                            $icon = $this->defaultImg($mimeType, $extension);
                            $placeholderUrl = $mediaPath . $icon;
                            // Keine Vorschau möglich -> Icon
                            $text = $this->template('record.file_placeholder', [
                                '{url}' => $placeholderUrl,
                                '{extension}' => $extension
                            ]);
                        }
                    }
                    else {
                        // Upload-Feld
                        $shorts = [
                            $value['mime_type'] ?? 'unknown_type',
                            $value['size']
                        ];
                        if (!empty($value['dimensions'])) $shorts[] = $value['dimensions'];
                        $text = implode(', ', $shorts);
                    }
                }
    
                else {
                    // File-Feld auf der Info-Seite
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
                        // Dateiname: bei Schreibrecht auch leer, damit er sich nachtragen lässt
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
                // Datei wird gerade von Hintergrundprozess erstellt
                // Erzeuge Container mit Meta-Daten, der bei Abschluss des Hintergrundprozesses aktualisiert wird
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
                // Ganzer Text (Info-Seite nach "mehr"): Felder mit Editor-Extension
                // als HTML - Markdown über Camindo\Core\Markdown (HTML im Text
                // maskiert), HTML der Extension HTMLVisual durch den HtmlSanitizer
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
     * Editor-Extension eines Feldes (details.extension) in Kleinbuchstaben, '' = keine.
     * Meta-Felder aus dem PHP-Schema tragen sie direkt als 'extension'.
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
     * Abfrage der in einem Verknüpfungsfeld gespeicherten Datensätze (Cms::select()
     * mit src_id + relation, $limit + 1 Zeilen - die eine zusätzliche zeigt an, dass
     * es mehr gibt). Sortierung: manuelle Reihenfolge des Feldes, sonst bei einem
     * Zielbereich dessen Standardsortierung, bei mehreren Änderungsdatum absteigend.
     * Das Ergebnis steht danach in Database (fetch()).
     *
     * @param int  $skip   Versatz (Blättern, relatedPage())
     * @param bool $count  Gesamtzahl zählen (Database::$num_rows_unlimited)
     * @return bool  false: kein Verknüpfungsfeld bzw. kein Zielbereich (keine Abfrage)
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

        // Sortierung
        // --------------------
        if ((int)($colDef['custom_order'] ?? 0) > 0) {
            // Manuelle Sortierung
            $sort = '_custom';
            $order = 'ASC';
        }
        elseif (count($areaIds) === 1) {
            // Ein Bereich -> dessen Standardsortierung
            $areaData = $this->areaData((int)$areaIds[0]) ?: [];
            $sort = (string)($areaData['sort_field_name'] ?? '') ?: 'changed_at';
            $order = (string)($areaData['sort_order'] ?? '') ?: 'DESC';
        }
        else {
            // Mehrere Bereiche ohne manuelle Sortierung -> Änderungsdatum absteigend
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
     * Eine Seite der in einem Verknüpfungsfeld gespeicherten Datensätze (Reihenfolge
     * wie relatedDatasets()) samt Gesamtzahl - zum Blättern (API, RecordApi::fieldValue()).
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
     * Ids der in einem Verknüpfungsfeld gespeicherten Datensätze, höchstens $limit
     * (Reihenfolge wie relatedDatasets()).
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
     * Ermittle die im Feld $name gespeicherten Datensätze und erstelle daraus ein Array
     * [record_id => Default-Feld]
     * Wenn das Relationfeld manuelle Sortierung unterstützt, dann
     *     sortiert nach manueller Reihenfolge 
     * Ansonsten
     *     - Bei max 1 Bereich nach dessen Defaultsortierung
     *     - Bei mehreren Bereichen nach Änderungsdatum absteigend
     * Hilfsfunktion für Backend::backendValue(), aber auch RecordBackendEdit (Loopup-Preview-Bereich)
     * 
     * @param int $id         Datensatz-Id
     * @param array $colDef   Spaltendefnition des Relation-Feldes
     * @param string $format  'short' => 10 Datensätze (standard),
     *                        'long' => admin.list_max_datasets aus Config
     * @return array          Datensätze als flaches Array
     */
    public function relatedDatasets(int $id, array $colDef, $format = 'short'): array
    {
        if (!in_array($colDef['type'] ?? '', $this->cms->relationTypes, true)) {
            return [];
        }

        // maximal angezeigte Anzahl Datensätze
        // --------------------
        if ($format === 'short') $limit = 10;
        else $limit = (int)($this->config->get('admin.list_max_datasets') ?? 200);

        // Datensätze sammeln (Ergebnis bleibt für die Schleife unten stehen)
        // --------------------
        $this->db->pushResult();
        if (!$this->relatedSelect($id, $colDef, $limit)) {
            $this->db->popResult();
            return [];
        }

        // Array bilden
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
                // keine Verknüfungen von den Verknüpfungen laden
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

        // Ellipsis-Eintrag anhängen
        if ($isCutted) {
            $datasets[''] = $this->cms->snippet('ellipsis');
        }
        return $datasets;
    }


    /**
     * Hilfsfunktion: Standard-Sortierung eines Bereichs ermitteln
     *
     * @param int $areaId  Bereichs-Id
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
                $icon = 'oth'; // Endgültiger Fallback
            }
        }
        return "/default/file_{$icon}.png";
    }
    /**
     * Hilfsfunktion: Haupt-Feld (für kompakte Listen) eines Bereichs ermitteln
     * Caching, weil zugriff auf selbe area_id in Listen häufig.
     *
     * @param int $areaId  Bereichs-Id
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
     * Hilfsfunktion - Text innerhalb Node Parameter säubern, auch innerhalb Click-Handler
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
     * Wandelt PHP-Ini-Größenangaben (z. B. "8M", "1G", "500K") in Bytes um.
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
                // Fall-through beabsichtigt
            case 'm':
                $size *= 1024;
                // Fall-through beabsichtigt
            case 'k':
                $size *= 1024;
                break;
        }
        return $size;
    }

    /**
     * Daten eines Inhaltsbereichs laden und in Cache aufbewahren
     * Für Listen, die teilweise durcheinander Ergebnisse aus unterschiedlichen Bereichen verarbeiten.
     * (Gelöschte Inhaltsbereichs werde nicht zurückgegeben)
     * 
     * @param int $areaId     area_id
     * @return array|null
     */
    public function areaData(int $areaId): ?array
    {
        if (isset($this->areaCache[$areaId])) {
            // liegt bereits im Cache
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
     * Profil-Bereiche (type 'profile'), deren User aus Sicht einer Instanz
     * angemeldet sein können: die der Instanz selbst und die ihrer lesbaren
     * Instanzen (`readable_instances`, siehe InstanceCache) - nur deren Datensätze
     * sieht das Frontend dieser Instanz.
     *
     * Für die Checkbox-Listen "Profil-Bereiche" eines UGC-Bereichs
     * (AreaBackend::_editForm()) und eines Upload-Feldes mit user_auth
     * (FieldBackend::_editForm()).
     *
     * @param int $instanceId  Instanz des Bereichs bzw. Feldes
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