<?php
/**
 * Startseite und Anmeldung des Backends.
 *
 * Einstiegspunkte (Scripte in backend/):
 *
 *     start.php  -> start()   Begrüßung und Modulübersicht
 *     login.php  -> login()   Anmeldedialog
 *     menu.php   -> menu()    Inhalt des Hauptmenüs (Hamburger links oben): Info,
 *                             Handbücher, die Module der Gruppe
 *     info.php   -> info()    Über camindo CMS: Version, für Superuser das Ergebnis
 *                             der Update-Prüfung (UpdateCheck) mit "Jetzt prüfen"
 *
 * DIE MODULLISTE KOMMT NICHT AUS DER DATENBANK
 * --------------------
 * _moduleButtons() läuft über Cms::$modules - das ist die von ComponentBackend
 * erzeugte Datei var/config/components/modules.php. Aufgenommen wird nur, was in seiner
 * install/settings.php 'listed' gesetzt hat; Module wie Area oder Record erscheinen
 * damit auf der Startseite, Hilfsmodule nicht.
 *
 * Jedes gelistete Modul muss zwei Dinge mitbringen, die hier vorausgesetzt werden:
 * das Snippet {modul}.module_label und die HTML-Vorlage {modul}.module_button.
 * Ein Modul, das in settings.php 'button' => false setzt, bleibt trotz 'listed'
 * ohne Kachel - es unterliegt weiter der Rechteverwaltung, zeigt sich aber nur als
 * Widget (so das Modul User). Umgekehrt bekommt ein NICHT gelistetes Modul mit
 * 'button' => true eine Kachel für jede Gruppe (so der Papierkorb, Modul Trash).
 *
 * VIER PLÄTZE AUF DER STARTSEITE
 * --------------------
 * Die Startseite ist ein Raster aus vier Halbspalten. Platz 1 (links oben) ist fest
 * mit den Modulkacheln belegt, die Plätze 2 bis 4 füllt jeder Admin über seine
 * persönlichen Einstellungen mit Widgets der Module (Camindo\Core\Widgets, dort
 * steht der Vertrag). In einen Platz passen mehrere Widgets; sie stehen dort
 * untereinander, in der Reihenfolge, in der sie hinzugefügt wurden.
 * _startWidgets() liest nur die Einstellung und stellt die Spalten - was in der
 * Karte steht, verantwortet das jeweilige Modul.
 *
 * DER ANMELDEDIALOG IST EIN LAYER, KEINE SEITE
 * --------------------
 * login() gibt kein vollständiges Dokument aus, sondern die drei Teile eines Modals.
 * Bei Erfolg wird gar kein HTML gesendet, sondern nur Javascript: das Cookie ist
 * gesetzt, cApp.layers.loginComplete() lädt die leer gebliebenen Layer nach und
 * schließt den Dialog. Dadurch bleibt der Stand der Oberfläche über die Anmeldung
 * hinweg erhalten - man landet nicht auf der Startseite, sondern dort, wo man war.
 *
 * ZWEI STUFEN, EIN DIALOG
 * --------------------
 * Hat der Admin einen zweiten Faktor (E-Mail laut admin.2_factor oder eine
 * Authenticator-App), liefert Admin::loginByName() bewusst keine 'sessid': es ist
 * noch keine Session entstanden, sondern nur ein Code per E-Mail verschickt bzw.
 * die App gefragt.
 * Woran 'step1' seine beiden Ausgänge unterscheidet, ist genau diese fehlende 'sessid' -
 * ohne sie folgt das zweite Formular, mit ihr ist die Anmeldung fertig.
 *
 * Das zweite Formular trägt die 'uid' aus 'step1' als verstecktes Feld weiter. Sie ist
 * der einzige Bezug zum wartenden Konto: der Benutzername wird nicht noch einmal
 * übertragen, und der Code allein gehört zu niemandem. 'step2' reicht beides an
 * Admin::loginByCode() weiter.
 *
 * Welcher Code erwartet wird, sagt 'factor' im Ergebnis (E-Mail oder Authenticator-
 * App, Admin::secondFactor()) - danach richtet sich nur der Hinweistext. Ist TOTP
 * Pflicht (security.admin_2fa = totp_required) und fehlt noch, liefert die Anmeldung
 * statt der Session 'totp_setup': der Dialog zeigt den QR-Code, 'totp_setup' prüft
 * den ersten Code (Admin::loginByTotpSetup()) und zeigt danach die
 * Wiederherstellungscodes, bevor er sich schließt.
 *
 * PASSWORT ÄNDERN MÜSSEN
 * --------------------
 * Hat ein anderer Admin das Passwort gesetzt oder ist es älter als
 * admin.password_retention_days, liefert die Anmeldung statt der Session
 * 'password_renew' (Admin::_loginComplete()): der Dialog fragt ein neues Passwort
 * ab ('password_change' -> Admin::loginByPasswordChange()). Erst danach geht es
 * weiter - ggf. mit dem Einrichten von TOTP. _loginNext() verteilt die Ergebnisse
 * aller Stufen auf den nächsten Schritt.
 *
 * EIN VERSUCH JE CODE
 * --------------------
 * Admin::loginByCode() würfelt die uid nach jedem Versuch neu, auch nach einem
 * misslungenen - sonst ließe sich der Code in Ruhe durchprobieren. Zusätzlich zählt
 * jeder falsche Code im LoginGuard (admin.bruteforce_max) auf das Konto.
 * Nach einem Fehlversuch führt deshalb kein Weg zurück ins Code-Formular: 'step2'
 * zeigt wieder das leere Anmeldeformular, und der Benutzer beginnt von vorn.
 *
 * SPRACHE, LOCALE UND ZEITZONE SCHON BEIM ANMELDEN
 * --------------------
 * Das Anmeldeformular fragt Backend-Sprache, Locale (Datums- und Zahlenformat) und
 * Zeitzone ab. Vorbelegt werden sie vom Javascript (cApp.ui.loginPrefsInit()): mit
 * der letzten Wahl aus dem localStorage - die auch AdminBackend::settings() dort
 * ablegt -, sonst mit Browsersprache, -locale und -zeitzone; eine Browsersprache ohne
 * Backend-Übersetzung ("nl") fällt auf 'en_US' zurück (Cms::resolveBackendLang()), eine
 * Locale ohne Region oder ohne ICU-Daten auf system.locale. Das Formular selbst wird
 * bereits in dieser Sprache geladen (Start/login.php?lang=…).
 *
 * Nach erfolgreicher Anmeldung landet die Wahl als persönliche Einstellung im
 * Admin-Datensatz (Admin::saveSettings()) - dieselbe Stelle, die die Einstellungen
 * rechts oben im Backend beschreiben. Bei der Zwei-Faktor-Anmeldung reisen die drei
 * Werte als versteckte Felder durch das Code-Formular. Außerdem wird das Cookie des
 * Frontend-Editings entsprechend der gespeicherten Einstellung gesetzt.
 *
 * BETRIEBSWARNUNGEN UNTER DEN MODULKACHELN
 * --------------------
 * Superuser sehen unter den Modulkacheln, was im Betrieb nicht stimmt: Cronjob
 * steht, Datenbanksicherung fehlt oder schlug fehl, Platte fast voll … (die
 * Prüfungen stehen in Camindo\Core\SystemHealth). Alles in Ordnung: kein Block.
 *
 * @see Camindo\Core\Widgets             Widgets der Module, Plätze 2 bis 4
 * @see Camindo\Core\SystemHealth        Betriebswarnungen unter den Modulkacheln
 * @see Camindo\Core\Modules\Admin\Admin  loginByName(), die eigentliche Prüfung
 * @see Camindo\Core\Session                Sitzungsverwaltung
 * @see Camindo\Core\Modules\Start\StartBgproc  Hintergrundprozess-Anzeige im Kopfbereich
 */
