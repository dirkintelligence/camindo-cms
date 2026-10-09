<?php
/**
 * Contents of the audit log in the file system - one file per object, append-only,
 * with an HMAC chain. The database (`audit_log`) only holds the metadata for lists and
 * search (AuditLog).
 *
 * STORAGE
 * --------------------
 *     var/audit/{object_type}/{bucket}/{object_id}.jsonl        page 1
 *     var/audit/{object_type}/{bucket}/{object_id}.{n}.jsonl    page n
 * bucket = object_id / 1000 (at most 1000 files per directory). If a page
 * grows beyond audit.page_bytes (default 1 MB), the next one begins; the chain continues
 * across the pages.
 *
 * LINE FORMAT
 * --------------------
 *     {h}<TAB>{json}\n
 * json contains id (audit_id), t (UTC with ms), action, type, oid, actor, label, area,
 * instance, changes - plus prev (h of the previous line, '' at the start) and k (id of the
 * key, Crypt::currentKeyId()). h = HMAC over the json string, exactly as it
 * stands in the file - checked byte by byte, without re-encoding.
 *
 * Checkpoints (json with "cp") begin a chain anew, where lines were deliberately removed
 * or rewritten:
 *     trim     retention period - "anchor" is the prev of the first remaining line
 *     rekey    key rotation - values re-encrypted, chain recalculated;
 *              "verified" says whether the old chain was intact before
 *     migrate  takeover from the former column audit_log.changes
 *     restore  database backup restored - entries after the state of the
 *              backup (audit_id > "max_id") removed, see cutAfter()
 *     reseal   broken chain re-linked after checking (`camindo.php audit reseal`,
 *              reseal()) - the entries from the break point on; "relinked" counts them,
 *              "reason" names the cause
 *     anonymize  IP addresses of earlier entries truncated (anonymizeIps()) - entries
 *              left on their pages, chain recalculated; "entries" counts them
 *     privacy    fields overwritten after a deletion policy (rewriteObject());
 *              "verified" says whether the chain was intact before
 * After a checkpoint the next line expects prev = anchor or h of the
 * checkpoint.
 *
 * WHAT THIS PROTECTS
 * --------------------
 * Whoever reaches only the database can neither read nor change the contents; deleted,
 * added or changed meta lines show up in the comparison (AuditLog::verifyObject(),
 * verifyAll()). A line changed, removed or inserted in the file breaks the chain - only
 * someone with the key can forge it. The comparison with the database detects a
 * truncated end of a file.
 *
 * Writing happens after the commit (Database::afterCommit(), AuditLog::write()) -
 * a rollback leaves nothing behind. Parallel writers are serialized by flock().
 */
namespace Camindo\Core;

class AuditStore {

    /** Default for audit.page_bytes */
    public const PAGE_BYTES = 1048576;

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    public function __construct(
        private Config $config,
        private Crypt $crypt,
    )
    {
    }

    /** var/audit */
    public function dir(): string
    {
        return rtrim((string)$this->config->get('paths.var_dir'), '/') . '/audit';
    }

    /**
     * File of a page.
     */
    public function path(string $type, int $objectId, int $page = 1): string
    {
        $type = preg_replace('/[^a-z0-9_]/', '', strtolower($type)) ?: 'unknown';
        $bucket = intdiv(max(0, $objectId), 1000);
        return $this->dir() . "/$type/$bucket/$objectId" . ($page > 1 ? ".$page" : '') . '.jsonl';
    }

    /** Existing pages of an object, ascending: [page => path] */
    public function pages(string $type, int $objectId): array
    {
        $pages = [];
        $first = $this->path($type, $objectId, 1);
        if (is_file($first)) $pages[1] = $first;
        foreach (glob(substr($first, 0, -6) . '.*.jsonl') ?: [] as $file) {
            if (preg_match('/\.(\d+)\.jsonl$/', $file, $m)) $pages[(int)$m[1]] = $file;
        }
        ksort($pages);
        return $pages;
    }

    // ====================
    // Schreiben

