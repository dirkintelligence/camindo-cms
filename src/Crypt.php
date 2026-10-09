<?php
/**
 * Encryption and hashing based on a single secret key.
 *
 * The key is stored as raw content in the file paths.key_file (.camindo.key) and is
 * read in the constructor. Without the file the environment variable CAMINDO_KEY
 * applies (Config::ENV_KEY) - the file takes precedence, so that after `key rotate` the new
 * key applies until the variable is updated by hand. If both are missing, it
 * throws immediately - deliberately no silent fallback, otherwise undecryptable
 * data would result. Likewise if the variable alone contains a retired
 * key (file removed after the rotation, variable not updated).
 *
 * WARNING: if .camindo.key is lost, all contents of encrypted fields
 * (type `encrypted`, table data_enc) are irrecoverably lost. The file belongs in the
 * backup, but not in the repository.
 *
 * WHAT IT IS USED FOR
 * --------------------
 *     encrypt()/decrypt()   field type `encrypted` and the varbinary columns of the table
 *                           `admin` (email, mobile, phone). Reversible.
 *     hash()                search value in data_enc.hash and the signatures of the class
 *                           Integrity. Not reversible, deterministic (see below).
 *     passwordHash()        passwords (admin.password, data_pwd.data). Not reversible,
 *     passwordVerify()      NOT deterministic, deliberately slow (Argon2id/bcrypt).
 *     passwordNeedsRehash() true if a stored hash should be recalculated.
 *     randomKey()           session ids, tokens, file tokens.
 *
 * WHY data_enc HAS AN EXTRA HASH COLUMN
 * --------------------
 * encrypt() is deliberately NOT deterministic: every call draws a new random
 * IV, so the same plain text yields a different ciphertext each time.
 * That is cryptographically correct, but makes a WHERE on the data column impossible.
 * Therefore data_enc additionally stores hash() of the normalized plain text
 * (Cms::encryptedHash(): indexText(), lower case, without spaces) - the equality
 * search in Cms::buildEncryptedCondition() and the unique check in
 * Dataset::uniqueVirtualOk() run over it. Case, whitespace and punctuation therefore
 * do not count; wildcards, LIKE and range comparisons are excluded. Data from
 * before the normalization: `camindo.php key rehash` (KeyRotation::rehashEncrypted()).
 *
 * WHY PASSWORDS DO NOT GO THROUGH hash()
 * --------------------
 * hash() is deterministic and fast - that is what the search needs. For passwords both
 * would be a disadvantage: identical passwords would yield identical hashes, and whoever
 * has the database and the key could try billions of candidates per second.
 * passwordHash() therefore uses password_hash() - Argon2id where PHP provides it, otherwise
 * bcrypt (security.password_hash, developer docs, chapter 13 (Security: authentication)).
 * An HMAC with the key from .camindo.key (pepper) is applied first: a database extract
 * alone is worth nothing, and bcrypt always gets 44 characters instead of the raw input
 * (no truncation after 72 bytes, no null bytes).
 *
 * FORMAT OF THE CIPHERTEXT
 * --------------------
 *     [ IV (12 bytes with GCM) ][ auth tag (16 bytes) ][ ciphertext ]
 * either raw (binary = true, for varbinary columns) or base64-encoded.
 *
 * FORMAT OF THE PASSWORD HASH
 * --------------------
 *     password_hash(base64(HMAC-SHA256(password, key)))
 *     $argon2id$v=19$m=19456,t=2,p=1$…   97 characters, or $2y$11$…  60 characters
 * Columns admin.password and data_pwd.data: varchar(255). The earlier format (until
 * 2026-09-24: [ salt 16 bytes ][ HMAC-SHA256(salt . password) ], compactly encoded,
 * 64 characters) is still checked by passwordVerify(); passwordNeedsRehash() reports it, and
 * the sign-in writes the new hash (Admin::loginByName(), RecordUser::cmsLogin()).
 *
 * ABOUT THE SALT PARAMETER
 * --------------------
 * $salt is not a salt in the usual sense, but AAD (additional authenticated data):
 * the value is not encrypted along, but it enters the authentication.
 * decrypt() only returns something if exactly the same value is passed -
 * this binds a ciphertext to a context (e.g. a record id), so that it cannot be
 * copied into another record.
 *
 * KEY RING AND ROTATION (Camindo\Core\KeyRotation, `camindo.php key …`)
 * --------------------
 * Besides the current key (.camindo.key) there can be retired ones:
 * {key_file}.retired, one per line "{kid} {key} {retired at, ISO 8601}". The
 * key id (kid) is the first 8 hex characters of sha256(key).
 *
 *     encrypt(), hash(), passwordHash()   always with the current key
 *     decrypt()                           current key; on failure the
 *                                         retired ones (GCM detects the wrong one)
 *     hashAll()                           HMAC under all keys - for stored
 *                                         HMACs whose plain text is unknown
 *                                         (recovery codes, device identifiers)
 *     passwordVerify()                    with the key from the id of the hash
 *
 * Password hashes carry the id of their pepper key: "{kid}:{hash}".
 * Hashes without an id (before the first rotation) count as peppered with the current
 * key; passwordNeedsRehash() adds the id to them at the next sign-in, likewise hashes of a
 * retired key with the current one.
 *
 * ERROR BEHAVIOR
 * --------------------
 * encrypt() throws on failure. decrypt() does NOT throw but returns '' -
 * equally for a wrong key, wrong salt, a manipulated ciphertext and invalid
 * base64. An empty result is therefore not distinguishable from a value that was
 * stored empty.
 */
