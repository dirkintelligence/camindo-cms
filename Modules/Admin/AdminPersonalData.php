<?php
/**
 * Personal data export for a user (admin account) - the counterpart
 * to RecordPersonalData for website users. Created in module Privacy
 * (PrivacyBackend::export(), additional right export) by someone who may see the users of the
 * group. A partial extract: for a data access request under Art. 15 GDPR it must be
 * supplemented by hand (legal bases, server log files, other systems …).
 * Instance name in code: $adminPersonalData.
 *
 * CONTENTS OF THE ZIP
 * --------------------
 *     report.html     for people: a standalone page (printable) from the
 *                     operator's template (PrivacyTemplate, table
 *                     privacy_template) in the language of the template
 *     data.json       the same data in machine-readable form (data portability)
 *
 * WHAT IS INCLUDED
 * --------------------
 *     Account         login name, names, email, mobile, phone (in plain text),
 *                     group, status, last login, password only "set on" date
 *                     (only the day is stored), personal settings
 *     Login           known devices (login_device), sessions (admin_session),
 *                     second factor: kind, date, passkeys with label, number of
 *                     recovery codes - never keys, codes or hashes
 *     Activities      what the user did in the audit log (latest MAX_ROWS, with
 *                     IP address - it is their own), plus changes to their account
 *     Logins          logins/logouts (only with audit.log_logins) and failed attempts
 *                     under their name from the audit log, each with the date of
 *                     deletion (audit.retention_days_logins)
 *     Messages        internal messages to them: date, sender, subject
 *                     (deleted after privacy.message_days)
 *     Contents        number of records they created, last changed or
 *                     own (the contents themselves belong to the operator)
 *     Retention       according to the settings of this installation
 *
 * Not included: lines of the log files under var/log and the counter table of
 * failed attempts (admin_login_attempt) - they cannot be reliably assigned to an account.
 * Shortened IP addresses show "xxx" for the removed parts.
 *
 * The zip is in var/export under personal-data-admin-{id}-{random}.zip, is deleted after
 * downloading (PrivacyBackend::download()) and otherwise after
 * admin.export_retention_hours by the cron (RecordExport::cleanup()). Every
 * extract is recorded in the audit log (action 'export' on the admin account).
 *
 * @see Camindo\Core\Modules\Record\RecordPersonalData   Extract for website users
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

    /** Upper limit per list (activities, messages) */
    public const MAX_ROWS = 1000;

    /** Prefix of the zip file; the download checks it against the account */
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
     * Create the extract.
     *
     * @return array  ['zip' => file name in var/export, 'size' => bytes]
     * @throws \RuntimeException  no account, zip not writable
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
        $zip->addFromString('data.json', (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        $zip->addFromString('report.html', $this->html($template, $data));
        $zip->close();

        $this->auditLog->write('export', 'admin', $adminId, (string)$data['account']['name']);

        return ['zip' => $zipName, 'size' => (int)filesize($dir . '/' . $zipName)];
    }

    /** Does the zip file $file belong to the account $adminId? (download) */
    public static function ownsFile(int $adminId, string $file): bool
    {
        return (bool)preg_match('/^' . preg_quote(self::PREFIX . $adminId . '-', '/') . '[0-9a-f]{24}\.zip$/', $file);
    }

    /**
     * Collect all data for the account (structure see data.json).
     *
     * @throws \RuntimeException  no account
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

    /** Rows of a query with one placeholder, optionally reshaped */
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
     * Logins/logouts of the account and failed attempts under its name (audit log), each
     * with the date on which they are deleted.
     */
    private function logins(int $adminId, string $name): array
    {
        $days = $this->auditLog->loginRetentionDays() ?: (int)($this->config->get('audit.retention_days_changes') ?? 0);
        $this->db->query(
            "SELECT `created_at`, `action`, `origin`, `ip` FROM `audit_log` WHERE `object_type` = 'admin'"
            // Failed attempts are stored under the admin_id of the account (AuditLog::login()),
            // older ones still under object 0 with the entered name
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

    /** Field names from audit_log.fields (JSON without values) */
    private function fieldNames(string $json): string
    {
        $names = [];
        foreach ((array)(json_decode($json, true) ?: []) as $field) {
            $names[] = is_array($field) ? (string)(($field['l'] ?? '') !== '' ? $field['l'] : ($field['k'] ?? '')) : (string)$field;
        }
        return implode(', ', array_filter($names));
    }

    /** Date from the database (UTC), '' for "never" */
    private function date(string $date): string
    {
        return $date === '' || str_starts_with($date, '0000') ? '' : $date;
    }

    /**
     * Retention periods - settings of this installation, as sentences.
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
        // Log files: task "Clean up logs" of the scheduler
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
     * The readable extract (report.html) from the operator's template
     * for users (PrivacyTemplate); tables and lists from the templates
     * of the profile extract (record.gdpr_*). All values via Backend::escape().
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
                // Retention periods directly below devices and sessions
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
     * Table from rows; without rows: "none".
     *
     * @param array $columns  column in $rows => snippet of the heading
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

    /** List; without entries: "none" */
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
