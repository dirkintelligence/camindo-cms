<?php
/**
 * Dataset child class for the table `component` - the directory of all components
 * (modules, processors, extensions; core and add-on).
 *
 * The table is not maintained via an input mask, but by the
 * ComponentManager (Core/src): refresh() compares it with the directories,
 * install/update/uninstall write version, update_version, status, tables,
 * installed_at, updated_at. The backend module (ComponentBackend) and the CLI
 * (`camindo.php component …`) call the same methods. At runtime the table
 * is not queried - Cms reads the generated lists var/config/components/*.php.
 *
 * This class now only serves as schema description (column labels, types).
 *
 * COLUMNS THAT ARE EASILY CONFUSED
 * --------------------
 *     component_type   kind: 'modules', 'processors' or 'extensions'
 *     type             subtype from install/settings.php, e.g. 'image' for a
 *                      processor or 'record' for a module
 *     version          installed version ('' = only discovered)
 *     update_version   version of the files, if it is newer than the installed one
 *     tables           JSON list of the tables (for deletion with uninstall --purge)
 *
 * @see Camindo\Core\ComponentManager
 * @see developer docs, chapter 09 (Installation and update)
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
            // Directory and class name of the component, e.g. 'ImageGd'.
            'name' => [
                'type'  => 'chars',
                'label' => '#component.field_name',
                'max'   => 32,
            ],
            'label' => [
                'type'  => 'chars',
                'label' => '#component.field_label',
                'max'   => 128,
                'extension' => 'Snippets',   // Core extension: select a snippet (#prefix.key)
            ],
            'description' => [
                'type'  => 'text',
                'label' => '#component.field_description',
            ],
            // 1 = built-in core component, 0 = installed later via Composer
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

    // All hooks deliberately empty: records of this table are written only by the
    // ComponentManager, not by an input mask.

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