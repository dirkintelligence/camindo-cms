<?php
namespace Camindo\Core\Modules\Area;

class FieldConvertBackend extends FieldBackend {

    /**
     * Change the type or multilingualism of an existing field (backend/field_convert.php).
     *
     * Three states via $_REQUEST['function']:
     *
     *     ''         selection of the possible targets (Field::conversionTargets()) as
     *                cards as in create() - types on the left, language switch on the right.
     *                Targets where content may be lost carry the note.
     *     confirm    confirmation: current and new type, on possible data loss
     *                the warning, with open working copies of the area the note
     *                about them, then the button "Change type" (POST, function=convert).
     *     convert    Field::convert(); afterwards a balloon and back to the detail view.
     *                On an error the message appears above the selection.
     *
     * Request: id, function, type, multilingual
     */
    public function convert(): void
    {
        $this->id = (int)($_REQUEST['id'] ?? 0);
        $this->function = (string)($_REQUEST['function'] ?? '');
        $toType = preg_replace('![^a-z0-9_-]+!', '', (string)($_REQUEST['type'] ?? ''));
        $toMultilingual = (int)!empty($_REQUEST['multilingual']);

        // Bread crum navi
        $this->backend->setNaviLabel('#area.button_change_type');

        // Container header
        echo $this->backend->template('container_header');

        // Daten laden
        if (!$this->data = $this->field->loadLive($this->id)) {
            echo $this->backend->template('alert_danger', [
                '{text}' => $this->cms->errorMsg()
            ]);
            echo $this->backend->template('container_footer');
            exit;
        }
        $type = (string)($this->data['type'] ?? 'text');
        $multilingual = (int)!empty($this->data['multilingual']);

        // Load the associated area (outputs the area name)
        $this->areaId = (int)($this->data['area_id'] ?? 0);
        echo $this->_loadArea();

        // H1 + Feld
        echo $this->backend->template('headline1', [
            '{text}' => $this->backend->template('area.icon_field') . ' {#area.headline_field_convert}',
        ]);
        echo $this->backend->template('p', [
            '{text}' => $this->backend->teaser('field', $this->id)
                . ' <span class="c9-grey-50">(' . htmlspecialchars((string)$this->data['name']) . ')</span>'
        ]);

        $targets = $this->field->conversionTargets($this->data);
        $currentHtml = $this->_convertLabel($type, $multilingual);

        // Umwandeln
        // --------------------
        if ($this->function === 'convert') {
            if ($this->field->convert($this->id, $toType, $toMultilingual)) {
                echo $this->backend->template('container_footer');
                $this->backend->balloon('success', $this->cms->snippet('area.message_field_converted'));
                $this->backend->script(
                    "cApp.layers.invalidate();"
                    ."cApp.layers.close();"
                );
                return;
            }
            echo $this->backend->template('alert_danger', [
                '{text}' => $this->cms->errorMsg()
            ]);
            $this->function = '';
        }

        // Confirmation
        // --------------------
        if ($this->function === 'confirm') {
            $target = null;
            foreach ($targets as $candidate) {
                if ($candidate['type'] === $toType && (int)$candidate['multilingual'] === $toMultilingual) {
                    $target = $candidate;
                    break;
                }
            }
            if ($target === null) {
                echo $this->backend->template('alert_danger', [
                    '{text}' => '{#area.error_field_convert_not_allowed}'
                ]);
                $this->function = '';
            }
            else {
                $warning = '';
                if (!empty($target['lossy'])) {
                    $warning = $this->backend->template('alert_warning', [
                        '{text}' => '{#area.warning_field_convert_lossy}'
                    ]);
                }
                $warning .= $this->_workingCopiesWarning();
                echo '<form action="Area/field_convert.php" method="post">';
                $this->backend->formInit('area.field_convert_confirm_form', [
                    '{current}' => $currentHtml,
                    '{target}' => $this->_convertLabel($toType, $toMultilingual),
                    '{warning}' => $warning,
                    '{id}' => $this->id,
                ]);
                $this->backend->formHidden('id', $this->id);
                $this->backend->formHidden('type', $toType);
                $this->backend->formHidden('multilingual', $toMultilingual);
                $this->backend->formHidden('function', 'convert');
                echo $this->backend->form();
                echo '</form>';
                echo $this->backend->template('container_footer');
                return;
            }
        }

        // Selection of the targets
        // --------------------
        if (empty($targets)) {
            echo $this->backend->template('alert_warning', [
                '{text}' => '{#area.note_field_convert_no_targets}'
            ]);
            echo $this->backend->template('button_close', ['{onclick}' => 'cApp.layers.close()']);
            echo $this->backend->template('container_footer');
            return;
        }
        $optionsType = '';
        $optionsMultilingual = '';
        $tplOption = $this->backend->template('area.field_convert_option');
        foreach ($targets as $target) {
            $href = "cApp.layers.replace('Area/field_convert.php?id={$this->id}&function=confirm"
                . "&type={$target['type']}&multilingual={$target['multilingual']}');";
            $lossyNote = !empty($target['lossy'])
                ? $this->backend->template('area.field_convert_note_lossy')
                : '';
            if ($target['type'] !== $type) {
                // Typwechsel
                $optionsType .= strtr($tplOption, [
                    '{href}' => $href,
                    '{label}' => '{#area.label_field_type_' . $target['type'] . '}',
                    '{note}' => '{#area.note_field_type_' . $target['type'] . '}' . $lossyNote,
                ]);
            }
            else {
                // Sprachwechsel
                $key = $target['multilingual'] ? 'multilingual' : 'monolingual';
                $optionsMultilingual .= strtr($tplOption, [
                    '{href}' => $href,
                    '{label}' => '{#area.label_field_convert_' . $key . '}',
                    '{note}' => '{#area.note_field_convert_' . $key . '}' . $lossyNote,
                ]);
            }
        }
        if ($optionsType !== '') {
            $optionsType = $this->backend->template('headline3', ['{text}' => '{#area.headline_field_convert_type}']) . $optionsType;
        }
        if ($optionsMultilingual !== '') {
            $optionsMultilingual = $this->backend->template('headline3', ['{text}' => '{#area.headline_field_convert_language}']) . $optionsMultilingual;
        }
        echo $this->backend->template('area.field_convert_form', [
            '{current}' => $currentHtml,
            '{options_type}' => $optionsType,
            '{options_multilingual}' => $optionsMultilingual
        ]);

        echo $this->backend->template('container_footer');
    }

