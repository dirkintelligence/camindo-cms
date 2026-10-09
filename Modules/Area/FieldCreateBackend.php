<?php
namespace Camindo\Core\Modules\Area;

class FieldCreateBackend extends FieldBackend {

    /**
     * Step 1 when creating: select field type (backend/field_create.php).
     *
     * A separate step, because the type determines the entire form and cannot be changed later.
     * Each entry of the selection is a link that replaces the layer
     * with field_edit.php with function=create and the selected type.
     *
     * If the area is still empty, the link additionally appends data[default_field]=1 -
     * the first field of an area becomes the title field without asking. Otherwise the
     * area would initially have none, and the record list would only show record_ids.
     *
     * Request: area_id
     */
    public function create():void {
        // Seiten-Parameter
        $this->areaId = (int)($_REQUEST['area_id'] ?? 0);

        // Bread crum navi
        $this->backend->setNaviLabel('#button_create');
        
        // Container header
        echo $this->backend->template('container_header');

        echo '<form action="Area/field_edit.php" method="post">';

        // Bereich laden
        if (!$this->areaData = $this->area->load($this->areaId)) {
            // Not found
            echo $this->backend->template('alert_danger', [
                '{text}' => '{#area.error_area_not_found}'
            ]);
            echo $this->backend->template('container_footer');
            exit;
        }

        // Area-Label anzeigen
        echo $this->backend->template('area.prefix_content_area', [
            '{text}' => str_replace('{area_label}', $this->areaData['label'], $this->cms->snippet('area.headline_area_label'))
        ]);

        // Formular
        $linkPrefix = "cApp.layers.replace('Area/field_edit.php?area_id={$this->areaId}&function=create";
        $linkSuffix = "');";
        // If the area does not yet have an input field: set default_field
        if ($this->area->fieldsNumber($this->areaId) < 1) {
            $linkPrefix .= '&data[default_field]=1';
        }
        $this->backend->formInit('area.field_create_form', [
            '{link_prefix}' => $linkPrefix,
            '{link_suffix}' => $linkSuffix
        ]);
        $this->backend->formHidden('area_id', $this->areaId);
        echo $this->backend->form();

        echo '</form>';

        echo $this->backend->template('container_footer');
    }
}