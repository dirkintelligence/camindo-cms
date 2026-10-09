<?php
/**
 * Dataset-Kindklasse für die Tabelle `admin` - die Backend-Benutzer.
 *
 * "Administrator" heißt in camindo jeder Benutzer mit Anmelderecht am Backend, unabhängig
 * von seinen Berechtigungen. Nicht zu verwechseln mit "User": das sind die Besucher des
 * Frontends (siehe Camindo\Core\Modules\Record\RecordUser).
 *
 * Neben den üblichen Dataset-Aufgaben enthält die Klasse die Anmeldung selbst -
 * loginByName() und, für die Zwei-Faktor-Anmeldung, loginByCode().
 *
 * SCHUTZ DER GESPEICHERTEN DATEN
 * --------------------
 *     password              Feldtyp 'password' -> Crypt::passwordHash(), 64 Zeichen mit
 *                           eingebettetem Salt. Nicht umkehrbar; nur passwordVerify()
 *                           kann vergleichen.
 *     sig                   Signatur über admin_id, name, password, group_id,
 *                           status_id und second_factor (Camindo\Core\Integrity).
 *                           hookOnSaved() schreibt sie nach jedem Speichern; die
 *                           Anmeldung und jeder Backend-
 *                           Request (Session::load()) prüfen sie. Eine per SQL
 *                           veränderte Zeile ist damit wertlos.
 *     email, mobile, phone  Feldtyp 'encrypted' -> Crypt::encrypt(), varbinary-Spalten.
 *                           Umkehrbar, aber nur exakt durchsuchbar (data_enc-Prinzip).
 *     uid                   Zufallsschlüssel für die Zwei-Faktor-Anmeldung.
 *     second_factor,        Authenticator-App (TotpFactor): nicht im Schema, save()
 *     totp_*                schreibt sie nie, hookOnLoaded() nimmt Secret und Schritt
 *                           aus load() heraus. Secret verschlüsselt an die Zeile
 *                           gebunden, second_factor in der Signatur; dazu die Tabelle
 *                           admin_recovery_code (gehashte Wiederherstellungscodes).
 *     ft                    Volltextspalte, gespeist aus name, displayname, primename
 *                           und sortname (siehe source_fields im Schema).
 *
 * BERECHTIGUNGEN
 * --------------------
 * Über group_id, Gruppe 1 ist der Superuser. Die Gruppen selbst liest derzeit
 * Backend::getGroups() mit direktem SQL - die Klasse Group ist noch ein leeres Gerüst.
 *
 * PERSÖNLICHE EINSTELLUNGEN (`settings`, JSON)
 * --------------------
 *     language_id    bevorzugte Sprachversion: der Reiter, den die Eingabemaske eines
 *                    Datensatzes zuerst öffnet (area.details.tabs); '' = Hauptsprache
 *     backend_code   Backend-Sprache der Snippets (Cms::backendLanguages())
 *     locale         ICU-Locale der Datums-, Zeit- und Zahlenformate (Cms::userLocale)
 *     timezone       Zeitzone für Datums- und Zeitangaben (Cms::userTZ)
 *     fed            Frontend-Editing: 0 aus, 1 bis Browser-Ende/Logout, 2 dauerhaft
 *                    (Cookie 'fed', siehe Cms::fedActive())
 *     widgets        Widgets der Startseite als Liste
 *                    [['module' => 'Logfiles', 'position' => 2], …] - in einen Platz
 *                    passen mehrere, Platz 1 sind die Modulkacheln
 *                    (Camindo\Core\Widgets)
 *     reports        abonnierte Berichte des Zeitplaners (scheduler_ids)
 *     message_digest Zusammenfassung der internen Nachrichten per E-Mail: 0 aus,
 *                    1 täglich (Zeitplaner-Aufgabe messagedigest, MessageDigest)
 * Gepflegt über AdminBackend::settings() (rechts oben im Backend bzw. als Reiter in
 * der Detailansicht - dort stellt ein Superuser sie für andere Admins ein) und über
 * das Anmeldeformular (Backend-Sprache, Locale, Zeitzone). Die Werte liegen in den
 * Session-Details und gelten ab Session::load() für jeden Request
 * (Cms::applyAdminSettings()); hookOnSaved() schreibt sie in laufende Sessions nach.
 *
 * SCHUTZ GEGEN DURCHPROBIEREN
 * --------------------
 * loginByName() fragt vor jeder Prüfung LoginGuard, ob der eingegebene Name gerade
 * gesperrt ist, und meldet jeden Fehlversuch dorthin zurück. Gezählt wird der
 * eingegebene Name - auch einer, den es gar nicht gibt: sonst verriete das Verhalten
 * der Anmeldung, welche Konten existieren. Einstellungen: admin.bruteforce_max,
 * admin.bruteforce_delay, admin.bruteforce_ip.
 *
 * Bei genau bruteforce_max Fehlversuchen ist noch ein letzter Versuch frei, und die
 * Meldung sagt das auch. Erst darüber wird gesperrt.
 *
 * Bekanntes Gerät (Geräte-Cookie, LoginDevices): Fehlversuche zählen dann auf einen
 * eigenen Schlüssel je Konto und Gerät (guardKey()), nicht auf den Namen. Wer den
 * Namen von außen absichtlich sperrt, sperrt damit nicht den Inhaber an seinem
 * eigenen Rechner aus; ein kopiertes Cookie bekommt ebenfalls nur bruteforce_max
 * Versuche. Die Drosselung je IP (admin.throttle.login_ip) gilt für alle.
 *
 * ZWEI-FAKTOR-ANMELDUNG
 * --------------------
 * loginByName() legt nach dem richtigen Passwort eine uid an und startet den zweiten
 * Faktor des Admins (secondFactor(), SecondFactorInterface), ohne schon eine Session
 * zu erzeugen; loginByCode() prüft den eingegebenen Code und meldet erst dann an.
 *
 *     TotpFactor   Authenticator-App (RFC 6238) oder Wiederherstellungscode - wenn
 *                  der Admin TOTP eingerichtet hat und security.admin_2fa nicht
 *                  'email' ist
 *     EmailFactor  fünfstelliger Code per E-Mail - sonst, wenn admin.2_factor an ist
 *
 * Das Zeitfenster steht in admin.2_factor_expiration. Jede uid ist genau einen
 * Bestätigungsversuch wert - danach wird sie neu gewürfelt, gleich ob der Code
 * stimmte. Ein falscher Code zählt zusätzlich im LoginGuard auf den Namen des
 * Kontos, und ein richtiges Passwort setzt den Zähler nicht mehr zurück - erst die
 * vollständige Anmeldung. Sonst ließe sich ein sechsstelliger Code mit Passwort und
 * Code im Wechsel beliebig oft raten.
 *
 * Mit security.admin_2fa = totp_required müssen Superuser TOTP haben. Fehlt es,
 * endet die Anmeldung nicht mit einer Session, sondern mit dem Einrichten im
 * Anmeldedialog (_loginComplete() -> 'totp_setup', loginByTotpSetup()).
 *
 * Unabhängig von admin.2_factor kann ein Admin den E-Mail-Code für sich selbst
 * wählen (admin.second_factor = 'email', eingerichtet in AdminBackend::totp()) -
 * auch als Rückweg von der Authenticator-App.
 *
 * PASSWORT ÄNDERN MÜSSEN
 * --------------------
 * Vor der Session prüft _loginComplete(), ob das Passwort erneuert werden muss:
 *
 *     password_change = 1        ein anderer Admin hat es gesetzt (Anlegen,
 *                                Zurücksetzen durch den Superuser) - hookOnSaved()
 *     password_changed_at alt    älter als admin.password_retention_days
 *
 * Dann gibt es statt der Session eine frische uid und 'password_renew'; der
 * Anmeldedialog fragt das neue Passwort ab (loginByPasswordChange()). Es muss der
 * Richtlinie genügen und sich vom bisherigen unterscheiden. Erst danach folgen -
 * falls nötig - die Pflicht-Einrichtung von TOTP und die Session.
 *
 * ANMELDEGERÄTE
 * --------------------
 * Nach jeder vollständigen Anmeldung (mit Session) verbucht LoginDevices das Gerät;
 * ein unbekanntes Gerät eines Kontos, das schon Geräte hat, löst eine E-Mail aus
 * (security.device_notify, security.login_notifier). Die Zwei-Faktor-Stufe bleibt
 * auch auf bekannten Geräten.
 *
 * @see Camindo\Core\Modules\Admin\AdminBackend  Backend-Oberfläche
 * @see Camindo\Core\Session                     Sitzungsverwaltung
 * @see Camindo\Core\Dataset
 */