namespace Camindo\Core;

class Crypt {
    /** Raw content of paths.key_file - serves as both AES and HMAC key */
    private string $key;

    /** Id of the current key (keyId()) */
    private string $keyId = '';

    /** Retired keys: kid => ['key' => …, 'retired_at' => ISO 8601] */
    private array $retired = [];

    /** Origin of the current key: 'file' or 'env' */
    private string $keySource = 'file';

    // Constant for the encryption method
    // GCM = authenticated encryption: returns an auth tag alongside the ciphertext,
    // with which decrypt() detects manipulation.
    private const CIPHER = 'aes-256-gcm';

    /** Length of the salt in the earlier password format (passwordVerify()) in bytes */
    private const PASSWORD_SALT_LENGTH = 16;

    public function __construct(
        private Config $config
    )
    {
        // Check required settings
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
     * Read the keys (again): current one from paths.key_file, retired ones from
     * {key_file}.retired - after a rotation (KeyRotation).
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
        // Variable not updated after a rotation and the file already removed:
        // continuing with the old key would mean encrypting new data under it and
        // treating all signatures as manipulated
        if ($this->keySource === 'env' && $this->isRetiredKey($this->key)) {
            throw new \RuntimeException('CONFIGURATION ERROR: ' . Config::ENV_KEY . ' holds the retired key '
                . $this->keyId . ' - set it to the current key (or restore ' . basename($keyFile) . ')');
        }
    }

    /** Is the key in {key_file}.retired? (ids there whose line was skipped included) */
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

    /** Origin of the current key: 'file' (paths.key_file) or 'env' (CAMINDO_KEY) */
    public function keySource(): string
    {
        return $this->keySource;
    }

    /**
     * Compare the variable CAMINDO_KEY with the current key:
     *     ''         variable not set
     *     'current'  contains the current key
     *     'retired'  contains a retired one - update after `key rotate`
     *     'other'    contains an unknown key
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
     * The current key itself - only for KeyRotation (old key into the key ring),
     * even if it comes from the environment.
     *
     * @internal
     */
    public function currentKeyForRotation(): string
    {
        return $this->key;
    }

    /** Id of a key: the first 8 hex characters of sha256 */
    public static function keyId(string $key): string
    {
        return substr(hash('sha256', $key), 0, 8);
    }

    /** Id of the current key */
    public function currentKeyId(): string
    {
        return $this->keyId;
    }

    /** Retired keys: kid => retired_at (without the keys themselves) */
    public function retiredKeys(): array
    {
        return array_map(fn($k) => $k['retired_at'], $this->retired);
    }

    /** Key for an id - current or retired, otherwise null */
    private function keyById(string $kid): ?string
    {
        if ($kid === $this->keyId) return $this->key;
        return $this->retired[$kid]['key'] ?? null;
    }

    /**
     * Can it be decrypted with exactly the current key? (KeyRotation::status())
     */
    public function decryptsWithCurrent(string $text, string $salt = '', bool $binary = false): bool
    {
        if ($text === '') return true;
        return $this->decryptWith($this->key, $text, $salt, $binary) !== null;
    }

