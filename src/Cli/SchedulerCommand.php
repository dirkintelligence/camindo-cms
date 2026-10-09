<?php
/**
 * scheduler list | defaults | run ID|TYPE
 *
 *     list      all tasks of the scheduler: id, type, on/off, schedule, next run,
 *               result of the last run
 *     defaults  create missing default tasks (Scheduler::createDefaults()) - only
 *               types for which there is no task yet; the installer calls this
 *               also on
 *     run       run a task now in the foreground - by id or by type (the
 *               first task of this type, e.g. "run trash"); also disabled ones.
 *               exit code 1 if the run fails
 */
namespace Camindo\Core\Cli;

use Camindo\Core\Cms;
use Camindo\Core\Database;
use Camindo\Core\Modules\Scheduler\Scheduler;
use Camindo\Core\Modules\Scheduler\SchedulerRunner;

class SchedulerCommand extends Command {

    public function run(array $args, array $options): int
    {
        $what = $args[0] ?? '';
        $db = $this->get(Database::class);

        if ($what === 'list') {
            $db->query("SELECT `scheduler_id`, `type`, `enabled` + 0 AS `enabled`, `cron_minute`, `cron_hour`, `cron_day`, `cron_month`, `cron_weekday`,"
                . " `next_run`, `last_run`, `last_status` FROM `scheduler` ORDER BY `scheduler_id`");
            foreach ($db->fetchAll() as $row) {
                $this->out(sprintf('%4d  %-12s %-3s  %-22s next: %-19s last: %s',
                    (int)$row['scheduler_id'], $row['type'], (int)$row['enabled'] === 1 ? 'on' : 'off',
                    implode(' ', [$row['cron_minute'], $row['cron_hour'], $row['cron_day'], $row['cron_month'], $row['cron_weekday']]),
                    (string)($row['next_run'] ?? '-'),
                    empty($row['last_run']) ? '-' : (['ok', 'running', 'error'][(int)$row['last_status']] ?? '?')));
            }
            return Cli::OK;
        }

        if ($what === 'defaults') {
            $scheduler = $this->get(Scheduler::class);
            $created = $scheduler->createDefaults();
            foreach ($created as $type => $id) $this->out("created: $type (#$id)");
            $errors = $this->get(Cms::class)->errorMsg(' | ');
            if ($errors !== '') {
                $this->err($errors);
                return Cli::ERROR;
            }
            if (!$created) $this->out('nothing to do - every default task type exists');
            return Cli::OK;
        }

        if ($what === 'run') {
            $ref = (string)($args[1] ?? '');
            if ($ref === '') return $this->usage('usage: scheduler run ID|TYPE');
            if (ctype_digit($ref)) {
                $id = (int)$ref;
            }
            else {
                $db->query("SELECT `scheduler_id` FROM `scheduler` WHERE `type` = ? ORDER BY `enabled` DESC, `scheduler_id` LIMIT 1", 's', [$ref]);
                $id = (int)($db->fetchNum()[0] ?? 0);
            }
            if ($id < 1) {
                $this->err("no task found: $ref");
                return Cli::ERROR;
            }
            $ok = $this->get(SchedulerRunner::class)->run($id);
            $db->query("SELECT `last_message` FROM `scheduler` WHERE `scheduler_id` = ?", 'i', [$id]);
            $this->out(($ok ? 'ok' : 'failed') . ': ' . (string)($db->fetchNum()[0] ?? ''));
            return $ok ? Cli::OK : Cli::ERROR;
        }

        return $this->usage('usage: scheduler list | defaults | run ID|TYPE');
    }
}
