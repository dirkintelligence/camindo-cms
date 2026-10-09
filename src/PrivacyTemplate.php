<?php
/**
 * Vorlage des Datenexports personenbezogener Daten - die Seite auskunft.html im Zip
 * von RecordPersonalData (Website-User) und AdminPersonalData (Benutzer), erstellt im
 * Modul Privacy. Ein Teilauszug, den der Betreiber vor der Weitergabe an die
 * betroffene Person ergänzt (Rechtsgrundlagen, Logfiles des Servers …).
 *
 * WO DIE VORLAGE LIEGT
 * --------------------
 * In der Tabelle `privacy_template`, getrennt für Website-User (kind 'user') und
 * Benutzer ('admin'), je Instanz und Sprache gepflegt im Modul Privacy (Reiter
 * "Vorlagen", Dataset Modules\Privacy\ExportTemplate). Die Auskunft entsteht in der
 * Sprache der gewählten Vorlage: wer sie erstellt, schaltet dafür die Snippet-Sprache
 * um (PrivacyBackend::export()). Neue Vorlagen sind mit
 * Core/install/privacy/privacy_{art}_{sprache}.html vorbelegt.
 *
 * WAS IN DER VORLAGE STEHT
 * --------------------
 *     {name}                       Platzhalter: Werte (maskiert) und fertige Blöcke
 *                                  (HTML-Tabellen und -Listen); unbekannte bleiben stehen
 *     {#modul.schluessel}          Sprach-Snippet der Sprache der Vorlage (maskiert)
 *     <!-- if:NAME --> … <!-- endif:NAME -->
 *                                  nur, wenn die Bedingung NAME gilt - etwa audit oder
 *                                  logcleanup; Liste in retention(). Verschachtelbar,
 *                                  nur nicht mit gleichem NAME. Website-User und
 *                                  Benutzer haben getrennte Vorlagen, dafür braucht es
 *                                  keine Bedingung.
 *     <!-- sonstige Kommentare -->  Erläuterungen - fallen in der Auskunft weg
 * Die Vorlage schreibt der Betreiber - sie ist vertrauenswürdig wie ein Template.
 * Werte aus der Datenbank kommen nur maskiert hinein.
 *
 * @see Camindo\Core\Modules\Record\RecordPersonalData
 * @see Camindo\Core\Modules\Admin\AdminPersonalData
 */
namespace Camindo\Core;

class PrivacyTemplate {

    /** Sprache der Rückfall-Vorlagen des Core (Core/install/privacy/privacy_{art}_en_US.html) */
    public const FALLBACK = 'en_US';

    public function __construct(
        private Config $config,
        private Database $db,
        private Cms $cms,
        private Backend $backend,
        private System $system,
    )
    {}

    /**
     * auskunft.html erzeugen - in der aktuellen Snippet-Sprache (Cms::getLang()).
     *
     * @param string $content  die Vorlage (privacy_template.content)
     * @param array  $values   Platzhalter => Text (wird maskiert)
     * @param array  $blocks   Platzhalter => fertiges HTML
     * @param array  $flags    Bedingung => bool (für <!-- if:NAME -->)
     */
    public function render(string $content, array $values, array $blocks, array $flags): string
    {
        $lang = $this->cms->getLang();
        $html = $content;

        // Kommentare der Vorlage (Erläuterungen des Betreibers) gehören nicht in die Auskunft
        $html = (string)preg_replace('/<!--(?!\s*(?:end)?if:).*?-->\s*/s', '', $html);
        // Bedingte Abschnitte - wiederholt, bis auch verschachtelte (mit anderem NAME)
        // ausgewertet sind
        for ($pass = 0; $pass < 10; $pass++) {
            $html = (string)preg_replace_callback(
                '/<!--\s*if:([a-z0-9_]+)\s*-->(.*?)<!--\s*endif:\1\s*-->/s',
                fn(array $m) => !empty($flags[$m[1]]) ? $m[2] : '',
                $html, -1, $count
            );
            if ($count === 0) break;
        }
        // Snippets der Sprache der Vorlage
        $html = (string)preg_replace_callback(
            '/\{#([a-z0-9_.]+)\}/i',
            fn(array $m) => $this->backend->escape($this->cms->snippet($m[1])),
            $html
        );
        // Platzhalter in einem Durchgang - eingesetzte Werte werden nicht erneut ersetzt
        $replace = [
            '{lang}' => $this->backend->escape(substr($lang, 0, 2)),
            '{dir}' => $this->cms->textDirection(),
        ];
        foreach ($values as $key => $value) $replace['{' . $key . '}'] = $this->backend->escape((string)$value);
        foreach ($blocks as $key => $block) $replace['{' . $key . '}'] = (string)$block;
        return strtr($html, $replace);
    }

