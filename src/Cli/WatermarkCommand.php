<?php
/**
 * watermark status | key | detect FILE [--json]
 *
 * Invisible watermark (Camindo\Core\Watermark):
 *
 *     status   program, version, key file, codes of the instances, fields with the switch
 *     key      create the secret key (paths.watermark_key_file) if it does not exist
 *     detect   check an image file; exit code 0 = watermark found, 1 = none
 */
namespace Camindo\Core\Cli;

use Camindo\Core\Database;
use Camindo\Core\Watermark;

class WatermarkCommand extends Command {

    public function run(array $args, array $options): int
    {
        $watermark = $this->get(Watermark::class);
        return match ($args[0] ?? 'status') {
            'status' => $this->status($watermark),
            'key'    => $this->key($watermark),
            'detect' => $this->detect($watermark, (string)($args[1] ?? ''), !empty($options['json'])),
            default  => $this->usage('watermark status | key | detect FILE [--json]'),
        };
    }

    private function status(Watermark $watermark): int
    {
        $binary = $watermark->binary();
        $this->out('program:  ' . ($binary === '' ? '(paths.watermark not set - function off)' : $binary));
        if ($binary !== '') {
            $this->out('version:  ' . ($watermark->version() ?? 'NOT EXECUTABLE'));
        }
        $keyFile = $watermark->keyFile();
        $this->out('key file: ' . $keyFile . ' (' . (is_readable($keyFile) ? 'present' : 'MISSING - camindo.php watermark key') . ')');
        $this->out('ready:    ' . ($watermark->available() ? 'yes' : 'no'));

        $db = $this->get(Database::class);
        $db->query('SELECT `instance_id`, `label`, `details` FROM `instance` ORDER BY `position`, `instance_id`');
        $this->out('');
        $this->out('instances with a code:');
        $any = false;
        foreach ($db->fetchAll() as $row) {
            $details = json_decode((string)($row['details'] ?? ''), true);
            $code = Watermark::normalizeCode((string)($details['watermark_code'] ?? ''));
            if ($code === null) continue;
            $this->out(sprintf('  %-6s %d %s', '"' . $code . '"', $row['instance_id'], $row['label']));
            $any = true;
        }
        if (!$any) $this->out('  (none)');

        // details may be empty - decoded in PHP (also works on MariaDB)
        $db->query("SELECT `field_id`, `name`, `area_id`, `details` FROM `field`"
            . " WHERE `deleted` = 0 AND `details` LIKE '%watermark%' ORDER BY `area_id`, `field_id`");
        $this->out('');
        $this->out('fields with watermark:');
        $rows = array_filter($db->fetchAll(), fn($row) => !empty(json_decode((string)$row['details'], true)['watermark']));
        foreach ($rows as $row) {
            $this->out(sprintf('  field %d %s (area %d)', $row['field_id'], $row['name'], $row['area_id']));
        }
        if ($rows === []) $this->out('  (none)');
        return Cli::OK;
    }

    private function key(Watermark $watermark): int
    {
        if ($watermark->createKey($error)) {
            $this->out('key created: ' . $watermark->keyFile());
            $this->out('Keep a copy in a safe place - without it no watermark can be read.');
            return Cli::OK;
        }
        if ($error === 'exists') {
            $this->out('key exists: ' . $watermark->keyFile() . ' (not replaced)');
            return Cli::OK;
        }
        $this->err('watermark key: ' . $error);
        return Cli::ERROR;
    }

    private function detect(Watermark $watermark, string $file, bool $json): int
    {
        if ($file === '' || !is_file($file)) return $this->usage('watermark detect FILE [--json]');
        $result = $watermark->detect($file, $error);
        if ($result === null) {
            $this->err('watermark detect: ' . $error);
            return Cli::ERROR;
        }
        if ($json) {
            $this->out((string)json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        elseif (($result['status'] ?? '') === 'found') {
            $label = isset($result['code']) ? '"' . $result['code'] . '"' : (string)($result['id'] ?? '');
            $instances = array_map(fn($i) => $i['instance_id'] . ' ' . $i['label'], (array)$result['instances']);
            $this->out('watermark found: ' . $label . ' (confidence ' . ($result['confidence'] ?? 0) . ')');
            $this->out('instance: ' . ($instances ? implode(', ', $instances) : '(no instance uses this code)'));
        }
        elseif (($result['status'] ?? '') === 'present_unreadable') {
            $this->out('watermark present, but unreadable (confidence ' . ($result['confidence'] ?? 0) . ')');
        }
        else {
            $this->out('no watermark found');
        }
        return ($result['status'] ?? '') === 'found' ? Cli::OK : Cli::ERROR;
    }
}