namespace Camindo\Core\Modules\Admin;

use Camindo\Core\Access;
use Camindo\Core\ClientIp;
use Camindo\Core\Cms;
use Camindo\Core\Dataset;
use Camindo\Core\Integrity;
use Camindo\Core\LoginDevices;
use Camindo\Core\LoginGuard;
use Camindo\Core\RateLimiter;
use Camindo\Core\Widgets;

/**
 * @property-read EmailFactor $emailFactor  Nachgeladen, siehe $lazyServices
 * @property-read TotpFactor $totpFactor    Nachgeladen, siehe $lazyServices
 * @property-read WebAuthnFactor $webAuthnFactor  Nachgeladen, siehe $lazyServices
 * @property-read LoginGuard $loginGuard  Nachgeladen, siehe $lazyServices
 * @property-read Integrity $integrity    Nachgeladen, siehe $lazyServices
 * @property-read Widgets $widgets        Nachgeladen, siehe $lazyServices
 * @property-read LoginDevices $loginDevices  Nachgeladen, siehe $lazyServices
 * @property-read RateLimiter $rateLimiter  Nachgeladen, siehe $lazyServices
 * @property-read ClientIp $clientIp      Nachgeladen, siehe $lazyServices
 */
class Admin extends Dataset {

    // Nur bei der Anmeldung bzw. beim Speichern gebraucht - deshalb nicht im
    // Konstruktor, sondern erst beim ersten Zugriff aus dem Container geholt.
    // @see Camindo\Core\LazyServicesTrait
    protected array $lazyServices = [
        'emailFactor' => EmailFactor::class,
        'totpFactor' => TotpFactor::class,
        'webAuthnFactor' => WebAuthnFactor::class,
        'loginGuard' => LoginGuard::class,
        'integrity' => Integrity::class,
        'widgets' => Widgets::class,
        'loginDevices' => LoginDevices::class,
        'rateLimiter' => RateLimiter::class,
        'clientIp' => ClientIp::class,
    ];

    // Audit-Log: Anmeldecode und -zeitpunkt, Signatur und persönliche Oberflächen-
    // Einstellungen gehören nicht ins Protokoll (Passwort: nur "geändert")
    protected array $auditIgnore = ['uid', 'uid_date', 'code', 'login_date', 'settings'];

    // changePassword(): der Admin setzt sein eigenes Passwort (auch im Anmeldedialog,
    // wo es noch keine Session gibt) - kein erneuter Änderungszwang
    private bool $passwordChangedBySelf = false;

    protected function hookDefinition(): void
    {
        $this->tableName  = 'admin';
        $this->moduleName = 'Admin';
        $this->defaultSortOrder = 'name ASC';
        $this->schema = [
            'admin_id' => [
                'type'  => 'integer',
                'label' => 'admin_id',
                'min' => 1
            ],
            'group_id' => [
                'type'  => 'integer',
                'label' => '#admin.field_admin_group_id',
                'display_type' => 'select',
                'min' => 1
            ],
            'name' => [
                'type'  => 'chars',
                'label' => '#admin.field_admin_name',
                'max'   => 64,
                'flags' => ['trim', 'not_empty', 'name', 'unique']
            ],
            'password' => [
                'type'  => 'password',
                'label' => '#admin.field_admin_password',
                'display_type' => 'password',
                // Länge der Eingabe; die eigentlichen Anforderungen stellt die
                // Extension Password (security.password_policy, auch serverseitig)
                'min'   => 8,
                'max'   => 128,
                'flags' => ['not_empty'],
                'extension' => 'Password',
            ],
            'uid' => [
                'type'  => 'chars',
                'label' => '#admin.field_admin_uid',
                'max'   => 43
            ],
            'uid_date'  => [
                'type'  => 'timestampl',
                'label' => '#admin.field_admin_uid_date',
            ],
            'code' => [
                'type'  => 'chars',
                'label' => '#admin.field_admin_code',
                'max'   => 5
            ],
            'primename' => [
                'type'  => 'text',
                'label' => '#admin.field_admin_primename',
                'flags' => ['trim']
            ],
            'displayname' => [
                'type'  => 'text',
                'label' => '#admin.field_admin_displayname',
                'flags' => ['trim']
            ],
            'sortname' => [
                'type'  => 'text',
                'label' => '#admin.field_admin_sortname',
                'flags' => ['trim']
            ],
            'email' => [
                'type'  => 'encrypted',
                'label' => '#admin.field_admin_email',
                'display_type' => 'email',
                'max'   => 255,
                'flags' => ['trim']
            ],
            'mobile' => [
                'type'  => 'encrypted',
                'label' => '#admin.field_admin_mobile',
                'max'   => 255,
                'flags' => ['trim']
            ],
            'phone' => [
                'type'  => 'encrypted',
                'label' => '#admin.field_admin_phone',
                'max'   => 255,
                'flags' => ['trim']
            ],
            'status_id' => [
                'type'  => 'integer',
                'label' => '#admin.field_admin_status_id',
                'display_type' => 'select',
            ],
            'ft' => [
                'type'  => 'fulltext',
                'label' => '#admin.field_admin_ft',
                'source_fields' => ['name', 'display_name', 'primename', 'sortname']
            ],
            // Persönliche Einstellungen - siehe Klassenkommentar. Die Subfelder werden
            // nur geprüft, wenn 'settings' in $fields steht (AdminBackend::settings()).
            'settings' => [
                'type'  => 'json',
                'label' => '#admin.field_admin_settings',
            ],
            'settings.language_id' => [
                'type'  => 'chars',
                'display_type' => 'select',
                'label' => '#admin.field_admin_settings_language_id',
                'tooltip' => '#admin.tooltip_settings_language_id',
                'max'   => 11,
                'flags' => ['trim'],
            ],
            'settings.backend_code' => [
                'type'  => 'chars',
                'display_type' => 'select',
                'label' => '#admin.field_admin_settings_backend_code',
                'tooltip' => '#admin.tooltip_settings_backend_code',
                'max'   => 8,
                'flags' => ['trim', 'not_empty'],
                'default' => Cms::FALLBACK_LANG,
            ],
            'settings.locale' => [
                'type'  => 'chars',
                'display_type' => 'select',
                'label' => '#admin.field_admin_settings_locale',
                'tooltip' => '#admin.tooltip_settings_locale',
                'max'   => 16,
                'flags' => ['trim', 'not_empty'],
                'default' => (string)$this->config->get('system.locale'),
            ],
            'settings.timezone' => [
                'type'  => 'chars',
                'display_type' => 'select',
                'label' => '#admin.field_admin_settings_timezone',
                'tooltip' => '#admin.tooltip_settings_timezone',
                'max'   => 64,
                'flags' => ['trim', 'not_empty'],
                'default' => (string)$this->config->get('system.timezone'),
            ],
            'settings.fed' => [
                'type'  => 'integer',
                'display_type' => 'radio',
                'label' => '#admin.field_admin_settings_fed',
                'tooltip' => '#admin.tooltip_settings_fed',
                'min'   => 0,
                'max'   => 2,
                'default' => '0',
            ],
            'settings.widgets' => [
                'type'  => 'json',
                'label' => '#admin.field_admin_settings_widgets',
            ],
            // abonnierte Berichte des Zeitplaners (scheduler_ids)
            'settings.reports' => [
                'type'  => 'json',
                'display_type' => 'checkbox',
                'label' => '#admin.field_admin_settings_reports',
                'tooltip' => '#admin.tooltip_settings_reports',
            ],
            // Zusammenfassung der internen Nachrichten per E-Mail (MessageDigest)
            'settings.message_digest' => [
                'type'  => 'integer',
                'display_type' => 'radio',
                'label' => '#admin.field_admin_settings_message_digest',
                'tooltip' => '#admin.tooltip_settings_message_digest',
                'min'   => 0,
                'max'   => 1,
                'default' => '0',
            ],
            'deleted' => [
                'type'  => 'bit',
                'label' => '#admin.field_admin_deleted',
            ],
        ];
    }