    /**
     * Encrypt a value (AES-256-GCM)
     *
     * @param string $text  text to encrypt
     * @param string $salt  (used as Additional Authenticated Data - AAD)
     * @param bool $binary  true: return raw bytes, false: return base64-encoded string (default)
     * @return string
     */
    public function encrypt(string $text, string $salt = '', bool $binary = false): string
    {
        // Empty string stays
        if ($text === '') return '';
        
        // Generate a random initialization vector IV
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = random_bytes($ivLength);
        $tag = '';

        // Encrypt the text
        // OPENSSL_RAW_DATA prevents additional formatting by OpenSSL
        $encrypted = openssl_encrypt($text, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag, $salt);

        if ($encrypted === false) {
            throw new \RuntimeException('Verschlüsselung fehlgeschlagen.');
        }

        // Join IV, tag (for authentication) and the encrypted text
        if ($binary) return $iv . $tag . $encrypted;
        else return base64_encode($iv . $tag . $encrypted);
    }

   /**
     * Decrypt a value
     *
     * @param string $text  encrypted text
     * @param string $salt  (must match the salt used for encryption exactly)
     * @param bool $binary  true: processes raw bytes, false: processes base64-encoded string (default)
     * @return string
     */
    public function decrypt(string $text, string $salt = '', bool $binary = false): string
    {
        // Empty string stays
        if ($text === '') return '';

        if (!$binary) {
            $decoded = base64_decode($text, true);
            if ($decoded === false) {
                // Invalid base64
                return '';
            }
        }
        else {
            $decoded = $text;
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $tagLength = 16; // Standard tag length for GCM

        // Check whether the minimum length for IV and tag is present
        if (strlen($decoded) < ($ivLength + $tagLength)) {
            return ''; 
        }

        // Current key first, then the retired ones (rotation, see class comment)
        $decrypted = $this->decryptWith($this->key, $decoded, $salt, true);
        if ($decrypted !== null) return $decrypted;
        foreach ($this->retired as $retired) {
            $decrypted = $this->decryptWith($retired['key'], $decoded, $salt, true);
            if ($decrypted !== null) return $decrypted;
        }
        return '';
    }

    /**
     * Decrypt with a specific key - null if it does not match.
     */
    private function decryptWith(string $key, string $text, string $salt, bool $binary): ?string
    {
        $decoded = $binary ? $text : base64_decode($text, true);
        if ($decoded === false) return null;
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $tagLength = 16;
        if (strlen($decoded) < ($ivLength + $tagLength)) return null;

        // Extract IV, tag and the actual ciphertext
        $iv = substr($decoded, 0, $ivLength);
        $tag = substr($decoded, $ivLength, $tagLength);
        $ciphertext = substr($decoded, $ivLength + $tagLength);

        // Decrypt the text and verify authenticity
        $decrypted = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, $salt);
        return $decrypted !== false ? $decrypted : null;
    }

    /**
     * Create a cryptographically secure, compact HMAC hash
     *
     * @param string $text  text to be hashed
     * @param bool $binary  true:  binary format (32 bytes)
     *                      false: readable format (43 latin-1 characters)
     * @return string
     */
    public function hash(string $text, bool $binary = false): string
    {
        if ($text === '') return '';
        
        // true = returns 32 raw bytes instead of 64 hex characters
        $rawHash = hash_hmac('sha256', $text, $this->key, true);
        
        if ($binary) {
            // 32 raw bytes
            return $rawHash;
        }
        else {
            // 43 readable characters
            return $this->encodeCompact($rawHash);
        }
    }

    /**
     * HMAC under all keys, the current one first - for finding stored HMACs
     * whose plain text is only known when redeemed (recovery codes,
     * device tokens), also after a rotation.
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
     * HMAC with a specific key (current or retired) - for data that carries
     * the id of its key along (chain of the audit log, AuditStore).
     *
     * @return string|null  as hash() (readable), null for an unknown key id
     */
    public function hashWithKey(string $kid, string $text): ?string
    {
        $key = $this->keyById($kid);
        if ($key === null) return null;
        return $this->encodeCompact(hash_hmac('sha256', $text, $key, true));
    }

    /**
     * Hash a password - password_hash() over the peppered value (format see
     * class comment). Two calls with the same password return different
     * values; only passwordVerify() can compare them.
     *
     * @param string $password  plain text
     * @return string           60 (bcrypt) or 97 characters (Argon2id); '' for an empty password
     */
    public function passwordHash(string $password): string
    {
        if ($password === '') return '';
        [$algo, $options] = $this->passwordAlgo();
        return $this->keyId . ':' . password_hash($this->passwordPepper($password, $this->key), $algo, $options);
    }

