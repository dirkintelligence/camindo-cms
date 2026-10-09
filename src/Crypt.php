<?php
/**
 * Verschlüsselung und Hashing auf Basis eines einzigen geheimen Schlüssels.
 *
 * Der Schlüssel steht als Rohinhalt in der Datei paths.key_file (.camindo.key) und wird
 * im Konstruktor eingelesen. Ohne Datei gilt die Umgebungsvariable CAMINDO_KEY
 * (Config::ENV_KEY) - die Datei hat Vorrang, damit nach `key rotate` der neue
 * Schlüssel gilt, bis die Variable von Hand nachgezogen ist. Fehlt beides, wird
 * sofort geworfen - bewusst kein stiller Fallback, sonst entstünden
 * unentschlüsselbare Daten. Ebenso, wenn die Variable allein einen abgelösten
 * Schlüssel enthält (Datei nach der Rotation entfernt, Variable nicht nachgezogen).
 *
 * ACHTUNG: Geht .camindo.key verloren, sind alle Inhalte verschlüsselter Felder
 * (Typ `encrypted`, Tabelle data_enc) unwiederbringlich verloren. Die Datei gehört ins
 * Backup, aber nicht ins Repository.
 *
 * WOFÜR WAS
 * --------------------
 *     encrypt()/decrypt()   Feldtyp `encrypted` und die varbinary-Spalten der Tabelle
 *                           `admin` (email, mobile, phone). Umkehrbar.
 *     hash()                Suchwert in data_enc.hash und die Signaturen der Klasse
 *                           Integrity. Nicht umkehrbar, deterministisch (siehe unten).
 *     passwordHash()        Passwörter (admin.password, data_pwd.data). Nicht umkehrbar,
 *     passwordVerify()      NICHT deterministisch, absichtlich langsam (Argon2id/bcrypt).
 *     passwordNeedsRehash() true, wenn ein gespeicherter Hash nachgezogen werden soll.
 *     randomKey()           Session-Ids, Tokens, Datei-Tokens.
 *
 * WARUM data_enc EINE EXTRA HASH-SPALTE HAT
 * --------------------
 * encrypt() ist absichtlich NICHT deterministisch: jeder Aufruf zieht einen neuen
 * zufälligen IV, derselbe Klartext ergibt also jedes Mal einen anderen Geheimtext.
 * Das ist kryptografisch richtig, macht aber ein WHERE auf die Datenspalte unmöglich.
 * Deshalb speichert data_enc zusätzlich hash() des normalisierten Klartexts
 * (Cms::encryptedHash(): indexText(), klein, ohne Leerzeichen) - darüber läuft die
 * Gleichheitssuche in Cms::buildEncryptedCondition() und die unique-Prüfung in
 * Dataset::uniqueVirtualOk(). Groß-/Kleinschreibung, Leerraum und Satzzeichen zählen
 * also nicht; Wildcards, LIKE und Bereichsvergleiche sind ausgeschlossen. Daten von
 * vor der Normalisierung: `camindo.php key rehash` (KeyRotation::rehashEncrypted()).
 *
 * WARUM PASSWÖRTER NICHT ÜBER hash() LAUFEN
 * --------------------
 * hash() ist deterministisch und schnell - das braucht die Suche. Für Passwörter wäre
 * beides ein Nachteil: gleiche Passwörter ergäben gleiche Hashes, und wer Datenbank
 * und Schlüssel hat, könnte Milliarden Kandidaten je Sekunde durchprobieren.
 * passwordHash() nimmt deshalb password_hash() - Argon2id, wo PHP es mitbringt, sonst
 * bcrypt (security.password_hash, 16-sicherheit.md §1). Vorgeschaltet ist ein HMAC
 * mit dem Schlüssel aus .camindo.key (Pepper): ein Datenbankauszug allein ist nichts
 * wert, und bcrypt bekommt immer 44 Zeichen statt der Roheingabe (keine Kürzung
 * nach 72 Bytes, keine Null-Bytes).
 *
 * FORMAT DES GEHEIMTEXTS
 * --------------------
 *     [ IV (12 Bytes bei GCM) ][ Auth-Tag (16 Bytes) ][ Geheimtext ]
 * wahlweise roh (binary = true, für varbinary-Spalten) oder base64-kodiert.
 *
 * FORMAT DES PASSWORT-HASHS
 * --------------------
 *     password_hash(base64(HMAC-SHA256(Passwort, key)))
 *     $argon2id$v=19$m=19456,t=2,p=1$…   97 Zeichen, bzw. $2y$11$…  60 Zeichen
 * Spalten admin.password und data_pwd.data: varchar(255). Das frühere Format (bis
 * 2026-09-24: [ Salt 16 Bytes ][ HMAC-SHA256(Salt . Passwort) ], kompakt kodiert,
 * 64 Zeichen) prüft passwordVerify() weiterhin; passwordNeedsRehash() meldet es, und
 * die Anmeldung schreibt den neuen Hash (Admin::loginByName(), RecordUser::cmsLogin()).
 *
 * ZUM SALT-PARAMETER
 * --------------------
 * $salt ist kein Salt im üblichen Sinn, sondern AAD (Additional Authenticated Data):
 * Der Wert wird nicht mitverschlüsselt, geht aber in die Authentifizierung ein.
 * decrypt() liefert nur dann etwas zurück, wenn exakt derselbe Wert übergeben wird -
 * damit lässt sich ein Geheimtext an einen Kontext binden (z. B. eine Datensatz-Id),
 * sodass er nicht in einen anderen Datensatz kopiert werden kann.
 *
 * SCHLÜSSELBUND UND ROTATION (Camindo\Core\KeyRotation, `camindo.php key …`)
 * --------------------
 * Neben dem aktuellen Schlüssel (.camindo.key) kann es abgelöste geben:
 * {key_file}.retired, je Zeile "{kid} {Schlüssel} {abgelöst am, ISO 8601}". Die
 * Schlüssel-Id (kid) sind die ersten 8 Hex-Zeichen von sha256(Schlüssel).
 *
 *     encrypt(), hash(), passwordHash()   immer mit dem aktuellen Schlüssel
 *     decrypt()                           aktueller Schlüssel, bei Misserfolg die
 *                                         abgelösten (GCM erkennt den falschen)
 *     hashAll()                           HMAC unter allen Schlüsseln - für gespeicherte
 *                                         HMACs, deren Klartext unbekannt ist
 *                                         (Wiederherstellungscodes, Gerätekennungen)
 *     passwordVerify()                    mit dem Schlüssel aus der Kennung des Hashs
 *
 * Passwort-Hashes tragen die Kennung ihres Pfeffer-Schlüssels: "{kid}:{hash}".
 * Hashes ohne Kennung (vor der ersten Rotation) gelten als mit dem aktuellen
 * Schlüssel gepfeffert; passwordNeedsRehash() versieht sie bei der nächsten Anmeldung
 * mit der Kennung, ebenso Hashes eines abgelösten Schlüssels mit dem aktuellen.
 *
 * FEHLERVERHALTEN
 * --------------------
 * encrypt() wirft bei Fehlschlag. decrypt() wirft NICHT, sondern liefert '' -
 * bei falschem Schlüssel, falschem Salt, manipuliertem Geheimtext und ungültigem
 * Base64 gleichermaßen. Ein leeres Ergebnis ist also nicht von einem leer
 * gespeicherten Wert unterscheidbar.
 */