namespace Camindo\Core\Modules\Start;

use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\ComponentManager;
use Camindo\Core\FrontendHandover;
use Camindo\Core\AuditLog;
use Camindo\Core\Session;
use Camindo\Core\SystemHealth;
use Camindo\Core\UpdateCheck;
use Camindo\Core\Widgets;
use Camindo\Core\Modules\Admin\Admin;
use Camindo\Core\Modules\Record\Record;

class StartBackend {
    public function __construct(
        private Backend $backend,
        private Cms $cms,
        private Session $session,
        private Admin $admin,
        private Widgets $widgets,
        private Record $record,
        private SystemHealth $systemHealth,
        private AuditLog $auditLog,
        private UpdateCheck $updateCheck,
        private FrontendHandover $frontendHandover,
    )
    {}

    /**
     * Startseite des Backends (backend/start.php).
     *
     * Begrüßung mit dem angemeldeten Benutzer und darunter die Knöpfe für die
     * verfügbaren Module.
     */
    public function start(): void
    {
        // Header
        echo $this->backend->template('container_header');

        // Print login credentials
        echo $this->_loginCredentials();

        echo $this->backend->template('row_start');
        echo $this->backend->template('col_start_50');

        // H3 - "Available modules"
        echo $this->backend->template('headline2h3', [
            '{text}' => '{#start.headline_available_modules}'
        ]);

        // Module buttons
        echo $this->_moduleButtons();

        // Betriebswarnungen (Cron, Sicherung, Speicherplatz …) - nur Superuser
        echo $this->_healthWarnings();

        echo $this->backend->template('col_end');

        // Plätze 2 bis 4: die Widgets aus den persönlichen Einstellungen
        echo $this->_startWidgets();

        echo $this->backend->template('row_end');

        // Footer
        echo $this->backend->template('container_footer');
    }

