<?php
/**
 * Resolve a frontend request: URL -> record -> template.
 *
 * Callers are Core/public_master/index.php (frontend, resolveRequest()) - the rewrite
 * rules in public/.htaccess (docroot) redirect all frontend calls there - and
 * Core/public_master/backend/preview.php (preview from the backend, resolvePreview()). The
 * class does not render itself; at the end it includes the compiled template from
 * var/php/ - which already contains finished PHP (cf. Modules/Template/
 * TemplateParserTrait).
 *
 * TWO MODES: FRONTEND AND BACKEND (PREVIEW)
 * --------------------
 * Both run through the same resolution (resolve()); they differ in
 * three points:
 *
 *                       FRONTEND (resolveRequest)     BACKEND (resolvePreview)
 *     access            everyone                      only with an admin session
 *                                                     (Session::load(), otherwise abort)
 *                                                     or preview token (see below)
 *     instance          from the domain               parameter `instance` - the
 *                                                     preview runs under the domain
 *                                                     of the backend, every one is allowed
 *     record in the     must be PUBLISHED:            may be anything: post-dated,
 *     URL               status_id = 0 and             expired, every status
 *                       online_at reached, otherwise
 *                       "record not found"
 *     canonical URL     301 on deviation              no redirect - the address
 *                                                     remains that of the backend call
 *     caching           -                             anti-caching headers, so that the
 *                                                     preview always shows the current
 *                                                     state
 *
 * What the templates see does not change with the mode: CMS:SELECT returns only
 * published records in both cases (Cms::cmsSelect() ->
 * Cms::publishedSql()). The preview thus shows an unpublished record
 * as it would look after release - including the lists that stand around it. For the
 * record in the URL, `offline_at` deliberately does NOT apply in the frontend:
 * an expired record remains reachable under its address (linked
 * articles, search engines), it only no longer appears in any list.
 *
 * With a fixed backend domain (admin.backend_domain) preview.php redirects to the
 * domain of the website: /index.php?c9preview={token} (FrontendHandover). resolveRequest()
 * checks the token and switches to preview mode; instance, path and language come
 * from the token, the domain must belong to the instance (developer docs, chapter 13 (Security: authentication)).
 *
 * The preview address is built by Backend::buttonAddPreview():
 *     /backend/preview.php?instance={id}&request={slug|id}[,{template}][&lang={code}]
 * `request` and `lang` have the same format as in the frontend (after the rewrite).
 *
 * WHICH WEBSITE? (INSTANCE)
 * --------------------
 * Every call belongs to exactly one instance - in the frontend determined from the domain
 * of the request (resolveInstance(), via the file cache Camindo\Core\InstanceCache -
 * the database is no longer queried for this):
 *
 *     1. `instance`.`primary_domain` matches
 *     2. a row in `rel_instance_domain` matches (literal, or as a
 *        regular expression if `regex` = 1 is set there)
 *     3. otherwise the default instance (Instance::DEFAULT_ID) - it catches everything
 *        that is not registered anywhere
 *
 * This produces Cms::$sqlAccess: the instance itself plus those released in
 * `rel_instance_instance`. Cms::select() appends the expression to every
 * query on `area`, `record` and `template` - contents of foreign instances are thus
 * not reachable via the frontend. Record and template are additionally
 * checked explicitly, because both are also fetched via direct queries.
 *
 * WHICH LANGUAGE? (resolveLanguage())
 * --------------------
 * The languages of a site are those of its own instance (primary language first)
 * plus those of the readable instances (`readable_instances`) - all from the file cache
 * (InstanceCache::languagesOf()), without a database query. Page holds this list
 * ($languages) and passes it on to Cms (language switcher, CMS:URL).
 *
 *     site with ONE language          always this one; a language code in the URL is
 *                                     only accepted if it means this language
 *     site with SEVERAL languages     the code from the URL (/{lang}/...); if it is missing,
 *                                     the Accept-Language of the browser decides
 *                                     (de-AT matches de_DE, see
 *                                     InstanceCache::negotiate()), otherwise the
 *                                     primary language of the own instance
 *
 * The determined language goes to Cms::setLanguage(): from then on select() returns the
 * data_* rows and the `record_cache` of this language. For every instance that does not
 * carry the language, its primary language applies (Cms::$languageMap) - this can also
 * be its own if the language is carried only by a readable instance.
 *
 * CANONICAL URL
 * --------------------
 * If the instance has canonical = 1, there is exactly ONE valid URL for every record:
 * primary domain + protocol of the instance + Cms::recordUrl() (language code, as soon as
 * the instance carries several languages; slug instead of id, as soon as there is one). If the
 * call deviates from it - e.g. the id "/42" printed in a print text or a call
 * without language code - Page replies with 301 to the canonical URL. Search engines
 * thus store only one address per content. The template in the URL is kept
 * (different presentations are different pages), as are the query parameters.
 * The call of the bare domain (start page of the instance) is not redirected.
 *
 * URL FORMAT
 * --------------------
 *     /{slug|id}                    record with its assigned template
 *                                   (pure digit sequence = id, otherwise slug)
 *     /{prefix}/{slug}              record of an area with slug prefix
 *                                   (Cms::recordIdsBySlugPath())
 *     /{slug|id},{template}         record with explicitly specified template
 *     /0,{template}                 template without record (must be `standalone`)
 *     /{lang}/{rest}                multilingual; the rewrite rule splits off {lang}
 *                                   and passes it as its own parameter
 *     /                             start page of the instance (`instance`.`record_id` /
 *                                   `template_id`)
 *
 * After the rewrite exactly two parameters arrive: `request` (everything after the language part)
 * and optionally `lang`. `request` is split at commas:
 *
 *     {slug|id} , {template} , {hash1} , {hash2}
 *
 * TEMPLATE DETERMINATION (first match wins)
 * --------------------
 *     1. template from the URL         -> permission is checked, see below
 *     2. record.template_id            -> chosen by the editor on the record
 *     3. area.default_template_id      -> default of the content area
 * If no template name remains after that, the request ends with an error message.
 *
 * PERMISSION FOR URL TEMPLATES
 * --------------------
 * A template forced via the URL must not be arbitrary, otherwise every
 * record could be rendered through every template. It is only allowed if
 *   - no record is involved AND template.standalone = 1, or
 *   - it is the template already chosen on the record, or
 *   - rel_area_template.override = 1 releases it for the area, or
 *   - the record has no template of its own and the given one is the default template
 *     of the area.
 *
 * ERROR HANDLING
 * --------------------
 * All error paths end with die() and a plain English message. What does not exist
 * under the address replies with 404 (notFound()): unknown record or one from a foreign
 * instance, unknown language code, unknown or not released template from the URL,
 * missing template file. A URL template without permission deliberately also gets 404 and not 403 - from the outside it
 * should not be distinguishable whether the template exists. Missing access to the preview and
 * expired tokens end with 403. There is no designed error page yet.
 *
 * ONLY THE LANGUAGE CODE (/en WITHOUT A SLASH)
 * --------------------
 * The rewrite rule for the language segment requires the slash after it, so "/en"
 * arrives as request=en. If the whole path is a language code of the site
 * (InstanceCache::findByCode(), the same rule as for /en/), redirectBareLanguage()
 * redirects with 301 to the start page of this language - with the
 * full code of the language ("/en_US/", with only one language "/"). With only one
 * language a record with exactly this slug takes precedence - there /en is its regular
 * address. With several languages each record address carries a language segment
 * anyway, the language wins.
 *
 * TEMPLATES IN THE BACKEND (renderRecord())
 * --------------------
 * The same class also runs a template outside a frontend request:
 * the record list (RecordBackendList) and the detailed lookup preview
 * (RecordBackendEdit) format each record with the list template of its
 * area (`area`.`list_template_id`). The compiled code accesses $this->cms,
 * $this->db and the lazily loadable services - exactly the environment that Page provides;
 * the output is buffered and returned as a string.
 */
