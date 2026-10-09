<?php
/**
 * Optionale Installationslogik einer Zusatzkomponente: {Komponente}/install/Installer.php
 *
 * Die Datei definiert die Klasse {Namespace der Komponente}\Install\Installer, also
 * z. B. Camindo\Modules\Blog\Install\Installer, und wird vom ComponentManager per
 * require_once geladen (der Autoloader findet sie nicht - install/ ist klein
 * geschrieben). Wer keine eigene Logik braucht, lässt die Datei weg: schema.sql,
 * seed.sql und migrations/ laufen auch ohne sie.
 *
 * Reihenfolge der Aufrufe (docs/developers/14-installation-und-update.md, 6.3-6.5):
 *
 *     component install     schema.sql, seed.sql -> install()
 *     component activate    activate()
 *     component deactivate  deactivate()
 *     component update      migrations/ from -> to -> migrate(from, to)
 *     component uninstall   deactivate() falls aktiv -> uninstall(purge) -> bei purge:
 *                           Tabellen aus settings.php['tables'] droppen
 *
 * Alle Methoden laufen auf der Kommandozeile oder im Backend-Modul Component, immer
 * mit InstallContext (Datenbank, Config, Logger) - keine Backend-Klassen.
 */
namespace Camindo\Core\Install;

interface ComponentInstallerInterface {

    /** Nach schema.sql/seed.sql - Stammdaten, Verzeichnisse, Einstellungen */
    public function install(InstallContext $ctx): void;

    /** Nach den Migrationen von $from auf $to */
    public function migrate(string $from, string $to, InstallContext $ctx): void;

    public function activate(InstallContext $ctx): void;

    public function deactivate(InstallContext $ctx): void;

    /**
     * Vor dem Löschen der component-Zeile. Die Tabellen aus settings.php['tables']
     * droppt der ComponentManager selbst, wenn $purge gesetzt ist - hier ist Platz für
     * alles andere (Dateien unter var/, Fremddaten in Core-Tabellen).
     */
    public function uninstall(InstallContext $ctx, bool $purge): void;
}
