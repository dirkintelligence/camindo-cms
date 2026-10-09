<?php
/**
 * Community-Features im Frontend: Anmeldung, Registrierung, Beiträge, Warenkorb.
 * Instanzname im Code: $recordUser.
 *
 * "User" meint in camindo den Website-Besucher, nicht den Backend-Benutzer -
 * der heißt "Administrator" und wird von Camindo\Core\Modules\Admin\Admin verwaltet.
 *
 * WARUM RecordUser UND NICHT User
 * --------------------
 * Ein User ist in camindo kein Sonderfall mit eigener Tabelle, sondern ein ganz
 * normaler Datensatz: das Profil ist ein `record` in einem Bereich vom Typ
 * 'profile', und was ein User im Frontend beiträgt (Kommentare, Anzeigen, Bilder)
 * sind `record`-Datensätze in Bereichen vom Typ 'ugc'. Anmeldedaten, Profilfelder
 * und Beiträge laufen damit über dieselben Wege wie alle anderen Inhalte -
 * Cms::select() zum Lesen, Record zum Schreiben (Arbeitskopie, Validierung, Uploads,
 * Thumbnails, Relationen - alles wie im Backend, nur ohne Administrator).
 *
 * Deshalb liegt die Klasse im Modul Record und trägt dessen Namenspräfix. Das
 * Modul User bleibt die Backend-Oberfläche dazu (RecordUser hat keine).
 *
 * DIE BAUSTEINE
 * --------------------
 *     Bereich 'profile'   ein Datensatz je User. In `area`.`details` stehen die
 *                         beiden Anmeldefelder: login_name_field_id (chars oder
 *                         encrypted, z. B. eine E-Mail-Adresse) und
 *                         login_password_field_id (password). Ohne beide kann sich
 *                         in diesem Bereich niemand anmelden.
 *     Bereich 'ugc'       Beiträge. details.profile_area_ids nennt die Profil-
 *                         Bereiche, deren User hier schreiben dürfen.
 *     record.user_id      Besitzer eines Datensatzes (Profil-Datensatz). Daran
 *                         hängt die Besitzprüfung von USER_INPUT id=… und
 *                         USER_DELETE.
 *     record.status_id    2 = unbestätigt (nach USER_REGISTER), 0 = freigeschaltet
 *                         (USER_REGISTER_CONFIRM), 1 = deaktiviert
 *     record.owner_id     Owner im Backend (ein Admin): Parameter 'owner' von
 *                         USER_REGISTER / USER_INPUT, sonst user.default_owner_id
 *                         (ownerId()); wird auch created_by
 *     record.needs_review 1 bei allem, was ein User anlegt oder ändert - die
 *                         Gegenlese-Liste des Moduls User
 *     user_session        Frontend-Session (Camindo\Core\UserSession), Cookie
 *                         'usess' bzw. Parameter 'cookiename'
 *     user_token          Einmal-Tokens für Freischaltung und Passwort-Reset
 *     user_basket         Warenkorb je Session
 *     user_login_attempt  Brute-Force-Schutz (LoginGuard::forUsers())
 *
 * WER DARF WAS SCHREIBEN (USER_INPUT / USER_DELETE)
 * --------------------
 *     Bereich 'ugc'       angemeldet, Profil-Bereich des Users steht in
 *                         details.profile_area_ids; ändern/löschen nur eigene
 *                         Datensätze (user_id)
 *     Bereich 'profile'   nur das eigene Profil (id = angemeldeter User), kein
 *                         Anlegen - dafür ist USER_REGISTER da
 *     Bereich 'record'    nur mit ignoreuser=true
 *     ignoreuser=true     hebt ALLE Prüfungen auf - das Template trägt dann die
 *                         Verantwortung (z. B. ein Kontaktformular ohne Anmeldung)
 *
 * WAS DIE BEFEHLE HINTERLASSEN
 * --------------------
 * Die Ergebnisse stehen in $set (CMS:TEXT useval="{$set['user_id']}") - je Befehl
 * dokumentiert. Nach einem erfolgreichen USER_LOGIN / USER_REGISTER /
 * USER_REGISTER_CONFIRM / USER_PW ist zusätzlich der Profil-Datensatz der
 * laufende Datensatz ($cms), nach USER_INPUT der gespeicherte Datensatz - das
 * Erfolgstemplate kann ihn mit CMS:TEXT & Co. ausgeben. Fehler landen in
 * Cms::error() und lassen sich im Formular-Template mit CMS:MESSAGE zeigen.
 *
 * SCHUTZ (developer_docs/20-frontend-schutz.md)
 * --------------------
 *     Drosselung        USER_PW, USER_REGISTER, Fehlanmeldungen je Herkunft
 *                       (RateLimiter, user.throttle); Meldung 'throttled'
 *     Formularschutz    formguard=true (FormGuard: Zeitstempel, Köderfeld)
 *     zweiter Faktor    area.details.second_factor (UserSecondFactor):
 *                       USER_LOGIN codetemplate=… -> USER_LOGIN_CODE; Verwaltung
 *                       durch den User mit USER_2FA
 *
 * Die Befehle mit php_suffix 'include_and_stop' (USER_DELETE, USER_INPUT, USER_LOGIN,
 * USER_LOGIN_CODE, USER_PROTECT, USER_PW, USER_REGISTER, USER_REGISTER_CONFIRM) geben einen
 * Templatenamen zurück: den des Erfolgs- bzw. Fehlertemplates. Der Compiler bindet
 * es ein und beendet danach die Seite - außer Cms::$returnAfterSuccess ist gesetzt
 * (Befehl CMS:CONTINUE). Ein leerer Rückgabewert lässt das Template weiterlaufen.
 *
 * FORMULARDATEN
 * --------------------
 * Der Parameter 'data' (Standard "data") nennt den Namen des Request-Arrays, in
 * dem die Eingaben stehen: die Formularfelder heißen data[feldname], Uploads
 * data[feldname] in $_FILES - dieselbe Form, die auch die Backend-Eingabemaske
 * schickt, sodass Record::save() Uploads und mehrsprachige Felder
 * (data[feldname][language_id]) ohne Umweg versteht.
 *
 * NACHLADEN STATT KONSTRUKTOR
 * --------------------
 * Die Klasse wird nicht mit jedem Request gebaut, sondern über LazyServicesTrait
 * erst dann, wenn ein Template tatsächlich einen (CMS:USER_...)-Befehl enthält -
 * siehe $lazyServices in Camindo\Core\Page.
 *
 * AUFRUFKONVENTION
 * --------------------
 * Alle cms*-Methoden werden ausschließlich aus kompilierten Templates aufgerufen und
 * bekommen genau einen Parameter: das Array $_a mit den im Template angegebenen
 * Befehlsparametern. Vgl. die cms*-Methoden in Camindo\Core\Cms.
 *
 * @see Camindo\Core\Modules\Record\Record         Datensätze lesen und schreiben
 * @see Camindo\Core\UserSession                    Frontend-Session
 * @see Camindo\Core\MediaDelivery                  geschützte Dateien (user_auth)
 * @see Camindo\Core\Modules\User\UserBackend      Backend-Oberfläche dazu
 * @see Camindo\Core\Modules\Template\commands.php Definition der CMS:USER_*-Befehle
 * @see Camindo\Core\LazyServicesTrait             Nachladen
 * @see docs/developers/06-templates.md            Befehlsreferenz
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Crypt;
use Camindo\Core\ClientIp;
use Camindo\Core\Database;
use Camindo\Core\ContainerInterface;
use Camindo\Core\FormGuard;
use Camindo\Core\RateLimiter;
use Camindo\Core\LoginDevices;
use Camindo\Core\PasswordPolicy;
use Camindo\Core\LoginGuard;
use Camindo\Core\System;
use Camindo\Core\UserSession;
use Camindo\Core\Modules\Area\Area;
use Camindo\Core\Modules\Privacy\PrivacyJobs;

class RecordUser {

    /** Spaltenbreite von `user_login_attempt`.`name` (siehe LoginGuard) */
    private const NAME_MAX = 64;

    /** Falsche Codes je wartender Anmeldung (USER_LOGIN_CODE), danach zurück zum Passwort */
    private const MAX_CODE_ATTEMPTS = 3;

    /** Hat USER_LOGIN_CODE submit/resend in diesem Request schon ausgewertet? */
    private bool $loginCodeHandled = false;

    /** Bereiche je Request: area_id => Zeile aus `area` (details bereits dekodiert) */
    private array $areaCache = [];

    /** Hat das letzte saveRecord() ein Passwort gesetzt? */
    private bool $passwordChanged = false;

    public function __construct(
        private Config $config,
        private Database $db,
        private Crypt $crypt,
        private Cms $cms,
        private System $system,
        private UserSession $userSession,
        private LoginGuard $loginGuard,
        private Record $record,
        private LoginDevices $loginDevices,
        private ContainerInterface $container,
    )
    {
    }

    // ====================
    // CMS:USER_AUTH / CMS:USER_PROTECT
    // ====================

    /**
     * CMS:USER_AUTH - Prüft die Session auf Gültigkeit.
     *
     * Nimmt die Session aus 'sessionid' oder dem Cookie 'cookiename' auf und prüft
     * mit 'filter' (Syntax wie CMS:SELECT) den Profil-Datensatz. Ergebnis in $set:
     *
     *     $set['user_id']          record_id des Profils, 0 = nicht angemeldet
     *     $set['user']             der Profil-Datensatz (Array) oder []
     *     $set['user_session_id']  Session-Id, '' = keine
     *
     * Gibt nichts aus. Für einen Zugriffsschutz mit Fehlertemplate siehe USER_PROTECT.
     */
    public function cmsAuth($p): void
    {
        $this->authenticate($p);
    }

    /**
     * CMS:USER_PROTECT - Schützt ein Template vor dem Zugriff nicht angemeldeter Besucher.
     *
     * Wie USER_AUTH; ist niemand angemeldet (oder der Filter trifft nicht), wird das
     * 'errortemplate' eingebunden und die Seite beendet. Sonst läuft das Template
     * weiter, $set ist wie bei USER_AUTH gefüllt.
     */
    public function cmsProtect($p): string
    {
        if ($this->authenticate($p)) return '';
        return $this->_template((string)($p['errortemplate'] ?? ''));
    }

    /**
     * Gemeinsame Laufzeit von USER_AUTH und USER_PROTECT.
     *
     * @return bool  true, wenn ein User angemeldet ist und der Filter passt
     */
    private function authenticate(array $p): bool
    {
        $this->setUserVars(0, []);

        $sessionId  = trim((string)($p['sessionid'] ?? ''));
        $cookieName = (string)($p['cookiename'] ?? '');
        if (!$this->userSession->load($sessionId, $cookieName) || !$this->userSession->isLoggedIn()) {
            return false;
        }
        $profile = $this->loadProfile($this->userSession->recordId(), (array)($p['filter'] ?? []), (array)($p['mode'] ?? []));
        if ($profile === null) return false;

        $this->setUserVars((int)$profile['record_id'], $profile);
        return true;
    }

    // ====================
    // CMS:USER_LOGIN / CMS:USER_LOGOUT
    // ====================

    /**
     * CMS:USER_LOGIN - Prüft die Anmeldedaten und erzeugt eine neue Session.
     *
     * Zwei Wege:
     *   1. Name + Passwort aus data[{namefeld}] / data[{passwortfeld}] - die beiden
     *      Felder legt der Profil-Bereich fest (details.login_*_field_id). Nur mit
     *      'submit'.
     *   2. 'hash': ein Passwort-Reset-Token aus USER_PW - meldet den User ohne
     *      Passwort an (einmalig), damit er im Anschluss per USER_INPUT ein neues
     *      setzen kann. 'submit' ist dann nicht nötig.
     *
     * Der Brute-Force-Schutz zählt Fehlversuche je Name (LoginGuard::forUsers()).
     * Ein Profil mit status_id 2 (unbestätigt) oder 1 (deaktiviert) wird abgewiesen.
     *
     * Meldungen ('message'): blocked, failed, disabled, unconfirmed, hash, container.
     * Ergebnis in $set wie USER_AUTH; der Profil-Datensatz wird zum laufenden
     * Datensatz des Erfolgstemplates.
     *
     * @return string  successtemplate oder '' (Fehler in Cms::error())
     */
    public function cmsLogin($p): string
    {
        $this->setUserVars(0, []);
        $hash = trim((string)($p['hash'] ?? ''));
        if ($hash === '' && !$this->submitted($p)) return '';

        $area = $this->loadArea($p['container'] ?? '', ['profile']);
        if ($area === null) {
            $this->fail($p, 'container', 'user.error_container');
            return '';
        }
        $areaId = (int)$area['area_id'];
        $cookie = $this->_bool($p['cookie'] ?? true);
        $cookieName = (string)($p['cookiename'] ?? '');

        // 2. Anmeldung mit Passwort-Reset-Token
        // --------------------
        if ($hash !== '') {
            $recordId = $this->consumeToken($hash, 'password');
            $profile = $recordId > 0 ? $this->loadProfile($recordId) : null;
            if ($profile === null || (int)$profile['area_id'] !== $areaId) {
                $this->fail($p, 'hash', 'user.error_hash_invalid');
                return '';
            }
            if ((int)$profile['status_id'] !== 0) {
                $this->fail($p, (int)$profile['status_id'] === 2 ? 'unconfirmed' : 'disabled',
                    (int)$profile['status_id'] === 2 ? 'user.error_unconfirmed' : 'user.error_disabled');
                return '';
            }
            // Mit Authenticator-App: auch der Reset-Link braucht den Code - sonst
            // übernähme jeder mit Zugriff auf das Postfach das Konto. Ein Code per
            // E-Mail entfällt: der Link kam über dasselbe Postfach.
            if ($this->secondFactor()->policy($area) !== 'none' && $this->secondFactor()->method((int)$profile['record_id']) === 'totp') {
                return $this->startSecondFactor($profile, $area, 'totp', '', $cookie, $cookieName, $p, true);
            }
            return $this->loginProfile($profile, $cookie, $cookieName, $p, true);
        }

        // 1. Name + Passwort
        // --------------------
        $nameField = $this->fieldName((int)($area['login_name_field_id'] ?? 0), $areaId);
        $pwField   = $this->fieldName((int)($area['login_password_field_id'] ?? 0), $areaId);
        if ($nameField === '' || $pwField === '') {
            $this->fail($p, 'container', 'user.error_login_fields');
            return '';
        }
        $input = $this->requestData($p);
        $name = (string)($input[$nameField] ?? '');
        $password = (string)($input[$pwField] ?? '');
        if ($this->_bool($p['trim'] ?? false)) {
            $name = trim($name);
            $password = trim($password);
        }
        if ($name === '' || $password === '') {
            $this->fail($p, 'failed', 'user.error_login_failed');
            return '';
        }

        $guard = $this->loginGuard->forUsers();
        $guardName = mb_substr($areaId . ':' . $name, 0, self::NAME_MAX);
        if ($blockedSeconds = $guard->blockedFor($guardName)) {
            $this->fail($p, 'blocked', 'user.error_login_blocked', ['minutes' => (int)ceil($blockedSeconds / 60)]);
            return '';
        }
        // Fehlversuche je Herkunft über alle Namen (Credential Stuffing: viele
        // Konten mit je einem Passwort aus einem fremden Leck)
        $limiter = $this->container->get(RateLimiter::class);
        $origin = $this->container->get(ClientIp::class)->rateKey();
        if (!$limiter->allows('login_ip', $origin)) {
            $this->fail($p, 'blocked', 'user.error_login_blocked', ['minutes' => (int)ceil($limiter->limits('login_ip')[1] / 60)]);
            return '';
        }

        // Profil zum Namen - genau eines
        $profile = $this->findProfile($areaId, $nameField, $name);
        // Auch ohne Treffer prüfen: ein erfundener Name soll nicht schneller
        // antworten als ein vorhandener (siehe Admin::loginByName())
        $profileOk = $profile !== null && $this->passwordOk((int)$profile['record_id'], $pwField, $password);
        if ($profile === null) $this->crypt->passwordVerify($password, '');
        if (!$profileOk) {
            $guard->recordFailure($guardName);
            $limiter->record('login_ip', $origin);
            $this->fail($p, 'failed', 'user.error_login_failed');
            return '';
        }
        if ((int)$profile['status_id'] === 2) {
            $guard->clear($guardName);
            $this->fail($p, 'unconfirmed', 'user.error_unconfirmed');
            return '';
        }
        if ((int)$profile['status_id'] !== 0) {
            $guard->clear($guardName);
            $this->fail($p, 'disabled', 'user.error_disabled');
            return '';
        }
        // Zweiter Faktor (area.details.second_factor, UserSecondFactor::needed()):
        // der Zähler des LoginGuard bleibt stehen, bis auch der Code stimmt - sonst
        // ließe sich Passwort + Code im Wechsel beliebig oft raten
        $method = $this->secondFactor()->needed($profile, $area);
        if ($method !== '') {
            return $this->startSecondFactor($profile, $area, $method, $guardName, $cookie, $cookieName, $p);
        }
        $guard->clear($guardName);
        return $this->loginProfile($profile, $cookie, $cookieName, $p);
    }

    /**
     * Passwort (bzw. Reset-Link) stimmt, ein zweiter Faktor fehlt noch: eine
     * ANONYME Session (neue Id, ein Warenkorb zieht mit um) merkt sich die
     * wartende Anmeldung in details.mfa_pending, eingebunden wird das
     * 'codetemplate'. Angemeldet ist danach niemand - das erledigt
     * CMS:USER_LOGIN_CODE mit dem richtigen Code.
     *
     * Beim Code per E-Mail entsteht hier der Code und steht in $set['login_code'];
     * das codetemplate verschickt ihn (CMS:EMAIL an die Adresse des Profils, das
     * der laufende Datensatz ist). Der Befehl selbst verschickt nichts - wie alle
     * CMS:USER_*-Befehle.
     *
     * $set: second_factor ('totp' | 'email'), login_pending = 1, pending_user
     * (Profil), login_code, user_session_id (wartende Session, für Aufrufe ohne
     * Cookie).
     */
    private function startSecondFactor(array $profile, array $area, string $method, string $guardName, bool $cookie, string $cookieName, array $p, bool $reset = false): string
    {
        $template = $this->_template((string)($p['codetemplate'] ?? ''));
        if ($template === '') {
            $this->system->log('user', 'CMS:USER_LOGIN: profile area ' . (int)$area['area_id'] . ' requires a second factor, but codetemplate is missing');
            $this->fail($p, 'failed', 'user.error_login_failed');
            return '';
        }
        $recordId = (int)$profile['record_id'];
        $pending = [
            'rid' => $recordId,
            'aid' => (int)$area['area_id'],
            'm'   => $method,
            'g'   => $guardName !== '' ? $guardName : mb_substr((int)$area['area_id'] . ':#' . $recordId, 0, self::NAME_MAX),
            'exp' => gmdate('Y-m-d H:i:s', time() + $this->loginCodeExpiration()),
            'n'   => 0,
            'rs'  => $reset ? 1 : 0,
            'ck'  => $cookie ? 1 : 0,
        ];
        $code = '';
        if ($method === 'email') {
            [$code, $pending['ch']] = $this->secondFactor()->newCode();
        }
        $sessionId = $this->userSession->create(0, (int)$area['area_id'],
            (int)($this->cms->instance['instance_id'] ?? $profile['instance_id'] ?? 0),
            ['mfa_pending' => $pending, 'password_reset_until' => null],
            $cookie, $cookieName
        );
        $this->setUserVars(0, []);
        $GLOBALS['set']['user_session_id'] = $sessionId;
        $GLOBALS['set']['second_factor'] = $method;
        $GLOBALS['set']['login_pending'] = 1;
        $GLOBALS['set']['pending_user'] = $profile;
        $GLOBALS['set']['login_code'] = $code;
        $GLOBALS['cms'] = $profile;
        return $template;
    }

    /** user.login_code_expiration, mindestens 60 Sekunden */
    private function loginCodeExpiration(): int
    {
        return max(60, (int)($this->config->get('user.login_code_expiration') ?: 600));
    }

    /**
     * CMS:USER_LOGIN_CODE - zweiter Schritt der Anmeldung: Code aus der App, per
     * E-Mail/SMS oder ein Wiederherstellungscode.
     *
     * Liest die wartende Anmeldung aus der Session (details.mfa_pending, siehe
     * startSecondFactor()). Mit 'submit': Code aus data[{codefield}] (Vorgabe
     * data[code]) prüfen. Richtig -> Anmeldung wie USER_LOGIN (neue Session-Id,
     * Gerät verbuchen) und 'successtemplate'. Falsch -> Meldung 'code'; jeder
     * Fehlversuch zählt im LoginGuard auf dasselbe Konto wie das Passwort, nach
     * MAX_CODE_ATTEMPTS falschen Codes ist die wartende Anmeldung verworfen
     * (Meldung 'expired', zurück zum Passwort).
     *
     * 'resend' (nur beim Code per E-Mail): neuer Code, gedrosselt über
     * user.throttle.login_code. Er steht wie beim ersten Mal in $set['login_code'],
     * eingebunden wird 'codetemplate' - dasselbe Template, das nach USER_LOGIN den
     * Code mit CMS:EMAIL verschickt ($set['code_resent'] = 1).
     *
     * Ohne submit und resend: nur $set füllen (second_factor, login_pending) - so
     * kann das Formular-Template auch aus dem codetemplate eingebunden werden. Je
     * Request wird submit/resend nur beim ersten Aufruf ausgewertet; ein weiterer
     * Aufruf (das eingebundene Formular sieht denselben POST) füllt nur $set.
     *
     * Meldungen: token, code, expired, blocked, throttled.
     * $set nach Erfolg wie USER_LOGIN, dazu second_factor_used ('totp' | 'email' |
     * 'recovery') und recovery_remaining.
     *
     * @return string  successtemplate oder ''
     */
    public function cmsLoginCode($p): string
    {
        $this->setUserVars(0, []);
        $GLOBALS['set']['login_pending'] = 0;
        $firstCall = !$this->loginCodeHandled;
        $this->loginCodeHandled = true;
        $sessionId = trim((string)($p['sessionid'] ?? ''));
        $cookieName = (string)($p['cookiename'] ?? '');
        $pending = null;
        if ($this->userSession->load($sessionId, $cookieName) && !$this->userSession->isLoggedIn()) {
            $pending = $this->userSession->get('mfa_pending');
        }
        $pending = is_array($pending) && (string)($pending['exp'] ?? '') >= gmdate('Y-m-d H:i:s') ? $pending : null;
        $resend = $firstCall && $this->_bool($p['resend'] ?? false);
        $submit = $firstCall && trim((string)($p['submit'] ?? '')) !== '';
        if ($pending === null) {
            if ($submit || $resend) $this->fail($p, 'expired', 'user.error_code_expired');
            return '';
        }
        $area = $this->areaById((int)$pending['aid']);
        $profile = $this->loadProfile((int)$pending['rid']);
        if ($area === null || $profile === null || (int)$profile['status_id'] !== 0) {
            $this->userSession->setDetail('mfa_pending', null);
            if ($submit || $resend) $this->fail($p, 'expired', 'user.error_code_expired');
            return '';
        }
        $GLOBALS['set']['second_factor'] = (string)$pending['m'];
        $GLOBALS['set']['login_pending'] = 1;
        $GLOBALS['set']['pending_user'] = $profile;
        $GLOBALS['set']['user_session_id'] = $this->userSession->id();
        if (!$submit && !$resend) return '';
        if (!$this->csrfOk($p)) return '';

        // Neuer Code
        // --------------------
        if ($resend && !$submit) {
            if ((string)$pending['m'] !== 'email') return '';
            $template = $this->_template((string)($p['codetemplate'] ?? ''));
            if ($template === '') {
                $this->system->log('user', 'CMS:USER_LOGIN_CODE: resend without codetemplate');
                return '';
            }
            if (!$this->throttleHit([['login_code', (string)(int)$pending['rid']]])) {
                $GLOBALS['set']['throttled'] = 1;
                $this->fail($p, 'throttled', 'user.error_throttled');
                return '';
            }
            [$code, $pending['ch']] = $this->secondFactor()->newCode();
            $pending['n'] = 0;
            $pending['exp'] = gmdate('Y-m-d H:i:s', time() + $this->loginCodeExpiration());
            $this->userSession->setDetail('mfa_pending', $pending);
            $GLOBALS['set']['login_code'] = $code;
            $GLOBALS['set']['code_resent'] = 1;
            $GLOBALS['cms'] = $profile;
            return $template;
        }

        // Code prüfen
        // --------------------
        $guard = $this->loginGuard->forUsers();
        $guardName = (string)$pending['g'];
        if ($blockedSeconds = $guard->blockedFor($guardName)) {
            $this->fail($p, 'blocked', 'user.error_login_blocked', ['minutes' => (int)ceil($blockedSeconds / 60)]);
            return '';
        }
        $input = $this->requestData($p);
        $codeField = $this->_name((string)($p['codefield'] ?? '')) ?: 'code';
        $code = trim((string)($input[$codeField] ?? ''));
        // Kein Code im Request (etwa das Anmeldeformular, das hierher weiterleitet):
        // kein Fehlversuch, keine Meldung
        if ($code === '') return '';
        $used = '';
        if ((string)$pending['m'] === 'totp') {
            $used = $this->secondFactor()->verifyTotpOrRecovery($profile, $code);
        }
        elseif ($this->secondFactor()->codeMatches((string)($pending['ch'] ?? ''), $code)) {
            $used = 'email';
        }
        if ($used === '') {
            $guard->recordFailure($guardName);
            $pending['n'] = (int)$pending['n'] + 1;
            if ($pending['n'] >= self::MAX_CODE_ATTEMPTS) {
                $this->userSession->setDetail('mfa_pending', null);
                $GLOBALS['set']['login_pending'] = 0;
                $this->fail($p, 'expired', 'user.error_code_expired');
            }
            else {
                $this->userSession->setDetail('mfa_pending', $pending);
                $this->fail($p, 'code', 'user.error_code_invalid');
            }
            return '';
        }
        $guard->clear($guardName);
        $this->userSession->setDetail('mfa_pending', null);
        $GLOBALS['set']['login_pending'] = 0;
        $result = $this->loginProfile($profile, (bool)($pending['ck'] ?? true), $cookieName, $p, !empty($pending['rs']));
        $GLOBALS['set']['second_factor_used'] = $used;
        $GLOBALS['set']['recovery_remaining'] = $used === 'recovery' ? $this->secondFactor()->recoveryCount((int)$profile['record_id']) : null;
        return $result;
    }

    /**
     * CMS:USER_2FA - zweiten Faktor des angemeldeten Users verwalten
     * (developer_docs/20-frontend-schutz.md B.4). Der Profil-Bereich muss
     * second_factor ≠ 'none' haben.
     *
     *     function=status        (Vorgabe) nur lesen
     *     function=totp_setup    neuen Schlüssel erzeugen, nichts speichern:
     *                            $set['totp_setup'] = token, secret (zum Abtippen),
     *                            qr (HTML: QR-Code für die App, withQrCode()),
     *                            uri (otpauth://, öffnet auf dem Telefon die App)
     *     function=totp_enable   data[token] + data[code] aus der App -> speichern,
     *                            $set['recovery_codes'] (nur jetzt zu sehen)
     *     function=code_enable   Code per E-Mail/SMS als Faktor wählen, bestätigt
     *                            mit dem Passwort (data[current_password])
     *     function=disable       abschalten - bei TOTP mit App- oder
     *                            Wiederherstellungscode (data[code]), beim Code mit
     *                            dem Passwort. Bei second_factor 'required' wird aus
     *                            TOTP der Code; den Code abschalten geht dann nicht
     *     function=recovery_renew  neue Wiederherstellungscodes, mit App-Code
     *
     * Schreibende Funktionen nur mit 'submit' (POST) und CSRF-Token.
     * $set nach jeder Funktion: second_factor ('' | 'totp' | 'email'),
     * second_factor_policy, second_factor_since (UTC), recovery_count; nach einer
     * Änderung second_factor_changed = Ereignis (totp_enabled, totp_disabled,
     * code_enabled, code_disabled, recovery_renewed).
     *
     * Meldungen: auth, policy, token, code, current, setup, blocked.
     */
    public function cmsTwoFactor($p): void
    {
        $GLOBALS['set']['second_factor_changed'] = '';
        $GLOBALS['set']['recovery_codes'] = [];
        if (!$this->authenticate($p)) {
            $this->fail($p, 'auth', 'user.error_access_denied');
            return;
        }
        $profile = (array)$GLOBALS['set']['user'];
        $recordId = (int)$profile['record_id'];
        $area = $this->areaById((int)$profile['area_id']);
        $sf = $this->secondFactor();
        $policy = $area !== null ? $sf->policy($area) : 'none';
        $this->twoFactorVars($recordId, $policy);
        if ($policy === 'none') {
            $this->fail($p, 'policy', 'user.error_2fa_policy');
            return;
        }
        $function = strtolower(trim((string)($p['function'] ?? 'status')));
        $account = (string)($profile[$this->fieldName((int)($area['login_name_field_id'] ?? 0), (int)$area['area_id'])] ?? '');
        $issuer = (string)($this->cms->instance['primary_domain'] ?? '');
        $input = $this->requestData($p);

        if ($function === 'totp_setup') {
            $GLOBALS['set']['totp_setup'] = $this->withQrCode($sf->newSetup($profile, $account, $issuer));
            return;
        }
        if ($function === 'status' || !in_array($function, ['totp_enable', 'code_enable', 'disable', 'recovery_renew'], true)) return;
        if (!$this->submitted($p)) {
            // Formular noch nicht abgeschickt: beim Einrichten den Schlüssel erneut zeigen
            if ($function === 'totp_enable' && ($setup = $sf->setupFromToken($profile, (string)($input['token'] ?? ''), $account, $issuer))) {
                $GLOBALS['set']['totp_setup'] = $this->withQrCode($setup);
            }
            return;
        }
        $method = $sf->method($recordId);
        $code = (string)($input[$this->_name((string)($p['codefield'] ?? '')) ?: 'code'] ?? '');

        switch ($function) {
            case 'totp_enable':
                $token = (string)($input['token'] ?? '');
                $codes = $sf->enableTotp($profile, $token, $code);
                if ($codes === null) {
                    $setup = $sf->setupFromToken($profile, $token, $account, $issuer);
                    if ($setup === null) {
                        $this->fail($p, 'setup', 'user.error_2fa_setup');
                    }
                    else {
                        $GLOBALS['set']['totp_setup'] = $this->withQrCode($setup);
                        $this->codeFailure($area, $recordId, $p);
                    }
                    break;
                }
                $GLOBALS['set']['recovery_codes'] = $codes;
                $GLOBALS['set']['second_factor_changed'] = 'totp_enabled';
                break;

            case 'code_enable':
                if ($method === 'totp') {
                    // Wechsel von der App zum Code: wie Abschalten mit App-Code
                    if (!$this->totpConfirmed($profile, $area, $code, $p)) break;
                }
                elseif (!$this->currentPasswordOk($area, $recordId, $input, $p, false)) {
                    break;
                }
                $sf->enableCode($profile);
                $GLOBALS['set']['second_factor_changed'] = 'code_enabled';
                break;

            case 'disable':
                if ($method === '') break;
                if ($method === 'totp') {
                    if (!$this->totpConfirmed($profile, $area, $code, $p)) break;
                    if ($policy === 'required') {
                        $sf->enableCode($profile);
                        $GLOBALS['set']['second_factor_changed'] = 'totp_disabled';
                        break;
                    }
                }
                else {
                    if ($policy === 'required') {
                        $this->fail($p, 'policy', 'user.error_2fa_policy');
                        break;
                    }
                    if (!$this->currentPasswordOk($area, $recordId, $input, $p, false)) break;
                }
                $sf->disable($profile);
                $GLOBALS['set']['second_factor_changed'] = $method === 'totp' ? 'totp_disabled' : 'code_disabled';
                break;

            case 'recovery_renew':
                if ($method !== 'totp') break;
                if (!$this->totpConfirmed($profile, $area, $code, $p, false)) break;
                $GLOBALS['set']['recovery_codes'] = $sf->regenerateRecoveryCodes($profile);
                $GLOBALS['set']['second_factor_changed'] = 'recovery_renewed';
                break;
        }
        $this->twoFactorVars($recordId, $policy);
    }

    /**
     * QR-Code zum Einrichten der Authenticator-App: $setup['qr'] ist fertiges HTML
     * - ein Element mit data-c9-qr (der otpauth://-URI), die Bibliothek
     * qrcode-generator (kazuhikoarase, MIT) aus den Backend-Assets und ein kleines
     * Script, das daraus ein SVG macht; beide Scripts mit der Nonce der Seite, also
     * auch unter einer durchgesetzten Script-Policy lauffähig ('self'). Dieselbe
     * Bibliothek und dasselbe Attribut wie im Backend (cApp.ui.qrCodes()).
     * $setup['qr_script'] = URL der Bibliothek für eigene Einbindungen.
     */
    private function withQrCode(array $setup): array
    {
        $script = rtrim((string)$this->config->get('paths.backend'), '/') . '/assets/qrcode/qrcode.min.js';
        $nonce = htmlspecialchars($this->cms->cspNonce(), ENT_QUOTES);
        $setup['qr_script'] = $script;
        $setup['qr'] = '<div class="c9-qr" data-c9-qr="' . htmlspecialchars((string)$setup['uri'], ENT_QUOTES) . '"></div>'
            . '<script src="' . htmlspecialchars($script, ENT_QUOTES) . '" nonce="' . $nonce . '"></script>'
            . '<script nonce="' . $nonce . '">document.querySelectorAll("[data-c9-qr]:not([data-c9-qr-ready])").forEach(function (el) {'
            . 'if (!window.qrcode) return; var qr = window.qrcode(0, "M"); qr.addData(el.getAttribute("data-c9-qr")); qr.make();'
            . 'el.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 4, scalable: true }); el.setAttribute("data-c9-qr-ready", "1"); });</script>';
        return $setup;
    }

    /** $set-Werte zum Zustand (USER_2FA) */
    private function twoFactorVars(int $recordId, string $policy): void
    {
        $state = $this->secondFactor()->state($recordId);
        $GLOBALS['set']['second_factor'] = $policy === 'none' ? '' : $state['method'];
        $GLOBALS['set']['second_factor_policy'] = $policy;
        $GLOBALS['set']['second_factor_since'] = $state['enabled_at'];
        $GLOBALS['set']['recovery_count'] = $state['recovery_left'];
    }

    /**
     * App- oder (mit $allowRecovery) Wiederherstellungscode bestätigt eine Änderung;
     * Fehlversuche zählen im LoginGuard wie ein falsches Passwort.
     */
    private function totpConfirmed(array $profile, array $area, string $code, array $p, bool $allowRecovery = true): bool
    {
        $recordId = (int)$profile['record_id'];
        $guard = $this->loginGuard->forUsers();
        $guardName = mb_substr((int)$area['area_id'] . ':#' . $recordId, 0, self::NAME_MAX);
        if ($blockedSeconds = $guard->blockedFor($guardName)) {
            $this->fail($p, 'blocked', 'user.error_login_blocked', ['minutes' => (int)ceil($blockedSeconds / 60)]);
            return false;
        }
        $ok = $allowRecovery
            ? $this->secondFactor()->verifyTotpOrRecovery($profile, $code) !== ''
            : $this->secondFactor()->verifyTotp($recordId, $code);
        if (!$ok) {
            $guard->recordFailure($guardName);
            $this->fail($p, 'code', 'user.error_code_invalid');
            return false;
        }
        $guard->clear($guardName);
        return true;
    }

    /** Falscher App-Code beim Einrichten: Meldung und Fehlversuch */
    private function codeFailure(array $area, int $recordId, array $p): void
    {
        $this->loginGuard->forUsers()->recordFailure(mb_substr((int)$area['area_id'] . ':#' . $recordId, 0, self::NAME_MAX));
        $this->fail($p, 'code', 'user.error_code_invalid');
    }

    private function secondFactor(): UserSecondFactor
    {
        return $this->container->get(UserSecondFactor::class);
    }

    /**
     * Session für ein geprüftes Profil anlegen, Gerät verbuchen, $set und $cms füllen.
     *
     * $reset (Anmeldung über den Reset-Link): die Session darf bis
     * details.password_reset_until ein neues Passwort ohne das alte setzen
     * (USER_INPUT, currentPasswordOk()); $set['password_reset'] = 1. Eine normale
     * Anmeldung löscht eine solche Freigabe (create() übernimmt sonst die Details
     * einer laufenden Session desselben Profils).
     *
     * $set['new_device'] = 1, wenn sich das Profil von einem unbekannten Gerät
     * anmeldet und schon andere Geräte hat (LoginDevices) - das Erfolgstemplate kann
     * dann eine Hinweis-E-Mail verschicken; $set['device'] = label, ip, time (UTC).
     */
    private function loginProfile(array $profile, bool $cookie, string $cookieName, array $p, bool $reset = false): string
    {
        $recordId = (int)$profile['record_id'];
        $sessionId = $this->userSession->create(
            $recordId,
            (int)$profile['area_id'],
            (int)($this->cms->instance['instance_id'] ?? $profile['instance_id'] ?? 0),
            [
                'login_at' => gmdate('Y-m-d H:i:s'),
                'password_reset_until' => $reset ? gmdate('Y-m-d H:i:s', time() + $this->passwordTokenExpiration()) : null,
                'mfa_pending' => null,
            ],
            $cookie,
            $cookieName
        );
        $this->setUserVars($recordId, $profile, $sessionId);
        $device = $cookie
            ? $this->loginDevices->register('user', $recordId, $profile)
            : ['new' => false, 'label' => '', 'ip' => '', 'time' => gmdate('Y-m-d H:i:s')];
        $GLOBALS['set']['new_device'] = $device['new'] ? 1 : 0;
        $GLOBALS['set']['device'] = ['label' => $device['label'], 'ip' => $device['ip'], 'time' => $device['time']];
        $GLOBALS['cms'] = $profile;
        return $this->_template((string)($p['successtemplate'] ?? ''));
    }

    /**
     * CMS:USER_LOGOUT - Beendet und löscht die Session eines angemeldeten Users.
     * 'deletecookie' = false lässt das Cookie stehen (nur serverseitig beenden).
     * 'submit' ist optional: angegeben, aber leer (Seite einfach aufgerufen) -
     * nichts tun, auch keine Meldung 'token'; ohne den Parameter immer abmelden.
     */
    public function cmsLogout($p): void
    {
        if (array_key_exists('submit', $p) && trim((string)$p['submit']) === '') return;
        if (!$this->csrfOk($p)) return;
        $cookieName = (string)($p['cookiename'] ?? '');
        $this->userSession->load('', $cookieName);
        $this->userSession->close($this->_bool($p['deletecookie'] ?? true), $cookieName);
        $this->setUserVars(0, []);
    }

    // ====================
    // CMS:USER_REGISTER / CMS:USER_REGISTER_CONFIRM / CMS:USER_PW
    // ====================

    /**
     * CMS:USER_REGISTER - Erzeugt ein User-Profil aus den Eingaben, Status = 2 (unbestätigt).
     *
     * Schreibt einen Datensatz in den Profil-Bereich 'container' (Felder aus
     * data[...], eingeschränkt durch 'fields', überschrieben durch 'overwrite'),
     * mit status_id 2 und needs_review 1. 'emailfield' muss eine gültige
     * E-Mail-Adresse enthalten; Anmeldename und E-Mail dürfen im Bereich noch nicht
     * vorkommen. Alle Feldprüfungen des Bereichs gelten wie im Backend.
     *
     * Ein Freischalt-Token (Tabelle user_token, purpose 'register') landet in
     * $set['hash'] - das Erfolgstemplate baut daraus den Link auf eine Seite mit
     * CMS:USER_REGISTER_CONFIRM und verschickt ihn (E-Mail-Versand ist Sache des
     * Templates). Dazu $set['user_id'], $set['user'] (das Profil), $set['email'];
     * das Profil ist der laufende Datensatz des Erfolgstemplates.
     *
     * Meldungen ('message'): container, email, exists, save (plus Feldfehler der
     * Validierung).
     *
     * @return string  successtemplate oder ''
     */
    public function cmsRegister($p): string
    {
        $this->setUserVars(0, []);
        if (!$this->submitted($p)) return '';

        $area = $this->loadArea($p['container'] ?? '', ['profile']);
        if ($area === null) {
            $this->fail($p, 'container', 'user.error_container');
            return '';
        }
        $areaId = (int)$area['area_id'];
        $emailField = $this->_name((string)($p['emailfield'] ?? ''));
        $input = $this->requestData($p);

        // E-Mail-Adresse
        $email = trim((string)($input[$emailField] ?? ''));
        if ($emailField === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->fail($p, 'email', 'user.error_email_invalid');
            return '';
        }
        $input[$emailField] = $email;

        // Drosselung und Formularschutz: geprüft wird vorab, gezählt nur, was eine
        // E-Mail auslöst oder ein Konto verraten könnte (Erfolg und 'exists') - ein
        // Tippfehler in der Passwort-Richtlinie soll niemanden aussperren
        $buckets = [
            ['register_ip', $this->container->get(ClientIp::class)->rateKey()],
            ['register_value', $areaId . '|' . mb_strtolower($email)],
            ['mail_instance', (string)(int)$area['instance_id']],
        ];
        if (!$this->humanOk($p) || !$this->throttleAllows($buckets)) {
            $GLOBALS['set']['throttled'] = 1;
            $this->fail($p, 'throttled', 'user.error_throttled');
            return '';
        }

        // Anmeldename und E-Mail müssen im Bereich einmalig sein - unabhängig davon,
        // ob der Redakteur das Flag 'unique' gesetzt hat
        $nameField = $this->fieldName((int)($area['login_name_field_id'] ?? 0), $areaId);
        foreach (array_unique(array_filter([$emailField, $nameField])) as $field) {
            $value = trim((string)($input[$field] ?? ''));
            if ($value === '') continue;
            if ($this->findProfile($areaId, $field, $value, true) !== null) {
                $this->throttleRecord($buckets);
                $this->fail($p, 'exists', 'user.error_exists');
                return '';
            }
        }

        $ownerId = $this->ownerId($p);
        if ($ownerId < 1) return '';

        $overwrite = (array)($p['overwrite'] ?? []);
        $overwrite += ['status_id' => 2, 'needs_review' => 1];
        $recordId = $this->saveRecord($areaId, 0, $input, $this->_list($p['fields'] ?? []), $overwrite, [], 0, $p, 0, $ownerId);
        if ($recordId < 1) return '';
        $this->throttleRecord($buckets);

        $profile = $this->loadProfile($recordId) ?? [];
        $hash = $this->createToken($recordId, 'register');
        $this->setUserVars($recordId, $profile);
        $GLOBALS['set']['hash']  = $hash;
        $GLOBALS['set']['email'] = $email;
        $GLOBALS['cms'] = $profile;
        return $this->_template((string)($p['successtemplate'] ?? ''));
    }

    /**
     * CMS:USER_REGISTER_CONFIRM - Schaltet ein User-Profil frei, Status = 0.
     *
     * 'hash' ist das Token aus USER_REGISTER. Ein unbekanntes oder abgelaufenes
     * Token (user.token_expiration) ergibt die Meldung 'hash'. Das Token wird
     * verbraucht; der User ist danach NICHT angemeldet (dafür USER_LOGIN), aber
     * $set['user_id'] / $set['user'] sind gefüllt und das Profil ist der laufende
     * Datensatz des Erfolgstemplates.
     *
     * @return string  successtemplate oder ''
     */
    public function cmsRegisterConfirm($p): string
    {
        $this->setUserVars(0, []);
        $hash = trim((string)($p['hash'] ?? ''));
        $recordId = $hash !== '' ? $this->consumeToken($hash, 'register') : 0;
        $profile = $recordId > 0 ? $this->loadProfile($recordId) : null;
        if ($profile === null) {
            $this->fail($p, 'hash', 'user.error_hash_invalid');
            return '';
        }
        if ((int)$profile['status_id'] === 2) {
            $this->db->query(
                "UPDATE `record` SET `status_id` = 0, `changed_at` = UTC_TIMESTAMP() WHERE `record_id` = ?",
                'i',
                [$recordId]
            );
            $profile['status_id'] = 0;
        }
        $this->setUserVars($recordId, $profile);
        $GLOBALS['cms'] = $profile;
        return $this->_template((string)($p['successtemplate'] ?? ''));
    }

    /**
     * CMS:USER_PW - Bereitet das Zurücksetzen eines Passworts vor.
     *
     * Sucht im Profil-Bereich 'container' das Profil, dessen Feld 'uniquefield' den
     * Wert 'uniquevalue' trägt (z. B. die E-Mail-Adresse). Gefunden: ein Token
     * (purpose 'password') landet in $set['hash']; das Erfolgstemplate verschickt
     * daraus den Link auf eine Seite mit (CMS:USER_LOGIN hash=…). Nicht gefunden:
     * Meldung 'notfound' und das 'errortemplate'.
     *
     * Ohne 'uniquevalue' (Formular noch nicht abgeschickt) passiert nichts.
     *
     * @return string  successtemplate, errortemplate oder ''
     */
    public function cmsPw($p): string
    {
        $this->setUserVars(0, []);
        if (!$this->submitted($p)) return '';
        $value = trim((string)($p['uniquevalue'] ?? ''));
        if ($value === '') return '';

        $area = $this->loadArea($p['container'] ?? '', ['profile']);
        if ($area === null) {
            $this->fail($p, 'container', 'user.error_container');
            return '';
        }
        $field = $this->_name((string)($p['uniquefield'] ?? ''));

        // Drosselung und Formularschutz VOR der Suche: gezählt wird der eingegebene
        // Wert, nicht das gefundene Konto - sonst wäre nur eine existierende
        // Adresse irgendwann gesperrt, und daran ließe sich ablesen, welche
        // Adressen ein Konto haben. Die Antwort ist das errortemplate, wie bei
        // 'notfound'.
        $buckets = [
            ['pw_ip', $this->container->get(ClientIp::class)->rateKey()],
            ['pw_value', (int)$area['area_id'] . '|' . mb_strtolower($value)],
            ['mail_instance', (string)(int)$area['instance_id']],
        ];
        if (!$this->humanOk($p) || !$this->throttleHit($buckets)) {
            $GLOBALS['set']['throttled'] = 1;
            $this->fail($p, 'throttled', 'user.error_throttled');
            return $this->_template((string)($p['errortemplate'] ?? ''));
        }

        $profile = $field !== '' ? $this->findProfile((int)$area['area_id'], $field, $value) : null;
        if ($profile === null) {
            $this->fail($p, 'notfound', 'user.error_profile_not_found');
            return $this->_template((string)($p['errortemplate'] ?? ''));
        }
        $recordId = (int)$profile['record_id'];
        $this->setUserVars($recordId, $profile);
        $GLOBALS['set']['hash'] = $this->createToken($recordId, 'password');
        $GLOBALS['cms'] = $profile;
        return $this->_template((string)($p['successtemplate'] ?? ''));
    }

    // ====================
    // CMS:USER_INPUT / CMS:USER_DELETE
    // ====================

    /**
     * CMS:USER_INPUT - Erzeugt oder aktualisiert einen Datensatz aus Frontend-Eingaben.
     *
     * Ablauf:
     *   1. 'submit' muss gefüllt sein, 'container' ein Datensatzbereich der Instanz
     *   2. Berechtigung (siehe Klassenkommentar "Wer darf was schreiben")
     *   3. 'id': vorhandenen Datensatz aktualisieren - gehört er nicht dem User
     *      oder gibt es ihn nicht, wird ohne 'forceupdate' ein neuer angelegt, mit
     *      'forceupdate' abgebrochen (Meldung 'notfound')
     *   4. Eingaben aus data[...]: nur die Felder aus 'fields' (leer = alle
     *      benutzerdefinierten Felder des Bereichs); Metafelder ausschließlich über
     *      'overwrite'. 'removefiles' leert Upload-Felder. Uploads kommen aus
     *      $_FILES[data].
     *   5. Speichern mit allen Prüfungen des Bereichs (Feldfehler -> CMS:MESSAGE);
     *      neue Datensätze bekommen user_id = angemeldeter User und needs_review 1,
     *      geänderte needs_review 1
     *   6. Erfolg: $set['record_id'], der Datensatz ist der laufende Datensatz des
     *      'successtemplate' (ohne successtemplate läuft das Template weiter)
     *
     * 'masterid' hängt den neuen Datensatz als Kind unter den angegebenen
     * (parent_id) - nur in Bereichen mit Baum (Eingabefeld parent_id), sonst ohne
     * Wirkung.
     *
     * Meldungen ('message'): container, access, notfound, save.
     *
     * @return string  successtemplate oder ''
     */
    public function cmsInput($p): string
    {
        if (!$this->submitted($p)) return '';

        $area = $this->loadArea($p['container'] ?? '', Area::CONTENT_TYPES);
        if ($area === null) {
            $this->fail($p, 'container', 'user.error_container');
            return '';
        }
        $areaId = (int)$area['area_id'];
        $type = (string)$area['type'];
        $ignoreUser = $this->_bool($p['ignoreuser'] ?? false);
        $id = (int)($p['id'] ?? 0);

        // Berechtigung
        // --------------------
        $userId = 0;
        if (!$ignoreUser) {
            if (!$this->userSession->load() || !$this->userSession->isLoggedIn()) {
                $this->fail($p, 'access', 'user.error_access_denied');
                return '';
            }
            $userId = $this->userSession->recordId();
            $allowed = match ($type) {
                'ugc'     => in_array($this->userSession->areaId(), array_map('intval', (array)($area['details']['profile_area_ids'] ?? [])), true),
                'profile' => ($id > 0 && $id === $userId && (int)$this->userSession->areaId() === $areaId),
                default   => false,
            };
            if (!$allowed) {
                $this->fail($p, 'access', 'user.error_access_denied');
                return '';
            }
        }

        // Vorhandenen Datensatz?
        // --------------------
        $previous = null;
        if ($id > 0) {
            $previous = $this->loadOwned($id, $areaId, $userId, $ignoreUser);
            if ($previous === null) {
                if ($this->_bool($p['forceupdate'] ?? false) || $type === 'profile') {
                    $this->fail($p, 'notfound', 'user.error_record_not_found');
                    return '';
                }
                $id = 0; // neu anlegen
            }
        }

        // Owner (Backend-Admin) eines neuen Datensatzes
        $ownerId = 0;
        if ($id < 1) {
            $ownerId = $this->ownerId($p);
            if ($ownerId < 1) return '';
        }

        // Neues Passwort im eigenen Profil: nur mit dem bisherigen oder direkt nach
        // dem Reset-Link (currentPasswordOk())
        $input = $this->requestData($p);
        if ($type === 'profile' && !$ignoreUser
            && $this->passwordInput($areaId, $input, $this->_list($p['fields'] ?? []))
            && !$this->currentPasswordOk($area, $userId, $input, $p)
        ) {
            return '';
        }

        $overwrite = (array)($p['overwrite'] ?? []);
        $overwrite += ['needs_review' => 1];
        $recordId = $this->saveRecord(
            $areaId, $id, $input, $this->_list($p['fields'] ?? []), $overwrite,
            $this->_list($p['removefiles'] ?? ''), $userId, $p, (int)($p['masterid'] ?? 0), $ownerId
        );
        if ($recordId < 1) return '';

        // Neues Passwort: alle anderen Sessions des Profils beenden - wer das alte
        // kannte (oder eine Session gekapert hat), ist draußen. Die laufende
        // Session bleibt, wenn sie zu diesem Profil gehört.
        $GLOBALS['set']['password_changed'] = 0;
        if ($type === 'profile' && $this->passwordChanged) {
            $keep = $this->userSession->recordId() === $recordId ? $this->userSession->id() : '';
            $this->userSession->closeAllOf($recordId, $keep);
            // Reset-Freigabe verbraucht
            if ($keep !== '' && $this->userSession->get('password_reset_until')) {
                $this->userSession->setDetail('password_reset_until', null);
            }
            // Hinweis: das Erfolgstemplate kann eine E-Mail schicken, ein eigener
            // Notifier (security.login_notifier) z. B. eine SMS
            $GLOBALS['set']['password_changed'] = 1;
            $profile = $this->loadRecord($recordId) ?? [];
            $this->loginDevices->notify('password_changed', 'user', $profile, $this->loginDevices->context());
        }

        $GLOBALS['set']['record_id'] = $recordId;
        $saved = $this->loadRecord($recordId);
        if ($saved !== null) $GLOBALS['cms'] = $saved;
        return $this->_template((string)($p['successtemplate'] ?? ''));
    }

    /**
     * CMS:USER_DELETE - Löscht einen Datensatz, der dem angemeldeten User gehört.
     *
     * Soft-Delete wie im Backend (Papierkorb). Ein Profil kann sich selbst löschen
     * (id = angemeldeter User) - seine Sessions enden damit. 'ignoreuser' hebt die
     * Besitzprüfung auf. Bei fehlender Berechtigung: 'message_error'.
     *
     * @return string  successtemplate oder ''
     */
    public function cmsDelete($p): string
    {
        if (!$this->csrfOk($p)) return '';
        $id = (int)($p['id'] ?? 0);
        $ignoreUser = $this->_bool($p['ignoreuser'] ?? false);
        $userId = 0;
        if (!$ignoreUser) {
            if (!$this->userSession->load() || !$this->userSession->isLoggedIn()) {
                $this->failMessage((string)($p['message_error'] ?? ''), 'user.error_access_denied');
                return '';
            }
            $userId = $this->userSession->recordId();
        }
        $record = $id > 0 ? $this->loadOwned($id, 0, $userId, $ignoreUser) : null;
        if ($record === null) {
            $this->failMessage((string)($p['message_error'] ?? ''), 'user.error_access_denied');
            return '';
        }
        // Was "löschen" heißt, sagt die Lösch-Policy des Bereichs (Modul Privacy):
        // Papierkorb, Felder überschreiben, endgültig löschen …
        if ($this->container->get(PrivacyJobs::class)->delete($id) === null) {
            return '';
        }
        if ($id === $userId) {
            $this->userSession->closeAllOf($id);
            $this->setUserVars(0, []);
        }
        return $this->_template((string)($p['successtemplate'] ?? ''));
    }

    // ====================
    // CMS:USER_TOKEN
    // ====================

    /**
     * CMS:USER_TOKEN - CSRF-Token für ein Formular mit schreibendem CMS:USER_*-Befehl.
     *
     * Gibt ein verstecktes Feld <input type="hidden" name="_token" value="…"> aus,
     * mit format=value nur den Wert (für Ajax: Header X-Camindo-Token oder POST-Feld
     * _token). Das Token steht außerdem in $set['user_token']. Geprüft wird es von
     * USER_LOGIN, USER_REGISTER, USER_PW, USER_INPUT (wenn 'submit' gefüllt ist),
     * USER_DELETE, USER_LOGOUT und USER_BASKET (außer function=load).
     *
     * Das Token hängt an einem Cookie: der ERSTE Aufruf je Request muss vor der
     * ersten Ausgabe stehen, üblicherweise (CMS:USER_TOKEN set=…) am
     * Template-Anfang; weitere Aufrufe im Formular geben dann dasselbe Token aus.
     * Steht er zu spät, gibt es kein Token - ausgegeben wird ein HTML-Kommentar
     * mit dem Hinweis, die Formulare scheitern mit der Meldung 'token'.
     * Siehe UserSession::csrfToken().
     */
    public function cmsToken($p): void
    {
        $token = $this->userSession->csrfToken();
        $GLOBALS['set']['user_token'] = $token;
        if ($token === '') {
            $html = '<!-- CMS:USER_TOKEN: headers already sent, no token - place (CMS:USER_TOKEN set=…) at the start of the template -->';
        }
        else {
            $html = strtolower((string)($p['format'] ?? '')) === 'value'
                ? $token
                : '<input type="hidden" name="_token" value="' . htmlspecialchars($token, ENT_QUOTES) . '">';
            // formguard=true: Zeitstempel und Köderfeld dazu (FormGuard) - nur im
            // Formular, nicht beim frühen Aufruf mit set=… am Template-Anfang
            if (FormGuard::parameter($p['formguard'] ?? '') !== false && strtolower((string)($p['format'] ?? '')) !== 'value') {
                $html .= $this->container->get(FormGuard::class)->fields();
            }
        }
        if (!empty($p['set'])) $GLOBALS['set'][$p['set']] = $html;
        else echo $html;
    }

    /**
     * CMS:USER_PASSWORD_RULES - Regeln und Texte der Passwort-Richtlinie als JSON für
     * data-c9-password (Extension Password, im Template inline eingebunden):
     *
     *     <input type="password" name="data[passwort]"
     *            data-c9-password='(CMS:USER_PASSWORD_RULES container=kunden)'>
     *
     * Ausgabe HTML-maskiert (für ein Attribut in einfachen oder doppelten
     * Anführungszeichen). Texte in der Sprache der Seite. Trägt das Feld die
     * Extension, sind es genau die Regeln, die der Server beim Speichern prüft
     * (PasswordPolicy::rulesFor()); sonst security.password_policy - geprüft wird
     * dann nicht.
     */
    public function cmsPasswordRules($p): void
    {
        $policy = $this->container->get(PasswordPolicy::class);
        $options = [];
        $area = $this->loadArea($p['container'] ?? '', ['profile']);
        if ($area !== null) {
            $field = $this->_name((string)($p['field'] ?? ''))
                ?: $this->fieldName((int)($area['login_password_field_id'] ?? 0), (int)$area['area_id']);
            $colDef = $this->record->virtualSchema((int)$area['area_id'], false)[$field] ?? [];
            $details = is_array($colDef['details'] ?? null) ? $colDef['details'] : (json_decode((string)($colDef['details'] ?? ''), true) ?: []);
            if (strcasecmp((string)($details['extension'] ?? ''), PasswordPolicy::EXTENSION) === 0) {
                $options = (array)($details['extension_options'] ?? []);
            }
        }
        $html = htmlspecialchars(json_encode($policy->clientOptions($options), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES);
        if (!empty($p['set'])) $GLOBALS['set'][$p['set']] = $html;
        else echo $html;
    }

    // ====================
    // CMS:USER_BASKET
    // ====================

    /**
     * CMS:USER_BASKET - Bearbeiten eines Warenkorbs.
     *
     * Der Warenkorb hängt an der Frontend-Session - auch an einer anonymen: gibt es
     * keine Session, legt der Befehl eine an (Cookie). Er bleibt 'duration' Tage ab
     * der letzten Änderung (Standard user.basket_days) und überlebt eine Anmeldung.
     *
     *     function=load           Warenkorb lesen
     *     function=add            Artikel aus $_REQUEST['variable'] hinzufügen -
     *                             eine Liste von record_ids (Menge 1) oder
     *                             record_id => Menge. 'overwrite' = true setzt die
     *                             Menge statt sie zu addieren.
     *     function=update         Mengen setzen (record_id => Menge, 0 entfernt)
     *     function=update_addon   'basket_addon' (Text, z. B. serialisiert) zur
     *                             Kategorie speichern
     *
     * 'category' trennt mehrere Warenkörbe je Session (Standard 0).
     *
     * Was in den Warenkorb darf (basketAllowedIds()): nur VERÖFFENTLICHTE
     * Datensätze (Cms::publishedSql() wie CMS:SELECT), die der Request sehen darf.
     * Ohne 'container' aus Bereichen vom Typ 'record' oder 'ugc' - nie Profile;
     * mit 'container' (Bereichsnamen oder Ids, kommasepariert) nur aus diesen
     * Bereichen (auch 'profile', wenn ausdrücklich genannt). Dieselbe Prüfung gilt
     * beim Lesen: ein Artikel, der inzwischen offline ist, bleibt in `user_basket`
     * stehen, erscheint aber weder in $set['basket'] noch in der Ergebnismenge.
     *
     * Ergebnis in $set - nach JEDER Funktion:
     *     $set['basket']        record_id => Menge (Kategorie)
     *     $set['basket_count']  Summe der Mengen
     *     $set['basket_addon']  gespeicherter Zusatztext der Kategorie
     * Bei function=load stehen die Artikel-Datensätze außerdem als Ergebnismenge
     * bereit (Cms::select() über die record_ids) - ein folgendes CMS:RESULT_START
     * durchläuft sie; die Menge je Zeile liefert $set['basket'][$cms['record_id']].
     */
    public function cmsBasket($p): void
    {
        $function = strtolower(trim((string)($p['function'] ?? 'load')));
        $category = (int)($p['category'] ?? 0);
        $cookieName = (string)($p['cookiename'] ?? '');
        $instanceId = (int)($this->cms->instance['instance_id'] ?? 0);

        $sessionId = $this->userSession->ensure($instanceId, $cookieName);
        $containerIds = $this->basketContainerIds($p);
        $days = (int)($p['duration'] ?? 0);
        if ($days < 1) $days = (int)$this->config->get('user.basket_days') ?: 30;

        $changed = false;
        // Schreibende Funktionen nur mit Token; ohne bleibt es beim Lesen
        if ($function !== 'load' && !$this->csrfOk($p)) $function = 'load_only';
        if ($function === 'add' || $function === 'update') {
            $variable = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($p['variable'] ?? ''));
            $items = $variable !== '' ? (array)($_REQUEST[$variable] ?? []) : [];
            $quantities = [];
            if (array_is_list($items)) {
                // Liste von Ids (variable[]=5&variable[]=7): je Nennung Menge 1
                foreach ($items as $value) {
                    if (is_array($value)) continue;
                    $recordId = (int)$value;
                    $quantities[$recordId] = ($quantities[$recordId] ?? 0) + 1;
                }
            }
            else {
                // record_id => Menge (variable[5]=2)
                foreach ($items as $key => $value) {
                    if (is_array($value)) continue;
                    $quantities[(int)$key] = (int)$value;
                }
            }
            unset($quantities[0]);
            // Nur Datensätze, die in den Warenkorb dürfen; entfernen (update mit
            // Menge 0) geht immer - sonst bliebe ein offline gegangener Artikel hängen
            $allowedIds = $this->basketAllowedIds(array_keys($quantities), $containerIds);
            $quantities = array_filter(
                $quantities,
                fn($quantity, $recordId) => in_array($recordId, $allowedIds, true) || ($quantity <= 0 && $function === 'update'),
                ARRAY_FILTER_USE_BOTH
            );
            $overwrite = ($function === 'update') || $this->_bool($p['overwrite'] ?? false);
            foreach ($quantities as $recordId => $quantity) {
                $this->basketSet($sessionId, $category, $recordId, $quantity, $overwrite, $function === 'update');
                $changed = true;
            }
        }
        elseif ($function === 'update_addon') {
            $this->userSession->setDetail('basket_addon_' . $category, (string)($p['basket_addon'] ?? ''));
            $changed = true;
        }
        if ($changed || $this->userSession->get('basket_expires') === null) {
            $this->userSession->setDetail('basket_expires', gmdate('Y-m-d H:i:s', time() + $days * 86400));
        }

        // Inhalt lesen
        // --------------------
        $this->db->pushResult();
        $this->db->query(
            "SELECT `record_id`, `quantity` FROM `user_basket` WHERE `session_id` = ? AND `category` = ? ORDER BY `basket_id` ASC",
            'si',
            [$sessionId, $category]
        );
        $basket = [];
        foreach ($this->db->fetchAll() as $row) {
            $basket[(int)$row['record_id']] = (int)$row['quantity'];
        }
        $this->db->popResult();
        // Was (nicht mehr) veröffentlicht ist oder außerhalb von 'container' liegt,
        // zählt nicht mit (die Zeile bleibt stehen, siehe Methodenkommentar)
        $basket = array_intersect_key($basket, array_flip($this->basketAllowedIds(array_keys($basket), $containerIds)));
        $GLOBALS['set']['basket'] = $basket;
        $GLOBALS['set']['basket_count'] = array_sum($basket);
        $GLOBALS['set']['basket_addon'] = (string)($this->userSession->get('basket_addon_' . $category) ?? '');

        if ($function === 'load') {
            // Artikel als Ergebnismenge (leere Liste -> leeres Ergebnis)
            if (empty($basket)) {
                $this->db->setArrayResult([]);
            }
            else {
                $this->cms->select(['filter' => ['record_id' => array_keys($basket)], 'limit' => -1]);
            }
        }
    }

    /**
     * Artikel im Warenkorb setzen / addieren / entfernen. Ob der Datensatz in den
     * Warenkorb darf, hat der Aufrufer geprüft (basketAllowedIds()).
     */
    private function basketSet(string $sessionId, int $category, int $recordId, int $quantity, bool $overwrite, bool $removeZero): void
    {
        if ($recordId < 1) return;
        $this->db->pushResult();
        try {
            if ($quantity <= 0 && ($removeZero || $overwrite)) {
                $this->db->query(
                    "DELETE FROM `user_basket` WHERE `session_id` = ? AND `category` = ? AND `record_id` = ?",
                    'sii',
                    [$sessionId, $category, $recordId]
                );
                return;
            }
            if ($quantity <= 0) return;
            $sql = "INSERT INTO `user_basket` (`session_id`, `category`, `record_id`, `quantity`, `addon`, `changed_at`)"
                 . " VALUES (?, ?, ?, ?, '', UTC_TIMESTAMP())"
                 . " ON DUPLICATE KEY UPDATE `quantity` = "
                 . ($overwrite ? "VALUES(`quantity`)" : "`quantity` + VALUES(`quantity`)")
                 . ", `changed_at` = UTC_TIMESTAMP()";
            $this->db->query($sql, 'siii', [$sessionId, $category, $recordId, $quantity]);
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Bereiche aus 'container' (Namen oder Ids, kommasepariert) - nur
     * Datensatzbereiche, die der Request sehen darf.
     *
     * @return int[]|null  null = kein 'container' angegeben; [] = angegeben, aber
     *                     nichts davon gefunden (dann darf nichts in den Warenkorb)
     */
    private function basketContainerIds(array $p): ?array
    {
        $names = $this->_list($p['container'] ?? []);
        if (empty($names)) return null;
        $ids = [];
        foreach ($names as $name) {
            $area = $this->loadArea($name, Area::CONTENT_TYPES);
            if ($area !== null) $ids[] = (int)$area['area_id'];
        }
        return array_values(array_unique($ids));
    }

    /**
     * Welche der Ids dürfen in den Warenkorb? Veröffentlicht (Cms::publishedSql(),
     * wie CMS:SELECT), sichtbar für den Request (Cms::$sqlAccess), und aus den
     * Bereichen $containerIds - ohne diese aus Bereichen vom Typ 'record' oder
     * 'ugc' (nie Profile anderer User).
     *
     * @param int[]      $ids
     * @param int[]|null $containerIds  siehe basketContainerIds()
     * @return int[]
     */
    private function basketAllowedIds(array $ids, ?array $containerIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn($id) => $id > 0));
        if (empty($ids) || $containerIds === []) return [];
        $where = "`r`.`record_id` IN (" . implode(',', $ids) . ")"
               . " AND `r`.`tmp` = 0 AND `r`.`deleted` = 0 AND `a`.`deleted` = 0"
               . " AND " . $this->cms->publishedSql([], 'r');
        if ($containerIds !== null) {
            $where .= " AND `r`.`area_id` IN (" . implode(',', array_map('intval', $containerIds)) . ")";
        }
        else {
            $where .= " AND `a`.`type` IN ('record', 'ugc')";
        }
        if ($this->cms->sqlAccess !== '') $where .= ' AND `r`.' . $this->cms->sqlAccess;
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT `r`.`record_id`, `r`.`area_id` FROM `record` `r`"
                . " INNER JOIN `area` `a` ON `a`.`area_id` = `r`.`area_id` WHERE " . $where
            );
            return array_map(fn($row) => (int)$row['record_id'], $this->db->fetchAll());
        }
        finally {
            $this->db->popResult();
        }
    }

    // ====================
    // Datensätze schreiben
    // ====================

    /**
     * Datensatz aus Frontend-Eingaben anlegen oder aktualisieren - über dieselbe
     * Arbeitskopie wie das Backend (tmpInit()/checkOut() -> save()).
     *
     * @param int    $areaId       Bereich
     * @param int    $id           vorhandener Datensatz, 0 = neu
     * @param array  $input        Eingaben (data[...])
     * @param array  $fields       erlaubte Feldnamen, leer = alle benutzerdefinierten
     * @param array  $overwrite    Werte, die die Eingaben überschreiben (auch Metafelder)
     * @param array  $removeFiles  Upload-Felder, deren Datei entfernt wird
     * @param int    $userId       Besitzer (nur bei neuen Datensätzen gesetzt)
     * @param array  $p            Befehlsparameter (Meldungen)
     * @param int    $masterId     parent_id für neue Datensätze in Baum-Bereichen
     * @param int    $ownerId      Owner (admin_id) neuer Datensätze, siehe ownerId()
     * @return int                 record_id, 0 bei Fehler (Meldungen in Cms::error())
     */
    private function saveRecord(int $areaId, int $id, array $input, array $fields, array $overwrite, array $removeFiles, int $userId, array $p, int $masterId = 0, int $ownerId = 0): int
    {
        $schemaVirtual = $this->record->virtualSchema($areaId, false);
        $allowed = array_map(fn($f) => $this->_name((string)$f), $this->_list($fields));
        $allowed = array_values(array_filter($allowed));

        // Uploads: Record::save() liest $_FILES['data'] - ein anderer Array-Name
        // wird dorthin gespiegelt
        $dataName = $this->dataName($p);
        if ($dataName !== 'data' && isset($_FILES[$dataName]) && !isset($_FILES['data'])) {
            $_FILES['data'] = $_FILES[$dataName];
        }

        // Arbeitskopie
        // --------------------
        if ($id > 0) {
            $data = $this->record->load($id);
            if ($data === null || !$tmpId = $this->record->checkOut($data)) {
                $this->fail($p, 'save', 'user.error_save_failed');
                return 0;
            }
        }
        else {
            $data = $this->record->tmpInit(['area_id' => $areaId]);
            if ($data === null) {
                $this->fail($p, 'save', 'user.error_save_failed');
                return 0;
            }
            $tmpId = (int)$data['record_id'];
        }

        // Eingaben übernehmen: nur benutzerdefinierte Felder, die 'fields' erlaubt
        // --------------------
        $written = [];
        foreach ($schemaVirtual as $name => $colDef) {
            if (!array_key_exists($name, $input)) continue;
            if (!empty($allowed) && !in_array($name, $allowed, true)) continue;
            if (!empty($colDef['meta'])) continue;
            $type = (string)($colDef['type'] ?? 'text');
            if ($type === 'file' || $type === 'fulltext') continue; // Dateien über $_FILES
            $data[$name] = $input[$name];
            $written[] = $name;
        }
        // Upload-Felder: die Datei kommt aus $_FILES, das Feld gilt als übermittelt
        foreach ($schemaVirtual as $name => $colDef) {
            if ((string)($colDef['type'] ?? '') !== 'file') continue;
            if ((string)($colDef['display_type'] ?? '') !== 'upload') continue;
            if (!empty($allowed) && !in_array($name, $allowed, true)) continue;
            if (isset($_FILES['data']['name'][$name]) || in_array($name, $removeFiles, true)) $written[] = $name;
        }

        // Dateien entfernen
        foreach ($removeFiles as $name) {
            $fieldId = (int)($schemaVirtual[$name]['field_id'] ?? 0);
            if ($fieldId > 0) $this->record->markFileDeleted($tmpId, $fieldId);
        }

        // Überschreibungen (Metafelder erlaubt), Besitzer, Baum
        // --------------------
        foreach ($overwrite as $name => $value) {
            $name = $this->_name((string)$name);
            if ($name === '' || in_array($name, ['record_id', 'area_id', 'instance_id', 'tmp', 'deleted', 'origin_id', 'owner_id', 'created_by', 'changed_by'], true)) continue;
            $data[$name] = $value;
            $written[] = $name;
        }
        if ($id < 1) {
            $data['user_id'] = $userId;
            $written[] = 'user_id';
            // Owner im Backend: owner_id und created_by - ohne Backend-Session nimmt
            // Dataset::save() den mitgegebenen Wert (siehe ownerId())
            if ($ownerId > 0) {
                $data['owner_id'] = $ownerId;
                $data['created_by'] = $ownerId;
                $written[] = 'owner_id';
            }
            if ($masterId > 0 && isset($schemaVirtual['parent_id'])) {
                $data['parent_id'] = $masterId;
                $written[] = 'parent_id';
            }
        }
        $data['record_id'] = $tmpId;
        $written = array_values(array_unique($written));

        // Wird ein Passwort gesetzt? (leer = bisheriges behalten, siehe
        // VirtualFieldsTrait::saveVirtualFields()) - cmsInput() beendet danach die
        // übrigen Sessions des Profils
        $this->passwordChanged = false;
        foreach ($written as $name) {
            $colDef = $schemaVirtual[$name] ?? [];
            if (($colDef['type'] ?? '') !== 'password' && ($colDef['display_type'] ?? '') !== 'password') continue;
            $values = is_array($data[$name] ?? null) ? $data[$name] : [$data[$name] ?? ''];
            foreach ($values as $value) {
                if (is_string($value) && trim($value) !== '') $this->passwordChanged = true;
            }
        }

        // Speichern - alle Prüfungen des Bereichs; Uploads aus dem Frontend ohne
        // aktiven Inhalt (VirtualFieldsTrait::setUntrustedUploads())
        // --------------------
        $this->record->setUntrustedUploads(true);
        try {
            $recordId = $this->record->save($written, $data);
        }
        finally {
            $this->record->setUntrustedUploads(false);
        }
        if (!$recordId) {
            // Arbeitskopie samt hochgeladener Dateien verwerfen: das Frontend kann
            // sie anders als die Backend-Maske nicht erneut anzeigen
            $this->record->cancel($tmpId);
            if (!$this->cms->hasErrors) $this->fail($p, 'save', 'user.error_save_failed');
            return 0;
        }
        return (int)$recordId;
    }

    /**
     * Owner (Backend-Admin) für die Datensätze, die ein User anlegt: Parameter
     * 'owner' (admin_id), sonst die Konfiguration user.default_owner_id. Der Admin
     * muss existieren - sonst Meldung 'owner' (user.error_owner) und 0.
     *
     * Jeder Datensatz gehört im Backend einem Admin (record.owner_id, dazu
     * created_by); seine Gruppe entscheidet, welche anderen Admins ihn bearbeiten
     * dürfen (docs/developers/12-group-rights.md).
     */
    private function ownerId(array $p): int
    {
        $ownerId = (int)($p['owner'] ?? 0);
        if ($ownerId < 1) $ownerId = (int)$this->config->get('user.default_owner_id');
        if ($ownerId > 0) {
            $this->db->pushResult();
            $this->db->query("SELECT 1 FROM `admin` WHERE `admin_id` = ? AND `deleted` = 0", 'i', [$ownerId]);
            $exists = $this->db->num_rows > 0;
            $this->db->popResult();
            if (!$exists) $ownerId = 0;
        }
        if ($ownerId < 1) $this->fail($p, 'owner', 'user.error_owner');
        return $ownerId;
    }

    // ====================
    // Profile und Datensätze lesen
    // ====================

    /**
     * Profil-Datensatz laden - über Cms::select(), damit Instanz-Zugriff und
     * optionale Filterkriterien (USER_AUTH / USER_PROTECT) gelten.
     *
     * @param int   $recordId
     * @param array $filter  zusätzliche Kriterien (Syntax wie CMS:SELECT 'filter')
     * @param array $mode    filtermode je Feld
     * @return array|null    Datensatz oder null (unbekannt, kein Zugriff, Filter trifft nicht)
     */
    private function loadProfile(int $recordId, array $filter = [], array $mode = []): ?array
    {
        if ($recordId < 1) return null;
        $filter['record_id'] = $recordId;
        $this->db->pushResult();
        try {
            $this->cms->select(['filter' => $filter, 'filtermode' => $mode, 'limit' => 1]);
            if ($this->db->num_rows < 1) return null;
            $data = $this->cms->fetch();
        }
        finally {
            $this->db->popResult();
        }
        if ($data === null) return null;
        // Nur Datensätze aus Profil-Bereichen
        $area = $this->areaById((int)$data['area_id']);
        if ($area === null || (string)$area['type'] !== 'profile') return null;
        return $data;
    }

    /**
     * Profil zu einem Feldwert (Anmeldename, E-Mail) - genau ein Treffer.
     *
     * Direkt auf der data_*-Tabelle statt über Cms::select(): dessen Filter-Syntax
     * deutet Operatoren, Platzhalter und " OR " in der Eingabe - ein Anmeldename
     * muss aber wörtlich verglichen werden. Bei 'chars' vergleicht die Datenbank
     * ohne Groß-/Kleinschreibung (Kollation), bei 'encrypted' über den Hash der
     * wie beim Speichern normalisierten Eingabe (Cms::encryptedHash()). Gefunden
     * wird nur, was der Request sehen darf (Cms::$sqlAccess).
     *
     * @param bool $any  true: irgendein Treffer genügt (Dublettenprüfung), auch
     *                   mehrere; false: genau einer, sonst null
     */
    private function findProfile(int $areaId, string $field, string $value, bool $any = false): ?array
    {
        $field = $this->_name($field);
        if ($field === '' || $value === '') return null;
        $fn = $this->cms->resolveVirtualField($field);
        if ($fn === null) return null;
        $type = (string)($fn['type'] ?? '');
        if ($type === 'chars' || $type === 'text') {
            $table = 'data_' . $this->cms->dataSuffix[$type];
            $col   = 'data';
            $needle = $value;
        }
        elseif ($type === 'encrypted') {
            // Eingabe normalisiert hashen wie beim Speichern (Cms::encryptedHash():
            // klein, ohne Leerraum und Satzzeichen) - "Anna@…" findet "anna@…"
            $needle = $this->cms->encryptedHash($value);
            if ($needle === '') return null;
            $table = 'data_enc';
            $col   = 'hash';
        }
        else {
            return null;
        }
        $access = $this->cms->sqlAccess !== '' ? ' AND `r`.' . $this->cms->sqlAccess : '';
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT DISTINCT `r`.`record_id` FROM `{$table}` `d`"
                . " INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`record_id`"
                . " WHERE `d`.`fieldname_id` = ? AND `d`.`{$col}` = ?"
                . " AND `r`.`area_id` = ? AND `r`.`tmp` = 0 AND `r`.`deleted` = 0" . $access
                . " LIMIT 2",
                'isi',
                [(int)$fn['fieldname_id'], $needle, $areaId]
            );
            $ids = array_map('intval', $this->db->fetchColumn());
        }
        finally {
            $this->db->popResult();
        }
        if (empty($ids)) return null;
        if (!$any && count($ids) > 1) return null;
        return $this->loadRecord($ids[0]);
    }

    /**
     * Passwort eines Profils mit der Eingabe vergleichen (`data_pwd`, Hash mit
     * eingebettetem Salt - Crypt::passwordVerify()).
     *
     * @param int    $recordId
     * @param string $pwField   Name des Passwort-Feldes
     * @param string $password  Eingabe (Klartext)
     */
    private function passwordOk(int $recordId, string $pwField, string $password): bool
    {
        $fn = $this->cms->resolveVirtualField($pwField);
        if ($fn === null || (string)($fn['type'] ?? '') !== 'password') return false;
        $this->db->pushResult();
        $this->db->query(
            "SELECT `data` FROM `data_pwd` WHERE `record_id` = ? AND `fieldname_id` = ? LIMIT 1",
            'ii',
            [$recordId, (int)$fn['fieldname_id']]
        );
        $stored = (string)($this->db->fetchNum()[0] ?? '');
        $this->db->popResult();
        if (!$this->crypt->passwordVerify($password, $stored)) return false;

        // Früheres Format oder alte Parameter: jetzt, wo das Passwort bekannt ist,
        // neu hashen (Crypt::passwordNeedsRehash()) - alle Sprachzeilen des Feldes
        if ($this->crypt->passwordNeedsRehash($stored)) {
            $this->db->pushResult();
            $this->db->query(
                "UPDATE `data_pwd` SET `data` = ? WHERE `record_id` = ? AND `fieldname_id` = ?",
                'sii',
                [$this->crypt->passwordHash($password), $recordId, (int)$fn['fieldname_id']]
            );
            $this->db->popResult();
        }
        return true;
    }

    /**
     * Setzt die Eingabe ein Passwort-Feld des Bereichs auf einen nicht leeren Wert?
     * (leer = bisheriges behalten; nur Felder, die 'fields' zulässt)
     */
    private function passwordInput(int $areaId, array $input, array $fields): bool
    {
        $allowed = array_values(array_filter(array_map(fn($f) => $this->_name((string)$f), $fields)));
        foreach ($this->record->virtualSchema($areaId, false) as $name => $colDef) {
            if (($colDef['type'] ?? '') !== 'password' && ($colDef['display_type'] ?? '') !== 'password') continue;
            if ($allowed && !in_array($name, $allowed, true)) continue;
            $values = is_array($input[$name] ?? null) ? $input[$name] : [$input[$name] ?? ''];
            foreach ($values as $value) {
                if (is_string($value) && trim($value) !== '') return true;
            }
        }
        return false;
    }

    /**
     * Darf die laufende Session das Passwort ihres Profils ändern?
     *
     * Ja, wenn
     *   - sie über den Reset-Link angemeldet wurde und die Freigabe noch gilt
     *     (details.password_reset_until, siehe loginProfile()), oder
     *   - das bisherige Passwort (Anmelde-Passwortfeld des Bereichs) im Request steht:
     *     data[{currentfield}], Vorgabe data[current_password].
     * Ein Profil ohne gespeichertes Passwort braucht keins. Fehlversuche zählen im
     * Brute-Force-Schutz wie eine falsche Anmeldung (gleicher Name) - sonst ließe
     * sich das Passwort über dieses Formular durchprobieren.
     *
     * Meldungen: 'current' (user.error_password_current), 'blocked'.
     */
    private function currentPasswordOk(array $area, int $userId, array $input, array $p, bool $allowReset = true): bool
    {
        $until = (string)($this->userSession->get('password_reset_until') ?? '');
        if ($allowReset && $until !== '' && $until >= gmdate('Y-m-d H:i:s')) return true;

        $areaId = (int)$area['area_id'];
        $pwField = $this->fieldName((int)($area['login_password_field_id'] ?? 0), $areaId);
        if ($pwField === '') return true; // kein Anmeldefeld - nichts, woran zu prüfen wäre
        $fn = $this->cms->resolveVirtualField($pwField);
        $this->db->pushResult();
        $this->db->query(
            "SELECT `data` FROM `data_pwd` WHERE `record_id` = ? AND `fieldname_id` = ? LIMIT 1",
            'ii',
            [$userId, (int)($fn['fieldname_id'] ?? 0)]
        );
        $stored = (string)($this->db->fetchNum()[0] ?? '');
        $this->db->popResult();
        if ($stored === '') return true;

        $guard = $this->loginGuard->forUsers();
        $guardName = mb_substr($areaId . ':#' . $userId, 0, self::NAME_MAX);
        if ($blockedSeconds = $guard->blockedFor($guardName)) {
            $this->fail($p, 'blocked', 'user.error_login_blocked', ['minutes' => (int)ceil($blockedSeconds / 60)]);
            return false;
        }
        $currentField = $this->_name((string)($p['currentfield'] ?? '')) ?: 'current_password';
        $current = (string)($input[$currentField] ?? '');
        if ($current === '' || !$this->passwordOk($userId, $pwField, $current)) {
            $guard->recordFailure($guardName);
            $this->fail($p, 'current', 'user.error_password_current');
            return false;
        }
        $guard->clear($guardName);
        return true;
    }

    /**
     * Produktiven Datensatz laden, den dieser Request sehen darf (Instanz-Zugriff).
     */
    private function loadRecord(int $recordId): ?array
    {
        if ($recordId < 1) return null;
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT * FROM `record` WHERE `record_id` = ? AND `tmp` = 0 AND `deleted` = 0",
                'i',
                [$recordId]
            );
            if ($this->db->num_rows < 1) return null;
            $data = $this->cms->fetch();
        }
        finally {
            $this->db->popResult();
        }
        if ($data === null || !$this->cms->instanceAllowed((int)($data['instance_id'] ?? 0))) return null;
        return $data;
    }

    /**
     * Datensatz laden, der dem User gehört (record.user_id) - oder sein eigenes
     * Profil. Mit $ignoreUser genügt, dass es ihn gibt.
     *
     * @param int  $areaId  Bereich, in dem er liegen muss (0 = beliebig)
     */
    private function loadOwned(int $recordId, int $areaId, int $userId, bool $ignoreUser): ?array
    {
        $data = $this->loadRecord($recordId);
        if ($data === null) return null;
        if ($areaId > 0 && (int)$data['area_id'] !== $areaId) return null;
        if ($ignoreUser) return $data;
        if ($userId < 1) return null;
        if ($recordId === $userId) return $data;                    // eigenes Profil
        if ((int)($data['user_id'] ?? 0) === $userId) return $data; // eigener Beitrag
        return null;
    }

    // ====================
    // Bereiche und Felder
    // ====================

    /**
     * Bereich zu Id oder Name ('container'), auf erlaubte Typen und den
     * Instanz-Zugriff des Requests geprüft. `details` ist dekodiert.
     */
    private function loadArea(mixed $container, array $types): ?array
    {
        $container = trim((string)$container);
        if ($container === '') return null;
        if (preg_match('/^\d+$/', $container)) {
            $area = $this->areaById((int)$container);
        }
        else {
            // Name -> Id aus dem Instanz-Dateicache (nur Bereiche der Instanzen des
            // Requests, Cms::areaMap()); der erste Treffer in Zugriffsreihenfolge
            $area = null;
            foreach ($this->cms->areaIds($container) as $areaId) {
                $area = $this->areaById((int)$areaId);
                if ($area !== null) break;
            }
        }
        if ($area === null) return null;
        if (!in_array((string)$area['type'], $types, true)) return null;
        if (!$this->cms->instanceAllowed((int)$area['instance_id'])) return null;
        return $area;
    }

    private function areaById(int $areaId): ?array
    {
        if ($areaId < 1) return null;
        if (array_key_exists($areaId, $this->areaCache)) return $this->areaCache[$areaId];
        $this->db->pushResult();
        $this->db->query("SELECT * FROM `area` WHERE `area_id` = ? AND `deleted` = 0", 'i', [$areaId]);
        $area = $this->db->fetch();
        $this->db->popResult();
        if ($area !== null) {
            $area['details'] = json_decode((string)($area['details'] ?? ''), true) ?: [];
        }
        return $this->areaCache[$areaId] = $area;
    }

    /**
     * Name eines Feldes des Bereichs, '' wenn es das Feld dort nicht gibt.
     */
    private function fieldName(int $fieldId, int $areaId): string
    {
        if ($fieldId < 1) return '';
        foreach ($this->record->virtualSchema($areaId, false) as $name => $colDef) {
            if ((int)($colDef['field_id'] ?? 0) === $fieldId) return (string)$name;
        }
        return '';
    }

    // ====================
    // Aufräumen (Cron)
    // ====================

    /**
     * Unbestätigte Profile (status_id 2 nach USER_REGISTER), die älter als
     * user.unconfirmed_days sind, endgültig löschen. Wer eine fremde Adresse
     * registriert und den Link nie klickt, blockiert sie so nur für diese Frist -
     * danach kann sich der Inhaber selbst registrieren. Aufruf aus der Zeitplaner-
     * Aufgabe "Aufbewahrungsfristen anwenden" (Typ retention), höchstens
     * PURGE_UNCONFIRMED_LIMIT je Aufruf - der Runner wiederholt, solange es volle Runden sind.
     *
     * @return int  Anzahl gelöschter Profile
     */
    /** Unbestätigte Profile je Aufruf von purgeUnconfirmed() */
    public const PURGE_UNCONFIRMED_LIMIT = 200;

    public function purgeUnconfirmed(): int
    {
        $days = (int)$this->config->get('user.unconfirmed_days');
        if ($days < 1) return 0;
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT `r`.`record_id`, `r`.`area_id` FROM `record` `r` INNER JOIN `area` `a` ON `a`.`area_id` = `r`.`area_id`"
                . " WHERE `a`.`type` = 'profile' AND `r`.`status_id` = 2 AND `r`.`tmp` = 0"
                . " AND `r`.`created_at` < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY) LIMIT " . self::PURGE_UNCONFIRMED_LIMIT,
                'i', [$days]
            );
            $rows = $this->db->fetchAll();
        }
        finally {
            $this->db->popResult();
        }
        $count = 0;
        foreach ($rows as $row) {
            try {
                if ($this->record->purge((int)$row['record_id'], false)) $count++;
            }
            catch (\Throwable $e) {
                $this->system->log('user', 'Unconfirmed profile ' . (int)$row['record_id'] . ' not purged: ' . $e->getMessage());
            }
        }
        if ($count > 0) {
            $this->system->log('user', sprintf('%d unconfirmed profile(s) older than %d days purged.', $count, $days));
        }
        return $count;
    }

    // ====================
    // Drosselung, Formularschutz
    // ====================

    /**
     * formguard=true (bzw. =N Sekunden Mindestzeit): stammt das Formular von einem
     * Menschen? (FormGuard)
     */
    private function humanOk(array $p): bool
    {
        $guard = FormGuard::parameter($p['formguard'] ?? '');
        if ($guard === false) return true;
        return $this->container->get(FormGuard::class)->verify($guard);
    }

    /**
     * Alle Buckets erlauben die Anfrage? Dann zählen (RateLimiter). Eine
     * abgewiesene Anfrage zählt in keinem.
     *
     * @param array $buckets  Liste [bucket, schlüssel]
     */
    private function throttleHit(array $buckets): bool
    {
        if (!$this->throttleAllows($buckets)) return false;
        $this->throttleRecord($buckets);
        return true;
    }

    private function throttleAllows(array $buckets): bool
    {
        $limiter = $this->container->get(RateLimiter::class);
        foreach ($buckets as [$bucket, $key]) {
            if (!$limiter->allows($bucket, (string)$key)) return false;
        }
        return true;
    }

    private function throttleRecord(array $buckets): void
    {
        $limiter = $this->container->get(RateLimiter::class);
        foreach ($buckets as [$bucket, $key]) {
            $limiter->record($bucket, (string)$key);
        }
    }


    // ====================
    // Tokens
    // ====================

    /**
     * Einmal-Token anlegen (Freischaltung, Passwort-Reset). Ältere Tokens desselben
     * Zwecks für das Profil verfallen.
     */
    private function createToken(int $recordId, string $purpose): string
    {
        $token = $this->crypt->randomKey(32);
        $this->db->pushResult();
        $this->db->query(
            "DELETE FROM `user_token` WHERE `record_id` = ? AND `purpose` = ?",
            'is',
            [$recordId, $purpose]
        );
        $this->db->query(
            "INSERT INTO `user_token` (`token`, `record_id`, `purpose`, `created_at`) VALUES (?, ?, ?, UTC_TIMESTAMP())",
            'sis',
            [$token, $recordId, $purpose]
        );
        $this->db->popResult();
        return $token;
    }

    /**
     * Token einlösen: gültig -> record_id, das Token wird gelöscht; sonst 0.
     * Abgelaufene Tokens (user.token_expiration) werden dabei mit entfernt.
     */
    private function consumeToken(string $token, string $purpose): int
    {
        if (strlen($token) !== 43 || preg_match('/^[A-Za-z0-9_\-]+$/', $token) !== 1) return 0;
        $expiration = (int)$this->config->get('user.token_expiration');
        if ($expiration < 1) $expiration = 86400;
        $valid = $purpose === 'password' ? $this->passwordTokenExpiration() : $expiration;
        $this->db->pushResult();
        try {
            $this->db->query(
                "DELETE FROM `user_token` WHERE `created_at` < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND)",
                'i',
                [max($expiration, $valid)]
            );
            $this->db->query(
                "SELECT `record_id` FROM `user_token` WHERE `token` = ? AND `purpose` = ?"
                . " AND `created_at` >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND)",
                'ssi',
                [$token, $purpose, $valid]
            );
            $recordId = (int)($this->db->fetchNum()[0] ?? 0);
            if ($recordId > 0) {
                $this->db->query("DELETE FROM `user_token` WHERE `token` = ?", 's', [$token]);
            }
            return $recordId;
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Gültigkeit des Reset-Tokens und der Freigabe danach (user.password_token_expiration).
     */
    private function passwordTokenExpiration(): int
    {
        $seconds = (int)$this->config->get('user.password_token_expiration');
        return $seconds > 0 ? $seconds : 3600;
    }

    // ====================
    // Hilfsfunktionen
    // ====================

    /**
     * $set['user_id'], $set['user'], $set['user_session_id'] setzen.
     */
    private function setUserVars(int $userId, array $profile, string $sessionId = ''): void
    {
        $GLOBALS['set']['user_id'] = $userId;
        $GLOBALS['set']['user'] = $profile;
        $GLOBALS['set']['user_session_id'] = $sessionId !== '' ? $sessionId : ($userId > 0 ? $this->userSession->id() : '');
        // Darf diese Session ein neues Passwort ohne das alte setzen? (Reset-Link,
        // loginProfile()) - das Formular lässt dann das Feld "bisheriges Passwort" weg
        $until = $userId > 0 ? (string)($this->userSession->get('password_reset_until') ?? '') : '';
        $GLOBALS['set']['password_reset'] = ($until !== '' && $until >= gmdate('Y-m-d H:i:s')) ? 1 : 0;
    }

    /**
     * 'submit': der Befehl läuft nur, wenn der Wert nicht leer ist.
     */
    private function submitted(array $p): bool
    {
        if (trim((string)($p['submit'] ?? '')) === '') return false;
        return $this->csrfOk($p);
    }

    /**
     * CSRF-Prüfung eines schreibenden Befehls (17-csrf.md §2.5): gültiges Token aus
     * CMS:USER_TOKEN im Request, sonst Meldung 'token' und false. Abschaltbar je
     * Befehl mit csrf=false oder global mit user.csrf = false.
     */
    private function csrfOk(array $p): bool
    {
        if (!$this->_bool($p['csrf'] ?? true) || !$this->config->get('user.csrf')) return true;
        if ($this->userSession->verifyCsrfRequest()) return true;
        $this->fail($p, 'token', 'user.error_token');
        return false;
    }

    /**
     * Name des Request-Arrays mit den Eingaben ('data', Standard "data").
     */
    private function dataName(array $p): string
    {
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($p['data'] ?? 'data'));
        return $name === '' ? 'data' : $name;
    }

    /**
     * Eingaben aus dem Request ($_POST hat Vorrang vor $_REQUEST).
     */
    private function requestData(array $p): array
    {
        $name = $this->dataName($p);
        $data = $_POST[$name] ?? $_REQUEST[$name] ?? [];
        return is_array($data) ? $data : [];
    }

    /**
     * Fehlermeldung ablegen: Text aus 'message[index]' des Befehls, sonst das
     * Snippet.
     */
    private function fail(array $p, string $index, string $snippet, array $params = []): void
    {
        $messages = (array)($p['message'] ?? []);
        $this->failMessage((string)($messages[$index] ?? ''), $snippet, $params);
    }

    private function failMessage(string $custom, string $snippet, array $params = []): void
    {
        $text = trim($custom) !== '' ? $custom : $this->cms->snippet($snippet, $params);
        $this->cms->error($text);
    }

    /**
     * Kommaliste oder Array -> bereinigte Liste.
     */
    private function _list(mixed $value): array
    {
        if (is_array($value)) $items = $value;
        else $items = explode(',', (string)$value);
        $items = array_map('trim', array_map('strval', $items));
        return array_values(array_filter($items, fn($v) => $v !== ''));
    }

    /**
     * Template-Parameter als Wahrheitswert lesen ("true"/"false" aus dem Template).
     */
    private function _bool(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        return filter_var((string)$value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Feldnamen auf [a-z0-9_-] beschränken (wie Cms::_name()).
     */
    private function _name(string $s): string
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower(trim($s)));
    }

    /**
     * successtemplate / errortemplate: Referenz "[bereich.]name" zur kompilierten
     * Datei auflösen (Cms::templateFile()); '' bleibt '' (kein Template).
     */
    private function _template(string $s): string
    {
        return $this->cms->templateFile($s);
    }
}
