<?php
namespace Camindo\Core\Modules\Area;

class FieldAjaxBackend extends FieldBackend {

    /**
     * Ajax: eine der drei Sichtbarkeiten umschalten.
     *
     * Antwortet nicht mit einer Seite, sondern mit genau der Checkbox, die geklickt
     * wurde - das Javascript ersetzt damit das angeklickte Element. Im onclick der
     * neuen Checkbox steht bereits der umgekehrte Wert, der nächste Klick schaltet
     * also zurück.
     *
     * $name wird gegen $visibilityFields geprüft, bevor daraus ein Spaltenname wird -
     * ohne diese Weißliste stünde ein Requestwert im Feldnamen von save().
     *
     * Request: id, name (input|search|list), value (0|1) - per POST, die Methode
     * schreibt (17-csrf.md §2.7).
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

        // Parameter prüfen
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