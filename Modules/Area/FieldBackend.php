<?php
/**
 * Backend interface for the input fields of a content area (table `field`).
 *
 * Entry points (the scripts are in backend/):
 *
 *     field_list.php     -> list()              Feldliste eines Bereichs
 *     field_info.php     -> info()              Detailansicht eines Feldes
 *     field_create.php   -> create()            step 1: select field type
 *     field_edit.php     -> edit()              step 2 and everything else
 *     field_convert.php  -> convert()           change type / multilingualism afterwards
 *     ajax_*.php         -> updateVisibility()  visibility ticks in the list
 *
 * ANLEGEN IN ZWEI SCHRITTEN
 * --------------------
 * Unlike in the other modules, "New" does not lead directly to the form. create()
 * first shows the type selection, because the type determines the form and it cannot be changed via the
 * mask any more. The selected type ends up as &function=create&data[type]=…
 * in the URL, edit() builds the matching form from it.
 *
 * CHANGE TYPE ONLY EXPLICITLY
 * --------------------
 * Only convert() changes type and multilingualism of an existing field - via the
 * button "Change type" of the detail view: first the selection of the possible targets
 * (Field::conversionTargets(), with a note where content can be lost), then
 * a confirmation, then Field::convert(). The switch "multilingual" in the
 * input mask is therefore only a display for existing fields.
 *
 * THE FORM IS DIFFERENT FOR EACH TYPE
 * --------------------
 * _editForm() first selects an HTML template (area.field_form_*) and then shows block
 * by block the inputs that match type and display_type: prefix/suffix only for
 * text and number fields, target areas only for relations and pointers, MIME types only
 * for upload, converters only for thumbnails. What does not fit is not rendered -
 * the associated placeholders are removed at the end by form(removePlaceholders: true).
 *
 * META-FELDER
 * --------------------
 * If a field carries the name of a fixed column from Cms::$schema (status_id, online_at,
 * template_id, …), it counts as a meta field: name and type are fixed and are only
 * displayed (formStatic + formHidden). The editor can only set the label,
 * display and visibility on it - the content lies in `record` itself and
 * not in a data_* table.
 *
 * VISIBILITY AS A PSEUDO FIELD
 * --------------------
 * In the database these are three bit columns (visibility_input / _search / _list). In the
 * form it is ONE checkbox group named 'visibility'. _visibilitySerialize() makes
 * the list from the three columns, _visibilityUnserialize() turns it back into three
 * values when saving. The special case 'fulltext' has only the search visibility and bypasses the
 * Umwandlung ganz.
 *
 * @see Camindo\Core\Modules\Area\Field       Dataset/model of the table `field`
 * @see Camindo\Core\Modules\Area\AreaBackend Backend of the associated area
 * @see Camindo\Core\Backend                    $displayTypes, $validationFlags, formField()
 */
namespace Camindo\Core\Modules\Area;

use Camindo\Core\Backend;
use Camindo\Core\System;
use Camindo\Core\Database;
use Camindo\Core\Cms;
use Camindo\Core\Crypt;
use Camindo\Core\InstanceCache;
use Camindo\Core\Modules\Area\Area;
use Camindo\Core\Modules\Area\Field;

abstract class FieldBackend {
    protected int $id = 0;
    protected int $areaId = 0;
    protected ?array $data = [];
    protected ?array $areaData = [];
    protected string $function = '';

    // The three visibilities; in the form a checkbox group, in the table three
    // bit columns visibility_{input|search|list}. Also serves as an allowlist in
    // updateVisibility().
    protected array $visibilityFields = ['input', 'search', 'list'];

    // Links per deselected target area (area_id => count) - filled by
    // _editSave(), shown by _editConfirmLinksForm()
    protected array $linkCounts = [];

    protected array $fieldSchema = [];  // Schema of the table `field` (the field definition)
    protected array $recordSchema = []; // Schema of the table `record` (the meta fields)

    public function __construct(
        protected Backend $backend,
        protected System $system,
        protected Database $db,
        protected Cms $cms,
        protected Crypt $crypt,
        protected Area $area,
        protected Field $field,
        protected InstanceCache $instanceCache,
        protected AreaBackend $areaBackend,     // Note "full text outdated" (field list)
    )
    {}

    /**
     * Headline with field label
     */
    protected function _headline(): void
    {
        // Icon
        $icon = $this->backend->template('area.icon_field');

        // Label
        $label = ($this->function === 'delete')
            ? ($this->data['label'] ?? '')
            : $this->cms->snippet('area.headline_field_info');

        // H1
        echo $this->backend->template('headline1', [
            '{text}' => $icon . ' ' . $label,
        ]);
    }

    /**
     * Load area data and return the area label as the header line.
     *
     * Returns HTML instead of outputting it - the caller decides whether and where it
     * belongs. If loading fails, $this->function is additionally set to 'skip'
     * : edit() then skips the form and actions and only shows the error.
     */
    protected function _loadArea(): string
    {
        if ($this->areaData = $this->area->load($this->areaId)) {
            // Bereichslabel ausgeben
            $areaLabel = $this->cms->textOrSnippet($this->areaData['label']);
            return $this->backend->template('area.prefix_content_area', [
                '{text}' => str_replace('{area_label}', $areaLabel, $this->cms->snippet('area.headline_area_label'))
            ]);
        }
        else {
            // Area not found
            $this->function = 'skip';
            $this->areaData = [];
            return $this->backend->template('alert_danger', [
                '{text}' => '{#area.error_area_not_found}'
            ]);
        }
    }

    protected function _extensionFile(string $extension, string $script): ?string
    {
        $dir = $this->cms->componentPath('extensions', $extension);
        if ($dir === null) return null;
        $file = $dir . '/backend/' . $script;
        return is_file($file) ? $file : null;
    }
}