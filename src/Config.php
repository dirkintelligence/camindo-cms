<?php
/**
 * Zugriff auf die CMS-Konfiguration aus .camindo.php.
 *
 * Die einzige Klasse, die der Container NICHT selbst bauen kann: ihr Konstruktor erwartet
 * ein Array statt einer Klasse. Sie wird deshalb in Core.php per Factory registriert:
 *
 *     $container->set(Config::class, fn() => new Config($_cfg));
 *
 * Direkt danach wird $_cfg in Core.php verworfen - ab dann ist diese Klasse die einzige
 * Quelle für Konfigurationswerte. Gelesen wird ausschließlich, es gibt bewusst keinen Setter.
 *
 * NEBENWIRKUNGEN DES KONSTRUKTORS
 * --------------------
 * Er liest nicht nur, er richtet die Laufzeitumgebung ein:
 *   - setlocale() + \Locale::setDefault()   (system.locale)
 *   - date_default_timezone_set()           (system.timezone; Cms überschreibt das später
 *                                            wieder mit der Benutzer-Zeitzone)
 *   - ini_set('error_log', ...)             PHP-Fehler nach var/log/{Y-m-d}_php_errors.log
 * Config muss deshalb möglichst früh instanziiert werden.
 *
 * GEHEIMNISSE AUS DER UMGEBUNG
 * --------------------
 * Passwörter müssen nicht in .camindo.php stehen: ist eine der Umgebungsvariablen aus
 * ENV_SECRETS gesetzt (nicht leer), gilt ihr Wert vor dem aus .camindo.php - der Rest
 * der Konfiguration (Host, Benutzer, Postfach …) bleibt in der Datei. Gedacht für
 * Systeme, die Variablen außerhalb von PHP setzen (Docker, PHP-FPM env[…], Apache
 * SetEnv, systemd); eine .env-Datei wird bewusst NICHT gelesen.
 *
 *     CAMINDO_DB_PASS     db.pass
 *     CAMINDO_SMTP_PASS   email.smtp.pass
 *     CAMINDO_KEY         Schlüssel (Crypt) - umgekehrt: die Datei paths.key_file
 *                         hat Vorrang, die Variable gilt nur ohne Datei
 *     CAMINDO_AI_API_KEY  ai.api_key (Zusatzmodul Ai, AiSettings)
 *
 * Gelesen wird ausschließlich über env(): der Wert wird je Prozess zwischengespeichert
 * und danach aus Prozessumgebung, $_ENV und $_SERVER entfernt - Kindprozesse
 * (ImageMagick, ffmpeg, mysqldump …) erben ihn nicht, phpinfo() zeigt ihn nicht
 * (außer unter "Apache Environment" bei SetEnv).
 */
namespace Camindo\Core;

class Config {
    /** Konfigurationswerte, die eine Umgebungsvariable vor .camindo.php setzt */
    public const ENV_SECRETS = [
        'db.pass'         => 'CAMINDO_DB_PASS',
        'email.smtp.pass' => 'CAMINDO_SMTP_PASS',
    ];

    /** Umgebungsvariable für den Schlüssel (Crypt; die Datei paths.key_file hat Vorrang) */
    public const ENV_KEY = 'CAMINDO_KEY';

    /** Zusammengeführte Konfiguration: Defaults, überschrieben von .camindo.php */
    private array $settings;

    /** Schlüssel aus ENV_SECRETS, deren Wert aus der Umgebung kommt */
    private array $fromEnv = [];

    /** Bereits gelesene Umgebungsvariablen (env()): Name => Wert, null = nicht gesetzt */
    private static array $env = [];

