<?php
/**
 * Frontend-Request auflösen: URL -> Datensatz -> Template.
 *
 * Aufrufer sind Core/public_master/index.php (Frontend, resolveRequest()) - dorthin leiten
 * die Rewrite-Regeln in public/.htaccess (Docroot) alle Frontend-Aufrufe um - und
 * Core/public_master/backend/preview.php (Vorschau aus dem Backend, resolvePreview()). Die
 * Klasse rendert nicht selbst, sie bindet am Ende das kompilierte Template aus
 * var/php/ ein - das enthält bereits fertiges PHP (vgl. Modules/Template/
 * TemplateParserTrait).
 *
 * ZWEI MODI: FRONTEND UND BACKEND (VORSCHAU)
 * --------------------
 * Beide laufen durch dieselbe Auflösung (resolve()); sie unterscheiden sich in
 * drei Punkten:
 *
 *                       FRONTEND (resolveRequest)     BACKEND (resolvePreview)
 *     Zugang            jeder                         nur mit Admin-Session
 *                                                     (Session::load(), sonst Abbruch)
 *                                                     bzw. Vorschau-Token (s. u.)
 *     Instanz           aus der Domain                Parameter `instance` - die
 *                                                     Vorschau läuft unter der Domain
 *                                                     des Backends, jede ist erlaubt
 *     Datensatz in      muss VERÖFFENTLICHT sein:     darf alles sein: vordatiert,
 *     der URL           status_id = 0 und             abgelaufen, jeder Status
 *                       online_at erreicht, sonst
 *                       "record not found"
 *     kanonische URL    301 bei Abweichung            kein Redirect - die Adresse
 *                                                     bleibt die des Backend-Aufrufs
 *     Caching           -                             Anti-Caching-Header, damit die
 *                                                     Vorschau immer den aktuellen
 *                                                     Stand zeigt
 *
 * Nicht mit dem Modus ändert sich, was die Templates sehen: CMS:SELECT liefert in
 * beiden Fällen nur veröffentlichte Datensätze (Cms::cmsSelect() ->
 * Cms::publishedSql()). Die Vorschau zeigt also einen unveröffentlichten Datensatz
 * so, wie er nach der Freigabe aussähe - samt den Listen, die dann um ihn herum
 * stehen. Für den Datensatz in der URL gilt im Frontend `offline_at` bewusst NICHT:
 * ein abgelaufener Datensatz bleibt unter seiner Adresse erreichbar (verlinkte
 * Artikel, Suchmaschinen), er erscheint nur in keiner Liste mehr.
 *
 * Mit fester Backend-Domain (admin.backend_domain) leitet preview.php weiter auf die
 * Domain der Website: /index.php?c9preview={token} (FrontendHandover). resolveRequest()
 * prüft das Token und schaltet in den Vorschau-Modus; Instanz, Pfad und Sprache kommen
 * aus dem Token, die Domain muss zur Instanz gehören (developer_docs/21-backend-domain.md).
 *
 * Die Vorschau-Adresse baut Backend::buttonAddPreview():
 *     /backend/preview.php?instance={id}&request={slug|id}[,{template}][&lang={code}]
 * `request` und `lang` haben dasselbe Format wie im Frontend (nach dem Rewrite).
 *
 * WELCHE WEBSITE? (INSTANCE)
 * --------------------
 * Jeder Aufruf gehört zu genau einer Instanz - im Frontend ermittelt aus der Domain
 * des Requests (resolveInstance(), über den Dateicache Camindo\Core\InstanceCache -
 * die Datenbank wird dafür nicht mehr befragt):
 *
 *     1. `instance`.`primary_domain` stimmt überein
 *     2. eine Zeile in `rel_instance_domain` stimmt überein (wörtlich, oder als
 *        regulärer Ausdruck, wenn dort `regex` = 1 steht)
 *     3. sonst die Standard-Instance (Instance::DEFAULT_ID) - sie fängt alles auf,
 *        was nirgends eingetragen ist
 *
 * Daraus entsteht Cms::$sqlAccess: die Instanz selbst plus die in
 * `rel_instance_instance` freigegebenen. Cms::select() hängt den Ausdruck an jede
 * Abfrage auf `area`, `record` und `template` - Inhalte fremder Instanzen sind damit
 * über das Frontend nicht erreichbar. Datensatz und Template werden zusätzlich
 * ausdrücklich geprüft, weil beide auch über direkte Abfragen geholt werden.
 *
 * WELCHE SPRACHE? (resolveLanguage())
 * --------------------
 * Die Sprachen eines Auftritts sind die der eigenen Instanz (Hauptsprache zuerst)
 * plus die der lesbaren Instanzen (`readable_instances`) - alles aus dem Dateicache
 * (InstanceCache::languagesOf()), ohne Datenbankabfrage. Page hält diese Liste
 * ($languages) und gibt sie an Cms weiter (Sprachumschalter, CMS:URL).
 *
 *     Auftritt mit EINER Sprache      immer diese; ein Sprachkürzel in der URL wird
 *                                     nur akzeptiert, wenn es diese Sprache meint
 *     Auftritt mit MEHREREN Sprachen  das Kürzel aus der URL (/{lang}/...); fehlt es,
 *                                     entscheidet Accept-Language des Browsers
 *                                     (de-AT trifft de_DE, siehe
 *                                     InstanceCache::negotiate()), sonst die
 *                                     Hauptsprache der eigenen Instanz
 *
 * Die ermittelte Sprache geht an Cms::setLanguage(): ab da liefert select() die
 * data_*-Zeilen und den `record_cache` dieser Sprache. Für jede Instanz, die die
 * Sprache nicht führt, gilt deren Hauptsprache (Cms::$languageMap) - das kann auch
 * die eigene sein, wenn die Sprache nur eine lesbare Instanz führt.
 *
 * KANONISCHE URL
 * --------------------
 * Steht in der Instanz canonical = 1, gibt es zu jedem Datensatz genau EINE gültige
 * URL: Haupt-Domain + Protokoll der Instanz + Cms::recordUrl() (Sprachkürzel, sobald
 * die Instanz mehrere Sprachen führt; Slug statt Id, sobald einer da ist). Weicht der
 * Aufruf davon ab - z. B. die in einem Printtext abgedruckte Id "/42" oder ein Aufruf
 * ohne Sprachkürzel -, antwortet Page mit 301 auf die kanonische URL. Suchmaschinen
 * speichern so je Inhalt nur eine Adresse. Das Template in der URL bleibt erhalten
 * (verschiedene Darstellungen sind verschiedene Seiten), ebenso die Query-Parameter.
 * Der Aufruf der bloßen Domain (Startseite der Instanz) wird nicht umgeleitet.
 *
 * URL-FORMAT
 * --------------------
 *     /{slug|id}                    Datensatz mit seinem zugewiesenen Template
 *                                   (reine Ziffernfolge = Id, sonst Slug)
 *     /{präfix}/{slug}              Datensatz eines Bereichs mit Slug-Präfix
 *                                   (Cms::recordIdsBySlugPath())
 *     /{slug|id},{template}         Datensatz mit explizit angegebenem Template
 *     /0,{template}                 Template ohne Datensatz (muss `standalone` sein)
 *     /{lang}/{rest}                mehrsprachig; die Rewrite-Regel trennt {lang} ab
 *                                   und übergibt es als eigenen Parameter
 *     /                             Startseite der Instanz (`instance`.`record_id` /
 *                                   `template_id`)
 *
 * Nach dem Rewrite kommen genau zwei Parameter an: `request` (alles nach dem Sprachteil)
 * und optional `lang`. `request` wird an Kommata zerlegt:
 *
 *     {slug|id} , {template} , {hash1} , {hash2}
 *
 * TEMPLATE-ERMITTLUNG (erster Treffer gewinnt)
 * --------------------
 *     1. Template aus der URL          -> Berechtigung wird geprüft, s. u.
 *     2. record.template_id            -> vom Redakteur am Datensatz gewählt
 *     3. area.default_template_id      -> Vorgabe des Inhaltsbereichs
 * Bleibt danach kein Templatename übrig, endet der Request mit einer Fehlermeldung.
 *
 * BERECHTIGUNG BEI URL-TEMPLATES
 * --------------------
 * Ein per URL erzwungenes Template darf nicht beliebig sein, sonst ließe sich jeder
 * Datensatz durch jedes Template rendern. Erlaubt ist es nur, wenn
 *   - kein Datensatz im Spiel ist UND template.standalone = 1, oder
 *   - es ohnehin das am Datensatz gewählte Template ist, oder
 *   - rel_area_template.override = 1 es für den Bereich freigibt, oder
 *   - der Datensatz kein eigenes Template hat und das angegebene das Default-Template
 *     des Bereichs ist.
 *
 * FEHLERBEHANDLUNG
 * --------------------
 * Alle Fehlerwege enden mit die() und einer englischen Klartextmeldung. Was es unter
 * der Adresse nicht gibt, antwortet mit 404 (notFound()): unbekannter Datensatz oder einer
 * aus einer fremden Instanz, unbekanntes Sprachkürzel, unbekanntes oder nicht
 * freigegebenes Template aus der URL, fehlende Template-Datei. Ein URL-Template ohne
 * Berechtigung bekommt bewusst ebenfalls 404 und nicht 403 - von außen soll nicht zu
 * unterscheiden sein, ob es das Template gibt. Fehlender Zugang zur Vorschau und
 * abgelaufene Token enden mit 403. Eine gestaltete Fehlerseite gibt es noch nicht.
 *
 * NUR DAS SPRACHKÜRZEL (/en OHNE SCHRÄGSTRICH)
 * --------------------
 * Die Rewrite-Regel für das Sprachsegment verlangt den Schrägstrich dahinter, "/en"
 * kommt deshalb als request=en an. Ist der ganze Pfad ein Sprachkürzel des Auftritts
 * (InstanceCache::findByCode(), dieselbe Regel wie für /en/), leitet
 * redirectBareLanguage() mit 301 auf die Startseite dieser Sprache um - mit dem
 * vollen Code der Sprache ("/en_US/", bei nur einer Sprache "/"). Bei nur einer
 * Sprache geht ein Datensatz mit genau diesem Slug vor - dort ist /en seine reguläre
 * Adresse. Bei mehreren Sprachen trägt jede Datensatz-Adresse ohnehin ein
 * Sprachsegment, die Sprache gewinnt.
 *
 * TEMPLATES IM BACKEND (renderRecord())
 * --------------------
 * Dieselbe Klasse führt ein Template auch außerhalb eines Frontend-Requests aus:
 * die Datensatzliste (RecordBackendList) und die ausführliche Lookup-Vorschau
 * (RecordBackendEdit) formatieren je Datensatz das Listen-Template seines
 * Bereichs (`area`.`list_template_id`). Der kompilierte Code greift auf $this->cms,
 * $this->db und die nachladbaren Dienste zu - genau die Umgebung, die Page bietet;
 * die Ausgabe wird gepuffert und als String zurückgegeben.
 */
