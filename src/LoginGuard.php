<?php
/**
 * class LoginGuard
 *
 * Brute-Force-Schutz der Backend-Anmeldung. Zählt fehlgeschlagene Versuche je
 * Anmeldename und sperrt den Namen, wenn es zu viele in zu kurzer Zeit werden.
 *
 * Gezählt werden falsche Passwörter UND falsche Codes der zweiten Stufe (E-Mail,
 * Authenticator-App, Wiederherstellungscode - Admin::loginByCode(), AdminBackend::
 * totp()) auf denselben Namen. Geleert wird der Zähler erst nach der vollständigen
 * Anmeldung, nicht schon nach dem richtigen Passwort: sonst ließe sich ein
 * sechsstelliger TOTP-Code mit Passwort + Code im Wechsel beliebig oft raten.
 *
 * WARUM AUCH UNBEKANNTE NAMEN GEZÄHLT WERDEN
 * --------------------
 * Gezählt wird der eingegebene Name, nicht das gefundene Konto - auch ein Name, den
 * es gar nicht gibt, wandert in die Tabelle. Sonst verhielte sich die Anmeldung bei
 * einem existierenden Konto nach drei Versuchen anders als bei einem erfundenen, und
 * genau daran liesse sich ablesen, welche Konten es gibt. Aus demselben Grund nennt
 * Admin::loginByName() nie den Grund des Scheiterns.
 *
 * DIE TABELLE BLEIBT VON SELBST SCHLANK
 * --------------------
 * Eine Zeile je Fehlversuch, kein Zähler-Feld. Vor jeder Auswertung fliegt alles
 * raus, was älter als admin.bruteforce_delay ist (purge()) - die Tabelle enthält
 * also immer nur das laufende Zeitfenster. Ein gleitendes Fenster ergibt sich damit
 * von selbst: ältere Versuche verfallen einzeln, die jüngeren zählen weiter.
 *
 * KONFIGURATION (.camindo.php, Defaults siehe Config)
 * --------------------
 *     admin.bruteforce_max     Erlaubte Fehlversuche im Zeitfenster (3).
 *                              0 oder kleiner schaltet den Schutz ab.
 *     admin.bruteforce_delay   Länge des Zeitfensters in Sekunden (600).
 *     admin.bruteforce_ip      IP-Adresse mitschreiben und mitprotokollieren (false).
 *                              Standardmässig aus, weil die IP ein personenbezogenes
 *                              Datum ist - gezählt wird ohnehin je Name.
 *     user.bruteforce_*        dasselbe für die Frontend-Anmeldung (forUsers())
 *
 * WAS PROTOKOLLIERT WIRD
 * --------------------
 * System::log('security', ...) bekommt eine Zeile, wenn eine Sperre EINSETZT - nicht
 * bei jedem abgewiesenen Versuch. Sonst könnte ein Angreifer das Logfile allein
 * dadurch volllaufen lassen, dass er weiter gegen die Sperre klopft.
 *
 * ZWEI ANMELDUNGEN, EINE KLASSE
 * --------------------
 * Die Frontend-Anmeldung der Community (CMS:USER_LOGIN, RecordUser) braucht
 * denselben Schutz, führt ihre Fehlversuche aber in einer eigenen Tabelle
 * `user_login_attempt` mit eigenen Grenzwerten (user.bruteforce_*). forUsers()
 * liefert dafür eine zweite Instanz mit umgestellter Tabelle und Konfiguration;
 * der Code ist derselbe.
 *
 * @see Camindo\Core\Modules\Admin\Admin::loginByName()  Aufruferin (Backend)
 * @see Camindo\Core\Modules\Record\RecordUser::cmsLogin() Aufruferin (Frontend)
 * @see Camindo\Core\Session                             Sitzungsverwaltung
 */
namespace Camindo\Core;

class LoginGuard {

    /** Tabelle mit den Fehlversuchen des laufenden Zeitfensters */
    private string $table = 'admin_login_attempt';
    /** Konfigurationsabschnitt der Grenzwerte: 'admin' oder 'user' */
    private string $configSection = 'admin';

