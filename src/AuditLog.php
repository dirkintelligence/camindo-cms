<?php
/**
 * Audit-Log: wer hat wann was geändert. Instanzname im Code: $auditLog.
 *
 * ZWEI ABLAGEN (seit 2026-09-26, 16-sicherheit.md §20)
 * --------------------
 *     Tabelle audit_log   Metadaten für Listen und Recherche: Zeit, Handelnder,
 *                         Herkunft, Aktion, Objekt, Bezeichnung und `fields` - die
 *                         geänderten Felder OHNE Werte (Schlüssel, Beschriftung,
 *                         Sprache), dazu `page` (Seite der Datei)
 *     var/audit/…         die vollständigen Einträge samt Vorher/Nachher, je Objekt
 *                         eine Datei mit HMAC-Kette (Camindo\Core\AuditStore)
 * write() trägt die Metazeile in der Transaktion des Aufrufers ein und hängt den
 * Inhalt nach dem Commit an die Datei (Database::afterCommit()). search() liest nur
 * die Datenbank; forObject() und load() holen die Inhalte aus der Datei.
 *
 * WAS HINEINKOMMT
 * --------------------
 * Jede Änderung, die über ein Dataset läuft - Datensätze, Bereiche, Felder,
 * Templates, Admins, Gruppen, Instanzen, Sprachen, Status, Konverter, Aufgaben,
 * Komponenten -, gleich aus welcher Quelle: Eingabemaske, Frontend (CMS:USER_INPUT),
 * Import, Stapelverarbeitung, Verschieben, Papierkorb-Cron. Dazu die Anmeldungen
 * am Backend (login, login_failed, logout) und jede Datenauskunft zu einem Website-
 * User (export, RecordPersonalData). Dataset ruft dafür an vier Stellen:
 *
 *     save()            create / update  - mit Vorher/Nachher der geänderten Felder
 *     softDeleteOne()   delete           - Papierkorb, Inhalt bleibt erhalten
 *     restoreOneCore()  restore
 *     finalDeleteOne()  purge            - endgültig; der letzte Stand wird mitgeschrieben
 *
 * Eine Kindklasse schaltet sich mit $audit = false ab (Messaging: private
 * Nachrichten gehören nicht ins Protokoll) und nennt mit $auditIgnore Spalten, die
 * nichts aussagen oder geheim sind (Anmeldecode, Signatur, Volltext …).
 *
 * VORHER / NACHHER
 * --------------------
 * Verglichen werden Schnappschüsse (Dataset::auditSnapshot()): eine flache Liste
 * Schlüssel => ['l' => Beschriftung (roh, '#snippet' möglich), 't' => Feldtyp,
 * 'g' => Sprachcode oder '', 'v' => Wert als Text, 'c' => Vergleichswert (optional)].
 * Ein Datensatz liefert dabei ALLE Felder in allen Sprachen samt Metafeldern und
 * Verknüpfungen (Record::auditSnapshot()). diff() nimmt nur, was sich unterscheidet;
 * ein Speichern ohne Änderung erzeugt keinen Eintrag.
 *
 * GEHEIMES BLEIBT GEHEIM
 * --------------------
 *     password    nie ein Wert, nur "geändert" (Vergleich über einen Hash des Hashs)
 *     encrypted   gespeichert wird der Geheimtext (base64), verglichen über einen
 *                 HMAC des Klartexts; entschlüsselt wird erst bei der Anzeige
 *
 * AUFBEWAHRUNG (Config audit.*)
 * --------------------
 *     retention_days_changes  Einträge zu Objekten, die es noch gibt, und
 *                             Anmeldungen. 0 = unbegrenzt: ein Datensatz bleibt bis
 *                             zu seinem Ursprung nachvollziehbar.
 *     log_logins              erfolgreiche An- und Abmeldungen am Backend
 *                             protokollieren (Vorgabe false - sie verraten
 *                             Arbeitszeiten, aber nichts über Änderungen);
 *                             Fehlversuche stehen immer drin
 *     retention_days_logins   An-/Abmeldungen und Fehlversuche, in Tagen (Vorgabe 90,
 *                             0 = wie retention_days_changes)
 *     retention_days_deleted  Einträge zu endgültig gelöschten Objekten, gezählt ab
 *                             dem Löschen (Eintrag 'purge'). Danach verschwindet
 *                             ihr ganzer Verlauf. Nicht für Datensätze: deren
 *                             Verlauf regelt die Lösch-Policy des Bereichs (Modul
 *                             Privacy - deleteObject(), overwriteObject()).
 * purge() läuft aus der Zeitplaner-Aufgabe "Aufbewahrungsfristen anwenden" (retention).
 *
 * IP-ADRESSEN
 * --------------------
 * Die Spalte `ip` hält die volle Adresse, bis IpAnonymizer sie nach
 * privacy.ip_anonymize_days kürzt (Vorgabe 7 Tage). In die Datei unter var/audit
 * kommt sie von Beginn an gekürzt - an die HMAC-Kette wird nur angehängt.
 *
 * Keine Fremdschlüssel: ein Eintrag überlebt Admin, Datensatz und Bereich, von denen
 * er erzählt.
 *
 * HANDELNDE NUR ALS ID
 * --------------------
 * Wer gehandelt hat, steht als admin_id bzw. user_id - ohne Namen, weder in
 * `audit_log.actor_name` noch in der Datei. Den Namen eines Benutzers setzt erst die
 * Anzeige ein (AuditDiff::actorLabel(), aus `admin` - auch für gelöschte). So genügt
 * zum Anonymisieren eines Benutzers das Umbenennen seiner Zeile (Modul Privacy,
 * AdminAnonymizer); die Dateien der Objekte, an denen er gearbeitet hat, bleiben
 * unberührt. Einen Namen trägt nur ein Fehlversuch mit einem Namen, den es nicht gibt
 * (login(): zu einem bestehenden Konto steht er unter dessen admin_id). Ältere Einträge
 * mit Namen bereinigt `camindo.php audit strip-names` (stripActorNames()).
 *
 * @see Camindo\Core\Dataset::auditSnapshot()
 * @see Camindo\Core\AuditDiff                       grafischer Vergleich
 * @see Camindo\Core\Modules\Audit\AuditBackend      Recherche (Superuser)
 * @see Camindo\Core\Modules\Record\RecordBackendInfo  Reiter "Änderungen"
 */
namespace Camindo\Core;

class AuditLog {

    public const ACTIONS = ['create', 'update', 'delete', 'restore', 'purge', 'login', 'login_failed', 'logout', 'export', 'privacy'];

    /** Ergebnis der Prüfung (Zeitplaner-Aufgabe auditverify) unter var/cache */
    public const VERIFY_FILE = 'audit-verify.json';

    /** Obergrenze eines einzelnen Werts (Zeichen) - darüber wird gekürzt */
    public const MAX_VALUE_LENGTH = 200000;

    /** Kennzeichen am Ende eines gekürzten Werts */
    public const TRUNCATED = ' […]';

    /** Anmeldungen und Abmeldungen hängen am Admin */
    private const LOGIN_ACTIONS = ['login', 'login_failed', 'logout'];

    /** Herkunft, die der Aufrufer für einen Vorgang setzt (setContext()) */
    private string $context = '';

    /** admin_id => Name, je Request */
    private array $adminNames = [];