namespace Camindo\Core;

use Camindo\Core\Modules\Record\RecordUser;
use Camindo\Core\Modules\Instance\Instance;

/**
 * @property-read Pagination $pagination  Lazily loaded, see $lazyServices
 * @property-read RecordUser $recordUser  Lazily loaded, see $lazyServices
 * @property-read Email $email            Lazily loaded, see $lazyServices
 * @property-read CmsZip $zip             Lazily loaded, see $lazyServices (CMS:ZIP)
 *
 * In addition come the services of the active add-on modules (settings.php['services']),
 * also lazily loaded - see constructor.
 */
class Page {

    // Lazily loadable services - filled in the constructor, see there.
    // --------------------
    use LazyServicesTrait;

    // Mode (see class comment)
    public const MODE_FRONTEND = 'frontend';
    public const MODE_BACKEND  = 'backend';
    private string $mode = self::MODE_FRONTEND;
    // Preview by token under the domain of the website (FrontendHandover) - instance
    // from the token, 0 = none
    private int $previewInstanceId = 0;

    // Determined properties from the request, available in the templates
    public string $protocol = 'https'; // protocol
    public int $instanceId = 0;        // instance_id from the domain, see resolveInstance()
    public array $instance = [];       // instance data, from InstanceCache
    public array $instanceIds = [];    // own instance + `readable_instances`
    public int $languageId = 0;        // content language of the request, see resolveLanguage()
    public string $langCode = '';      // its code
    public array $languages = [];      // languages of all interconnected instances
                                        // (own + readable), language_id => row
    public int $id = 0;                // record id that is included
    public string $slug = '';          // record slug
    public int $areaId = 0;            // record area_id
    public string $templateName = '';  // template name that is included
    public bool $urlTemplate = false;  // flag: true if the template is explicitly given in the URL
    public ?int $hash1 = null;         // additional parameter 1
    public ?int $hash2 = null;         // additional parameter 2