namespace Camindo\Core;

use Camindo\Core\Modules\Record\RecordUser;
use Camindo\Core\Modules\Instance\Instance;

/**
 * @property-read Pagination $pagination  Nachgeladen, siehe $lazyServices
 * @property-read RecordUser $recordUser  Nachgeladen, siehe $lazyServices
 * @property-read Email $email            Nachgeladen, siehe $lazyServices
 * @property-read CmsZip $zip             Nachgeladen, siehe $lazyServices (CMS:ZIP)
 *
 * Dazu kommen die Dienste der aktiven Zusatzmodule (settings.php['services']),
 * ebenfalls nachgeladen - siehe Konstruktor.
 */
class Page {

    // Nachladbare Dienste - gefüllt im Konstruktor, siehe dort.
    // --------------------
    use LazyServicesTrait;

    // Modus (siehe Klassenkommentar)
    public const MODE_FRONTEND = 'frontend';
    public const MODE_BACKEND  = 'backend';
    private string $mode = self::MODE_FRONTEND;
    // Vorschau per Token unter der Domain der Website (FrontendHandover) - Instanz
    // aus dem Token, 0 = keine
    private int $previewInstanceId = 0;

    // Ermittelte Eigenschaften aus dem Request, stehen in den Templates zu Verfügung
    public string $protocol = 'https'; // protocol
    public int $instanceId = 0;        // instance_id aus der Domain, siehe resolveInstance()
    public array $instance = [];       // instance data, aus InstanceCache
    public array $instanceIds = [];    // eigene Instanz + `readable_instances`
    public int $languageId = 0;        // Inhaltssprache des Requests, siehe resolveLanguage()
    public string $langCode = '';      // ihr Kürzel
    public array $languages = [];      // Sprachen aller zusammengeschalteten Instanzen
                                        // (eigene + lesbare), language_id => Zeile
    public int $id = 0;                // record id, der eingebunden wird
    public string $slug = '';          // record slug
    public int $areaId = 0;            // record area_id
    public string $templateName = '';  // template name, das eingebunden wird
    public bool $urlTemplate = false;  // Flag: true wenn das Template explizit in der URL angegeben wirde
    public ?int $hash1 = null;         // Zusatz-Parameter 1
    public ?int $hash2 = null;         // Zusatz-Parameter 2

    // unique url, nur verfügbar wenn in der Instance canonical=1 gesetzt ist
    // (siehe redirectCanonical())
    public string $canonicalUrl = '';

    public function __construct(
        private Cms $cms,
        private Database $db,
        private Session $session,
        private InstanceCache $instanceCache,
        protected ContainerInterface $container
    )
    {
        // Nachladbare Dienste
        // --------------------
        // $this->cms braucht jedes Template, diese drei nur, wenn der passende
        // Befehl darin vorkommt. Der kompilierte Template-Code greift unter genau
        // diesen Namen darauf zu - sie stehen so in der Spalte 'method' der
        // Befehlsdefinitionen ('pagination.cmsJumpList' -> $this->pagination).
        //
        // (Die Zuweisung steht hier und nicht als Startwert am Property, weil PHP
        // das Überschreiben einer Trait-Eigenschaft mit abweichendem Startwert
        // innerhalb derselben Klasse nicht erlaubt.)
        //
        // @see Camindo\Core\LazyServicesTrait
        // @see Camindo\Core\Modules\Template\commands.php
        $this->lazyServices = [
            'pagination' => Pagination::class,   // (CMS:JUMP_LIST|JUMP_PREV|JUMP_NEXT)
            'recordUser' => RecordUser::class,   // (CMS:USER_*)
            'email'      => Email::class,        // (CMS:EMAIL)
            'zip'        => CmsZip::class,       // (CMS:ZIP) - selten, daher nachgeladen
        ];

        // Dienste der Zusatzmodule: settings.php['services'] = ['pdf' => CmsPdf::class]
        // macht $this->pdf im Template-Code verfügbar - für die Befehle, die das
        // Modul in seiner commands.php mit 'method' => 'pdf.cmsPdfPage' definiert
        // (TemplateParserTrait::loadCommands()). Die Core-Namen bleiben reserviert.
        foreach ($this->cms->modules as $module) {
            foreach ((array)($module['services'] ?? []) as $name => $class) {
                if (is_string($name) && is_string($class) && !isset($this->lazyServices[$name])) {
                    $this->lazyServices[$name] = $class;
                }
            }
        }
    }

