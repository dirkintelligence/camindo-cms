<?php
    define ('CAMINDO_STARTED', true);
    define ('CAMINDO_BACKEND', true);

    // Bootstrap laden
    require_once dirname(__DIR__, 3) . '/load_camindo.php';

    use Camindo\Core\Config;
    use Camindo\Core\Cms;
    use Camindo\Core\Backend;
    use Camindo\Core\Session;
    use Camindo\Core\Modules\Messaging\MessagingBackend;
    use Camindo\Core\SystemState;
    use Camindo\Core\SecurityHeaders;

    // Load Config
    $app = $GLOBALS['app'];
    // Nie installiert (keine .camindo.php, keine Datenbank): Hinweis auf den Installer
    $app->get(SystemState::class)->guardNotInstalled();
    $config = $app->get(Config::class);
    $cms = $app->get(Cms::class);
    $backend = $app->get(Backend::class);

    // Session laden, sofern angemeldet: stellt Backend-Sprache und Zeitzone des
    // Admins ein (Session::load() -> Cms::applyAdminSettings()), damit die
    // statischen Teile der Oberfläche in seiner Sprache erscheinen. Ohne Session
    // bleibt es bei 'en_US' - der Anmeldedialog lädt sich in der gemerkten Sprache.
    $session = $app->get(Session::class);
    $session->load();

    // Wartungsmodus / Datenbank nicht auf dem Stand der Dateien (SystemState):
    // Superuser sehen einen Hinweisbalken und können weiterarbeiten, alle anderen
    // die Wartungsseite. Ohne Session bleibt der Login erreichbar.
    $state = $app->get(SystemState::class);
    $blockReason = $state->blockReason();
    if ($blockReason !== null && $session->get('admin_id') && !$backend->isSuperuser()) {
        $state->sendUnavailable();
    }

    // Ungelesene interne Nachrichten für das Abzeichen am Brief-Symbol (0 ohne
    // Anmeldung; danach hält cApp.mail den Wert aktuell)
    $unreadCount = $session->get('admin_id') ? $app->get(MessagingBackend::class)->badgeCount() : 0;
    $unreadCount = min($unreadCount, 99);
    
    // In der Entwicklungsumgebung immer neusten Stand der Scripte laden
    $developmentVersion = (bool)($config->get('system.development_version') ?? false);
    $nocache = $developmentVersion ? '?nocache' . time() : '';

    // Nonce der Seite (Script-Policy, developer_docs/19-csp.md): für die Inline-Scripts
    // hier und als cConfig.nonce für alles, was das Javascript nachlädt
    $nonceAttr = $app->get(SecurityHeaders::class)->nonceAttr();

    // Schreibrichtung der Backend-Sprache (Cms::RTL_LANGUAGES): bei rtl die gespiegelte
    // Fassung von Bootstrap (ms-/me-, text-start, float-end, ... tauschen die Seiten);
    // backend.css und die Extensions arbeiten mit logischen Properties und brauchen keine
    // eigene Fassung. Fehlt bootstrap.rtl.min.css, bleibt es bei der normalen Datei.
    $dir = $cms->textDirection();
    $bootstrapCss = ($dir === 'rtl' && is_file(__DIR__ . '/assets/bootstrap/bootstrap.rtl.min.css'))
        ? 'bootstrap.rtl.min.css' : 'bootstrap.min.css';

    // Assets der aktiven Module (settings.php 'assets', publiziert nach mod/{name}/):
    // Stylesheets im Kopf, Scripte vor backend-init.js. Sie kommen auf jede Seite,
    // auch ohne Modulrecht - die Rechte prüft der Server.
    $moduleAssets = ['js' => [], 'css' => []];
    foreach ($cms->modules as $moduleSettings) {
        foreach (['js', 'css'] as $assetType) {
            foreach ((array)($moduleSettings['assets_published'][$assetType] ?? []) as $assetPath) {
                $moduleAssets[$assetType][] = (string)$assetPath;
            }
        }
    }
?>
<!doctype html>
<html lang="<?php echo htmlspecialchars(str_replace('_', '-', $cms->getLang())); ?>" dir="<?php echo $dir; ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>camindo CMS 9</title>
        <meta name="robots" content="noindex, nofollow">
        <link href="assets/bootstrap/<?php echo $bootstrapCss . $nocache; ?>" rel="stylesheet">
        <link href="assets/css/backend.css<?php echo $nocache; ?>" rel="stylesheet">
