<?php
/**
 * Signaturen über sicherheitsrelevante Zeilen der Datenbank.
 *
 * WOGEGEN
 * --------------------
 * Gegen jemanden, der in die Datenbank schreiben kann, aber nicht auf den Server
 * (kein Zugriff auf .camindo.key): eine offene phpMyAdmin-Instanz, ein geleaktes
 * Datenbank-Passwort, eine SQL-Injection in fremdem Code auf demselben Server. Ohne
 * Signaturen genügt dort ein UPDATE, um sich zum Superuser zu machen:
 *
 *     admin.group_id       auf 1 setzen
 *     admin.name/password  mit einer anderen Zeile tauschen
 *     admin.status_id      ein gesperrtes Konto wieder öffnen
 *     admin_session        eine Session für einen fremden Admin einfügen
 *     rel_group_*          der eigenen Gruppe Rechte geben
 *     group.details        der eigenen Gruppe Module freischalten
 *
 * WIE
 * --------------------
 * Jede geschützte Zeile trägt in `sig` einen HMAC (Crypt::hash(), Schlüssel aus
 * .camindo.key) über ihre entscheidenden Spalten. Wer die Datei nicht hat, kann keine
 * gültige Signatur erzeugen; eine Zeile ohne oder mit falscher Signatur wird behandelt,
 * als gäbe es sie nicht. Verifiziert wird dort, wo die Zeile Rechte verleiht:
 *
 *     admin          Anmeldung (Admin::loginByName/ByCode) und jeder Request
 *                    (Session::load): Name, Passwort-Hash, Gruppe, Status und der
 *                    zweite Faktor (second_factor - sonst ließe sich TOTP per
 *                    UPDATE auf die schwächere E-Mail zurückstellen)
 *     admin_session  jeder Request (Session::load): Session-Id, admin_id
 *     group          beim Bau der Gruppendateien (InstanceCache::readGroups): details
 *     rel_group_*    ebenda, je Zeile: Gruppe, Ziel, Flags
 *
 * Die Signatur der Gruppenrechte ist je ZEILE, nicht je Gruppe: Bereiche, Instanzen
 * und Sprachen verschwinden per ON DELETE CASCADE aus den Relation-Tabellen, ohne
 * dass eine Gruppe gespeichert wird. Eine Signatur über die ganze Rechtemenge wäre
 * danach ungültig. Zeilen löschen kann der Angreifer so zwar - das nimmt aber nur
 * Rechte, es gibt keine.
 *
 * Nicht signiert ist `admin`.`deleted` (Dataset::markDeleted() läuft ohne Hooks):
 * ein wiederbelebtes Konto nützt ohne sein Passwort nichts.
 *
 * WER SIGNIERT
 * --------------------
 * Jeder Schreibpfad, den der Core kennt - Admin::hookOnSaved(), Group::hookOnSaved(),
 * Group::grantInstance()/saveAreaRights(), Area::saveAccess()/grantGroups()/
 * copyAccess(), Session::create(). Neue Schreibpfade müssen die Signatur mitschreiben,
 * sonst gelten ihre Zeilen als manipuliert. Ein direktes UPDATE per SQL auf eine
 * signierte Spalte macht die Zeile ungültig - absichtlich.
 *
 * Ein Fehlschlag landet im Log 'security' (System::log()).
 *
 * @see Camindo\Core\Crypt   hash()
 * @see docs 16-sicherheit.md
 */
namespace Camindo\Core;

class Integrity {

    public function __construct(
        private Database $db,
        private Crypt $crypt,
        private System $system,
    )
    {
    }

    // ====================
    // admin

    /**
     * Signatur einer Admin-Zeile.
     *
     * second_factor geht nur ein, wenn es gesetzt ist: Zeilen ohne eigenen Faktor
     * behalten damit die Signatur, die sie vor dessen Einführung hatten.
     *
     * @param array $row  admin_id, name, password (Hash), group_id, status_id, second_factor
     */
    public function adminSignature(array $row): string
    {
        $parts = [
            'admin',
            (int)($row['admin_id'] ?? 0),
            (string)($row['name'] ?? ''),
            (string)($row['password'] ?? ''),
            (int)($row['group_id'] ?? 0),
            (int)($row['status_id'] ?? 0),
        ];
        $factor = (string)($row['second_factor'] ?? '');
        if ($factor !== '') $parts[] = $factor;
        return $this->crypt->hash(implode('|', $parts));
    }

