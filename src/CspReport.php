<?php
/**
 * Log violations of the frontend script policy (developer docs, chapter 14 (Security: web)).
 *
 *     POST /csp_report.php   (report-uri of the frontend policy, SecurityHeaders)
 *
 * The browser sends the report itself - without session, without token, from every
 * visitor. Therefore: at most MAX_BYTES, throttled per origin (RateLimiter,
 * user.throttle.csp_report), every field cut to one line and one length. The
 * response is always 204 - a sender learns nothing.
 *
 * Formats: application/csp-report ({"csp-report": {…}}, report-uri) and
 * application/reports+json ([{"type": "csp-violation", "body": {…}}], Reporting API).
 *
 * Log: var/log/{Y-m-d}_csp.log as in the backend, with "frontend" and the instance.
 */
namespace Camindo\Core;

class CspReport
{
    /** Largest accepted report in bytes */
    public const MAX_BYTES = 8192;

    public function __construct(
        private System $system,
        private RateLimiter $rateLimiter,
        private ClientIp $clientIp,
        private InstanceCache $instanceCache,
    ) {}

    public function receive(): void
    {
        http_response_code(204);
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
        $raw = (string)file_get_contents('php://input', false, null, 0, self::MAX_BYTES + 1);
        if ($raw === '' || strlen($raw) > self::MAX_BYTES) return;
        if (!$this->rateLimiter->hit('csp_report', $this->clientIp->rateKey())) return;

        $json = json_decode($raw, true);
        if (!is_array($json)) return;
        $reports = isset($json['csp-report']) ? [$json['csp-report']] : [];
        if (!$reports && array_is_list($json)) {
            foreach (array_slice($json, 0, 5) as $item) {
                if (is_array($item) && ($item['type'] ?? '') === 'csp-violation' && is_array($item['body'] ?? null)) {
                    $reports[] = $item['body'];
                }
            }
        }
        $host = preg_replace('/[^a-z0-9.\-:]/i', '', (string)($_SERVER['HTTP_HOST'] ?? '')) ?? '';
        $instanceId = $host !== '' ? $this->instanceCache->resolveDomain(preg_replace('/:\d+$/', '', $host) ?? '') : 0;
        foreach ($reports as $r) {
            if (!is_array($r)) continue;
            $v = fn(string ...$keys) => $this->field($r, $keys);
            $this->system->log('csp', sprintf(
                'frontend instance %d %s %s blocked=%s source=%s line=%s page=%s sample=%s',
                $instanceId,
                preg_replace('/[^a-z-]/', '', strtolower($v('disposition'))) ?: 'enforce',
                preg_replace('/[^a-z-]/', '', strtolower($v('effective-directive', 'effectiveDirective', 'violated-directive'))) ?: '-',
                $v('blocked-uri', 'blockedURL'),
                $v('source-file', 'sourceFile'),
                $v('line-number', 'lineNumber'),
                $v('document-uri', 'documentURL'),
                $v('script-sample', 'sample')
            ));
        }
    }

    /** First existing key, one line, at most 300 characters */
    private function field(array $report, array $keys): string
    {
        foreach ($keys as $key) {
            if (!isset($report[$key]) || !is_scalar($report[$key])) continue;
            $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)$report[$key]);
            return mb_substr(trim((string)$value), 0, 300);
        }
        return '';
    }
}
