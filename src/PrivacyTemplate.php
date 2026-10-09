<?php
/**
 * Template of the personal data export - the page auskunft.html in the zip
 * from RecordPersonalData (website users) and AdminPersonalData (users), created in
 * the Privacy module. A partial extract that the operator completes before handing it
 * to the data subject (legal bases, server logfiles …).
 *
 * WHERE THE TEMPLATE LIVES
 * --------------------
 * In the table `privacy_template`, separately for website users (kind 'user') and
 * users ('admin'), maintained per instance and language in the Privacy module (tab
 * "Templates", dataset Modules\Privacy\ExportTemplate). The export is produced in the
 * language of the chosen template: whoever creates it switches the snippet language
 * for that (PrivacyBackend::export()). New templates are pre-filled from
 * Core/install/privacy/privacy_{kind}_{language}.html.
 *
 * WHAT THE TEMPLATE CONTAINS
 * --------------------
 *     {name}                       Placeholder: values (escaped) and ready-made blocks
 *                                  (HTML tables and lists); unknown ones remain
 *     {#module.key}                Language snippet in the template's language (escaped)
 *     <!-- if:NAME --> … <!-- endif:NAME -->
 *                                  only if the condition NAME applies - e.g. audit or
 *                                  logcleanup; list in retention(). Can be nested,
 *                                  only not with the same NAME. Website users and
 *                                  users have separate templates, so no condition is
 *                                  needed for that.
 *     <!-- other comments -->      Explanations - dropped from the export
 * The operator writes the template - it is as trustworthy as a template.
 * Values from the database only go in escaped.
 *
 * @see Camindo\Core\Modules\Record\RecordPersonalData
 * @see Camindo\Core\Modules\Admin\AdminPersonalData
 */
namespace Camindo\Core;

class PrivacyTemplate {

    /** Language of the core's fallback templates (Core/install/privacy/privacy_{kind}_en_US.html) */
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
     * Build auskunft.html - in the current snippet language (Cms::getLang()).
     *
     * @param string $content  the template (privacy_template.content)
     * @param array  $values   Placeholder => text (will be escaped)
     * @param array  $blocks   Placeholder => ready-made HTML
     * @param array  $flags    Condition => bool (for <!-- if:NAME -->)
     */
    public function render(string $content, array $values, array $blocks, array $flags): string
    {
        $lang = $this->cms->getLang();
        $html = $content;

        // Comments in the template (the operator's explanations) do not belong in the export
        $html = (string)preg_replace('/<!--(?!\s*(?:end)?if:).*?-->\s*/s', '', $html);
        // Conditional sections - repeated until nested ones (with another NAME)
        // are evaluated too
        for ($pass = 0; $pass < 10; $pass++) {
            $html = (string)preg_replace_callback(
                '/<!--\s*if:([a-z0-9_]+)\s*-->(.*?)<!--\s*endif:\1\s*-->/s',
                fn(array $m) => !empty($flags[$m[1]]) ? $m[2] : '',
                $html, -1, $count
            );
            if ($count === 0) break;
        }
        // Snippets in the template's language
        $html = (string)preg_replace_callback(
            '/\{#([a-z0-9_.]+)\}/i',
            fn(array $m) => $this->backend->escape($this->cms->snippet($m[1])),
            $html
        );
        // Placeholders in one pass - inserted values are not replaced again
        $replace = [
            '{lang}' => $this->backend->escape(substr($lang, 0, 2)),
            '{dir}' => $this->cms->textDirection(),
        ];
        foreach ($values as $key => $value) $replace['{' . $key . '}'] = $this->backend->escape((string)$value);
        foreach ($blocks as $key => $block) $replace['{' . $key . '}'] = (string)$block;
        return strtr($html, $replace);
    }

    /** IP address for the export: shortened parts as "xxx" (ClientIp::display()) */
    public function displayIp(string $ip): string
    {
        return ClientIp::display($ip, ClientIp::v4Octets($this->config));
    }

    /**
     * Domain and label of an instance (placeholders {domain}, {instance}).
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
     * Retention periods of this installation as placeholders and conditions - the same
     * for both kinds of export.
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
        // Scheduler tasks: clean up logs, database backup
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