    /**
     * Anmeldedialog (backend/login.php).
     *
     * Drei Zustände:
     *     ''       leeres Formular anzeigen
     *     'step1'  Name und Passwort prüfen
     *     'step2'  uid und Code der Zwei-Faktor-Anmeldung prüfen
     *
     * Bei Erfolg wird kein HTML ausgegeben, sondern nur das Session-Cookie gesetzt und
     * Javascript gesendet, das die Oberfläche weiterlaufen lässt (siehe
     * Klassenkommentar). Bei Misserfolg kommt das Formular mit der Fehlermeldung
     * zurück - welche der beiden Eingaben falsch war, verrät Admin::loginByName()
     * bewusst nicht.
     * Bei der Zwei-Faktor-Anmeldung kommt ein neues Formular für die Code-Eingabe
     *
     * Der eingegebene Name wird beim erneuten Anzeigen mit htmlspecialchars()
     * maskiert, bevor er in die Vorlage geht.
     */
    public function login() {
        // Schon angemeldet (z. B. in einem anderen Fenster, während hier noch der
        // Dialog offen stand): nicht erneut anmelden, sondern Token nachreichen und
        // den Dialog schließen (Backend::backendPath() lässt diesen Aufruf ohne Token durch)
        if ($this->session->load()) {
            $this->_loginResume();
            return;
        }

        // Seiten-Parameter
        $function = (string)($_POST['function'] ?? '');

        // Backend-Sprache, Locale und Zeitzone: aus dem Formular (step1) bzw. der Vorbelegung
        // (?lang=… aus dem localStorage / Browser, siehe Klassenkommentar)
        $backendCode = $this->cms->resolveBackendLang((string)($_POST['backend_code'] ?? $_REQUEST['lang'] ?? ''));
        $timezone = trim((string)($_POST['timezone'] ?? ''));
        if (!$this->cms->isValidTimezone($timezone)) $timezone = $this->cms->serverTZ;
        $locale = trim((string)($_POST['locale'] ?? ''));
        if (!$this->cms->isValidLocale($locale)) $locale = $this->cms->userLocale;
        // Das Formular in der gewählten Sprache
        $this->cms->setLang($backendCode);

        // Modal templates laden
        // lang/dir: die Seite dahinter wurde ohne Anmeldung in 'en_US' geladen - der
        // Dialog steht schon in der gewählten Sprache und Schreibrichtung (Cms::isRtl())
        $tplHeader = $this->backend->template('start.modal_header', [
            '{camindo_cms}' => $this->backend->template('camindo_cms'),
            '{lang}' => htmlspecialchars(str_replace('_', '-', $backendCode), ENT_QUOTES),
            '{dir}' => $this->cms->textDirection($backendCode),
        ]);
        $tplForm = $this->backend->template('start.modal_form', $this->_loginFormOptions($backendCode, $timezone, $locale));
        $tplFormEmpty = str_replace('{name}', '', $tplForm);
        $tplFooter = $this->backend->template('start.modal_footer');
        // http:// bei admin.cookie_secure = true: der Browser verwirft das Cookie -
        // Hinweis über dem Formular statt einer rätselhaft scheiternden Anmeldung
        if ($this->session->secureCookieOverHttp()) {
            $tplHeader .= str_replace('{text}', $this->cms->snippet('start.warning_cookie_secure'),
                $this->backend->template('alert_warning'));
        }

        /**
         * Formular 1
         */
        if ($function == '') {
            // Formular ohne Meldungen zeigen
            echo $tplHeader;
            echo $tplFormEmpty;
            echo $tplFooter;
            // Sprache, Locale und Zeitzone vorbelegen (localStorage, sonst Browser)
            $this->backend->script('cApp.ui.loginPrefsInit();');
        }

        /**
         * Formular 1 abgesendet
         */
        if ($function == 'step1') {
            // Formular 1 abgesendet
            // Prüfe Name + Passwort

            // Loginversuch
            $name = $_REQUEST['name'] ?? '';
            $pass = $_REQUEST['pass'] ?? '';
            // 1. Name und Passwort leer

            // Bei aktiver Zwei-Faktor-Anmeldung verschickt loginByName() eine E-Mail und
            // wirft, wenn das misslingt (kein Postfach erreichbar, kein MTA). Ohne dieses
            // catch bekäme der Benutzer an dieser Stelle eine PHP-Fehlerseite im Dialog.
            try {
                $data = $this->admin->loginByName($name, $pass);
            } catch (\Throwable $e) {
                $this->_loginFailed(
                    $tplHeader, $tplForm, $tplFooter,
                    $this->cms->snippet('start.error_login_mail_failed'),
                    $name
                );
                return;
            }

            if ($data) {
                if (empty($data['sessid']) && empty($data['totp_setup']) && empty($data['password_renew'])) {
                    // Zwei-Faktor-Anmeldung: der Code ist unterwegs (E-Mail) bzw. in
                    // der App, aber es gibt noch keine Session. Weiter mit Formular 2 -
                    // Sprache und Zeitzone reisen als versteckte Felder mit.
                    if (($data['factor'] ?? '') === 'webauthn') {
                        $this->_passkeyForm($tplHeader, $tplFooter, $data, $backendCode, $timezone, $locale);
                        return;
                    }
                    $totp = ($data['factor'] ?? '') === 'totp';
                    echo $tplHeader;
                    echo $this->backend->template('start.modal_form_code', [
                        '{text}' => $this->cms->snippet($totp ? 'start.text_login_code_totp' : 'start.text_login_code'),
                        // TOTP: auch Wiederherstellungscodes (Buchstaben, XXXXX-XXXXX)
                        '{inputmode}' => $totp ? 'text' : 'numeric',
                        '{maxlength}' => $totp ? '16' : '5',
                        '{uid}' => htmlspecialchars((string)($data['uid'] ?? ''), ENT_QUOTES),
                        '{backend_code}' => htmlspecialchars($backendCode, ENT_QUOTES),
                        '{timezone}' => htmlspecialchars($timezone, ENT_QUOTES),
                        '{locale}' => htmlspecialchars($locale, ENT_QUOTES),
                    ]);
                    echo $tplFooter;
                    $this->backend->script("cApp.ui.focus('#login-code')");
                    return;
                }

                // Session, Passwort erneuern oder TOTP einrichten
                $this->_loginNext($data, $tplHeader, $tplFooter, $backendCode, $timezone, $locale);
            }
            else {
                // Fehler-Meldungen + Formular
                $this->_loginFailed($tplHeader, $tplForm, $tplFooter, $this->cms->errorMsg(), $name);
            }
        }

        /**
         * Formular 2 abgesendet (Zwei-Faktor-Anmeldung)
         */
        if ($function == 'step2') {
            // Prüfe uid + Code
            $uid  = (string)($_POST['uid'] ?? '');
            // Passkey: Antwort des Browsers als JSON (cApp.ui.passkeyLogin()), sonst Code
            $code = (string)($_POST['passkey'] ?? '');
            if ($code === '') $code = (string)($_POST['code'] ?? '');

            if ($data = $this->admin->loginByCode($uid, $code)) {
                // Erst jetzt existiert eine Session (oder es folgt Passwort / TOTP)
                $this->_loginNext($data, $tplHeader, $tplFooter, $backendCode, $timezone, $locale);
            }
            else {
                // Die uid ist mit diesem Versuch verbraucht (siehe Klassenkommentar),
                // ein zweites Code-Formular wäre also nutzlos. Zurück zu Formular 1,
                // diesmal leer: der Benutzername stand nur in Formular 1.
                $this->_loginFailed($tplHeader, $tplFormEmpty, $tplFooter, $this->cms->errorMsg(), '');
            }
        }

        /**
         * Pflicht-Einrichtung von TOTP abgesendet (security.admin_2fa = totp_required)
         */
        if ($function == 'totp_setup') {
            $uid   = (string)($_POST['uid'] ?? '');
            $token = (string)($_POST['token'] ?? '');
            $code  = (string)($_POST['code'] ?? '');
            $data = $this->admin->loginByTotpSetup($uid, $token, $code);

            if ($data && !empty($data['retry'])) {
                // Falscher Code: derselbe QR-Code mit neuer uid, der Fehlversuch
                // zählt im LoginGuard (Admin::loginByTotpSetup())
                $this->auditLog->login('login_failed', 0, '');
                $alert = str_replace('{text}', $this->cms->errorMsg(), $this->backend->template('alert_danger'));
                $this->_totpSetupForm($tplHeader . $alert, $tplFooter, $data, $backendCode, $timezone, $locale);
            }
            elseif ($data && !empty($data['sessid'])) {
                $this->_loginNext($data, $tplHeader, $tplFooter, $backendCode, $timezone, $locale);
            }
            else {
                $this->_loginFailed($tplHeader, $tplFormEmpty, $tplFooter, $this->cms->errorMsg(), '');
            }
        }

        /**
         * Neues Passwort abgesendet (Startpasswort, abgelaufen)
         */
        if ($function == 'password_change') {
            $uid = (string)($_POST['uid'] ?? '');
            $data = $this->admin->loginByPasswordChange($uid,
                (string)($_POST['password'] ?? ''), (string)($_POST['password_repeat'] ?? ''));

            if ($data && !empty($data['retry'])) {
                // Passwort abgelehnt (Richtlinie, Wiederholung, unverändert): dasselbe
                // Formular mit neuer uid
                $alert = str_replace('{text}', $this->cms->errorMsg(), $this->backend->template('alert_danger'));
                $this->_passwordChangeForm($tplHeader . $alert, $tplFooter, $data, $backendCode, $timezone, $locale);
            }
            elseif ($data) {
                $this->_loginNext($data, $tplHeader, $tplFooter, $backendCode, $timezone, $locale);
            }
            else {
                $this->_loginFailed($tplHeader, $tplFormEmpty, $tplFooter, $this->cms->errorMsg(), '');
            }
        }
    }

