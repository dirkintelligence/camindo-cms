<?php
/**
 * fulltext [--area=ID[,ID…]] [--stale] [--token=…]
 *
 * Rebuild full text - RecordService::rebuildFulltext(): all full-text fields and
 * the default full text of the areas (without --area all record areas). --stale
 * takes only those marked as stale (FulltextState). Areas that another
 * process is currently rebuilding are skipped.
 *
 * --token=… reports progress and end to the entry in `bgproc` - this is how the
 * button "Update now" (AreaBackend::fulltext()) runs the command in the background.
 */
namespace Camindo\Core\Cli;

use Camindo\Core\BackgroundProcess;
use Camindo\Core\FulltextState;
use Camindo\Core\Modules\Record\RecordService;

class FulltextCommand extends Command {

    public function run(array $args, array $options): int
    {
        $areaIds = is_string($options['area'] ?? null)
            ? array_values(array_filter(array_map('intval', explode(',', $options['area']))))
            : [];
        if (!empty($options['stale'])) {
            $stale = $this->get(FulltextState::class)->staleAreaIds();
            $areaIds = $areaIds ? array_values(array_intersect($areaIds, $stale)) : $stale;
            if (empty($areaIds)) {
                $this->out('fulltext: no outdated areas');
                return Cli::OK;
            }
        }
        $token = is_string($options['token'] ?? null) ? $options['token'] : '';
        $bgproc = $this->get(BackgroundProcess::class);

        $ok = false;
        try {
            $result = $this->get(RecordService::class)->rebuildFulltext($areaIds, 0,
                function (int $done, int $total) use ($bgproc, $token) {
                    if ($token === '') return;
                    $bgproc->setProgressByToken($token, $total > 0 ? (int)floor($done * 100 / $total) : 100, "{$done}/{$total}");
                });
            $this->out(sprintf('fulltext: %d records in %d areas', $result['records'], $result['areas']));
            if ($result['skipped']) {
                $this->out('skipped (already running): ' . implode(', ', $result['skipped']));
            }
            $ok = true;
        }
        finally {
            if ($token !== '') {
                $bgproc->setStatusByToken($token, $ok ? BackgroundProcess::STATUS_FINISHED : BackgroundProcess::STATUS_FAILED);
            }
        }
        return Cli::OK;
    }
}