    /**
     * Append an entry (see class comment).
     *
     * @param array $entry  id, t, action, type, oid, actor, label, area, instance, changes
     * @return int          page on which the entry is stored
     */
    public function append(array $entry): int
    {
        $type = (string)$entry['type'];
        $objectId = (int)$entry['oid'];
        $pages = $this->pages($type, $objectId);
        $page = $pages ? (int)array_key_last($pages) : 1;
        $file = $this->path($type, $objectId, $page);
        $this->ensureDir(dirname($file));

        $handle = fopen($file, 'c+');
        if ($handle === false) throw new \RuntimeException("cannot open $file");
        try {
            flock($handle, LOCK_EX);
            $size = (int)fstat($handle)['size'];
            $prev = $size > 0 ? $this->lastHash($handle, $size) : $this->previousPageHash($type, $objectId, $page);
            // Page full: start the next one (under the lock of the full page)
            if ($size >= $this->pageBytes()) {
                flock($handle, LOCK_UN);
                fclose($handle);
                $page++;
                $file = $this->path($type, $objectId, $page);
                $handle = fopen($file, 'c+');
                if ($handle === false) throw new \RuntimeException("cannot open $file");
                flock($handle, LOCK_EX);
                $size = (int)fstat($handle)['size'];
                if ($size > 0) $prev = $this->lastHash($handle, $size);
            }
            fseek($handle, 0, SEEK_END);
            fwrite($handle, $this->line($entry, $prev));
            fflush($handle);
            flock($handle, LOCK_UN);
        }
        finally {
            if (is_resource($handle)) fclose($handle);
        }
        $this->chmodFile($file);
        return $page;
    }

    /** One line: h TAB json with prev and k */
    private function line(array $body, string $prev): string
    {
        $body['prev'] = $prev;
        $body['k'] = $this->crypt->currentKeyId();
        $json = json_encode($body, self::JSON);
        return $this->crypt->hash($json) . "\t" . $json . "\n";
    }

    /** h of the last line of an open file */
    private function lastHash($handle, int $size): string
    {
        $chunk = min($size, 65536);
        fseek($handle, -$chunk, SEEK_END);
        $tail = rtrim((string)fread($handle, $chunk), "\n");
        $pos = strrpos($tail, "\n");
        $last = $pos === false ? $tail : substr($tail, $pos + 1);
        if (strlen($tail) === $chunk && $pos === false && $chunk < $size) {
            // Line longer than the excerpt: read it completely
            $lines = $this->readLines(stream_get_meta_data($handle)['uri']);
            $last = (string)end($lines);
        }
        return (string)strstr($last, "\t", true);
    }

    /** h of the last line of the previous page ('' for page 1) */
    private function previousPageHash(string $type, int $objectId, int $page): string
    {
        if ($page <= 1) return '';
        $lines = $this->readLines($this->path($type, $objectId, $page - 1));
        return $lines ? (string)strstr((string)end($lines), "\t", true) : '';
    }

    // ====================
    // Reading and checking

    /**
     * Entries of an object: audit_id => entry (without checkpoints).
     *
     * @param int|null $page  only this page
     */
    public function entries(string $type, int $objectId, ?int $page = null): array
    {
        $files = $page !== null ? [$page => $this->path($type, $objectId, $page)] : $this->pages($type, $objectId);
        $entries = [];
        foreach ($files as $file) {
            foreach ($this->readLines($file) as $line) {
                $json = substr($line, (int)strpos($line, "\t") + 1);
                $body = json_decode($json, true);
                if (!is_array($body) || isset($body['cp'])) continue;
                $entries[(int)$body['id']] = $body;
            }
        }
        return $entries;
    }

    /**
     * Check the chain of an object.
     *
     * @return array  ok (bool), lines, ids (list of audit_ids), entries (audit_id =>
     *                entry, only lines with a valid HMAC), errors (list of page,
     *                line, reason), keys (key ids used)
     */
    public function verify(string $type, int $objectId): array
    {
        $result = ['ok' => true, 'lines' => 0, 'ids' => [], 'entries' => [], 'errors' => [], 'keys' => []];
        $expected = '';
        $first = true;
        foreach ($this->pages($type, $objectId) as $page => $file) {
            foreach ($this->readLines($file) as $number => $line) {
                $result['lines']++;
                $error = function (string $reason) use (&$result, $page, $number) {
                    $result['ok'] = false;
                    $result['errors'][] = ['page' => $page, 'line' => $number + 1, 'reason' => $reason];
                };
                $tab = strpos($line, "\t");
                if ($tab === false) { $error('format'); continue; }
                $h = substr($line, 0, $tab);
                $json = substr($line, $tab + 1);
                $body = json_decode($json, true);
                if (!is_array($body)) { $error('json'); continue; }
                $kid = (string)($body['k'] ?? '');
                $result['keys'][$kid] = true;
                $check = $this->crypt->hashWithKey($kid, $json);
                $signed = false;
                if ($check === null) { $error('unknown key ' . $kid); }
                elseif (!hash_equals($check, $h)) { $error('hmac'); }
                else $signed = true;
                if (isset($body['cp'])) {
                    // Checkpoint: the chain begins anew
                    $expected = (string)($body['anchor'] ?? $h);
                    $first = false;
                    continue;
                }
                if (!$first && (string)($body['prev'] ?? '') !== $expected) $error('chain');
                if ($first && (string)($body['prev'] ?? '') !== '') $error('chain start');
                $first = false;
                $expected = $h;
                $result['ids'][] = (int)($body['id'] ?? 0);
                if ($signed) $result['entries'][(int)($body['id'] ?? 0)] = $body;
            }
        }
        $result['keys'] = array_keys($result['keys']);
        return $result;
    }