    /**
     * Nächster Schritt nach einer erfolgreichen Anmeldestufe (siehe Klassenkommentar):
     * neues Passwort, TOTP einrichten oder - mit 'sessid' - fertig.
     *
     * @param array $data  Ergebnis von Admin::loginBy…()
     */
    private function _loginNext(array $data, string $tplHeader, string $tplFooter,
        string $backendCode, string $timezone, string $locale): void
    {
        if (!empty($data['password_renew'])) {
            $this->_passwordChangeForm($tplHeader, $tplFooter, $data, $backendCode, $timezone, $locale);
            return;
        }
        if (!empty($data['totp_setup'])) {
            $this->_totpSetupForm($tplHeader, $tplFooter, $data, $backendCode, $timezone, $locale);
            return;
        }
        // Session-Cookie setzen (HttpOnly, secure/SameSite aus der Konfiguration)
        $this->session->setCookie((string)$data['sessid']);
        $this->_loginComplete((int)$data['admin_id'], $backendCode, $timezone, $locale,
            (array)($data['recovery_codes'] ?? []), $tplHeader, $tplFooter);
    }

    /**
     * Zweite Stufe mit Passkey (WebAuthnFactor): Knopf "Mit Passkey bestätigen" -
     * das Script holt die Signatur vom Gerät und schickt sie im Feld 'passkey' -,
     * darunter das Feld für App-Code (wenn TOTP aktiv ist) bzw.
     * Wiederherstellungscode. Ohne Optionen (WebAuthn nicht verfügbar, keine
     * Passkeys mehr) nur das Code-Feld.
     */
    private function _passkeyForm(string $tplHeader, string $tplFooter, array $data,
        string $backendCode, string $timezone, string $locale): void
    {
        $options = $data['webauthn'] ?? null;
        $totp = !empty($data['totp_active']);
        echo $tplHeader;
        echo $this->backend->template('start.modal_form_passkey', [
            '{text}' => $this->cms->snippet(is_array($options) ? 'start.text_login_passkey' : 'start.text_login_passkey_unavailable'),
            '{passkey_block}' => is_array($options) ? $this->backend->template('start.modal_passkey_button', [
                '{options}' => htmlspecialchars(json_encode($options, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES),
            ]) : '',
            '{code_label}' => $this->cms->snippet($totp ? 'start.label_login_code_totp_or_recovery' : 'start.label_login_recovery_code'),
            '{uid}' => htmlspecialchars((string)($data['uid'] ?? ''), ENT_QUOTES),
            '{backend_code}' => htmlspecialchars($backendCode, ENT_QUOTES),
            '{timezone}' => htmlspecialchars($timezone, ENT_QUOTES),
            '{locale}' => htmlspecialchars($locale, ENT_QUOTES),
        ]);
        echo $tplFooter;
        $this->backend->script(is_array($options) ? "cApp.ui.focus('#login-passkey')" : "cApp.ui.focus('#login-code')");
    }

    /**
     * Neues Passwort im Anmeldedialog: Grund (Startpasswort / abgelaufen), zwei
     * Felder; das erste mit der Extension Password (Stärke, Anforderungen - wie in
     * der Eingabemaske des Admins). Die uid reist als verstecktes Feld mit.
     */
    private function _passwordChangeForm(string $tplHeader, string $tplFooter, array $data,
        string $backendCode, string $timezone, string $locale): void
    {
        $extClass = '';
        $extAttr = '';
        $ext = $this->backend->fieldExtension($this->admin->getSchema()['password'] ?? []);
        if ($ext !== null) {
            $extClass = ' c9-ext';
            $extAttr = ' data-c9-ext="' . htmlspecialchars($ext['name']) . '" data-c9-ext-type="password"'
                . " data-c9-ext-options='" . htmlspecialchars(json_encode($ext['options'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES) . "'";
        }
        $reason = (string)$data['password_renew'] === 'expired' ? 'expired' : 'initial';
        echo $tplHeader;
        echo $this->backend->template('start.modal_form_password_change', [
            '{text}' => $this->cms->snippet('start.text_password_change_' . $reason),
            '{ext_class}' => $extClass,
            '{ext_attr}' => $extAttr,
            '{uid}' => htmlspecialchars((string)($data['uid'] ?? ''), ENT_QUOTES),
            '{backend_code}' => htmlspecialchars($backendCode, ENT_QUOTES),
            '{timezone}' => htmlspecialchars($timezone, ENT_QUOTES),
            '{locale}' => htmlspecialchars($locale, ENT_QUOTES),
        ]);
        echo $tplFooter;
        $this->backend->script("cApp.extensions && cApp.extensions.refreshAll($('#login-dialog')); cApp.ui.focus('#login-new-password')");
    }

    /**
     * TOTP im Anmeldedialog einrichten: QR-Code, Secret zum Abtippen, Code-Feld.
     * Das Setup-Token (verschlüsseltes Secret, TotpFactor::newSetup()) und die uid
     * reisen als versteckte Felder mit - gespeichert ist noch nichts.
     *
     * @param array $data  Ergebnis von Admin::loginByName()/loginByCode()/loginByTotpSetup()
     *                     mit 'uid' und 'totp_setup'
     */
    private function _totpSetupForm(string $tplHeader, string $tplFooter, array $data,
        string $backendCode, string $timezone, string $locale): void
    {
        $setup = (array)$data['totp_setup'];
        echo $tplHeader;
        echo $this->backend->template('start.modal_form_totp_setup', [
            '{uri}' => htmlspecialchars((string)$setup['uri'], ENT_QUOTES),
            '{secret}' => htmlspecialchars((string)$setup['secret'], ENT_QUOTES),
            '{token}' => htmlspecialchars((string)$setup['token'], ENT_QUOTES),
            '{uid}' => htmlspecialchars((string)($data['uid'] ?? ''), ENT_QUOTES),
            '{backend_code}' => htmlspecialchars($backendCode, ENT_QUOTES),
            '{timezone}' => htmlspecialchars($timezone, ENT_QUOTES),
            '{locale}' => htmlspecialchars($locale, ENT_QUOTES),
        ]);
        echo $tplFooter;
        $this->backend->script("cApp.ui.qrCodes(); cApp.ui.focus('#login-code')");
    }

    /**
     * Abmeldung (backend/logout.php).
     *
     * Zwei Zustände:
     *     ''         Rückfrage "Wirklich abmelden?" als Modal zeigen
     *     'confirm'  abmelden
     */
    public function logout(): void
    {
        // Seiten-Parameter
        $function = (string)($_POST['function'] ?? '');

        if ($function === 'confirm') $this->_logout();
        else $this->_logoutForm();
    }

    /**
     * Rückfrage vor dem Abmelden.
     *
     * Ausgegeben werden - wie beim Anmelden - die Teile eines Modals, die das
     * Javascript (cApp.layers.logout()) in denselben Dialog (#login-modal) lädt.
     * Das Formular schickt sich mit 'confirm' an dieses Script zurück; der
     * Abbrechen-Knopf schließt das Modal (data-bs-dismiss) und lässt die
     * Oberfläche unberührt.
     */
    private function _logoutForm(): void
    {
        echo $this->backend->template('start.modal_logout_header');
        echo $this->backend->template('start.modal_logout_form');
        echo $this->backend->template('start.modal_footer');
    }

    /**
     * Logout durchführen.
     *
     * Vor dem Schließen der Session werden die Arbeitskopien verworfen - das gibt
     * die Bearbeitungssperren frei. Danach fehlte die Session-Admin-Id, an der
     * Record::cancel() den Besitz prüft.
     *
     *     ohne Häkchen          nur dieser Browser: die Arbeitskopien DIESER Session
     *                           (Record::cancelSession()); offene Masken auf anderen
     *                           Geräten bleiben
     *     "auf allen Geräten"   alle Arbeitskopien des Admins (Record::cancelAll())
     *                           und alle seine Sessions (Session::closeAllOf())
     *
     * Ungespeicherte Eingaben in den betroffenen Masken sind damit verloren; das
     * automatische Abmelden nach admin.max_inactive_sec läuft nicht hier durch
     * und lässt die Kopien stehen (ihre Sperre verfällt nach derselben Zeit).
     */
    private function _logout(): void
    {
        $adminId = (int)$this->session->get('admin_id');
        $allDevices = !empty($_POST['all_devices']);

        // Bearbeitungssperren freigeben
        if ($allDevices) {
            $this->record->cancelAll();
        }
        else {
            $this->record->cancelSession((string)$this->session->get('session_id'));
        }

        // Audit-Log - solange die Session den Admin noch kennt
        $this->auditLog->login('logout', $adminId);

        // Sessions aus der Datenbank löschen (die übrigen zuerst: close() entlädt die Rechte)
        if ($allDevices) {
            $this->session->closeAllOf($adminId, keepCurrent: true);
        }
        $this->session->close();

        // Frontend-Editing "bis zur Abmeldung" (Cookie 'fed' = 1) endet hier;
        // 2 (dauerhaft) bleibt bestehen - siehe Backend::setFedCookie()
        if ($this->frontendHandover->fedMode((string)($_COOKIE[$this->cms::FED_COOKIE] ?? '')) === 1) {
            $this->backend->setFedCookie(0);
        }

        // Komplettes Fenser neu laden
        $this->backend->script("window.location.href='/backend/index.php';");
    }

    /**
     * Anmeldedialog bei bereits bestehender Session (login()): CSRF-Token der
     * Session nachreichen, Layer nachladen, Dialog schließen - wie nach einer
     * Anmeldung, aber ohne Einstellungen zu speichern oder ins Audit-Log zu
     * schreiben. Sprache und Locale aus den gespeicherten Einstellungen.
     */
    private function _loginResume(): void
    {
        $settings = $this->backend->adminSettings();
        $code = json_encode((string)($settings['backend_code'] ?? ''));
        $loc = json_encode((string)($settings['locale'] ?? ''));
        $token = json_encode($this->session->csrfToken());
        $this->backend->script("cApp.system.setCsrfToken({$token}); cApp.layers.loginComplete({$code}, {$loc});");
    }

    /**
     * Anmeldung abgeschlossen: Wahl von Sprache, Locale und Zeitzone als persönliche
     * Einstellung speichern, Cookie des Frontend-Editings setzen und die Oberfläche
     * weiterlaufen lassen.
     *
     * Das Speichern darf die Anmeldung nicht scheitern lassen - misslingt es, bleibt
     * die bisherige Einstellung. Die Session hat Admin::hookOnSaved() bereits
     * nachgezogen, die neue Sprache gilt also ab dem nächsten Request; das
     * Javascript lädt das Backend neu, wenn die Kopfzeile noch in einer anderen
     * Sprache steht (cApp.layers.loginComplete()).
     *
     * @param int    $adminId
     * @param string $backendCode  gewählte Backend-Sprache (bereits aufgelöst)
     * @param string $timezone     gewählte Zeitzone (bereits geprüft)
     * @param string $locale       gewählte Locale (bereits geprüft)
     * @param array  $recoveryCodes  nach der Pflicht-Einrichtung von TOTP: die
     *                               Wiederherstellungscodes - der Dialog zeigt sie
     *                               und schließt sich erst mit "Weiter"
     */
    private function _loginComplete(int $adminId, string $backendCode, string $timezone, string $locale,
        array $recoveryCodes = [], string $tplHeader = '', string $tplFooter = ''): void
    {
        $this->admin->saveSettings($adminId, [
            'backend_code' => $backendCode,
            'timezone'     => $timezone,
            'locale'       => $locale,
        ]);
        $this->cms->errorsReset();
        $this->auditLog->login('login', $adminId);

        // Frontend-Editing laut gespeicherter Einstellung (Cookie dieses Browsers)
        $settings = $this->backend->adminSettings();
        $this->backend->setFedCookie((int)($settings['fed'] ?? 0));

        // JavaScript senden: CSRF-Token der neuen Session nachreichen, Wahl für das
        // nächste Anmeldeformular merken, Inhalte leer gebliebener Layer nachladen und
        // Login-Dialog schließen.
        //
        // Das Token MUSS zuerst kommen: die Seite wurde ohne Session geladen, in
        // cConfig steht also noch ein leeres Token - und die Layer, die gleich
        // nachladen, sollen schon das neue mitschicken (17-csrf.md §2.4).
        $code = json_encode($backendCode);
        $tz = json_encode($timezone);
        $loc = json_encode($locale);
        $token = json_encode($this->session->csrfToken());
        $complete = "cApp.ui.loginPrefsStore({$code}, {$tz}, {$loc}); cApp.layers.loginComplete({$code}, {$loc});";

        if ($recoveryCodes) {
            // Codes zeigen; weiter geht es erst mit dem Knopf
            echo $tplHeader;
            echo $this->backend->template('start.modal_recovery_codes', [
                '{codes}' => $this->_recoveryCodesHtml($recoveryCodes),
                '{onclick}' => $this->backend->on('click', $complete),
            ]);
            echo $tplFooter;
            $this->backend->script("cApp.system.setCsrfToken({$token});");
            return;
        }
        $this->backend->script("cApp.system.setCsrfToken({$token}); " . $complete);
    }

    /**
     * Liste der Wiederherstellungscodes mit Kopierknopf (Vorlage wie in den
     * persönlichen Einstellungen, AdminBackend::totp()).
     */
    private function _recoveryCodesHtml(array $codes): string
    {
        $items = '';
        foreach ($codes as $code) {
            $items .= '<li>' . htmlspecialchars((string)$code) . "</li>\n";
        }
        return $this->backend->template('admin.totp_recovery_codes', [
            '{items}' => $items,
            '{copy}' => htmlspecialchars(implode("\n", $codes), ENT_QUOTES),
        ]);
    }

    /**
     * Auswahllisten des Anmeldeformulars: Backend-Sprachen, Locales und Zeitzonen,
     * mit der jeweils aktuellen Wahl als selected.
     *
     * @return array  Ersetzungen für die Vorlage start.modal_form
     */
    private function _loginFormOptions(string $backendCode, string $timezone, string $locale): array
    {
        return [
            '{lang_options}' => $this->backend->options($this->backend->backendLanguageOptions(), $backendCode),
            '{timezone_options}' => $this->backend->options($this->backend->timezoneOptions(), $timezone),
            '{locale_options}' => $this->backend->options($this->backend->localeOptions(), $locale),
        ];
    }

    /**
     * Fehlgeschlagene Anmeldung: Meldung über dem Anmeldeformular ausgeben.
     *
     * @param string $tplForm  Formularvorlage - mit oder ohne {name}-Platzhalter
     * @param string $message  Fertiger Meldungstext (HTML)
     * @param string $name     Eingegebener Benutzername, wird maskiert eingesetzt
     */
    private function _loginFailed(string $tplHeader, string $tplForm, string $tplFooter, string $message, string $name): void
    {
        // Audit-Log: der eingegebene Name (bei einem falschen Code leer)
        $this->auditLog->login('login_failed', 0, mb_substr(trim($name), 0, 64));
        echo $tplHeader;
        $tplAlert = $this->backend->template('alert_danger');
        echo str_replace('{text}', $message, $tplAlert);
        echo str_replace('{name}', htmlspecialchars($name), $tplForm);
        echo $tplFooter;
        $this->backend->script("cApp.ui.focus('#login-password')");
    }

    /**
     * Begrüßung mit dem Namen des angemeldeten Benutzers.
     *
     * Der Name kommt aus der Session (dort unter 'details' abgelegt), nicht aus einer
     * erneuten Datenbankabfrage.
     */
    private function _loginCredentials(): string
    {
        $adminData = $this->session->get('details');
        $loginName = $adminData['name'] ?? '';
        return strtr($this->backend->template('start.login_credentials'), [
            '{username}' => "<strong>$loginName</strong>"
        ]);
    }

    /**
     * Knöpfe der verfügbaren Module, nach 'position' aus ihrer settings.php (je höher,
     * desto weiter unten), bei gleichem Wert alphabetisch nach ihrer Beschriftung.
     *
     * Quelle ist Cms::$modules aus var/config/components/modules.php - nur Module mit
     * 'listed' und ohne 'button' => false in ihrer settings.php kommen vor, dazu nicht
     * gelistete mit 'button' => true (jeder Gruppe offen, z. B. Trash). Der Modulname wird kleingeschrieben
     * zum Snippet- und Vorlagen-Präfix ({modul}.module_label, {modul}.module_button).
     *
     * Sortiert wird über den Array-Schlüssel "{position}|{Beschriftung}": ksort()
     * bringt die Knöpfe in die Reihenfolge, die der Redakteur sieht - und nicht in
     * die des Verzeichnisses. (Zwei Module mit identischem Sortierwert und identischer
     * Beschriftung würden sich dabei allerdings gegenseitig verdrängen.)
     */
    private function _moduleButtons(): string
    {
        $moduleList = [];
        foreach ($this->_moduleEntries() as $sortKey => $entry) {
            $moduleList[$sortKey] = $this->backend->template("module_button", [
                '{icon}' => $entry['icon'] ?? 'draft',
                '{text}' => $entry['label'],
                '{onclick}' => $entry['onclick'],
            ]);
        }

        // Button Bar Header
        $tplButtonBar = explode('{buttons}', $this->backend->template('button_bar'));

        // Print buttons, sorted by module label
        return $tplButtonBar[0] . implode("\n", $moduleList) . $tplButtonBar[1];
    }

    /**
     * Die Module, die der Admin öffnen darf, sortiert wie auf der Startseite -
     * gemeinsame Quelle für die Kacheln (_moduleButtons()) und das Hauptmenü (menu()).
     *
     * @return array  "{position}|{Beschriftung}" => ['prefix', 'label', 'onclick']
     */
    private function _moduleEntries(): array
    {
        $moduleList = [];
        $access = $this->backend->access();
        foreach ($this->cms->modules as $moduleName => $moduleSettings) {
            $prefix = strtolower($moduleName);
            // Modul settings 'listed' gesetzt? Nicht gelistete Module (ohne
            // Rechteverwaltung) bekommen nur mit ausdrücklichem 'button' => true
            // eine Kachel - z. B. der Papierkorb, den jeder Admin hat
            if (empty($moduleSettings['listed']) && ($moduleSettings['button'] ?? false) !== true) continue;
            // Modul ohne Kachel ('button' => false): nur als Widget zu haben
            if (array_key_exists('button', $moduleSettings) && empty($moduleSettings['button'])) continue;
            // Zugriff der Benutzergruppe?
            if (!$access->module($moduleName)) continue;
            // Einstiegsscript: das erste aus settings.php 'entries' (Standard index.php),
            // dessen Zusatzrecht ('script_access') die Gruppe hat - keines: keine Kachel
            $entry = '';
            foreach ((array)($moduleSettings['entries'] ?? ['index.php']) as $candidate) {
                $right = (string)($moduleSettings['script_access'][$candidate] ?? '');
                if ($right === '' || $access->moduleRight($moduleName, $right)) {
                    $entry = (string)$candidate;
                    break;
                }
            }
            if ($entry === '') continue;

            // Position + Label als Sortierschlüssel
            $moduleLabel = $this->cms->snippet("{$prefix}.module_label");
            $sortKey = sprintf('%011d|%s', ComponentManager::position($moduleSettings) + 1000000000, $moduleLabel);
            $moduleList[$sortKey] = [
                'prefix' => $prefix,
                'label' => $moduleLabel,
                'icon' => $moduleSettings['icon'] ?? 'draft',
                'onclick' => "cApp.layers.add('{$moduleName}/{$entry}');",
            ];
        }
        ksort($moduleList);
        return $moduleList;
    }

    /**
     * Inhalt des Hauptmenüs (backend/menu.php) - bei jedem Öffnen neu geladen
     * (cApp.ui._initMainMenu()), damit er den Rechten der laufenden Session folgt:
     *
     *     Info, Handbücher
     *     ---
     *     die Module der Gruppe (wie die Kacheln der Startseite)
     *     ---
     *     Einstellungen, Seitenleiste schließen, Abmelden
     */
    public function menu(): void
    {
        $item = fn(string $label, string $code, string $icon = '') => $this->backend->template('start.menu_item', [
            '{js}' => $this->backend->escape($code),
            '{icon}' => $icon !== '' ? $this->backend->template('icon', ['{name}' => $this->backend->escape($icon)]) . ' ' : '',
            '{label}' => $this->backend->escape($label),
        ]);
        $divider = $this->backend->template('start.menu_divider');

        $html = $item($this->cms->snippet('start.menu_info'), "cApp.layers.addOnce('Start/info.php');", 'info');
        $html .= $item($this->cms->snippet('docs.module_label'), "cApp.layers.addOnce('Docs/index.php');", 'menu_book');
        $modules = $this->_moduleEntries();
        if ($modules) {
            $html .= $divider;
            foreach ($modules as $entry) {
                $html .= $item($entry['label'], $entry['onclick'], $entry['icon'] ?? 'draft');
            }
        }
        echo $this->backend->template('start.menu', ['{items}' => $html]);
    }

    /**
     * Über camindo CMS (backend/info.php): Vorlage camindo_info mit der installierten
     * Version und - nur für Superuser - dem Ergebnis der Update-Prüfung.
     *
     *     ''       anzeigen (gespeichertes Ergebnis, UpdateCheck::result())
     *     'check'  POST: jetzt prüfen (composer outdated / audit, einige Sekunden)
     */
    public function info(): void
    {
        $function = (string)($_REQUEST['function'] ?? '');
        $this->backend->setNaviLabel('#start.menu_info');
        echo $this->backend->template('container_header');

        $update = '';
        if ($this->backend->isSuperuser()) {
            if ($function === 'check' && $this->backend->requirePost()) {
                $this->updateCheck->run();
            }
            $update = $this->_updateInfo();
        }
        echo $this->backend->template('camindo_info', [
            '{version}' => htmlspecialchars(defined('CAMINDO_VERSION') ? CAMINDO_VERSION : ''),
            '{check_for_update}' => $update,
        ]);

        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_close', "cApp.layers.close()");
        echo $this->backend->buttonBar();
        echo $this->backend->template('container_footer');
    }

    /**
     * Ergebnis der Update-Prüfung als Block der Info-Seite (siehe info()).
     */
    private function _updateInfo(): string
    {
        $result = $this->updateCheck->result();
        $alert = fn(string $level, string $text) => $this->backend->template('alert_' . $level, ['{text}' => $text]);
        $alerts = '';
        $table = '';
        if (!$this->updateCheck->enabled()) {
            $checked = htmlspecialchars($this->cms->snippet('start.text_update_disabled'));
        }
        elseif (!$result || empty($result['checked_at'])) {
            $checked = htmlspecialchars($this->cms->snippet('start.text_update_never'));
        }
        else {
            $checked = htmlspecialchars(strtr($this->cms->snippet('start.text_update_checked'), [
                '{date}' => $this->backend->backendValue($this->cms->utcToUserTZ((string)$result['checked_at']), ['type' => 'timestampl'], 'medium'),
            ]));
            if ((string)($result['error'] ?? '') !== '') {
                $alerts .= $alert('warning', htmlspecialchars($this->cms->snippet('start.text_update_error')) . '<br><small>' . htmlspecialchars((string)$result['error']) . '</small>');
            }
            // Sicherheitslücken zuerst
            if (!empty($result['advisories'])) {
                $items = '';
                foreach ($result['advisories'] as $advisory) {
                    $title = htmlspecialchars(trim($advisory['package'] . ': ' . $advisory['title']
                        . ($advisory['cve'] !== '' ? ' (' . $advisory['cve'] . ')' : '')
                        . ($advisory['severity'] !== '' ? ' – ' . $advisory['severity'] : '')));
                    $link = preg_match('~^https://~', (string)$advisory['link'])
                        ? ' <a href="' . htmlspecialchars((string)$advisory['link']) . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($this->cms->snippet('start.link_update_advisory')) . '</a>'
                        : '';
                    $items .= '<li>' . $title . $link . '</li>';
                }
                $alerts .= $alert('danger', $this->backend->template('start.update_advisories', ['{items}' => $items]));
            }
            if (!empty($result['core']['latest'])) {
                $alerts .= $alert('warning', htmlspecialchars(strtr($this->cms->snippet('start.text_update_core'), ['{version}' => (string)$result['core']['latest']])));
            }
            foreach ((array)($result['abandoned'] ?? []) as $abandoned) {
                $alerts .= $alert('warning', htmlspecialchars(strtr($this->cms->snippet('start.text_update_abandoned'), [
                    '{package}' => (string)$abandoned['package'],
                    '{replacement}' => (string)$abandoned['replacement'] ?: '–',
                ])));
            }
            if (!empty($result['outdated'])) {
                $rows = '';
                foreach ($result['outdated'] as $package) {
                    $kind = $this->cms->snippet($package['direct'] ? 'start.label_update_direct' : 'start.label_update_dependency');
                    if ($package['status'] === 'update-possible') $kind .= ' · ' . $this->cms->snippet('start.label_update_major');
                    $rows .= '<tr><td>' . htmlspecialchars((string)$package['name']) . '</td><td>'
                        . htmlspecialchars((string)$package['version']) . '</td><td>'
                        . htmlspecialchars((string)$package['latest']) . '</td><td>'
                        . htmlspecialchars($kind) . "</td></tr>\n";
                }
                $table = $this->backend->template('start.update_table', ['{table_rows}' => $rows]);
            }
            elseif ($alerts === '') {
                $alerts .= $alert('success', htmlspecialchars($this->cms->snippet('start.text_update_current')));
            }
        }
        $button = $this->updateCheck->enabled()
            ? $this->backend->template('button_secondary', [
                '{onclick}' => "cApp.layers.replacePost('Start/info.php?function=check')",
                '{label}' => $this->cms->snippet('start.button_update_check'),
            ])
            : '';
        return $this->backend->template('start.update_info', [
            '{checked}' => $checked,
            '{alerts}' => $alerts,
            '{table}' => $table,
            '{button}' => $button,
        ]);
    }

    /**
     * Die Plätze 2 bis 4 der Startseite (siehe Klassenkommentar).
     *
     * Je Platz stehen in den persönlichen Einstellungen beliebig viele Module;
     * Widgets::render() macht daraus die Karten oder gibt leer zurück - etwa, wenn
     * ein Modul inzwischen fehlt oder die Gruppe es nicht mehr darf. Ein leerer
     * Platz bekommt trotzdem seine Spalte, damit die Anordnung nicht springt.
     *
     * Ist überhaupt nichts eingestellt, steht im zweiten Platz der Hinweis, wo sich
     * das ändern lässt - sonst bliebe die halbe Startseite ohne Erklärung leer.
     */
    /**
     * Betriebswarnungen unter den Modulkacheln (siehe Klassenkommentar).
     */
    private function _healthWarnings(): string
    {
        if (!$this->backend->isSuperuser()) return '';
        $warnings = $this->systemHealth->warnings();
        if (!$warnings) return '';

        $rows = '';
        foreach ($warnings as $warning) {
            $action = (string)$warning['action'];
            $rows .= $this->backend->template($action !== '' ? 'start.health_row_link' : 'start.health_row', [
                '{level}'  => htmlspecialchars((string)$warning['level']),
                '{icon}'   => $warning['level'] === 'info' ? 'info' : ($warning['level'] === 'danger' ? 'error' : 'warning'),
                '{text}'   => htmlspecialchars((string)$warning['text']),
                '{onclick}' => $action,
            ]);
        }
        return $this->backend->template('start.health_block', ['{rows}' => $rows]);
    }

    private function _startWidgets(): string
    {
        $byPosition = $this->widgets->byPosition($this->backend->adminSettings());

        $columns = [];
        foreach (Widgets::POSITIONS as $position) {
            $cards = '';
            foreach ($byPosition[$position] ?? [] as $module) {
                $cards .= $this->widgets->render($module);
            }
            $columns[] = $cards;
        }

        // Kein einziges Widget: Hinweis auf die Einstellungen in den ersten Platz
        if (implode('', $columns) === '') {
            $columns[0] = $this->backend->template('start.widget_hint');
        }

        $html = '';
        foreach ($columns as $column) {
            $html .= $this->backend->template('col_start_50')
                . $column
                . $this->backend->template('col_end');
        }
        return $html;
    }
}