    /**
     * Frontend-Request auflösen (Core/public_master/index.php) - Frontend-Modus, siehe
     * Klassenkommentar.
     *
     * Zerlegt die URL, lädt den Datensatz, ermittelt und prüft das Template und bindet es
     * ein. Kehrt im Erfolgsfall zurück, nachdem die Seite ausgegeben wurde; in allen
     * Fehlerfällen wird der Request mit die() beendet.
     */
    public function resolveRequest(): void
    {
        $this->mode = self::MODE_FRONTEND;

        // Feste Backend-Domain: dort gibt es keine Website, nur das Backend
        // (es sei denn, die Domain gehört doch einer Instanz)
        $backendDomain = $this->container->get(BackendDomain::class);
        if ($backendDomain->isFixed() && $backendDomain->isBackendHost()
            && $this->instanceCache->matchDomain($this->requestHost()) < 1 && PHP_SAPI !== 'cli') {
            header('Location: ' . $backendDomain->url('/'), true, 302);
            exit;
        }

        // Frontend-Editing unter dieser Domain einschalten (FrontendHandover::fedUrl())
        $fedToken = (string)($_GET[FrontendHandover::FED_PARAM] ?? '');
        if ($fedToken !== '') {
            $this->switchFedOn($fedToken);
        }

        // Vorschau aus dem Backend unter der Domain der Website (FrontendHandover)
        $token = (string)($_GET[FrontendHandover::PARAM] ?? '');
        if ($token !== '') {
            $this->startTokenPreview($token);
        }
        $this->resolve();
    }

    /**
     * Vorschau-Token prüfen und in den Vorschau-Modus wechseln: Instanz, Pfad und
     * Sprache kommen aus dem Token, nicht aus der URL. Ein ungültiges oder
     * abgelaufenes Token (Backend-Session vorbei) endet mit 403.
     * Setzt nebenbei das Cookie des Frontend-Editings unter dieser Domain nach der
     * Einstellung des Admins.
     */
    private function startTokenPreview(string $token): void
    {
        $handover = $this->container->get(FrontendHandover::class);
        $preview = $handover->readPreview($token);
        if ($preview === null) {
            if (!headers_sent()) {
                http_response_code(403);
                header('Cache-Control: no-store');
            }
            die('CMS error: preview link expired - please open the preview from the backend again.');
        }
        $this->mode = self::MODE_BACKEND;
        $this->previewInstanceId = $preview['instance'];
        $_REQUEST['request'] = $preview['request'];
        $_REQUEST['lang'] = $preview['lang'];
        if (!headers_sent()) {
            $this->sendPreviewHeaders();
            // Das Token steht in der URL - nicht an fremde Seiten weiterreichen
            header('Referrer-Policy: same-origin');
        }
        $handover->setFedCookie($preview['admin_id'], $preview['fed']);
    }

    /**
     * FED-Link einlösen: Token prüfen, Domain muss zur Instanz gehören, Cookie setzen
     * und auf die Startseite weiterleiten (das Token soll nicht in der Adresszeile
     * bleiben). Ungültig: 403.
     */
    private function switchFedOn(string $token): void
    {
        $handover = $this->container->get(FrontendHandover::class);
        if (isset($_GET[FrontendHandover::HOPS_PARAM])) {
            $this->fedSyncHop($handover, $token);
        }
        $fed = $handover->readFed($token);
        if ($fed === null || $this->instanceCache->resolveDomain($this->requestHost()) !== $fed['instance']) {
            if (!headers_sent()) {
                http_response_code(403);
                header('Cache-Control: no-store');
            }
            die('CMS error: link expired - please open it from the backend settings again.');
        }
        $handover->setFedCookie($fed['admin_id'], $fed['fed']);
        if (!headers_sent()) {
            header('Cache-Control: no-store');
            header('Referrer-Policy: same-origin');
            header('Location: /', true, 302);
        }
        exit;
    }

    /**
     * Ein Schritt des Abgleichs mit allen Websites (FrontendHandover::fedSyncData()):
     * Die erste Nummer in c9hops muss diese Domain sein. Cookie setzen bzw. löschen,
     * weiter zur nächsten Website, nach der letzten zurück ins Backend.
     *
     * Mit c9probe=1 nur prüfen: 1x1-GIF, wenn Token und Domain passen, sonst 404 -
     * cApp.ui.fedSync() erkennt daran Domains, die nicht (oder nicht erreichbar) zu
     * dieser Installation gehören, und lässt sie aus der Kette.
     */
    private function fedSyncHop(FrontendHandover $handover, string $token): never
    {
        $probe = !empty($_GET[FrontendHandover::PROBE_PARAM]);
        $hops = [];
        foreach (explode('-', (string)$_GET[FrontendHandover::HOPS_PARAM]) as $hop) {
            if (ctype_digit($hop)) $hops[] = (int)$hop;
        }
        $sync = $handover->readFedSync($token);
        $site = ($sync !== null && $hops) ? ($sync['sites'][$hops[0]] ?? null) : null;
        $host = $this->requestHost();
        $valid = is_array($site) && count($hops) <= count($sync['sites'])
            && preg_replace('/:\d+$/', '', (string)($site[0] ?? '')) === $host
            && $this->instanceCache->matchDomain($host) === (int)($site[1] ?? 0);
        if (!headers_sent()) {
            header('Cache-Control: no-store');
            header('Referrer-Policy: same-origin');
        }
        if ($probe) {
            if (!$valid) {
                http_response_code(404);
                exit;
            }
            header('Content-Type: image/gif');
            echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
            exit;
        }
        if (!$valid) {
            http_response_code(403);
            die('CMS error: link expired - please save the backend settings again.');
        }
        $handover->setFedCookie($sync['admin_id'], $sync['fed']);
        array_shift($hops);
        if ($hops) {
            [$domain, , $protocol] = $sync['sites'][$hops[0]];
            $next = $protocol . '://' . $domain . '/index.php?' . FrontendHandover::FED_PARAM . '='
                . rawurlencode($token) . '&' . FrontendHandover::HOPS_PARAM . '=' . implode('-', $hops);
        }
        else {
            $next = $sync['return'] !== '' ? $sync['return'] : '/';
        }
        header('Location: ' . $next, true, 302);
        exit;
    }

    /** Anti-Caching und noindex für die Vorschau */
    private function sendPreviewHeaders(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Robots-Tag: noindex, nofollow');
    }

    /**
     * Vorschau aus dem Backend (Core/public_master/backend/preview.php) - Backend-Modus,
     * siehe Klassenkommentar. Request: instance, request, lang.
     *
     * Zugriffsrecht ist derzeit allein die laufende Admin-Session; eine feinere
     * Prüfung (darf dieser Admin diese Instanz sehen?) gehört zu der noch offenen
     * Rechteverwaltung des Backends. Session::load() stellt nebenbei Backend-Sprache
     * und Zeitzone des Admins ein - beides überschreibt resolveLanguage() gleich mit
     * denen der Inhaltssprache, wie im Frontend.
     */
    public function resolvePreview(): void
    {
        if (!$this->session->load()) {
            die('CMS error: access denied!');
        }
        $this->mode = self::MODE_BACKEND;

        // Backend unter einer fremden Domain: weiter auf die Domain der Website, mit
        // Token statt Session-Cookie (FrontendHandover::previewUrl()). Das Recht auf die Instanz wird hier
        // geprüft - danach zählt nur noch das Token.
        $instanceId = (int)($_REQUEST['instance'] ?? 0);
        if (!$this->container->get(Access::class)->instance($instanceId)) {
            die('CMS error: access denied!');
        }
        $url = $this->container->get(FrontendHandover::class)->previewUrl(
            $instanceId, (string)($_REQUEST['request'] ?? ''), (string)($_REQUEST['lang'] ?? '')
        );

        // Die Vorschau darf nirgends hängen bleiben - weder im Browser noch in
        // einem Proxy: der Redakteur will nach jedem Speichern den neuen Stand
        // sehen. Und sie soll nicht in einem Suchindex landen, falls das Backend
        // öffentlich erreichbar ist.
        if (!headers_sent()) {
            $this->sendPreviewHeaders();
            if ($url !== '') {
                header('Location: ' . $url, true, 302);
                exit;
            }
        }
        $this->resolve();
    }

