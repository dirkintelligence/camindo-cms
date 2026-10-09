<?php
/**
 * Dataset-Kindklasse für die Tabelle `component` - das Verzeichnis aller Komponenten
 * (Module, Prozessoren, Extensions; Core und Zusatz).
 *
 * Die Tabelle wird nicht über eine Eingabemaske gepflegt, sondern vom
 * ComponentManager (Core/src): refresh() gleicht sie mit den Verzeichnissen ab,
 * install/update/uninstall schreiben version, update_version, status, tables,
 * installed_at, updated_at. Das Backend-Modul (ComponentBackend) und die CLI
 * (`camindo.php component …`) rufen dieselben Methoden. Zur Laufzeit wird die Tabelle
 * nicht abgefragt - Cms liest die generierten Listen var/config/components/*.php.
 *
 * Diese Klasse dient nur noch als Schema-Beschreibung (Spaltenlabels, Typen).
 *
 * SPALTEN, DIE MAN LEICHT VERWECHSELT
 * --------------------
 *     component_type   Art: 'modules', 'processors' oder 'extensions'
 *     type             Untertyp aus install/settings.php, z. B. 'image' bei einem
 *                      Prozessor oder 'record' bei einem Modul
 *     version          installierte Version ('' = nur entdeckt)
 *     update_version   Version der Dateien, wenn sie neuer ist als die installierte
 *     tables           JSON-Liste der Tabellen (für das Löschen bei uninstall --purge)
 *
 * @see Camindo\Core\ComponentManager
 * @see docs/developers/14-installation-und-update.md, 5.2
 */
namespace Camindo\Core\Modules\Component;

use Camindo\Core\Dataset;

class Component extends Dataset {

    protected function hookDefinition(): void
    {
        $this->tableName  = 'component';
        $this->moduleName = 'Component';
        $this->defaultSortOrder = 'name ASC';
        $this->schema = [
            'component_id' => [
                'type'  => 'integer',
                'label' => 'component_id',
                'min' => 1
            ],
            'component_type' => [
                'type'  => 'chars',
                'label' => '#component.field_component_type',
                'max'   => 32,
                'flags' => ['trim', 'name']
            ],
            'type' => [
                'type'  => 'chars',
                'label' => '#component.field_type',
                'max'   => 32,
                'flags' => ['trim', 'name']
            ],
            // Verzeichnis- und Klassenname der Komponente, z. B. 'ImageGd'.
            'name' => [
                'type'  => 'chars',
                'label' => '#component.field_name',
                'max'   => 32,
            ],
            'label' => [
                'type'  => 'chars',
                'label' => '#component.field_label',
                'max'   => 128,
                'extension' => 'Snippets',   // Core-Extension: Snippet auswählen (#prefix.key)
            ],
            'description' => [
                'type'  => 'text',
                'label' => '#component.field_description',
            ],
            // 1 = fest eingebaute Core-Komponente, 0 = per Composer nachinstalliert
            'core' => [
                'type'  => 'integer',
                'label' => '#component.field_core',
                'display_type' => 'select',
                'options' => [0 => 'additional', 1 => 'core']
            ],
            // installed version
            'version' => [
                'type'  => 'chars',
                'label' => '#component.field_version',
                'max' => 64
            ],
            // downloaded version
            'update_version' => [
                'type'  => 'chars',
                'label' => '#component.field_update_version',
                'max' => 64
            ],
            'status' => [
                'type'  => 'integer',
                'label' => '#component.field_status',
                'display_type' => 'select',
                'options' => [0 => 'disabled', 1 => 'enabled']
            ],
        ];
    }

    // Alle Hooks bewusst leer: Datensätze dieser Tabelle schreibt ausschließlich der
    // ComponentManager, nicht eine Eingabemaske.

    protected function hookOnCreate(array &$data): void
    {
    }

    protected function hookOnSave(array &$fields, array &$data, array &$previousData): void
    {
    }

    protected function hookOnSaved(array &$fields, array &$data, array &$previousData): void
    {
    }

    protected function hookOnLoaded(array &$data): void
    {
    }

    protected function hookOnDelete(array $data): void
    {
    }
}