    /**
     * All object files: list of [type, object_id].
     */
    public function objects(): array
    {
        $objects = [];
        foreach (glob($this->dir() . '/*/*/*.jsonl') ?: [] as $file) {
            if (!preg_match('~/([a-z0-9_]+)/\d+/(\d+)(?:\.\d+)?\.jsonl$~', $file, $m)) continue;
            $objects[$m[1] . ':' . $m[2]] = [$m[1], (int)$m[2]];
        }
        return array_values($objects);
    }

    // ====================
    // Aufbewahrung, Umschreiben

    /** Delete all pages of an object */
    public function delete(string $type, int $objectId): void
    {
        foreach ($this->pages($type, $objectId) as $file) @unlink($file);
    }

    /**
     * Remove entries (retention period): $remove(array $body): bool decides per
     * entry. Pages that drop out entirely are deleted; if anything remains, the
     * first remaining page begins with a checkpoint 'trim'.
     *
     * @return int  removed entries
     */
    public function trim(string $type, int $objectId, callable $remove): int
    {
        $removed = 0;
        $anchor = '';
        $pendingCheckpoints = [];
        foreach ($this->pages($type, $objectId) as $file) {
            $lines = $this->readLines($file);
            $keep = [];
            $started = false;
            foreach ($lines as $line) {
                if (!$started) {
                    $body = json_decode(substr($line, (int)strpos($line, "\t") + 1), true);
                    if (is_array($body) && isset($body['cp'])) {
                        $pendingCheckpoints[] = $line;
                        continue;
                    }
                    if (is_array($body) && $remove($body)) {
                        $removed++;
                        $anchor = (string)strstr($line, "\t", true);
                        continue;
                    }
                    $started = true;
                    // Nothing removed: the existing checkpoints stay in place,
                    // otherwise a new one with the h of the last removed line would replace them
                    if ($removed === 0) {
                        array_push($keep, ...$pendingCheckpoints);
                    }
                    else {
                        $checkpoint = ['cp' => 'trim', 't' => gmdate('Y-m-d H:i:s'), 'removed' => $removed, 'anchor' => $anchor];
                        $keep[] = rtrim($this->line($checkpoint, ''), "\n");
                    }
                }
                $keep[] = $line;
            }
            if ($removed === 0) return 0;
            if ($keep) {
                $this->writeFile($file, implode("\n", $keep) . "\n");
                return $removed;
            }
            // Page dropped entirely - continue with the next
            @unlink($file);
        }
        return $removed;
    }

