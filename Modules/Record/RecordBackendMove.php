<?php
/**
 * Datensatz in einen anderen Inhaltsbereich verschieben (Erweiterung für
 * RecordBackend).
 *
 * Einstiegspunkt (Script in backend/):
 *
 *     record_move.php  -> move()
 *
 * Erreichbar über den Button "Verschieben" der Detailansicht (RecordBackendInfo) -
 * nicht aus einem Lookup-Fenster heraus (src_id > 0): dort geht es um das
 * Verknüpfen, nicht um den Datensatz selbst.
 *
 * ZWEI SCHRITTE, EIN SCRIPT
 * --------------------
 *     ohne target_area_id   Schritt 1: Zielbereich wählen. Die Auswahl schickt das
 *                           Formular gleich ab (record.record_select_area_id).
 *     mit target_area_id    Schritt 2: Felder gegenüberstellen - links die
 *                           Eingabefelder des Quellbereichs, rechts je Feld eine
 *                           Auswahl der passenden Zielfelder (MoveTrait::
 *                           moveTargets()), "Löschen" voran; vorgewählt ist das
 *                           gleichnamige Zielfeld.
 *     function=move         Verschieben in zwei Schritten (MoveTrait):
 *                           movePrepare() legt die Arbeitskopie im Zielbereich an -
 *                           scheitert schon das (Abbildung, Sperre, Kinder),
 *                           erscheint die Gegenüberstellung mit den Fehlern erneut.
 *                           moveSave() checkt sie ein; danach geht es zur
 *                           Detailansicht des Datensatzes, die nun zum Zielbereich
 *                           gehört.
 *
 * SCHEITERT DIE PRÜFUNG IM ZIELBEREICH
 * --------------------
 * Der Zielbereich kann Felder haben, die der Herkunftsbereich nicht kennt - ein
 * Pflichtfeld ohne Abbildung, eine Verknüpfung in einen anderen Bereich, strengere
 * Grenzwerte. Statt der Gegenüberstellung erscheint dann die EINGABEMASKE DES
 * ZIELBEREICHS mit der Arbeitskopie und den Fehlern an ihren Feldern
 * (RecordBackendEdit::_editWorkingCopyForm()): der Redakteur ergänzt, was fehlt.
 * Speichern ist der Check-in (record_edit.php, function=save - die Kopie trägt die
 * area_id des Zielbereichs), Abbrechen verwirft die Kopie, und der Datensatz bleibt
 * unverändert im bisherigen Bereich. Bis dahin ist er wie bei jeder Bearbeitung
 * gesperrt.
 *
 * Die Fehler der Abbildung tragen den Namen des Quellfeldes (Gegenüberstellung),
 * die der Prüfung den des ZIELFELDES, mehrsprachig "name.{language_id}" - so, wie
 * die Eingabemaske sie an ihren Feldern zeigt.
 *
 * @see Camindo\Core\MoveTrait                            die eigentliche Arbeit
 * @see Camindo\Core\Modules\Record\RecordBackendInfo     der Button dazu
 * @see Camindo\Core\Modules\Record\RecordBackendEdit     die Eingabemaske des Zielbereichs
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\Modules\Area\Area;

class RecordBackendMove extends RecordBackendEdit {

    /**
     * Verschieben-Maske (backend/record_move.php).
     *
     * Request: id, area_id, target_area_id (auch als data[target_area_id] aus der
     *          Bereichsauswahl), mapping[quellfeld] = zielfeld, function ('move')
     */
    public function move(): void
    {
        echo $this->backend->template('container_header');

        $this->function = 'info';
        $this->_resolveParams();
        if ($this->function !== 'info') {
            $this->errors();
            echo $this->backend->template('container_footer');
            return;
        }

        // Bread crum navi
        $this->backend->setNaviLabel('#button_move');

        // Datensatz laden - produktiv, nicht gelöscht - und die Rechte der
        // Benutzergruppe: Schreiben + Löschen im Quellbereich, und alle
        // Verknüpfungsfelder müssen lesbare Zielbereiche haben (12-group-rights.md,
        // RecordService::loadForAction(); den Zielbereich prüft MoveTrait::movePrepare())
        if (!$this->data = $this->service->loadForAction($this->id, 'move')) {
            $this->errors();
            echo $this->backend->template('container_footer');
            return;
        }
        $this->areaId = (int)$this->data['area_id'];
        $this->areaData = $this->backend->areaData($this->areaId);
        $this->params = 'area_id=' . $this->areaId;

        // Bereichslabel, H1, Datensatz
        // --------------------
        echo $this->backend->template('record.prefix_content_area', [
            '{text}' => str_replace('{area_label}', $this->cms->textOrSnippet((string)$this->areaData['label']), $this->cms->snippet('area.headline_area_label'))
        ]);
        echo $this->backend->template('headline1', [
            '{text}' => '{#record.headline_record_move}'
        ]);
        echo $this->backend->template('p', [
            '{text}' => $this->backend->teaser('record', $this->id)
        ]);

        // Parameter der Schritte
        // --------------------
        $function = (string)($_REQUEST['function'] ?? '');
        $targetAreaId = (int)($_REQUEST['data']['target_area_id'] ?? $_REQUEST['target_area_id'] ?? 0);
        $mapping = (array)($_REQUEST['mapping'] ?? []);

        // Verschieben
        // --------------------
        if ($function === 'move' && $targetAreaId > 0) {
            $tmpId = $this->record->movePrepare($this->id, $targetAreaId, $mapping);
            if ($tmpId && $this->record->moveSave($tmpId)) {
                $this->backend->balloon('success', $this->cms->snippet('record.message_record_moved'));
                $this->backend->script(
                    "cApp.layers.invalidate();"
                    ."cApp.layers.replace('Record/record_info.php?area_id={$targetAreaId}&id={$this->id}');"
                );
                echo $this->backend->template('container_footer');
                return;
            }
            if ($tmpId) {
                // Prüfung im Zielbereich gescheitert: die Arbeitskopie steht im
                // Zielbereich, ihre Eingabemaske zeigt die Fehler (Klassenkommentar)
                echo $this->backend->template('alert_warning', [
                    '{text}' => strtr($this->cms->snippet('record.warning_move_edit'), [
                        '{area}' => htmlspecialchars($this->cms->textOrSnippet((string)($this->backend->areaData($targetAreaId)['label'] ?? $targetAreaId))),
                    ]),
                ]);
                $this->errors();
                if ($this->_editWorkingCopyForm($tmpId)) {
                    echo $this->backend->template('container_footer');
                    return;
                }
                // Kopie nicht zugänglich (sollte nie eintreten): verwerfen, zurück zur Auswahl
                $this->record->cancel($tmpId);
                $this->errors();
            }
            else {
                // Abbildung, Sperre, Kinder: Gegenüberstellung mit den Fehlern erneut
                $this->errors();
            }
        }

        // Schritt 2: Felder gegenüberstellen
        // --------------------
        $targetData = ($targetAreaId > 0 && $targetAreaId !== $this->areaId)
            ? $this->backend->areaData($targetAreaId)
            : null;
        if ($targetData && in_array((string)$targetData['type'], Area::CONTENT_TYPES, true)) {
            $this->_moveMappingForm($targetAreaId, $targetData, $mapping);
        }
        else {
            // Schritt 1: Zielbereich wählen
            $this->_moveAreaForm();
        }

        echo $this->backend->template('container_footer');
    }

    /**
     * Schritt 1: Zielbereich wählen.
     *
     * Alle Datensatz-Bereiche (Area::CONTENT_TYPES) außer dem eigenen, eingerückt
     * nach `depth`, in Baumreihenfolge. Bereiche einer anderen Instanz bekommen deren
     * Namen als Zusatz - der Wechsel der Instanz ist möglich, aber folgenreich
     * (siehe MoveTrait). Die Auswahl schickt das Formular sofort ab.
     */
    private function _moveAreaForm(): void
    {
        echo '<form action="Record/record_move.php" method="post">';
        $this->backend->formInit('record.record_move_area_form');

        $sourceInstanceId = (int)($this->areaData['instance_id'] ?? 0);
        $placeholders = implode(',', array_fill(0, count(Area::CONTENT_TYPES), '?'));
        $this->db->query(
            "SELECT `area_id`, `instance_id`, `depth`, `label` FROM `area`"
            . " WHERE `type` IN ($placeholders) AND `deleted` = 0 AND `area_id` != ?"
            . $this->backend->areaAccessSql(writable:true)
            . " ORDER BY `lft` ASC",
            str_repeat('s', count(Area::CONTENT_TYPES)) . 'i',
            array_merge(Area::CONTENT_TYPES, [$this->areaId])
        );
        $areaOptions = [];
        while ($areaData = $this->db->fetch()) {
            $label = str_repeat('&nbsp; ', (int)$areaData['depth'] * 2)
                . $this->cms->textOrSnippet((string)$areaData['label']);
            $instanceId = (int)$areaData['instance_id'];
            if ($instanceId !== $sourceInstanceId) {
                $instanceLabel = (string)($this->instanceCache->instance($instanceId)['label'] ?? $instanceId);
                $label .= ' (' . $this->cms->textOrSnippet($instanceLabel) . ')';
            }
            $areaOptions[(string)$areaData['area_id']] = $label;
        }
        $this->backend->formField($this->record, [
            'name' => 'target_area_id',
            'value' => 0,
            'id' => $this->id,
            'colDef' => [
                'type' => 'integer',
                'display_type' => 'select',
                'label' => '#record.headline_move_target'
            ],
            'options' => $areaOptions,
            'template' => 'record.record_select_area_id',
            'selectPlease' => true
        ]);

        $this->backend->formHidden('id', $this->id);
        $this->backend->formHidden('area_id', $this->areaId);
        echo $this->backend->form();
        echo '</form>';
    }

    /**
     * Schritt 2: Felder des Quellbereichs den Feldern des Zielbereichs zuordnen.
     *
     * Je Quellfeld eine Zeile: links Beschriftung, Name, Typ (und ob mehrsprachig),
     * rechts die Auswahl - "Löschen" zuerst, dann die passenden Zielfelder
     * (MoveTrait::moveTargets()). Vorgewählt ist das gleichnamige Zielfeld, nach einem
     * fehlgeschlagenen Versuch die Auswahl des Redakteurs ($mapping).
     *
     * Fehler: unter dem Quellfeld die der Abbildung, unter dem gewählten Zielfeld die
     * der Eingabeprüfung im Zielbereich (mehrsprachig je Sprache) - beide erscheinen
     * in der Zeile des Quellfeldes.
     *
     * @param int   $targetAreaId
     * @param array $targetData    Backend::areaData() des Zielbereichs
     * @param array $mapping       Auswahl aus dem Request (leer beim ersten Aufruf)
     */
    private function _moveMappingForm(int $targetAreaId, array $targetData, array $mapping): void
    {
        $schemaSource = $this->record->virtualSchema($this->areaId, false);
        $schemaTarget = $this->record->virtualSchema($targetAreaId, false);
        $sourceFields = $this->record->moveFields($schemaSource);
        $targetFields = $this->record->moveFields($schemaTarget);
        $errorFields  = $this->cms->getErrorFields();
        $fromRequest  = !empty($mapping);
        $defaults     = $this->record->moveDefaultMapping($sourceFields, $targetFields);

        $tplRow = $this->backend->template('record.record_move_row');
        $tableRows = '';
        foreach ($sourceFields as $name => $colDef) {
            $type = (string)($colDef['type'] ?? '');
            $targets = $this->record->moveTargets($colDef, $targetFields);

            // Vorauswahl
            if ($fromRequest) {
                $selected = (string)($mapping[$name] ?? '');
            }
            else {
                $selected = $defaults[$name] ?? '';
            }

            // Optionen: Löschen, dann die Zielfelder
            $options = '<option value="">' . htmlspecialchars($this->cms->snippet('record.option_move_delete')) . "</option>\n";
            foreach ($targets as $targetName => $targetDef) {
                $optionLabel = $this->cms->textOrSnippet((string)($targetDef['label'] ?? $targetName))
                    . ' (' . $targetName . ')';
                $isSelected = ($selected === (string)$targetName) ? ' selected' : '';
                $options .= '<option value="' . htmlspecialchars((string)$targetName) . '"' . $isSelected . '>'
                    . htmlspecialchars($optionLabel) . "</option>\n";
            }

            // Fehler dieser Zeile: Abbildung (Quellfeld) und Prüfung (Zielfeld, je Sprache)
            $errorTexts = [];
            foreach ($errorFields as $errorKey) {
                $errorKey = (string)$errorKey;
                if ($errorKey === $name
                    || ($selected !== '' && ($errorKey === $selected || str_starts_with($errorKey, $selected . '.')))
                ) {
                    $errorTexts[] = (string)($this->cms->errors[$errorKey]['text'] ?? '');
                }
            }
            $errorHtml = '';
            foreach (array_unique($errorTexts) as $text) {
                $errorHtml .= $this->backend->template('form_error', ['{text}' => $text]);
            }

            $multilingual = $this->cms->isMultilingualField($colDef)
                ? ' &middot; ' . $this->cms->snippet('record.label_move_multilingual')
                : '';
            $tableRows .= strtr($tplRow, [
                '{label}' => htmlspecialchars($this->cms->textOrSnippet((string)($colDef['label'] ?? $name))),
                '{name}' => htmlspecialchars((string)$name),
                '{type}' => htmlspecialchars($type),
                '{multilingual}' => $multilingual,
                '{options}' => $options,
                '{class}' => ($errorHtml !== '') ? 'c9-invalid' : '',
                '{error}' => $errorHtml,
            ]) . "\n";
        }

        // Hinweis bei Instanzwechsel
        $instanceWarning = '';
        if ((int)($targetData['instance_id'] ?? 0) !== (int)($this->areaData['instance_id'] ?? 0)) {
            $instanceWarning = $this->backend->template('alert_warning', [
                '{text}' => '{#record.warning_move_instance}'
            ]);
        }

        echo '<form action="Record/record_move.php" method="post">';
        $this->backend->formInit('record.record_move_form', [
            '{instance_warning}' => $instanceWarning,
            '{source_area}' => $this->backend->teaser('area', $this->areaId, false),
            '{target_area}' => $this->backend->teaser('area', $targetAreaId, false),
            '{table_rows}' => $tableRows,
            '{params}' => $this->params,
            '{id}' => $this->id,
        ]);
        $this->backend->formHidden('id', $this->id);
        $this->backend->formHidden('area_id', $this->areaId);
        $this->backend->formHidden('target_area_id', $targetAreaId);
        $this->backend->formHidden('function', 'move');
        echo $this->backend->form('record.form_move_cancel', [
            '{params}' => $this->params,
            '{id}' => $this->id,
        ]);
        echo '</form>';
    }
}