    /** Spaltenbreite von `name` - längere Eingaben werden gekürzt, nicht abgewiesen */
    private const NAME_MAX = 64;

    public function __construct(
        private Config $config,
        private Database $db,
        private System $system,
        private ClientIp $clientIp
    )
    {
    }

    /**
     * Variante für die Frontend-Anmeldung: Tabelle `user_login_attempt`,
     * Grenzwerte aus user.bruteforce_* (siehe Klassenkommentar).
     */
    public function forUsers(): static
    {
        $guard = clone $this;
        $guard->table = 'user_login_attempt';
        $guard->configSection = 'user';
        return $guard;
    }

    /**
     * Ist dieser Name gerade gesperrt?
     *
     * Wird VOR der Prüfung von Name und Passwort aufgerufen - eine Sperre soll gelten,
     * ohne dass überhaupt noch einmal gegen die Zugangsdaten geprüft wird.
     *
     * @param string $name  Eingegebener Anmeldename, roh
     * @return int          Verbleibende Sperrzeit in Sekunden, 0 = nicht gesperrt
     */
    public function blockedFor(string $name): int
    {
        $max = $this->maxAttempts();
        if ($max < 1) return 0;   // Schutz abgeschaltet

        $name = $this->normalizeName($name);
        if ($name === '') return 0;

        $this->db->pushResult();
        try {
            $this->purge();
            $attempts = $this->countAttempts($name);

            // Gesperrt wird erst OBERHALB der erlaubten Zahl: bei genau $max
            // Fehlversuchen ist noch ein letzter Versuch frei (siehe Klassenkommentar
            // von Admin und der Hinweis in loginByName()).
            if ($attempts <= $max) return 0;

            // Die Sperre endet, sobald so viele Zeilen verfallen sind, dass wieder
            // höchstens $max übrig sind. Massgeblich ist also nicht die älteste Zeile,
            // sondern die ($attempts - $max)-älteste - deren Verfall bringt den Zähler
            // gerade auf $max herunter.
            $offset = $attempts - $max - 1;
            $this->db->query(
                "SELECT TIMESTAMPDIFF(SECOND, `attempt_date`, UTC_TIMESTAMP()) AS `age`"
                . " FROM `" . $this->table . "` WHERE `name` = ?"
                . " ORDER BY `attempt_date` ASC LIMIT 1 OFFSET ?",
                'si',
                [$name, $offset]
            );
            $row = $this->db->fetch();
            if ($row === null) return 0;

            $remaining = $this->delaySeconds() - (int)$row['age'];

            // Nie 0 zurückgeben, solange die Sperre steht: 0 heisst "frei".
            return $remaining > 0 ? $remaining : 1;
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Fehlversuch verbuchen.
     *
     * @param string $name  Eingegebener Anmeldename, roh
     * @return int          Fehlversuche im Zeitfenster INKLUSIVE diesem
     */
    public function recordFailure(string $name): int
    {
        $max = $this->maxAttempts();
        if ($max < 1) return 0;   // Schutz abgeschaltet

        $name = $this->normalizeName($name);
        if ($name === '') return 0;

        $ip = $this->clientIp();

        $this->db->pushResult();
        try {
            $this->db->query(
                "INSERT INTO `" . $this->table . "` (`name`, `ip`, `attempt_date`)"
                . " VALUES (?, ?, UTC_TIMESTAMP())",
                'ss',
                [$name, $ip]
            );
            $attempts = $this->countAttempts($name);
        }
        finally {
            $this->db->popResult();
        }

        // Nur der Übergang wird protokolliert - der Versuch, der die Sperre auslöst.
        // Jeder weitere prallt an blockedFor() ab und schreibt nichts mehr.
        if ($attempts == $max + 1) {
            $this->system->log('security', sprintf(
                'Login blocked for %s seconds. Name: "%s", %d failed attempts within %d seconds.%s',
                $this->delaySeconds(),
                $name,
                $attempts,
                $this->delaySeconds(),
                $ip === '' ? '' : ' IP: ' . $ip
            ));
        }

        return $attempts;
    }

    /**
     * Zähler eines Namens leeren - nach erfolgreicher Anmeldung (mit zweitem Faktor:
     * erst nach dem richtigen Code).
     *
     * Ohne das bliebe jemand, der sich zweimal vertippt hat, bis zum Ablauf des
     * Zeitfensters dicht an der Sperre.
     */
    public function clear(string $name): void
    {
        $name = $this->normalizeName($name);
        if ($name === '') return;

        $this->db->pushResult();
        try {
            $this->db->query("DELETE FROM `" . $this->table . "` WHERE `name` = ?", 's', [$name]);
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Fehlversuche eines Namens im laufenden Zeitfenster.
     *
     * Verlässt sich darauf, dass purge() die abgelaufenen Zeilen bereits entfernt hat -
     * deshalb ohne eigene Zeitbedingung. Ruft der Aufrufer nur recordFailure() auf,
     * ist der Zähler höchstens für die Dauer eines Requests zu gross, denn blockedFor()
     * läuft bei jedem Anmeldeversuch als Erstes.
     */
    private function countAttempts(string $name): int
    {
        $this->db->query(
            "SELECT COUNT(*) FROM `" . $this->table . "` WHERE `name` = ?",
            's',
            [$name]
        );
        $row = $this->db->fetchNum();
        return (int)($row[0] ?? 0);
    }

    /**
     * Abgelaufene Fehlversuche löschen - für ALLE Namen, nicht nur den geprüften.
     *
     * Das ist die einzige Aufräumstelle: die Tabelle hat keinen Cronjob und wächst
     * sonst mit jedem Tippfehler weiter. Da bei jedem Anmeldeversuch einmal gelöscht
     * wird, bleibt sie so gross wie das Zeitfenster - nicht grösser.
     */
    private function purge(): void
    {
        $this->db->query(
            "DELETE FROM `" . $this->table . "`"
            . " WHERE `attempt_date` < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND)",
            'i',
            [$this->delaySeconds()]
        );
    }

    /**
     * Name in die Form bringen, in der er in der Tabelle steht.
     *
     * Gekürzt wird auf die Spaltenbreite: ein überlanger Name soll nicht in einen
     * SQL-Fehler laufen, sondern schlicht als Fehlversuch zählen. Kleinschreibung,
     * weil die Spalte `admin`.`name` ohne Groß-/Kleinschreibung verglichen wird - sonst
     * wären "Dirk" und "dirk" für die Anmeldung derselbe Benutzer, für die Sperre aber
     * zwei verschiedene Namen.
     */
    private function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '') return '';
        return mb_strtolower(mb_substr($name, 0, self::NAME_MAX));
    }

    /**
     * IP des Aufrufers - leer, wenn admin.bruteforce_ip nicht gesetzt ist.
     *
     * X-Forwarded-For zählt nur von Proxies aus security.trusted_proxies (ClientIp) -
     * der Header ist frei wählbar, und wer ihn setzt, könnte sich sonst für jeden
     * Versuch eine neue Herkunft ausdenken.
     */
    private function clientIp(): string
    {
        if (empty($this->config->get($this->configSection . '.bruteforce_ip'))) return '';
        return mb_substr($this->clientIp->ip(), 0, 45);
    }

    /** Erlaubte Fehlversuche im Zeitfenster; kleiner als 1 schaltet den Schutz ab. */
    private function maxAttempts(): int
    {
        return (int)$this->config->get($this->configSection . '.bruteforce_max');
    }

    /** Länge des Zeitfensters in Sekunden, mindestens 1. */
    private function delaySeconds(): int
    {
        $delay = (int)$this->config->get($this->configSection . '.bruteforce_delay');
        return $delay > 0 ? $delay : 1;
    }
}
