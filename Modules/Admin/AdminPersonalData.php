<?php
/**
 * Datenexport personenbezogener Daten eines Benutzers (Admin-Konto) - das Gegenstück
 * zu RecordPersonalData für Website-User. Erstellt im Modul Privacy
 * (PrivacyBackend::export(), Zusatzrecht export) von jemandem, der die Benutzer der
 * Gruppe sehen darf. Ein Teilauszug: für eine Auskunft nach Art. 15 DSGVO von Hand
 * zu ergänzen (Rechtsgrundlagen, Server-Logfiles, andere Systeme …).
 * Instanzname im Code: $adminPersonalData.
 *
 * INHALT DES ZIPS
 * --------------------
 *     auskunft.html   für Menschen: eine eigenständige Seite (druckbar) aus der
 *                     Vorlage des Betreibers (PrivacyTemplate, Tabelle
 *                     privacy_template) in der Sprache der Vorlage
 *     daten.json      dieselben Daten maschinenlesbar (Datenübertragbarkeit)
 *
 * WAS DAZUGEHÖRT
 * --------------------
 *     Konto           Anmeldename, Namen, E-Mail, Mobil, Telefon (im Klartext),
 *                     Gruppe, Status, letzte Anmeldung, Passwort nur "gesetzt am"
 *                     (gespeichert wird nur der Tag), persönliche Einstellungen
 *     Anmeldung       bekannte Geräte (login_device), Sitzungen (admin_session),
 *                     zweiter Faktor: Art, Datum, Passkeys mit Bezeichnung, Zahl der
 *                     Wiederherstellungscodes - nie Schlüssel, Codes oder Hashes
 *     Aktivitäten     was der Benutzer im Audit-Log getan hat (neueste MAX_ROWS, mit
 *                     IP-Adresse - es ist seine), dazu Änderungen an seinem Konto
 *     Anmeldungen     An-/Abmeldungen (nur mit audit.log_logins) und Fehlversuche
 *                     unter seinem Namen aus dem Audit-Log, je mit dem Tag der
 *                     Löschung (audit.retention_days_logins)
 *     Nachrichten     interne Nachrichten an ihn: Datum, Absender, Betreff
 *                     (gelöscht nach privacy.message_days)
 *     Inhalte         Zahl der Datensätze, die er angelegt, zuletzt geändert hat oder
 *                     besitzt (die Inhalte selbst gehören dem Betreiber)
 *     Speicherdauer   nach den Einstellungen dieser Installation
 *
 * Nicht enthalten: Zeilen der Logdateien unter var/log und die Zähltabelle der
 * Fehlversuche (admin_login_attempt) - sie lassen sich keinem Konto sicher zuordnen.
 * Gekürzte IP-Adressen erscheinen mit "xxx" für die entfernten Stellen.
 *
 * Das Zip liegt in var/export unter personal-data-admin-{id}-{zufall}.zip, wird nach
 * dem Herunterladen gelöscht (PrivacyBackend::download()) und
 * sonst nach admin.export_retention_hours vom Cron (RecordExport::cleanup()). Jede
 * Auskunft steht im Audit-Log (Aktion 'export' am Admin-Konto).
 *
 * @see Camindo\Core\Modules\Record\RecordPersonalData   Auskunft für Website-User
 */
namespace Camindo\Core\Modules\Admin;

use Camindo\Core\AuditDiff;
use Camindo\Core\AuditLog;
use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Database;
use Camindo\Core\PrivacyTemplate;
use Camindo\Core\System;

class AdminPersonalData {

    /** Obergrenze je Liste (Aktivitäten, Nachrichten) */
    public const MAX_ROWS = 1000;

    /** Präfix der Zip-Datei; der Download prüft ihn gegen das Konto */
    public const PREFIX = 'personal-data-admin-';