    // unique URL, only available if canonical=1 is set in the instance
    // (see redirectCanonical())
    public string $canonicalUrl = '';

    public function __construct(
        private Cms $cms,
        private Database $db,
        private Session $session,
        private InstanceCache $instanceCache,
        protected ContainerInterface $container
    )
    {
        // Lazily loadable services
        // --------------------
        // $this->cms is needed by every template, these three only if the matching
        // command occurs in it. The compiled template code accesses them under exactly
        // these names - they stand so in the column 'method' of the
        // command definitions ('pagination.cmsJumpList' -> $this->pagination).
        //
        // (The assignment is here and not as a start value on the property, because PHP
        // does not allow overriding a trait property with a different start value
        // within the same class.)
        //
        // @see Camindo\Core\LazyServicesTrait
        // @see Camindo\Core\Modules\Template\commands.php
        $this->lazyServices = [
            'pagination' => Pagination::class,   // (CMS:JUMP_LIST|JUMP_PREV|JUMP_NEXT)
            'recordUser' => RecordUser::class,   // (CMS:USER_*)
            'email'      => Email::class,        // (CMS:EMAIL)
            'zip'        => CmsZip::class,       // (CMS:ZIP) - rare, hence loaded lazily
        ];

        // Services of the add-on modules: settings.php['services'] = ['pdf' => CmsPdf::class]
        // makes $this->pdf available in the template code - for the commands that the
        // module defines in its commands.php with 'method' => 'pdf.cmsPdfPage'
        // (TemplateParserTrait::loadCommands()). The core names remain reserved.
        foreach ($this->cms->modules as $module) {
            foreach ((array)($module['services'] ?? []) as $name => $class) {
                if (is_string($name) && is_string($class) && !isset($this->lazyServices[$name])) {
                    $this->lazyServices[$name] = $class;
                }
            }
        }
    }

    /**
     * Resolve a frontend request (Core/public_master/index.php) - frontend mode, see
     * class comment.
     *
     * Splits the URL, loads the record, determines and checks the template and includes it.
     * Returns in the success case, after the page has been output; in all
     * error cases the request is ended with die().
     */
    public function resolveRequest(): void
    {
        $this->mode = self::MODE_FRONTEND;

        // Fixed backend domain: there is no website there, only the backend
        // (unless the domain does belong to an instance after all)
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

        // Preview from the backend under the domain of the website (FrontendHandover)
        $token = (string)($_GET[FrontendHandover::PARAM] ?? '');
        if ($token !== '') {
            $this->startTokenPreview($token);
        }
        $this->resolve();
    }

    /**
     * Check the preview token and switch to preview mode: instance, path and
     * language come from the token, not from the URL. An invalid or
     * expired token (backend session over) ends with 403.
     * Also sets the cookie of frontend editing under this domain according to
     * the admin's setting.
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
            // The token is in the URL - do not pass it on to foreign pages
            header('Referrer-Policy: same-origin');
        }
        $handover->setFedCookie($preview['admin_id'], $preview['fed']);
    }

    /**
     * Redeem a FED link: check the token, the domain must belong to the instance, set the cookie
     * and redirect to the start page (the token should not stay in the address
     * bar). Invalid: 403.
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
     * One step of the synchronization with all websites (FrontendHandover::fedSyncData()):
     * The first number in c9hops must be this domain. Set or delete the cookie,
     * continue to the next website, after the last one back to the backend.
     *
     * With c9probe=1 only check: 1x1 GIF if token and domain match, otherwise 404 -
     * cApp.ui.fedSync() uses this to detect domains that do not (or not reachably) belong to
     * this installation, and leaves them out of the chain.
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

    /** Anti-caching and noindex for the preview */
    private function sendPreviewHeaders(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Robots-Tag: noindex, nofollow');
    }

