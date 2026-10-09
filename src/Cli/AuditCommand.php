<?php
/**
 * audit verify [TYPE ID] | restore [--dry-run] | reseal TYPE ID [--reason=…] [--force] [--dry-run] | migrate
 *       | anonymize-ip [--dry-run] | strip-names | delete TYPE ID | delete --area=ID [--force] [--dry-run]
 *
 *     verify    check the HMAC chain of the files under var/audit and compare with the meta lines
 *               of the table audit_log (Camindo\Core\AuditStore) - all
 *               objects or one ("record 4237"). Exit code 1 on findings. The
 *               result replaces that of the daily check (note on the start page).
 *     restore   after restoring a database backup: remove entries of the files
 *               that are newer than the state of the database (copy to
 *               var/audit-restore-{time}/, AuditLog::restoreToDatabase())
 *     reseal    re-chain a broken HMAC chain of an object after checking
 *               (AuditLog::resealObject()) - only if every line has a valid HMAC
 *               otherwise with --force; copy to var/audit-reseal-{time}/
 *     migrate   take over entries of the former column audit_log.changes into the files
 *               (once, afterwards the column is removed)
 *     anonymize-ip  shorten IP addresses in the files (privacy.ip_v4_octets) that
 *               were written with the full address before the update - once after
 *               the update (AuditLog::anonymizeFileIps()); files with a broken
 *               chain remain unchanged and are named
 *     strip-names  remove names of the actors (users, website users) from older entries
 *               - they are now only stored as an id (AuditLog::stripActorNames());
 *               once after the update, repeatable
 *     delete    delete the entire history - database and files, for records
 *               also their jobs of the deletion policies (PrivacyJobs::forget()): one
 *               object ("record 4237", "admin 34") or with --area=ID all
 *               records of an area (also deleted ones; requires --force).
 *               For exceptional cases - regularly the deadlines and the deletion policies delete
 */
namespace Camindo\Core\Cli;

use Camindo\Core\AuditLog;
use Camindo\Core\Modules\Privacy\PrivacyJobs;

class AuditCommand extends Command {

    public function run(array $args, array $options): int
    {
        $what = $args[0] ?? '';
        $auditLog = $this->get(AuditLog::class);
        if ($what === 'migrate') {
            $count = $auditLog->migrateToFiles();
            $this->out($count > 0 ? "$count entries moved to var/audit, column audit_log.changes dropped" : 'nothing to migrate');
            return Cli::OK;
        }
        if ($what === 'strip-names') {
            $result = $auditLog->stripActorNames();
            $this->out("{$result['entries']} entries in {$result['files']} files, {$result['rows']} database rows without actor names");
            return Cli::OK;
        }
        if ($what === 'anonymize-ip') {
            $dryRun = !empty($options['dry-run']);
            $result = $auditLog->anonymizeFileIps($dryRun);
            $this->out(($dryRun ? 'would shorten ' : 'shortened ') . "{$result['entries']} IP addresses in {$result['files']} files");
            foreach ($result['skipped'] as $object) $this->out("$object: chain broken - unchanged, see: audit verify");
            return $result['skipped'] ? Cli::ERROR : Cli::OK;
        }
        if ($what === 'restore') {
            $dryRun = !empty($options['dry-run']);
            $result = $auditLog->restoreToDatabase($dryRun);
            $this->out(($dryRun ? 'would remove ' : 'removed ') . "{$result['removed']} entries after #{$result['max_id']}"
                . " from {$result['objects']} objects");
            if ($result['archive'] !== '') $this->out('previous files copied to ' . $result['archive']);
            if ($dryRun) return Cli::OK;
            $check = $auditLog->verifyAll();
            $auditLog->storeVerifyResult($check);
            $this->out("{$check['checked']} objects checked, " . count($check['failed']) . ' with findings'
                . ($check['failed'] ? ' - see: audit verify' : ''));
            return $check['failed'] ? Cli::ERROR : Cli::OK;
        }
        if ($what === 'reseal') {
            if (!isset($args[1], $args[2]) || !ctype_digit((string)$args[2])) {
                return $this->usage('usage: audit reseal TYPE ID [--reason=…] [--force] [--dry-run]');
            }
            $reason = is_string($options['reason'] ?? null) ? $options['reason'] : '';
            $result = $auditLog->resealObject((string)$args[1], (int)$args[2], $reason, !empty($options['force']), !empty($options['dry-run']));
            $this->out("{$args[1]} {$args[2]}: {$result['status']}"
                . ($result['status'] !== 'intact' ? " ({$result['relinked']} entries to relink, {$result['unverified']} lines without valid HMAC)" : ''));
            foreach ($result['errors'] as $error) $this->out("  before: page {$error['page']} line {$error['line']}: {$error['reason']}");
            if ($result['status'] === 'refused') {
                $this->out('  lines without a valid HMAC - their content cannot be verified; use --force to relink anyway');
                return Cli::ERROR;
            }
            if ($result['archive'] !== '') $this->out('  previous files copied to ' . $result['archive']);
            if (!in_array($result['status'], ['would_reseal'], true)) {
                $this->out('  after: ' . ($result['check']['ok'] ? 'ok' : 'FAILED'));
                foreach ($result['check']['errors'] as $error) $this->out('    ' . $error);
                return $result['check']['ok'] ? Cli::OK : Cli::ERROR;
            }
            return Cli::OK;
        }
        if ($what === 'delete') {
            return $this->delete($auditLog, $args, $options);
        }
        if ($what === 'verify') {
            if (isset($args[1], $args[2])) {
                $check = $auditLog->verifyObject((string)$args[1], (int)$args[2]);
                $auditLog->updateVerifyResult((string)$args[1], (int)$args[2], $check);
                $this->out("{$args[1]} {$args[2]}: {$check['lines']} lines, " . ($check['ok'] ? 'ok' : 'FAILED'));
                foreach ($check['errors'] as $error) $this->out('  ' . $error);
                return $check['ok'] ? Cli::OK : Cli::ERROR;
            }
            $result = $auditLog->verifyAll();
            // the start page now shows this result (SystemHealth)
            $auditLog->storeVerifyResult($result);
            foreach ($result['failed'] as $object => $errors) {
                $this->out("$object: FAILED");
                foreach ($errors as $error) $this->out('  ' . $error);
            }
            $this->out("{$result['checked']} objects checked, " . count($result['failed']) . ' with findings');
            return $result['failed'] ? Cli::ERROR : Cli::OK;
        }
        return $this->usage('usage: audit verify [TYPE ID] | restore [--dry-run] | reseal TYPE ID [--reason=…] [--force] [--dry-run] | migrate | anonymize-ip [--dry-run] | strip-names'
            . ' | delete TYPE ID | delete --area=ID [--force] [--dry-run]');
    }

