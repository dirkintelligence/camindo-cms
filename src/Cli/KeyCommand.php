<?php
/**
 * key status | rotate [--invalidate-passwords] [--no-backup] | retire KID [--force] | rehash
 *
 *     status    aktueller Schlüssel, abgelöste Schlüssel mit Frist
 *               (security.key_password_grace_days), was noch an ihnen hängt
 *     rotate    neuen Schlüssel einführen, alles neu verschlüsseln und signieren
 *               (Camindo\Core\KeyRotation). Alle Admins werden abgemeldet.
 *               Höchstens einmal je 24 Stunden (KeyRotation::MIN_INTERVAL).
 *               --invalidate-passwords (auch -f): Passwörter sind öffentlich
 *               aufgetaucht - alle Passwörter sofort ungültig, alle Sessions, Geräte
 *               und Wiederherstellungscodes weg, der alte Schlüssel wird entfernt.
 *               Admins bekommen danach ein Passwort mit `admin password NAME`,
 *               Frontend-User über "Passwort vergessen".
 *     retire    abgelösten Schlüssel entfernen; mit --force auch, wenn noch
 *               Passwörter an ihm hängen (die werden ungültig)
 *     rehash    Suchwerte verschlüsselter Felder (data_enc.hash) mit der aktuellen
 *               Normalisierung neu berechnen (KeyRotation::rehashEncrypted()) -
 *               einmal für Daten von vor der Normalisierung
 */
namespace Camindo\Core\Cli;

use Camindo\Core\Config;
use Camindo\Core\KeyRotation;

class KeyCommand extends Command {

    public function run(array $args, array $options): int
    {
        $what = $args[0] ?? '';
        $rotation = $this->get(KeyRotation::class);

        if ($what === 'status') {
            $status = $rotation->status();
            $this->out('current key   ' . $status['current'] . '   ' . ($status['key_source'] === 'env'
                ? '(from environment variable ' . Config::ENV_KEY . ')'
                : '(file mode ' . $status['key_file_mode'] . ')'));
            $this->envKeyWarning($status['env_key']);
            if ($status['last_rotation'] !== '') {
                $this->out('last rotation ' . $status['last_rotation']
                    . ($status['next_rotation'] !== '' ? '   (next possible ' . $status['next_rotation'] . ')' : ''));
            }
            if ($status['key_file_mode'] !== '' && $status['key_file_mode'] !== $status['key_file_mode_config']) {
                $this->out('  WARNING: .camindo.key has mode ' . $status['key_file_mode']
                    . ', permissions.key_file is ' . $status['key_file_mode_config']);
            }
            foreach ($status['passwords'] as $kid => $count) {
                $this->out(sprintf('passwords     %-10s %d', $kid, $count));
            }
            if (!$status['retired']) $this->out('retired keys  none');
            foreach ($status['retired'] as $retired) {
                $this->out(sprintf('retired key   %s   since %s   grace until %s   passwords %d   recovery codes from before %d',
                    $retired['kid'], $retired['retired_at'] ?: '?', $retired['grace_until'] ?: '-',
                    $retired['passwords'], $retired['recovery_codes']));
            }
            $pending = array_filter($status['not_current']);
            $this->out('not on current key  ' . ($pending ? json_encode($pending) : 'nothing'));
            return Cli::OK;
        }

        if ($what === 'rotate') {
            $invalidate = !empty($options['invalidate-passwords']) || in_array('-f', $args, true);
            try {
                $result = $rotation->rotate($invalidate, empty($options['no-backup']), fn(string $line) => $this->out($line));
            }
            catch (\RuntimeException $e) {
                $this->err($e->getMessage());
                return Cli::ERROR;
            }
            foreach ($result['counts'] as $area => $count) $this->out(str_pad($area, 32) . $count);
            $this->out("key rotated {$result['old']} -> {$result['new']}; all backend sessions ended");
            $this->envKeyWarning($rotation->status()['env_key']);
            $this->out($invalidate
                ? 'all passwords are invalid - set admin passwords with: php Core/cron/camindo.php admin password NAME'
                : "old key {$result['old']} kept for passwords and recovery codes - remove later with: key retire {$result['old']}");
            return Cli::OK;
        }

        if ($what === 'retire') {
            $kid = (string)($args[1] ?? '');
            if (!preg_match('/^[0-9a-f]{8}$/', $kid)) return $this->usage('usage: key retire KID [--force]');
            $result = $rotation->retire($kid, !empty($options['force']));
            if ($result['error'] !== '') {
                $this->err($result['error']);
                return Cli::ERROR;
            }
            $this->out("key $kid removed" . ($result['passwords'] ? ", {$result['passwords']} password(s) invalidated" : ''));
            return Cli::OK;
        }

        if ($what === 'rehash') {
            $this->out('data_enc hashes updated: ' . $rotation->rehashEncrypted());
            return Cli::OK;
        }

        return $this->usage('usage: key status | rotate [--invalidate-passwords] [--no-backup] | retire KID [--force] | rehash');
    }

    /** Hinweis, wenn CAMINDO_KEY nicht den aktuellen Schlüssel enthält */
    private function envKeyWarning(string $state): void
    {
        if ($state === 'retired') {
            $this->out('  WARNING: ' . Config::ENV_KEY . ' holds a retired key - set it to the content of .camindo.key;');
            $this->out('           until then .camindo.key must stay (it takes precedence)');
        }
        elseif ($state === 'other') {
            $this->out('  WARNING: ' . Config::ENV_KEY . ' holds an unknown key - .camindo.key is used');
        }
    }
}
