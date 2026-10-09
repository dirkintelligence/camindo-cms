<?php
/**
 * backup [--reason=…] | list | prune [--keep=N]
 *
 * mysqldump nach var/dbbackup/{reason}-{Ymd-His}.sql (Backup::beforeChange()).
 * prune behält die N jüngsten Änderungs-Sicherungen; die rotierenden backup-N.sql
 * des Schedulers bleiben unberührt.
 */
namespace Camindo\Core\Cli;

use Camindo\Core\Backup;
use Camindo\Core\System;

class BackupCommand extends Command {

    public function run(array $args, array $options): int
    {
        $backup = $this->get(Backup::class);
        $system = $this->get(System::class);

        switch ($args[0] ?? 'create') {
            case 'create':
                $reason = is_string($options['reason'] ?? null) ? $options['reason'] : 'manual';
                $file = $backup->beforeChange($reason);
                $this->out(basename($file) . ' written, ' . $system->convertSize((float)filesize($file)));
                return Cli::OK;

            case 'list':
                foreach ($backup->list() as $name => $info) {
                    $this->out(sprintf('%-50s %10s  %s', $name,
                        $system->convertSize((float)$info['size']), date('Y-m-d H:i', $info['mtime'])));
                }
                return Cli::OK;

            case 'prune':
                $keep = max(0, (int)($options['keep'] ?? 5));
                $deleted = $backup->prune($keep);
                $this->out(count($deleted) . ' deleted' . ($deleted ? ': ' . implode(', ', $deleted) : ''));
                return Cli::OK;
        }
        return $this->usage('usage: backup [--reason=…] | list | prune [--keep=N]');
    }
}