    /**
     * @param array $cfg  Das Array $_cfg aus .camindo.php. Per Referenz nur, um das
     *                    Kopieren zu sparen - der Inhalt wird nicht verändert.
     * @throws \Exception wenn system.locale oder system.timezone leer sind (ohne sie
     *                    sind Datums- und Zahlenformate undefiniert). Fehlen die
     *                    Schlüssel ganz - es gibt noch keine .camindo.php -, gelten
     *                    en_US und UTC, damit der Installer laufen kann.
     */
    public function __construct(array &$cfg)
    {
        // Sprach-Einstellungen. Fehlt der Schlüssel ganz (keine .camindo.php - vor dem
        // Installer), gilt der Default; ein leerer Wert ist ein Konfigurationsfehler.
        $locale = $cfg['system']['locale'] ?? 'en_US';
        if (empty($locale)) throw new \Exception("Configuration error: \$_cfg['system']['locale'] is empty.");
        setlocale(LC_TIME, $locale . ".utf8");
        \Locale::setDefault($locale);

        // Zeiteinstellungen
        $timezone = $cfg['system']['timezone'] ?? 'UTC';
        if (empty($timezone)) throw new \Exception("Configuration error: \$_cfg['system']['timezone'] is empty.");
        date_default_timezone_set($timezone);
        $corDir = dirname(__DIR__, 2);   // .../camindo (von Core/src aus zwei Ebenen hoch)

        // Config Default-Werte
        // Alles, was .camindo.php nicht setzt, kommt von hier. Die Pfade sind so gewählt,
        // dass eine Standardinstallation ohne Pfadangaben in .camindo.php lauffähig ist.
        $defaults = [
            'system' => [
                /*
                    Default-Sprache
                */
                'locale' => 'en_US',
                /*
                    Default-Zeitzone
                */
                'timezone' => 'Europe/Berlin',
                /*
                    Entwicklungsmodus - schaltet zusätzliche Debug-Möglichkeiten frei.
                    Nicht in produktiver Umgebung aktivieren!
                */
                'development_version' => false,
                /*
                    ALLE SQL-Befehle loggen und Zeit messen.
                    Reine Entwicklerfunktion, setzt system.development_version = true voraus.
                */
                'debugsql' => false,
                /*
                    Maximale Länge eines automatisch erzeugten Slugs (Zeichen).
                    Greift, wenn ein Datensatz mit leerem Feld 'slug' gespeichert wird -
                    dann wird der Inhalt des Default-Feldes eingesetzt und gekürzt.
                    Siehe Cms::textToUri()
                */
                'link_length' => 120,
                /*
                    Whitelist für HTML in Inhalten: Liste der erlaubten Tag-Namen, z. B.
                    ['p','br','b','strong','i','em','u','a','ul','ol','li','h2','h3',
                    'img','table','thead','tbody','tr','th','td','blockquote','span','div'].
                    Andere Tags entfernt Cms::_sanitize_html() beim Speichern und beim
                    Lesen (der Text dazwischen bleibt). Leer = keine Whitelist; script,
                    iframe, object, embed, base, meta … fallen immer weg
                    (16-sicherheit.md §6). Gilt nicht für Templates.
                */
                'html_allowed_tags' => [],
            ],
            'email' => [
                /*
                    Absendeadresse für E-Mails, die vom CMS versendet werden,
                    z.B. bei der Zwei-Faktor-Anmeldung
                    Standard-Absender für CMS:EMAIL
                */
                'noreply' => '',
                /*
                    Empfängeradresse für Feedback
                    Wird bei Zwei-Faktor-Anmeldung mit noreply-Absender im E-Mail Text erwähnt
                */
                'reply' => '',
                /*
                    SMTP-Postfach der Absender-Adresse für sauberen Versand
                    Fallback: PHP mail()
                */
                'smtp' => [
                    'host' => '',
                    'user' => '',
                    'pass' => '',
                    'enc'  => 'starttls',  // 'starttls' oder 'smtps'
                    'port' => 587
                ],
            ],
            'admin' => [
                /*
                    Zwei-Faktor-Anmeldung per E-Mail aktivieren.
                    Nach akzeptiertem Passwort wird Login-Code per E-Mail versendet.
                    Setzt email.noreply / email.reply voraus.
                    Admins mit Authenticator-App (TOTP, security.admin_2fa) werden
                    unabhängig davon immer nach dem App-Code gefragt.
                */
                '2_factor' => true,
                /*
                    Gültigkeit des 2-Faktor-Codes in Sekunden
                    (Bei Wert 0 kann keine Anmeldung gelingen)
                */
                '2_factor_expiration' => 600,
                /*
                    Zeitfenster für Untätigkeit im Backend vor automatischer Abmeldung
                    in Sekunden. Ungespeicherte Inhalte bleiben auch bei Re-Login in der
                    Eingabemaske stehen.
                */
                'max_inactive_sec' => 1800,
                /*
                    Bearbeitungssperre in Sekunden: eine Arbeitskopie, an der so lange
                    nichts geändert wurde, sperrt den Datensatz nicht mehr (andere
                    Benutzer können ihn wieder öffnen). Bewusst länger als
                    max_inactive_sec - nach Pause und erneuter Anmeldung lässt sich
                    die offene Maske noch speichern.
                */
                'edit_lock_sec' => 5400,
                /*
                    Brute-Force-Schutz der Anmeldung (Camindo\Core\LoginGuard)
                    Erlaubte Fehlversuche je Name, 0 = Schutz aus
                */
                'bruteforce_max' => 3,
                /*
                    Zeitfenster und Sperrdauer in Sekunden
                */
                'bruteforce_delay' => 600,
                /*
                    Bei zu vielen Fehlversuchen:
                    IP mitschreiben und in security-Log aufnehmen
                */
                'bruteforce_ip' => true,
                /*
                    Drosselung der Anmeldung je Herkunft (Camindo\Core\RateLimiter,
                    Bucket admin_login_ip) - [höchstens, Zeitfenster in Sekunden],
                    höchstens 0 = aus.
                        login_ip   Fehlanmeldungen je IP über alle Namen
                                   (Password-Spraying: viele Namen, je ein Passwort)
                    Hinter einem Reverse Proxy security.trusted_proxies setzen, sonst
                    teilen sich alle Besucher eine Adresse (ClientIp).
                */
                'throttle' => [
                    'login_ip' => [20, 600],
                ],
                /*
                    Session-Cookie 'sessid' des Backends: 'secure' nur über HTTPS senden
                    (bei http in der Entwicklung auf false), SameSite-Attribut.
                    HttpOnly ist immer gesetzt (Session::setCookie()).
                */
                'cookie_secure' => true,
                'cookie_samesite' => 'Lax',
                /*
                    Feste Backend-Domain (Camindo\Core\BackendDomain,
                    developer_docs/21-backend-domain.md), z. B. 'cms-k7x2.example.com'.
                    Leer: Backend unter jeder Domain der Installation ({domain}/backend/).
                    Gesetzt: Backend nur dort (sonst 404), Vorschau und
                    Frontend-Editing laufen über die Domain der Website
                    (FrontendHandover). Voraussetzung für WebAuthn.
                */
                'backend_domain' => '',
                /*
                    Herkunftsprüfung (Backend::originAllowed(), 17-csrf.md §2.6):
                    verändernde Backend-Requests müssen - sofern der Browser Origin
                    bzw. Referer mitschickt - vom Host des Backends kommen. Weitere
                    erlaubte Herkünfte als Liste, z. B. ['https://cms.example.com'],
                    falls ein Proxy den Host umschreibt.
                */
                'allowed_origins' => [],
                /*
                    Passwort-Gültigkeit in Tagen: danach muss der Admin bei der
                    Anmeldung ein neues Passwort setzen, das sich vom bisherigen
                    unterscheidet (16-sicherheit.md §17). 0 = unbegrenzt.
                    Unabhängig davon: ein Passwort, das ein anderer Admin gesetzt hat
                    (Anlegen, Zurücksetzen), muss bei der ersten Anmeldung geändert werden.
                */
                'password_retention_days' => 0,
                /*
                    Maximale Anzahl Datensätze in Direktauswahl (Select-Liste)
                */
                'list_max_datasets' => 200,
                /*
                    Anzahl Datensätze auf einer Seite, bei Liste mit Pagination
                */
                'skiplist_default' => 20,
                /*
                    Maximale Verschachtelungstiefe von Templates
                    Siehe CMS:SELECT
                */
                'max_include_depth' => 20,
                /*
                    Ausgabe-Cache von CMS:INCLUDE (caching=…, IncludeCache) - false
                    schaltet ihn ab: alle Templates laufen wieder bei jedem Aufruf
                */
                'include_cache' => true,
                /*
                    So viele Sekunden wartet ein Aufruf höchstens, während ein anderer
                    Prozess dieselbe Cache-Datei erzeugt; danach rendert er selbst
                    (ohne zu speichern)
                */
                'include_cache_lock_wait' => 30,
                /*
                    Aufbewahrungsfrist gelöschter Datensätze im Papierkorb in Tagen -
                    danach endgültig gelöscht (Zeitplaner-Aufgabe "Papierkorb leeren"). Die Aktion "In den
                    Papierkorb" einer Lösch-Policy (Modul Privacy) verlässt sich darauf.
                */
                'trash_retention_days' => 7, 
                /*
                    Maximale Anzahl Datensätze, die bei einer Massen-Löschung / -Wiederherstellung
                    sofort verarbeitet werden. Bei mehr Datensätzen startet Hintergrundprozess
                    (0 = immer im Request abarbeiten)
                */
                'mass_delete_threshold' => 200,
                /*
                    Export von Datensätzen (RecordExport): ab dieser Anzahl Datensätze
                    läuft der Export immer als Hintergrundprozess (0 = nie automatisch)
                */
                'export_background_threshold' => 1000,
                /*
                    Maximale Verweildauer einer Exportdatei in var/export in Stunden -
                    danach räumt die Zeitplaner-Aufgabe "Temporäre Dateien löschen" sie ab
                    (Vorgabe stündlich); ein heruntergeladenes
                    Zip wird sofort gelöscht
                */
                'export_retention_hours' => 24,
                /*
                    Import von Datensätzen (RecordImport): ab dieser Anzahl Datensätze
                    läuft der Import immer als Hintergrundprozess (0 = nie automatisch)
                */
                'import_background_threshold' => 200,
                /*
                    Aufräumen von var/tmp (Zeitplaner-Aufgabe tmpcleanup, Vorgabe stündlich): Dateien und
                    Verzeichnisse, die älter sind als so viele Stunden, werden entfernt
                    (Sperrdateien *.lock, Stempeldateien und cron.log.txt bleiben)
                */
                'tmp_retention_hours' => 24,
            ],
            'api' => [
                /*
                    Sessions der Kommandozeile und der PHP-API (Camindo\Core\Api\ApiSession):
                    Zeitfenster für Untätigkeit in Sekunden
                */
                'max_inactive_sec' => 3600,
                /*
                    Höchste Laufzeit einer solchen Session in Sekunden, unabhängig von
                    Aktivität - auch die Obergrenze für eine beim Anmelden angegebene
                    Laufzeit (maxLifetime)
                */
                'max_lifetime' => 86400,
                /*
                    Höchstzahl Datensätze je Seite einer API-Liste (record.search …)
                */
                'max_limit' => 200,
                /*
                    Höchstgröße einer Datei, die als Base64 übergeben wird (Bytes, 0 = ohne
                    Grenze) - record.update/create {"content": …}
                */
                'base64_max_bytes' => 20971520,
            ],
            'user' => [
                /*
                    Frontend-Community (Camindo\Core\UserSession, Modules\Record\RecordUser)
                    Zeitfenster für Untätigkeit vor dem Verfall einer User-Session
                    in Sekunden (Cookie und Tabelle `user_session`)
                */
                'max_inactive_sec' => 86400,
                /*
                    Aufbewahrung anonymer Sessions (nur Warenkorb) in Tagen, wenn
                    CMS:USER_BASKET keinen Parameter 'duration' angibt
                */
                'basket_days' => 30,
                /*
                    Gültigkeit der Einmal-Tokens (Freischaltung nach Registrierung,
                    Passwort-Reset) in Sekunden
                */
                'token_expiration' => 86400,
                /*
                    Gültigkeit des Passwort-Reset-Tokens (CMS:USER_PW) in Sekunden -
                    kürzer als die Freischaltung: der Link ersetzt das Passwort.
                    Nach dem Einlösen (USER_LOGIN hash=…) darf die Session so lange
                    ein neues Passwort ohne das alte setzen (USER_INPUT).
                */
                'password_token_expiration' => 3600,
                /*
                    Brute-Force-Schutz der Frontend-Anmeldung (Tabelle
                    `user_login_attempt`), Bedeutung wie admin.bruteforce_*
                */
                'bruteforce_max' => 5,
                'bruteforce_delay' => 600,
                'bruteforce_ip' => false,
                /*
                    Session-Cookie: 'secure' nur über HTTPS senden (bei http-Instanzen
                    in der Entwicklung auf false), SameSite-Attribut
                */
                'cookie_secure' => true,
                'cookie_samesite' => 'Lax',
                /*
                    CSRF-Schutz der schreibenden CMS:USER_*-Befehle: Token aus
                    CMS:USER_TOKEN im Request verlangen (17-csrf.md §2.5).
                    false = aus (nur für Übergangszeiten; je Befehl: csrf=false)
                */
                'csrf' => true,
                /*
                    Owner (admin_id) der Datensätze, die User über CMS:USER_REGISTER /
                    CMS:USER_INPUT anlegen, wenn der Befehl keinen Parameter 'owner'
                    nennt. 0 = kein Fallback, der Befehl scheitert dann ohne 'owner'.
                */
                'default_owner_id' => 0,
                /*
                    Drosselung der Befehle, die E-Mails an frei eingegebene Adressen
                    auslösen oder Anmeldungen durchprobieren lassen (Camindo\Core\
                    RateLimiter, developer_docs/20-frontend-schutz.md A). Je Bucket
                    [höchstens, Zeitfenster in Sekunden]; höchstens 0 = aus.
                        pw_value        CMS:USER_PW je eingegebenem Wert
                        pw_ip           CMS:USER_PW je Herkunft (IP)
                        register_ip     CMS:USER_REGISTER je Herkunft
                        register_value  CMS:USER_REGISTER je E-Mail-Adresse
                        mail_instance   USER_PW + USER_REGISTER je Instanz zusammen
                        login_ip        Fehlanmeldungen je Herkunft, über alle Namen
                        login_code      neuer E-Mail-Code der Anmeldung je Profil
                        email_ip        CMS:EMAIL throttle=true je Herkunft
                        csp_report      Meldungen an /csp_report.php je Herkunft
                        zip_ip          neu erzeugte ZIP-Archive (CMS:ZIP) je Herkunft
                    Eigene Buckets für CMS:EMAIL throttle=name werden hier ergänzt.
                */
                'throttle' => [
                    'pw_value'       => [3, 3600],
                    'pw_ip'          => [10, 3600],
                    'register_ip'    => [5, 3600],
                    'register_value' => [3, 86400],
                    'mail_instance'  => [200, 3600],
                    'login_ip'       => [30, 600],
                    'login_code'     => [3, 600],
                    'email_ip'       => [5, 3600],
                    'csp_report'     => [60, 600],
                    'zip_ip'         => [30, 3600],
                ],
                /*
                    Formularschutz ohne fremden Dienst (CMS:USER_TOKEN formguard=true):
                    ein Formular, das schneller als min_seconds oder später als
                    max_seconds nach dem Ausliefern abgeschickt wird oder dessen
                    Köderfeld gefüllt ist, gilt als Robot.
                */
                /*
                    CMS:ZIP (Camindo\Core\CmsZip): höchstens so viele Dateien und MB je
                    Archiv (die Befehlsparameter maxfiles/maxsize dürfen nur kleiner
                    sein); erzeugte Archive bleiben so viele Stunden in var/tmp/zip
                    und werden bei gleicher Anfrage wiederverwendet.
                */
                'zip' => [
                    'max_files'       => 100,
                    'max_mb'          => 500,
                    'retention_hours' => 24,
                ],
                'formguard_min_seconds' => 3,
                'formguard_max_seconds' => 3600,
                /*
                    Unbestätigte Profile (nach CMS:USER_REGISTER, status_id 2) werden
                    nach so vielen Tagen endgültig gelöscht - sonst blockiert eine
                    fremde Registrierung die Adresse des Inhabers. 0 = nie.
                */
                'unconfirmed_days' => 7,
                /*
                    Gültigkeit des Anmeldecodes (zweiter Faktor per E-Mail/SMS) in
                    Sekunden
                */
                'login_code_expiration' => 600,
            ],
            'security' => [
                /*
                    HSTS (Strict-Transport-Security, Camindo\Core\SecurityHeaders):
                    Gültigkeit in Sekunden, 0 = aus. Erst einschalten, wenn die Domain
                    dauerhaft und vollständig über HTTPS läuft - der Browser merkt sich
                    die Regel bis max-age. Empfohlen: 31536000 (ein Jahr), vorher zum
                    Test 300. Gesendet wird nur über HTTPS.
                */
                'hsts_max_age' => 0,
                /*
                    HSTS auch für alle Subdomains - nur, wenn wirklich jede Subdomain
                    HTTPS kann.
                */
                'hsts_include_subdomains' => false,
                /*
                    Script-Policy des Backends (Camindo\Core\SecurityHeaders,
                    developer_docs/19-csp.md): 'enforce' - nur Scripts aus eigenen
                    Dateien oder mit der Nonce der Seite laufen; 'report' - alles läuft,
                    Verstöße landen in var/log/{Datum}_csp.log; 'off'.
                    'report' für Module/Extensions, die noch onclick="…" schreiben.
                */
                'backend_csp' => 'enforce',
                /*
                    ImageMagick (Prozessor Magick): Formate, die die eigene Policy
                    freigibt (Camindo\Core\Processors\Magick\MagickPolicy,
                    developer_docs/18-imagemagick.md). Möglich: 'svg', 'pdf'.
                    Die Policy selbst ist immer aktiv: Skriptformate, Delegates,
                    PostScript gesperrt, Grenzen für Speicher und Bildgröße.
                    PDF läuft über Ghostscript - nur freigeben, wenn gebraucht.
                    Die Policy des Servers kann ein Format trotzdem sperren; die
                    Infoseite des Konverters zeigt, ob es wirklich läuft.
                */
                'magick_allow' => [],
                /*
                    Passwort-Hashing (Crypt::passwordHash(), 16-sicherheit.md §1):
                    'algo' 'auto' = Argon2id, wenn PHP es mitbringt, sonst bcrypt;
                    'argon2id' / 'bcrypt' erzwingen. memory_cost (KiB) und time_cost
                    für Argon2id (Vorgabe = OWASP-Minimum 19 MiB, 2 Durchläufe), cost
                    für bcrypt. Geänderte Werte greifen für bestehende Hashes bei der
                    nächsten Anmeldung (Crypt::passwordNeedsRehash()).
                */
                'password_hash' => [
                    'algo' => 'auto',
                    'memory_cost' => 19456,
                    'time_cost' => 2,
                    'cost' => 11,
                ],
                /*
                    Passwort-Richtlinie (Camindo\Core\PasswordPolicy): gilt für
                    Admin-Passwörter immer, für Passwort-Felder der Bereiche, wenn das
                    Feld die Extension "Password" trägt (deren Optionen überschreiben
                    einzelne Werte). 0 = keine Anforderung. Sonderzeichen = alles außer
                    Buchstaben und Ziffern (Zeichenklasse [^\p{L}\p{N}]).
                    upper_not_first: ein Großbuchstabe am Anfang zählt nicht mit.
                */
                'password_policy' => PasswordPolicy::DEFAULTS, // 12 Zeichen, 1 Sonderzeichen, 1 Groß- (nicht am Anfang), 1 Kleinbuchstabe
                /*
                    Anmeldegeräte (Camindo\Core\LoginDevices, 16-sicherheit.md §11):
                    ein Cookie mit Zufallswert erkennt den Browser wieder; meldet sich
                    ein Konto von einem unbekannten Gerät an, geht ein Hinweis raus.
                    device_notify: Hinweis senden (Admins per E-Mail, Frontend-User
                    über das Template, $set['new_device']).
                    device_days: Geräte, die so lange nicht benutzt wurden, gelten als
                    unbekannt und werden gelöscht; zugleich Laufzeit des Cookies.
                    login_notifier: Klasse, die statt der E-Mail benachrichtigt (z. B.
                    per SMS-API) - implementiert Camindo\Core\LoginNotifierInterface;
                    '' = Camindo\Core\LoginNotifier (E-Mail an Admins).
                */
                'device_notify' => true,
                'device_days' => 180,
                'device_cookie' => 'c9dev',
                'login_notifier' => '',
                /*
                    Zweiter Faktor der Backend-Anmeldung (16-sicherheit.md §16,
                    Camindo\Core\Modules\Admin\TotpFactor):
                    'email'          nur der Code per E-Mail (admin.2_factor);
                                     TOTP ist nicht einrichtbar, eingerichtetes ruht
                    'totp'           jeder Admin kann in seinen Einstellungen eine
                                     Authenticator-App einrichten (TOTP, RFC 6238);
                                     wer das nicht tut, bekommt den E-Mail-Code
                    'totp_required'  wie 'totp', Superuser MÜSSEN TOTP haben - wer es
                                     noch nicht hat, richtet es bei der nächsten
                                     Anmeldung im Anmeldedialog ein
                    totp_issuer: Name, unter dem das Konto in der App erscheint
                    (dazu Anmeldename@Host); '' = 'camindo'.
                */
                'admin_2fa' => 'totp',
                'totp_issuer' => '',
                /*
                    Passkeys / Sicherheitsschlüssel (WebAuthn, developer_docs/22-webauthn.md)
                    als zweiter Faktor im Backend - nur mit fester Backend-Domain
                    (admin.backend_domain). false schaltet das Anlegen und die Anmeldung
                    mit Passkey ab; Konten mit nur Passkeys melden sich dann mit einem
                    Wiederherstellungscode an.
                */
                'webauthn' => true,
                /*
                    Schlüsselrotation (`camindo.php key rotate`, Camindo\Core\KeyRotation):
                    Frist in Tagen, in der Passwörter mit dem abgelösten Schlüssel noch
                    gelten - wer sich darin anmeldet, bekommt automatisch einen neuen
                    Hash. Danach mit `key retire KID --force` den alten Schlüssel
                    entfernen; übrige Passwörter werden ungültig. Nur Anzeige
                    (`key status`), entfernt wird nichts automatisch.
                */
                'key_password_grace_days' => 90,
                /*
                    Proxies, deren X-Forwarded-For gilt (Camindo\Core\ClientIp):
                    Liste von IP-Adressen oder CIDR-Bereichen, z. B. ['10.0.0.0/8'].
                    Leer = REMOTE_ADDR ist der Besucher. Hinter einem Proxy MUSS er
                    hier stehen, sonst haben alle Besucher dieselbe Herkunft und die
                    Drosselung je IP trifft alle zugleich.
                */
                'trusted_proxies' => [],
                /*
                    Script-Policy des Frontends (SecurityHeaders::frontendPolicy(),
                    developer_docs/19-csp.md §9): Vorgabe für Instanzen ohne eigene
                    Einstellung - 'off', 'report' oder 'enforce'.
                */
                'frontend_csp' => 'off',
                /*
                    Externes Captcha für formguard=true (Turnstile, Friendly Captcha …):
                    Klasse mit Camindo\Core\FormChallengeInterface; '' = keins.
                */
                'form_challenge' => '',
            ],
            'audit' => [
                /*
                    Audit-Log (Camindo\Core\AuditLog): jede Änderung an Datensätzen,
                    Bereichen, Feldern, Templates, Admins, Gruppen … mit Vorher/Nachher,
                    dazu die Anmeldungen am Backend. Tabelle `audit_log`.
                */
                'enabled' => true,
                /*
                    Aufbewahrung der Einträge zu Objekten, die es noch gibt, in Tagen.
                    0 = unbegrenzt - jeder Datensatz bleibt bis zu seiner Entstehung
                    nachvollziehbar.
                */
                'retention_days_changes' => 0,
                /*
                    Erfolgreiche An- und Abmeldungen am Backend ins Audit-Log
                    schreiben. Vorgabe false: Anmeldungen ohne Tätigkeit sagen nichts
                    über Änderungen, wohl aber über Arbeitszeiten von Mitarbeitern.
                    Fehlgeschlagene Anmeldungen stehen immer drin (und nach
                    mehreren Fehlversuchen im Security-Log).
                */
                'log_logins' => false,
                /*
                    Aufbewahrung der An-/Abmeldungen und Fehlversuche in Tagen.
                    0 = wie retention_days_changes.
                */
                'retention_days_logins' => 90,
                /*
                    Aufbewahrung des Verlaufs endgültig gelöschter Objekte in Tagen,
                    gezählt ab dem endgültigen Löschen (nach dem Papierkorb).
                    0 = unbegrenzt.
                */
                'retention_days_deleted' => 365,
                /*
                    Inhalte des Audit-Logs liegen unter var/audit/ (AuditStore), je
                    Objekt eine Datei; ab dieser Größe in Bytes beginnt eine neue Seite.
                */
                'page_bytes' => 1048576,
            ],
            'privacy' => [
                /*
                    IP-Adressen kürzen (Camindo\Core\IpAnonymizer, Zeitplaner-Aufgabe
                    retention): Einträge, die älter sind als so viele Tage, behalten
                    nur noch das Netz - im Audit-Log, bei den Anmeldeversuchen und in
                    den Logdateien unter var/log. Bekannte Geräte (login_device) und
                    die Inhalte des Audit-Logs unter var/audit speichern die IP von
                    Beginn an gekürzt. 0 = sofort beim nächsten Lauf kürzen.
                    Für die Verfolgung eines konkreten Angriffs vorübergehend erhöhen
                    (bereits Gekürztes kommt nicht zurück).
                */
                'ip_anonymize_days' => 7,
                /*
                    Wie viele Oktette am Ende einer IPv4-Adresse genullt werden:
                    1 (203.0.113.45 -> 203.0.113.0) oder 2 (-> 203.0.0.0).
                    IPv6 behält immer die ersten 48 Bit (die letzten 80 werden
                    genullt: 2001:db8:abcd:12::1 -> 2001:db8:abcd::).
                */
                'ip_v4_octets' => 1,
                /*
                    Interne Nachrichten (Modul Messaging) werden so viele Tage nach dem
                    Versand gelöscht - für Absender und Empfänger, auch ungelesene
                    (Message::purgeExpired(), Zeitplaner-Aufgabe retention).
                    0 = nie automatisch löschen.
                */
                'message_days' => 60,
            ],
            'paths' => [
                /*
                    (DEPRECATED - immer '')
                    Pfad zum CMS-Frontend
                    relativ zu DOCUMENT_ROOT
                */
                'cms' => '',
                /*
                    Frontend-Pfad zum Medien-Stammverzeichnis
                    relativ zu DOCUMENT_ROOT
                */
                'media' => '/media',
                /*
                    Frontend-Pfad zum Backend
                    relativ zu DOCUMENT_ROOT
                */
                'backend' => '/backend',
                /*
                    Absoluter Pfad zum CMS-Frontend (DOCUMENT_ROOT des Webservers)
                    Standard: camindo/public - Stubs, .htaccess, media/, backend/assets-Symlink;
                    der Code liegt in Core/public_master (docs/developers/14-installation-und-update.md, 1.4)
                */
                'document_root' => $corDir . '/public',
                /*
                    Absoluter Pfad zur Schlüssel-Datei für Verschlüsselung
                */
                'key_file' => $corDir . '/.camindo.key',
                /*
                    Absoluter Pfad zum Haupt-Verzeichnis mit Schreibrechten,
                    sofern die nachfolgenden Verzeichnisse darin enthalten sind.
                    (Einziges weiteres Verzeichnis in das Schreibzugriffe erfolgen ist 'media')
                */
                'var_dir' => $corDir . '/var',
                /*
                    Absoluter Pfad zu Verzeichnis für temporäre Dateien
                */
                'tmp_dir' => $corDir . '/var/tmp',
                /*
                    Absoluter Pfad zum Upload-Verzeichnis
                    Seine Inhalte werden bei Klick auf das Datei-Icon neben jedem
                    Upload-Feld in den Record-Eingabemasken angezeigt
                    (Für sehr große Dateien gedacht, die per FTP übertragen werden)
                */
                'upload_dir' => $corDir . '/var/upload',
                /*
                    Absoluter Pfad zum .sqlpwd-File mit den mySQL-Zugangsdaten
                    für mysqldump (verhindert Passworteingabe als Shell-Parameter)
                */
                'sqlpwd_file' => $corDir . '/.sqlpwd',
                /*
                    PHP-Executable (absoluter Pfad)
                */
                'php' => 'php',
                /*
                    mysqldump-Executable (absoluter Pfad) - Datenbank-Sicherung
                    im Modul Scheduler
                */
                'mysqldump' => 'mysqldump',
                /*
                    ImageMagick-Executable (absoluter Pfad)
                    Version < 7 'convert' (identify wird mit selbem Pfad aufgerufen)
                    Version >= 7 'magick'
                */
                'magick' => 'magick',
                /*
                    ffmpeg-Executable (absoluter Pfad)
                */
                'ffmpeg' => 'ffmpeg',
                /*
                    ffprobe-Executable (absoluter Pfad)
                */
                'ffprobe' => 'ffprobe',
                /*
                    Composer-Executable (absoluter Pfad) - Update-Prüfung
                    (Camindo\Core\UpdateCheck: composer outdated / composer audit).
                    '' = keine Prüfung
                */
                'composer' => 'composer',
            ],
            'permissions' => [
                /*
                    Unix-Rechte für vom CMS erzeugte Verzeichnisse und Dateien
                */
                'dir'  => 0775,
                'file' => 0664,
                /*
                    Rechte von .camindo.key und .camindo.key.retired bei `key rotate`.
                    Gilt nur, wenn es noch keine .camindo.key gibt (Schlüssel aus
                    CAMINDO_KEY) - sonst übernimmt die Rotation die Rechte der
                    bisherigen Datei. 0600 ist optimal; wo der Webserver die Datei
                    nur über die Gruppe lesen kann, z. B. 0640
                */
                'key_file' => 0600,
            ],
            'db' => [
                /*
                    MySQL-Zugangsdaten
                    Pflichtfelder:
                        'host' => '...',
                        'name' => '...',
                        'user' => '...',
                        'pass' => '...',
                */
                'port' => 3306,
                // SQL-Modus jeder Verbindung (SET SESSION, vor init_querys). Das
                // Schema verlässt sich auf den nicht-strikten Modus: NOT-NULL-Spalten
                // ohne DEFAULT, die ein INSERT weglässt, und Null-Datumswerte.
                // Mit dem Standard von MySQL 8 (STRICT_TRANS_TABLES …) brechen
                // solche INSERTs ab. null = Modus des Servers unverändert lassen
                'sql_mode' => 'NO_ENGINE_SUBSTITUTION',
                'init_querys'   => [/*
                    ACHTUNG bei Listen: Elemente werden per Schlüssel ersetzt,
                    nicht angehängt. Eindeutige Schlüssel verwenden!
                */],
            ],
        ];

        // Mit Werten aus .camindo.php mergen
        // array_replace_recursive: .camindo.php gewinnt, fehlende Schlüssel bleiben auf Default.
        $this->settings = array_replace_recursive($defaults, $cfg);

        // Geheimnisse aus der Umgebung gehen vor
        foreach (self::ENV_SECRETS as $key => $name) {
            $value = self::env($name);
            if ($value === null) continue;
            [$group, $rest] = explode('.', $key, 2);
            $target = &$this->settings[$group];
            foreach (explode('.', $rest) as $k) $target = &$target[$k];
            $target = $value;
            unset($target);
            $this->fromEnv[] = $key;
        }

        // Referenz auf paths-Array
        $paths = &$this->settings['paths'];

        // Erweitere Pfade
        // Abgeleitete Werte, die nicht konfiguriert werden können - sie ergeben sich
        // zwingend aus dem Ort dieser Datei bzw. aus den Werten oben.
        $paths['core_dir'] = dirname(__DIR__);            // .../camindo/Core
        $paths['camindo_dir'] = dirname($paths['core_dir']); // .../camindo
        $paths['media_dir'] = $paths['document_root'] . $paths['media'];
        $paths['backend_dir'] = $paths['document_root'] . $paths['backend'];

        // PHP-Error-Log umleiten
        // Tagesweise Dateien, damit das Log nicht unbegrenzt wächst.
        ini_set('error_log', $paths['var_dir'] . '/log/' . date('Y-m-d') . '_php_errors.log');
    }