    /**
     * Admin-Zeile (neu) signieren - nach jedem Speichern (Admin::hookOnSaved()).
     */
    public function signAdmin(int $adminId): void
    {
        if ($adminId < 1) return;
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT `admin_id`, `name`, `password`, `group_id`, `status_id`, `second_factor` FROM `admin` WHERE `admin_id` = ?",
                'i', [$adminId]
            );
            $row = $this->db->num_rows > 0 ? $this->db->fetch() : null;
            if ($row === null) return;
            $this->db->query(
                "UPDATE `admin` SET `sig` = ? WHERE `admin_id` = ?",
                'si', [$this->adminSignature($row), $adminId]
            );
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Admin-Zeile lesen und ihre Signatur prüfen.
     *
     * @return array|null  admin_id, name, group_id, status_id, second_factor, deleted - oder null,
     *                     wenn es die Zeile nicht gibt oder die Signatur nicht stimmt
     */
    public function verifyAdmin(int $adminId): ?array
    {
        if ($adminId < 1) return null;
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT `admin_id`, `name`, `password`, `group_id`, `status_id`, `second_factor`, `deleted`, `sig` FROM `admin` WHERE `admin_id` = ?",
                'i', [$adminId]
            );
            $row = $this->db->num_rows > 0 ? $this->db->fetch() : null;
        }
        finally {
            $this->db->popResult();
        }
        if ($row === null) return null;
        if (!hash_equals($this->adminSignature($row), (string)$row['sig'])) {
            $this->system->log('security', "admin {$adminId} ({$row['name']}): signature mismatch - row ignored");
            return null;
        }
        unset($row['password'], $row['sig']);
        $row['admin_id']  = (int)$row['admin_id'];
        $row['group_id']  = (int)$row['group_id'];
        $row['status_id'] = (int)$row['status_id'];
        $row['deleted']   = (int)$row['deleted'];
        return $row;
    }

    // ====================
    // admin_session

    /**
     * Signatur einer Session-Zeile: Id, Admin und Oberfläche (backend, cli, php) -
     * eine per SQL umgestellte Oberfläche macht die Session ungültig.
     */
    public function sessionSignature(string $sessionId, int $adminId, string $interface = 'backend'): string
    {
        return $this->crypt->hash('session|' . $sessionId . '|' . $adminId . '|' . $interface);
    }

    public function verifySession(string $sessionId, int $adminId, string $sig, string $interface = 'backend'): bool
    {
        if (hash_equals($this->sessionSignature($sessionId, $adminId, $interface), $sig)) return true;
        $this->system->log('security', "admin_session for admin {$adminId}: signature mismatch - session ignored");
        return false;
    }

    /**
     * CSRF-Token einer Session (17-csrf.md §2.4).
     *
     * Abgeleitet, nicht gespeichert: derselbe HMAC-Schlüssel wie bei den Signaturen
     * (Crypt::hash(), .camindo.key). Wer die Session-Id nicht kennt, kann das Token
     * nicht erzeugen - und wer sie kennt, hat ohnehin die Session. Das Cookie ist
     * HttpOnly, das Token steht dafür im DOM (cConfig): gegen XSS schützt es nicht,
     * gegen fremde Origins schon (die kommen nicht an cConfig).
     *
     * Bewusst mit eigenem Präfix gegen sessionSignature() abgegrenzt - dieselbe
     * Eingabe darf nicht zwei Bedeutungen haben.
     */
    public function csrfToken(string $sessionId): string
    {
        if ($sessionId === '') return '';
        return $this->crypt->hash('csrf|' . $sessionId);
    }

    public function verifyCsrf(string $sessionId, string $token): bool
    {
        if ($sessionId === '' || $token === '') return false;
        return hash_equals($this->csrfToken($sessionId), $token);
    }

    // ====================
    // group

    /**
     * @param string $details  `group`.`details` wie gespeichert (JSON)
     */
    public function groupSignature(int $groupId, string $details): string
    {
        return $this->crypt->hash('group|' . $groupId . '|' . $details);
    }

    /**
     * Gruppen-Zeile (neu) signieren - nach jedem Speichern (Group::hookOnSaved()).
     */
    public function signGroup(int $groupId): void
    {
        if ($groupId < 1) return;
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `details` FROM `group` WHERE `group_id` = ?", 'i', [$groupId]);
            $row = $this->db->num_rows > 0 ? $this->db->fetch() : null;
            if ($row === null) return;
            $this->db->query(
                "UPDATE `group` SET `sig` = ? WHERE `group_id` = ?",
                'si', [$this->groupSignature($groupId, (string)$row['details']), $groupId]
            );
        }
        finally {
            $this->db->popResult();
        }
    }

    public function verifyGroup(int $groupId, string $details, string $sig): bool
    {
        if (hash_equals($this->groupSignature($groupId, $details), $sig)) return true;
        $this->system->log('security', "group {$groupId}: signature mismatch - rights ignored");
        return false;
    }

    // ====================
    // Alles neu signieren

    /**
     * Alle signierten Zeilen neu signieren - `camindo.php admin sign`.
     *
     * Für den Wechsel von .camindo.key und für Entwicklungsdaten (dev.sql), die mit
     * einem anderen Schlüssel signiert wurden. Signiert wird, was in der Datenbank
     * steht - der Aufruf ist ein Vertrauensakt dessen, der die Kommandozeile hat, und
     * gehört nicht in einen Web-Request. Sessions werden gelöscht (neue Anmeldung),
     * nicht neu signiert.
     *
     * @return array  Tabelle => Anzahl signierter Zeilen
     */
    public function signAll(): array
    {
        $counts = [];
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `admin_id` FROM `admin`");
            $ids = array_map('intval', $this->db->fetchColumn());
            foreach ($ids as $id) $this->signAdmin($id);
            $counts['admin'] = count($ids);

            // Superuser-Gruppe ohne Signatur: ihre Rechte stehen nicht in der Datenbank
            // (InstanceCache::buildGroups()), und seed.sql bleibt schlüsselunabhängig
            $this->db->query("SELECT `group_id` FROM `group` WHERE `group_id` <> ?", 'i', [InstanceCache::SUPERUSER_GROUP_ID]);
            $ids = array_map('intval', $this->db->fetchColumn());
            foreach ($ids as $id) $this->signGroup($id);
            $counts['group'] = count($ids);

            $this->db->query("SELECT * FROM `rel_group_area`");
            $rows = $this->db->fetchAll();
            foreach ($rows as $row) {
                $this->db->query(
                    "UPDATE `rel_group_area` SET `sig` = ? WHERE `group_id` = ? AND `area_id` = ?",
                    'sii', [$this->areaRightSignature((int)$row['group_id'], (int)$row['area_id'], $row), (int)$row['group_id'], (int)$row['area_id']]
                );
            }
            $counts['rel_group_area'] = count($rows);

            $this->db->query("SELECT `group_id`, `instance_id` FROM `rel_group_instance`");
            $rows = $this->db->fetchAll();
            foreach ($rows as $row) {
                $this->db->query(
                    "UPDATE `rel_group_instance` SET `sig` = ? WHERE `group_id` = ? AND `instance_id` = ?",
                    'sii', [$this->instanceRightSignature((int)$row['group_id'], (int)$row['instance_id']), (int)$row['group_id'], (int)$row['instance_id']]
                );
            }
            $counts['rel_group_instance'] = count($rows);

            $this->db->query("SELECT `group_id`, `language_id`, `can_write` FROM `rel_group_language`");
            $rows = $this->db->fetchAll();
            foreach ($rows as $row) {
                $this->db->query(
                    "UPDATE `rel_group_language` SET `sig` = ? WHERE `group_id` = ? AND `language_id` = ?",
                    'sii', [$this->languageRightSignature((int)$row['group_id'], (int)$row['language_id'], !empty($row['can_write'])), (int)$row['group_id'], (int)$row['language_id']]
                );
            }
            $counts['rel_group_language'] = count($rows);

            $this->db->query("SELECT `group_id`, `status_id` FROM `rel_group_status`");
            $rows = $this->db->fetchAll();
            foreach ($rows as $row) {
                $this->db->query(
                    "UPDATE `rel_group_status` SET `sig` = ? WHERE `group_id` = ? AND `status_id` = ?",
                    'sii', [$this->statusRightSignature((int)$row['group_id'], (int)$row['status_id']), (int)$row['group_id'], (int)$row['status_id']]
                );
            }
            $counts['rel_group_status'] = count($rows);

            $this->db->query("SELECT `passkey_id`, `admin_id`, `credential_hash`, `public_key`, `alg` FROM `admin_passkey`");
            $rows = $this->db->fetchAll();
            foreach ($rows as $row) {
                $this->db->query(
                    "UPDATE `admin_passkey` SET `sig` = ? WHERE `passkey_id` = ?",
                    'si', [$this->passkeySignature($row), (int)$row['passkey_id']]
                );
            }
            $counts['admin_passkey'] = count($rows);

            $this->db->query("DELETE FROM `admin_session`");
        }
        finally {
            $this->db->popResult();
        }
        return $counts;
    }

    // ====================
    // admin_passkey (developer_docs/22-webauthn.md)

    /**
     * Signatur einer Passkey-Zeile: Konto, Credential (Hash der Id), öffentlicher
     * Schlüssel und Verfahren. Wer die Datenbank schreibt, kann so keinen eigenen
     * Schlüssel für ein fremdes Konto hinterlegen. Der Signaturzähler steht nicht
     * darin - er ändert sich bei jeder Anmeldung.
     *
     * @param array $row  admin_id, credential_hash (binär), public_key, alg
     */
    public function passkeySignature(array $row): string
    {
        return $this->crypt->hash(implode('|', [
            'passkey',
            (int)($row['admin_id'] ?? 0),
            bin2hex((string)($row['credential_hash'] ?? '')),
            hash('sha256', (string)($row['public_key'] ?? '')),
            (int)($row['alg'] ?? 0),
        ]));
    }

    /** Passt die gespeicherte Signatur einer Passkey-Zeile? */
    public function verifyPasskey(array $row): bool
    {
        $sig = (string)($row['sig'] ?? '');
        return $sig !== '' && hash_equals($this->passkeySignature($row), $sig);
    }

    // ====================
    // rel_group_area, rel_group_instance, rel_group_language, rel_group_status

    /**
     * Signatur einer Zeile aus rel_group_area: Gruppe, Bereich und die fünf Flags.
     *
     * @param array $flags  group_write, group_delete, other_write, other_delete,
     *                      set_owner (0|1; fehlende Schlüssel = Spalten-Default)
     */
    public function areaRightSignature(int $groupId, int $areaId, array $flags): string
    {
        return $this->crypt->hash(implode('|', [
            'rel_group_area', $groupId, $areaId,
            (int)!empty($flags['group_write'] ?? 1),
            (int)!empty($flags['group_delete'] ?? 1),
            (int)!empty($flags['other_write'] ?? 1),
            (int)!empty($flags['other_delete'] ?? 1),
            (int)!empty($flags['set_owner'] ?? 0),
        ]));
    }

    public function instanceRightSignature(int $groupId, int $instanceId): string
    {
        return $this->crypt->hash('rel_group_instance|' . $groupId . '|' . $instanceId);
    }

    public function languageRightSignature(int $groupId, int $languageId, bool $canWrite): string
    {
        return $this->crypt->hash('rel_group_language|' . $groupId . '|' . $languageId . '|' . (int)$canWrite);
    }

    /** Zeile in rel_group_status: die Gruppe darf diesen Status setzen */
    public function statusRightSignature(int $groupId, int $statusId): string
    {
        return $this->crypt->hash('rel_group_status|' . $groupId . '|' . $statusId);
    }

    /**
     * Zeile einer Relation-Tabelle prüfen (InstanceCache::readGroups()).
     *
     * @param string $table  rel_group_area | rel_group_instance | rel_group_language |
     *                       rel_group_status
     * @param array  $row    die Zeile wie gelesen (mit `sig`)
     */
    public function verifyRight(string $table, array $row): bool
    {
        $groupId = (int)($row['group_id'] ?? 0);
        $expected = match ($table) {
            'rel_group_area'     => $this->areaRightSignature($groupId, (int)($row['area_id'] ?? 0), $row),
            'rel_group_instance' => $this->instanceRightSignature($groupId, (int)($row['instance_id'] ?? 0)),
            'rel_group_language' => $this->languageRightSignature($groupId, (int)($row['language_id'] ?? 0), !empty($row['can_write'])),
            'rel_group_status'   => $this->statusRightSignature($groupId, (int)($row['status_id'] ?? 0)),
            default => '',
        };
        if ($expected !== '' && hash_equals($expected, (string)($row['sig'] ?? ''))) return true;
        $this->system->log('security', "{$table} group {$groupId}: signature mismatch - row ignored");
        return false;
    }
}
