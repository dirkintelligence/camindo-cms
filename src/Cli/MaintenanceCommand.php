<?php
/**
 * maintenance on [--reason=…] | off | status
 *
 * Sets or removes var/maintenance.lock (SystemState). When the lock is set,
 * the frontend responds with 503, the backend only lets superusers through and the
 * background jobs end themselves (developer docs, chapter 09 (Installation and update)).
 */
namespace Camindo\Core\Cli;

use Camindo\Core\SystemState;

class MaintenanceCommand extends Command {

    public function run(array $args, array $options): int
    {
        $state = $this->get(SystemState::class);
        switch ($args[0] ?? 'status') {
            case 'on':
                $reason = is_string($options['reason'] ?? null) ? $options['reason'] : 'manual';
                $state->lock($reason);
                $this->out("maintenance mode on ($reason)");
                return Cli::OK;

            case 'off':
                if (!$state->isMaintenance()) {
                    $this->out('maintenance mode was not on');
                    return Cli::OK;
                }
                $state->unlock();
                $this->out('maintenance mode off');
                return Cli::OK;

            case 'status':
                $info = $state->maintenanceInfo();
                if ($info === null) {
                    $this->out('maintenance mode off');
                }
                else {
                    $this->out(sprintf('maintenance mode on since %s (%s), %d min',
                        $info['since'], $info['reason'] ?: '-', intdiv($info['age'], 60)));
                }
                return Cli::OK;
        }
        return $this->usage('usage: maintenance on [--reason=…] | off | status');
    }
}
