<?php
/**
 * Run migrations of a directory from version `from` to version `to`.
 *
 *     {dir}/9.1.0.sql     schema - statement by statement (SqlFile)
 *     {dir}/9.1.0.php     data/logic of the same version, runs after the .sql;
 *                         returns a closure function(InstallContext): void
 *     {dir}/9.2.0.sql
 *
 * File name = the version this migration introduces. All with
 * from < v <= to run, sorted by version_compare(). After each version the
 * runner calls $advance($version) - the caller thereby advances the pointer
 * (system.core_version or component.version), so that an abort leaves the
 * installation at the last good version.
 *
 * Core: Core/install/migrations, caller camindo.php upgrade.
 * Add-on components: {component}/install/migrations, caller ComponentManager::update().
 *
 * @see developer docs, chapter 09 (Installation and update)
 */
namespace Camindo\Core\Install;

class MigrationRunner {

    /**
     * versions in the directory that lie between from (exclusive) and to (inclusive),
     * aufsteigend.
     *
     * @return string[]
     */
    public function pending(string $dir, string $from, string $to): array
    {
        $versions = [];
        foreach (glob($dir . '/*.{sql,php}', GLOB_BRACE) ?: [] as $file) {
            $version = pathinfo($file, PATHINFO_FILENAME);
            if (!preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $version)) continue;
            if (version_compare($version, $from, '<=') || version_compare($version, $to, '>')) continue;
            $versions[$version] = true;
        }
        $versions = array_keys($versions);
        usort($versions, 'version_compare');
        return $versions;
    }

    /**
     * Run.
     *
     * @param callable $advance  function(string $version): void - after each version
     * @return string[]          gelaufene Versionen
     */
    public function run(string $dir, string $from, string $to, InstallContext $ctx, callable $advance): array
    {
        $done = [];
        foreach ($this->pending($dir, $from, $to) as $version) {
            $ctx->log("migration $version");
            $sql = "$dir/$version.sql";
            if (is_file($sql)) {
                $n = $ctx->runSqlFile($sql);
                $ctx->log("  $version.sql: $n statements");
            }
            $php = "$dir/$version.php";
            if (is_file($php)) {
                $closure = include $php;
                if (!$closure instanceof \Closure) {
                    throw new \RuntimeException("$version.php must return a Closure(InstallContext)");
                }
                $closure($ctx);
                $ctx->log("  $version.php: done");
            }
            $advance($version);
            $done[] = $version;
        }
        return $done;
    }
}