    /**
     * Login Admin
     * 
     * @param string   $name         Column `name`
     * @param string   $password     Column `password` unhashed
     * @param string   $interface    Oberfläche der Session: backend | php
     *                               (Access::INTERFACES) - die Gruppe muss sie
     *                               benutzen dürfen
     * @param int|null $maxLifetime  php: Höchstlaufzeit der Session (Session::create())
     * @return array|null            Rückgabewerte bei Erfolg: Admin-Data, ansonsten null
     *                               Bei aktiver Zwei-Faktor-Anmeldung ist noch KEINE Session
     *                               erzeugt - dann fehlt 'sessid' im Ergebnis und es muss
     *                               loginByCode() folgen.
     *
     * Fehlermeldungen landen in Cms::$errors, der Aufrufer holt sie mit errorMsg() ab.
     * Ob der Benutzername oder das Passwort falsch war, wird bewusst nicht unterschieden.
     * Jeder Fehlschlag wandert in den LoginGuard; ist der Name dort gesperrt, wird gar
     * nicht mehr geprüft.
     */
    public function loginByName(string $name, string $password, string $interface = 'backend', ?int $maxLifetime = null): ?array
    {
        // Steuerzeichen raus: kein Name enthält sie, und guardKey() trennt damit den
        // Gerätezähler vom Namen - ein eingegebener Name kann ihn so nicht treffen
        $trimmedName = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', $name));
        $guardKey = $this->guardKey($trimmedName);

        // Gesperrt? Dann ohne weitere Prüfung abweisen - eine Sperre soll gerade nicht
        // davon abhängen, ob die Zugangsdaten diesmal stimmen.
        if ($blockedSeconds = $this->loginGuard->blockedFor($guardKey)) {
            $this->cms->errorsReset();
            $this->cms->error($this->cms->snippet('admin.error_login_blocked', [
                'minutes' => (int)ceil($blockedSeconds / 60)
            ]));
            return null;
        }
        // Fehlversuche je Herkunft über alle Namen (Password-Spraying: viele Namen
        // mit je einem Passwort - die Sperre je Name greift dann nie).
        // admin.throttle.login_ip, gezählt in _loginFailedMessage()
        if (!$this->rateLimiter->allows('admin_login_ip', $this->clientIp->rateKey())) {
            $this->cms->errorsReset();
            $this->cms->error($this->cms->snippet('admin.error_login_blocked', [
                'minutes' => (int)ceil($this->rateLimiter->limits('admin_login_ip')[1] / 60)
            ]));
            return null;
        }

        if (!$data = $this->load($trimmedName, 'name')) {
            // Admin nicht gefunden
            // Sicherheit: Hinweis allgemein halten (unklar belassen ob Username oder Passwort falsch ist)
            //
            // Auch ohne Treffer wird geprüft. Sonst antwortet ein erfundener Name
            // messbar schneller als ein vorhandener, und die gleiche Fehlermeldung
            // nützt nichts mehr - die Laufzeit verriete das Konto.
            $this->crypt->passwordVerify($password, '');

            $this->cms->errorsReset();
            $this->cms->error($this->_loginFailedMessage($guardKey));
            return null;
        };

        // Admin found
        $adminId = (int)$data['admin_id'];

        // Passwort prüfen (Salt steckt im gespeicherten Wert, Vergleich in
        // konstanter Zeit - siehe Crypt)
        if (!$this->crypt->passwordVerify($password, (string)$data['password'])) {
            // Passwort passt nicht
            $this->cms->error($this->_loginFailedMessage($guardKey));
            return null;
        }

        // Signatur der Zeile: eine per SQL geänderte Gruppe, ein getauschter Name
        // oder Hash fällt hier auf (Integrity). Die Meldung bleibt die allgemeine.
        if ($this->integrity->verifyAdmin($adminId) === null) {
            $this->cms->error($this->_loginFailedMessage($guardKey));
            return null;
        }

        // Hash im früheren Format oder mit alten Parametern: jetzt, wo das Passwort
        // bekannt ist, neu berechnen (Crypt::passwordNeedsRehash()) - direkt, ohne
        // save(): die Richtlinie gilt für neue Passwörter, nicht für bestehende
        if ($this->crypt->passwordNeedsRehash((string)$data['password'])) {
            $this->db->query(
                "UPDATE `admin` SET `password` = ? WHERE `admin_id` = ?",
                'si', [$this->crypt->passwordHash($password), $adminId]
            );
            $this->integrity->signAdmin($adminId);
        }

        // Status blocked
        if ($data['status_id'] > 0) {
            // Benutzer gesperrt.
            // Kein Fehlversuch: die Zugangsdaten waren richtig, der Zähler soll dem
            // rechtmässigen Inhaber eines deaktivierten Kontos nicht zulasten fallen.
            $errorMsg = $this->cms->snippet('admin.error_account_disabled');
            $this->cms->error($errorMsg);
            return null;
        }

        // Oberfläche: darf die Gruppe sie benutzen? (Access, "Oberflächen") - vor
        // dem zweiten Faktor, damit kein Code für eine unzulässige Anmeldung
        // verschickt wird. Kein Fehlversuch, wie beim gesperrten Konto.
        if (!$this->_interfaceAllowed($data, $interface)) return null;

        // Zweiter Faktor dieses Admins (E-Mail, TOTP oder keiner)
        $factor = $this->secondFactor($data);

        if ($factor === null) {
            // Direkte Anmeldung (ohne zweiten Faktor) - das Passwort war alles
            $this->loginGuard->clear($guardKey);
            return $this->_loginComplete($data, $interface, $maxLifetime);
        }

        // Zwei-Faktor-Anmeldung
        // --------------------
        // Der Zähler des LoginGuard bleibt hier stehen: Fehlversuche bei der
        // Code-Eingabe zählen auf denselben Namen (loginByCode()), und ein richtiges
        // Passwort darf sie nicht löschen - sonst ließe sich ein sechsstelliger
        // TOTP-Code mit Passwort + Code im Wechsel beliebig oft raten.
        //
        // uid anlegen; der Code (E-Mail) kommt vom Faktor. Erst speichern, dann
        // verschicken: schlägt das Speichern fehl, wäre der Code in der Mail wertlos.
        $updateData = [
            'admin_id' => $adminId,
            'uid' => $this->crypt->randomKey(32),
            'code' => '',
            'uid_date' => 'UTC_TIMESTAMP()'
        ];
        if (!$this->save(array_keys($updateData), $updateData)) {
            return null;
        }
        $data = array_replace($data, $updateData, ['uid_date' => '']);
        if (!$factor->start($data)) {
            return null;
        }
        $data['factor'] = $factor->name();
        // Passkey: Optionen für navigator.credentials.get() (Challenge aus der uid)
        if ($factor instanceof WebAuthnFactor) {
            $data['webauthn'] = $factor->loginOptions($data);
            $data['totp_active'] = $factor->totpActive($adminId);
        }

        // Liefere Admin-Daten zurück - ohne 'sessid': es folgt loginByCode()
        return $data;
    }