    public function __construct(
        private Config $config,
        private Database $db,
        private Cms $cms,
        private System $system,
        private Admin $admin,
        private Passkeys $passkeys,
        private TotpFactor $totpFactor,
        private AuditLog $auditLog,
        private AuditDiff $auditDiff,
        private Backend $backend,
        private PrivacyTemplate $privacyTemplate,
    )
    {}

    /**
     * Auskunft erstellen.
     *
     * @return array  ['zip' => Dateiname in var/export, 'size' => Bytes]
     * @throws \RuntimeException  kein Konto, Zip nicht schreibbar
     */
    public function export(int $adminId, string $template, int $instanceId): array
    {
        $data = $this->collect($adminId);
        $data += $this->privacyTemplate->instanceValues($instanceId);

        $varDir = (string)$this->config->get('paths.var_dir');
        $dir = rtrim($varDir, '/') . '/export';
        if (!is_dir($dir) && !$this->system->createDir($varDir, 'export')) {
            throw new \RuntimeException('var/export not writable');
        }
        $zipName = self::PREFIX . $adminId . '-' . bin2hex(random_bytes(12)) . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($dir . '/' . $zipName, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            throw new \RuntimeException('could not create ' . $zipName);
        }
        $zip->addFromString('daten.json', (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        $zip->addFromString('auskunft.html', $this->html($template, $data));
        $zip->close();

        $this->auditLog->write('export', 'admin', $adminId, (string)$data['account']['name']);

        return ['zip' => $zipName, 'size' => (int)filesize($dir . '/' . $zipName)];
    }

    /** Gehört die Zip-Datei $file zum Konto $adminId? (Download) */
    public static function ownsFile(int $adminId, string $file): bool
    {
        return (bool)preg_match('/^' . preg_quote(self::PREFIX . $adminId . '-', '/') . '[0-9a-f]{24}\.zip$/', $file);
    }

    /**
     * Alle Daten zum Konto zusammentragen (Aufbau siehe daten.json).
     *
     * @throws \RuntimeException  kein Konto
     */
    public function collect(int $adminId): array
    {
        $account = $this->admin->load($adminId);
        if (!$account) throw new \RuntimeException('admin not found');

        $this->db->query("SELECT `label` FROM `group` WHERE `group_id` = ?", 'i', [(int)$account['group_id']]);
        $group = (string)($this->db->fetchNum()[0] ?? '');
        $this->db->query("SELECT `login_date`, `password_changed_at`, `totp_enabled_at`, `second_factor` FROM `admin` WHERE `admin_id` = ?", 'i', [$adminId]);
        $raw = $this->db->fetch() ?? [];

        $activityTotal = $this->count("SELECT COUNT(*) FROM `audit_log` WHERE `actor_type` = 'admin' AND `admin_id` = ?", $adminId);

        return [
            'created_at' => gmdate('Y-m-d H:i:s') . ' UTC',
            'account' => [
                'admin_id' => $adminId,
                'name' => (string)$account['name'],
                'primename' => (string)($account['primename'] ?? ''),
                'displayname' => (string)($account['displayname'] ?? ''),
                'sortname' => (string)($account['sortname'] ?? ''),
                'email' => (string)($account['email'] ?? ''),
                'mobile' => (string)($account['mobile'] ?? ''),
                'phone' => (string)($account['phone'] ?? ''),
                'group' => $this->cms->textOrSnippet($group),
                'status_id' => (int)($account['status_id'] ?? 0),
                'login_date' => $this->date((string)($raw['login_date'] ?? '')),
                'password_changed_at' => $this->date((string)($raw['password_changed_at'] ?? '')),
                'settings' => (array)($account['settings'] ?? []),
            ],
            'devices' => $this->rows(
                "SELECT `label`, `ip`, `created_at`, `last_seen_at` FROM `login_device` WHERE `admin_id` = ? ORDER BY `last_seen_at` DESC",
                $adminId, fn(array $row) => ['ip' => $this->privacyTemplate->displayIp((string)$row['ip'])] + $row
            ),
            'sessions' => $this->rows(
                "SELECT `interface`, `access_date`, `expires_at` FROM `admin_session` WHERE `admin_id` = ? ORDER BY `access_date` DESC",
                $adminId
            ),
            'second_factor' => [
                'method' => (string)($raw['second_factor'] ?? '') !== '' ? (string)$raw['second_factor'] : ($this->totpFactor->isActive($adminId) ? 'totp' : ''),
                'totp_enabled_at' => $this->date((string)($raw['totp_enabled_at'] ?? '')),
                'passkeys' => $this->rows(
                    "SELECT `label`, `created_at`, `last_used_at` FROM `admin_passkey` WHERE `admin_id` = ? ORDER BY `created_at`",
                    $adminId
                ),
                'recovery_codes' => $this->count("SELECT COUNT(*) FROM `admin_recovery_code` WHERE `admin_id` = ?", $adminId),
            ],
            'activity_total' => $activityTotal,
            'activity' => $this->rows(
                "SELECT `created_at`, `action`, `object_type`, `object_id`, `label`, `origin`, `ip`, `fields` FROM `audit_log`"
                . " WHERE `actor_type` = 'admin' AND `admin_id` = ? AND `action` NOT IN ('login', 'logout', 'login_failed')"
                . " ORDER BY `audit_id` DESC LIMIT " . self::MAX_ROWS,
                $adminId, fn(array $row) => ['fields' => $this->fieldNames((string)$row['fields']), 'ip' => $this->privacyTemplate->displayIp((string)$row['ip'])] + $row
            ),
            'logins' => $this->logins($adminId, (string)$account['name']),
            'account_changes' => $this->rows(
                "SELECT `created_at`, `action`, `actor_type`, `admin_id`, `user_id`, `actor_name`, `fields` FROM `audit_log`"
                . " WHERE `object_type` = 'admin' AND `object_id` = ? AND `action` NOT IN ('login', 'logout', 'login_failed')"
                . " ORDER BY `audit_id` DESC LIMIT " . self::MAX_ROWS,
                $adminId, fn(array $row) => [
                    'created_at' => substr((string)$row['created_at'], 0, 19),
                    'action' => (string)$row['action'],
                    'by' => (int)$row['admin_id'] === $adminId && (string)$row['actor_type'] === 'admin'
                        ? $this->cms->snippet('record.gdpr_by_you') : $this->auditDiff->actorLabel($row),
                    'fields' => $this->fieldNames((string)$row['fields']),
                ]
            ),
            'messages' => $this->rows(
                "SELECT m.`created_at`, a.`name` AS `sender`, m.`subject` FROM `message` m"
                . " LEFT JOIN `admin` a ON a.`admin_id` = m.`created_by`"
                . " WHERE m.`to_admin_id` = ? AND m.`recipient_deleted` = 0 ORDER BY m.`created_at` DESC LIMIT " . self::MAX_ROWS,
                $adminId
            ),
            'contents' => [
                'created' => $this->count("SELECT COUNT(*) FROM `record` WHERE `created_by` = ?", $adminId),
                'changed' => $this->count("SELECT COUNT(*) FROM `record` WHERE `changed_by` = ?", $adminId),
                'owned' => $this->count("SELECT COUNT(*) FROM `record` WHERE `owner_id` = ?", $adminId),
                'history' => $this->count("SELECT COUNT(*) FROM `history` WHERE `admin_id` = ?", $adminId),
            ],
            'retention' => $this->retention(),
        ];
    }

    // ====================

    /** Zeilen einer Abfrage mit einem Platzhalter, optional umgeformt */
    private function rows(string $sql, int $id, ?callable $map = null): array
    {
        $this->db->query($sql, 'i', [$id]);
        $rows = [];
        while ($row = $this->db->fetch()) $rows[] = $map ? $map($row) : $row;
        return $rows;
    }

    private function count(string $sql, int $id): int
    {
        $this->db->query($sql, 'i', [$id]);
        return (int)($this->db->fetchNum()[0] ?? 0);
    }

    /**
     * An-/Abmeldungen des Kontos und Fehlversuche unter seinem Namen (Audit-Log), je
     * mit dem Tag, an dem sie gelöscht werden.
     */
    private function logins(int $adminId, string $name): array
    {
        $days = $this->auditLog->loginRetentionDays() ?: (int)($this->config->get('audit.retention_days_changes') ?? 0);
        $this->db->query(
            "SELECT `created_at`, `action`, `origin`, `ip` FROM `audit_log` WHERE `object_type` = 'admin'"
            // Fehlversuche stehen unter der admin_id des Kontos (AuditLog::login()),
            // ältere noch unter Objekt 0 mit dem eingegebenen Namen
            . " AND (`object_id` = ? OR (`object_id` = 0 AND `action` = 'login_failed' AND `label` = ?))"
            . " AND `action` IN ('login', 'logout', 'login_failed')"
            . " ORDER BY `audit_id` DESC LIMIT " . self::MAX_ROWS,
            'is', [$adminId, $name]
        );
        $rows = [];
        while ($row = $this->db->fetch()) {
            $rows[] = [
                'created_at' => substr((string)$row['created_at'], 0, 19),
                'action' => $this->auditDiff->actionLabel((string)$row['action']),
                'origin' => trim((string)$row['origin'] . ((string)$row['ip'] !== '' ? ', ' . $this->privacyTemplate->displayIp((string)$row['ip']) : ''), ', '),
                'deletion' => $days > 0 ? gmdate('Y-m-d', strtotime(substr((string)$row['created_at'], 0, 19) . ' UTC') + $days * 86400) : '',
            ];
        }
        return $rows;
    }

    /** Feldnamen aus audit_log.fields (JSON ohne Werte) */
    private function fieldNames(string $json): string
    {
        $names = [];
        foreach ((array)(json_decode($json, true) ?: []) as $field) {
            $names[] = is_array($field) ? (string)(($field['l'] ?? '') !== '' ? $field['l'] : ($field['k'] ?? '')) : (string)$field;
        }
        return implode(', ', array_filter($names));
    }

    /** Datum aus der Datenbank (UTC), '' für "nie" */
    private function date(string $date): string
    {
        return $date === '' || str_starts_with($date, '0000') ? '' : $date;
    }

    /**
     * Speicherdauer - Einstellungen dieser Installation, als Sätze.
     *
     * @return string[]
     */
    private function retention(): array
    {
        ['values' => $v, 'flags' => $f] = $this->privacyTemplate->retention();
        $lines = [$this->cms->snippet('admin.gdpr_retention_account')];
        if ($this->auditLog->enabled()) {
            $lines[] = $f['audit_changes_limited']
                ? strtr($this->cms->snippet('record.gdpr_retention_audit_changes'), ['{days}' => (string)$v['audit_changes_days']])
                : $this->cms->snippet('record.gdpr_retention_audit_changes_unlimited');
        }
        $lines[] = strtr($this->cms->snippet('record.gdpr_retention_devices'), ['{days}' => (string)$v['device_days']]);
        $lines[] = strtr($this->cms->snippet('record.gdpr_retention_ip'), ['{days}' => (string)$v['ip_days']]);
        $lines[] = strtr($this->cms->snippet('admin.gdpr_retention_sessions'), ['{minutes}' => (string)$v['session_minutes']]);
        if ($f['messages_limited']) {
            $lines[] = strtr($this->cms->snippet('admin.gdpr_retention_messages'), ['{days}' => (string)$v['message_days']]);
        }
        // Logdateien: Aufgabe "Logs aufräumen" des Zeitplaners
        $lines[] = $f['logcleanup']
            ? strtr($this->cms->snippet('record.gdpr_retention_logs'), [
                '{days}' => (string)$v['log_days'],
                '{security_days}' => (string)$v['security_days'],
                '{attempt_days}' => (string)$v['attempt_days'],
            ])
            : $this->cms->snippet('record.gdpr_retention_logs_unlimited');
        return $lines;
    }

    // ==== HTML ====

    /**
     * Die lesbare Auskunft (auskunft.html) aus der Vorlage des Betreibers
     * für Benutzer (PrivacyTemplate); Tabellen und Listen aus den Vorlagen
     * der Profil-Auskunft (record.gdpr_*). Alle Werte über Backend::escape().
     */
    private function html(string $template, array $data): string
    {
        $e = fn(string $text) => $this->backend->escape($text);
        $a = $data['account'];
        $s = fn(string $key) => $this->cms->snippet($key);

        $account = [
            $s('admin.field_admin_name') => $a['name'],
            $s('admin.field_admin_primename') => $a['primename'],
            $s('admin.field_admin_displayname') => $a['displayname'],
            $s('admin.field_admin_sortname') => $a['sortname'],
            $s('admin.field_admin_email') => $a['email'],
            $s('admin.field_admin_mobile') => $a['mobile'],
            $s('admin.field_admin_phone') => $a['phone'],
            $s('admin.gdpr_group') => $a['group'],
            $s('admin.field_admin_login_date') . ' (UTC)' => $a['login_date'],
            $s('admin.gdpr_password') => $a['password_changed_at'] !== ''
                ? strtr($s('admin.gdpr_password_set'), ['{date}' => substr($a['password_changed_at'], 0, 10)])
                : $s('record.gdpr_password_stored'),
            $s('admin.field_admin_settings') => (string)json_encode($a['settings'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        $rows = '';
        foreach ($account as $label => $value) {
            $rows .= $this->backend->template('record.gdpr_field_row', [
                '{label}' => $e((string)$label),
                '{value}' => (string)$value === '' ? $this->backend->template('record.gdpr_empty_value') : $e((string)$value),
            ]);
        }

        $factor = $data['second_factor'];
        $factorRows = [];
        if ($factor['method'] !== '') {
            $factorRows[] = ['method' => $factor['method'], 'created_at' => $factor['totp_enabled_at']];
        }
        foreach ($factor['passkeys'] as $passkey) {
            $factorRows[] = ['method' => 'passkey: ' . $passkey['label'], 'created_at' => $passkey['created_at']];
        }
        if ($factor['recovery_codes'] > 0) {
            $factorRows[] = ['method' => strtr($s('admin.gdpr_recovery_codes'), ['{count}' => (string)$factor['recovery_codes']]), 'created_at' => ''];
        }

        $activity = array_map(fn($r) => [
            'time' => substr((string)$r['created_at'], 0, 19), 'action' => $r['action'],
            'object' => $r['label'] . ' (' . $r['object_type'] . ' #' . $r['object_id'] . ')',
            'origin' => trim($r['origin'] . ((string)$r['ip'] !== '' ? ', ' . $r['ip'] : ''), ', '),
            'fields' => $r['fields'],
        ], $data['activity']);
        $c = $data['contents'];

        $retention = $this->privacyTemplate->retention();
        $v = $retention['values'];
        $note = fn(string $text) => $this->backend->template('record.gdpr_note', ['{text}' => $e($text)]);
        return $this->privacyTemplate->render(
            $template,
            $v + [
                'subject' => (string)$a['name'],
                'subject_id' => (string)(int)$a['admin_id'],
                'domain' => (string)($data['domain'] ?? ''),
                'instance' => (string)($data['instance'] ?? ''),
                'created' => (string)$data['created_at'],
                'activity_note' => strtr($s('admin.gdpr_activity_note'), [
                    '{shown}' => (string)count($data['activity']), '{total}' => (string)$data['activity_total']]),
                'content_counts' => strtr($s('admin.gdpr_contents_text'), [
                    '{created}' => (string)$c['created'], '{changed}' => (string)$c['changed'],
                    '{owned}' => (string)$c['owned'], '{history}' => (string)$c['history']]),
            ],
            [
                'account' => $this->backend->template('record.gdpr_record_table', ['{rows}' => $rows]),
                // Fristen direkt unter Geräten und Sitzungen
                'devices' => $this->table($data['devices'], [
                    'label' => 'record.gdpr_col_device', 'ip' => 'record.gdpr_col_ip', 'created_at' => 'record.gdpr_col_first', 'last_seen_at' => 'record.gdpr_col_last'])
                    . $note(strtr($s('record.gdpr_retention_devices'), ['{days}' => (string)$v['device_days']])),
                'sessions' => $this->table($data['sessions'], [
                    'interface' => 'admin.gdpr_col_interface', 'access_date' => 'record.gdpr_col_last', 'expires_at' => 'admin.gdpr_col_expires'])
                    . $note(strtr($s('admin.gdpr_retention_sessions'), ['{minutes}' => (string)$v['session_minutes']])),
                'logins' => $this->table($data['logins'], ['created_at' => 'record.gdpr_col_time', 'action' => 'record.gdpr_col_action',
                    'origin' => 'admin.gdpr_col_origin', 'deletion' => 'record.gdpr_col_deletion'])
                    . $note($this->auditLog->logLogins() ? $s('admin.gdpr_note_logins') : $s('admin.gdpr_note_logins_off')),
                'second_factor' => $this->table($factorRows, ['method' => 'record.gdpr_col_method', 'created_at' => 'record.gdpr_col_first']),
                'activity' => $this->table($activity, ['time' => 'record.gdpr_col_time', 'action' => 'record.gdpr_col_action',
                    'object' => 'record.gdpr_col_object', 'origin' => 'admin.gdpr_col_origin', 'fields' => 'record.gdpr_col_fields']),
                'account_changes' => $this->table($data['account_changes'], ['created_at' => 'record.gdpr_col_time',
                    'action' => 'record.gdpr_col_action', 'by' => 'record.gdpr_col_by', 'fields' => 'record.gdpr_col_fields']),
                'messages' => $this->table($data['messages'], ['created_at' => 'record.gdpr_col_time',
                    'sender' => 'admin.gdpr_col_sender', 'subject' => 'admin.gdpr_col_subject']),
                'retention' => $this->list($data['retention']),
            ],
            $retention['flags']
        );
    }

    /**
     * Tabelle aus Zeilen, ohne Zeilen: "keine".
     *
     * @param array $columns  Spalte in $rows => Snippet der Überschrift
     */
    private function table(array $rows, array $columns): string
    {
        if (!$rows) return $this->backend->template('record.gdpr_none');
        $head = '';
        foreach ($columns as $key) {
            $head .= $this->backend->template('record.gdpr_table_head', ['{text}' => $this->backend->escape($this->cms->snippet($key))]);
        }
        $body = '';
        foreach ($rows as $row) {
            $cells = '';
            foreach (array_keys($columns) as $col) {
                $cells .= $this->backend->template('record.gdpr_table_cell', ['{text}' => $this->backend->escape((string)($row[$col] ?? ''))]);
            }
            $body .= $this->backend->template('record.gdpr_table_row', ['{cells}' => $cells]);
        }
        return $this->backend->template('record.gdpr_table', ['{head}' => $head, '{rows}' => $body]);
    }

    /** Aufzählung, ohne Einträge: "keine" */
    private function list(array $lines): string
    {
        if (!$lines) return $this->backend->template('record.gdpr_none');
        $items = '';
        foreach ($lines as $line) {
            $items .= $this->backend->template('record.gdpr_list_item', ['{text}' => $this->backend->escape((string)$line)]);
        }
        return $this->backend->template('record.gdpr_list', ['{items}' => $items]);
    }
}