    /**
     * Ist das die Vorschau aus dem Backend? (für Templates, die sich in der
     * Vorschau anders verhalten wollen - z. B. einen Hinweisbalken zeigen)
     */
    public function isPreview(): bool
    {
        return $this->mode === self::MODE_BACKEND;
    }

    /**
     * Die gemeinsame Auflösung beider Modi: URL -> Datensatz -> Template -> include.
     */
    private function resolve(): void
    {
        global $cms;
        global $set;

        $this->protocol = $this->getProtocol();

        // Die Vorschau zeigt den Arbeitsstand - nichts aus dem Ausgabe-Cache von
        // CMS:INCLUDE, und der Arbeitsstand kommt auch nicht hinein
        $this->cms->includeCacheOff = $this->isPreview();

        // Welche Website ist gemeint? Muss VOR jeder Inhaltsabfrage feststehen -
        // sie setzt Cms::$sqlAccess, und daran hängt select().
        $this->resolveInstance();

        // Script-Policy der Instanz (zuschaltbar, SecurityHeaders::sendFrontendCsp())
        // - vor jeder Ausgabe, CMS:CSP im Template kann sie danach noch ändern
        $this->container->get(SecurityHeaders::class)->sendFrontendCsp((array)$this->instance);

        // Welche Sprache? Ebenfalls vor der ersten Inhaltsabfrage - select() joint
        // die data_*-Zeilen und den Inhalts-Cache der Sprache.
        $langCode = preg_replace('/[^a-zA-Z0-9\-_]/', '', (string)($_REQUEST['lang'] ?? ''));
        $this->resolveLanguage($langCode);

        $request = (string)($_REQUEST['request'] ?? '');

        // "/en" ohne Schrägstrich -> Startseite der Sprache (siehe Klassenkommentar)
        if ($langCode === '') $this->redirectBareLanguage($request);
        $parts = explode(',', $request);

        // Bestandteile der URL: {slug|id},{template},{hash1},{hash2}
        // Template als Referenz "[bereich.]name" (Cms::templateFile()) - hart auf
        // [a-z0-9_.] beschränken, der Rest wird unten zu einem Dateinamen.
        $slug = $parts[0];
        $templateName = preg_replace('/[^a-z0-9_.]/', '', (string)($parts[1] ?? ''));
        $this->hash1 = (int)($parts[2] ?? 0);
        $this->hash2 = (int)($parts[3] ?? 0);
        $this->urlTemplate = !empty($templateName);

        // Startseite der Instanz: nur die Domain aufgerufen
        // --------------------
        // `instance`.`record_id` / `template_id` sagen, was dann ausgeliefert wird.
        // Ein Aufruf der Startseite wird nie auf die kanonische URL umgeleitet.
        // $templateFile: Pfad der kompilierten Datei relativ zu Cms::$templatePath,
        // "{area_id}/{name}" - vom CMS gewählte Templates (Datensatz, Bereich,
        // Startseite) stehen damit fest; ein Template aus der URL wird unten erst
        // aufgelöst und geprüft.
        $templateFile = '';
        $isStartPage = ($slug === '' && $templateName === '');
        if ($isStartPage) {
            $startRecordId = (int)($this->instance['record_id'] ?? 0);
            if ($startRecordId > 0) $slug = (string)$startRecordId;
            $startTemplateId = (int)($this->instance['template_id'] ?? 0);
            if ($startTemplateId > 0) {
                $templateName = $this->getTemplateRef($startTemplateId);
                // Wie ein Template aus der URL behandeln: die Berechtigung wird geprüft,
                // damit ein im Backend gewähltes, inzwischen ungeeignetes Template
                // nicht ungeprüft läuft
                $this->urlTemplate = ($templateName !== '');
            }
        }

        // Übersicht eines Bereichs: der Pfad ist genau der Slug-Präfix eines Bereichs
        // (/de/modelle) mit Übersichts-Template (area.overview_template_id)
        // --------------------
        // Das Template läuft ohne Datensatz wie ein vom CMS gewähltes Template (kein
        // Standalone-Flag nötig); Cms::$overviewAreaId lässt CMS:URL ohne 'id' auf
        // diese Übersicht zeigen (Sprachumschalter). Ohne Übersichts-Template bleibt
        // es bei "record not found".
        $isOverview = false;
        if ($slug !== '' && $templateName === '' && !preg_match('/^\d+$/', $slug)) {
            $overviewAreaId = $this->cms->overviewAreaIdByPath($slug);
            if ($overviewAreaId > 0) {
                $overviewTemplateId = (int)($this->container->get(InstanceCache::class)->areaInfo($overviewAreaId)['overview_template_id'] ?? 0);
                $templateFile = $this->getTemplateFile($overviewTemplateId);
                if ($templateFile !== '') {
                    $isOverview = true;
                    $this->areaId = $overviewAreaId;
                    $this->cms->overviewAreaId = $overviewAreaId;
                    $templateName = $this->templateName;
                    $slug = '';
                }
            }
        }

        // dataset
        // $cms hält die Inhalte des angeforderten Datensatzes und bleibt leer,
        // wenn kein Datensatz angefordert wurde (Aufruf "/0,{template}"). Auf einer
        // Übersichtsseite stehen dort die Angaben des Bereichs (overviewData()).
        $cms = $isOverview ? $this->overviewData($this->areaId) : [];
        // $set dient als Speicherplatz für CMS:Befehlsausgaben,
        // vgl. CMS:TEXT mit Parameter 'set'
        $set = [];
        if (!empty($slug)) {
            // Veröffentlicht? Im Frontend muss der Datensatz freigegeben und sein
            // Erscheinungsdatum erreicht sein; ein abgelaufener (offline_at) bleibt
            // erreichbar (archive, siehe Klassenkommentar). Die Vorschau des
            // Backends zeigt jeden Datensatz.
            $published = ($this->mode === self::MODE_FRONTEND)
                ? $this->cms->publishedSql(['archive' => true])
                : '';

            // Reine Ziffernfolge = record_id, alles andere ein Slug.
            // Bewusst nicht is_numeric(): das hielte auch "-42", "3.14", "1e3" und
            // " 12" für Zahlen. Genau "-42" ist aber ein gültiger Slug - Record::
            // hookOnSave() stellt numerischen Slugs ein Minus voran, damit sie sich
            // nicht mit einer record_id überschneiden.
            if (preg_match('/^\d+$/', $slug)) {
                // get record by id
                // Direkter Zugriff ohne Cms::select() - über die Id sind weder virtuelle
                // Felder noch Filterlogik nötig. Arbeitskopien (tmp) und gelöschte
                // Datensätze bleiben ausgeschlossen.
                $sql = "SELECT * FROM `record` WHERE `record_id` = ?"
                ." AND `tmp`=0 AND `deleted`=0"
                . ($published !== '' ? " AND $published" : '');
                $this->db->query($sql, 'i', [(int)$slug]);
            }
            else {
                // get record by slug
                // Der Pfad ist [präfix/]slug: beginnt er mit dem Slug-Präfix eines
                // Bereichs, wird der Rest nur dort gesucht, sonst der ganze Pfad in
                // den Bereichen ohne Präfix - in der angeforderten Sprache (ein
                // mehrsprachiges Slug-Feld hat je Sprache seinen eigenen Slug), nur in
                // den Instanzen dieses Auftritts, ohne Arbeitskopien und Gelöschte
                // (Cms::recordIdsBySlugPath()). Ohne Slug-Feld im System bleibt die
                // Liste leer. Mehr als ein Treffer ist eine Inkonsistenz der
                // Datenbank (Slugs sind je Pfad eindeutig, Record::slugPathOk()).
                $ids = $this->cms->recordIdsBySlugPath($slug, $published);
                if (count($ids) > 1) {
                    die('Error: Ambiguous path.');
                }
                $sql = "SELECT * FROM `record` WHERE `record_id` = ?"
                ." AND `tmp`=0 AND `deleted`=0";
                $this->db->query($sql, 'i', [(int)($ids[0] ?? 0)]);
            }
            if ($this->db->num_rows > 0) {
                $cms = $this->cms->fetch();
                $this->id = (int)$cms['record_id'];
                $this->areaId = (int)$cms['area_id'];
                $this->slug = (string)($cms['slug'] ?? $slug);
            }
            else {
                $this->notFound('CMS error: record not found!');
            }

            // Gehört der Datensatz zu dieser Website?
            // --------------------
            // Über den Slug filtert Cms::select() bereits mit $sqlAccess; der Zugriff
            // über die Id läuft dagegen als direkte Abfrage an select() vorbei. Die
            // Prüfung steht deshalb hier, für beide Wege gemeinsam.
            if (!$this->instanceAllowed((int)($cms['instance_id'] ?? 0))) {
                $this->notFound('CMS error: record not found!');
            }

            // Kein Template in der URL angegeben?
            if (empty($templateName)) {
                // Template explizit im Datensatz gewählt?
                $templateId = (int)$cms['template_id'];
                if (!empty($templateId)) {
                    $templateFile = $this->getTemplateFile($templateId);
                }

                // Letzte Option: Standard-Template des Bereichs ermitteln
                if (empty($templateFile)) {

                    $sql = 'SELECT `default_template_id` FROM `area` WHERE `area_id` = ? AND `deleted`=0';
                    $this->db->query($sql, 'i', [$this->areaId]);
                    if ($this->db->num_rows > 0) {
                        $templateId = (int)$this->db->fetchNum()[0];
                        if (!empty($templateId)) {
                            $templateFile = $this->getTemplateFile($templateId);
                        }
                    }
                }
            }
        }

        $this->templateName = $templateName;

        // Kanonische URL erzwingen (siehe Klassenkommentar) - nicht in der Vorschau:
        // sie läuft unter der Adresse des Backends und soll dort bleiben
        if (!$isStartPage && !$isOverview && $this->mode === self::MODE_FRONTEND) {
            $this->redirectCanonical($cms, $templateName);
        }

        if (!empty($templateName) || !empty($templateFile)) {
            // Template einbinden

            if ($this->urlTemplate) {
                // Template über die URL zugewiesen -> Zugriffserlaubnis prüfen
                // (bei 2. und 3. entfällt die Prüfung: dort hat das CMS das Template
                //  selbst ausgewählt, es kann also nicht manipuliert sein)

                // Referenz "[bereich.]name" auflösen. Cms::findTemplates() sieht nur
                // die Templates dieser Website (Cms::$sqlAccess) - ein Name, der darin
                // mehrfach vorkommt, ist ohne Bereichsangabe nicht zu gebrauchen.
                $templates = $this->cms->findTemplates($templateName);
                if (count($templates) > 1) {
                    throw new \RuntimeException("Error: template name \"$templateName\" is ambiguous.");
                }
                if (count($templates) < 1) {
                    $this->notFound('Error: Template not found.');
                }
                $templateId = (int)$templates[0]['template_id'];
                $standalone = (int)$templates[0]['standalone'];
                // Zeitgesteuerte Templates (Scheduler) laufen nie über eine Adresse
                if ((string)($templates[0]['type'] ?? '') === 'scheduler') {
                    $this->notFound('Error: Invalid template request.');
                }
                $templateInstanceId = (int)$templates[0]['instance_id'];
                $templateFile = (int)$templates[0]['area_id'] . '/' . $templates[0]['name'];

                // Gehört das Template zu dieser Website?
                // --------------------
                // Nur hier nötig: in den beiden anderen Fällen hat das CMS das Template
                // selbst gewählt (am Datensatz oder als Vorgabe des Bereichs) - und der
                // Datensatz gehört an dieser Stelle bereits nachweislich zur Instanz.
                if (!$this->instanceAllowed($templateInstanceId)) {
                    $this->notFound('Error: Invalid template request.');
                }
                if (empty($cms['record_id'])) {
                    // 1. Template ohne Datensatzinhalte
                    // --------------------
                    // Nur erlaubt, wenn das Template ausdrücklich als eigenständig
                    // markiert ist (z. B. Startseite, Suchformular, Sitemap).
                    if ((int)$standalone === 0) {
                        $this->notFound('Error: Invalid template request.');
                    }
                }
                else {
                    // 2. Template mit Datensatzinhalten
                    // --------------------
                    // Angegebenes Template ist das im Datensatz ausgewählte?
                    if ((int)$cms['template_id'] !== $templateId) {
                        // Nein, anderes.

                        // Prüfe ob das angegeben Template das ausgewählte überschreiben darf
                        $sql = "SELECT 1 FROM `rel_area_template` WHERE `template_id` = ? AND `area_id` = ? AND `override` = 1";
                        $this->db->query($sql, 'ii', [$templateId, $this->areaId]);
                        if ($this->db->num_rows < 1) {
                            // Nein, darf nicht überschreiben.

                            // Letzte Möglichkeit: das angegebene Template ist das Default-Template
                            // und das ist auch im Datensatz gewählt
                            if ((int)$cms['template_id'] === 0) {
                                // Default-Template ist gewählt. Ist das das angegebene?
                                $sql = "SELECT `default_template_id` FROM `area`"
                                ." WHERE `area_id` = ? AND `deleted`=0";
                                $this->db->query($sql, 'i', [$this->areaId]);
                                $defaultTemplateId = ($this->db->num_rows > 0) ? (int)$this->db->fetchNum()[0] : 0;
                                if ($templateId !== $defaultTemplateId) {
                                    // Nein nicht das angegebene
                                    $this->notFound('Error: Invalid template request.');
                                }
                                // else: angegebenes Template ist zugewiesenes Default-Template
                            }
                            else $this->notFound('Error: Invalid template request.');
                        }
                        // else: darf überschreiben
                    }
                    // else: ohnehin zugewiesen
                }
            }

            // Cms::$templatePath zeigt auf var/php - dort legt Template::hookOnSaved()
            // beim Speichern den kompilierten PHP-Code jedes Templates als Datei ab
            // (Unterverzeichnis je Bereich, Template::fileName()).
            $_template = $this->cms->templatePath . "/{$templateFile}.php";
            if (!file_exists($_template)) {
                $this->notFound('Error: Invalid template request.');
            }

            // Namensraum rein halten: erstes include innerhalb IIFE
            // Ohne die Kapselung lägen alle Variablen des Templates im Methodenkörper und
            // könnten die lokalen Variablen von resolveRequest() überschreiben.
            //
            // $this ist in der Closure weiterhin diese Page-Instanz. Der kompilierte
            // Template-Code greift genau darauf zu: $this->cms (Cms-Objekt) und $this->db.
            // Nicht verwechseln mit $cms - das ist das Daten-Array des Datensatzes,
            // aus dem CMS:TEXT & Co. lesen.
            //
            // FRONTEND-START
            (function() use ($_template) {

                // $cms und $set im Namensraum des Templates verfügbar machen
                global $cms, $set;
                include $_template;

            })();
            // FRONTEND-END
        }
        else {
            $this->notFound('Error: Invalid request.');
        }
    }