    /**
     * Der zweite Faktor eines Admins (SecondFactorInterface).
     *
     *     Passkey  wenn der Admin Passkeys hat bzw. second_factor = 'webauthn'
     *              (WebAuthnFactor::applies())
     *     TOTP     wenn der Admin es eingerichtet hat und security.admin_2fa nicht
     *              'email' ist - unabhängig von admin.2_factor: die eigene Wahl des
     *              Admins ist der stärkere Faktor und wird nicht abgeschaltet
     *     E-Mail   sonst, wenn admin.2_factor an ist
     *     keiner   sonst (null)
     *
     * @param array $data  Admin::load()
     */
    public function secondFactor(array $data): ?SecondFactorInterface
    {
        $adminId = (int)($data['admin_id'] ?? 0);
        // Passkeys zuerst: nur Passkeys (second_factor 'webauthn') oder Passkeys neben
        // TOTP - das Formular nimmt dann beides (WebAuthnFactor)
        if ($this->webAuthnFactor->applies($data)) {
            return $this->webAuthnFactor;
        }
        if ($this->totpFactor->available() && $this->totpFactor->isActive($adminId)) {
            return $this->totpFactor;
        }
        // E-Mail-Code: für alle (admin.2_factor) oder als eigene Wahl des Admins
        if ($this->config->get('admin.2_factor') || $this->emailFactorChosen($adminId)) {
            return $this->emailFactor;
        }
        return null;
    }

    /**
     * Hat der Admin den E-Mail-Code selbst gewählt (admin.second_factor = 'email')?
     * Gelesen wird die signierte Zeile - eine per SQL gesetzte Wahl gälte nicht,
     * aber ein per SQL entferntes 'totp' auch nicht (Integrity::verifyAdmin()).
     */
    public function emailFactorChosen(int $adminId): bool
    {
        $row = $this->integrity->verifyAdmin($adminId);
        return $row !== null && ($row['second_factor'] ?? '') === 'email';
    }

    /**
     * Den E-Mail-Code als eigenen zweiten Faktor ein- bzw. ausschalten
     * (AdminBackend::totp(); TOTP schaltet TotpFactor::disable() ab).
     * second_factor steht in der Signatur - danach neu signieren.
     */
    public function setEmailFactor(int $adminId, bool $on): void
    {
        $this->db->query(
            "UPDATE `admin` SET `second_factor` = ? WHERE `admin_id` = ? AND `second_factor` IN ('', 'email')",
            'si', [$on ? 'email' : '', $adminId]
        );
        $this->integrity->signAdmin($adminId);
    }

