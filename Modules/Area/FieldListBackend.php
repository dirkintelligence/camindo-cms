<?php
namespace Camindo\Core\Modules\Area;

class FieldListBackend extends FieldBackend {

    /**
     * Feldliste eines Inhaltsbereichs (backend/field_list.php).
     *
     * Sortiert nach `position` - das ist zugleich die Reihenfolge im Eingabeformular
     * des Datensatzes. Die drei Sichtbarkeits-Spalten sind anklickbar und gehen über
     * updateVisibility() per Ajax zurück, ohne die Seite neu zu laden.
     *
     * Request: area_id
     */
    public function list()
    {
        // $this->id = (int)($_REQUEST['id'] ?? 0);
        $this->areaId = (int)($_REQUEST['area_id'] ?? 0);

        // Seitenelemente
        // --------------------

        // Bread crum navi
        $this->backend->setNaviLabel('#area.headline_field_list');
        
        // Container header
        echo $this->backend->template('container_header');

        // Zugehörigen Bereich laden
        // (gibt Bereichsname aus)
        echo $this->_loadArea();

        // H1
        echo $this->backend->template('headline1', [
            '{text}' => '{#area.headline_field_list}'
        ]);
        
        // Tabs
        $this->backend->tabBarReset();
        $this->backend->tabAdd('#area.headline_settings', "cApp.layers.replace('Area/area_info.php?id=$this->areaId')");
        $this->backend->tabAdd('#area.headline_field_list', '', true);
        $this->backend->tabAdd('#area.headline_access', "cApp.layers.replace('Area/area_access.php?id={$this->areaId}')");
        if ($this->backend->access()->module('Record')) {
            $this->backend->tabAdd('#area.headline_records', "cApp.layers.replace('Record/record_list.php?area_id={$this->areaId}')");
        }
        $this->backend->auditTabAdd("Area/area_info.php?id={$this->areaId}");
        echo $this->backend->tabBar();

        // Buttons
        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_create_primary', "cApp.layers.add('Area/field_create.php?area_id={$this->areaId}');");
        $this->backend->buttonAdd('button_refresh', "cApp.layers.refresh();");
        echo $this->backend->buttonBar();

        echo $this->areaBackend->fulltextNotice($this->areaId, 'fields');

        $f = $this->cms->select([
            'limit' => -1,
            'sort' => 'position ASC',
            'filtertext' => 'true',
            'sql' => '`area_id`=' . $this->areaId,
        ], $this->field);

        // 2. Results
        if ($this->db->num_rows > 0) {

            // Der Tabellenkopf ist fix
            // und daher in das Template area.field_table_with_head integriert

            // Tabellen-Zeilen
            $tplTableRow = $this->backend->template('area.field_table_row');
            $tplFormCheck = $this->backend->template('list_checkbox');
            $tplCheckBoxDisabled = $this->backend->template('list_checkbox_disabled');
            
            $tableRows = '';
            while ($data = $this->field->fetch()) {
                $fieldId = (int)$data['field_id'];
                $type = $data['type'];
                $displayType = $data['display_type'];

                // default field: Sichtbarkeit 'list' ist immer gegeben
                $isDefaultField = (bool)($data['default_field'] ?? 0);

                $tableRow = $tplTableRow;

                // name | label | type | subtype | visibility
                $label = $this->cms->textOrSnippet($data['label']);
                $tableRow = strtr($tplTableRow, [
                    '{data_id}' => $data['field_id'],
                    '{data_name}' => $data['name'],
                    '{data_label}' => $label . ($isDefaultField ? '*' : ''),
                    '{data_type}' => $type,
                    '{data_subtype}' => $data['subtype'],
                ]) ."\n";   
                
                // visibility input | search | list
                foreach ($this->visibilityFields as $key) {
                    if ($type == 'fulltext') {
                        // Volltextsuchfeld: Keine Sichtbarkeit in Liste und Eingabemaske
                        if ($key === 'list') {
                            $tableRow = str_replace('{visibility_list}','',$tableRow);
                            continue;
                        }
                        else if ($key === 'input') {
                            $tableRow = str_replace('{visibility_input}','',$tableRow);
                            continue;
                        }
                    }
                    if ($key === 'list' && $isDefaultField) {
                        // Default-Feld: Immer sichtbar in der Liste
                        $formCheck = strtr($tplCheckBoxDisabled, [
                            "{checked}" => ' checked'
                        ]);
                        $tableRow = str_replace('{visibility_list}', $formCheck, $tableRow);
                    }
                    elseif ($displayType === 'thumbnail'
                        && in_array($key, ['input','search'], true)
                    ) {
                        // Thumbnail: Nie sichtbar in der Eingabeamske und Liste
                        $formCheck = strtr($tplCheckBoxDisabled, [
                            "{checked}" => ''
                        ]);
                        $tableRow = str_replace('{visibility_' . $key . '}', $formCheck, $tableRow);
                    }
                    else {
                        // Sichtbarkeit mittels Checkbox einstellen
                        $name = 'visibility_' . $key;
                        $value = (int) ($data[$name] ?? 0);
                        $changeValue = $value ? 0 : 1;
                        $checked = $value ? ' checked' : '';
                        $clickHandler = "cApp.ui.visibility(this,$fieldId,'$key',$changeValue);event.stopPropagation()";
                        $formCheck = strtr($tplFormCheck, [
                            "{onclick}" => $clickHandler,
                            "{checked}" => $checked
                        ]);
                        $tableRow = str_replace('{'.$name.'}', $formCheck, $tableRow);
                    }
                }
                $tableRows .= $tableRow;
            }

            // Render table
            echo $this->backend->template('area.field_table_with_head', [
                '{table_rows}' => $tableRows
            ]);

            // Context menu
            echo $this->backend->template('area.field_context_menu');

            // Pagination bottom
            echo $this->backend->paginationBottom();
        }

        // Container Footer
        echo $this->backend->template('container_footer');
    }
}