    /**
     * Separate id and hash: "{kid}:{hash}" -> [kid, hash]; without an id ['', hash].
     */
    public static function splitPasswordHash(string $stored): array
    {
        if (preg_match('/^([0-9a-f]{8}):(.*)$/s', $stored, $m)) return [$m[1], $m[2]];
        return ['', $stored];
    }

    /**
     * Check a password against a stored hash - current format or the
     * earlier one (salt + HMAC, 64 characters).
     *
     * Also runs the same calculation with an empty or unusable $stored (against
     * a substitute hash with the current parameters), so that the response time reveals
     * nothing about whether an account exists for the name (Admin::loginByName(),
     * RecordUser::cmsLogin()).
     *
     * @param string $password  plain text
     * @param string $stored    value from admin.password or data_pwd.data
     */
    public function passwordVerify(string $password, string $stored): bool
    {
        // Key from the id; without an id the current one (see class comment).
        // An unknown id (key removed) calculates against the substitute hash.
        [$kid, $stored] = self::splitPasswordHash($stored);
        $key = $kid === '' ? $this->key : $this->keyById($kid);
        if ($key !== null && str_starts_with($stored, '$')) {
            $ok = password_verify($this->passwordPepper($password, $key), $stored);
            return $password !== '' && $ok;
        }
        $raw = $key !== null ? $this->decodeCompact($stored) : '';
        if ($password !== '' && strlen($raw) === self::PASSWORD_SALT_LENGTH + 32) {
            // earlier format
            $salt = substr($raw, 0, self::PASSWORD_SALT_LENGTH);
            return hash_equals(substr($raw, self::PASSWORD_SALT_LENGTH), hash_hmac('sha256', $salt . $password, $key, true));
        }
        // No usable hash: calculate once anyway
        password_verify($this->passwordPepper($password, $this->key), $this->dummyHash());
        return false;
    }

    /**
     * Should a stored hash be recalculated? Earlier format, a different
     * method or different parameters than in security.password_hash. The caller
     * has just checked the password and writes passwordHash() back.
     */
    public function passwordNeedsRehash(string $stored): bool
    {
        if ($stored === '') return false;
        [$kid, $hash] = self::splitPasswordHash($stored);
        // without an id or with the pepper of a retired key
        if ($kid !== $this->keyId) return true;
        return $this->hashOutdated($hash);
    }

    /** Earlier format, different method or different parameters (without an id) */
    private function hashOutdated(string $hash): bool
    {
        if (!str_starts_with($hash, '$')) return true;
        [$algo, $options] = $this->passwordAlgo();
        return password_needs_rehash($hash, $algo, $options);
    }

    /**
     * Method and parameters from security.password_hash: algo 'auto' (Argon2id if
     * PHP supports it, otherwise bcrypt), 'argon2id' or 'bcrypt'.
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
     * Pepper: HMAC with the key, base64 - 44 characters, independent of the length and
     * content of the input.
     */
    private function passwordPepper(string $password, string $key): string
    {
        return base64_encode(hash_hmac('sha256', $password, $key, true));
    }

    /**
     * Substitute hash for passwordVerify() without a stored value - with the current
     * parameters, so that the computation time matches a real check. Created once
     * and stored in var/cache: recalculating it per request would cost
     * double the time, especially in the case "name unknown".
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
     * Create a cryptographically secure, random key
     *
     * @param int $length length of the raw entropy in bytes (default: 32 bytes = 256 bit)
     * @return string compact string (43 characters for 32 bytes)
     */
    public function randomKey(int $length = 32): string 
    {
        $rawBytes = random_bytes($length);
        
        return $this->encodeCompact($rawBytes);
    }

    /**
     * Converts raw bytes into a compact, URL-safe base64 string
     * Uses a-z, A-Z, 0-9, - and _
     */
    private function encodeCompact(string $rawData): string 
    {
        // Standard base64 produces + and / (can disturb URLs or systems)
        // We swap these for - and _ and remove the = padding character
        return rtrim(strtr(base64_encode($rawData), '+/', '-_'), '=');
    }

    /**
     * Inverse of encodeCompact(); '' for invalid input
     */
    private function decodeCompact(string $data): string
    {
        if ($data === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $data)) return '';
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }
}