    /** audit delete TYPE ID | --area=ID (see header) */
    private function delete(AuditLog $auditLog, array $args, array $options): int
    {
        $dryRun = !empty($options['dry-run']);
        $areaId = $options['area'] ?? null;
        if ($areaId !== null) {
            if (!ctype_digit((string)$areaId)) return $this->usage('usage: audit delete --area=ID [--force] [--dry-run]');
            $type = 'record';
            $ids = $auditLog->recordIdsOfArea((int)$areaId);
            $scope = "area $areaId";
            if (!$dryRun && empty($options['force'])) {
                $count = $auditLog->deleteObjects($type, $ids, true)['objects'];
                $this->out("$count records with audit history in area $areaId - add --force to delete it, --dry-run for details");
                return Cli::ERROR;
            }
        }
        else {
            if (!isset($args[1], $args[2]) || !preg_match('/^[a-z_]+$/', (string)$args[1]) || !ctype_digit((string)$args[2])) {
                return $this->usage('usage: audit delete TYPE ID [--dry-run] | delete --area=ID [--force] [--dry-run]');
            }
            $type = (string)$args[1];
            $ids = [(int)$args[2]];
            $scope = '';
        }
        $result = $auditLog->deleteObjects($type, $ids, $dryRun, $scope);
        $this->out(($dryRun ? 'would delete ' : 'deleted ') . "{$result['entries']} entries and {$result['files']} files"
            . " of {$result['objects']} objects ($type)");
        if ($type !== 'record' || !class_exists(PrivacyJobs::class)) return Cli::OK;
        $jobs = ['deleted' => 0, 'kept' => 0];
        $privacyJobs = $this->get(PrivacyJobs::class);
        foreach ($ids as $id) {
            foreach ($privacyJobs->forget($id, $dryRun) as $key => $count) $jobs[$key] += $count;
        }
        $this->out(($dryRun ? 'would delete ' : 'deleted ') . "{$jobs['deleted']} privacy jobs"
            . ($jobs['kept'] ? ", {$jobs['kept']} pending jobs of existing records kept" : ''));
        return Cli::OK;
    }
}