    public function __construct(
        private Config $config,
        private Database $db,
        private Session $session,
        private Crypt $crypt,
        private ContainerInterface $container,
        private AuditStore $store,
    )
    {}

    /**
     * Audit-Log eingeschaltet (audit.enabled, Vorgabe true)?
     */
    public function enabled(): bool
    {
        return $this->config->get('audit.enabled') !== false;
    }

    /**
     * Herkunft der folgenden Einträge festlegen - 'import', 'batch', 'trash' …
     * (leer = aus der Laufumgebung: backend, frontend, cli).
     */
    public function setContext(string $context): void
    {
        $this->context = preg_replace('/[^a-z0-9_-]/', '', strtolower($context)) ?? '';
    }

    /** Die gesetzte Herkunft ('' = aus der Laufumgebung) */
    public function context(): string
    {
        return $this->context;
    }

    // ==== Schreiben ====

    /**
     * Einen Eintrag schreiben. Läuft in der Transaktion des Aufrufers - ein Rollback
     * nimmt den Eintrag mit.
     *
     * @param string   $action      create|update|delete|restore|purge|login|login_failed|logout|export
     * @param string   $objectType  Tabellenname ('record', 'area', 'admin' …)
     * @param int      $objectId    Id des Objekts
     * @param string   $label       Bezeichnung des Objekts zum Zeitpunkt der Änderung
     * @param array    $changes     Ergebnis von diff() bzw. fullChanges()
     * @param int|null $areaId      Bereich (bei Datensätzen)
     * @param int|null $instanceId  Instanz (wo bekannt)
     * @param string   $actorName   nur für login_failed: der eingegebene Name
     */
    public function write(string $action, string $objectType, int $objectId, string $label = '',
        array $changes = [], ?int $areaId = null, ?int $instanceId = null, string $actorName = ''): void
    {
        if (!$this->enabled() || !in_array($action, self::ACTIONS, true)) return;
        $actor = $this->actor();
        if ($actorName !== '') $actor['name'] = $actorName;

        $changes = array_values($changes);
        $label = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($label)) ?? ''), 0, 255);
        // Datensatz, dessen Audit-Log eine Lösch-Policy schon überschrieben hat: auch
        // jeder spätere Eintrag (etwa 'purge' mit dem letzten Stand) wird so geschrieben
        if ($objectType === 'record' && ($override = $this->policyOverride($objectId)) !== null) {
            $entry = self::applyOverride(['label' => $label, 'actor' => $actor, 'changes' => $changes], ...$override);
            [$label, $actor, $changes] = [$entry['label'], $entry['actor'], $entry['changes']];
        }
        $actor['name'] = mb_substr($actor['name'], 0, 128);
        // -1 = alle Instanzen (etwa eine Lösch-Policy) - die Spalte ist unsigned
        if ($instanceId !== null && $instanceId < 0) $instanceId = 0;
        $time = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
        $this->db->pushResult();
        try {
            $this->db->query(
                "INSERT INTO `audit_log` (`created_at`, `actor_type`, `admin_id`, `user_id`, `actor_name`, `ip`,"
                . " `origin`, `action`, `object_type`, `object_id`, `area_id`, `instance_id`, `label`, `fields`, `page`)"
                . " VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)",
                'ssiisssssiiiss',
                [
                    $time, $actor['type'], $actor['admin_id'], $actor['user_id'],
                    $actor['name'], $actor['ip'], $actor['origin'],
                    $action, $objectType, $objectId, $areaId, $instanceId,
                    $label, $this->fieldsJson($changes),
                ]
            );
            $auditId = (int)$this->db->insert_id;
        }
        finally {
            $this->db->popResult();
        }

        // Inhalt erst nach dem Commit in die Datei - ein Rollback nimmt die Metazeile
        // mit, in der Datei bliebe sonst ein Eintrag ohne Gegenstück
        // In der Datei nur die gekürzte IP: an die HMAC-Kette wird nur angehängt,
        // kürzen ließe sie sich dort später nicht mehr (IpAnonymizer)
        $fileActor = ['ip' => ClientIp::anonymize((string)$actor['ip'], ClientIp::v4Octets($this->config))] + $actor;
        if ((string)($fileActor['name'] ?? '') === '') unset($fileActor['name']);
        $entry = [
            'id' => $auditId, 't' => $time, 'action' => $action, 'type' => $objectType, 'oid' => $objectId,
            'actor' => $fileActor, 'label' => $label, 'area' => $areaId, 'instance' => $instanceId,
            'changes' => $changes,
        ];
        $this->db->afterCommit(function () use ($entry) {
            try {
                $page = $this->store->append($entry);
                $this->db->pushResult();
                try {
                    $this->db->query("UPDATE `audit_log` SET `page` = ? WHERE `audit_id` = ?", 'ii', [$page, $entry['id']]);
                }
                finally {
                    $this->db->popResult();
                }
            }
            catch (\Throwable $e) {
                // Der Vorgang selbst ist gespeichert - der fehlende Inhalt fällt bei
                // `camindo.php audit verify` auf (page = 0)
                $this->container->get(System::class)->log('security', "audit file write failed for #{$entry['id']}: " . $e->getMessage());
            }
        });
    }

    /**
     * Die geänderten Felder ohne Werte für die Metazeile (Listen, Filter "Feld").
     */
    private function fieldsJson(array $changes): string
    {
        $fields = [];
        foreach ($changes as $change) {
            $fields[] = ['k' => (string)($change['k'] ?? ''), 'l' => (string)($change['l'] ?? ''), 'g' => (string)($change['g'] ?? '')];
        }
        return $fields ? (string)json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : '';
    }

    /**
     * Anmeldung am Backend protokollieren (StartBackend).
     *
     * Ein Fehlversuch mit dem Namen eines bestehenden Kontos steht im Verlauf dieses
     * Kontos (object_id = admin_id) und ohne den eingegebenen Namen - der ergibt sich
     * aus der Id. Nur ein Name, den es nicht gibt, wird mitgeschrieben (Objekt 0).
     *
     * @param string $action  login|login_failed|logout
     * @param int    $adminId 0 bei login_failed
     * @param string $name    login_failed: der eingegebene Name; sonst leer = aus `admin`
     * @param string $origin  Oberfläche (Access::INTERFACES), wo der Kontext sie nicht
     *                        schon nennt - etwa eine gescheiterte Anmeldung über die API
     */
    public function login(string $action, int $adminId, string $name = '', string $origin = ''): void
    {
        if (!in_array($action, self::LOGIN_ACTIONS, true)) return;
        // Erfolgreiche An- und Abmeldungen ohne Tätigkeit sagen nichts über Änderungen
        // aus, wohl aber über Arbeitszeiten - nur auf ausdrücklichen Wunsch (audit.log_logins)
        if ($action !== 'login_failed' && !$this->logLogins()) return;
        $actorName = '';
        if ($action === 'login_failed' && $adminId < 1 && $name !== '') {
            $adminId = $this->adminIdByName($name);
            if ($adminId < 1) $actorName = $name;
        }
        if ($adminId > 0) $name = $this->adminName($adminId);
        $context = $this->context;
        if ($origin !== '') $this->context = $origin;
        try {
            $this->write($action, 'admin', $adminId, $name, [], null, null, $actorName);
        }
        finally {
            $this->context = $context;
        }
    }

    /** admin_id zu einem Benutzernamen (Kollation der Spalte), 0 = keiner */
    private function adminIdByName(string $name): int
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `admin_id` FROM `admin` WHERE `name` = ?", 's', [$name]);
            return (int)($this->db->fetchNum()[0] ?? 0);
        }
        finally {
            $this->db->popResult();
        }
    }

    /** Erfolgreiche An- und Abmeldungen protokollieren? (audit.log_logins, Vorgabe false) */
    public function logLogins(): bool
    {
        return (bool)($this->config->get('audit.log_logins') ?? false);
    }

    /** Tage, nach denen An- und Abmeldungen gelöscht werden (audit.retention_days_logins, 0 = wie Änderungen) */
    public function loginRetentionDays(): int
    {
        return max(0, (int)($this->config->get('audit.retention_days_logins') ?? 90));
    }

    // ==== Vergleich ====

    /**
     * Die Unterschiede zweier Schnappschüsse.
     *
     * @param array $before  Schnappschuss vorher ([] bei create)
     * @param array $after   Schnappschuss nachher ([] bei purge)
     * @return array         Liste aus ['k' => Schlüssel, 'l' => Beschriftung, 't' => Typ,
     *                       'g' => Sprachcode, 'b' => vorher, 'a' => nachher]
     */
    public function diff(array $before, array $after): array
    {
        $changes = [];
        foreach (array_keys($before + $after) as $key) {
            $b = $before[$key] ?? null;
            $a = $after[$key] ?? null;
            $bCmp = $b === null ? '' : (string)($b['c'] ?? $b['v'] ?? '');
            $aCmp = $a === null ? '' : (string)($a['c'] ?? $a['v'] ?? '');
            if ($bCmp === $aCmp) continue;
            $ref = $a ?? $b;
            $type = (string)($ref['t'] ?? 'text');
            $changes[] = [
                'k' => (string)$key,
                'l' => (string)($ref['l'] ?? $key),
                't' => $type,
                'g' => (string)($ref['g'] ?? ''),
                // Passwörter: nur die Tatsache der Änderung
                'b' => $type === 'password' ? ($bCmp === '' ? '' : '***') : $this->limit((string)($b['v'] ?? '')),
                'a' => $type === 'password' ? ($aCmp === '' ? '' : '***') : $this->limit((string)($a['v'] ?? '')),
            ];
        }
        return $changes;
    }

    // ==== Lesen ====

    /**
     * Einträge zu einem Objekt mit vollständigen Änderungen, neueste zuerst.
     */
    public function forObject(string $objectType, int $objectId, int $limit = 200): array
    {
        $rows = $this->search(['object_type' => $objectType, 'object_id' => $objectId], $limit, 0);
        $entries = $rows ? $this->store->entries($objectType, $objectId) : [];
        foreach ($rows as &$row) {
            $row['changes'] = (array)($entries[(int)$row['audit_id']]['changes'] ?? []);
            $row['content_missing'] = !isset($entries[(int)$row['audit_id']]);
        }
        return $rows;
    }

    /**
     * Eine Seite des Verlaufs eines Objekts samt Vorher/Nachher - liest nur die Seiten
     * der Datei, auf denen die Einträge stehen (für blätternde Abfragen wie
     * audit.history).
     *
     * @return array  Zeilen wie forObject()
     */
    public function historyPage(string $objectType, int $objectId, int $limit, int $offset, bool $oldestFirst): array
    {
        $rows = $this->search(['object_type' => $objectType, 'object_id' => $objectId], $limit, $offset, $oldestFirst);
        $pages = array_unique(array_map(fn($row) => (int)$row['page'], $rows));
        $entries = [];
        if (in_array(0, $pages, true)) {
            $entries = $rows ? $this->store->entries($objectType, $objectId) : [];
        }
        else {
            foreach ($pages as $page) $entries += $this->store->entries($objectType, $objectId, $page);
        }
        foreach ($rows as &$row) {
            $row['changes'] = (array)($entries[(int)$row['audit_id']]['changes'] ?? []);
            $row['content_missing'] = !isset($entries[(int)$row['audit_id']]);
        }
        return $rows;
    }

    /**
     * Prüfung der Datei eines Objekts (AuditStore::verify()) und Abgleich mit der
     * Datenbank: fehlen Inhalte zu Metazeilen oder Metazeilen zu Inhalten, und stimmen
     * die Metaspalten mit dem signierten Eintrag der Datei überein (metaMismatch())?
     * Listen und Recherche zeigen die Metaspalten - per SQL geändert, fiele das sonst
     * nicht auf.
     *
     * @return array  ok, errors (Texte), lines
     */
    public function verifyObject(string $objectType, int $objectId): array
    {
        $check = $this->store->verify($objectType, $objectId);
        $errors = array_map(fn($e) => "page {$e['page']} line {$e['line']}: {$e['reason']}", $check['errors']);
        $rows = [];
        $this->db->pushResult();
        try {
            $this->db->query("SELECT * FROM `audit_log` WHERE `object_type` = ? AND `object_id` = ?",
                'si', [$objectType, $objectId]);
            while ($row = $this->db->fetch()) $rows[(int)$row['audit_id']] = $row;
        }
        finally {
            $this->db->popResult();
        }
        $dbIds = array_keys($rows);
        $missingContent = array_diff($dbIds, $check['ids']);
        $missingMeta = array_diff($check['ids'], $dbIds);
        if ($missingContent) $errors[] = 'content missing for #' . implode(', #', $missingContent);
        if ($missingMeta) $errors[] = 'database entries missing for #' . implode(', #', $missingMeta);
        foreach ($rows as $auditId => $row) {
            if (!isset($check['entries'][$auditId])) continue;
            $columns = $this->metaMismatch($row, $check['entries'][$auditId]);
            if ($columns) $errors[] = "database entry #$auditId differs from file: " . implode(', ', $columns);
        }
        return ['ok' => !$errors, 'errors' => $errors, 'lines' => $check['lines']];
    }

    /**
     * Metaspalten einer Zeile aus `audit_log`, die nicht zum Eintrag der Datei passen.
     * Verglichen wird, was write() in beide Ablagen schreibt, in derselben Kürzung;
     * die IP nur auf zwei Oktette bzw. 48 Bit gekürzt - in der Datei steht sie von
     * Beginn an gekürzt, in der Spalte erst nach privacy.ip_anonymize_days, und
     * privacy.ip_v4_octets kann sich dazwischen geändert haben.
     *
     * @return array  Namen der abweichenden Spalten
     */
    private function metaMismatch(array $row, array $entry): array
    {
        $actor = (array)($entry['actor'] ?? []);
        $int = fn($v) => $v === null || $v === '' ? null : (int)$v;
        $expected = [
            'created_at'  => (string)($entry['t'] ?? ''),
            'action'      => (string)($entry['action'] ?? ''),
            'actor_type'  => (string)($actor['type'] ?? ''),
            'admin_id'    => $int($actor['admin_id'] ?? null),
            'user_id'     => $int($actor['user_id'] ?? null),
            'actor_name'  => mb_substr((string)($actor['name'] ?? ''), 0, 128),
            'origin'      => (string)($actor['origin'] ?? ''),
            'area_id'     => $int($entry['area'] ?? null),
            // -1 (alle Instanzen) steht in der Spalte als 0, siehe write()
            'instance_id' => ($instance = $int($entry['instance'] ?? null)) !== null ? max(0, $instance) : null,
            'label'       => mb_substr((string)($entry['label'] ?? ''), 0, 255),
            'fields'      => $this->fieldsJson((array)($entry['changes'] ?? [])),
            'ip'          => ClientIp::anonymize((string)($actor['ip'] ?? ''), 2),
        ];
        $actual = [
            'created_at'  => (string)$row['created_at'],
            'action'      => (string)$row['action'],
            'actor_type'  => (string)$row['actor_type'],
            'admin_id'    => $int($row['admin_id']),
            'user_id'     => $int($row['user_id']),
            'actor_name'  => (string)$row['actor_name'],
            'origin'      => (string)$row['origin'],
            'area_id'     => $int($row['area_id']),
            'instance_id' => $int($row['instance_id']),
            'label'       => (string)$row['label'],
            'fields'      => (string)$row['fields'],
            'ip'          => ClientIp::anonymize((string)$row['ip'], 2),
        ];
        return array_keys(array_filter($expected, fn($value, $column) => $value !== $actual[$column], ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Alle Dateien und Metazeilen prüfen (`camindo.php audit verify`).
     *
     * @return array  checked (Objekte), failed ("typ:id" => Fehlertexte)
     */
    public function verifyAll(): array
    {
        $objects = [];
        foreach ($this->store->objects() as [$type, $id]) $objects["$type:$id"] = [$type, $id];
        $this->db->pushResult();
        try {
            $this->db->query("SELECT DISTINCT `object_type`, `object_id` FROM `audit_log`");
            while ($row = $this->db->fetch()) {
                $objects[$row['object_type'] . ':' . $row['object_id']] = [(string)$row['object_type'], (int)$row['object_id']];
            }
        }
        finally {
            $this->db->popResult();
        }
        $failed = [];
        foreach ($objects as $key => [$type, $id]) {
            $check = $this->verifyObject($type, $id);
            if (!$check['ok']) $failed[$key] = $check['errors'];
        }
        return ['checked' => count($objects), 'failed' => $failed];
    }

    /**
     * Ergebnis einer Gesamtprüfung festhalten (var/cache/audit-verify.json) - daraus
     * zeigt die Startseite den Betriebshinweis (SystemHealth, Prüfung 'audit').
     * Geschrieben von der Zeitplaner-Aufgabe "Audit-Log prüfen" (auditverify) und von
     * `camindo.php audit verify|restore`.
     *
     * @param array $result  verifyAll()
     */
    public function storeVerifyResult(array $result): void
    {
        $file = rtrim((string)$this->config->get('paths.var_dir'), '/') . '/cache/' . self::VERIFY_FILE;
        @file_put_contents($file, json_encode(['checked_at' => gmdate('Y-m-d H:i:s')] + $result,
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * Den festgehaltenen Befund EINES Objekts nachführen (nach reseal oder einer
     * Einzelprüfung) - ohne alle Objekte erneut zu prüfen. Ohne gespeicherte
     * Gesamtprüfung bleibt es dabei.
     *
     * @param array $check  verifyObject()
     */
    public function updateVerifyResult(string $objectType, int $objectId, array $check): void
    {
        $file = rtrim((string)$this->config->get('paths.var_dir'), '/') . '/cache/' . self::VERIFY_FILE;
        if (!is_file($file)) return;
        $result = json_decode((string)file_get_contents($file), true);
        if (!is_array($result)) return;
        $key = "{$objectType}:{$objectId}";
        $failed = (array)($result['failed'] ?? []);
        if ($check['ok']) unset($failed[$key]);
        else $failed[$key] = $check['errors'];
        $result['failed'] = $failed;
        @file_put_contents($file, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * Dateien auf den Stand der Datenbank zurücksetzen - nach dem Zurückspielen einer
     * Datenbanksicherung (`camindo.php audit restore`).
     *
     * Die Dateien unter var/audit sind nicht Teil des Dumps: nach dem Zurückspielen
     * enthalten sie Einträge, deren Metazeilen es nicht mehr gibt, und die Datenbank
     * vergibt deren audit_ids neu. Entfernt wird jeder Eintrag mit einer audit_id über
     * der höchsten der Datenbank (AuditStore::cutAfter()); die betroffenen Dateien
     * landen vorher unverändert in var/audit-restore-{Zeitpunkt}/. Muss laufen, bevor
     * neue Einträge entstehen.
     *
     * @param bool $dryRun  nur zählen
     * @return array        max_id, objects (betroffene Objekte), removed (Einträge),
     *                      archive (Verzeichnis der Kopien, '' ohne Änderung)
     */
    public function restoreToDatabase(bool $dryRun = false): array
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT MAX(`audit_id`) FROM `audit_log`");
            $maxId = (int)($this->db->fetchNum()[0] ?? 0);
        }
        finally {
            $this->db->popResult();
        }
        $archive = rtrim((string)$this->config->get('paths.var_dir'), '/') . '/audit-restore-' . gmdate('Ymd-His');
        $result = ['max_id' => $maxId, 'objects' => 0, 'removed' => 0, 'archive' => ''];
        foreach ($this->store->objects() as [$type, $objectId]) {
            if ($dryRun) {
                $count = count(array_filter(array_keys($this->store->entries($type, $objectId)), fn($id) => $id > $maxId));
            }
            else {
                $count = $this->store->cutAfter($type, $objectId, $maxId, $archive);
            }
            if ($count > 0) {
                $result['objects']++;
                $result['removed'] += $count;
            }
        }
        if (!$dryRun && $result['removed'] > 0) $result['archive'] = $archive;
        return $result;
    }

    /**
     * Gebrochene Kette eines Objekts neu verketten (`camindo.php audit reseal`,
     * AuditStore::reseal()). Die Seiten landen vorher unverändert in
     * var/audit-reseal-{Zeitpunkt}/; der Vorgang steht im Protokoll 'security'.
     *
     * @return array  wie AuditStore::reseal(), dazu archive ('' ohne Änderung) und
     *                check (verifyObject() danach)
     */
    public function resealObject(string $objectType, int $objectId, string $reason = '', bool $force = false, bool $dryRun = false): array
    {
        $archive = rtrim((string)$this->config->get('paths.var_dir'), '/') . '/audit-reseal-' . gmdate('Ymd-His');
        $result = $this->store->reseal($objectType, $objectId, $reason, $force, $dryRun, $dryRun ? null : $archive);
        $result['archive'] = $result['status'] === 'resealed' ? $archive : '';
        if ($result['status'] === 'resealed') {
            $this->container->get(System::class)->log('security', "audit reseal {$objectType} {$objectId}: "
                . "{$result['relinked']} entries relinked, {$result['unverified']} unverified"
                . ($reason !== '' ? ", reason: {$reason}" : ''));
        }
        $result['check'] = $this->verifyObject($objectType, $objectId);
        if (!$dryRun) $this->updateVerifyResult($objectType, $objectId, $result['check']);
        return $result;
    }

    /**
     * IP-Adressen in den Dateien kürzen, die vor dem Update mit voller Adresse
     * geschrieben wurden (`camindo.php audit anonymize-ip`, AuditStore::anonymizeIps()).
     * Steht im Protokoll 'security'.
     *
     * @return array  files, entries, skipped (Objekte mit gebrochener Kette)
     */
    public function anonymizeFileIps(bool $dryRun = false): array
    {
        $octets = ClientIp::v4Octets($this->config);
        $result = $this->store->anonymizeIps(fn(string $ip) => ClientIp::anonymize($ip, $octets), $dryRun);
        if (!$dryRun && $result['files'] > 0) {
            $this->container->get(System::class)->log('security', "audit anonymize-ip: {$result['entries']} IP addresses"
                . " in {$result['files']} files shortened" . ($result['skipped'] ? ', skipped (chain broken): ' . implode(', ', $result['skipped']) : ''));
        }
        return $result;
    }

    /**
     * Recherche.
     *
     * @param array $filter  from, to (Y-m-d, UTC), admin_id, action, object_type,
     *                       object_id, area_id, origin, search (Bezeichnung/Name, Teilwort),
     *                       field (Feldschlüssel oder -beschriftung in den Änderungen);
     *                       Einschränkungen: object_types, area_ids (Listen - leer = nichts)
     * @param bool  $oldestFirst  älteste Einträge zuerst (sonst neueste)
     * @return array         Zeilen, `changes` als Array
     */
    public function search(array $filter, int $limit = 100, int $offset = 0, bool $oldestFirst = false): array
    {
        [$where, $types, $values] = $this->where($filter);
        $sql = "SELECT * FROM `audit_log`" . $where
            . " ORDER BY `audit_id` " . ($oldestFirst ? 'ASC' : 'DESC') . " LIMIT " . max(1, $limit) . " OFFSET " . max(0, $offset);
        $this->db->pushResult();
        try {
            $this->db->query($sql, $types, $values);
            $rows = [];
            while ($row = $this->db->fetch()) {
                // Nur die Felder ohne Werte - die Werte stehen in der Datei (forObject(), load())
                $row['changes'] = json_decode((string)$row['fields'], true) ?: [];
                $rows[] = $row;
            }
        }
        finally {
            $this->db->popResult();
        }
        return $rows;
    }

    /**
     * Objekte, die ein Admin zuletzt geändert hat - je Objekt eine Zeile, das
     * zuletzt geänderte zuerst (Reiter "Aktivitäten" der Admin-Info).
     *
     * @param int    $adminId
     * @param string $objectType  z. B. 'record'
     * @return array  ['object_id', 'area_id', 'label', 'action', 'created_at' (UTC),
     *                 'changes' (Anzahl seiner Einträge zu dem Objekt)] je Objekt
     */
    public function recentObjects(int $adminId, string $objectType, int $limit = 20): array
    {
        $this->db->pushResult();
        try {
            // letzter Eintrag je Objekt über idx_admin (admin_id, audit_id)
            $this->db->query(
                "SELECT `a`.`object_id`, `a`.`area_id`, `a`.`label`, `a`.`action`, `a`.`created_at`, `m`.`changes`"
                . " FROM (SELECT `object_id`, MAX(`audit_id`) AS `last_id`, COUNT(*) AS `changes` FROM `audit_log`"
                . "       WHERE `admin_id` = ? AND `object_type` = ? GROUP BY `object_id`"
                . "       ORDER BY `last_id` DESC LIMIT " . max(1, $limit) . ") `m`"
                . " INNER JOIN `audit_log` `a` ON `a`.`audit_id` = `m`.`last_id`"
                . " ORDER BY `a`.`audit_id` DESC",
                'is', [$adminId, $objectType]
            );
            $rows = [];
            while ($row = $this->db->fetch()) $rows[] = $row;
            return $rows;
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Anzahl der Treffer einer Recherche.
     */
    public function count(array $filter): int
    {
        [$where, $types, $values] = $this->where($filter);
        $this->db->pushResult();
        try {
            $this->db->query("SELECT COUNT(*) FROM `audit_log`" . $where, $types, $values);
            return (int)($this->db->fetchNum()[0] ?? 0);
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Ein Eintrag.
     */
    public function load(int $auditId): ?array
    {
        $rows = $this->search(['audit_id' => $auditId], 1, 0);
        $row = $rows[0] ?? null;
        if ($row === null) return null;
        $page = (int)$row['page'];
        $entries = $this->store->entries((string)$row['object_type'], (int)$row['object_id'], $page > 0 ? $page : null);
        $row['changes'] = (array)($entries[$auditId]['changes'] ?? []);
        $row['content_missing'] = !isset($entries[$auditId]);
        return $row;
    }

    /**
     * Vorkommende Objekttypen (für die Auswahlliste der Recherche).
     *
     * @return string[]
     */
    public function objectTypes(): array
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT DISTINCT `object_type` FROM `audit_log` ORDER BY `object_type`");
            return array_map('strval', $this->db->fetchColumn());
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Admins, die im Protokoll vorkommen: admin_id => Name (für die Auswahlliste).
     */
    public function actors(): array
    {
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT `l`.`admin_id`, COALESCE(MAX(`a`.`name`), CONCAT('#', `l`.`admin_id`)) FROM `audit_log` `l`"
                . " LEFT JOIN `admin` `a` ON `a`.`admin_id` = `l`.`admin_id`"
                . " WHERE `l`.`admin_id` IS NOT NULL AND `l`.`actor_type` = 'admin' GROUP BY `l`.`admin_id` ORDER BY 2"
            );
            $list = [];
            foreach ($this->db->fetchPairs() as $id => $name) $list[(int)$id] = (string)$name;
            return $list;
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Namen der Handelnden aus älteren Einträgen entfernen (`camindo.php audit
     * strip-names`): Benutzer und Website-User stehen nur noch als Id (siehe
     * Klassenkommentar). Dateien mit Kontrollpunkt 'strip-names', dazu
     * `audit_log.actor_name`. Wiederholbar - ein zweiter Lauf findet nichts mehr.
     *
     * @return array  ['entries' => geänderte Einträge in Dateien, 'files' => Dateien,
     *                 'rows' => geänderte Metazeilen]
     */
    public function stripActorNames(): array
    {
        $entries = 0;
        $files = 0;
        foreach ($this->store->objects() as [$type, $id]) {
            $changed = $this->store->rewriteObject($type, (int)$id, function (array $body): array {
                if (in_array((string)($body['actor']['type'] ?? ''), ['admin', 'user'], true)) unset($body['actor']['name']);
                return $body;
            }, 'strip-names');
            $entries += $changed;
            if ($changed > 0) $files++;
        }
        $this->db->pushResult();
        try {
            $this->db->query("UPDATE `audit_log` SET `actor_name` = '' WHERE `actor_type` IN ('admin', 'user') AND `actor_name` <> ''");
            $rows = max(0, (int)$this->db->affected_rows);
        }
        finally {
            $this->db->popResult();
        }
        return ['entries' => $entries, 'files' => $files, 'rows' => $rows];
    }

    // ==== Übernahme aus der Datenbank ====

    /**
     * Einträge aus der früheren Spalte audit_log.changes in die Dateien übernehmen
     * (`camindo.php audit migrate`) - je Objekt beginnend mit Kontrollpunkt 'migrate',
     * in der Reihenfolge der audit_id. Danach wird die Spalte entfernt.
     *
     * @return int  übernommene Einträge (0: nichts zu tun)
     */
    public function migrateToFiles(): int
    {
        $this->db->query("SHOW COLUMNS FROM `audit_log` LIKE 'changes'");
        if ($this->db->num_rows < 1) return 0;
        $this->db->query("SELECT * FROM `audit_log` ORDER BY `audit_id`");
        $rows = [];
        while ($row = $this->db->fetch()) $rows[] = $row;
        $count = 0;
        $seen = [];
        foreach ($rows as $row) {
            $type = (string)$row['object_type'];
            $objectId = (int)$row['object_id'];
            if (!isset($seen["$type:$objectId"])) {
                $this->store->appendCheckpoint($type, $objectId, 'migrate');
                $seen["$type:$objectId"] = true;
            }
            $changes = json_decode((string)$row['changes'], true) ?: [];
            $page = $this->store->append([
                'id' => (int)$row['audit_id'], 't' => (string)$row['created_at'], 'action' => (string)$row['action'],
                'type' => $type, 'oid' => $objectId,
                'actor' => ['type' => (string)$row['actor_type'], 'admin_id' => $row['admin_id'] === null ? null : (int)$row['admin_id'],
                    'user_id' => $row['user_id'] === null ? null : (int)$row['user_id'], 'name' => (string)$row['actor_name'],
                    'ip' => ClientIp::anonymize((string)$row['ip'], ClientIp::v4Octets($this->config)), 'origin' => (string)$row['origin']],
                'label' => (string)$row['label'],
                'area' => $row['area_id'] === null ? null : (int)$row['area_id'],
                'instance' => $row['instance_id'] === null ? null : (int)$row['instance_id'],
                'changes' => array_values($changes),
            ]);
            $this->db->query("UPDATE `audit_log` SET `fields` = ?, `page` = ? WHERE `audit_id` = ?",
                'sii', [$this->fieldsJson($changes), $page, (int)$row['audit_id']]);
            $count++;
        }
        $this->db->query("ALTER TABLE `audit_log` DROP COLUMN `changes`");
        return $count;
    }

    // ==== Lösch-Policies (Modul Privacy) ====

    /**
     * Den ganzen Verlauf eines Objekts löschen - Datenbank und Datei (Aktion
     * audit_delete bzw. purge einer Lösch-Policy).
     *
     * @return int  entfernte Einträge der Datenbank
     */
    public function deleteObject(string $objectType, int $objectId): int
    {
        $this->db->pushResult();
        try {
            $this->db->query("DELETE FROM `audit_log` WHERE `object_type` = ? AND `object_id` = ?", 'si', [$objectType, $objectId]);
            $count = max(0, (int)$this->db->affected_rows);
        }
        finally {
            $this->db->popResult();
        }
        $this->store->delete($objectType, $objectId);
        return $count;
    }

    /**
     * Den Verlauf mehrerer Objekte von Hand löschen (`camindo.php audit delete`) - wie
     * deleteObject(), dazu der festgehaltene Prüfbefund und ein Eintrag im Protokoll
     * 'security' (nur Typ, Anzahl und $scope, keine Inhalte).
     *
     * @param int[]  $objectIds
     * @param string $scope   wofür, für das Protokoll, z. B. "area 141"
     * @return array          objects (Objekte mit Verlauf), entries (Zeilen der
     *                        Datenbank), files (Seiten unter var/audit)
     */
    public function deleteObjects(string $objectType, array $objectIds, bool $dryRun = false, string $scope = ''): array
    {
        $result = ['objects' => 0, 'entries' => 0, 'files' => 0];
        foreach (array_unique(array_map('intval', $objectIds)) as $objectId) {
            $files = count($this->store->pages($objectType, $objectId));
            $this->db->pushResult();
            try {
                $this->db->query("SELECT COUNT(*) FROM `audit_log` WHERE `object_type` = ? AND `object_id` = ?", 'si', [$objectType, $objectId]);
                $entries = (int)($this->db->fetchNum()[0] ?? 0);
            }
            finally {
                $this->db->popResult();
            }
            if ($files === 0 && $entries === 0) continue;
            $result['objects']++;
            $result['files'] += $files;
            if ($dryRun) {
                $result['entries'] += $entries;
                continue;
            }
            $result['entries'] += $this->deleteObject($objectType, $objectId);
            $this->updateVerifyResult($objectType, $objectId, ['ok' => true, 'errors' => []]);
        }
        if (!$dryRun && $result['objects'] > 0) {
            $this->container->get(System::class)->log('security', "audit delete {$objectType}"
                . ($scope !== '' ? " ({$scope})" : '') . ": {$result['objects']} objects, {$result['entries']} entries, {$result['files']} files");
        }
        return $result;
    }

    /**
     * Datensätze, deren Verlauf zu einem Bereich gehört: die im Bereich liegen
     * (auch im Papierkorb) oder laut Audit-Log dort lagen - auch endgültig gelöschte.
     *
     * @return int[]
     */
    public function recordIdsOfArea(int $areaId): array
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT DISTINCT `object_id` FROM `audit_log` WHERE `object_type` = 'record' AND `area_id` = ?"
                . " UNION SELECT `record_id` FROM `record` WHERE `area_id` = ? AND `tmp` = 0", 'ii', [$areaId, $areaId]);
            $ids = array_map('intval', $this->db->fetchColumn());
        }
        finally {
            $this->db->popResult();
        }
        sort($ids);
        return $ids;
    }

    /**
     * Felder im Verlauf eines Objekts überschreiben (Aktion audit_set_fields einer
     * Lösch-Policy) - Datei (AuditStore::rewriteObject(), neuer Kontrollpunkt) und
     * Bezeichnung in der Datenbank.
     *
     * @param array $fields    Feld => Wert, dazu _label, * (DeletePolicy)
     * @param array $vars      Platzhalter: record_id, user_id
     * @return int  geänderte Einträge der Datei
     */
    public function overwriteObject(string $objectType, int $objectId, array $fields, array $vars): int
    {
        $changed = $this->store->rewriteObject($objectType, $objectId,
            fn(array $body) => self::applyOverride($body, $fields, $vars));
        if (array_key_exists('_label', $fields)) {
            $this->db->pushResult();
            try {
                $this->db->query("UPDATE `audit_log` SET `label` = ? WHERE `object_type` = ? AND `object_id` = ?",
                    'ssi', [mb_substr(self::renderValue((string)$fields['_label'], $vars), 0, 255), $objectType, $objectId]);
            }
            finally {
                $this->db->popResult();
            }
        }
        return $changed;
    }

    /**
     * Jeden Eintrag im Verlauf eines Objekts durch eine Funktion schicken (Aktion
     * audit_trigger einer Lösch-Policy: onPrivacyAction() des Trigger-Templates) und
     * Geändertes zurückschreiben - Datei (AuditStore::rewriteObject(), neuer
     * Kontrollpunkt) und Bezeichnung/Name in der Datenbank (audit_log.label,
     * actor_name je Eintrag).
     *
     * @param callable $transform  function(array $entry): array - Eintrag wie in der
     *                             Datei: id, t, action, actor {type, name, user_id …},
     *                             label, changes [{k, l, t, g, b, a}, …]
     * @return int  geänderte Einträge
     */
    public function transformObject(string $objectType, int $objectId, callable $transform): int
    {
        $rows = [];
        $changed = $this->store->rewriteObject($objectType, $objectId, function (array $body) use ($transform, &$rows): array {
            $new = $transform($body);
            if (!is_array($new)) return $body;
            // Kennung, Zeitpunkt und Objekt bleiben - sonst wäre der Eintrag ein anderer
            foreach (['id', 't', 'type', 'oid'] as $key) {
                if (array_key_exists($key, $body)) $new[$key] = $body[$key];
            }
            if ((int)($body['id'] ?? 0) > 0 && (($new['label'] ?? null) !== ($body['label'] ?? null)
                || ($new['actor']['name'] ?? null) !== ($body['actor']['name'] ?? null))) {
                $rows[(int)$body['id']] = [(string)($new['label'] ?? ''), (string)($new['actor']['name'] ?? '')];
            }
            return $new;
        });
        if ($rows) {
            $this->db->pushResult();
            try {
                foreach ($rows as $auditId => [$label, $actorName]) {
                    $this->db->query("UPDATE `audit_log` SET `label` = ?, `actor_name` = ? WHERE `audit_id` = ? AND `object_type` = ? AND `object_id` = ?",
                        'ssisi', [mb_substr($label, 0, 255), mb_substr($actorName, 0, 128), $auditId, $objectType, $objectId]);
                }
            }
            finally {
                $this->db->popResult();
            }
        }
        return $changed;
    }

    /**
     * Einen Eintrag (label, changes) nach den Feldern einer Lösch-Policy umschreiben:
     * Feld => Wert ersetzt Vorher und Nachher dieses Felds, * alle übrigen, _label
     * die Bezeichnung. Leere Werte bleiben leer (nichts verraten, was nicht da war).
     * Wer gehandelt hat, steht nur als Id im Eintrag und bleibt.
     */
    public static function applyOverride(array $entry, array $fields, array $vars): array
    {
        $render = fn(string $v) => self::renderValue($v, $vars);
        if (array_key_exists('_label', $fields)) $entry['label'] = $render((string)$fields['_label']);
        foreach ((array)($entry['changes'] ?? []) as $i => $change) {
            $key = (string)($change['k'] ?? '');
            // mehrsprachige Felder stehen als feld@de_DE im Eintrag - feld meint alle Sprachen
            $base = str_contains($key, '@') ? strstr($key, '@', true) : $key;
            if (array_key_exists($key, $fields)) $value = $render((string)$fields[$key]);
            elseif (array_key_exists($base, $fields)) $value = $render((string)$fields[$base]);
            elseif (array_key_exists('*', $fields)) $value = $render((string)$fields['*']);
            else continue;
            foreach (['b', 'a'] as $side) {
                if ((string)($change[$side] ?? '') !== '') $entry['changes'][$i][$side] = $value;
            }
        }
        return $entry;
    }

    /** {record_id}, {user_id} in einem Wert einer Lösch-Policy ersetzen */
    public static function renderValue(string $value, array $vars): string
    {
        return strtr($value, ['{record_id}' => (string)(int)($vars['record_id'] ?? 0), '{user_id}' => (string)(int)($vars['user_id'] ?? 0)]);
    }

    /**
     * Schon ausgeführte Überschreibungen des Audit-Logs eines Datensatzes (Modul
     * Privacy) - [fields, vars] oder null. Je Request zwischengespeichert.
     */
    private function policyOverride(int $recordId): ?array
    {
        if (!array_key_exists($recordId, $this->policyOverrides)) {
            $class = \Camindo\Core\Modules\Privacy\PrivacyJobs::class;
            $this->policyOverrides[$recordId] = class_exists($class) ? $this->container->get($class)->auditOverride($recordId) : null;
        }
        return $this->policyOverrides[$recordId];
    }

    /** Zwischenspeicher für policyOverride() */
    private array $policyOverrides = [];

    /** Nach einer Aktion audit_set_fields: policyOverride() neu lesen */
    public function forgetPolicyOverride(int $recordId): void
    {
        unset($this->policyOverrides[$recordId]);
    }

    // ==== Aufbewahrung ====

    /**
     * Abgelaufene Einträge löschen (siehe Klassenkommentar).
     *
     * @return array  ['deleted' => n, 'changes' => n] gelöschte Zeilen je Regel
     */
    public function purge(): array
    {
        $result = ['deleted' => 0, 'changes' => 0, 'logins' => 0];
        $deletedDays = (int)($this->config->get('audit.retention_days_deleted') ?? 365);
        $changesDays = (int)($this->config->get('audit.retention_days_changes') ?? 0);
        $loginDays = $this->loginRetentionDays();

        $this->db->pushResult();
        try {
            if ($loginDays > 0) {
                // An- und Abmeldungen, Fehlversuche: eigene, kürzere Frist
                $cutoff = gmdate('Y-m-d H:i:s', time() - $loginDays * 86400);
                $actions = "'" . implode("','", self::LOGIN_ACTIONS) . "'";
                $this->db->query("SELECT DISTINCT `object_id` FROM `audit_log` WHERE `object_type` = 'admin'"
                    . " AND `action` IN ($actions) AND `created_at` < ?", 's', [$cutoff]);
                $ids = array_map('intval', $this->db->fetchColumn());
                foreach ($ids as $adminId) {
                    // mitten aus dem Verlauf des Kontos - trim() nähme nur den Anfang
                    $this->store->rewriteObject('admin', $adminId, fn(array $b) => in_array((string)($b['action'] ?? ''), self::LOGIN_ACTIONS, true)
                        && (string)$b['t'] < $cutoff ? null : $b, 'retention');
                }
                $this->db->query("DELETE FROM `audit_log` WHERE `object_type` = 'admin' AND `action` IN ($actions) AND `created_at` < ?", 's', [$cutoff]);
                $result['logins'] = max(0, $this->db->affected_rows);
            }
            if ($deletedDays > 0) {
                // Objekte, deren endgültiges Löschen länger zurückliegt - außer
                // Datensätzen: deren Verlauf regelt die Lösch-Policy ihres Bereichs
                // (Modul Privacy, Aktion audit_delete)
                $this->db->query(
                    "SELECT DISTINCT `object_type`, `object_id` FROM `audit_log`"
                    . " WHERE `action` = 'purge' AND `object_type` <> 'record' AND `created_at` < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)",
                    'i', [$deletedDays]
                );
                $objects = [];
                while ($row = $this->db->fetch()) $objects[] = $row;
                foreach ($objects as $object) {
                    // Wieder angelegt (Import mit derselben Id)? Dann nur bis zum Löschen
                    $this->db->query(
                        "SELECT MAX(`audit_id`) FROM `audit_log` WHERE `object_type` = ? AND `object_id` = ? AND `action` = 'purge'",
                        'si', [$object['object_type'], (int)$object['object_id']]
                    );
                    $maxId = (int)($this->db->fetchNum()[0] ?? 0);
                    $this->store->trim((string)$object['object_type'], (int)$object['object_id'], fn(array $b) => (int)$b['id'] <= $maxId);
                    $this->db->query(
                        "DELETE FROM `audit_log` WHERE `object_type` = ? AND `object_id` = ? AND `audit_id` <= ("
                        . "SELECT `max_id` FROM (SELECT MAX(`audit_id`) AS `max_id` FROM `audit_log`"
                        . " WHERE `object_type` = ? AND `object_id` = ? AND `action` = 'purge') AS `p`)",
                        'sisi', [$object['object_type'], (int)$object['object_id'], $object['object_type'], (int)$object['object_id']]
                    );
                    $result['deleted'] += max(0, $this->db->affected_rows);
                }
            }
            if ($changesDays > 0) {
                $cutoff = gmdate('Y-m-d H:i:s', time() - $changesDays * 86400);
                $this->db->query("SELECT DISTINCT `object_type`, `object_id` FROM `audit_log` WHERE `created_at` < ?", 's', [$cutoff]);
                $objects = [];
                while ($row = $this->db->fetch()) $objects[] = $row;
                foreach ($objects as $object) {
                    $this->store->trim((string)$object['object_type'], (int)$object['object_id'], fn(array $b) => (string)$b['t'] < $cutoff);
                }
                $this->db->query("DELETE FROM `audit_log` WHERE `created_at` < ?", 's', [$cutoff]);
                $result['changes'] = max(0, $this->db->affected_rows);
            }
        }
        finally {
            $this->db->popResult();
        }
        return $result;
    }

    // ==== Hilfen für die Schnappschüsse (Dataset / Record) ====

    /**
     * Schnappschuss-Eintrag eines verschlüsselten Werts: Geheimtext als base64 zum
     * späteren Entschlüsseln, verglichen wird über den HMAC des Klartexts.
     *
     * @param string $cipher  Geheimtext, binär
     */
    public function encryptedEntry(string $label, string $cipher, string $lang = ''): array
    {
        $plain = $cipher === '' ? '' : $this->crypt->decrypt($cipher, '', true);
        return [
            'l' => $label, 't' => 'encrypted', 'g' => $lang,
            'v' => $cipher === '' ? '' : base64_encode($cipher),
            'c' => $plain === '' ? '' : bin2hex($this->crypt->hash($plain, true)),
        ];
    }

    /**
     * Gespeicherten verschlüsselten Wert (base64) für die Anzeige entschlüsseln.
     */
    public function decryptValue(string $value): string
    {
        if ($value === '') return '';
        $cipher = base64_decode($value, true);
        if ($cipher === false) return '';
        try {
            return $this->crypt->decrypt($cipher, '', true);
        }
        catch (\Throwable) {
            return '';
        }
    }

    // ====================

    /**
     * Wer handelt gerade? Admin (Session, auch CLI mit setCliAdmin()), angemeldeter
     * Frontend-User oder das System (Cron, CLI).
     *
     * @return array  type, admin_id, user_id, name, ip, origin
     */
    private function actor(): array
    {
        $origin = $this->context !== '' ? $this->context
            : (PHP_SAPI === 'cli' ? 'cli' : (defined('CAMINDO_BACKEND') ? 'backend' : 'frontend'));
        // Über ClientIp: hinter einem Proxy die IP des Besuchers, nicht die des Proxys
        $ip = PHP_SAPI === 'cli' ? '' : mb_substr($this->container->get(ClientIp::class)->ip(), 0, 45);
        $actor = ['type' => 'system', 'admin_id' => null, 'user_id' => null, 'name' => '', 'ip' => $ip, 'origin' => $origin];

        // Benutzer und Website-User nur als Id (siehe Klassenkommentar)
        $adminId = (int)$this->session->get('admin_id');
        if ($adminId > 0) {
            $actor['type'] = 'admin';
            $actor['admin_id'] = $adminId;
            return $actor;
        }
        if (PHP_SAPI === 'cli') return $actor;
        // Web-Request ohne Admin: angemeldeter Frontend-User oder Besucher (auch die
        // fehlgeschlagene Anmeldung am Backend)
        $actor['type'] = 'visitor';
        if (!defined('CAMINDO_BACKEND')) {
            $userSession = $this->container->get(UserSession::class);
            if ($userSession->isLoggedIn()) {
                $actor['type'] = 'user';
                $actor['user_id'] = $userSession->recordId();
            }
        }
        return $actor;
    }

    private function adminName(int $adminId): string
    {
        if (!isset($this->adminNames[$adminId])) {
            $this->db->pushResult();
            try {
                $this->db->query("SELECT `name` FROM `admin` WHERE `admin_id` = ?", 'i', [$adminId]);
                $this->adminNames[$adminId] = (string)($this->db->fetchNum()[0] ?? '');
            }
            finally {
                $this->db->popResult();
            }
        }
        return $this->adminNames[$adminId];
    }

    private function limit(string $value): string
    {
        if (mb_strlen($value) <= self::MAX_VALUE_LENGTH) return $value;
        return mb_substr($value, 0, self::MAX_VALUE_LENGTH) . self::TRUNCATED;
    }

    /**
     * WHERE-Klausel der Recherche.
     *
     * @return array  [sql, types, values]
     */
    private function where(array $filter): array
    {
        $parts = [];
        $types = '';
        $values = [];
        $add = function (string $sql, string $type, mixed $value) use (&$parts, &$types, &$values) {
            $parts[] = $sql;
            $types .= $type;
            $values[] = $value;
        };
        if (!empty($filter['audit_id'])) $add('`audit_id` = ?', 'i', (int)$filter['audit_id']);
        if (!empty($filter['object_type'])) $add('`object_type` = ?', 's', (string)$filter['object_type']);
        if (!empty($filter['object_id'])) $add('`object_id` = ?', 'i', (int)$filter['object_id']);
        if (!empty($filter['area_id'])) $add('`area_id` = ?', 'i', (int)$filter['area_id']);
        if (!empty($filter['origin'])) $add('`origin` = ?', 's', (string)$filter['origin']);
        // Einschränkungen (Rechte): Listen, leer = keine Treffer
        if (isset($filter['object_types'])) {
            $list = array_values(array_filter(array_map('strval', (array)$filter['object_types']), fn($t) => preg_match('/^[a-z_]{1,32}$/', $t)));
            $parts[] = $list ? "`object_type` IN ('" . implode("','", $list) . "')" : '0';
        }
        if (isset($filter['area_ids'])) {
            $list = array_values(array_filter(array_map('intval', (array)$filter['area_ids']), fn($id) => $id > 0));
            $parts[] = $list ? '`area_id` IN (' . implode(',', $list) . ')' : '0';
        }
        if (!empty($filter['admin_id'])) $add('`admin_id` = ?', 'i', (int)$filter['admin_id']);
        if (!empty($filter['action']) && in_array($filter['action'], self::ACTIONS, true)) {
            $add('`action` = ?', 's', (string)$filter['action']);
        }
        if (!empty($filter['from'])) $add('`created_at` >= ?', 's', (string)$filter['from']);
        if (!empty($filter['to'])) $add('`created_at` < ?', 's', (string)$filter['to']);
        if (($search = trim((string)($filter['search'] ?? ''))) !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            // Handelnde stehen nur als Id - ihr Name aus `admin`
            $parts[] = '(`label` LIKE ? OR `actor_name` LIKE ? OR `ip` LIKE ?'
                . ' OR `admin_id` IN (SELECT `admin_id` FROM `admin` WHERE `name` LIKE ?))';
            $types .= 'ssss';
            array_push($values, $like, $like, $like, $like);
        }
        if (($field = trim((string)($filter['field'] ?? ''))) !== '') {
            $add('`fields` LIKE ?', 's', '%' . addcslashes(json_encode($field, JSON_UNESCAPED_UNICODE) ?: '', '%_\\') . '%');
        }
        return [$parts ? ' WHERE ' . implode(' AND ', $parts) : '', $types, $values];
    }
}
