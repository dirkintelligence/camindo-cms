<?php
/**
 * Migrationen eines Verzeichnisses von Version `from` auf Version `to` ausführen.
 *
 *     {dir}/9.1.0.sql     Schema - Anweisung für Anweisung (SqlFile)
 *     {dir}/9.1.0.php     Daten/Logik derselben Version, läuft nach der .sql;
 *                         gibt eine Closure function(InstallContext): void zurück
 *     {dir}/9.2.0.sql
 *
 * Dateiname = die Version, die diese Migration einführt. Es laufen alle mit
 * from < v <= to, sortiert per version_compare(). Nach jeder Version ruft der
 * Runner $advance($version) - der Aufrufer schreibt damit den Zeiger fort
 * (system.core_version bzw. component.version), sodass ein Abbruch die
 * Installation auf der letzten guten Version stehen lässt.
 *
 * Core: Core/install/migrations, Aufrufer camindo.php upgrade.
 * Zusatzkomponenten: {Komponente}/install/migrations, Aufrufer ComponentManager::update().
 *
 * @see docs/developers/14-installation-und-update.md, Kapitel 3
 */
namespace Camindo\Core\Install;

class MigrationRunner {

    /**
     * Versionen im Verzeichnis, die zwischen from (exklusiv) und to (inklusiv) liegen,
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
     * Ausführen.
     *
     * @param callable $advance  function(string $version): void - nach jeder Version
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
