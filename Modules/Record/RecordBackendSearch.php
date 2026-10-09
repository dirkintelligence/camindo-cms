<?php
/**
 * Suchmaske und Suchausführung für Datensätze (Erweiterung für RecordBackend).
 *
 * Einstiegspunkte (Scripte in backend/):
 *
 *     record_search.php         -> search()           Maske bzw. Ausführung
 *     record_global_search.php  -> searchCrossArea()  leerer Zwischen-Layer
 *     ajax_slookup_preview.php  -> sLookupPreview()   Lookup-Vorschau aktualisieren
 *
 * DIE MASKE STEHT NICHT FEST
 * --------------------
 * Wie die Liste hat auch die Suchmaske keine festen Felder: gesucht werden kann nach
 * dem, was der Redakteur je Bereich mit visibility_search=1 gekennzeichnet hat. Dazu
 * kommen immer das Feld _url (record_id, Slug oder URL) und vier Zeitfelder (online_at, offline_at, created_at,
 * changed_at), die ans Ende gehängt werden, und immer das Volltext-Standardfeld
 * (fulltext, ohne Eingabefeld). Die globale Suche (ohne Bereich) bietet zusätzlich das
 * Default-Feld (_default) an - es heißt, wie `fulltext`, in jedem Bereich gleich.
 *
 * Ohne eindeutigen Bereich (globale Suche) erscheint zusätzlich eine Mehrfachauswahl
 * der Bereiche; bei einem Pointer-Aufruf ist sie auf dessen Zielbereiche eingegrenzt.
 *
 * DIE SUCHE RENDERT KEINE TREFFERLISTE
 * --------------------
 * _searchAction() schließt den Suchlayer und lädt den darunterliegenden Listenlayer
 * mit den Suchparametern in der URL neu - RecordBackendList wertet sie aus. Dadurch
 * funktionieren Sortierung, Paginierung und Filtertext danach wie sonst auch.
 *
 * VERKNÜPFUNGEN ALS SUCHKRITERIUM
 * --------------------
 * Nach einem Relations- oder Pointer-Feld sucht man nicht mit Text, sondern indem man
 * die gemeinten Datensätze auswählt. Dafür gibt es dasselbe Lookup-Verfahren wie im
 * Eingabeformular, nur mit dem Formularpräfix 'filter' statt 'data':
 *
 *     _sLookupInit()           baut die Vorschau beim ersten Aufbau der Maske
 *     sLookupPreview()         aktualisiert sie per Ajax nach einer Auswahl
 *     _sLookupPreviewCreate()  der gemeinsame Kern
 *
 * Dasselbe Verfahren trägt das Meta-Feld `parent_id`, wenn es als Lookup eingerichtet
 * ist. Anders als in der Eingabemaske (RecordBackendEdit, dort genau EIN Vaterdatensatz
 * und ein eigenes Feld) braucht es hier keine Sonderbehandlung: der Filter `parent_id`
 * sucht die Nachkommen der angegebenen Datensätze und verträgt mehrere Werte.
 *
 * SORTIERFELDER
 * --------------------
 * Die Auswahlliste "sortieren nach" wird nebenbei mitgebaut. Nicht jedes Feld taugt
 * dafür: nach 'relation'/'pointer' (n Ziele) lässt sich nicht sortieren, bei den
 * 1:1-Varianten wird über das Default-Feld des Ziels sortiert (name._default), bei
 * Dateifeldern über den Dateinamen (name.filename).
 *
 * @see Camindo\Core\Modules\Record\RecordBackend      gemeinsame Basis
 * @see Camindo\Core\Modules\Record\RecordBackendList  wertet die Suchparameter aus
 * @see docs/developers/05-select-und-filter.md                      Filter-Syntax
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\Modules\Area\Field;

class RecordBackendSearch extends RecordBackend {

    /**
     * Suche (backend/record_search.php).
     *
     * Ohne function die Maske, mit function=search die Ausführung.
     */
    public function search(): void
    {
        $function = (string)($_REQUEST['function'] ?? '');
        $this->_resolveParams();
        // Erstelle temporären Datensatz, um relations per Lookupfeld hunzufügen zu können

        if ($function == 'search') {
            $this->_searchAction();
        }
        else {
            $this->_searchForm();
        }
    }

    /**
     * Leerer Zwischen-Layer für die bereichsübergreifende Suche.
     *
     * Die Layer stapeln sich: Bereichsbaum, darüber die Trefferliste. Eine Suche über
     * alle Bereiche hat aber keinen Bereich, aus dem heraus sie geöffnet wäre - ohne
     * diesen Platzhalter läge die Trefferliste direkt auf dem Baum und würde ihn beim
     * Schließen mitnehmen. Der Layer enthält deshalb nichts außer einem
     * Schließen-Knopf.
     */
    public function searchCrossArea(): void
    {
        $this->backend->setNaviLabel('#record.headline_global_results');

        echo $this->backend->template('container_header');
        echo $this->backend->template('button_close', [
            '{onclick}' => 'cApp.layers.close();'
        ]);
        echo $this->backend->template('container_footer');
    }

    /**
     * Metafelder, die die globale Suche (ohne Bereich) über "weitere Felder"
     * temporär anbietet. (Ohne Bereich gibt es kein parent_id - und damit auch keine
     * Tiefe, die sich nur auf parent_id bezieht.)
     */
    private const GLOBAL_EXTRA_FIELDS = ['status_id', 'user_id', 'owner_id', 'instance_id', 'needs_review', 'template_id'];

    /** Unterfelder eines Upload-Feldes in der Suchmaske (Cms::buildFileExists()) */
    private const FILE_SUBFIELDS = ['width', 'height', 'size', 'mime_type', 'extension'];

    /**
     * Die Suchmaske.
     *
     * Aufbau von oben nach unten:
     *
     *  1. _url - immer da: record_id, Slug oder URL (RecordService::urlFilter())
     *  2. Bereichsauswahl - nur ohne eindeutigen Bereich; bei einem Pointer-Aufruf
     *     auf dessen Zielbereiche eingeschränkt
     *  3. die Felder mit visibility_search=1, in Schema-Reihenfolge
     *  4. Frontend-User (user_id) in Bereichen mit Frontend-Eingaben (ugc mit
     *     Profil-Bereichen); direkt hinter parent_id dessen Tiefe (filter[depth],
     *     relativ zum gewählten Vaterdatensatz wie bei CMS:SELECT)
     *  5. die vier Zeitfelder
     *  6. temporär hinzugefügte Felder (Klasse .warning, siehe unten)
     *  7. sortieren nach / Richtung, darunter die Auswahl "weitere Felder"
     *
     * searchField() statt formField(): Suchfelder sind nie Pflichtfelder und schreiben
     * nach filter[...] statt nach data[...].
     *
     * WEITERE FELDER
     * Unter dem Absende-Knopf lassen sich Felder hinzufügen, die sonst nicht in der
     * Maske stehen: im Bereich die Felder mit visibility_search=0, in der globalen
     * Suche Metafelder (GLOBAL_EXTRA_FIELDS). "Hinzufügen" schickt die Maske mit
     * function=add_field an sich selbst; die Liste der hinzugefügten Felder reist als
     * extra[] mit (auch in die Trefferliste und von dort zurück in die Maske), die
     * eingegebenen Werte als filter[...].
     *
     * UPLOAD-FELDER
     * Ein Upload-Feld sucht im gespeicherten Dateinamen; aufklappbar darunter Breite,
     * Höhe, Dateigröße (mit k/M/G, Cms::expandSizeUnits()), Mime-Type und Endung
     * (filter[feld.width] …).
     *
     * Woher die Auswahlmöglichkeiten eines Feldes kommen, hängt vom Feld ab -
     * status_id, template_id, parent_id und position holen sie sich aus den
     * Hilfsmethoden der Basisklasse, alle übrigen aus den Zeilen des `default`-Feldes
     * der Felddefinition (dort stehen bei Auswahlfeldern die möglichen Werte, eine je
     * Zeile).
     */
    private function _searchForm(): void
    {
        echo $this->backend->template('container_header');

        // Seiten-Parameter
        $filter = (array)($_REQUEST['filter'] ?? []);

        echo '<form action="Record/record_search.php" method="post" enctype="multipart/form-data">';

        // Bread crum navi
        $this->backend->setNaviLabel('#button_search');
        
        // H1
        echo $this->backend->headline('#record.headline_search');

        $schemaVirtual = [];
        $visibleFields = [];

        // Immer sichtbare Meta-Felder
        $visibleMetaFields = [
            'online_at', 'offline_at', 'created_at', 'changed_at'
        ];

        // Virtuelle Felder, die mit visibility_search=1 gekennzeichnet sind
        $configured = [];
        if ($this->areaId > 0) {
            $schemaVirtual = $this->record->virtualSchema($this->areaId, true);
            // die im Bereich eingerichteten Felder (ohne die übrigen Metaspalten)
            $configured = $this->record->virtualSchema($this->areaId, false);

            // vom Admin definierte Such-Felder für den Bereich
            $visibleFields = [];
            foreach($schemaVirtual as $name => $colDef) {
                if (in_array($name, $visibleMetaFields)) continue;

                $visibility = (int)($colDef['visibility_search'] ?? 0);
                if ($visibility === 1) {
                    $visibleFields[] = $name;
                    }
            }
        }

        // Weitere Felder: wählbar und bereits hinzugefügt (extra[])
        $extraOptions = $this->_extraFieldOptions($configured, $visibleFields, $visibleMetaFields);
        $extraFields = array_values(array_intersect(
            array_unique(array_map('strval', (array)($_REQUEST['extra'] ?? []))),
            array_keys($extraOptions)
        ));
        $addField = (string)($_REQUEST['add_field'] ?? '');
        if ((string)($_REQUEST['function'] ?? '') === 'add_field' && isset($extraOptions[$addField])
            && !in_array($addField, $extraFields, true)) {
            $extraFields[] = $addField;
        }

        // Formular erzeugen
        // --------------------
        $this->backend->formInit('record.record_search_form');

        // Id, Slug oder URL (RecordService::urlFilter(), aufgelöst in der Liste)
        $this->backend->searchField($this->record, [
            'name' => '_url',
            'value' => (string)($filter['_url'] ?? $filter['record_id'] ?? ''),
            'colDef' => ['name' => '_url', 'type' => 'chars', 'label' => '#record.field_url'],
            'note' => '#record.note_field_url',
        ]);

        // area_id
        if (empty($this->areaId)) {
            $sqlTypes = '';
            $sqlValues = [];            
            $sql = "SELECT `area_id`, `depth`, `label` FROM `area` WHERE `type` IN ('record','profile','ugc') AND `deleted`=0"
                 . $this->backend->areaAccessSql();
            
            if (!empty($this->areaIds)) {
                $placeholders = implode(',', array_fill(0, count($this->areaIds), '?'));
                $sql .= " AND `area_id` IN ($placeholders)";                
                $sqlTypes .= str_repeat('i', count($this->areaIds));
                $sqlValues = $this->areaIds;
            }
            
            $sql .= " ORDER BY `lft` ASC";            
            $this->db->query($sql, $sqlTypes, $sqlValues);
            
            $areaOptions = [];
            while ($areaData = $this->db->fetch()) {
                $fullPath = str_repeat('&nbsp; ', (int)$areaData['depth'] * 2) . $this->cms->textOrSnippet($areaData['label']);
                $areaId = (string)$areaData['area_id'];
                $areaOptions[$areaId] = $fullPath;
            }
            $areaColDef = [
                'type' => 'json', // Mehrfachauswahl fester Werte erlauben
                'display_type' => 'multiselect',
                'label' => '#area.content_area'
            ];
            $this->backend->searchField($this->record, [
                'name' => 'area_id',
                'value' => $this->areaFilter,
                'colDef' => $areaColDef,
                'options' => $areaOptions,
                'append' => true
            ]);

            // default-Feld (data_dft)
            $this->backend->searchField($this->record, [
                'name' => '_default',
                'value' => (string)($filter['_default'] ?? ''),
                'colDef' => ['name' => '_default', 'type' => 'chars', 'label' => '#record.field_default'],
                'append' => true
            ]);
        }

        // Volltext-Standardfeld: in jedem Datensatzbereich, ohne Eingabefeld
        // (VirtualFieldsTrait::refreshStandardFulltext()); heißt überall gleich, der
        // Filter greift also auch bereichsübergreifend
        $this->backend->searchField($this->record, [
            'name' => Field::FULLTEXT,
            'value' => (string)($filter[Field::FULLTEXT] ?? ''),
            'colDef' => ['name' => Field::FULLTEXT, 'type' => 'fulltext', 'display_type' => 'text', 'label' => '#area.label_field_fulltext'],
            'append' => true
        ]);
        
        // Felder für sort-Auswahlliste sammeln
        $sortOptions = [];

        // Eingabefelder (virtuelle Felder)
        // --------------------
        foreach ($visibleFields as $name) {
            $this->_searchVirtualField($name, $schemaVirtual[$name] ?? [], $filter, $sortOptions);
        }

        // Frontend-User - in Bereichen mit Frontend-Eingaben
        // --------------------
        if ($this->areaId > 0 && !empty($this->_profileAreaIds())) {
            $this->_searchMetaField('user_id', $filter);
        }

        // Metafelder ans Ende anhängen
        // --------------------
        foreach ($visibleMetaFields as $name) {
            $this->backend->searchField($this->record, [
                'name' => $name,
                'value' => $filter[$name] ?? '',
                'append' => true
            ]);
            $sortOptions[$name] = $this->cms->textOrSnippet($this->cms->schema[$name]['label'] ?? $name);
        }

        // Temporär hinzugefügte Felder (weitere Felder)
        // --------------------
        foreach ($extraFields as $name) {
            if ($this->areaId > 0) {
                $this->_searchVirtualField($name, $schemaVirtual[$name] ?? [], $filter, $sortOptions, 'warning');
            }
            else {
                $this->_searchMetaField($name, $filter, 'warning');
            }
            $this->backend->formHidden('extra[]', $name);
        }

        // Sort, Order
        $this->backend->searchFieldSortOrder($sortOptions, append:true);

        // Hidden fields
        // --------------------
        $this->backend->formHidden('function', 'search');
        $this->backend->formHidden('area_id', $this->areaId);
        // call by lookup field - field_id oder (fremdes Formular) Spaltenname; als
        // Zahl ginge der Name verloren und die Ergebnisliste wäre kein Lookup mehr
        // (ohne "Übernehmen")
        $this->backend->formHidden('src_field', $this->srcFieldName !== '' ? $this->srcFieldName : $this->srcField);
        $this->backend->formHidden('src_id', $this->srcId);
        // bereits gewählte Ids eines Suchmasken-Lookups (slookupSearch(): checked) -
        // die Trefferliste hakt sie wieder an
        $checked = preg_replace('/[^0-9,]/', '', (string)($_REQUEST['checked'] ?? ''));
        if ($checked !== '' && $this->hasLookup()) $this->backend->formHidden('checked', $checked);
        // Verweise von bzw. auf einen Datensatz (RecordBackendList::list()) bleiben
        // bei der Suche erhalten (src_id steht schon oben)
        $destId = (int)($_REQUEST['dest_id'] ?? 0);
        $relation = strtolower(trim((string)($_REQUEST['relation'] ?? '')));
        if (preg_match('/^[a-z0-9_]+(,[a-z0-9_]+)*$/', $relation) && ($destId > 0 || $this->srcId > 0)) {
            if ($destId > 0) $this->backend->formHidden('dest_id', $destId);
            $this->backend->formHidden('relation', $relation);
        }

        // Formular rendern
        echo $this->backend->form('form_search_cancel');

        // Weitere Felder: Auswahl unter dem Absende-Knopf
        $addOptions = array_diff_key($extraOptions, array_flip($extraFields));
        if (!empty($addOptions)) {
            $optionsHtml = '';
            foreach ($addOptions as $value => $label) {
                $optionsHtml .= '<option value="' . htmlspecialchars((string)$value) . '">'
                    . htmlspecialchars($this->cms->_strip_tags($label)) . "</option>\n";
            }
            echo $this->backend->template('record.search_add_field', ['{options}' => $optionsHtml]);
        }

        // Container footer
        echo $this->backend->template('container_footer');

        echo '</form>';
    }

    /**
     * Ein virtuelles Feld als Suchfeld (Felder mit visibility_search=1 und die über
     * "weitere Felder" hinzugefügten) - samt Eintrag in die Sortierauswahl.
     *
     * @param array  $sortOptions  wird ergänzt
     * @param string $blockClass   'warning' für ein temporär hinzugefügtes Feld
     */
    private function _searchVirtualField(string $name, array $colDef, array $filter, array &$sortOptions, string $blockClass = ''): void
    {
        $type = $colDef['type'] ?? 'text';
        $displayType = $colDef['display_type'] ?? 'text';
        // Fest verknüpfte Inhalte: eingebettet gibt es kein Suchfeld dafür
        // (als Lookup wie jedes Verknüpfungsfeld)
        if (in_array($type, $this->cms->dependentTypes, true) && $displayType !== 'lookup') return;

        // Upload-Feld: Dateiname, aufklappbar die Unterfelder
        if ($type === 'file') {
            $this->_searchFileField($name, $colDef, $filter, $blockClass);
            $sortOptions[$name . '.filename'] = $this->cms->textOrSnippet($colDef['label'] ?? $name);
            return;
        }

        // Optionen
        $options = [];
        if ($name == 'status_id') {
            $options = $this->_statusDropdownItems(icon:false);
        }
        else {
            // Default-Value Werte, falls display_type 'json'
            $list = preg_split('/(\r\n|\r|\n)/', $colDef['default'] ?? '');
            foreach ($list as $item) {
                $options[$item] = $item;
            }
        }

        // template_id
        if ($name === 'template_id' && $displayType != 'lookup') {
            $options = $this->_templateOptions();
        }

        // status_id
        elseif ($name === 'status_id' && $displayType != 'lookup') {
            $options = $this->_statusOptions();
        }

        // parent_id
        elseif ($name === 'parent_id' && $displayType != 'lookup') {
            $options = $this->_parentOptions();
        }

        // position
        // _previousOptions() listet die Geschwister EINES Vaterdatensatzes. In der
        // Suchmaske gibt es keinen, deshalb bleibt es bei der Wurzelebene
        // (Standardwert 0).
        elseif ($name == 'position' && $displayType != 'lookup') {
            $options = $this->_previousOptions();
        }

        $fieldParams = [
            'name' => $name,
            'value' => $filter[$name] ?? '',
            'colDef' => $colDef,
            'options' => $options,
            'append' => true,
            'blockClass' => $blockClass,
        ];
        if ($name === 'parent_id' && $displayType === 'lookup') {
            // Kein Verknüpfungsfeld - das Kontextmenü der Verknüpfungen
            // (Verknüpfung lösen) hätte hier nichts zu tun
            $fieldParams['contextMenu'] = '';
        }
        $this->backend->searchField($this->record, $fieldParams);

        // Lookup-Feld - Tabelle record - nachträgliche Ersetzungen.
        //
        // Auch das Meta-Feld parent_id wird so gesucht. Anders als in der
        // Eingabemaske braucht es dafür keine Sonderbehandlung: der Filter
        // parent_id sucht die NACHKOMMEN der angegebenen Datensätze und verträgt
        // mehrere Werte (docs/developers/05-select-und-filter.md) - genau das, was
        // das gewöhnliche Such-Lookup liefert. Der Filtername kommt aus der
        // Felddefinition, die versteckten Felder heißen also filter[parent_id][].
        if ($displayType === 'lookup'
            && (in_array($type, $this->cms->relationTypes, true)
                || ($name === 'parent_id' && $type === 'id'))
        ) {
            $srcField = $colDef['field_id'] ?? 0;
            $urlSuffix = "src_field={$srcField}";
            $searchUrl = "Record/record_list.php?" . $urlSuffix;
            $updateUrl = "Record/ajax_slookup_preview.php?" . $urlSuffix;
            $filterIds = (array)($_REQUEST['filter'][$name] ?? []);
            $ids = $this->system->sanitizeIds($filterIds, true);
            $idString = implode(',', $ids);
            
            $this->backend->formReplace('{src_field}', $srcField);
            $this->backend->formReplace('{search_url}', $searchUrl);
            $this->backend->formReplace('{update_url}', $updateUrl);
            $this->backend->formReplace('{max}', '');
            $this->backend->formReplace('{data_ids}', $idString);
            $this->backend->formReplace('{preview}', $this->_sLookupInit($srcField, $ids));
        }

        // Tiefe direkt hinter dem Vaterdatensatz - sie bezieht sich auf ihn
        if ($name === 'parent_id') {
            $this->_searchMetaField('depth', $filter, $blockClass);
        }

        if (in_array($type, ['relation', 'pointer', 'link'], true)) {
            // nicht sortierbar
            return;
        }
        elseif (in_array($type, ['relation1', 'pointer1'], true)) {
            $sortKey = $name . '._default';
        }
        else {
            $sortKey = $name;
        }
        $sortOptions[$sortKey] = $this->cms->textOrSnippet($colDef['label'] ?? $name);
    }

    /**
     * Upload-Feld als Suchfeld: ein Textfeld für den gespeicherten Dateinamen
     * (filter[feld]), darunter aufklappbar Breite, Höhe, Dateigröße, Mime-Type und
     * Endung (filter[feld.width] …, Cms::buildFileExists()). Der Bereich ist offen,
     * sobald eines der Unterfelder einen Wert hat.
     */
    private function _searchFileField(string $name, array $colDef, array $filter, string $blockClass): void
    {
        $label = (string)($colDef['label'] ?? $name);
        $this->backend->searchField($this->record, [
            'name' => $name,
            'value' => (string)(is_scalar($filter[$name] ?? null) ? $filter[$name] : ''),
            'colDef' => ['name' => $name, 'type' => 'chars', 'label' => $label],
            'note' => '#record.note_search_file_filename',
            'append' => true,
            'blockClass' => $blockClass,
        ]);

        // Unterfelder in einem eigenen Puffer bauen und aufklappbar einsetzen
        $this->backend->formPush();
        $open = false;
        foreach (self::FILE_SUBFIELDS as $sub) {
            $key = $name . '.' . $sub;
            $value = is_scalar($filter[$key] ?? null) ? (string)$filter[$key] : '';
            if ($value !== '') $open = true;
            $this->backend->searchField($this->record, [
                'name' => $key,
                'value' => $value,
                'colDef' => ['name' => $key, 'type' => 'chars', 'label' => '#record.field_file_' . $sub],
                'note' => in_array($sub, ['width', 'height', 'size'], true) ? '#record.note_search_file_' . ($sub === 'size' ? 'size' : 'number') : '',
                'flatName' => true,
                'append' => true,
                'blockClass' => $blockClass,
            ]);
        }
        $subFields = $this->backend->formPop();
        $this->backend->formAppend('', $this->backend->template('record.search_file_details', [
            '{label}' => $this->cms->textOrSnippet($label),
            '{fields}' => $subFields,
            '{open}' => $open ? ' open' : '',
        ]));
    }

    /**
     * Metafeld als Suchfeld - die festen Zusatzfelder (Tiefe, Frontend-User) und
     * die "weiteren Felder" der globalen Suche (GLOBAL_EXTRA_FIELDS).
     *
     * @param string $blockClass  'warning' für ein temporär hinzugefügtes Feld
     */
    private function _searchMetaField(string $name, array $filter, string $blockClass = ''): void
    {
        $value = $filter[$name] ?? '';
        $base = ['name' => $name, 'value' => $value, 'append' => true, 'blockClass' => $blockClass];

        switch ($name) {
            case 'depth':
                // Tiefe relativ zum Vaterdatensatz (Modifier von filter[parent_id],
                // Cms::filter()): leer = alle Nachkommen, 1 = direkte Kinder …
                $this->backend->searchField($this->record, array_replace($base, [
                    'value' => is_scalar($value) ? (string)$value : '',
                    'colDef' => ['name' => 'depth', 'type' => 'chars', 'label' => '#record.field_search_depth'],
                    'note' => '#record.note_search_depth',
                ]));
                return;

            case 'status_id':
                $this->backend->searchField($this->record, array_replace($base, [
                    'colDef' => ['type' => 'integer', 'display_type' => 'select', 'label' => $this->cms->schema['status_id']['label']],
                    'options' => $this->_statusOptions(),
                ]));
                return;

            case 'instance_id':
                $options = [];
                $access = $this->backend->access();
                foreach ($this->instanceCache->instanceLabels() as $instanceId => $label) {
                    if (!$access->instance((int)$instanceId)) continue;
                    $options[(int)$instanceId] = $this->cms->textOrSnippet($label);
                }
                $this->backend->searchField($this->record, array_replace($base, [
                    'colDef' => ['type' => 'integer', 'display_type' => 'select', 'label' => $this->cms->schema['instance_id']['label']],
                    'options' => $options,
                ]));
                return;

            case 'needs_review':
                $this->backend->searchField($this->record, array_replace($base, [
                    'colDef' => ['type' => 'integer', 'display_type' => 'select', 'label' => $this->cms->schema['needs_review']['label']],
                    'options' => [1 => $this->cms->snippet('yes'), 0 => $this->cms->snippet('no')],
                ]));
                return;

            case 'template_id':
                $this->backend->searchField($this->record, array_replace($base, [
                    'colDef' => ['type' => 'id', 'display_type' => 'select', 'label' => $this->cms->schema['template_id']['label']],
                    'options' => $this->_allTemplateOptions(),
                ]));
                return;

            case 'user_id':
                // Lookup auf die Profil-Bereiche (Datensatzliste mit Bereichsfilter)
                $ids = $this->system->sanitizeIds((array)$value, true);
                $this->backend->searchField($this->record, array_replace($base, [
                    'value' => $ids,
                    'colDef' => ['type' => 'id', 'display_type' => 'lookup', 'label' => $this->cms->schema['user_id']['label']],
                    'template' => 'edit_form_lookup_foreign_table',
                    'contextMenu' => '',
                ]));
                foreach ($this->profileLookupReplace('user_id', 'filter', $ids, 0, $this->_profileAreaIds()) as $from => $to) {
                    $this->backend->formReplace($from, $to);
                }
                return;

            case 'owner_id':
                // Lookup auf die Benutzerliste (wie in der Eingabemaske)
                $ids = $this->system->sanitizeIds((array)$value, true);
                $this->backend->searchField($this->record, array_replace($base, [
                    'value' => $ids,
                    'colDef' => $this->cms->schema['owner_id'],
                    'template' => 'edit_form_lookup_foreign_table',
                    'contextMenu' => '',
                ]));
                $this->backend->formReplace('{src_id}', '0');
                $this->backend->formReplace('{src_field}', 'owner_id');
                $this->backend->formReplace('{search_url}', 'Admin/admin_list.php?src_field=owner_id');
                $this->backend->formReplace('{update_url}', 'Admin/ajax_lookup_preview.php?src_field=owner_id&prefix=filter');
                $this->backend->formReplace('{data_ids}', implode(',', $ids));
                $this->backend->formReplace('{max}', '');
                $this->backend->formReplace('{preview}',
                    $GLOBALS['app']->get(\Camindo\Core\Modules\Admin\AdminBackend::class)->lookupPreviewCreate('owner_id', $ids, 'filter', 0));
                return;
        }
    }

    /**
     * Auswahl "weitere Felder": im Bereich die eingerichteten Felder, die nicht in
     * der Suchmaske stehen (visibility_search=0) und sich durchsuchen lassen; in der
     * globalen Suche die Metafelder GLOBAL_EXTRA_FIELDS.
     *
     * @param array $configured  virtuelle Felder des Bereichs (virtualSchema(…, false))
     * @return array  Feldname => Beschriftung
     */
    private function _extraFieldOptions(array $configured, array $visibleFields, array $visibleMetaFields): array
    {
        $options = [];
        if ($this->areaId < 1) {
            foreach (self::GLOBAL_EXTRA_FIELDS as $name) {
                // Instanz nur, wenn es mehrere gibt
                if ($name === 'instance_id' && !$this->instanceCache->hasMultipleInstances()) continue;
                $label = $this->cms->schema[$name]['label'] ?? $name;
                $options[$name] = $this->cms->textOrSnippet($label);
            }
            return $options;
        }
        foreach ($configured as $name => $colDef) {
            $name = (string)$name;
            if (in_array($name, $visibleFields, true) || in_array($name, $visibleMetaFields, true)) continue;
            if ($name === Field::FULLTEXT) continue;
            $type = (string)($colDef['type'] ?? 'text');
            $displayType = (string)($colDef['display_type'] ?? 'text');
            // nicht durchsuchbar: Passwörter, Felder ohne eigene Spalte, eingebettete Inhalte
            if ($type === 'password' || $displayType === 'password') continue;
            if (in_array($type, $this->cms->noColumnTypes, true)) continue;
            if (in_array($type, $this->cms->dependentTypes, true) && $displayType !== 'lookup') continue;
            // Felder ohne Bezeichnung (technische Metaspalten) nicht anbieten
            if (empty($colDef['field_id']) && empty($colDef['label'])) continue;
            $options[$name] = $this->cms->textOrSnippet((string)($colDef['label'] ?? $name));
        }
        asort($options, SORT_NATURAL | SORT_FLAG_CASE);
        return $options;
    }

    /**
     * Profil-Bereiche, deren User im Bereich Datensätze anlegen dürfen
     * (Bereichstyp ugc, details.profile_area_ids) - in der globalen Suche alle
     * lesbaren Profil-Bereiche (RecordBackend::readableProfileAreaIds()).
     *
     * @return int[]
     */
    private function _profileAreaIds(): array
    {
        if ($this->areaId > 0) {
            if ((string)($this->areaData['type'] ?? '') !== 'ugc') return [];
            $details = $this->areaData['details'] ?? [];
            if (is_string($details)) $details = json_decode($details, true) ?: [];
            return $this->system->sanitizeIds((array)($details['profile_area_ids'] ?? []), true);
        }
        return $this->readableProfileAreaIds();
    }

    /**
     * Templates für template_id in der globalen Suche: die wählbaren und die
     * Standard-Templates der lesbaren Inhaltsbereiche.
     *
     * @return array  template_id => Beschriftung
     */
    private function _allTemplateOptions(): array
    {
        $access = $this->backend->areaAccessSql(false, '`a`.`area_id`');
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT DISTINCT `t`.`template_id`, `t`.`label` FROM `template` `t`"
                . " WHERE `t`.`template_id` IN ("
                . "   SELECT `r`.`template_id` FROM `rel_area_template` `r` INNER JOIN `area` `a` ON `a`.`area_id` = `r`.`area_id`"
                . "   WHERE `a`.`deleted` = 0" . $access
                . "   UNION SELECT `a`.`default_template_id` FROM `area` `a` WHERE `a`.`deleted` = 0" . $access
                . " ) ORDER BY `t`.`label` ASC"
            );
            $rows = $this->db->fetchAll();
        }
        finally {
            $this->db->popResult();
        }
        $options = [];
        foreach ($rows as $row) {
            $options[(int)$row['template_id']] = $this->cms->textOrSnippet((string)$row['label']);
        }
        return $options;
    }

    /**
     * function=search: Suche ausführen.
     *
     * Rendert keine Trefferliste, sondern schließt den Suchlayer und lädt den
     * darunterliegenden Listenlayer mit den Suchparametern in der URL neu (siehe
     * Klassenkommentar).
     *
     * json_encode maskiert alles, was das Javascript-Literal beenden könnte;
     * substr(..., 1) entfernt nur das öffnende Anführungszeichen, das schließende
     * bleibt stehen und beendet unten den String - deshalb steht {$safeUri} dort ohne
     * eigene Anführungszeichen.
     */
    private function _searchAction():void
    {
        
        // Parameter (POST)
        $queryString = http_build_query($_POST);

        // Sicher für Javascript verpacken
        $safeParams = json_encode($queryString, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $safeUri = '"Record/record_list.php?' . substr($safeParams, 1);

        // Referer-Layer mit diesen Parametern neu laden
        $this->backend->script("
            cApp.layers.close();
            cApp.layers.replace({$safeUri});
        ");
    }

    /**
     * Vorschau eines Lookup-Suchfeldes beim ERSTEN Aufbau der Maske erzeugen.
     *
     * Wird aus _searchForm() heraus aufgerufen, wenn die Maske mit einem bereits
     * gesetzten Filter erneut angezeigt wird - die ausgewählten Datensätze sollen dann
     * wieder dastehen.
     *
     * Die übergebenen $ids werden verworfen und stattdessen aus
     * $_REQUEST['filter'][{feldname}] geholt: der Aufrufer kennt nur die field_id, der
     * Filter steht aber unter dem Feldnamen.
     *
     * @param int $srcField  Id des Relation-Feldes
     * @param array $ids     wird nicht verwendet (siehe oben)
     */
    public function _sLookupInit(int $srcField, array $ids): string
    {
        if (!$fieldData = $this->record->fieldData($srcField)) {
            $this->cms->error('_sLookupInit() Exception: field not found.');
            $this->errors();
            return '';
        }

        $relFieldName = $fieldData['name'];
        $filterIds = (array)($_REQUEST['filter'][$relFieldName] ?? []);
        $ids = $this->system->sanitizeIds($filterIds, true);
        return $this->_sLookupPreviewCreate($fieldData, $ids);
    }

    /**
     * Ajax: Vorschau eines Lookup-Suchfeldes aktualisieren.
     *
     * Aufgerufen von cApp.ui.slookupUpdate(), nachdem in der Auswahlliste ein Häkchen
     * gesetzt oder entfernt wurde. Antwortet mit dem fertigen Vorschaublock, den das
     * Javascript an die Stelle des alten setzt.
     *
     * Die Id-Liste wird vor dem Zerlegen auf Ziffern und Kommas gefiltert.
     *
     * Request: src_field (field_id), ids (Kommaliste)
     */
    public function sLookupPreview(): void
    {
        $srcField = (int)($_REQUEST['src_field'] ?? 0);
        if (!$fieldData = $this->record->fieldData($srcField)) {
            $this->cms->error('sLookupPreview() Exception: field not found.');
            $this->errors();
            return;
        }

        $idString = preg_replace('/[^0-9,]/', '', (string)($_REQUEST['ids'] ?? ''));
        $ids = explode(',', $idString);
        echo $this->_sLookupPreviewCreate($fieldData, $ids);
    }

    /**
     * Den Vorschaublock eines Lookup-Suchfeldes rendern.
     *
     * Gemeinsamer Kern von _sLookupInit() und sLookupPreview(). Je ausgewähltem
     * Datensatz eine Zeile mit seinem Titel, einem Entfernen-Verweis und einem
     * versteckten Feld, das die Id trägt - der Vorschaublock IST also das Suchfeld;
     * ein sichtbares Eingabefeld gibt es nicht.
     *
     * Als Titel dient der Inhalt des Default-Feldes. Das wird je ZEILE ermittelt: die
     * ausgewählten Datensätze können bei einem Pointer-Feld aus verschiedenen
     * Bereichen stammen und dort verschiedene Default-Felder haben.
     *
     * Die Reihenfolge folgt der übergebenen Id-Liste und nicht der Sortierung der
     * Datenbank - der Redakteur hat sie so ausgewählt. Dafür wird die
     * Ausgangsreihenfolge mit array_flip() zu einem Rang und uksort() danach sortiert.
     *
     * @param array $fieldData  Relation-Feld Daten
     * @param array $ids        im Feld gespeicherte Ids
     */
    private function _sLookupPreviewCreate(array $fieldData, array $ids): string
    {   
        $srcField = (int)($fieldData['field_id'] ?? 0);
        $relFieldName = $fieldData['name'];
        if (empty($srcField)) return '';
        if (empty($ids)) return '';

        // Lade Datensätze
        $this->cms->select([
            'filter' => [
                'record_id' => $ids
            ],
            'limit' => 10
        ]);
        if ($this->db->num_rows < 1) return '';
        
        $records = [];
        while ($row = $this->record->fetch()) {
            // record_id im Schlüssel
            $records[(int)$row['record_id']] = $row;
        }

        // Ergebnisse nach ursprünglicher Id-Reihenfolge sortieren
        $flippedOrder = array_flip($ids); 
        uksort($records, function($a, $b) use ($flippedOrder) {
            return (int)$flippedOrder[$a] <=> (int)$flippedOrder[$b];
        });

        $rows = [];
        foreach ($records as $id => $data) {
            // default field text
            $areaId = (int)$data['area_id'];
            $defaultField = $this->backend->getDefaultField($areaId);
            $fieldName = $defaultField['name'];
            $fieldType =  $defaultField['type'] ?? 'text';
            $label = $this->backend->backendValue(
                $data[$fieldName] ?? '',
                ['type' => $fieldType]
            );

            $rows[] = $this->backend->template('record.slookup_table_row', [
                '{text}' => $label,
                '{data_id}' => $id,
                // Der Feldname der Vorlage ist inzwischen frei wählbar ({var_name}) -
                // hier ist es immer ein Suchkriterium, also der 'filter'-Block.
                '{var_name}' => "filter[{$relFieldName}][]",
                '{unlink_url}' => "cApp.ui.slookupUnlink($id);",
            ]);
            $this->db->popResult();
        }

        // render table
        $table = $this->backend->template('record.slookup_table', [
            '{table_rows}' => implode("\n", $rows)
        ]);

        // render preview container
        $previewContainer = $this->backend->template('record.lookup_preview_container', [
            '{html}' => $table
        ]);

        return $previewContainer;
    }
}