    /**
     * Die Website (Instance) des laufenden Requests aus der Domain ermitteln.
     *
     * Reihenfolge - der erste Treffer gewinnt (InstanceCache::resolveDomain()):
     *
     *     1. `instance`.`primary_domain`
     *     2. `rel_instance_domain` - wörtlich, oder als regulärer Ausdruck, wenn dort
     *        `regex` = 1 gesetzt ist. Die Zeilen werden in ihrer `position`
     *        durchgegangen, der Redakteur bestimmt also die Reihenfolge.
     *     3. die Standard-Instance - sie fängt auf, was nirgends eingetragen ist
     *
     * Alle Angaben kommen aus den Cache-Dateien var/cache/instances.php und
     * instance-{id}.php; fehlen sie, legt InstanceCache sie sofort neu an.
     *
     * Danach steht fest, welche Inhalte dieser Request überhaupt sehen darf: die
     * eigene Instanz und die in `rel_instance_instance` freigegebenen. Der Ausdruck
     * dafür geht an Cms::setInstanceAccess() und wirkt ab da in jedem select().
     */
    private function resolveInstance(): void
    {
        if ($this->mode === self::MODE_BACKEND && $this->previewInstanceId > 0) {
            // Vorschau per Token (FrontendHandover): Instanz aus dem Token, und sie
            // muss zur Domain passen - ein Token gilt nur unter der eigenen Website
            $this->instanceId = $this->previewInstanceId;
            if ($this->instanceCache->resolveDomain($this->requestHost()) !== $this->instanceId) {
                if (!headers_sent()) http_response_code(403);
                die('CMS error: access denied!');
            }
        }
        elseif ($this->mode === self::MODE_BACKEND) {
            // Vorschau: die Instanz kommt als Parameter, die Domain spielt keine
            // Rolle (das Backend läuft meist unter einer anderen als die Website).
            // Eine unbekannte Id endet unten bei "instance not found". Die Gruppe
            // des Admins muss Zugriff auf die Instanz haben (12-group-rights.md).
            $this->instanceId = (int)($_REQUEST['instance'] ?? 0);
            if (!$this->container->get(Access::class)->instance($this->instanceId)) {
                die('CMS error: access denied!');
            }
        }
        else {
            $this->instanceId = $this->instanceCache->resolveDomain($this->requestHost());
            if ($this->instanceId < 1) {
                $this->instanceId = Instance::DEFAULT_ID;
            }
        }

        // Angaben der Instanz laden (Sprachen, Protokoll, Startseite, canonical)
        $this->instance = ($this->instanceId > 0)
            ? ($this->instanceCache->instance($this->instanceId) ?? [])
            : [];
        if (empty($this->instance)) {
            // Frontend: ohne Standard-Instance ist die Installation nicht benutzbar -
            // sie ist Teil des Grunddatenbestands und lässt sich im Backend nicht
            // löschen. Vorschau: Parameter fehlt oder nennt eine unbekannte Instanz.
            die('CMS error: instance not found!');
        }

        // Lesbare Instanzen dazunehmen
        $this->instanceIds = $this->readableInstanceIds($this->instanceId);

        // Ab hier filtert jedes Cms::select() auf `area`, `record` und `template`
        $this->cms->setInstanceAccess($this->instanceIds);
    }