    /**
     * Remove entries with an audit_id above $maxId - after restoring a
     * database backup (`camindo.php audit restore`, AuditLog::restoreToDatabase()):
     * the meta lines of these entries no longer exist, and the database assigns
     * its ids anew.
     *
     * The lines before the first removed one stay unchanged (the chain up to there
     * is intact); after that follows a checkpoint 'restore' and - rarely, only if
     * entries were not appended in id order - the other remaining entries,
     * re-chained. If no entry remains, the file is dropped.
     *
     * @param string|null $archiveDir  copy of the affected pages before changing
     * @return int                     removed entries
     */
    public function cutAfter(string $type, int $objectId, int $maxId, ?string $archiveDir = null): int
    {
        $pages = $this->pages($type, $objectId);
        $keep = [];          // [page => lines] unchanged
        $rest = [];          // entries remaining after the first removed line
        $removed = 0;
        $entriesLeft = 0;
        $lastPage = 1;
        foreach ($pages as $page => $file) {
            foreach ($this->readLines($file) as $line) {
                $body = json_decode(substr($line, (int)strpos($line, "\t") + 1), true);
                $isEntry = is_array($body) && !isset($body['cp']);
                if ($isEntry && (int)($body['id'] ?? 0) > $maxId) {
                    $removed++;
                    continue;
                }
                if ($removed === 0) {
                    $keep[$page][] = $line;
                    $lastPage = $page;
                    if ($isEntry) $entriesLeft++;
                    continue;
                }
                // Checkpoints behind the cut are replaced by the new one
                if ($isEntry) {
                    $rest[] = $body;
                    $entriesLeft++;
                }
            }
        }
        if ($removed === 0) return 0;

        if ($archiveDir !== null) {
            foreach ($pages as $file) {
                $target = $archiveDir . '/' . substr($file, strlen($this->dir()) + 1);
                $this->ensureDir(dirname($target));
                if (!@copy($file, $target)) throw new \RuntimeException("cannot copy $file");
            }
        }
        if ($entriesLeft === 0) {
            $this->delete($type, $objectId);
            return $removed;
        }

        $checkpoint = ['cp' => 'restore', 't' => gmdate('Y-m-d H:i:s'), 'removed' => $removed, 'max_id' => $maxId];
        $tail = $this->line($checkpoint, '');
        $prev = (string)strstr($tail, "\t", true);
        foreach ($rest as $body) {
            unset($body['prev'], $body['k']);
            $line = $this->line($body, $prev);
            $prev = (string)strstr($line, "\t", true);
            $tail .= $line;
        }
        foreach ($pages as $page => $file) {
            if ($page > $lastPage) {
                @unlink($file);
                continue;
            }
            $content = isset($keep[$page]) ? implode("\n", $keep[$page]) . "\n" : '';
            if ($page === $lastPage) $content .= $tail;
            $this->writeFile($file, $content);
        }
        if (!$pages) $this->writeFile($this->path($type, $objectId, 1), $tail);
        return $removed;
    }

    /**
     * Rewrite all files - key rotation (KeyRotation): first check the old
     * chain, then pass every entry through $transform(array $body): array
     * (re-encrypt values) and recalculate the chain with the current key,
     * beginning with checkpoint 'rekey'.
     *
     * @return array  files, entries, broken (files whose old chain was not intact)
     */
    public function rewriteAll(callable $transform, string $reason = 'rekey'): array
    {
        $result = ['files' => 0, 'entries' => 0, 'broken' => []];
        foreach ($this->objects() as [$type, $objectId]) {
            $check = $this->verify($type, $objectId);
            $entries = $this->entries($type, $objectId);
            $pages = $this->pages($type, $objectId);
            if (!$check['ok']) $result['broken'][] = "$type:$objectId";
            // Everything anew on page 1, other pages dropped (page change when appending)
            $file = $this->path($type, $objectId, 1);
            $checkpoint = ['cp' => $reason, 't' => gmdate('Y-m-d H:i:s'), 'verified' => $check['ok'],
                'from' => $check['keys']];
            $prevLine = $this->line($checkpoint, '');
            $content = $prevLine;
            $prev = (string)strstr($prevLine, "\t", true);
            foreach ($entries as $body) {
                unset($body['prev'], $body['k']);
                $line = $this->line($transform($body), $prev);
                $prev = (string)strstr($line, "\t", true);
                $content .= $line;
                $result['entries']++;
            }
            foreach ($pages as $page => $path) if ($page > 1) @unlink($path);
            $this->writeFile($file, $content);
            $result['files']++;
        }
        return $result;
    }