    /**
     * Konfigurationswert lesen, Ebenen durch Punkt getrennt.
     *
     *     $config->get('db');                // ganzes Teil-Array
     *     $config->get('paths.media_dir');   // einzelner Wert
     *     $config->get('gibts.nicht');       // null
     *
     * Ein nicht vorhandener Schlüssel ist kein Fehler, sondern liefert null - Aufrufer
     * setzen ihren Default üblicherweise per ?? dahinter.
     *
     * @param string $key  Schlüssel in Punktsyntax
     * @return mixed       Wert, Teil-Array oder null
     */
    // Getter optimiert auf Geschwindigkeit
    public function get(string $key)
    {
        // 1. Schneller Check: Wenn kein Punkt vorhanden ist, direkt zugreifen
        if (strpos($key, '.') === false) {
            return $this->settings[$key] ?? null;
        }

        // 2. Punkt vorhanden
        // Referenz nutzen, um das Kopieren von Arrays bei der Neuzuweisung zu verhindern
        $value = &$this->settings;

        foreach (explode('.', $key) as $k) {
            if (is_array($value) && isset($value[$k])) {
                $value = &$value[$k];
            } else {
                return null;
            }
        }

        return $value;
    }

    /**
     * Schlüssel aus ENV_SECRETS, deren Wert aus der Umgebung kommt
     * (z. B. ['db.pass']) - für Statusanzeigen, Backup und Installer.
     *
     * @return string[]
     */
    public function fromEnv(): array
    {
        return $this->fromEnv;
    }

    /**
     * Umgebungsvariable lesen - der einzige Weg für Geheimnisse (siehe Klassenkommentar).
     * Der Wert wird je Prozess gemerkt und aus Prozessumgebung, $_ENV und $_SERVER
     * entfernt; ein zweiter Aufruf liefert ihn aus dem Zwischenspeicher.
     *
     * @return string|null  Wert, null = nicht gesetzt oder leer
     */
    public static function env(string $name): ?string
    {
        if (array_key_exists($name, self::$env)) return self::$env[$name];
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;
        }
        $value = is_string($value) && $value !== '' ? $value : null;
        if ($value !== null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
        return self::$env[$name] = $value;
    }
}