namespace Camindo\Core;

class Crypt {
    /** Roher Inhalt von paths.key_file - dient sowohl als AES- als auch als HMAC-Schlüssel */
    private string $key;

    /** Id des aktuellen Schlüssels (keyId()) */
    private string $keyId = '';

    /** Abgelöste Schlüssel: kid => ['key' => …, 'retired_at' => ISO 8601] */
    private array $retired = [];

    /** Herkunft des aktuellen Schlüssels: 'file' oder 'env' */
    private string $keySource = 'file';

    // Konstante für das Verschlüsselungsverfahren
    // GCM = authenticated encryption: liefert neben dem Geheimtext ein Auth-Tag,
    // mit dem decrypt() Manipulationen erkennt.
    private const CIPHER = 'aes-256-gcm';

    /** Länge des Salts im früheren Passwort-Format (passwordVerify()) in Bytes */
    private const PASSWORD_SALT_LENGTH = 16;

    public function __construct(
        private Config $config
    )
    {
        // Pfüfe nötige Angaben
        $keyFile = $this->config->get('paths.key_file');
        if (empty($keyFile)) {
            throw new \RuntimeException('CONFIGURATION ERROR: paths.key_file is empty!');
        }
        if ((!file_exists($keyFile) || !is_readable($keyFile)) && Config::env(Config::ENV_KEY) === null) {
            throw new \RuntimeException('CONFIGURATION ERROR: paths.key_file - file not found or not readable, and no ' . Config::ENV_KEY . ' set!');
        }

        $this->reloadKeys();
    }