    /**
     * Host ohne Port, kleingeschrieben - so stehen die Domains auch im Cache
     * (Instance::hookOnSave() schreibt sie klein)
     */
    private function requestHost(): string
    {
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if (($colon = strpos($host, ':')) !== false) $host = substr($host, 0, $colon);
        return mb_strtolower(trim($host));
    }

    /**
     * Eine Instanz plus die in `readable_instances` freigegebenen - die Instanzen,
     * deren Inhalte ein Request dieser Instanz sehen darf.
     *
     * @return int[]  die eigene zuerst
     */
    private function readableInstanceIds(int $instanceId): array
    {
        $ids = [$instanceId];
        $instance = $this->instanceCache->instance($instanceId) ?? [];
        foreach ((array)($instance['readable_instances'] ?? []) as $readableId) {
            $readableId = (int)$readableId;
            if ($readableId > 0 && !in_array($readableId, $ids, true)) {
                $ids[] = $readableId;
            }
        }
        return $ids;
    }

    /**
     * Ein Template im BACKEND für einen Datensatz ausführen (Listen-Template des
     * Bereichs, `area`.`list_template_id` - siehe Klassenkommentar).
     *
     * Der Datensatz steht im Template als $cms bereit (wie in der Ergebnisschleife
     * von CMS:SELECT), $set ist leer. Die Instanzbeschränkung gilt wie im Frontend
     * für die Instanz des Datensatzes - ein CMS:SELECT im Template sieht also nur,
     * was auch die Website sähe -, danach wird der vorherige Zustand des Backends
     * wiederhergestellt. Läuft das Template in einen Fehler (fehlendes Template,
     * mehrdeutiger Name, PHP-Fehler), steht statt der Ausgabe die Meldung im
     * Ergebnis; der Aufrufer (eine Liste) läuft weiter.
     *
     * Der Aufrufer klammert den Aufruf mit Database::pushResult()/popResult(),
     * wenn er selbst gerade ein Ergebnis durchläuft - das Template darf eigene
     * Abfragen absetzen.
     *
     * @param int   $templateId  `template`.`template_id`
     * @param array $record      Datensatzinhalte (Cms::fetch())
     * @return string            Ausgabe des Templates, '' ohne Template/Datei
     */
    public function renderRecord(int $templateId, array $record): string
    {
        if ($templateId < 1) return '';
        $this->db->pushResult();
        $templateFile = $this->getTemplateFile($templateId);
        $this->db->popResult();
        if ($templateFile === '') return '';
        $_template = $this->cms->templatePath . "/{$templateFile}.php";
        if (!is_file($_template)) return '';

        // Umgebung des Frontends: Instanzbeschränkung, $cms/$set
        $previousAccess = $this->cms->accessInstanceIds();
        $instanceId = (int)($record['instance_id'] ?? 0);
        if ($instanceId > 0) {
            $this->cms->setInstanceAccess($this->readableInstanceIds($instanceId));
        }
        $previousCms = $GLOBALS['cms'] ?? null;
        $previousSet = $GLOBALS['set'] ?? null;
        $GLOBALS['cms'] = $record;
        $GLOBALS['set'] = [];

        ob_start();
        try {
            (function() use ($_template) {
                global $cms, $set;
                include $_template;
            })();
        }
        catch (\Throwable $e) {
            echo '<span class="text-danger">' . htmlspecialchars($e->getMessage()) . '</span>';
        }
        finally {
            $html = (string)ob_get_clean();
            $GLOBALS['cms'] = $previousCms;
            $GLOBALS['set'] = $previousSet;
            $this->cms->setInstanceAccess($previousAccess);
        }
        return $html;
    }