    /**
     * Re-chain a broken chain (`camindo.php audit reseal TYPE ID`).
     *
     * For the case that the entries themselves are genuine (every line carries a
     * valid HMAC) but the chaining is wrong - e.g. because a file was replaced in the
     * meantime or restored from a backup. The lines up to the first break point stay
     * unchanged; after that follows a checkpoint 'reseal', and all remaining entries
     * are re-chained with the current key (earlier checkpoints after it are dropped).
     * Everything is on page 1 afterwards.
     *
     * If a line has no valid HMAC (content changed, unknown key, broken format),
     * re-chaining only happens with $force - then its content is no longer
     * vouched for, and the checkpoint notes this ("unverified"). Lines that cannot
     * be read are dropped.
     *
     * This is a trust act of whoever has the command line - like
     * `camindo.php admin sign`: afterwards the chain counts as intact again.
     *
     * @param string      $reason      cause (appears in the checkpoint)
     * @param bool        $force       also take over lines without a valid HMAC
     * @param bool        $dryRun      check only
     * @param string|null $archiveDir  copy of the pages before changing
     * @return array  status (intact | refused | resealed | would_reseal), relinked
     *                (re-chained entries), unverified (lines without a valid HMAC),
     *                errors (findings of the check beforehand)
     */
    public function reseal(string $type, int $objectId, string $reason = '', bool $force = false, bool $dryRun = false, ?string $archiveDir = null): array
    {
        $check = $this->verify($type, $objectId);
        $result = ['status' => 'intact', 'relinked' => 0, 'unverified' => 0, 'errors' => $check['errors']];
        if ($check['ok']) return $result;

        $unverified = array_filter($check['errors'], fn($e) => !in_array($e['reason'], ['chain', 'chain start'], true));
        $result['unverified'] = count($unverified);
        if ($unverified && !$force) {
            $result['status'] = 'refused';
            return $result;
        }

        // rows of all pages in order; break point = first finding
        $first = $check['errors'][0];
        $keep = [];
        $rest = [];
        $broken = false;
        foreach ($this->pages($type, $objectId) as $page => $file) {
            foreach ($this->readLines($file) as $number => $line) {
                if (!$broken && $page === (int)$first['page'] && $number + 1 === (int)$first['line']) $broken = true;
                if (!$broken) {
                    $keep[] = $line;
                    continue;
                }
                $body = json_decode(substr($line, (int)strpos($line, "\t") + 1), true);
                if (!is_array($body) || isset($body['cp'])) continue;
                $rest[] = $body;
            }
        }
        $result['relinked'] = count($rest);
        if ($dryRun) {
            $result['status'] = 'would_reseal';
            return $result;
        }

        $pages = $this->pages($type, $objectId);
        if ($archiveDir !== null) {
            foreach ($pages as $file) {
                $target = $archiveDir . '/' . substr($file, strlen($this->dir()) + 1);
                $this->ensureDir(dirname($target));
                if (!@copy($file, $target)) throw new \RuntimeException("cannot copy $file");
            }
        }
        $checkpoint = ['cp' => 'reseal', 't' => gmdate('Y-m-d H:i:s'), 'relinked' => count($rest), 'reason' => $reason];
        if ($unverified) $checkpoint['unverified'] = count($unverified);
        $content = $keep ? implode("\n", $keep) . "\n" : '';
        $line = $this->line($checkpoint, '');
        $content .= $line;
        $prev = (string)strstr($line, "\t", true);
        foreach ($rest as $body) {
            unset($body['prev'], $body['k']);
            $line = $this->line($body, $prev);
            $prev = (string)strstr($line, "\t", true);
            $content .= $line;
        }
        foreach ($pages as $page => $path) if ($page > 1) @unlink($path);
        $this->writeFile($this->path($type, $objectId, 1), $content);
        $result['status'] = 'resealed';
        return $result;
    }

    /**
     * Rewrite the entries of ONE object - deletion policy (AuditLog::overwriteObject()):
     * $transform(array $body): ?array per entry - null removes it (retention period
     * of the logins, AuditLog::purge()). The entries stay on their pages,
     * the chain begins anew with the checkpoint $reason; "verified" says whether they
     * were intact before. Unlike anonymizeIps(), even a broken chain is rewritten -
     * personal data must go; the finding stays noted in the
     * checkpoint.
     *
     * @return int  entries that have changed
     */
    public function rewriteObject(string $type, int $objectId, callable $transform, string $reason = 'privacy'): int
    {
        $pages = [];
        $changed = 0;
        foreach ($this->pages($type, $objectId) as $page => $file) {
            $pages[$page] = [];
            foreach ($this->readLines($file) as $line) {
                $body = json_decode(substr($line, (int)strpos($line, "\t") + 1), true);
                if (!is_array($body) || isset($body['cp'])) continue;
                unset($body['prev'], $body['k']);
                $new = $transform($body);
                if ($new !== $body) $changed++;
                if ($new !== null) $pages[$page][] = $new;
            }
        }
        if ($changed === 0) return 0;
        $verified = $this->verify($type, $objectId)['ok'];
        $prev = '';
        foreach ($pages as $page => $bodies) {
            $content = '';
            if ($page === array_key_first($pages)) {
                $content = $this->line(['cp' => $reason, 't' => gmdate('Y-m-d H:i:s'), 'entries' => $changed, 'verified' => $verified], '');
                $prev = (string)strstr($content, "\t", true);
            }
            foreach ($bodies as $body) {
                $line = $this->line($body, $prev);
                $prev = (string)strstr($line, "\t", true);
                $content .= $line;
            }
            $this->writeFile($this->path($type, $objectId, $page), $content);
        }
        return $changed;
    }