    /**
     * Schlüssel (neu) einlesen: aktueller aus paths.key_file, abgelöste aus
     * {key_file}.retired - nach einer Rotation (KeyRotation).
     */
    public function reloadKeys(): void
    {
        $keyFile = (string)$this->config->get('paths.key_file');
        if (is_file($keyFile) && is_readable($keyFile)) {
            $this->key = (string)file_get_contents($keyFile);
            $this->keySource = 'file';
        }
        else {
            $this->key = (string)Config::env(Config::ENV_KEY);
            $this->keySource = 'env';
        }
        if ($this->key === '') throw new \RuntimeException('CONFIGURATION ERROR: key is empty!');
        $this->keyId = self::keyId($this->key);
        $this->retired = [];
        $retiredFile = $keyFile . '.retired';
        if (is_file($retiredFile) && is_readable($retiredFile)) {
            foreach (file($retiredFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) < 2 || !preg_match('/^[0-9a-f]{8}$/', $parts[0])) continue;
                if ($parts[0] === $this->keyId) continue;
                $this->retired[$parts[0]] = ['key' => $parts[1], 'retired_at' => (string)($parts[2] ?? '')];
            }
        }
        // Variable nach einer Rotation nicht nachgezogen und die Datei schon entfernt:
        // mit dem alten Schlüssel weiterzuarbeiten hieße, Neues unter ihm zu
        // verschlüsseln und alle Signaturen für manipuliert zu halten
        if ($this->keySource === 'env' && $this->isRetiredKey($this->key)) {
            throw new \RuntimeException('CONFIGURATION ERROR: ' . Config::ENV_KEY . ' holds the retired key '
                . $this->keyId . ' - set it to the current key (or restore ' . basename($keyFile) . ')');
        }
    }

    /** Steht der Schlüssel in {key_file}.retired? (Ids dort, deren Zeile übersprungen wurde, eingeschlossen) */
    private function isRetiredKey(string $key): bool
    {
        $retiredFile = (string)$this->config->get('paths.key_file') . '.retired';
        if (!is_file($retiredFile) || !is_readable($retiredFile)) return false;
        $kid = self::keyId($key);
        foreach (file($retiredFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (str_starts_with(trim($line), $kid . ' ')) return true;
        }
        return false;
    }

    /** Herkunft des aktuellen Schlüssels: 'file' (paths.key_file) oder 'env' (CAMINDO_KEY) */
    public function keySource(): string
    {
        return $this->keySource;
    }

    /**
     * Abgleich der Variablen CAMINDO_KEY mit dem aktuellen Schlüssel:
     *     ''         Variable nicht gesetzt
     *     'current'  enthält den aktuellen Schlüssel
     *     'retired'  enthält einen abgelösten - nach `key rotate` nachziehen
     *     'other'    enthält einen unbekannten Schlüssel
     */
    public function envKeyState(): string
    {
        $env = Config::env(Config::ENV_KEY);
        if ($env === null) return '';
        $kid = self::keyId($env);
        if ($kid === $this->keyId) return 'current';
        return isset($this->retired[$kid]) || $this->isRetiredKey($env) ? 'retired' : 'other';
    }

    /**
     * Der aktuelle Schlüssel selbst - nur für KeyRotation (alter Schlüssel in den
     * Schlüsselbund), auch wenn er aus der Umgebung kommt.
     *
     * @internal
     */
    public function currentKeyForRotation(): string
    {
        return $this->key;
    }

    /** Id eines Schlüssels: die ersten 8 Hex-Zeichen von sha256 */
    public static function keyId(string $key): string
    {
        return substr(hash('sha256', $key), 0, 8);
    }

    /** Id des aktuellen Schlüssels */
    public function currentKeyId(): string
    {
        return $this->keyId;
    }

    /** Abgelöste Schlüssel: kid => retired_at (ohne die Schlüssel selbst) */
    public function retiredKeys(): array
    {
        return array_map(fn($k) => $k['retired_at'], $this->retired);
    }

    /** Schlüssel zu einer Id - aktueller oder abgelöster, sonst null */
    private function keyById(string $kid): ?string
    {
        if ($kid === $this->keyId) return $this->key;
        return $this->retired[$kid]['key'] ?? null;
    }

    /**
     * Mit genau dem aktuellen Schlüssel entschlüsselbar? (KeyRotation::status())
     */
    public function decryptsWithCurrent(string $text, string $salt = '', bool $binary = false): bool
    {
        if ($text === '') return true;
        return $this->decryptWith($this->key, $text, $salt, $binary) !== null;
    }

    /**
     * Wert verschlüsseln (AES-256-GCM)
     *
     * @param string $text  zu verschlüsselnder Text
     * @param string $salt  (Wird als Additional Authenticated Data - AAD - genutzt)
     * @param bool $binary  true: liefere rohe Bytes, false: leifert base64-encodierten String (default)
     * @return string       
     */
    public function encrypt(string $text, string $salt = '', bool $binary = false): string
    {
        // Leerstring bleibt
        if ($text === '') return '';
        
        // Zufälligen Initialisierungsvektor IV generieren
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = random_bytes($ivLength);
        $tag = '';

        // Text verschlüsseln
        // OPENSSL_RAW_DATA verhindert zusätzliche Formatierung durch OpenSSL
        $encrypted = openssl_encrypt($text, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag, $salt);

        if ($encrypted === false) {
            throw new \RuntimeException('Verschlüsselung fehlgeschlagen.');
        }

        // IV-Tag (für die Authentifizierung) und den verschlüsselten Text zusammenfügen
        if ($binary) return $iv . $tag . $encrypted;
        else return base64_encode($iv . $tag . $encrypted);
    }

   /**
     * Wert entschlüsseln
     *
     * @param string $text  verschlüsselter Text
     * @param string $salt  (Muss exakt dem Salt bei der Verschlüsselung entsprechen)
     * @param bool $binary  true: verarbeitet rohe Bytes, false: verarbeitet base64-encodierten String (default)
     * @return string   
     */
    public function decrypt(string $text, string $salt = '', bool $binary = false): string
    {
        // Leerstring bleibt
        if ($text === '') return '';

        if (!$binary) {
            $decoded = base64_decode($text, true);
            if ($decoded === false) {
                // Ungültiges Base64
                return '';
            }
        }
        else {
            $decoded = $text;
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $tagLength = 16; // Standard-Tag-Länge bei GCM

        // Prüfen, ob die Mindestlänge für IV und Tag vorhanden ist
        if (strlen($decoded) < ($ivLength + $tagLength)) {
            return ''; 
        }

        // Aktueller Schlüssel, dann die abgelösten (Rotation, siehe Klassenkommentar)
        $decrypted = $this->decryptWith($this->key, $decoded, $salt, true);
        if ($decrypted !== null) return $decrypted;
        foreach ($this->retired as $retired) {
            $decrypted = $this->decryptWith($retired['key'], $decoded, $salt, true);
            if ($decrypted !== null) return $decrypted;
        }
        return '';
    }

    /**
     * Mit einem bestimmten Schlüssel entschlüsseln - null, wenn er nicht passt.
     */
    private function decryptWith(string $key, string $text, string $salt, bool $binary): ?string
    {
        $decoded = $binary ? $text : base64_decode($text, true);
        if ($decoded === false) return null;
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $tagLength = 16;
        if (strlen($decoded) < ($ivLength + $tagLength)) return null;

        // IV, Tag und den eigentlichen Geheimtext extrahieren
        $iv = substr($decoded, 0, $ivLength);
        $tag = substr($decoded, $ivLength, $tagLength);
        $ciphertext = substr($decoded, $ivLength + $tagLength);

        // Text entschlüsseln und Authentizität prüfen
        $decrypted = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, $salt);
        return $decrypted !== false ? $decrypted : null;
    }

    /**
     * Erzeuge kryptografisch sicheren, kompakten HMAC-Hash
     *
     * @param string $text  Text, der gehasht wird
     * @param bool $binary  true:  Binäres Format (32 Bytes)
     *                      false: Lesbares Format (43 latin-1 Zeichen)
     * @return string
     */
    public function hash(string $text, bool $binary = false): string
    {
        if ($text === '') return '';
        
        // true = liefert 32 rohe Bytes anstatt 64 Hex-Zeichen
        $rawHash = hash_hmac('sha256', $text, $this->key, true);
        
        if ($binary) {
            // 32 rohe Bytes
            return $rawHash;
        }
        else {
            // 43 lesbare Zeichen
            return $this->encodeCompact($rawHash);
        }
    }

    /**
     * HMAC unter allen Schlüsseln, der aktuelle zuerst - zum Wiederfinden gespeicherter
     * HMACs, deren Klartext erst beim Einlösen bekannt ist (Wiederherstellungscodes,
     * Geräte-Tokens), auch nach einer Rotation.
     *
     * @return string[]  wie hash()
     */
    public function hashAll(string $text, bool $binary = false): array
    {
        if ($text === '') return [];
        $hashes = [$this->hash($text, $binary)];
        foreach ($this->retired as $retired) {
            $raw = hash_hmac('sha256', $text, $retired['key'], true);
            $hashes[] = $binary ? $raw : $this->encodeCompact($raw);
        }
        return $hashes;
    }

    /**
     * HMAC mit einem bestimmten Schlüssel (aktuell oder abgelöst) - für Daten, die
     * die Id ihres Schlüssels mitführen (Kette des Audit-Logs, AuditStore).
     *
     * @return string|null  wie hash() (lesbar), null bei unbekannter Schlüssel-Id
     */
    public function hashWithKey(string $kid, string $text): ?string
    {
        $key = $this->keyById($kid);
        if ($key === null) return null;
        return $this->encodeCompact(hash_hmac('sha256', $text, $key, true));
    }

    /**
     * Passwort hashen - password_hash() über den gepfefferten Wert (Format siehe
     * Klassenkommentar). Zwei Aufrufe mit demselben Passwort liefern verschiedene
     * Werte; vergleichen kann nur passwordVerify().
     *
     * @param string $password  Klartext
     * @return string           60 (bcrypt) bzw. 97 Zeichen (Argon2id); '' bei leerem Passwort
     */
    public function passwordHash(string $password): string
    {
        if ($password === '') return '';
        [$algo, $options] = $this->passwordAlgo();
        return $this->keyId . ':' . password_hash($this->passwordPepper($password, $this->key), $algo, $options);
    }

    /**
     * Kennung und Hash trennen: "{kid}:{hash}" -> [kid, hash]; ohne Kennung ['', hash].
     */
    public static function splitPasswordHash(string $stored): array
    {
        if (preg_match('/^([0-9a-f]{8}):(.*)$/s', $stored, $m)) return [$m[1], $m[2]];
        return ['', $stored];
    }

    /**
     * Passwort gegen einen gespeicherten Hash prüfen - aktuelles Format oder das
     * frühere (Salt + HMAC, 64 Zeichen).
     *
     * Läuft auch bei leerem oder unbrauchbarem $stored durch dieselbe Rechnung (gegen
     * einen Ersatz-Hash mit den aktuellen Parametern), damit die Antwortzeit nichts
     * darüber verrät, ob es zum Namen ein Konto gibt (Admin::loginByName(),
     * RecordUser::cmsLogin()).
     *
     * @param string $password  Klartext
     * @param string $stored    Wert aus admin.password bzw. data_pwd.data
     */
    public function passwordVerify(string $password, string $stored): bool
    {
        // Schlüssel aus der Kennung; ohne Kennung der aktuelle (siehe Klassenkommentar).
        // Eine unbekannte Kennung (Schlüssel entfernt) rechnet gegen den Ersatz-Hash.
        [$kid, $stored] = self::splitPasswordHash($stored);
        $key = $kid === '' ? $this->key : $this->keyById($kid);
        if ($key !== null && str_starts_with($stored, '$')) {
            $ok = password_verify($this->passwordPepper($password, $key), $stored);
            return $password !== '' && $ok;
        }
        $raw = $key !== null ? $this->decodeCompact($stored) : '';
        if ($password !== '' && strlen($raw) === self::PASSWORD_SALT_LENGTH + 32) {
            // früheres Format
            $salt = substr($raw, 0, self::PASSWORD_SALT_LENGTH);
            return hash_equals(substr($raw, self::PASSWORD_SALT_LENGTH), hash_hmac('sha256', $salt . $password, $key, true));
        }
        // Kein brauchbarer Hash: trotzdem einmal rechnen
        password_verify($this->passwordPepper($password, $this->key), $this->dummyHash());
        return false;
    }

    /**
     * Soll ein gespeicherter Hash neu berechnet werden? Früheres Format, anderes
     * Verfahren oder andere Parameter als in security.password_hash. Der Aufrufer
     * hat das Passwort gerade geprüft und schreibt passwordHash() zurück.
     */
    public function passwordNeedsRehash(string $stored): bool
    {
        if ($stored === '') return false;
        [$kid, $hash] = self::splitPasswordHash($stored);
        // ohne Kennung oder mit dem Pfeffer eines abgelösten Schlüssels
        if ($kid !== $this->keyId) return true;
        return $this->hashOutdated($hash);
    }

    /** Früheres Format, anderes Verfahren oder andere Parameter (ohne Kennung) */
    private function hashOutdated(string $hash): bool
    {
        if (!str_starts_with($hash, '$')) return true;
        [$algo, $options] = $this->passwordAlgo();
        return password_needs_rehash($hash, $algo, $options);
    }

    /**
     * Verfahren und Parameter aus security.password_hash: algo 'auto' (Argon2id, wenn
     * PHP es kann, sonst bcrypt), 'argon2id' oder 'bcrypt'.
     *
     * @return array{0: string, 1: array}
     */
    private function passwordAlgo(): array
    {
        $cfg = (array)$this->config->get('security.password_hash');
        $algo = strtolower((string)($cfg['algo'] ?? 'auto'));
        $argon = defined('PASSWORD_ARGON2ID') && $algo !== 'bcrypt';
        if ($argon) {
            return [PASSWORD_ARGON2ID, [
                'memory_cost' => max(7168, (int)($cfg['memory_cost'] ?? 19456)),
                'time_cost'   => max(1, (int)($cfg['time_cost'] ?? 2)),
                'threads'     => 1,
            ]];
        }
        return [PASSWORD_BCRYPT, ['cost' => min(15, max(10, (int)($cfg['cost'] ?? 11)))]];
    }

    /**
     * Pepper: HMAC mit dem Schlüssel, base64 - 44 Zeichen, unabhängig von Länge und
     * Inhalt der Eingabe.
     */
    private function passwordPepper(string $password, string $key): string
    {
        return base64_encode(hash_hmac('sha256', $password, $key, true));
    }

    /**
     * Ersatz-Hash für passwordVerify() ohne gespeicherten Wert - mit den aktuellen
     * Parametern, damit die Rechenzeit einer echten Prüfung entspricht. Einmal
     * erzeugt und in var/cache abgelegt: ihn je Request neu zu berechnen, kostete
     * gerade im Fall "Name unbekannt" die doppelte Zeit.
     */
    private function dummyHash(): string
    {
        static $hash = null;
        if ($hash !== null && !$this->hashOutdated($hash)) return $hash;
        $file = (string)$this->config->get('paths.var_dir') . '/cache/password_dummy.txt';
        $hash = is_file($file) ? trim((string)file_get_contents($file)) : '';
        if ($this->hashOutdated($hash)) {
            [$algo, $options] = $this->passwordAlgo();
            $hash = password_hash(bin2hex(random_bytes(16)), $algo, $options);
            if (is_dir(dirname($file))) @file_put_contents($file, $hash, LOCK_EX);
        }
        return $hash;
    }

    /**
     * Erzeuge einen kryptografisch sicheren, zufälligen Schlüssel
     *
     * @param int $length Länge der rohen Entropie in Bytes (Standard: 32 Bytes = 256 Bit)
     * @return string Kompakter String (43 Zeichen bei 32 Bytes)
     */
    public function randomKey(int $length = 32): string 
    {
        $rawBytes = random_bytes($length);
        
        return $this->encodeCompact($rawBytes);
    }

    /**
     * Wandelt rohe Bytes in einen kompakten, URL-sicheren Base64-String um
     * Nutzt a-z, A-Z, 0-9, - und _
     */
    private function encodeCompact(string $rawData): string 
    {
        // Standard-Base64 erzeugt + und / (kann in URLs oder Systemen stören)
        // Wir tauschen diese gegen - und _ aus und entfernen das = Füllzeichen
        return rtrim(strtr(base64_encode($rawData), '+/', '-_'), '=');
    }

    /**
     * Umkehrung von encodeCompact(); '' bei ungültiger Eingabe
     */
    private function decodeCompact(string $data): string
    {
        if ($data === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $data)) return '';
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }
}