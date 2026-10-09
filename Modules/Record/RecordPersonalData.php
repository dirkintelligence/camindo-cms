<?php
/**
 * Personal data export of a website user: what the CMS has stored for a
 * profile record, as a zip. Created in the module Privacy
 * (PrivacyBackend::export(), additional right export). A partial extract: to be
 * completed by hand for a disclosure under Art. 15/20 GDPR
 * (legal bases, server log files, accounting …). Name in the code: $personalData.
 *
 * CONTENTS OF THE ZIP
 * --------------------
 *     auskunft.html   for people: a self-contained page (printable) from the
 *                     template of the operator (PrivacyTemplate, table
 *                     privacy_template) in the language of the template
 *     daten.json      the same data in machine-readable form (data portability)
 *     dateien/        the uploaded files under their original name
 *                     ({record_id}_{field}[_{language}]_{name})
 *
 * WHAT IS INCLUDED
 * --------------------
 *     profile             all fields of the profile record, all languages;
 *                         encrypted fields in plain text; passwords only as
 *                         "stored (not readable)"
 *     user contents       records with record.user_id = profile (comments,
 *                         ratings … via CMS:USER_INPUT), also in the trash,
 *                         grouped by area
 *     deleted contents    permanently deleted records that the audit log still
 *                         links to the user (entries with his user_id), together with
 *                         what the deletion policy still plans for them
 *     references          records that point to the profile via a link field
 *                         (e.g. orders), at most MAX_RECORDS
 *     sign-in             known devices (login_device), sessions (user_session),
 *                         second factor (user_second_factor: type and date - never
 *                         keys or codes),
 *                         open one-time links (user_token), shopping basket (user_basket)
 *     log                 audit log entries for these records and for
 *                         actions of the user. Who acted is only recorded there as
 *                         "You", "Admin", "User" or "System" - names
 *                         of other persons do not belong in the disclosure
 *     failed attempts     failed sign-ins and codes (user_login_attempt
 *                         under "{area_id}:{field value}" or "{area_id}:#{record_id}"),
 *                         locks in the security log with this name, lines of the
 *                         mail log with an e-mail address of the profile (e.g. undeliverable
 *                         mails)
 *     retention periods   trash (admin.trash_retention_days), audit log
 *                         (audit.*), devices, shortening of IP addresses
 *                         (privacy.ip_anonymize_days), sessions, log files
 *                         (scheduler task "Clean up logs")
 *
 * Not included, because they cannot be reliably assigned to the profile: the other lines of the
 * log files under var/log - the retention section names their period.
 * Shortened IP addresses appear with "xxx" for the removed parts.
 *
 * The zip lies in var/export under a name that cannot be guessed, is deleted after
 * downloading (PrivacyBackend::download()) and otherwise after
 * admin.export_retention_hours by the cron. Every disclosure is recorded in the audit log
 * (action 'export').
 *
 * @see Camindo\Core\Modules\Privacy\PrivacyBackend::export()   user interface
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\AuditDiff;
use Camindo\Core\AuditLog;
use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Database;
use Camindo\Core\InstanceCache;
use Camindo\Core\PrivacyTemplate;
use Camindo\Core\System;

class RecordPersonalData {

    /** Upper limit per list (user contents, references, log) */
    public const MAX_RECORDS = 500;

    /** date placeholders "no date" / "no end" (as Cms::_formatDate()) */
    private const DATE_MIN_DISPLAY = '1000-01-02 00:00:00';
    private const DATE_MAX_DISPLAY = '8999-12-31 00:00:00';

    /** meta fields that appear in the disclosure */
    private const META_FIELDS = ['record_id', 'created_at', 'changed_at', 'online_at', 'offline_at', 'status_id'];

    public function __construct(
        private Config $config,
        private Database $db,
        private Cms $cms,
        private System $system,
        private InstanceCache $instanceCache,
        private Record $record,
        private AuditLog $auditLog,
        private AuditDiff $auditDiff,
        private Backend $backend,
        private PrivacyTemplate $privacyTemplate,
    )
    {}

    /**
     * Create the disclosure.
     *
     * @param int    $profileId  record_id of the profile (area of type 'profile')
     * @param string $template   template of the disclosure (privacy_template.content); the
     *                           caller sets the snippet language accordingly
     * @return array             ['zip' => file name in var/export, 'size' => bytes]
     * @throws \RuntimeException  no profile, zip not writable
     */
    public function export(int $profileId, string $template): array
    {
        $data = $this->collect($profileId);

        $dir = rtrim((string)$this->config->get('paths.var_dir'), '/') . '/export';
        if (!is_dir($dir) && !$this->system->createDir((string)$this->config->get('paths.var_dir'), 'export')) {
            throw new \RuntimeException('var/export not writable');
        }
        $zipName = 'personal-data-' . $profileId . '-' . bin2hex(random_bytes(12)) . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($dir . '/' . $zipName, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            throw new \RuntimeException('could not create ' . $zipName);
        }
        $files = [];
        foreach ($data['files'] as $file) {
            if (is_file($file['path'])) {
                $zip->addFile($file['path'], 'dateien/' . $file['name']);
                $files[] = $file['name'];
            }
        }
        $json = $data;
        unset($json['files']);
        $json['files'] = $files;
        $zip->addFromString('daten.json', (string)json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        $zip->addFromString('auskunft.html', $this->html($template, $data, $files));
        $zip->close();

        $this->auditLog->write('export', 'record', $profileId, (string)$data['profile']['label'], [],
            (int)$data['profile']['area_id'], (int)$data['profile']['instance_id']);

        return ['zip' => $zipName, 'size' => (int)filesize($dir . '/' . $zipName)];
    }

    /**
     * Collect all data on the profile (structure see daten.json).
     *
     * @throws \RuntimeException  no profile record
     */
    public function collect(int $profileId): array
    {
        $profile = $this->recordData($profileId);
        if ($profile === null || $this->areaType($profile['area_id']) !== 'profile') {
            throw new \RuntimeException('not a profile record');
        }
        $instance = $this->instanceCache->instance($profile['instance_id']) ?? [];

        // user contents
        $this->db->query(
            "SELECT `record_id` FROM `record` WHERE `user_id` = ? AND `tmp` = 0 ORDER BY `record_id` LIMIT " . self::MAX_RECORDS,
            'i', [$profileId]
        );
        $contentIds = array_map('intval', $this->db->fetchColumn());
        // records that point to the profile
        $this->db->query(
            "SELECT DISTINCT r.`src_id` FROM `data_rel` r JOIN `record` s ON s.`record_id` = r.`src_id`"
            . " WHERE r.`dest_id` = ? AND s.`tmp` = 0 ORDER BY r.`src_id` LIMIT " . self::MAX_RECORDS,
            'i', [$profileId]
        );
        $refIds = array_values(array_diff(array_map('intval', $this->db->fetchColumn()), $contentIds));

        $contents = [];
        foreach ($contentIds as $id) {
            if ($r = $this->recordData($id)) $contents[] = $r;
        }
        $references = [];
        foreach ($refIds as $id) {
            if ($r = $this->recordData($id)) $references[] = $r;
        }
        $guardNames = $this->guardNames($profile);
        $emails = $this->emails($profile);

        // files: profile and own contents (references belong to others)
        $files = [];
        foreach (array_merge([$profile], $contents) as $r) {
            foreach ($r['file_rows'] as $f) $files[] = $f;
        }
        $strip = fn(array $r) => array_diff_key($r, ['file_rows' => 1]);

        return [
            'created_at' => gmdate('Y-m-d H:i:s') . ' UTC',
            'domain' => (string)($instance['primary_domain'] ?? ''),
            'instance' => $this->cms->textOrSnippet((string)($instance['label'] ?? '')),
            'profile' => $strip($profile),
            'contents' => array_map($strip, $contents),
            'purged' => $this->purgedContents($profileId),
            'references' => array_map($strip, $references),
            'devices' => array_map(fn($row) => ['ip' => $this->privacyTemplate->displayIp((string)$row['ip'])] + $row, $this->rows(
                "SELECT `label`, `ip`, `created_at`, `last_seen_at` FROM `login_device` WHERE `record_id` = ? ORDER BY `last_seen_at` DESC",
                $profileId
            )),
            'sessions' => $this->rows(
                "SELECT `created_at`, `access_date` FROM `user_session` WHERE `record_id` = ? ORDER BY `access_date` DESC",
                $profileId
            ),
            'second_factor' => $this->rows(
                "SELECT `method`, `enabled_at` AS `created_at` FROM `user_second_factor` WHERE `record_id` = ?",
                $profileId
            ),
            'tokens' => $this->rows(
                "SELECT `purpose`, `created_at` FROM `user_token` WHERE `record_id` = ? ORDER BY `created_at` DESC",
                $profileId
            ),
            'basket' => $this->rows(
                "SELECT b.`record_id`, b.`quantity`, b.`changed_at` FROM `user_basket` b"
                . " JOIN `user_session` s ON s.`session_id` = b.`session_id` WHERE s.`record_id` = ? ORDER BY b.`changed_at` DESC",
                $profileId
            ),
            'audit' => $this->auditEntries($profileId, array_merge([$profileId], $contentIds)),
            'login_attempts' => $this->loginAttempts($guardNames),
            'log_lines' => $this->logLines($guardNames, $emails),
            'retention' => $this->retention(),
            'files' => $files,
        ];
    }

    // ====================

    /**
     * One record for the disclosure: area, label, meta fields, fields.
     *
     * @return array|null  record_id, area_id, area, instance_id, label, deleted,
     *                     meta [label => value], fields [list of field, label,
     *                     language, value], file_rows (internal, for the zip)
     */
    private function recordData(int $id): ?array
    {
        $this->db->query("SELECT * FROM `record` WHERE `record_id` = ? AND `tmp` = 0", 'i', [$id]);
        $row = $this->db->fetch();
        if (!$row) return null;
        $areaId = (int)$row['area_id'];
        $snapshot = $this->record->auditSnapshot($id);

        $meta = [];
        foreach (self::META_FIELDS as $name) {
            $label = $name === 'record_id' ? 'Id' : $this->cms->textOrSnippet((string)($this->cms->schema[$name]['label'] ?? $name));
            $value = (string)($row[$name] ?? '');
            if (in_array($name, ['created_at', 'changed_at', 'online_at', 'offline_at'], true)) {
                $value = $value !== '' && $value >= self::DATE_MIN_DISPLAY && $value < self::DATE_MAX_DISPLAY
                    ? substr($value, 0, 19) . ' UTC' : '';
            }
            $meta[$label] = $value;
        }

        $fields = [];
        foreach ($snapshot as $key => $entry) {
            $name = explode('@', (string)$key)[0];
            if (isset($this->cms->schema[$name])) continue;   // meta fields: see above
            $type = (string)($entry['t'] ?? 'text');
            $value = (string)($entry['v'] ?? '');
            if ($type === 'password') {
                $value = (string)($entry['c'] ?? '') !== '' ? $this->cms->snippet('record.gdpr_password_stored') : '';
            }
            elseif ($type === 'encrypted') {
                $value = $this->auditLog->decryptValue($value);
            }
            $fields[] = [
                'field' => $name,
                'label' => $this->cms->textOrSnippet((string)($entry['l'] ?? $name)),
                'language' => (string)($entry['g'] ?? ''),
                'type' => $type,
                'value' => $value,
            ];
        }

        // files (only real files, see VirtualFieldsTrait::recordFileRows())
        $fileRows = [];
        $this->db->query(
            "SELECT DISTINCT d.`field_id`, d.`extension`, d.`filename`, d.`file_language_id`, f.`name`"
            . " FROM `data_fil` d JOIN `field` f ON f.`field_id` = d.`field_id`"
            . " WHERE d.`record_id` = ? AND (d.`file_language_id` IS NULL OR d.`file_language_id` = d.`language_id`)"
            . " AND d.`extension` <> ''",
            'i', [$id]
        );
        foreach ($this->db->fetchAll() as $f) {
            $fileLanguageId = $this->cms->fileLanguageId($f);
            $relative = $this->cms->mediaPath((int)$f['field_id'], $id, $fileLanguageId, (string)$f['extension']);
            $path = rtrim((string)$this->config->get('paths.media_dir'), '/') . '/' . $relative;
            if (!empty($row['deleted'])) {
                // files in the trash are stored under var/deleted
                continue;
            }
            $original = preg_replace('/[^\p{L}\p{N}._-]+/u', '_', (string)$f['filename']) ?: ('file.' . $f['extension']);
            $fileRows[] = [
                'path' => $path,
                'name' => $id . '_' . preg_replace('/[^a-z0-9_]/i', '', (string)$f['name'])
                    . ($fileLanguageId !== null ? '_' . $fileLanguageId : '') . '_' . $original,
            ];
        }

        return [
            'record_id' => $id,
            'area_id' => $areaId,
            'area' => $this->areaLabel($areaId),
            'instance_id' => (int)$row['instance_id'],
            'label' => $this->recordLabel($snapshot, $id),
            'deleted' => !empty($row['deleted']),
            'meta' => $meta,
            'fields' => $fields,
            'file_rows' => $fileRows,
        ];
    }

    /**
     * Log entries: on the records and actions of the user.
     */
    private function auditEntries(int $profileId, array $recordIds): array
    {
        $ids = implode(',', array_map('intval', $recordIds));
        $this->db->query(
            "SELECT `created_at`, `action`, `object_type`, `object_id`, `label`, `actor_type`, `admin_id`, `user_id`, `actor_name`, `fields`"
            . " FROM `audit_log` WHERE (`object_type` = 'record' AND `object_id` IN ($ids)) OR `user_id` = ?"
            . " ORDER BY `audit_id` DESC LIMIT " . self::MAX_RECORDS,
            'i', [$profileId]
        );
        $entries = [];
        while ($row = $this->db->fetch()) {
            $fields = [];
            // changed fields without values (meta line; the values are stored in var/audit)
            foreach ((array)json_decode((string)$row['fields'], true) as $change) {
                $fields[] = $this->cms->textOrSnippet((string)($change['l'] ?? $change['k'] ?? ''))
                    . ((string)($change['g'] ?? '') !== '' ? ' (' . $change['g'] . ')' : '');
            }
            $entries[] = [
                'time' => substr((string)$row['created_at'], 0, 19) . ' UTC',
                'action' => $this->auditDiff->actionLabel((string)$row['action']),
                'record_id' => (int)$row['object_id'],
                'label' => (string)$row['label'],
                'by' => $this->actorText($row, $profileId),
                'fields' => array_values(array_unique($fields)),
            ];
        }
        return $entries;
    }

    /**
     * Retention periods - settings of this installation, as sentences (placeholder
     * {retention} of the template; the individual periods also exist there as their own
     * placeholders, PrivacyTemplate::retention()).
     *
     * @return string[]
     */
    private function retention(): array
    {
        ['values' => $v, 'flags' => $f] = $this->privacyTemplate->retention();
        $lines = [
            strtr($this->cms->snippet('record.gdpr_retention_trash'), ['{days}' => (string)$v['trash_days']]),
        ];
        // What happens to the change log after deletion is stated by the
        // deletion policy of the area (placeholder {delete_policy})
        if ($this->auditLog->enabled()) {
            $lines[] = $f['audit_changes_limited']
                ? strtr($this->cms->snippet('record.gdpr_retention_audit_changes'), ['{days}' => (string)$v['audit_changes_days']])
                : $this->cms->snippet('record.gdpr_retention_audit_changes_unlimited');
        }
        $lines[] = strtr($this->cms->snippet('record.gdpr_retention_devices'), ['{days}' => (string)$v['device_days']]);
        $lines[] = strtr($this->cms->snippet('record.gdpr_retention_ip'), ['{days}' => (string)$v['ip_days']]);
        $lines[] = strtr($this->cms->snippet('record.gdpr_retention_sessions'), ['{hours}' => (string)$v['session_hours']]);
        // log files: scheduler task "Clean up logs"
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
     * The readable disclosure (auskunft.html) from the operator's template
     * for website users (PrivacyTemplate, table privacy_template) in their
     * language. The blocks are built from the templates record.gdpr_*; all values
     * via Backend::escape().
     */
    private function html(string $template, array $data, array $files): string
    {
        $profile = $data['profile'];

        $audit = [];
        foreach ($data['audit'] as $entry) {
            $audit[] = ['time' => $entry['time'], 'action' => $entry['action'], 'object' => $entry['label'] . ' (#' . $entry['record_id'] . ')',
                'by' => $entry['by'], 'fields' => implode(', ', $entry['fields'])];
        }

        $retention = $this->privacyTemplate->retention();
        $v = $retention['values'];
        return $this->privacyTemplate->render(
            $template,
            $v + [
                'subject' => (string)$profile['label'],
                'subject_id' => (string)(int)$profile['record_id'],
                'domain' => (string)$data['domain'],
                'instance' => (string)$data['instance'],
                'created' => (string)$data['created_at'],
            ],
            [
                'profile' => $this->recordHtml($profile),
                'contents' => $this->recordsHtml($data['contents']),
                'purged' => $this->purgedHtml($data['purged']),
                'references' => $this->recordsHtml($data['references']),
                // periods directly below devices and sessions
                'devices' => $this->tableHtml($data['devices'], [
                    'label' => 'gdpr_col_device', 'ip' => 'gdpr_col_ip', 'created_at' => 'gdpr_col_first', 'last_seen_at' => 'gdpr_col_last'])
                    . $this->noteHtml(strtr($this->cms->snippet('record.gdpr_retention_devices'), ['{days}' => (string)$v['device_days']])),
                'sessions' => $this->tableHtml($data['sessions'], [
                    'created_at' => 'gdpr_col_first', 'access_date' => 'gdpr_col_last'])
                    . $this->noteHtml(strtr($this->cms->snippet('record.gdpr_retention_sessions'), ['{hours}' => (string)$v['session_hours']])),
                'login_attempts' => $this->tableHtml($data['login_attempts'], [
                    'attempt_date' => 'gdpr_col_time', 'ip' => 'gdpr_col_ip', 'deletion' => 'gdpr_col_deletion'])
                    . $this->noteHtml($this->cms->snippet('record.gdpr_note_login_attempts')),
                'log_lines' => $this->tableHtml($data['log_lines'], ['file' => 'gdpr_col_log', 'line' => 'gdpr_col_entry', 'deletion' => 'gdpr_col_deletion']),
                'second_factor' => $this->tableHtml($data['second_factor'], [
                    'method' => 'gdpr_col_method', 'created_at' => 'gdpr_col_first']),
                'tokens' => $this->tableHtml($data['tokens'], [
                    'purpose' => 'gdpr_col_purpose', 'created_at' => 'gdpr_col_first']),
                'basket' => $this->tableHtml($data['basket'], [
                    'record_id' => 'gdpr_col_product', 'quantity' => 'gdpr_col_quantity', 'changed_at' => 'gdpr_col_last']),
                'files' => $this->listHtml(array_map(fn($file) => 'dateien/' . $file, $files)),
                'audit' => $this->tableHtml($audit, ['time' => 'gdpr_col_time', 'action' => 'gdpr_col_action',
                    'object' => 'gdpr_col_object', 'by' => 'gdpr_col_by', 'fields' => 'gdpr_col_fields']),
                // retention periods: always at least one line
                'retention' => $this->listHtml($data['retention']),
                // what happens on deletion: the deletion policy of the profile area
                'delete_policy' => $this->listHtml($GLOBALS['app']->get(\Camindo\Core\Modules\Privacy\PrivacyBackend::class)
                    ->policyLines((int)$profile['area_id'])),
            ],
            $retention['flags']
        );
    }

    /** Several records, grouped by area, with heading; none: "none" */
    private function recordsHtml(array $records): string
    {
        if (!$records) return $this->backend->template('record.gdpr_none');
        $byArea = [];
        foreach ($records as $r) $byArea[(string)$r['area']][] = $r;
        $html = '';
        foreach ($byArea as $area => $list) {
            $html .= $this->backend->template('record.gdpr_area_heading', ['{area}' => $this->backend->escape($area), '{count}' => (string)count($list)]);
            foreach ($list as $r) $html .= $this->recordHtml($r, true);
        }
        return $html;
    }

    /** Permanently deleted contents (only still in the audit log), grouped by area */
    private function purgedHtml(array $purged): string
    {
        if (!$purged) return $this->backend->template('record.gdpr_none');
        $byArea = [];
        foreach ($purged as $row) $byArea[(string)$row['area']][] = $row;
        $html = '';
        foreach ($byArea as $area => $rows) {
            $html .= $this->backend->template('record.gdpr_area_heading', ['{area}' => $this->backend->escape($area), '{count}' => (string)count($rows)]);
            $html .= $this->tableHtml($rows, ['object' => 'gdpr_col_object', 'purged_at' => 'gdpr_col_purged', 'planned' => 'gdpr_col_planned']);
        }
        return $html;
    }

    /** Note below a table (e.g. the period) */
    private function noteHtml(string $text): string
    {
        return $this->backend->template('record.gdpr_note', ['{text}' => $this->backend->escape($text)]);
    }

    /** One record: fields (per language), below them the meta fields */
    private function recordHtml(array $r, bool $withHeading = false): string
    {
        $out = '';
        if ($withHeading) {
            $out .= $this->backend->template('record.gdpr_record_heading', [
                '{label}' => $this->backend->escape((string)$r['label']),
                '{id}' => (string)(int)$r['record_id'],
                '{trash}' => $r['deleted'] ? $this->backend->template('record.gdpr_badge_trash') : '',
            ]);
        }
        $rows = '';
        foreach ($r['fields'] as $f) {
            $rows .= $this->backend->template('record.gdpr_field_row', [
                '{label}' => $this->backend->escape((string)$f['label'])
                    . ($f['language'] !== '' ? $this->backend->template('record.gdpr_badge', ['{text}' => $this->backend->escape((string)$f['language'])]) : ''),
                '{value}' => (string)$f['value'] === '' ? $this->backend->template('record.gdpr_empty_value') : $this->backend->escape((string)$f['value']),
            ]);
        }
        foreach ($r['meta'] as $label => $value) {
            $rows .= $this->backend->template('record.gdpr_meta_row', [
                '{label}' => $this->backend->escape((string)$label),
                '{value}' => (string)$value === '' ? '–' : $this->backend->escape((string)$value),
            ]);
        }
        return $out . $this->backend->template('record.gdpr_record_table', ['{rows}' => $rows]);
    }

    /**
     * Table from rows, without rows: "none".
     *
     * @param array $columns  column in $rows => snippet of the heading (without "record.")
     */
    private function tableHtml(array $rows, array $columns): string
    {
        if (!$rows) return $this->backend->template('record.gdpr_none');
        $head = '';
        foreach ($columns as $key) {
            $head .= $this->backend->template('record.gdpr_table_head', ['{text}' => $this->backend->escape($this->cms->snippet('record.' . $key))]);
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

    /** List, without entries: "none" */
    private function listHtml(array $lines): string
    {
        if (!$lines) return $this->backend->template('record.gdpr_none');
        $items = '';
        foreach ($lines as $line) {
            $items .= $this->backend->template('record.gdpr_list_item', ['{text}' => $this->backend->escape((string)$line)]);
        }
        return $this->backend->template('record.gdpr_list', ['{items}' => $items]);
    }

    // ====================

    private function rows(string $sql, int $profileId): array
    {
        $this->db->query($sql, 'i', [$profileId]);
        return $this->db->fetchAll();
    }

    /**
     * Who acted - without names of other persons: "You", "Admin", "User"
     * (another website user), "Visitor", "System".
     */
    private function actorText(array $row, int $profileId): string
    {
        return match ((string)$row['actor_type']) {
            'user' => (int)$row['user_id'] === $profileId ? $this->cms->snippet('record.gdpr_by_you') : $this->cms->snippet('record.gdpr_by_user'),
            'admin' => $this->cms->snippet('record.gdpr_by_admin'),
            'visitor' => $this->cms->snippet('audit_actor_visitor'),
            default => $this->cms->snippet('audit_actor_system'),
        };
    }

    /**
     * Permanently deleted records that the audit log still links to the user
     * (entries with his user_id), together with what the deletion policy still plans
     * (open jobs for the audit log).
     *
     * @return array  list of record_id, area, object, purged_at, planned
     */
    private function purgedContents(int $profileId): array
    {
        $this->db->query(
            "SELECT a.`object_id`, MAX(a.`area_id`) AS `area_id`, MAX(a.`audit_id`) AS `last_id` FROM `audit_log` a"
            . " LEFT JOIN `record` r ON r.`record_id` = a.`object_id`"
            . " WHERE a.`object_type` = 'record' AND a.`user_id` = ? AND a.`object_id` <> ? AND r.`record_id` IS NULL"
            . " GROUP BY a.`object_id` ORDER BY `last_id` DESC LIMIT " . self::MAX_RECORDS,
            'ii', [$profileId, $profileId]
        );
        $objects = $this->db->fetchAll();
        $list = [];
        foreach ($objects as $object) {
            $id = (int)$object['object_id'];
            $this->db->query("SELECT `label`, `created_at` FROM `audit_log` WHERE `object_type` = 'record' AND `object_id` = ?"
                . " AND `action` = 'purge' ORDER BY `audit_id` DESC LIMIT 1", 'i', [$id]);
            $purge = $this->db->fetch() ?? [];
            $this->db->query("SELECT `label` FROM `audit_log` WHERE `audit_id` = ?", 'i', [(int)$object['last_id']]);
            $label = (string)(($purge['label'] ?? '') ?: ($this->db->fetchNum()[0] ?? ''));
            $this->db->query("SELECT `action`, MIN(`due_at`) AS `due_at` FROM `privacy_job` WHERE `record_id` = ? AND `done_at` IS NULL"
                . " AND `action` LIKE 'audit\\_%' GROUP BY `action` ORDER BY `due_at`", 'i', [$id]);
            $planned = [];
            foreach ($this->db->fetchAll() as $job) {
                $planned[] = $this->cms->snippet('privacy.action_' . $job['action']) . ': ' . substr((string)$job['due_at'], 0, 10);
            }
            $list[] = [
                'record_id' => $id,
                'area' => (int)$object['area_id'] > 0 ? $this->areaLabel((int)$object['area_id']) : '–',
                'object' => ($label !== '' ? $label . ' ' : '') . '(#' . $id . ')',
                'purged_at' => isset($purge['created_at']) ? substr((string)$purge['created_at'], 0, 19) . ' UTC' : '',
                'planned' => implode(', ', $planned),
            ];
        }
        return $list;
    }

    /**
     * Names under which LoginGuard counts failed attempts of the profile:
     * "{area_id}:#{record_id}" (codes) and "{area_id}:{value}" for each short
     * text value of the profile - the sign-in name is in one of them.
     */
    private function guardNames(array $profile): array
    {
        $areaId = (int)$profile['area_id'];
        $names = [$areaId . ':#' . (int)$profile['record_id']];
        foreach ($profile['fields'] as $f) {
            $value = trim((string)$f['value']);
            if ($value === '' || mb_strlen($value) > 60 || preg_match('/\R/', $value)
                || !in_array((string)$f['type'], ['chars', 'text', 'encrypted'], true)) continue;
            $names[] = mb_substr($areaId . ':' . $value, 0, 64);
        }
        return array_values(array_unique($names));
    }

    /** E-mail addresses among the field values of the profile */
    private function emails(array $profile): array
    {
        $emails = [];
        foreach ($profile['fields'] as $f) {
            $value = trim((string)$f['value']);
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL)) $emails[] = mb_strtolower($value);
        }
        return array_values(array_unique($emails));
    }

    /** Failed sign-ins and codes (user_login_attempt), with deletion date */
    private function loginAttempts(array $guardNames): array
    {
        if (!$guardNames) return [];
        $this->db->query("SELECT `ip`, `attempt_date` FROM `user_login_attempt` WHERE `name` IN ("
            . implode(',', array_fill(0, count($guardNames), '?')) . ") ORDER BY `attempt_date` DESC LIMIT " . self::MAX_RECORDS,
            str_repeat('s', count($guardNames)), $guardNames);
        $days = $this->attemptDays();
        return array_map(fn($row) => [
            'attempt_date' => (string)$row['attempt_date'] . ' UTC',
            'ip' => $this->privacyTemplate->displayIp((string)$row['ip']),
            'deletion' => $days > 0 ? gmdate('Y-m-d', strtotime((string)$row['attempt_date'] . ' UTC') + $days * 86400) : '',
        ], $this->db->fetchAll());
    }

    /**
     * Lines of the log files that clearly belong to the profile: locks in the
     * security log with one of its names (LoginGuard) and lines of the mail log with
     * one of its e-mail addresses (e.g. undeliverable mails). The debug log
     * of the mails (whole messages) is excluded.
     */
    private function logLines(array $guardNames, array $emails): array
    {
        $dir = rtrim((string)$this->config->get('paths.var_dir'), '/') . '/log';
        $logDays = (int)($this->privacyTemplate->retention()['values']['log_days'] ?: 0);
        $securityDays = (int)($this->privacyTemplate->retention()['values']['security_days'] ?: 0);
        $lines = [];
        $patterns = [
            'security' => array_map(fn($n) => 'Name: "' . $n . '"', $guardNames),
            'mail' => $emails,
        ];
        foreach ($patterns as $category => $needles) {
            if (!$needles) continue;
            foreach ((array)glob($dir . '/*_' . $category . '.log') as $file) {
                if (!preg_match('/^(\d{4}-\d{2}-\d{2})_/', basename((string)$file), $m)) continue;
                $handle = @fopen((string)$file, 'r');
                if (!$handle) continue;
                while (($line = fgets($handle)) !== false && count($lines) < self::MAX_RECORDS) {
                    $lower = mb_strtolower($line);
                    foreach ($needles as $needle) {
                        if (!str_contains($category === 'mail' ? $lower : $line, $needle)) continue;
                        $days = $category === 'security' ? $securityDays : $logDays;
                        $lines[] = [
                            'file' => basename((string)$file),
                            'line' => $this->maskIps(mb_substr(trim($line), 0, 500)),
                            'deletion' => $days > 0 ? gmdate('Y-m-d', strtotime($m[1] . ' UTC') + $days * 86400) : '',
                        ];
                        break;
                    }
                }
                fclose($handle);
            }
        }
        return $lines;
    }

    /** Show IP addresses in a log line as in the rest of the disclosure */
    private function maskIps(string $line): string
    {
        return (string)preg_replace_callback('/\b\d{1,3}(?:\.\d{1,3}){3}\b/', fn($m) => $this->privacyTemplate->displayIp($m[0]), $line);
    }

    /** Period of the sign-in attempts (task "Clean up logs"), 0 = none */
    private function attemptDays(): int
    {
        return (int)($this->privacyTemplate->retention()['values']['attempt_days'] ?: 0);
    }

    private function areaType(int $areaId): string
    {
        return (string)($this->instanceCache->areaInfo($areaId)['type'] ?? '');
    }

    private function areaLabel(int $areaId): string
    {
        $this->db->query("SELECT `label`, `name` FROM `area` WHERE `area_id` = ?", 'i', [$areaId]);
        $row = $this->db->fetch() ?? [];
        return $this->cms->textOrSnippet((string)(($row['label'] ?? '') ?: ($row['name'] ?? '#' . $areaId)));
    }

    private function recordLabel(array $snapshot, int $id): string
    {
        // same rule as in the audit log: default field of the area
        $label = $this->record->auditLabelOf($id, $snapshot);
        return $label !== '' ? $label : '#' . $id;
    }
}