    /**
     * Truncate IP addresses in the files (`camindo.php audit anonymize-ip`) - for
     * entries from the time before AuditLog::write() only wrote the truncated IP to the
     * file. $anonymize(string $ip): string returns the truncated address.
     *
     * Only a file in which an IP really has to be truncated is rewritten, and only
     * if its chain is intact - otherwise truncating would silently make a broken chain
     * valid again (reseal() is there for that). The entries stay on their pages
     * (audit_log.page remains correct); the chain begins anew with the checkpoint
     * 'anonymize', earlier checkpoints are dropped as in rewriteAll(). A copy of the
     * old files is deliberately not kept.
     *
     * @return array  files (rewritten), entries (truncated IPs), skipped
     *                ("type:id" with a broken chain - unchanged)
     */
    public function anonymizeIps(callable $anonymize, bool $dryRun = false): array
    {
        $result = ['files' => 0, 'entries' => 0, 'skipped' => []];
        foreach ($this->objects() as [$type, $objectId]) {
            $pages = [];
            $changed = 0;
            foreach ($this->pages($type, $objectId) as $page => $file) {
                $pages[$page] = [];
                foreach ($this->readLines($file) as $line) {
                    $body = json_decode(substr($line, (int)strpos($line, "\t") + 1), true);
                    if (!is_array($body) || isset($body['cp'])) continue;
                    $ip = (string)($body['actor']['ip'] ?? '');
                    if ($ip !== '') {
                        $short = (string)$anonymize($ip);
                        if ($short !== $ip) {
                            $body['actor']['ip'] = $short;
                            $changed++;
                        }
                    }
                    unset($body['prev'], $body['k']);
                    $pages[$page][] = $body;
                }
            }
            if ($changed === 0) continue;
            if (!$this->verify($type, $objectId)['ok']) {
                $result['skipped'][] = "$type:$objectId";
                continue;
            }
            $result['files']++;
            $result['entries'] += $changed;
            if ($dryRun) continue;

            $prev = '';
            foreach ($pages as $page => $bodies) {
                $content = '';
                if ($page === array_key_first($pages)) {
                    $content = $this->line(['cp' => 'anonymize', 't' => gmdate('Y-m-d H:i:s'), 'entries' => $changed], '');
                    $prev = (string)strstr($content, "\t", true);
                }
                foreach ($bodies as $body) {
                    $line = $this->line($body, $prev);
                    $prev = (string)strstr($line, "\t", true);
                    $content .= $line;
                }
                $this->writeFile($this->path($type, $objectId, $page), $content);
            }
        }
        return $result;
    }

    /**
     * Create entries under a checkpoint anew (takeover from the database,
     * AuditLog::migrateToFiles()) - appends to existing files.
     */
    public function appendCheckpoint(string $type, int $objectId, string $reason): void
    {
        $file = $this->path($type, $objectId, 1);
        if (is_file($file) && filesize($file) > 0) return;
        $this->ensureDir(dirname($file));
        $this->writeFile($file, $this->line(['cp' => $reason, 't' => gmdate('Y-m-d H:i:s')], ''));
    }

    // ====================

    /** Lines of a file (without line ending, without empty lines) */
    private function readLines(string $file): array
    {
        if (!is_file($file)) return [];
        return file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    }

    private function writeFile(string $file, string $content): void
    {
        $this->ensureDir(dirname($file));
        if (@file_put_contents($file, $content, LOCK_EX) === false) throw new \RuntimeException("cannot write $file");
        $this->chmodFile($file);
    }

    private function pageBytes(): int
    {
        $bytes = (int)($this->config->get('audit.page_bytes') ?? 0);
        return $bytes > 0 ? $bytes : self::PAGE_BYTES;
    }

    private function ensureDir(string $dir): void
    {
        if (is_dir($dir)) return;
        $mode = (int)($this->config->get('permissions.dir') ?: 0775);
        if (!@mkdir($dir, $mode, true) && !is_dir($dir)) throw new \RuntimeException("cannot create $dir");
        @chmod($dir, $mode);
    }

    private function chmodFile(string $file): void
    {
        @chmod($file, (int)($this->config->get('permissions.file') ?: 0664));
    }
}