    /**
     * Zeitgesteuertes Template ausführen (Modul Scheduler, Aufgabentyp template) -
     * auf der Kommandozeile, ohne Adresse und ohne Besucher.
     *
     * Die Umgebung ist die des Frontends: Instanz des Templates samt lesbaren
     * Instanzen (Cms::$sqlAccess), Inhaltssprache mit Zeitzone, Snippet-Sprache und
     * Locale wie bei einem Seitenaufruf, kein Datensatz ($cms leer). $set bringt das
     * Zeitfenster mit - in der Zeitzone der Sprache, wie Filterwerte sie erwarten
     * (filter[online_at] > "{$set['last_run']}"), und zusätzlich in UTC:
     *
     *     last_run, this_run          Y-m-d H:i:s, Zeitzone der Inhaltssprache
     *     last_run_utc, this_run_utc  dasselbe in UTC
     *     scheduler_id                die Aufgabe
     *
     * Nur Templates mit der Verwendung 'scheduler' (template.type); Page lehnt sie
     * umgekehrt als Adresse ab.
     *
     * @param int $templateId  `template`.`template_id`
     * @param int $languageId  Inhaltssprache; 0 oder unbekannt = Hauptsprache der Instanz
     * @param int $lastRun     Beginn des Zeitfensters (Unix-Zeit, ausschließlich)
     * @param int $thisRun     Ende des Zeitfensters (Unix-Zeit, einschließlich)
     * @param int $taskId      scheduler_id (für $set)
     * @return string          Ausgabe des Templates
     * @throws \RuntimeException  kein zeitgesteuertes Template, Datei oder Instanz fehlt
     */
    public function renderScheduled(int $templateId, int $languageId, int $lastRun, int $thisRun, int $taskId = 0): string
    {
        $this->db->query("SELECT `instance_id`, `type` FROM `template` WHERE `template_id` = ?", 'i', [$templateId]);
        $row = $this->db->fetch();
        if (!$row || (string)$row['type'] !== 'scheduler') {
            throw new \RuntimeException("template $templateId is not a scheduler template");
        }
        $templateFile = $this->getTemplateFile($templateId);
        $_template = $this->cms->templatePath . "/{$templateFile}.php";
        if ($templateFile === '' || !is_file($_template)) {
            throw new \RuntimeException("template file for $templateId not found");
        }

        // Instanz und Sprache wie bei einem Seitenaufruf
        $this->mode = self::MODE_FRONTEND;
        $this->instanceId = (int)$row['instance_id'];
        $this->instance = $this->instanceCache->instance($this->instanceId) ?? [];
        if (empty($this->instance)) throw new \RuntimeException("instance {$this->instanceId} not found");
        $this->instanceIds = $this->readableInstanceIds($this->instanceId);
        $this->cms->setInstanceAccess($this->instanceIds);
        $this->protocol = (string)($this->instance['protocol'] ?? '') ?: 'https';
        $languages = $this->instanceCache->languagesOf($this->instanceIds);
        $this->resolveLanguage((string)($languages[$languageId]['code'] ?? ''));

        // Zeitfenster in der Zeitzone der Inhaltssprache (Cms::$userTZ) und in UTC
        $local = new \DateTimeZone($this->cms->userTZ ?: date_default_timezone_get());
        $utc = new \DateTimeZone('UTC');
        $format = fn(int $time, \DateTimeZone $zone) => (new \DateTimeImmutable('@' . $time))->setTimezone($zone)->format('Y-m-d H:i:s');

        $GLOBALS['cms'] = [];
        $GLOBALS['set'] = [
            'last_run' => $format($lastRun, $local),
            'this_run' => $format($thisRun, $local),
            'last_run_utc' => $format($lastRun, $utc),
            'this_run_utc' => $format($thisRun, $utc),
            'scheduler_id' => $taskId,
        ];

        $level = ob_get_level();
        ob_start();
        try {
            (function() use ($_template) {
                global $cms, $set;
                include $_template;
            })();
            return (string)ob_get_clean();
        }
        finally {
            while (ob_get_level() > $level) ob_end_clean();
        }
    }

    /**
     * Ein kompiliertes Template in den laufenden Request einbinden und seine Ausgabe
     * als String zurückgeben, statt sie auszugeben - für Befehle, die aus einem
     * Template einen Text bauen (CMS:EMAIL bodytemplate/subjecttemplate).
     *
     * Anders als renderRecord() bleibt die Umgebung, wie sie ist: $cms ist der
     * laufende Datensatz, $set und die Instanzbeschränkung die der Seite. Der
     * kompilierte Code braucht $this = Page ($this->cms, $this->email ...), deshalb
     * steht die Methode hier und nicht beim Aufrufer. Lokale Variablen des aufrufenden
     * Templates sieht das eingebundene nicht - nur $cms, $set und die Superglobals.
     *
     * Ausnahmen gehen an den Aufrufer weiter; die bis dahin gepufferte Ausgabe wird
     * verworfen.
     *
     * @param string $templateFile  "{area_id}/{name}" aus Cms::templateFile()
     * @return string               Ausgabe des Templates
     * @throws \RuntimeException    Datei fehlt oder Einbindetiefe überschritten
     */
    public function renderTemplateFile(string $templateFile): string
    {
        $_template = $this->cms->templatePath . "/{$templateFile}.php";
        if ($templateFile === '' || !is_file($_template)) {
            throw new \RuntimeException("template file \"{$templateFile}\" not found.");
        }
        if ($this->cms->includeDepth > (int)$this->container->get(Config::class)->get('admin.max_include_depth')) {
            throw new \RuntimeException('maximum inclusion depth exceeded.');
        }

        $level = ob_get_level();
        ob_start();
        $this->cms->includeDepth++;
        try {
            (function() use ($_template) {
                global $cms, $set;
                include $_template;
            })();
            return (string)ob_get_clean();
        }
        finally {
            $this->cms->includeDepth--;
            // Bei einer Ausnahme steht der Puffer noch offen (auch einer, den das
            // Template selbst geöffnet und nicht geschlossen hat)
            while (ob_get_level() > $level) ob_end_clean();
        }
    }

