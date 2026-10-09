<?php
/**
 * admin password NAME [--pass-file=…] | sign
 *
 *     password   Backend-Passwort eines Admins setzen - für den ersten Zugang nach
 *                `install --fixtures`, ein vergessenes Superuser-Passwort oder nach
 *                einem Wechsel des Hash-Verfahrens. Das Passwort kommt aus
 *                --pass-file, CAMINDO_ADMIN_PASS oder (interaktiv) verdeckt von STDIN;
 *                als Argument wäre es in der Shell-History.
 *     sign       alle Signaturen neu schreiben (Integrity::signAll()) - nach einem
 *                Wechsel von .camindo.key oder für Entwicklungsdaten, die mit einem
 *                anderen Schlüssel signiert wurden. Löscht alle Sessions.
 */
namespace Camindo\Core\Cli;

use Camindo\Core\Integrity;
use Camindo\Core\Modules\Admin\Admin;
use Camindo\Core\Cms;

class AdminCommand extends Command {

    public function run(array $args, array $options): int
    {
        $what = $args[0] ?? '';
        if ($what === 'sign') {
            $counts = $this->get(Integrity::class)->signAll();
            foreach ($counts as $table => $count) $this->out(str_pad($table, 20) . $count);
            $this->out('sessions deleted');
            return Cli::OK;
        }
        if ($what === 'password') {
            $name = trim((string)($args[1] ?? ''));
            if ($name === '') return $this->usage('usage: admin password NAME [--pass-file=…]');
            $admin = $this->get(Admin::class);
            $data = $admin->load($name, 'name');
            if ($data === null) {
                $this->err("admin '$name' not found");
                return Cli::ERROR;
            }
            // Länge und Richtlinie (security.password_policy) prüft save()
            $password = $this->password($options);
            $update = ['admin_id' => (int)$data['admin_id'], 'password' => $password];
            $ok = $admin->save(['password'], $update);
            if (!$ok) {
                $this->err($this->get(Cms::class)->errorMsg());
                return Cli::ERROR;
            }
            $this->out("password of '$name' set");
            return Cli::OK;
        }
        return $this->usage('usage: admin password NAME [--pass-file=…] | sign');
    }

    private function password(array $options): string
    {
        $file = is_string($options['pass-file'] ?? null) ? $options['pass-file'] : '';
        if ($file !== '') {
            if (!is_readable($file)) throw new \RuntimeException("cannot read $file");
            return rtrim((string)file_get_contents($file), "\r\n");
        }
        $value = (string)getenv('CAMINDO_ADMIN_PASS');
        if ($value !== '') return $value;
        if (PHP_SAPI !== 'cli') throw new \RuntimeException('missing --pass-file (or CAMINDO_ADMIN_PASS)');
        $tty = function_exists('posix_isatty') && posix_isatty(STDIN);
        fwrite(STDOUT, '  Password: ');
        if ($tty) shell_exec('stty -echo');
        $line = fgets(STDIN);
        if ($tty) { shell_exec('stty echo'); fwrite(STDOUT, "\n"); }
        if ($line === false) throw new \RuntimeException('input closed');
        return rtrim($line, "\r\n");
    }
}