    /** IP-Adresse für die Auskunft: gekürzte Stellen als "xxx" (ClientIp::display()) */
    public function displayIp(string $ip): string
    {
        return ClientIp::display($ip, ClientIp::v4Octets($this->config));
    }

    /**
     * Domain und Bezeichnung einer Instanz (Platzhalter {domain}, {instance}).
     *
     * @return array  ['domain' => …, 'instance' => …]
     */
    public function instanceValues(int $instanceId): array
    {
        $instance = $GLOBALS['app']->get(InstanceCache::class)->instance($instanceId) ?? [];
        return [
            'domain' => (string)($instance['primary_domain'] ?? ''),
            'instance' => $this->cms->textOrSnippet((string)($instance['label'] ?? '')),
        ];
    }

    /**
     * Fristen dieser Installation als Platzhalter und Bedingungen - für beide Arten
     * der Auskunft gleich.
     *
     * @return array  ['values' => [...], 'flags' => [...]]
     */
    public function retention(): array
    {
        $int = fn(string $key, int $default) => (int)($this->config->get($key) ?? $default);
        $values = [
            'trash_days'         => $int('admin.trash_retention_days', 7),
            'device_days'        => $int('security.device_days', 180),
            'ip_days'            => max(0, $int('privacy.ip_anonymize_days', 7)),
            'ip_v4_octets'       => ClientIp::v4Octets($this->config),
            'message_days'       => max(0, $int('privacy.message_days', 60)),
            'audit_changes_days' => $int('audit.retention_days_changes', 0),
            'audit_deleted_days' => $int('audit.retention_days_deleted', 365),
            'session_hours'      => max(1, (int)round($int('user.max_inactive_sec', 86400) / 3600)),
            'session_minutes'    => max(1, (int)round($int('admin.max_inactive_sec', 1800) / 60)),
            'basket_days'        => $int('user.basket_days', 30),
            'unconfirmed_days'   => $int('user.unconfirmed_days', 7),
            'export_hours'       => $int('admin.export_retention_hours', 24),
            'log_days' => '', 'security_days' => '', 'attempt_days' => '', 'backups' => '',
        ];
        // Aufgaben des Zeitplaners: Logs aufräumen, Datenbank-Backup
        $tasks = [];
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `type`, `details` FROM `scheduler` WHERE `type` IN ('logcleanup', 'dbbackup') AND `enabled` = 1 ORDER BY `scheduler_id`");
            while ($row = $this->db->fetch()) {
                $tasks[(string)$row['type']] ??= json_decode((string)$row['details'], true) ?: [];
            }
        }
        finally {
            $this->db->popResult();
        }
        if (isset($tasks['logcleanup'])) {
            $values['log_days'] = (int)($tasks['logcleanup']['log_days'] ?? 90);
            $values['security_days'] = (int)($tasks['logcleanup']['security_days'] ?? 365);
            $values['attempt_days'] = (int)($tasks['logcleanup']['attempt_days'] ?? 30);
        }
        if (isset($tasks['dbbackup'])) $values['backups'] = max(1, (int)($tasks['dbbackup']['backups'] ?? 7));

        $flags = [
            'audit'                 => (bool)$this->config->get('audit.enabled'),
            'audit_changes_limited' => $values['audit_changes_days'] > 0,
            'audit_changes_unlimited' => $values['audit_changes_days'] <= 0,
            'audit_deleted_limited' => $values['audit_deleted_days'] > 0,
            'logcleanup'            => isset($tasks['logcleanup']),
            'no_logcleanup'         => !isset($tasks['logcleanup']),
            'dbbackup'              => isset($tasks['dbbackup']),
            'no_dbbackup'           => !isset($tasks['dbbackup']),
            'messages_limited'      => $values['message_days'] > 0,
            'ip_v4_one'             => $values['ip_v4_octets'] === 1,
            'ip_v4_two'             => $values['ip_v4_octets'] === 2,
        ];
        return ['values' => $values, 'flags' => $flags];
    }
}
