<?php
namespace Camindo\Core\Modules\Area;

class FieldCreateBackend extends FieldBackend {

    /**
     * Schritt 1 beim Anlegen: Feldtyp auswählen (backend/field_create.php).
     *
     * Ein eigener Schritt, weil der Typ das gesamte Formular bestimmt und sich später
     * nicht mehr ändern lässt. Jeder Eintrag der Auswahl ist ein Link, der den Layer
     * durch field_edit.php mit function=create und dem gewählten Typ ersetzt.
     *
     * Ist der Bereich noch leer, hängt der Link zusätzlich data[default_field]=1 an -
     * das erste Feld eines Bereichs wird ungefragt zum Titelfeld. Sonst hätte der
     * Bereich zunächst keins, und die Datensatzliste zeigte nur record_ids.
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
            // Nicht gefunden
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
        // Wenn der Bereich noch kein Eingabefeld besitzt: default_field setzen
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