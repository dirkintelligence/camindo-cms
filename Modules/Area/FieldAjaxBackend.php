<?php
namespace Camindo\Core\Modules\Area;

class FieldAjaxBackend extends FieldBackend {

    /**
     * Ajax: toggle one of the three visibilities.
     *
     * It does not answer with a page, but with exactly the checkbox that was clicked -
     * the JavaScript replaces the clicked element with it. In the onclick of the
     * new checkbox the inverse value is already present, the next click switches
     * back.
     *
     * $name is checked against $visibilityFields before it becomes a column name -
     * without this allowlist a request value would end up in the field name of save().
     *
     * Request: id, name (input|search|list), value (0|1) - via POST, the method
     * writes (developer docs, chapter 14 (Security: web)).
     */
    public function updateVisibility():void
    {
        if (!$this->backend->requirePost()) {
            echo $this->backend->access()->deniedHtml();
            return;
        }

        // Parameter
        $id = (int) ($_REQUEST['id'] ?? 0);
        $name = (string) ($_REQUEST['name'] ?? '');
        $value = (int) (bool) ($_REQUEST['value'] ?? 0);

        // Check parameters
        if (empty($id) || !in_array($name, $this->visibilityFields, true)) {
            echo $this->backend->template('check_error');
            exit;
        }

        // Daten updaten
        $fieldName = 'visibility_' . $name;
        $data = [$fieldName => (int)$value, 'field_id' => $id];
        if ($this->field->save(
            [$fieldName],                // fields
            $data,
        )) {
            // Balloon-Message ausgeben
            $this->backend->balloon('success', $this->cms->snippet('area.message_visibility_updated'));

            // Checkbox ausgeben
            $changeValue = $value ? 0 : 1;
            echo $this->backend->template('list_checkbox', [
                '{onclick}' => "cApp.ui.visibility(this,$id,'$name',$changeValue);event.stopPropagation()",
                '{checked}' => $value ? ' checked' : ''
            ]);
        }
        else {
            echo $this->backend->template('check_error');
            echo $this->cms->errorMsg();
        }
    }
}