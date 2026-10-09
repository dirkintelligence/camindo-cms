<?php
namespace Camindo\Core\Modules\Area;

class FieldInfoBackend extends FieldBackend {

    /**
     * Detailansicht eines Feldes (backend/field_info.php).
     *
     * Shows only what makes sense for the respective type - the same case distinction
     * as in the form, only without input options.
     */
    public function info()
    {
        $id = (int)($_REQUEST['id'] ?? 0);

        // Seitenelemente
        // --------------------

        // Bread crum navi
        $this->backend->setNaviLabel('#button_info');

        // Container header
        echo $this->backend->template('container_header');

        // Daten laden
        if (!$data = $this->field->load($id)) {
            echo $this->backend->template('alert_danger', [
                '{text}' => $this->cms->errorMsg()
            ]);
            echo $this->backend->template('container_footer');
            exit;
        }
        $type = (string) ($data['type'] ?? 'text');

        // Load the associated area
        // (outputs the area name)
        $this->areaId = (int)($data['area_id'] ?? 0);
        echo $this->_loadArea();

        // H1 (Icon + Label)
        $this->_headline();

        // Buttons
        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_edit_primary', "cApp.layers.replace('Area/field_edit.php?id={$id}');");
        $this->backend->buttonAdd('button_copy', "cApp.layers.replace('Area/field_edit.php?id={$id}&function=copy');");
        $this->backend->buttonAdd('button_delete', "cApp.layers.replace('Area/field_edit.php?id={$id}&function=delete');");
        // Change type - only if there is a target for the type at all
        if (!empty($this->field->conversionTargets($data))) {
            $this->backend->buttonAdd('area.button_change_type', "cApp.layers.add('Area/field_convert.php?id={$id}');");
        }
        if (!empty($_REQUEST['back'])) {
            $this->backend->buttonAdd('button_back', "history.back()");
        }
        else {
            $this->backend->buttonAdd('button_close', "cApp.layers.close()");
        }
        echo $this->backend->buttonBar();

        // Tabs: Info | Changes (audit log, Backend::infoAuditTabs())
        if ($this->backend->infoAuditTabs("Area/field_info.php?id={$id}", 'field', $id)) return;

        // switch values
        $yes = $this->backend->template('icon_checked');
        $no = $this->backend->emptyPlaceholder;

        // --------------------
        // Basisdaten
        echo $this->backend->template('headline4', [
            '{text}' => '{#area.headline_field_info}'
        ]);

        // label
        $this->backend->infoAdd($this->field, 'label', $data['label'] ?? '', ['primary']);

        // name
        $this->backend->infoAdd($this->field, 'name', $data['name'] ?? '', ['monospace']);

        // type + multilingual
        $typeHtml = $type . ' (' . $this->cms->snippet('area.label_field_type_' . $type) .')';
        if (!empty($data['multilingual'])) {
            $typeHtml .= ' ' . $this->backend->template('language.icon_multilingual_labeled');
        }
        $this->backend->infoAdd($this->field, 'type', $typeHtml, ['html']);

        // display_type
        $displayType = $data['display_type'] ?? '';
        $displayTypeLabel = $this->cms->snippet("area.label_display_type_{$displayType}");
        $this->backend->infoAdd($this->field, 'display_type', $displayTypeLabel);

        // Extension with its options (developer docs, chapter 08 (Components and extensions))
        $details = is_array($data['details'] ?? null) ? $data['details'] : (json_decode((string)($data['details'] ?? ''), true) ?: []);
        $extension = (string)($details['extension'] ?? '');
        if ($extension !== '') {
            $extSettings = $this->cms->extensions[$extension] ?? null;
            $label = $extSettings !== null
                ? htmlspecialchars($this->cms->textOrSnippet((string)($extSettings['label'] ?? $extension)))
                : htmlspecialchars($extension) . ' ' . $this->backend->template('note_warning', ['{text}' => $this->cms->snippet('component.state_inactive')]);
            $this->backend->infoAdd($this->field, 'details.extension', $label
                . $this->_extensionInfoHtml($extension, (array)($details['extension_options'] ?? [])), ['html']);
        }

        // default_field_id
        $this->backend->infoAdd($this->field, 'default_field', $data['default_field'] ? $yes : '', ['html', 'ifnotempty']);

        // custom_order ('link' is always hand-sorted - nothing to show)
        if (in_array($type, $this->cms->relationTypes, true) && !in_array($type, $this->cms->dependentTypes, true)) {
            $this->backend->infoAdd($this->field, 'custom_order', $data['custom_order'] ? $yes : $no, ['html']);
        }

        // details.lookup_detailed (Lookup-Felder)
        if (in_array($type, $this->cms->relationTypes, true) && $displayType === 'lookup') {
            $details = is_array($data['details'] ?? null) ? $data['details'] : (json_decode((string)($data['details'] ?? ''), true) ?: []);
            $this->backend->infoAdd($this->field, 'details.lookup_detailed', !empty($details['lookup_detailed']) ? $yes : $no, ['html']);
        }

        // note
        $this->backend->infoAdd($this->field, 'note', $data['note'] ?? '', ['ifnotempty']);

        // tooltip
        $this->backend->infoAdd($this->field, 'tooltip', $data['tooltip'] ?? '', ['ifnotempty']);

        if (in_array($type, $this->cms->textTypes, true)) {
            // height
            $height = (int)($data['height'] ?? 0);
            if ($height === 0) $height = '(auto)';
            else $height .= $this->cms->snippet('unit_rows');
            $this->backend->infoAdd($this->field, 'height', $height, ['ifnotempty']);
        }

        // prefix
        $this->backend->infoAdd($this->field, 'prefix', $data['prefix'] ?? '', ['ifnotempty']);

        // suffix
        $this->backend->infoAdd($this->field, 'suffix', $data['suffix'] ?? '', ['ifnotempty']);

        // visibility
        foreach (['input', 'search', 'list'] as $vType) {
            $this->backend->infoAdd($this->field, 'visibility_' . $vType, $data['visibility_' . $vType] ? $yes : $no, ['html']);
        }

        // default
        $defaultValue = $this->cms->_nl2br(htmlspecialchars($data['default'] ?? ''));
        $this->backend->infoAdd($this->field, 'default', $defaultValue, ['html', 'ifnotempty']);

        // min
        if (!empty($data['min'])) {
            $minVal = ($data['min_op'] ?? '>=') . ' ' . $data['min'];
            $this->backend->infoAdd($this->field, 'min', $minVal);
        }

        // max
        if (!empty($data['max'])) {
            $maxVal = ($data['max_op'] ?? '<=') . ' ' . $data['max'];
            $this->backend->infoAdd($this->field, 'max', $maxVal);
        }

        // pattern
        $this->backend->infoAdd($this->field, 'pattern', $data['pattern'] ?? '', ['ifnotempty']);

        // flags
        $flags = (array) $data['flags'] ?? [];
        if (!empty($flags)) {
            $flagsHtml = '';
            $checkmark = $this->backend->template('icon_checked');
            foreach ($flags as $key => $flag) {
                if (!empty($flagsHtml)) $flagsHtml .= "<br>";
                $flagsHtml .= $checkmark . ' ' . htmlspecialchars($this->cms->snippet('area.flag_' . $flag));
            }
            $this->backend->infoAdd($this->field, 'flags', $flagsHtml, ['ifnotempty', 'html']);
        }

        if (str_starts_with($type, 'relation')) {

            // rel_field_area
            $relAreaId = (int)($data['rel_area_id'] ?? 0);
            $relAreaLiked = $this->backend->teaser('area', $relAreaId)
                ?? $this->backend->template('alert_danger', [
                    '{text}' => '{#area.error_area_not_found}'
                ]);
            $this->backend->infoAdd($this->field, 'rel_area_id', $relAreaLiked, ['html']);

            // rel_field_id
            $relFieldId = (int) $data['rel_field_id'];
            $relFieldLinked = $this->backend->teaser('field', $relFieldId);
            $this->backend->infoAdd($this->field, 'rel_field_id', $relFieldLinked, ['ifnotempty', 'html']);
        }

        if (in_array($type, $this->cms->multiAreaTypes, true)) {

            // rel_area_ids (pointer, link)
            // List linked target areas
            $sql = "SELECT `a`.`area_id` FROM `rel_field_area` `r` LEFT JOIN `area` `a`"
            ." ON `r`.`area_id` = `a`.`area_id`"
            ." WHERE `r`.`field_id` =? ORDER BY `a`.`position` ASC";
            $this->db->query($sql, 'i', [$id]);
            if ($this->db->num_rows > 0) {
                $items = [];
                while ($row = $this->db->fetch()) {
                    $items[] = $this->backend->teaser('area',$row['area_id']);
                }
                $listHtml = implode('<br>', $items);
            }
            else {
                $listHtml = $this->backend->template('alert_danger', [
                    '{text}' => '{#nothing_selected}'
                ]);
            }
            $this->backend->infoAdd($this->field, 'rel_area_ids', $listHtml, ['ifnotempty', 'html']);
        }

        if ($type === 'file') {
            $details = $data['details'] ?? [];
            if ($displayType == 'upload') {
                // media_types
                if (!empty($details['media_types'])) {
                    $mimeTypes = [];
                    foreach ($details['media_types'] as $mimeType) {
                        $mimeTypes[] = $this->cms->snippet('area.mime_type_'.$mimeType);
                    }
                    $mimeTypesHtml = $this->backend->template('ul_unstyled', [
                        '{items}' => '<li>' . implode ("</li>\n<li>", $mimeTypes) . "</li>\n"
                    ]);
                    $flags = ['html'];
                }
                else {
                    $mimeTypesHtml = '';
                    $flags = [];
                }
                $this->backend->infoAdd($this->field, 'details.media_types', $mimeTypesHtml, $flags);

                // media_types_incl
                $this->backend->infoAdd($this->field, 'details.media_types_incl', $details['media_types_incl'], ['not_empty']);
                
                // media_types_excl
                $this->backend->infoAdd($this->field, 'details.media_types_excl', $details['media_types_excl'], ['not_empty']);
            }
            elseif ($displayType == 'thumbnail') {

                // parent_field_id
                $parentFieldHtml = '';
                $flags = [];
                $parentFieldId = (int)$data['parent_field_id'];
                if (!empty($parentFieldId)) {
                    $parentFieldHtml = $this->backend->teaser('field', $parentFieldId);
                    $flags = ['html'];
                }
                $this->backend->infoAdd($this->field, 'parent_field_id', $parentFieldHtml, $flags);

                // converter_ids (in their order, hookOnLoaded() from rel_field_converter)
                $converters = [];
                foreach ($this->system->sanitizeIds((array)($data['converter_ids'] ?? []), true) as $converterId) {
                    $converters[] = $this->backend->teaser('converter', $converterId);
                }
                $this->backend->infoAdd($this->field, 'converter_ids', implode('<br>', $converters), ['html']);
            }

            // user_auth / profile_area_ids (upload and thumbnail)
            $userAuth = !empty($details['user_auth']);
            $this->backend->infoAdd($this->field, 'details.user_auth',
                $userAuth ? $this->backend->template('icon_checked') : $this->backend->emptyPlaceholder,
                ['html']
            );
            if ($userAuth) {
                $items = [];
                foreach ($this->system->sanitizeIds((array)($details['profile_area_ids'] ?? []), true) as $profileAreaId) {
                    $items[] = $this->backend->teaser('area', (int)$profileAreaId);
                }
                $this->backend->infoAdd($this->field, 'details.profile_area_ids', implode('<br>', $items), ['html']);
            }
        }

        // field_id
        $this->backend->infoAdd($this->field, 'field_id', $id);

        // Tabelle rendern
        echo $this->backend->infoTable();

        echo $this->backend->template('container_footer');
    }

    /**
     * Options on the info page: {Extension}/backend/info.php, otherwise key: value.
     */
    private function _extensionInfoHtml(string $extension, array $options): string
    {
        if (!$options) return '';
        $file = $this->_extensionFile($extension, 'info.php');
        if ($file !== null) {
            return (static function (string $file, \Camindo\Core\Backend $backend, \Camindo\Core\Cms $cms, array $options): string {
                ob_start();
                include $file;
                return (string)ob_get_clean();
            })($file, $this->backend, $this->cms, $options);
        }
        $items = [];
        foreach ($options as $key => $value) {
            $items[] = htmlspecialchars((string)$key) . ': ' . htmlspecialchars(is_array($value) ? implode(', ', $value) : (string)$value);
        }
        return $this->backend->template('area.extension_options_plain', ['{html}' => implode('<br>', $items)]);
    }
}