    /**
     * Note for convert(): records of the area that are currently being edited.
     * The conversion does not respect the edit lock; it takes the working copy along
     * (Record::retypeWorkingCopies()), but the open input mask still shows the
     * alten Feldtyp.
     */
    private function _workingCopiesWarning(): string
    {
        $rows = $this->field->workingCopies($this->areaId);
        if (empty($rows)) return '';
        $names = array_unique(array_filter(array_map(fn($row) => (string)$row['displayname'], $rows)));
        return $this->backend->template('alert_warning', [
            '{text}' => str_replace(
                '{count}',
                count($rows) . ($names ? ' (' . htmlspecialchars(implode(', ', $names)) . ')' : ''),
                $this->cms->snippet('area.warning_field_convert_locked')
            )
        ]);
    }

    /**
     * Label of a type including multilingualism for convert():
     * "chars (short text), multilingual" - the language suffix only for types that
     * can.
     */
    private function _convertLabel(string $type, int $multilingual): string
    {
        $label = htmlspecialchars($type) . ' (' . $this->cms->snippet('area.label_field_type_' . $type) . ')';
        if ($this->cms->isMultilingualField(['type' => $type, 'multilingual' => 1])) {
            $label .= ', ' . mb_strtolower($this->cms->snippet(
                $multilingual ? 'area.label_field_convert_multilingual' : 'area.label_field_convert_monolingual'
            ));
        }
        return $label;
    }
}