<?php foreach ($moduleAssets['css'] as $assetPath): ?>
        <link href="<?php echo htmlspecialchars($assetPath); ?>" rel="stylesheet">
<?php endforeach; ?>
    </head>
    <body data-bs-theme="light">
    <!-- old browsers -->
    <noscript>
        <div class="container"><div class="alert alert-danger">###no_script</div></div>
    </noscript>

    <script<?php echo $nonceAttr; ?>>
        (function(){
            var isModern = true;
            // Alter Browser?
            isModern = (typeof window.FormData !== 'undefined' && document.querySelector);
            if (!isModern) {
                alert("##old_browser");
                if (window.stop) { window.stop(); }
            }
        })();
    </script>
    <div class="resize-overlay"></div>
    <header class="d-flex align-items-center justify-content-between p-0">
    <div class="d-flex align-items-center">
        <button id="c9-hamburger" class="btn c9-head-btn p-0" title="<?php echo $cms->snippet('speak_open_menu'); ?>">
            <span class="icon">menu</span>
        </button>
        <button id="c9-logo" class="btn c9-head-btn" <?php echo $backend->on('click', 'cApp.layers.home();'); ?>>
            camindo<small>CMS</small>
        </button>
    </div>
    <div class="d-flex align-items-center">
        <button id="c9-bgproc-btn" class="btn c9-head-btn p-0 position-relative" title="Background Processes">
            <span class="icon" aria-hidden="false">hourglass</span>
            <span class="c9-badge" style="display:none"><small>0</small></span>
        </button>
        <button id="c9-mail-btn" class="btn c9-head-btn p-0 position-relative" title="<?php echo $cms->snippet('messaging.speak_open_messages'); ?>">
            <span class="icon">mail</span>
            <span class="c9-badge"<?php if ($unreadCount < 1) echo ' style="display:none"'; ?>><small><?php echo $unreadCount; ?></small></span>
        </button>
        <button id="c9-settings-btn" class="btn c9-head-btn p-0" title="<?php echo $cms->snippet('button_settings'); ?>">
            <span class="icon">settings</span>
        </button>
        <button id="c9-logout-btn" class="btn c9-head-btn p-0" title="<?php echo $cms->snippet('button_logout'); ?>">
            <span class="icon icon-dir">logout</span>
        </button>
    </div>
    </header>

    <main>
        <?php if ($blockReason !== null): ?>
        <div class="alert alert-warning rounded-0 mb-0 py-2 small" role="alert">
            <span class="icon" aria-hidden="true">warning</span>
            <?php echo htmlspecialchars($state->blockMessage($blockReason)); ?>
        </div>
        <?php endif; ?>
        <article>
        <!--  JavaScript enabled? -->

		<noscript>
			<div class="c5-alert-container c5-body-space">
				<div class="alert alert-danger">
					###js_required
				</div>
			</div><!-- container -->
		</noscript>
        </article>
        <div id="c9-navi"><nav class="container"></nav></div>
        <aside>
            <div class="resize-handle">
                <span class="icon" aria-hidden="true">drag_indicator</span>
            </div>
            <div id="aside-content"></div>
            <button class="btn aside-close-btn" <?php echo $backend->on('click', 'cApp.aside.close();'); ?>><span class="icon" aria-hidden="true">close</span><span class="visually-hidden"><?php echo $cms->snippet('button_close_sidebar'); ?></span></button>
        </aside>
    </main>

    <!-- main menu: Inhalt lädt cApp.ui._initMainMenu() beim Öffnen aus Start/menu.php
         (Info, Handbücher, Module der Gruppe); dies ist nur der Stand vor der Anmeldung -->
    <div id="c9-menu-container" class="shadow-lg bg-white p-3">
    <ul class="list-unstyled mb-0">
        <li><a class="dropdown-item" href="#"<?php echo $backend->on('js', "cApp.aside.close('');"); ?>><?php echo $cms->snippet('label_close_sidebar'); ?></a></li>
        <li><a class="dropdown-item" href="#"<?php echo $backend->on('js', "cApp.layers.addOnce('Admin/settings.php');"); ?>><?php echo $cms->snippet('button_settings'); ?></a></li>
        <li><a class="dropdown-item" href="#"<?php echo $backend->on('js', 'cApp.layers.logout();'); ?>><?php echo $cms->snippet('label_logout'); ?></a></li>
    </ul>
    </div>

    <!-- bgproc details -->
    <div id="c9-bgproc-container" class="shadow-lg bg-white">
        wait...
    </div>

    <!-- Zielcontainer für das Abzeichen der internen Nachrichten (cApp.mail.refreshBadge) -->
    <div id="c9-mail-container" class="d-none"></div>

    <!-- modal -->
    <div id="login-modal" class="modal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="staticBackdropLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" id="login-dialog">
        </div>
    </div>
    <script src="assets/jquery/jquery.min.js"></script>
    <script src="assets/bootstrap/bootstrap.bundle.min.js<?php echo $nocache; ?>"></script>
    <script<?php echo $nonceAttr; ?>>
        window.cConfig = {
            cms_path: '<?php echo $config->get('paths.cms'); ?>', // deprecated
            backend_path: '<?php echo $config->get('paths.backend'); ?>',
            // CSRF-Token der Session (17-csrf.md §2.4). Leer ohne Anmeldung;
            // StartBackend::_loginComplete() reicht es nach, sobald eine Session steht.
            csrf_token: <?php echo json_encode($session->csrfToken()); ?>,
            // Nonce der Seite: geht mit jedem Ajax-Request als X-Camindo-Nonce mit;
            // backend-handlers.js erkennt daran die Event-Handler aus dem Code (19-csp.md)
            nonce: <?php echo json_encode($app->get(SecurityHeaders::class)->nonce()); ?>,
            // Locale des Admins (ICU, z. B. de_AT) - Datumsformate der Feld-Extensions
            locale: <?php echo json_encode($cms->userLocale); ?>,
            message_edited:'<?php echo $cms->snippet('message_edited'); ?>',
            label_home: '<?php echo $cms->snippet('label_home'); ?>',
            icon_home: '<?php echo $backend->template('icon_home'); ?>',
            path_separator: '<?php echo $cms->snippet('path_separator'); ?>',
            message_connection_error: '<?php echo $cms->snippet('message_connection_error'); ?>',
            bgproc_finished: '<?php echo $cms->snippet('bgproc_message_finished'); ?>',
            bgproc_finished_errors: '<?php echo $cms->snippet('bgproc_message_finished_errors'); ?>',
            max_upload_bytes: '<?php
                echo min(
                    $backend->parseSize(ini_get('upload_max_filesize')),
                    $backend->parseSize(ini_get('post_max_size'))
                );
            ?>',
            error_uploadsize_exceeded: '<?php echo $cms->snippet('error_uploadsize_exceeded'); ?>',
            // Feld-Extensions: publizierte Assets je Extension (cApp.extensions lädt sie bei Bedarf)
            extensions: <?php
                $manifest = [];
                foreach ($cms->extensions as $extName => $extSettings) {
                    $manifest[preg_replace('/[^a-z0-9_]/', '', strtolower((string)$extName))] = $extSettings['assets_published'] ?? ['js' => [], 'css' => []];
                }
                echo json_encode($manifest, JSON_UNESCAPED_SLASHES);
            ?>
        };
    </script>
    <script src="assets/js/backend.js<?php echo $nocache; ?>"></script>
    <script src="assets/js/backend-handlers.js<?php echo $nocache; ?>"></script>
    <script src="assets/js/backend-system.js<?php echo $nocache; ?>"></script>
    <script src="assets/js/backend-forms.js<?php echo $nocache; ?>"></script>
    <script src="assets/js/backend-ui.js<?php echo $nocache; ?>"></script>
    <script src="assets/js/backend-layers.js<?php echo $nocache; ?>"></script>
    <script src="assets/js/backend-aside.js<?php echo $nocache; ?>"></script>
    <script src="assets/js/backend-ctx-menu.js<?php echo $nocache; ?>"></script>
    <script src="assets/js/backend-polling.js<?php echo $nocache; ?>"></script>
    <script src="assets/js/backend-mail.js<?php echo $nocache; ?>"></script>
    <script src="assets/js/backend-smartrows.js<?php echo $nocache; ?>"></script>
    <script src="assets/js/backend-embedded.js<?php echo $nocache; ?>"></script>
    <script src="assets/js/backend-extensions.js<?php echo $nocache; ?>"></script>
<?php foreach ($moduleAssets['js'] as $assetPath): ?>
    <script src="<?php echo htmlspecialchars($assetPath); ?>"></script>
<?php endforeach; ?>
    <!-- finally -->
    <script src="assets/js/backend-init.js<?php echo $nocache; ?>"></script>
</body>
</html>