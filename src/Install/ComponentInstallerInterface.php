<?php
/**
 * Optional installation logic of an add-on component: {component}/install/Installer.php
 *
 * The file defines the class {namespace of the component}\Install\Installer, i.e.
 * e.g. Camindo\Modules\Blog\Install\Installer, and is loaded by the ComponentManager via
 * require_once (the autoloader does not find it - install/ is written in
 * lowercase). Whoever needs no own logic omits the file: schema.sql,
 * seed.sql and migrations/ run without it as well.
 *
 * Order of the calls (developer docs, chapter 09 (Installation and update)):
 *
 *     component install     schema.sql, seed.sql -> install()
 *     component activate    activate()
 *     component deactivate  deactivate()
 *     component update      migrations/ from -> to -> migrate(from, to)
 *     component uninstall   deactivate() if active -> uninstall(purge) -> with purge:
 *                           drop tables from settings.php['tables']
 *
 * All methods run on the command line or in the backend module Component, always
 * with InstallContext (database, config, logger) - no backend classes.
 */
namespace Camindo\Core\Install;

interface ComponentInstallerInterface {

    /** After schema.sql/seed.sql - master data, directories, settings */
    public function install(InstallContext $ctx): void;

    /** After the migrations from $from to $to */
    public function migrate(string $from, string $to, InstallContext $ctx): void;

    public function activate(InstallContext $ctx): void;

    public function deactivate(InstallContext $ctx): void;

    /**
     * Before deleting the component row. The tables from settings.php['tables']
     * are dropped by the ComponentManager itself if $purge is set - here there is room for
     * everything else (files under var/, foreign data in core tables).
     */
    public function uninstall(InstallContext $ctx, bool $purge): void;
}
