<?php
namespace Camindo\Core\Modules\Area;

class FieldCreateBackend extends FieldBackend {

    /** Cards with a fixed field name (section "Meta fields" of field_create_form.html) */
    private const META_CARDS = ['online_at', 'offline_at', 'template_id', 'slug', 'status_id', 'needs_review', 'parent_id', 'position'];

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
     * The cards of the section "Meta fields" (fixed names such as online_at or slug) are
     * grayed out and not clickable if the area already uses the name - also by a field
     * in the trash, which keeps its name (Field::hookOnSave()). The card names the reason
     * in words: "Already present" or, with the trash symbol, "In the trash". They stay
     * visible.
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
        $replace = [
            '{link_prefix}' => $linkPrefix,
            '{link_suffix}' => $linkSuffix
        ];
        // name => true if only a field in the trash carries it
        $used = [];
        $this->db->query("SELECT `name`, MIN(`deleted`) AS `trash` FROM `field` WHERE `area_id` = ? GROUP BY `name`", 'i', [$this->areaId]);
        foreach ($this->db->fetchAll() as $row) {
            $used[(string)$row['name']] = (bool)(int)$row['trash'];
        }
        // The reason is always written out, the symbol alone would not explain it
        $hint = fn(string $icon, string $snippet) => '<div class="card-state mt-1"><span class="icon" aria-hidden="true">'
            . $icon . '</span> ' . htmlspecialchars($this->cms->snippet($snippet)) . '</div>';
        $hints = [false => $hint('check', 'area.note_field_meta_in_use'), true => $hint('delete', 'area.note_field_meta_in_trash')];
        foreach (self::META_CARDS as $name) {
            $replace['{card_' . $name . '}'] = isset($used[$name])
                ? 'class="btn btn-card disabled" aria-disabled="true" tabindex="-1"'
                : 'class="btn btn-card"';
            $replace['{state_' . $name . '}'] = isset($used[$name]) ? $hints[$used[$name]] : '';
        }
        $this->backend->formInit('area.field_create_form', $replace);
        $this->backend->formHidden('area_id', $this->areaId);
        echo $this->backend->form();

        echo '</form>';

        echo $this->backend->template('container_footer');
    }
}