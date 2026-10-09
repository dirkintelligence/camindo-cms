<?php
/**
 * Datenexport personenbezogener Daten eines Website-Users: was das CMS zu einem
 * Profil-Datensatz gespeichert hat, als Zip. Erstellt im Modul Privacy
 * (PrivacyBackend::export(), Zusatzrecht export). Ein Teilauszug: für eine Auskunft
 * nach Art. 15/20 DSGVO von Hand zu ergänzen (Rechtsgrundlagen, Server-Logfiles,
 * Buchhaltung …). Instanzname im Code: $personalData.
 *
 * INHALT DES ZIPS
 * --------------------
 *     auskunft.html   für Menschen: eine eigenständige Seite (druckbar) aus der
 *                     Vorlage des Betreibers (PrivacyTemplate, Tabelle
 *                     privacy_template) in der Sprache der Vorlage
 *     daten.json      dieselben Daten maschinenlesbar (Datenübertragbarkeit)
 *     dateien/        die hochgeladenen Dateien unter ihrem ursprünglichen Namen
 *                     ({record_id}_{feld}[_{sprache}]_{name})
 *
 * WAS DAZUGEHÖRT
 * --------------------
 *     Profil              alle Felder des Profil-Datensatzes, alle Sprachen;
 *                         verschlüsselte Felder im Klartext; Passwörter nur als
 *                         "gespeichert (nicht lesbar)"
 *     Inhalte des Users   Datensätze mit record.user_id = Profil (Kommentare,
 *                         Bewertungen … über CMS:USER_INPUT), auch im Papierkorb,
 *                         nach Bereich gegliedert
 *     Gelöschte Inhalte   endgültig gelöschte Datensätze, die das Audit-Log noch mit
 *                         dem User verbindet (Einträge mit seiner user_id), samt
 *                         dem, was die Lösch-Policy noch damit vorhat
 *     Verweise            Datensätze, die per Verknüpfungsfeld auf das Profil zeigen
 *                         (etwa Bestellungen), höchstens MAX_RECORDS
 *     Anmeldung           bekannte Geräte (login_device), Sitzungen (user_session),
 *                         zweiter Faktor (user_second_factor: Art und Datum - nie
 *                         Schlüssel oder Codes),
 *                         offene Einmal-Links (user_token), Warenkorb (user_basket)
 *     Protokoll           Einträge des Audit-Logs zu diesen Datensätzen und zu
 *                         Handlungen des Users. Wer gehandelt hat, steht nur als
 *                         "Sie selbst", "Admin", "User" oder "System" darin - Namen
 *                         anderer Personen gehören nicht in die Auskunft
 *     Fehlversuche        fehlgeschlagene Anmeldungen und Codes (user_login_attempt
 *                         unter "{area_id}:{Feldwert}" bzw. "{area_id}:#{record_id}"),
 *                         Sperren im Security-Log mit diesem Namen, Zeilen des
 *                         Mail-Logs mit einer E-Mail-Adresse des Profils (etwa nicht
 *                         zustellbare Mails)
 *     Speicherdauer       Papierkorb (admin.trash_retention_days), Audit-Log
 *                         (audit.*), Geräte, Kürzen der IP-Adressen
 *                         (privacy.ip_anonymize_days), Sitzungen, Logdateien
 *                         (Aufgabe "Logs aufräumen" des Zeitplaners)
 *
 * Nicht enthalten, weil nicht sicher dem Profil zuzuordnen: die übrigen Zeilen der
 * Logdateien unter var/log - der Abschnitt Speicherdauer nennt ihre Frist.
 * Gekürzte IP-Adressen erscheinen mit "xxx" für die entfernten Stellen.
 *
 * Das Zip liegt in var/export unter einem nicht erratbaren Namen, wird nach dem
 * Herunterladen gelöscht (PrivacyBackend::download()) und sonst nach
 * admin.export_retention_hours vom Cron. Jede Auskunft steht im Audit-Log
 * (Aktion 'export').
 *
 * @see Camindo\Core\Modules\Privacy\PrivacyBackend::export()   Oberfläche
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

    /** Obergrenze je Liste (Inhalte des Users, Verweise, Protokoll) */
    public const MAX_RECORDS = 500;

    /** Datums-Platzhalter "kein Datum" / "kein Ende" (wie Cms::_formatDate()) */
    private const DATE_MIN_DISPLAY = '1000-01-02 00:00:00';
    private const DATE_MAX_DISPLAY = '8999-12-31 00:00:00';

    /** Metafelder, die in der Auskunft erscheinen */
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
     * Auskunft erstellen.
     *
     * @param int    $profileId  record_id des Profils (Bereich vom Typ 'profile')
     * @param string $template   Vorlage der Auskunft (privacy_template.content); die
     *                           Snippet-Sprache stellt der Aufrufer passend ein
     * @return array             ['zip' => Dateiname in var/export, 'size' => Bytes]
     * @throws \RuntimeException  kein Profil, Zip nicht schreibbar
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
     * Alle Daten zum Profil zusammentragen (Aufbau siehe daten.json).
     *
     * @throws \RuntimeException  kein Profil-Datensatz
     */
    public function collect(int $profileId): array
    {
        $profile = $this->recordData($profileId);
        if ($profile === null || $this->areaType($profile['area_id']) !== 'profile') {
            throw new \RuntimeException('not a profile record');
        }
        $instance = $this->instanceCache->instance($profile['instance_id']) ?? [];

        // Inhalte des Users
        $this->db->query(
            "SELECT `record_id` FROM `record` WHERE `user_id` = ? AND `tmp` = 0 ORDER BY `record_id` LIMIT " . self::MAX_RECORDS,
            'i', [$profileId]
        );
        $contentIds = array_map('intval', $this->db->fetchColumn());
        // Datensätze, die auf das Profil verweisen
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

        // Dateien: Profil und eigene Inhalte (Verweise gehören anderen)
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
     * Ein Datensatz für die Auskunft: Bereich, Bezeichnung, Metafelder, Felder.
     *
     * @return array|null  record_id, area_id, area, instance_id, label, deleted,
     *                     meta [Beschriftung => Wert], fields [Liste aus field, label,
     *                     language, value], file_rows (intern, fürs Zip)
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
            if (isset($this->cms->schema[$name])) continue;   // Metafelder: siehe oben
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

        // Dateien (nur echte Dateien, siehe VirtualFieldsTrait::recordFileRows())
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
                // im Papierkorb liegen die Dateien unter var/deleted
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
     * Protokolleinträge: zu den Datensätzen und Handlungen des Users.
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
            // Geänderte Felder ohne Werte (Metazeile; die Werte stehen in var/audit)
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
     * Speicherdauer - Einstellungen dieser Installation, als Sätze (Platzhalter
     * {retention} der Vorlage; die einzelnen Fristen gibt es dort auch als eigene
     * Platzhalter, PrivacyTemplate::retention()).
     *
     * @return string[]
     */
    private function retention(): array
    {
        ['values' => $v, 'flags' => $f] = $this->privacyTemplate->retention();
        $lines = [
            strtr($this->cms->snippet('record.gdpr_retention_trash'), ['{days}' => (string)$v['trash_days']]),
        ];
        // Was nach dem Löschen mit dem Änderungsprotokoll geschieht, sagt die
        // Lösch-Policy des Bereichs (Platzhalter {delete_policy})
        if ($this->auditLog->enabled()) {
            $lines[] = $f['audit_changes_limited']
                ? strtr($this->cms->snippet('record.gdpr_retention_audit_changes'), ['{days}' => (string)$v['audit_changes_days']])
                : $this->cms->snippet('record.gdpr_retention_audit_changes_unlimited');
        }
        $lines[] = strtr($this->cms->snippet('record.gdpr_retention_devices'), ['{days}' => (string)$v['device_days']]);
        $lines[] = strtr($this->cms->snippet('record.gdpr_retention_ip'), ['{days}' => (string)$v['ip_days']]);
        $lines[] = strtr($this->cms->snippet('record.gdpr_retention_sessions'), ['{hours}' => (string)$v['session_hours']]);
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
     * für Website-User (PrivacyTemplate, Tabelle privacy_template) in deren
     * Sprache. Die Blöcke entstehen aus den Vorlagen record.gdpr_*; alle Werte
     * über Backend::escape().
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
                // Fristen direkt unter Geräten und Sitzungen
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
                // Speicherdauer: immer mindestens eine Zeile
                'retention' => $this->listHtml($data['retention']),
                // Was beim Löschen geschieht: die Lösch-Policy des Profil-Bereichs
                'delete_policy' => $this->listHtml($GLOBALS['app']->get(\Camindo\Core\Modules\Privacy\PrivacyBackend::class)
                    ->policyLines((int)$profile['area_id'])),
            ],
            $retention['flags']
        );
    }

    /** Mehrere Datensätze, nach Bereich gegliedert, mit Überschrift; ohne: "keine" */
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

    /** Endgültig gelöschte Inhalte (nur noch im Audit-Log), nach Bereich gegliedert */
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

    /** Hinweis unter einer Tabelle (etwa die Frist) */
    private function noteHtml(string $text): string
    {
        return $this->backend->template('record.gdpr_note', ['{text}' => $this->backend->escape($text)]);
    }

    /** Ein Datensatz: Felder (je Sprache), darunter die Metafelder */
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
     * Tabelle aus Zeilen, ohne Zeilen: "keine".
     *
     * @param array $columns  Spalte in $rows => Snippet der Überschrift (ohne "record.")
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

    /** Aufzählung, ohne Einträge: "keine" */
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
     * Wer gehandelt hat - ohne Namen anderer Personen: "Sie selbst", "Admin", "User"
     * (ein anderer Website-User), "Besucher", "System".
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
     * Endgültig gelöschte Datensätze, an denen der User im Audit-Log noch hängt
     * (Einträge mit seiner user_id), samt dem, was die Lösch-Policy noch vorhat
     * (offene Aufträge für das Audit-Log).
     *
     * @return array  Liste aus record_id, area, object, purged_at, planned
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
     * Namen, unter denen LoginGuard Fehlversuche des Profils zählt:
     * "{area_id}:#{record_id}" (Codes) und "{area_id}:{Wert}" für jeden kurzen
     * Textwert des Profils - der Anmeldename steckt in einem davon.
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

    /** E-Mail-Adressen unter den Feldwerten des Profils */
    private function emails(array $profile): array
    {
        $emails = [];
        foreach ($profile['fields'] as $f) {
            $value = trim((string)$f['value']);
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL)) $emails[] = mb_strtolower($value);
        }
        return array_values(array_unique($emails));
    }

    /** Fehlgeschlagene Anmeldungen und Codes (user_login_attempt), mit Löschdatum */
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
     * Zeilen der Logdateien, die eindeutig zum Profil gehören: Sperren im
     * Security-Log mit einem seiner Namen (LoginGuard) und Zeilen des Mail-Logs mit
     * einer seiner E-Mail-Adressen (etwa nicht zustellbare Mails). Das Debug-Log
     * der Mails (ganze Nachrichten) bleibt außen vor.
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

    /** IP-Adressen in einer Logzeile wie in der übrigen Auskunft darstellen */
    private function maskIps(string $line): string
    {
        return (string)preg_replace_callback('/\b\d{1,3}(?:\.\d{1,3}){3}\b/', fn($m) => $this->privacyTemplate->displayIp($m[0]), $line);
    }

    /** Frist der Anmeldeversuche (Aufgabe "Logs aufräumen"), 0 = keine */
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
        // dieselbe Regel wie im Audit-Log: Default-Feld des Bereichs
        $label = $this->record->auditLabelOf($id, $snapshot);
        return $label !== '' ? $label : '#' . $id;
    }
}
