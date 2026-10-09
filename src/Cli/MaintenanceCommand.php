<?php
/**
 * maintenance on [--reason=…] | off | status
 *
 * Setzt oder entfernt var/maintenance.lock (SystemState). Bei gesetztem Lock
 * antwortet das Frontend mit 503, das Backend lässt nur Superuser weiter und die
 * Hintergrundjobs beenden sich (14 §7).
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