    /**
     * Muss der Admin sein Passwort erneuern, bevor er eine Session bekommt?
     * (siehe Klassenkommentar "Passwort ändern müssen")
     *
     * @return string  '' nein, 'initial' Passwort eines anderen Admins,
     *                 'expired' älter als admin.password_retention_days
     */
    public function passwordChangeReason(int $adminId): string
    {
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT `password`, `password_change`, `password_changed_at` FROM `admin` WHERE `admin_id` = ?",
                'i', [$adminId]
            );
            $row = $this->db->num_rows > 0 ? $this->db->fetch() : null;
        }
        finally {
            $this->db->popResult();
        }
        // Ohne Passwort (nur per CLI/SQL angelegt) gibt es nichts zu erneuern
        if ($row === null || (string)$row['password'] === '') return '';
        if ((int)$row['password_change'] === 1) return 'initial';
        $days = (int)$this->config->get('admin.password_retention_days');
        if ($days > 0) {
            $changed = (string)($row['password_changed_at'] ?? '');
            if ($changed === '' || strtotime($changed . ' UTC') < time() - $days * 86400) return 'expired';
        }
        return '';
    }

    /**
     * Muss dieser Admin TOTP erst noch einrichten, bevor er eine Session bekommt?
     * (security.admin_2fa = totp_required, Superuser ohne TOTP)
     */
    public function totpSetupRequired(array $data): bool
    {
        $adminId = (int)($data['admin_id'] ?? 0);
        // Ein Passkey erfüllt die Pflicht ebenso (der stärkere Faktor)
        return $this->totpFactor->requiredFor((int)($data['group_id'] ?? 0))
            && !$this->totpFactor->isActive($adminId)
            && !$this->webAuthnFactor->hasPasskeys($adminId);
    }

    /**
     * Letzter Schritt jeder erfolgreichen Anmeldung: Session anlegen - oder, wenn
     * TOTP Pflicht ist und noch fehlt, statt der Session eine frische uid für das
     * Einrichten im Anmeldedialog (loginByTotpSetup()).
     *
     * @param array $data  Admin::load()
     * @return array|null  $data mit 'sessid' bzw. mit 'uid' + 'totp_setup' (Token,
     *                     otpauth-URI und Secret, siehe TotpFactor::newSetup())
     */
    private function _loginComplete(array $data, string $interface = 'backend', ?int $maxLifetime = null): ?array
    {
        $adminId = (int)$data['admin_id'];
        if (!$this->_interfaceAllowed($data, $interface)) return null;

        // Passwort erneuern oder TOTP einrichten geht nur im Anmeldedialog des Backends
        if ($interface !== 'backend'
            && ($this->passwordChangeReason($adminId) !== '' || $this->totpSetupRequired($data))) {
            $this->cms->error($this->cms->snippet('admin.error_login_backend_first'));
            return null;
        }

        // Passwort erneuern (Startpasswort, abgelaufen): vor allem anderen
        $reason = $this->passwordChangeReason($adminId);
        if ($reason !== '') {
            $data['uid'] = $this->_newUid($adminId);
            $data['password_renew'] = $reason;
            return $data;
        }

        if ($this->totpSetupRequired($data)) {
            $data['uid'] = $this->_newUid($adminId);
            $data['totp_setup'] = $this->totpFactor->newSetup($data);
            return $data;
        }

        // Letzte Anmeldung nur als Tag (Datenschutz: keine Arbeitszeiten); das
        // Zeitfenster der Zwei-Faktor-Anmeldung schließen - uid_date sagt sonst, wann
        // die Anmeldung begann. Direkt per SQL: login_date steht nicht im Schema.
        $this->db->query("UPDATE `admin` SET `login_date` = UTC_DATE(), `uid_date` = '1000-01-01 00:00:00' WHERE `admin_id` = ?",
            'i', [$adminId]);

        // Session Starten. Der Aufrufer setzt damit das Cookie.
        $data['sessid'] = $this->session->create($adminId, $interface, $maxLifetime);
        // Gerät wiedererkennen, bei einem neuen Hinweis per E-Mail (LoginDevices),
        // und die Session an das Gerät hängen - Entfernen des Geräts beendet sie
        // (AdminBackend::devices(), Fremdschlüssel admin_session.device_id).
        // Nur im Backend: ein Programm hat kein Geräte-Cookie und wäre bei jeder
        // Anmeldung ein "neues Gerät".
        if ($interface === 'backend') {
            $device = $this->loginDevices->register('admin', $adminId, $data);
            if (!empty($device['device_id'])) {
                $this->session->attachDevice((string)$data['sessid'], (int)$device['device_id']);
            }
        }
        return $data;
    }

    /**
     * Darf die Gruppe des Admins die Oberfläche benutzen? Sonst Meldung in Cms::errors.
     *
     * @param array  $data       Admin::load()
     * @param string $interface  Access::INTERFACES
     */
    private function _interfaceAllowed(array $data, string $interface): bool
    {
        if ($this->container->get(Access::class)->groupInterface((int)($data['group_id'] ?? 0), $interface)) return true;
        $this->cms->error($this->cms->snippet('admin.error_login_interface'));
        return false;
    }

    /**
     * Frische uid für einen weiteren Schritt im Anmeldedialog (Passwort erneuern,
     * TOTP einrichten) - gültig admin.2_factor_expiration Sekunden, ein Versuch.
     */
    private function _newUid(int $adminId): string
    {
        $uid = $this->crypt->randomKey(32);
        $this->db->query(
            "UPDATE `admin` SET `uid` = ?, `code` = '', `uid_date` = UTC_TIMESTAMP() WHERE `admin_id` = ?",
            'si', [$uid, $adminId]
        );
        return $uid;
    }

    /**
     * Anmeldung mit neuem Passwort abschließen (siehe Klassenkommentar "Passwort
     * ändern müssen"): uid einlösen, neues Passwort prüfen (Richtlinie, Wiederholung,
     * nicht das bisherige) und speichern, dann weiter wie jede Anmeldung
     * (_loginComplete() - dort ggf. noch das Einrichten von TOTP).
     *
     * Ein abgelehntes Passwort ist kein Fehlversuch im LoginGuard: Name, Passwort
     * und zweiter Faktor waren bereits richtig.
     *
     * @return array|null  wie _loginComplete(); bei abgelehntem Passwort
     *                     ['retry' => true, 'uid' => …, 'password_renew' => …]
     *                     (Fehler in Cms::errors); null = uid ungültig/abgelaufen
     */
    public function loginByPasswordChange(string $uid, string $password, string $repeat): ?array
    {
        if (!($row = $this->_claimUid($uid))) {
            $this->cms->error($this->cms->snippet('admin.error_login_code_failed'));
            return null;
        }
        $data = $this->_checkClaimed($row);
        if ($data === null) return null;
        $adminId = (int)$data['admin_id'];
        $reason = $this->passwordChangeReason($adminId);
        if ($reason === '') {
            // Inzwischen nicht mehr nötig (anderes Fenster) - einfach weiter
            return $this->_loginComplete($data);
        }

        if ($this->changePassword($adminId, $password, $repeat)) {
            return $this->_loginComplete($data);
        }
        return ['retry' => true, 'uid' => $this->_newUid($adminId), 'password_renew' => $reason];
    }

    /**
     * Eigenes Passwort erneuern (Anmeldedialog und AdminBackend::password()): gleich
     * wiederholt, nicht das bisherige, Richtlinie (Dataset::validateValue()).
     * Das bisherige Passwort prüft der Aufrufer.
     *
     * @return bool  false: Fehler in Cms::errors
     */
    public function changePassword(int $adminId, string $password, string $repeat): bool
    {
        $label = $this->cms->snippet('admin.field_admin_password');
        if ($password === '' || $password !== $repeat) {
            $this->cms->error($this->cms->snippet('admin.error_password_repeat'), 'password', $label);
            return false;
        }
        $this->db->pushResult();
        $this->db->query("SELECT `password` FROM `admin` WHERE `admin_id` = ?", 'i', [$adminId]);
        $stored = (string)($this->db->fetchNum()[0] ?? '');
        $this->db->popResult();
        if ($stored !== '' && $this->crypt->passwordVerify($password, $stored)) {
            $this->cms->error($this->cms->snippet('admin.error_password_unchanged'), 'password', $label);
            return false;
        }
        $data = ['admin_id' => $adminId, 'password' => $password];
        // hookOnSaved() setzt password_changed_at und nimmt password_change zurück
        $this->passwordChangedBySelf = true;
        try {
            return $this->save(['password'], $data) !== null;
        }
        finally {
            $this->passwordChangedBySelf = false;
        }
    }

    /**
     * Schlüssel im LoginGuard für eine Anmeldung an diesem Konto: der Name - oder,
     * wenn der Browser ein bekanntes Gerät des Kontos ist (LoginDevices), ein
     * eigener Zähler je Konto und Gerät. So sperrt ein Angreifer, der den Namen
     * durchprobiert, den Inhaber nicht an dessen eigenem Gerät aus (siehe
     * Klassenkommentar "Schutz gegen Durchprobieren").
     *
     * Der Gerätezähler beginnt mit einem Steuerzeichen, das loginByName() aus jeder
     * Eingabe entfernt - ein eingegebener Name trifft ihn nie.
     *
     * @param string $name     Anmeldename (eingegeben bzw. aus der Zeile)
     * @param int    $adminId  0 = über den Namen suchen
     */
    public function guardKey(string $name, int $adminId = 0): string
    {
        if ($adminId < 1 && $name !== '') {
            $this->db->pushResult();
            try {
                $this->db->query(
                    "SELECT `admin_id` FROM `admin` WHERE `name` = ? AND `deleted` = 0 LIMIT 1",
                    's', [$name]
                );
                $adminId = (int)($this->db->fetchNum()[0] ?? 0);
            }
            finally {
                $this->db->popResult();
            }
        }
        $deviceId = $adminId > 0 ? $this->loginDevices->knownDeviceId('admin', $adminId) : 0;
        return $deviceId > 0 ? "\x1Fdevice " . $deviceId . ': ' . $name : $name;
    }

    /** guardKey() für eine Zeile aus _claimUid() (admin_id, name) */
    private function _rowGuardKey(array $row): string
    {
        return $this->guardKey((string)$row['name'], (int)$row['admin_id']);
    }

    /**
     * Fehlversuch verbuchen und die passende Meldung dazu liefern.
     *
     * Die Meldung bleibt in jedem Fall dieselbe allgemeine - ergänzt nur um den
     * Hinweis, dass der nächste Versuch der letzte ist. Dieser Hinweis erscheint bei
     * genau bruteforce_max Fehlversuchen; ab dem nächsten greift die Sperre in
     * blockedFor(). Er verrät nichts über das Konto: gezählt wird der eingegebene
     * Name, ob es ihn gibt oder nicht.
     *
     * @param string $name  Schlüssel im LoginGuard (guardKey())
     * @return string       Fertiger Meldungstext
     */
    private function _loginFailedMessage(string $name): string
    {
        $this->rateLimiter->record('admin_login_ip', $this->clientIp->rateKey());
        $attempts = $this->loginGuard->recordFailure($name);
        $max = (int)$this->config->get('admin.bruteforce_max');
        $minutes = (int)ceil((int)$this->config->get('admin.bruteforce_delay') / 60);

        // Dieser Fehlversuch war einer zu viel: ab jetzt greift die Sperre, und der
        // naechste Aufruf kommt gar nicht mehr bis zur Passwortpruefung. Dann ist der
        // Hinweis auf die Angaben gegenstandslos - es zaehlt nur noch die Wartezeit.
        if ($max > 0 && $attempts > $max) {
            return $this->cms->snippet('admin.error_login_blocked', ['minutes' => $minutes]);
        }

        $message = $this->cms->snippet('admin.error_login_failed');

        // Genau bruteforce_max: ein Versuch ist noch frei, und das wird auch gesagt.
        if ($max > 0 && $attempts == $max) {
            $message .= ' ' . $this->cms->snippet('admin.error_login_last_try', [
                'minutes' => $minutes
            ]);
        }

        return $message;
    }

    /**
     * Login Admin - 2-Faktor-Auth Step 2
     *
     * Der Faktor (E-Mail-Code, TOTP-Code oder Wiederherstellungscode) prüft
     * SecondFactorInterface::verify(); hier liegt, was für alle gleich ist: uid im
     * Zeitfenster, ein Versuch je uid, LoginGuard und Signatur.
     *
     * @param string $uid    Wert in Spalte `uid`, per Hidden-Feld im Formular aus Step 1
     * @param string $code   Eingabe (Code aus E-Mail oder App, Wiederherstellungscode)
     * @return array|null    Rückgabewerte bei Erfolg: Admin-Data, ansonsten null.
     *                       Mit 'totp_setup' statt 'sessid': TOTP ist Pflicht und muss
     *                       noch eingerichtet werden (loginByTotpSetup()).
     */
    public function loginByCode(string $uid, string $code, string $interface = 'backend', ?int $maxLifetime = null): ?array
    {
        $trimmedCode = trim($code);

        // uid einlösen: gültig, im Zeitfenster und genau einmal (siehe _claimUid())
        if ($trimmedCode === '' || !($row = $this->_claimUid($uid))) {
            $this->cms->error($this->cms->snippet('admin.error_login_code_failed'));
            return null;
        }
        $data = $this->_checkClaimed($row);
        if ($data === null) return null;

        $factor = $this->secondFactor($data);
        // Kein Faktor mehr (Konfiguration inzwischen geändert) oder falscher Code
        // uid: Challenge der Passkey-Anmeldung (WebAuthnFactor::challengeFor())
        if ($factor === null || !$factor->verify(array_replace($data, ['code' => (string)$row['code'], 'uid' => trim($uid)]), $trimmedCode)) {
            $this->cms->error($this->_codeFailedMessage($this->_rowGuardKey($row)));
            return null;
        }
        $this->loginGuard->clear($this->_rowGuardKey($row));

        return $this->_loginComplete($data, $interface, $maxLifetime);
    }

    /**
     * Anmeldung mit Pflicht-Einrichtung von TOTP abschließen (security.admin_2fa =
     * totp_required, Superuser ohne TOTP): Code aus der App zum Secret im
     * Setup-Token prüfen, TOTP einschalten, Session anlegen.
     *
     * Anders als loginByCode() führt ein falscher Code nicht zurück zum Anfang - er
     * zählt im LoginGuard, und das Ergebnis trägt eine neue uid und dasselbe
     * Setup-Token, damit der QR-Code nicht erneut gescannt werden muss.
     *
     * @return array|null  bei Erfolg $data mit 'sessid' und 'recovery_codes';
     *                     bei falschem Code ['retry' => true, 'uid' => …,
     *                     'totp_setup' => …] (Fehler in Cms::errors); sonst null
     */
    public function loginByTotpSetup(string $uid, string $token, string $code): ?array
    {
        if (!($row = $this->_claimUid($uid))) {
            $this->cms->error($this->cms->snippet('admin.error_login_code_failed'));
            return null;
        }
        $data = $this->_checkClaimed($row);
        if ($data === null) return null;
        $adminId = (int)$data['admin_id'];
        $setup = $this->totpFactor->setupFromToken($data, $token);
        if ($setup === null || !$this->totpSetupRequired($data)) {
            $this->cms->error($this->cms->snippet('admin.error_login_code_failed'));
            return null;
        }

        $codes = $this->totpFactor->enable($data, $token, $code);
        if ($codes === null) {
            // "Code falsch" ohne "bitte erneut anmelden" - es geht im selben Formular weiter
            $this->cms->error($this->_codeFailedMessage($this->_rowGuardKey($row), 'admin.error_totp_code'));
            if ($this->loginGuard->blockedFor($this->_rowGuardKey($row))) return null;
            $newUid = $this->crypt->randomKey(32);
            $this->db->query(
                "UPDATE `admin` SET `uid` = ?, `uid_date` = UTC_TIMESTAMP() WHERE `admin_id` = ?",
                'si', [$newUid, $adminId]
            );
            return ['retry' => true, 'uid' => $newUid, 'totp_setup' => $setup];
        }
        $this->loginGuard->clear($this->_rowGuardKey($row));

        $data = $this->_loginComplete($data);
        if ($data !== null) $data['recovery_codes'] = $codes;
        return $data;
    }

    /**
     * uid einlösen: Zeile im Zeitfenster suchen und die uid sofort neu würfeln.
     * Nur wer das UPDATE gewinnt, bekommt die Zeile - zwei gleichzeitige Versuche
     * mit derselben uid ergeben genau einen.
     *
     * @return array|null  admin_id, name, code (Stand vor dem Entwerten)
     */
    private function _claimUid(string $uid): ?array
    {
        $uid = trim($uid);
        // Ohne uid gar nicht erst suchen: ein leerer Wert passt auf nichts Sinnvolles
        if ($uid === '') return null;

        // Sekunden, die maximal zwischen Login und Bestätigung des Codes vergehen dürfen.
        // Der Default steht in Config; ohne ihn waere das Zeitfenster 0 Sekunden gross
        // und keine Anmeldung koennte je gelingen.
        $seconds = (int)$this->config->get('admin.2_factor_expiration');
        $this->db->query(
            "SELECT `admin_id`, `name`, `code` FROM `admin` WHERE `uid` = ?"
            . " AND `uid_date` >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND)"
            . " AND `status_id` = 0 AND `deleted` = 0",
            'si',
            [$uid, $seconds]
        );
        $row = $this->db->num_rows > 0 ? $this->db->fetch() : null;

        // uid in jedem Fall neu wuerfeln, auch beim Fehlversuch. Damit ist jede uid
        // genau einen Bestaetigungsversuch wert.
        $this->invalidateLoginCode($uid);
        if ($row === null || $this->db->affected_rows !== 1) return null;
        return $row;
    }

    /**
     * Nach dem Einlösen der uid: Sperre des LoginGuard und Signatur prüfen, Datensatz
     * laden. Fehler in Cms::errors.
     */
    private function _checkClaimed(array $row): ?array
    {
        // Gesperrt? Dann gar nicht erst prüfen - wie in loginByName()
        if ($blockedSeconds = $this->loginGuard->blockedFor($this->_rowGuardKey($row))) {
            $this->cms->error($this->cms->snippet('admin.error_login_blocked', [
                'minutes' => (int)ceil($blockedSeconds / 60)
            ]));
            return null;
        }
        $adminId = (int)$row['admin_id'];
        // Signatur der Zeile (siehe loginByName())
        if ($this->integrity->verifyAdmin($adminId) === null) {
            $this->cms->error($this->cms->snippet('admin.error_login_code_failed'));
            return null;
        }
        // Vollstaendigen Datensatz ueber load() holen statt die Rohzeile weiterzureichen:
        // load() entschluesselt email/mobile/phone und durchlaeuft die Hooks, so dass
        // hier dasselbe Array entsteht wie bei loginByName().
        return $this->load($adminId);
    }

    /**
     * Falscher Code: Fehlversuch im LoginGuard verbuchen (derselbe Zähler wie das
     * Passwort, admin.bruteforce_max) und die Meldung dazu liefern.
     */
    private function _codeFailedMessage(string $name, string $snippet = 'admin.error_login_code_failed'): string
    {
        $attempts = $this->loginGuard->recordFailure($name);
        $max = (int)$this->config->get('admin.bruteforce_max');
        $minutes = (int)ceil((int)$this->config->get('admin.bruteforce_delay') / 60);
        if ($max > 0 && $attempts > $max) {
            return $this->cms->snippet('admin.error_login_blocked', ['minutes' => $minutes]);
        }
        $message = $this->cms->snippet($snippet);
        if ($max > 0 && $attempts == $max) {
            $message .= ' ' . $this->cms->snippet('admin.error_login_last_try', ['minutes' => $minutes]);
        }
        return $message;
    }

    /**
     * uid neu wuerfeln und den Code loeschen - der bisherige Link ist damit verbraucht.
     *
     * Wird nach jedem Bestaetigungsversuch aufgerufen, auch nach einem fehlgeschlagenen.
     * Die uid bleibt dabei ein Zufallswert und wird nicht geleert: ein leeres Feld
     * wuerde bei der naechsten Abfrage auf alle Konten ohne laufende Anmeldung passen.
     *
     * Geht absichtlich direkt ueber SQL statt ueber save(): der Treffer wird ueber die
     * alte uid gesucht, nicht ueber die admin_id, damit auch ein Fehlversuch die
     * betroffene uid entwertet, ohne dass wir wissen, zu wem sie gehoert.
     */
    private function invalidateLoginCode(string $uid): void
    {
        $this->db->query(
            "UPDATE `admin` SET `uid` = ?, `code` = '' WHERE `uid` = ?",
            'ss',
            [$this->crypt->randomKey(32), $uid]
        );
    }

    /**
     * Vor dem Erstellen: Felder der Zwei-Faktor-Anmeldung vorbelegen.
     *
     * Die uid wird gleich mit einem Zufallswert besetzt statt leer zu bleiben - ein
     * leerer Wert wäre für jeden erratbar und würde beim Abgleich auf alle noch nicht
     * konfigurierten Konten passen.
     */
    protected function hookOnCreate(array &$data): void
    {
        // Initialisiere 2_factor-Auth Felder
        $data['uid'] = $this->crypt->randomKey(32);
        $data['uid_date'] = '1000-01-01';
    }

    /**
     * Vor dem Speichern: die persönlichen Einstellungen gegen die tatsächlichen
     * Möglichkeiten prüfen. Eine Auswahlliste schützt nicht vor einem manipulierten
     * Formular, und Zeitzone wie Sprachkürzel gehen später ungeprüft an
     * date_default_timezone_set() bzw. an den Snippet-Cache.
     */
    protected function hookOnSave(array &$fields, array &$data, array &$previousData): void
    {
        // Benutzernamen admin_* sind für anonymisierte Konten reserviert (Modul
        // Privacy) - ein bestehender Name bleibt erlaubt
        if ((empty($fields) || in_array('name', $fields, true)) && array_key_exists('name', $data)) {
            $name = (string)$data['name'];
            if (preg_match('/^admin_/i', $name) && $name !== (string)($previousData['name'] ?? '')) {
                $this->createFieldError('admin.error_name_reserved', 'name', $this->schema['name']['label'] ?? 'name');
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
        }

        // Benutzername scheduler kann nicht geändert werden
        $previousName = (string)($previousData['name'] ?? '');
        if ($previousName === 'scheduler') {
            $data['name'] = 'scheduler';
        }

        if (!empty($fields) && !in_array('settings', $fields, true)) return;
        $settings = (array)($data['settings'] ?? []);
        $schema = $this->schema;

        $code = trim((string)($settings['backend_code'] ?? ''));
        // Frühere zweistellige Kürzel ("de") auf das heutige ("de_DE") heben
        if (preg_match('/^[a-z]{2,3}$/', $code)) {
            $code = Cms::matchLanguage($code, $this->cms->backendLanguages()) ?? $code;
            $data['settings']['backend_code'] = $code;
        }
        if ($code !== '' && !in_array($code, $this->cms->backendLanguages(), true)) {
            $this->createFieldError('error_invalid_format', 'settings.backend_code', $schema['settings.backend_code']['label']);
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }
        $locale = trim((string)($settings['locale'] ?? ''));
        if ($locale !== '' && !$this->cms->isValidLocale($locale)) {
            $this->createFieldError('error_invalid_format', 'settings.locale', $schema['settings.locale']['label']);
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }
        $timezone = trim((string)($settings['timezone'] ?? ''));
        if ($timezone !== '' && !$this->cms->isValidTimezone($timezone)) {
            $this->createFieldError('error_invalid_format', 'settings.timezone', $schema['settings.timezone']['label']);
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }
        // Bevorzugte Sprachversion: '' oder eine vorhandene Sprache
        $languageId = trim((string)($settings['language_id'] ?? ''));
        if ($languageId !== '') {
            $this->db->query("SELECT 1 FROM `language` WHERE `language_id` = ?", 'i', [(int)$languageId]);
            if ($this->db->num_rows < 1 || !preg_match('/^\d+$/', $languageId)) {
                $this->createFieldError('error_invalid_format', 'settings.language_id', $schema['settings.language_id']['label']);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
        }
        // Widgets der Startseite: eine Liste aus Modulname + Platz, jedes Modul
        // höchstens einmal. Geprüft wird nur die FORM - ob es das Modul noch gibt
        // und ob der betroffene Admin es darf, entscheidet Widgets::render() beim
        // Anzeigen (ein deinstalliertes Modul darf das Speichern der übrigen
        // Einstellungen nicht blockieren).
        // Berichte: Liste von scheduler_ids (welche die Gruppe abonnieren darf, prüft
        // AdminBackend::settings(); der Versand prüft die Gruppe ohnehin erneut)
        if (array_key_exists('reports', $settings)) {
            $data['settings']['reports'] = $this->system->sanitizeIds((array)$settings['reports'], true);
        }
        $widgets = (array)($settings[Widgets::SETTINGS_KEY] ?? []);
        if (count($this->widgets->normalize($widgets, requireExists: false)) !== count($widgets)) {
            $this->createFieldError('error_invalid_format', 'settings.widgets', $schema['settings.widgets']['label']);
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }
    }

    /**
     * Nach dem Speichern: geänderte Einstellungen in die laufenden Sessions des Admins
     * schreiben - für den Admin selbst gelten sie damit sofort, für einen Redakteur,
     * dem ein Superuser sie einstellt, mit seinem nächsten Request.
     */
    protected function hookOnSaved(array &$fields, array &$data, array &$previousData): void
    {
        $adminId = (int)($data['admin_id'] ?? 0);
        // Neues Passwort: Zeitpunkt merken; hat es ein anderer Admin gesetzt
        // (Anlegen, Zurücksetzen), muss es bei der ersten Anmeldung geändert werden.
        // Die CLI (admin password, kein Session-Admin) und der Admin selbst setzen
        // ein endgültiges Passwort. Andere Sitzungen des Kontos enden.
        if ((empty($fields) || in_array('password', $fields, true)) && (string)($data['password'] ?? '') !== '') {
            $actorId = (int)$this->session->get('admin_id');
            $bySomeoneElse = !$this->passwordChangedBySelf && $actorId > 0 && $actorId !== $adminId;
            $this->db->query(
                // nur der Tag (Datenminimierung) - für die Frist admin.password_retention_days genügt er
                "UPDATE `admin` SET `password_changed_at` = UTC_DATE(), `password_change` = ? WHERE `admin_id` = ?",
                'ii', [$bySomeoneElse ? 1 : 0, $adminId]
            );
            $this->session->closeAllOf($adminId, keepCurrent: true);
        }
        // Signatur nachziehen, sobald eine signierte Spalte dabei war (leeres
        // $fields = alle Spalten). Läuft in der Transaktion von save().
        if (empty($fields) || array_intersect($fields, ['name', 'password', 'group_id', 'status_id'])) {
            $this->integrity->signAdmin($adminId);
        }
        if (!empty($fields) && !in_array('settings', $fields, true)) return;
        $this->session->refreshDetails($adminId);
    }

    /**
     * Einzelne Einstellungen eines Admins ändern, die übrigen bleiben - für das
     * Anmeldeformular (Backend-Sprache, Locale, Zeitzone) und AdminBackend::settings().
     *
     * @param int   $adminId
     * @param array $settings  zu ändernde Schlüssel (language_id, backend_code,
     *                         locale, timezone, fed)
     * @return bool            false: Fehler in Cms::errors
     */
    public function saveSettings(int $adminId, array $settings): bool
    {
        if ($adminId < 1) return false;
        if (!$data = $this->load($adminId)) return false;
        $current = (array)($data['settings'] ?? []);
        // Fehlende Angaben mit den Schema-Vorgaben füllen (ein Admin aus der Zeit vor
        // den Einstellungen hat noch keine)
        $defaults = (array)($this->init()['settings'] ?? []);
        $merged = array_replace($defaults, $current, $settings);
        $data = [
            'admin_id' => $adminId,
            'settings' => $merged,
        ];
        return $this->save(['settings'], $data) !== null;
    }

    /**
     * Nach dem Laden: Secret und Zeitschritt der Authenticator-App entfernen. load()
     * liest die ganze Zeile; das Secret (binär, verschlüsselt) gehört in kein
     * weitergereichtes Array - es liest nur TotpFactor selbst.
     */
    protected function hookOnLoaded(array &$data): void
    {
        unset($data['totp_secret'], $data['totp_step']);
    }

    protected function hookOnDelete(array $data): void
    {
    }

    /**
     * Beim Löschen (Soft-Delete): alles entfernen, womit das Konto sich noch anmelden
     * oder wiedererkannt werden könnte - Sessions, gespeicherte Geräte, Passkeys,
     * Wiederherstellungscodes - und den Verlauf "zuletzt bearbeitet" (history). Das
     * Audit-Log bleibt; anonymisiert wird es erst auf Wunsch (Modul Privacy,
     * AdminAnonymizer). Das eigene Konto lässt sich nicht löschen.
     */
    protected function hookOnSoftDelete(array $data): void
    {
        $adminId = (int)($data['admin_id'] ?? 0);
        if ($adminId > 0 && $adminId === (int)$this->session->get('admin_id')) {
            $errorMsg = $this->cms->snippet('admin.error_delete_self');
            $this->cms->error($errorMsg);
            throw new \Exception('#' . $errorMsg);
        }
        $this->removeCredentials($adminId);
    }

    /**
     * Sessions, Geräte, Passkeys, Wiederherstellungscodes und Verlauf eines Kontos
     * entfernen (Löschen, Anonymisieren).
     */
    public function removeCredentials(int $adminId): void
    {
        if ($adminId < 1) return;
        $this->session->closeAllOf($adminId);
        $this->loginDevices->removeAll('admin', $adminId);
        $this->container->get(Passkeys::class)->removeAll($adminId);
        $this->db->pushResult();
        try {
            $this->db->query("DELETE FROM `admin_recovery_code` WHERE `admin_id` = ?", 'i', [$adminId]);
            $this->db->query("DELETE FROM `history` WHERE `admin_id` = ?", 'i', [$adminId]);
        }
        finally {
            $this->db->popResult();
        }
    }
}