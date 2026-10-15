<?php
/**
 * htaccess [status|renew]
 *
 * The managed block of public/.htaccess (HtaccessWriter) - the same as the button in
 * Tools > Server. status (default): up to date or outdated (exit code 1 when outdated,
 * usable in scripts). renew: rewrite the block; lines outside it stay.
 * `upgrade` and the installer write the file anyway.
 */
namespace Camindo\Core\Cli;

use Camindo\Core\HtaccessWriter;

class HtaccessCommand extends Command {

    public function run(array $args, array $options): int
    {
        $what = $args[0] ?? 'status';
        $writer = $this->get(HtaccessWriter::class);

        if ($what === 'status') {
            $outdated = $writer->hasChanges();
            $this->out('htaccess: ' . $writer->file() . ($outdated ? ' is outdated (run: htaccess renew)' : ' is up to date'));
            return $outdated ? Cli::ERROR : Cli::OK;
        }
        if ($what === 'renew') {
            if (!$writer->update()) {
                $this->err('htaccess: could not write ' . $writer->file());
                return Cli::ERROR;
            }
            $this->out('htaccess: ' . $writer->file() . ' written');
            return Cli::OK;
        }
        return $this->usage('usage: htaccess [status|renew]');
    }
}
