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
    // Never installed (no .camindo.php, no database): note about the installer
    $app->get(SystemState::class)->guardNotInstalled();
    $config = $app->get(Config::class);
    $cms = $app->get(Cms::class);
    $backend = $app->get(Backend::class);

    // Load the session, if logged in: sets the backend language and time zone of the
    // administrator (Session::load() -> Cms::applyAdminSettings()), so that the
    // static parts of the UI appear in their language. Without a session
    // it stays at 'en_US' - the login dialog loads in the remembered language.
    $session = $app->get(Session::class);
    $session->load();

    // Maintenance mode / database not at the state of the files (SystemState):
    // superusers see a notice bar and can keep working, all others
    // the maintenance page. Without a session the login remains reachable.
    $state = $app->get(SystemState::class);
    $blockReason = $state->blockReason();
    if ($blockReason !== null && $session->get('admin_id') && !$backend->isSuperuser()) {
        $state->sendUnavailable();
    }

    // Unread internal messages for the badge on the mail icon (0 without
    // login; afterwards cApp.mail keeps the value up to date)
    $unreadCount = $session->get('admin_id') ? $app->get(MessagingBackend::class)->badgeCount() : 0;
    $unreadCount = min($unreadCount, 99);
    
    // In the development environment, always load the latest state of the scripts
    $developmentVersion = (bool)($config->get('system.development_version') ?? false);
    $nocache = $developmentVersion ? '?nocache' . time() : '';

    // Nonce of the page (script policy, developer docs, chapter 14 (Security: web)): for the inline scripts
    // here and as cConfig.nonce for everything that the JavaScript loads
    $nonceAttr = $app->get(SecurityHeaders::class)->nonceAttr();

    // Writing direction of the backend language (Cms::RTL_LANGUAGES): for rtl the mirrored
    // version of Bootstrap (ms-/me-, text-start, float-end, ... swap the sides);
    // backend.css and the extensions work with logical properties and need no
    // own version. If bootstrap.rtl.min.css is missing, the normal file stays.
    $dir = $cms->textDirection();
    $bootstrapCss = ($dir === 'rtl' && is_file(__DIR__ . '/assets/bootstrap/bootstrap.rtl.min.css'))
        ? 'bootstrap.rtl.min.css' : 'bootstrap.min.css';

    // Color scheme (personal setting admin.settings.dark_mode): data-bs-theme on <html>
    // switches Bootstrap and the --c9-* tokens (custom.scss). Without a session null - then
    // the login page takes the scheme last used in this browser (localStorage c9.theme).
    $theme = $session->get('admin_id')
        ? (empty(((array)($session->get('details') ?? []))['settings']['dark_mode']) ? 'light' : 'dark')
        : null;

    // Assets of the active modules (settings.php 'assets', published to mod/{name}/):
    // Stylesheets in the head, scripts before backend-init.js. They come on every page,
    // also without module permission - the server checks the permissions.
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
<html lang="<?php echo htmlspecialchars(str_replace('_', '-', $cms->getLang())); ?>" dir="<?php echo $dir; ?>" data-bs-theme="<?php echo $theme ?? 'light'; ?>">
    <head>
        <script<?php echo $nonceAttr; ?>>
            // Color scheme: remember it for the login page, there apply it before the first paint
            (function () {
                var theme = <?php echo json_encode($theme); ?>;
                try {
                    if (theme) localStorage.setItem('c9.theme', theme);
                    else theme = localStorage.getItem('c9.theme');
                } catch (e) { /* storage blocked: light */ }
                if (theme === 'dark') document.documentElement.setAttribute('data-bs-theme', 'dark');
            })();
        </script>
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
    <body>
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
    <!-- Skip links (WCAG 2.4.1): first focusable elements of the page, visible on focus;
         backend-a11y.js moves the focus (a #hash would change the layer routing) -->
    <div class="c9-skip-links">
        <a href="#c9-content" class="visually-hidden-focusable c9-skip-link" data-c9-skip="#c9-content"><?php echo $cms->snippet('skip_to_content'); ?></a>
        <a href="#c9-navi" class="visually-hidden-focusable c9-skip-link" data-c9-skip="#c9-navi"><?php echo $cms->snippet('skip_to_breadcrumb'); ?></a>
    </div>
    <div class="resize-overlay"></div>
    <header class="d-flex align-items-center justify-content-between p-0">
    <div class="d-flex align-items-center c9-head-start">
        <button type="button" id="c9-hamburger" class="btn c9-head-btn p-0" title="<?php echo $cms->snippet('speak_open_menu'); ?>"
            aria-haspopup="true" aria-expanded="false" aria-controls="c9-menu-container">
            <span class="icon" aria-hidden="true">menu</span><span class="visually-hidden"><?php echo $cms->snippet('speak_open_menu'); ?></span>
        </button>
        <button type="button" id="c9-logo" class="btn c9-head-btn" title="<?php echo $cms->snippet('label_home'); ?>" <?php echo $backend->on('click', 'cApp.layers.home();'); ?>>
            camindo<small>CMS</small>
        </button>
    </div>
    <div class="d-flex align-items-center c9-head-end">
        <button type="button" id="c9-bgproc-btn" class="btn c9-head-btn p-0 position-relative" title="<?php echo $cms->snippet('bgproc_headline'); ?>"
            aria-haspopup="true" aria-expanded="false" aria-controls="c9-bgproc-container">
            <span class="icon" aria-hidden="true">hourglass</span><span class="visually-hidden"><?php echo $cms->snippet('bgproc_headline'); ?></span>
            <span class="c9-badge" style="display:none"><small>0</small></span>
        </button>
        <button type="button" id="c9-mail-btn" class="btn c9-head-btn p-0 position-relative" title="<?php echo $cms->snippet('messaging.speak_open_messages'); ?>">
            <span class="icon" aria-hidden="true">mail</span><span class="visually-hidden"><?php echo $cms->snippet('messaging.speak_open_messages'); ?></span>
            <span class="c9-badge"<?php if ($unreadCount < 1) echo ' style="display:none"'; ?>><small><?php echo $unreadCount; ?></small></span>
        </button>
        <button type="button" id="c9-settings-btn" class="btn c9-head-btn p-0" title="<?php echo $cms->snippet('button_settings'); ?>">
            <span class="icon" aria-hidden="true">settings</span><span class="visually-hidden"><?php echo $cms->snippet('button_settings'); ?></span>
        </button>
        <button type="button" id="c9-logout-btn" class="btn c9-head-btn p-0" title="<?php echo $cms->snippet('label_logout'); ?>">
            <span class="icon icon-dir" aria-hidden="true">logout</span><span class="visually-hidden"><?php echo $cms->snippet('label_logout'); ?></span>
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
        <!-- Target of the skip link: the layers (backend-layers.js); tabindex -1 so that it can take the focus -->
        <article id="c9-content" tabindex="-1">
        <!--  JavaScript enabled? -->

		<noscript>
			<div class="c5-alert-container c5-body-space">
				<div class="alert alert-danger">
					###js_required
				</div>
			</div><!-- container -->
		</noscript>
        </article>
        <div id="c9-navi" tabindex="-1"><nav class="container" aria-label="<?php echo $cms->snippet('label_breadcrumb'); ?>"></nav></div>
        <aside aria-label="<?php echo $cms->snippet('label_sidebar'); ?>">
            <div class="resize-handle">
                <span class="icon" aria-hidden="true">drag_indicator</span>
            </div>
            <div id="aside-content"></div>
            <button type="button" class="btn aside-close-btn" <?php echo $backend->on('click', 'cApp.aside.close();'); ?>><span class="icon" aria-hidden="true">close</span><span class="visually-hidden"><?php echo $cms->snippet('button_close_sidebar'); ?></span></button>
        </aside>
    </main>

    <!-- main menu: Inhalt lädt cApp.ui._initMainMenu() beim Öffnen aus Start/menu.php
         (Info, Handbücher, Module der Gruppe); dies ist nur der Stand vor der Anmeldung -->
    <nav id="c9-menu-container" class="shadow-lg bg-white p-3" aria-label="<?php echo $cms->snippet('speak_open_menu'); ?>">
    <ul class="list-unstyled mb-0">
        <li><a class="dropdown-item" href="#"<?php echo $backend->on('js', "cApp.aside.close('');"); ?>><?php echo $cms->snippet('label_close_sidebar'); ?></a></li>
        <li><a class="dropdown-item" href="#"<?php echo $backend->on('js', "cApp.layers.addOnce('Admin/settings.php');"); ?>><?php echo $cms->snippet('button_settings'); ?></a></li>
        <li><a class="dropdown-item" href="#"<?php echo $backend->on('js', 'cApp.layers.logout();'); ?>><?php echo $cms->snippet('label_logout'); ?></a></li>
    </ul>
    </nav>

    <!-- bgproc details -->
    <div id="c9-bgproc-container" class="shadow-lg bg-white" role="region" aria-label="<?php echo $cms->snippet('bgproc_headline'); ?>">
        <?php echo $cms->snippet('label_loading'); ?>
    </div>

    <!-- Zielcontainer für das Abzeichen der internen Nachrichten (cApp.mail.refreshBadge) -->
    <div id="c9-mail-container" class="d-none"></div>

    <!-- modal -->
    <div id="login-modal" class="modal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="login-modal-title" aria-hidden="true">
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
            // Accessibility (backend-a11y.js, backend-system.js): texts for screen readers
            label_loading: <?php echo json_encode($cms->snippet('label_loading')); ?>,
            // Personal setting "Animation" (admin.settings.animation, default on): the slide
            // animation of the record list of a lookup field (backend-layers.js _slideOk())
            animation: <?php echo json_encode((int)(((array)($session->get('details') ?? []))['settings']['animation'] ?? 1) !== 0); ?>,
            button_close: <?php echo json_encode($cms->snippet('button_close')); ?>,
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
    <script src="assets/js/backend-a11y.js<?php echo $nocache; ?>"></script>
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