    /**
     * Die Inhaltssprache des laufenden Requests ermitteln (siehe Klassenkommentar).
     *
     * @param string $langCode  Sprachkürzel aus der URL, '' wenn keins angegeben
     */
    private function resolveLanguage(string $langCode): void
    {
        // Sprachen aller zusammengeschalteten Instanzen: die eigene zuerst (ihre
        // Hauptsprache an erster Stelle), dann die der lesbaren Instanzen. Jede davon
        // ist eine gültige Sprachversion dieses Auftritts - auch eine, die nur eine
        // lesbare Instanz führt. Alles aus dem Dateicache, keine Datenbankabfrage.
        $this->languages = $this->instanceCache->languagesOf($this->instanceIds);
        $primaryId = (int)($this->instance['primary_language_id'] ?? 0);

        if ($langCode !== '') {
            // Kürzel aus der URL - muss eine Sprache dieses Auftritts sein
            $language = $this->instanceCache->findByCode($this->languages, $langCode);
            if ($language === null) {
                $this->notFound('CMS error: language not found!');
            }
            $this->languageId = (int)$language['language_id'];
        }
        elseif (count($this->languages) > 1) {
            // Ohne Kürzel: die dem Browser liebste Sprache, die der Auftritt führt
            $this->languageId = $this->instanceCache->negotiate(
                $this->languages,
                $primaryId,
                (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')
            );
        }
        else {
            $this->languageId = $primaryId;
        }
        $this->langCode = (string)($this->languages[$this->languageId]['code'] ?? '');

        // Sprache je lesbarer Instanz: die angeforderte, wenn sie dort geführt wird,
        // sonst deren Hauptsprache
        $languageMap = [];
        foreach ($this->instanceIds as $instanceId) {
            $ids = $this->instanceCache->languageIds($instanceId);
            $languageMap[$instanceId] = in_array($this->languageId, $ids, true)
                ? $this->languageId
                : ($ids[0] ?? $this->languageId);
        }

        $this->cms->setLanguage($this->languageId, $this->langCode, $languageMap, $this->instance, $this->languages);
    }

    /**
     * Pfad ist nur ein Sprachkürzel ("/en", "/de-at") -> 301 auf die Startseite dieser
     * Sprache (siehe Klassenkommentar). Nur im Frontend und nur für GET/HEAD; sonst
     * und wenn der Pfad kein Kürzel des Auftritts ist, kehrt die Methode zurück und
     * der Pfad wird wie jeder andere als Slug gesucht.
     *
     * @param string $request  Pfad aus der URL (request), ohne Sprachsegment
     */
    private function redirectBareLanguage(string $request): void
    {
        if ($this->mode !== self::MODE_FRONTEND) return;
        // Dasselbe Muster wie die Rewrite-Regel für das Sprachsegment
        if (!preg_match('/^[a-zA-Z]{2}(?:[-_][a-zA-Z]{2})?$/', $request)) return;
        if (PHP_SAPI === 'cli' || headers_sent()) return;
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD') return;

        $language = $this->instanceCache->findByCode($this->languages, $request);
        if ($language === null) return;

        if (count($this->languages) > 1) {
            $path = '/' . $language['code'] . '/';
        }
        else {
            // Nur eine Sprache: ein veröffentlichter Datensatz mit diesem Slug geht vor
            $published = $this->cms->publishedSql(['archive' => true]);
            if ($this->cms->recordIdsBySlugPath($request, $published) !== []) return;
            $path = '/';
        }

        $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $pos = strpos($requestUri, '?');
        $query = ($pos !== false) ? substr($requestUri, $pos) : '';

        header('HTTP/1.1 301 Moved Permanently');
        header('Location: ' . $path . $query);
        exit;
    }

    /**
     * Anfrage beenden: unter dieser Adresse gibt es nichts (404, siehe Klassenkommentar).
     */
    private function notFound(string $message): never
    {
        if (!headers_sent()) http_response_code(404);
        die($message);
    }

    /**
     * Bei canonical = 1: die eine gültige URL ermitteln und bei Abweichung mit 301
     * dorthin umleiten (siehe Klassenkommentar).
     *
     * Verglichen werden Protokoll, Host und Pfad; die Query-Parameter des Aufrufs
     * bleiben erhalten und wandern mit.
     *
     * @param array  $record        geladener Datensatz ([] ohne Datensatz)
     * @param string $templateName  ermitteltes Template
     */
    private function redirectCanonical(array $record, string $templateName): void
    {
        if (empty($this->instance['canonical'])) return;
        if (PHP_SAPI === 'cli' || headers_sent()) return;
        // Nur lesende Aufrufe umleiten - ein 301 auf einen POST verlöre die Eingaben
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD') return;

        // Template nur, wenn es aus der URL kam - das vom CMS gewählte Template ist
        // der Normalfall und gehört nicht in die Adresse
        $urlTemplate = $this->urlTemplate ? $templateName : '';
        $path = $this->cms->recordUrl($record, '', $urlTemplate);
        // Hash-Parameter bleiben erhalten
        if (!empty($this->hash1) || !empty($this->hash2)) {
            if ($urlTemplate === '') $path .= ',';
            $path .= ',' . (int)$this->hash1;
            if (!empty($this->hash2)) $path .= ',' . (int)$this->hash2;
        }

        $protocol = (string)($this->instance['protocol'] ?? '') ?: $this->protocol;
        $host = (string)($this->instance['primary_domain'] ?? '');
        if ($host === '') $host = mb_strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $this->canonicalUrl = $protocol . '://' . $host . $path;

        // Aufgerufene Adresse: Pfad ohne Query-String, Host ohne Port
        $requestUri  = (string)($_SERVER['REQUEST_URI'] ?? '');
        $query       = '';
        if (($pos = strpos($requestUri, '?')) !== false) {
            $query      = substr($requestUri, $pos);
            $requestUri = substr($requestUri, 0, $pos);
        }
        $requestHost = mb_strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        if (($colon = strpos($requestHost, ':')) !== false) $requestHost = substr($requestHost, 0, $colon);
        $requestUrl = $this->protocol . '://' . $requestHost . rawurldecode($requestUri);

        if ($requestUrl === $this->canonicalUrl) return;

        header('HTTP/1.1 301 Moved Permanently');
        header('Location: ' . $this->canonicalUrl . $query);
        exit;
    }

    /**
     * Darf dieser Request Inhalte der angegebenen Instanz sehen?
     *
     * @param int $instanceId  `instance_id` des Datensatzes bzw. Templates
     */
    private function instanceAllowed(int $instanceId): bool
    {
        return in_array($instanceId, $this->instanceIds, true);
    }

    /**
     * Protokoll des laufenden Requests ermitteln.
     *
     * Wertet nur die direkten Server-Variablen aus, bewusst NICHT X-Forwarded-Proto -
     * das wäre ohne bekannten Reverse Proxy fälschbar.
     *
     * @return string 'http' oder 'https'
     */
    private function getProtocol() {
        $proto = 'http';
        if (!empty($_SERVER['HTTPS'])) {
            // IIS setzt 'off' statt die Variable wegzulassen -> Wert prüfen, nicht Existenz
            $value = mb_strtolower((string) $_SERVER['HTTPS'], 'utf-8');
            if (($value == 'on') || ($value == '1')) $proto = 'https';
        }
        elseif (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
            $proto = 'https';
        }
        return $proto;
    }

    /**
     * $cms einer Übersichtsseite (Präfix-Adresse, area.overview_template_id): statt
     * eines Datensatzes die Angaben des Bereichs. record_id bleibt 0, damit Befehle
     * wie CMS:URL weiterhin "kein Datensatz" erkennen; area_id erlaubt unmittelbar
     * (CMS:SELECT liste filter[area]="{$cms['area_id']}") bzw. (CMS:TEXT area_label).
     *
     * @return array  record_id (0), area_id, area (Name), area_label (Snippet in der
     *                Inhaltssprache aufgelöst), area_type, area_icon, instance_id,
     *                parent_id (Elternbereich), language_id, slug_prefix (in der
     *                laufenden Sprache), overview (true)
     */
    private function overviewData(int $areaId): array
    {
        $this->db->query(
            "SELECT `area_id`, `instance_id`, `type`, `name`, `label`, `icon`, `parent_id` FROM `area` WHERE `area_id` = ? AND `deleted`=0",
            'i',
            [$areaId]
        );
        $row = $this->db->fetch();
        if ($row === null) return [];
        $instanceId = (int)$row['instance_id'];
        $languageId = $this->cms->recordLanguageId($instanceId);
        return [
            'record_id'   => 0,
            'area_id'     => (int)$row['area_id'],
            'area'        => (string)$row['name'],
            'area_label'  => $this->cms->textOrSnippet((string)$row['label']),
            'area_type'   => (string)$row['type'],
            'area_icon'   => (string)$row['icon'],
            'instance_id' => $instanceId,
            'parent_id'   => (int)($row['parent_id'] ?? 0),
            'language_id' => $languageId,
            'slug_prefix' => $this->container->get(InstanceCache::class)->slugPrefix($areaId, $languageId),
            'overview'    => true,
        ];
    }

    /**
     * Pfad der kompilierten Datei zu einer Template-Id: "{area_id}/{name}"
     * (relativ zu Cms::$templatePath, ohne ".php" - siehe Template::fileName()).
     *
     * @param int $templateId
     * @return string  '' wenn es das Template nicht (mehr) gibt. Der Aufrufer
     *                 behandelt '' wie "kein Template gewählt" und geht zur nächsten
     *                 Stufe der Template-Ermittlung über.
     */
    private function getTemplateFile(int $templateId): string
    {
        $this->db->query("SELECT `area_id`, `name` FROM `template` WHERE `template_id` = ?", 'i', [$templateId]);
        if ($this->db->num_rows > 0) {
            [$areaId, $name] = $this->db->fetchNum();
            $this->templateName = $name; // aber $his->urlTemplate bleibt false
            return (int)$areaId . '/' . $name;
        }
        return ''; // ignore
    }

    /**
     * Eindeutige Referenz "bereichsname.templatename" zu einer Template-Id - für
     * das Startseiten-Template, das wie ein Template aus der URL geprüft wird.
     *
     * @return string  '' wenn es das Template nicht (mehr) gibt
     */
    private function getTemplateRef(int $templateId): string
    {
        $this->db->query(
            "SELECT `a`.`name`, `t`.`name` FROM `template` `t`"
            . " INNER JOIN `area` `a` ON `a`.`area_id` = `t`.`area_id`"
            . " WHERE `t`.`template_id` = ?",
            'i', [$templateId]
        );
        if ($this->db->num_rows > 0) {
            [$areaName, $name] = $this->db->fetchNum();
            return $areaName . '.' . $name;
        }
        return '';
    }
}