    /**
     * Preview from the backend (Core/public_master/backend/preview.php) - backend mode,
     * see class comment. Request: instance, request, lang.
     *
     * The access right is currently solely the running admin session; a finer
     * check (may this admin see this instance?) belongs to the still open
     * rights management of the backend. Session::load() also sets the backend language
     * and time zone of the admin - resolveLanguage() immediately overrides both
     * with those of the content language, as in the frontend.
     */
    public function resolvePreview(): void
    {
        if (!$this->session->load()) {
            die('CMS error: access denied!');
        }
        $this->mode = self::MODE_BACKEND;

        // Backend under a foreign domain: continue to the domain of the website, with a
        // token instead of the session cookie (FrontendHandover::previewUrl()). The right to the instance is
        // checked here - afterwards only the token counts.
        $instanceId = (int)($_REQUEST['instance'] ?? 0);
        if (!$this->container->get(Access::class)->instance($instanceId)) {
            die('CMS error: access denied!');
        }
        $url = $this->container->get(FrontendHandover::class)->previewUrl(
            $instanceId, (string)($_REQUEST['request'] ?? ''), (string)($_REQUEST['lang'] ?? '')
        );

        // The preview must not get stuck anywhere - neither in the browser nor in
        // a proxy: the editor wants to see the new state after every save.
        // And it should not end up in a search index in case the backend is
        // publicly reachable.
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
     * Is this the preview from the backend? (for templates that want to behave
     * differently in the preview - e.g. show a notice bar)
     */
    public function isPreview(): bool
    {
        return $this->mode === self::MODE_BACKEND;
    }

    /**
     * The common resolution of both modes: URL -> record -> template -> include.
     */
    private function resolve(): void
    {
        global $cms;
        global $set;

        $this->protocol = $this->getProtocol();

        // The preview shows the working state - nothing from the output cache of
        // CMS:INCLUDE, and the working state does not go into it either
        $this->cms->includeCacheOff = $this->isPreview();

        // Which website is meant? Must be fixed BEFORE every content query -
        // it sets Cms::$sqlAccess, and select() depends on it.
        $this->resolveInstance();

        // Script policy of the instance (switchable, SecurityHeaders::sendFrontendCsp())
        // - before any output; CMS:CSP in the template can still change it afterwards
        $this->container->get(SecurityHeaders::class)->sendFrontendCsp((array)$this->instance);

        // Which language? Likewise before the first content query - select() joins
        // the data_* rows and the content cache of the language.
        $langCode = preg_replace('/[^a-zA-Z0-9\-_]/', '', (string)($_REQUEST['lang'] ?? ''));
        $this->resolveLanguage($langCode);

        $request = (string)($_REQUEST['request'] ?? '');

        // "/en" without slash -> start page of the language (see class comment)
        if ($langCode === '') $this->redirectBareLanguage($request);
        $parts = explode(',', $request);

        // Parts of the URL: {slug|id},{template},{hash1},{hash2}
        // Template as reference "[area.]name" (Cms::templateFile()) - strictly limited to
        // [a-z0-9_.], the rest becomes a file name below.
        $slug = $parts[0];
        $templateName = preg_replace('/[^a-z0-9_.]/', '', (string)($parts[1] ?? ''));
        $this->hash1 = (int)($parts[2] ?? 0);
        $this->hash2 = (int)($parts[3] ?? 0);
        $this->urlTemplate = !empty($templateName);

        // Start page of the instance: only the domain was called
        // --------------------
        // `instance`.`record_id` / `template_id` determine what is delivered then.
        // A call of the start page is never redirected to the canonical URL.
        // $templateFile: path of the compiled file relative to Cms::$templatePath,
        // "{area_id}/{name}" - templates chosen by the CMS (record, area,
        // start page) are thus fixed; a template from the URL is only resolved
        // and checked further below.
        $templateFile = '';
        $isStartPage = ($slug === '' && $templateName === '');
        if ($isStartPage) {
            $startRecordId = (int)($this->instance['record_id'] ?? 0);
            if ($startRecordId > 0) $slug = (string)$startRecordId;
            $startTemplateId = (int)($this->instance['template_id'] ?? 0);
            if ($startTemplateId > 0) {
                $templateName = $this->getTemplateRef($startTemplateId);
                // Treat it like a template from the URL: the permission is checked,
                // so that a template chosen in the backend that has since become unsuitable
                // does not run unchecked
                $this->urlTemplate = ($templateName !== '');
            }
        }

        // Overview of an area: the path is exactly the slug prefix of an area
        // (/de/modelle) with an overview template (area.overview_template_id)
        // --------------------
        // The template runs without a record like a template chosen by the CMS (no
        // standalone flag needed); Cms::$overviewAreaId lets CMS:URL without 'id' point to
        // this overview (language switcher). Without an overview template it stays
        // with "record not found".
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
        // $cms holds the contents of the requested record and stays empty
        // if no record was requested (call "/0,{template}"). On an overview page
        // the details of the area are there (overviewData()).
        $cms = $isOverview ? $this->overviewData($this->areaId) : [];
        // $set serves as storage for CMS command outputs,
        // cf. CMS:TEXT with parameter 'set'
        $set = [];
        if (!empty($slug)) {
            // Published? In the frontend the record must be released and its
            // publication date reached; an expired one (offline_at) remains
            // reachable (archive, see class comment). The preview of the
            // backend shows every record.
            $published = ($this->mode === self::MODE_FRONTEND)
                ? $this->cms->publishedSql(['archive' => true])
                : '';

            // Pure digit sequence = record_id, anything else a slug.
            // Deliberately not is_numeric(): that would also count "-42", "3.14", "1e3" and
            // " 12" as numbers. But exactly "-42" is a valid slug - Record::
            // hookOnSave() prefixes numeric slugs with a minus sign, so that they do not
            // overlap with a record_id.
            if (preg_match('/^\d+$/', $slug)) {
                // get record by id
                // Direct access without Cms::select() - via the id neither virtual
                // fields nor filter logic are needed. Working copies (tmp) and deleted
                // records remain excluded.
                $sql = "SELECT * FROM `record` WHERE `record_id` = ?"
                ." AND `tmp`=0 AND `deleted`=0"
                . ($published !== '' ? " AND $published" : '');
                $this->db->query($sql, 'i', [(int)$slug]);
            }
            else {
                // get record by slug
                // The path is [prefix/]slug: if it begins with the slug prefix of an
                // area, the rest is searched only there, otherwise the whole path in
                // the areas without a prefix - in the requested language (a multilingual
                // slug field has its own slug per language), only in
                // the instances of this site, without working copies and deleted ones
                // (Cms::recordIdsBySlugPath()). Without a slug field in the system the
                // list stays empty. More than one hit is an inconsistency of the
                // database (slugs are unique per path, Record::slugPathOk()).
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

            // Does the record belong to this website?
            // --------------------
            // Via the slug, Cms::select() already filters with $sqlAccess; access
            // via the id, however, bypasses select() as a direct query. The
            // check is therefore here, common to both paths.
            if (!$this->instanceAllowed((int)($cms['instance_id'] ?? 0))) {
                $this->notFound('CMS error: record not found!');
            }

            // No template given in the URL?
            if (empty($templateName)) {
                // Template explicitly chosen in the record?
                $templateId = (int)$cms['template_id'];
                if (!empty($templateId)) {
                    $templateFile = $this->getTemplateFile($templateId);
                }

                // Last option: determine the default template of the area
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

        // Enforce the canonical URL (see class comment) - not in the preview:
        // it runs under the address of the backend and should stay there
        if (!$isStartPage && !$isOverview && $this->mode === self::MODE_FRONTEND) {
            $this->redirectCanonical($cms, $templateName);
        }

        if (!empty($templateName) || !empty($templateFile)) {
            // Include the template

            if ($this->urlTemplate) {
                // Template assigned via the URL -> check the access permission
                // (cases 2. and 3. skip the check: there the CMS has chosen the template
                //  itself, so it cannot be manipulated)

                // Resolve the reference "[area.]name". Cms::findTemplates() only sees
                // the templates of this website (Cms::$sqlAccess) - a name that occurs
                // several times in it is not usable without an area specification.
                $templates = $this->cms->findTemplates($templateName);
                if (count($templates) > 1) {
                    throw new \RuntimeException("Error: template name \"$templateName\" is ambiguous.");
                }
                if (count($templates) < 1) {
                    $this->notFound('Error: Template not found.');
                }
                $templateId = (int)$templates[0]['template_id'];
                $standalone = (int)$templates[0]['standalone'];
                // Time-controlled templates (scheduler) never run via an address
                if ((string)($templates[0]['type'] ?? '') === 'scheduler') {
                    $this->notFound('Error: Invalid template request.');
                }
                $templateInstanceId = (int)$templates[0]['instance_id'];
                $templateFile = (int)$templates[0]['area_id'] . '/' . $templates[0]['name'];

                // Does the template belong to this website?
                // --------------------
                // Only needed here: in the other two cases the CMS has chosen the template
                // itself (on the record or as the default of the area) - and the
                // record demonstrably already belongs to the instance at this point.
                if (!$this->instanceAllowed($templateInstanceId)) {
                    $this->notFound('Error: Invalid template request.');
                }
                if (empty($cms['record_id'])) {
                    // 1. Template without record contents
                    // --------------------
                    // Only allowed if the template is explicitly marked as standalone
                    // (e.g. start page, search form, sitemap).
                    if ((int)$standalone === 0) {
                        $this->notFound('Error: Invalid template request.');
                    }
                }
                else {
                    // 2. Template with record contents
                    // --------------------
                    // Is the given template the one chosen in the record?
                    if ((int)$cms['template_id'] !== $templateId) {
                        // No, a different one.

                        // Check whether the given template may override the chosen one
                        $sql = "SELECT 1 FROM `rel_area_template` WHERE `template_id` = ? AND `area_id` = ? AND `override` = 1";
                        $this->db->query($sql, 'ii', [$templateId, $this->areaId]);
                        if ($this->db->num_rows < 1) {
                            // No, it may not override.

                            // Last possibility: the given template is the default template
                            // and that one is also chosen in the record
                            if ((int)$cms['template_id'] === 0) {
                                // Default template is chosen. Is that the given one?
                                $sql = "SELECT `default_template_id` FROM `area`"
                                ." WHERE `area_id` = ? AND `deleted`=0";
                                $this->db->query($sql, 'i', [$this->areaId]);
                                $defaultTemplateId = ($this->db->num_rows > 0) ? (int)$this->db->fetchNum()[0] : 0;
                                if ($templateId !== $defaultTemplateId) {
                                    // No, not the given one
                                    $this->notFound('Error: Invalid template request.');
                                }
                                // else: given template is the assigned default template
                            }
                            else $this->notFound('Error: Invalid template request.');
                        }
                        // else: may override
                    }
                    // else: assigned anyway
                }
            }

            // Cms::$templatePath points to var/php - there Template::hookOnSaved()
            // stores the compiled PHP code of each template as a file when saving
            // (subdirectory per area, Template::fileName()).
            $_template = $this->cms->templatePath . "/{$templateFile}.php";
            if (!file_exists($_template)) {
                $this->notFound('Error: Invalid template request.');
            }

            // Keep the namespace clean: first include inside an IIFE
            // Without the encapsulation all variables of the template would sit in the method body and
            // could overwrite the local variables of resolveRequest().
            //
            // $this is still this Page instance in the closure. The compiled
            // template code accesses exactly that: $this->cms (Cms object) and $this->db.
            // Do not confuse with $cms - that is the data array of the record,
            // from which CMS:TEXT & Co. read.
            //
            // FRONTEND-START
            (function() use ($_template) {

                // Make $cms and $set available in the namespace of the template
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
     * Determine the website (instance) of the running request from the domain.
     *
     * Order - the first match wins (InstanceCache::resolveDomain()):
     *
     *     1. `instance`.`primary_domain`
     *     2. `rel_instance_domain` - literal, or as a regular expression if
     *        `regex` = 1 is set there. The rows are gone through in their `position`,
     *        so the editor determines the order.
     *     3. the default instance - it catches what is not registered anywhere
     *
     * All data comes from the cache files var/cache/instances.php and
     * instance-{id}.php; if they are missing, InstanceCache recreates them immediately.
     *
     * Afterwards it is fixed which contents this request may see at all: the
     * own instance and those released in `rel_instance_instance`. The expression
     * for this goes to Cms::setInstanceAccess() and applies from then on in every select().
     */
    private function resolveInstance(): void
    {
        if ($this->mode === self::MODE_BACKEND && $this->previewInstanceId > 0) {
            // Preview by token (FrontendHandover): instance from the token, and it
            // must match the domain - a token is only valid under its own website
            $this->instanceId = $this->previewInstanceId;
            if ($this->instanceCache->resolveDomain($this->requestHost()) !== $this->instanceId) {
                if (!headers_sent()) http_response_code(403);
                die('CMS error: access denied!');
            }
        }
        elseif ($this->mode === self::MODE_BACKEND) {
            // Preview: the instance comes as a parameter, the domain plays no
            // role (the backend mostly runs under a different domain than the website).
            // An unknown id ends below with "instance not found". The group
            // of the admin must have access to the instance (developer docs, chapter 11 (Permissions)).
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

        // Load the data of the instance (languages, protocol, start page, canonical)
        $this->instance = ($this->instanceId > 0)
            ? ($this->instanceCache->instance($this->instanceId) ?? [])
            : [];
        if (empty($this->instance)) {
            // Frontend: without the default instance the installation is not usable -
            // it is part of the basic data stock and cannot be deleted in the
            // backend. Preview: parameter missing or names an unknown instance.
            die('CMS error: instance not found!');
        }

        // Add the readable instances
        $this->instanceIds = $this->readableInstanceIds($this->instanceId);

        // From here on every Cms::select() filters on `area`, `record` and `template`
        $this->cms->setInstanceAccess($this->instanceIds);
    }

    /**
     * Host without port, lower case - this is how the domains are also stored in the cache
     * (Instance::hookOnSave() writes them in lower case)
     */
    private function requestHost(): string
    {
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if (($colon = strpos($host, ':')) !== false) $host = substr($host, 0, $colon);
        return mb_strtolower(trim($host));
    }

    /**
     * One instance plus those released in `readable_instances` - the instances
     * whose contents a request of this instance may see.
     *
     * @return int[]  the own one first
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
     * Run a template in the BACKEND for a record (list template of the
     * area, `area`.`list_template_id` - see class comment).
     *
     * The record is available in the template as $cms (as in the result loop
     * of CMS:SELECT), $set is empty. The instance restriction applies as in the frontend
     * for the instance of the record - so a CMS:SELECT in the template only sees
     * what the website would also see -, afterwards the previous state of the backend
     * is restored. If the template runs into an error (missing template,
     * ambiguous name, PHP error), the message appears in the result instead of the
     * output; the caller (a list) continues.
     *
     * The caller brackets the call with Database::pushResult()/popResult(),
     * if it is itself just traversing a result - the template may issue
     * its own queries.
     *
     * @param int   $templateId  `template`.`template_id`
     * @param array $record      record contents (Cms::fetch())
     * @return string            output of the template, '' without template/file
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

        // Frontend environment: instance restriction, $cms/$set
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
     * Run a time-controlled template (Scheduler module, task type template) -
     * on the command line, without address and without visitors.
     *
     * The environment is that of the frontend: instance of the template including readable
     * instances (Cms::$sqlAccess), content language with time zone, snippet language and
     * locale as with a page call, no record ($cms empty). $set carries the
     * time window - in the time zone of the language, as filter values expect it
     * (filter[online_at] > "{$set['last_run']}"), and additionally in UTC:
     *
     *     last_run, this_run          Y-m-d H:i:s, time zone of the content language
     *     last_run_utc, this_run_utc  the same in UTC
     *     scheduler_id                the task
     *
     * Only templates with the use 'scheduler' (template.type); conversely Page rejects them
     * as an address.
     *
     * @param int $templateId  `template`.`template_id`
     * @param int $languageId  content language; 0 or unknown = primary language of the instance
     * @param int $lastRun     start of the time window (Unix time, exclusive)
     * @param int $thisRun     end of the time window (Unix time, inclusive)
     * @param int $taskId      scheduler_id (for $set)
     * @return string          output of the template
     * @throws \RuntimeException  not a time-controlled template, file or instance missing
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

        // Instance and language as with a page call
        $this->mode = self::MODE_FRONTEND;
        $this->instanceId = (int)$row['instance_id'];
        $this->instance = $this->instanceCache->instance($this->instanceId) ?? [];
        if (empty($this->instance)) throw new \RuntimeException("instance {$this->instanceId} not found");
        $this->instanceIds = $this->readableInstanceIds($this->instanceId);
        $this->cms->setInstanceAccess($this->instanceIds);
        $this->protocol = (string)($this->instance['protocol'] ?? '') ?: 'https';
        $languages = $this->instanceCache->languagesOf($this->instanceIds);
        $this->resolveLanguage((string)($languages[$languageId]['code'] ?? ''));

        // Time window in the time zone of the content language (Cms::$userTZ) and in UTC
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
     * Include a compiled template in the running request and return its output
     * as a string instead of outputting it - for commands that build a text from a
     * template (CMS:EMAIL bodytemplate/subjecttemplate).
     *
     * Unlike renderRecord() the environment stays as it is: $cms is the
     * running record, $set and the instance restriction those of the page. The
     * compiled code needs $this = Page ($this->cms, $this->email ...), therefore
     * the method is here and not with the caller. Local variables of the calling
     * template are not visible to the included one - only $cms, $set and the superglobals.
     *
     * Exceptions are passed on to the caller; the output buffered up to that point is
     * discarded.
     *
     * @param string $templateFile  "{area_id}/{name}" from Cms::templateFile()
     * @return string               output of the template
     * @throws \RuntimeException    file missing or inclusion depth exceeded
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
            // On an exception the buffer is still open (also one that the
            // template itself opened and did not close)
            while (ob_get_level() > $level) ob_end_clean();
        }
    }

    /**
     * Determine the content language of the running request (see class comment).
     *
     * @param string $langCode  language code from the URL, '' if none given
     */
    private function resolveLanguage(string $langCode): void
    {
        // Languages of all interconnected instances: the own one first (its
        // primary language in first place), then those of the readable instances. Each of them
        // is a valid language version of this site - also one that only a
        // readable instance carries. All from the file cache, no database query.
        $this->languages = $this->instanceCache->languagesOf($this->instanceIds);
        $primaryId = (int)($this->instance['primary_language_id'] ?? 0);

        if ($langCode !== '') {
            // Code from the URL - must be a language of this site
            $language = $this->instanceCache->findByCode($this->languages, $langCode);
            if ($language === null) {
                $this->notFound('CMS error: language not found!');
            }
            $this->languageId = (int)$language['language_id'];
        }
        elseif (count($this->languages) > 1) {
            // Without a code: the browser's favourite language that the site carries
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

        // Language per readable instance: the requested one if it is carried there,
        // otherwise its primary language
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
     * Path is only a language code ("/en", "/de-at") -> 301 to the start page of this
     * language (see class comment). Only in the frontend and only for GET/HEAD; otherwise
     * and if the path is not a code of the site, the method returns and the path
     * is searched as a slug like any other.
     *
     * @param string $request  path from the URL (request), without language segment
     */
    private function redirectBareLanguage(string $request): void
    {
        if ($this->mode !== self::MODE_FRONTEND) return;
        // Same pattern as the rewrite rule for the language segment
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
            // Only one language: a published record with this slug takes precedence
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
     * End the request: there is nothing under this address (404, see class comment).
     */
    private function notFound(string $message): never
    {
        if (!headers_sent()) http_response_code(404);
        die($message);
    }

    /**
     * With canonical = 1: determine the one valid URL and redirect there with 301
     * if it deviates (see class comment).
     *
     * Protocol, host and path are compared; the query parameters of the call
     * are kept and carried along.
     *
     * @param array  $record        loaded record ([] without record)
     * @param string $templateName  determined template
     */
    private function redirectCanonical(array $record, string $templateName): void
    {
        if (empty($this->instance['canonical'])) return;
        if (PHP_SAPI === 'cli' || headers_sent()) return;
        // Only redirect read-only calls - a 301 on a POST would lose the input
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD') return;

        // Template only if it came from the URL - the template chosen by the CMS is
        // the normal case and does not belong in the address
        $urlTemplate = $this->urlTemplate ? $templateName : '';
        $path = $this->cms->recordUrl($record, '', $urlTemplate);
        // Hash parameters are kept
        if (!empty($this->hash1) || !empty($this->hash2)) {
            if ($urlTemplate === '') $path .= ',';
            $path .= ',' . (int)$this->hash1;
            if (!empty($this->hash2)) $path .= ',' . (int)$this->hash2;
        }

        $protocol = (string)($this->instance['protocol'] ?? '') ?: $this->protocol;
        $host = (string)($this->instance['primary_domain'] ?? '');
        if ($host === '') $host = mb_strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $this->canonicalUrl = $protocol . '://' . $host . $path;

        // Requested address: path without query string, host without port
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
     * May this request see contents of the given instance?
     *
     * @param int $instanceId  `instance_id` of the record or template
     */
    private function instanceAllowed(int $instanceId): bool
    {
        return in_array($instanceId, $this->instanceIds, true);
    }

    /**
     * Determine the protocol of the running request.
     *
     * Evaluates only the direct server variables, deliberately NOT X-Forwarded-Proto -
     * that would be forgeable without a known reverse proxy.
     *
     * @return string 'http' or 'https'
     */
    private function getProtocol() {
        $proto = 'http';
        if (!empty($_SERVER['HTTPS'])) {
            // IIS sets 'off' instead of omitting the variable -> check the value, not existence
            $value = mb_strtolower((string) $_SERVER['HTTPS'], 'utf-8');
            if (($value == 'on') || ($value == '1')) $proto = 'https';
        }
        elseif (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
            $proto = 'https';
        }
        return $proto;
    }

    /**
     * $cms of an overview page (prefix address, area.overview_template_id): instead of
     * a record the details of the area. record_id stays 0, so that commands
     * like CMS:URL still recognize "no record"; area_id allows directly
     * (CMS:SELECT list filter[area]="{$cms['area_id']}") or (CMS:TEXT area_label).
     *
     * @return array  record_id (0), area_id, area (name), area_label (snippet resolved in the
     *                content language), area_type, area_icon, instance_id,
     *                parent_id (parent area), language_id, slug_prefix (in the
     *                running language), overview (true)
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
     * Path of the compiled file for a template id: "{area_id}/{name}"
     * (relative to Cms::$templatePath, without ".php" - see Template::fileName()).
     *
     * @param int $templateId
     * @return string  '' if the template does not (any more) exist. The caller
     *                 treats '' like "no template chosen" and moves on to the next
     *                 level of the template determination.
     */
    private function getTemplateFile(int $templateId): string
    {
        $this->db->query("SELECT `area_id`, `name` FROM `template` WHERE `template_id` = ?", 'i', [$templateId]);
        if ($this->db->num_rows > 0) {
            [$areaId, $name] = $this->db->fetchNum();
            $this->templateName = $name; // but $this->urlTemplate stays false
            return (int)$areaId . '/' . $name;
        }
        return ''; // ignore
    }

    /**
     * Unique reference "areaname.templatename" for a template id - for
     * the start page template, which is checked like a template from the URL.
     *
     * @return string  '' if the template does not (any more) exist
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
