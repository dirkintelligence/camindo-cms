<?php
/**
 * API des Moduls Record: Datensätze suchen und lesen.
 *
 *     record.search       Trefferliste wie die Datensatzliste des Backends - dieselben
 *                         Spalten (RecordPresenter), als Text, zum Blättern
 *     record.info         Detailansicht: Reiter content, meta, history, fulltext
 *     record.field_value  der volle Inhalt EINES Feldes ("mehr" der Detailansicht):
 *                         ungekürzter Text, alle Verknüpfungen seitenweise, die
 *                         eingebetteten Inhalte eines 'link'-Feldes
 *
 *     record.update       ändern  - Auschecken, Ändern, Einchecken in einem Schritt
 *     record.create       anlegen
 *     record.copy         kopieren (und dabei ändern)
 *     record.move         in einen anderen Bereich verschieben (Feldzuordnung)
 *     record.move_options die passenden Zielfelder je Quellfeld
 *     record.delete       in den Papierkorb
 *     record.restore      aus dem Papierkorb zurück
 *     record.message      interne Nachricht zum Datensatz
 *     record.preview_url  Vorschau-Adresse (nur in einer Backend-Session, attach)
 *     record.batch        Stapelverarbeitung: ändern, verschieben, löschen, Volltext
 *     record.export       Export CSV, DOCX, XML (Zip unter var/export)
 *     record.export_fields  die wählbaren Felder eines Exports
 *     record.exports      vorhandene Exportdateien
 *
 * Große Mengen laufen wie im Backend als Hintergrundprozess (Schwellen
 * admin.mass_delete_threshold bzw. admin.export_background_threshold, oder
 * background = yes); die Antwort nennt dann dessen token - Stand über
 * system.process, das Ergebnis kommt als interne Nachricht.
 *
 * SCHREIBEN
 * --------------------
 * Derselbe Weg wie die Eingabemaske (RecordService): Rechte der Gruppe, fremde
 * Sperre (locked, mit unlock übergehbar wie in der Maske), eigene noch offene Maske
 * (open_in_backend - die API verwirft sie nicht), Validierung nach den
 * Felddefinitionen, Check-in in einer Transaktion. Werte im neutralen Format
 * (ApiValues::input()), mehrsprachige als [Code => Wert] - nur in Sprachen mit
 * Schreibrecht; ein einzelner Wert gilt der Hauptsprache.
 *
 *     data       Feld => Wert
 *     relations  Feld => [id, …] (ersetzt) | {set|add|remove|order: [id, …]}
 *     files      Feld => {serverfile: Pfad in var/upload} | {content: Base64, filename}
 *                | {tmpfile: Pfad unter var/tmp} | null (entfernen); mehrsprachig
 *                {Code: …}
 *
 * Rechte wie im Backend: Cms::select() sieht nur die Instanzen und lesbaren Bereiche
 * der Gruppe, record.info verlangt Leserecht (RecordService::loadForAction()), nur
 * lesbare Sprachen, Verknüpfungsfelder nur mit lesbarem Zielbereich.
 *
 * Werte im neutralen Format (ApiValues), Sprachen als Codes. Die Sprache der Inhalte
 * ist ohne Angabe die des Backends (bevorzugte Sprachversion des Admins, sonst die
 * Hauptsprache der Instanz).
 *
 * @see Camindo\Core\Api\Api
 * @see Camindo\Core\Modules\Record\RecordService
 * @see Camindo\Core\Modules\Record\RecordPresenter
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\Access;
use Camindo\Core\AuditDiff;
use Camindo\Core\AuditLog;
use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\ContainerInterface;
use Camindo\Core\Crypt;
use Camindo\Core\Database;
use Camindo\Core\FrontendHandover;
use Camindo\Core\InstanceCache;
use Camindo\Core\System;
use Camindo\Core\Api\ApiContext;
use Camindo\Core\Api\ApiException;
use Camindo\Core\Api\ApiMethod;
use Camindo\Core\Api\ApiValues;
use Camindo\Core\Modules\Admin\Admin;
use Camindo\Core\Modules\Messaging\Message;
use Camindo\Core\Modules\Trash\Trash;

class RecordApi
{
    /** Verknüpfungen je Feld in record.info (mehr: record.field_value) */
    private const RELATED_MAX = 20;

    /** Einträge im Reiter history */
    private const HISTORY_MAX = 20;

    /** Feldtypen, deren Werte record.info kürzt */
    private const TEXT_TYPES = ['text', 'chars', 'encrypted', 'fulltext'];

    public function __construct(
        private Config $config,
        private Cms $cms,
        private Database $db,
        private System $system,
        private Backend $backend,
        private InstanceCache $instanceCache,
        private Access $access,
        private AuditLog $auditLog,
        private AuditDiff $auditDiff,
        private Record $record,
        private RecordService $service,
        private RecordPresenter $presenter,
        private ApiContext $context,
        private ApiValues $values,
        private Crypt $crypt,
        private ContainerInterface $container,
    )
    {
    }

    // ====================
    // record.search

    /**
     * Datensätze suchen. Die Parameter sind die von Cms::select().
     *
     * @param int|array    $areaId      Bereich oder Liste von Bereichen; 0 = alle lesbaren (globale Suche)
     * @param array        $filter      Feldname => Wert bzw. [Wert, …] (Syntax wie CMS:SELECT: Platzhalter *, Vergleiche über filterop)
     * @param array        $filterop    Vergleichsoperatoren je Feld, indexgleich zu filter ('=', '<', '>', '<=', '>=', '!=', 'like' …)
     * @param array        $filtermode  Verknüpfung gleichnamiger Werte je Feld: OR (Vorgabe) oder AND
     * @param string|array $sort        Feldname(n), optional mit Richtung ('title ASC'); ohne Angabe die Sortierung des Bereichs
     * @param string|array $order       Richtungen, indexgleich zu sort
     * @param int          $skip        Versatz (Blättern)
     * @param int          $limit       Treffer je Seite (Vorgabe wie im Backend, höchstens api.max_limit)
     * @param string       $url         Id, Slug (mit oder ohne Slug-Präfix) oder URL eines Datensatzes
     * @param string       $language    Sprachcode der Inhalte; leer = Sprache des Backends
     * @param int          $srcId       mit relation: die Datensätze, auf die das Feld relation von src_id zeigt
     * @param int          $destId      mit relation: die Datensätze, die über relation auf dest_id zeigen
     * @param string       $relation    Name eines Verknüpfungsfeldes, mehrere kommagetrennt, oder 'any'
     * @return array  area (bei einem Bereich), language, columns (Name => label, type), rows
     *                (record_id, area_id, values: Name => Text wie in der Backend-Liste),
     *                filter_text, sort, paging (total, skip, limit, next_skip)
     */
    #[ApiMethod('record.search', '#record.api_search')]
    public function search(
        int|array $areaId = 0,
        array $filter = [],
        array $filterop = [],
        array $filtermode = [],
        string|array $sort = '',
        string|array $order = '',
        int $skip = 0,
        int $limit = 0,
        string $url = '',
        string $language = '',
        int $srcId = 0,
        int $destId = 0,
        string $relation = '',
    ): array
    {
        $this->context->require();
        $areaIds = $this->system->sanitizeIds((array)$areaId, true);

        // Id, Slug oder URL (wie das Suchfeld _url der Suchmaske)
        if (trim($url) !== '') {
            $urlFilter = $this->service->urlFilter($url);
            if (isset($urlFilter['area_id'])) {
                $areaIds = $areaIds ? (array_values(array_intersect($areaIds, $urlFilter['area_id'])) ?: [0]) : $urlFilter['area_id'];
                unset($urlFilter['area_id']);
            }
            $filter = array_replace($filter, $urlFilter);
        }

        // Bereiche: jeder muss lesbar sein
        foreach ($areaIds as $id) {
            if ($id > 0 && !$this->access->areaRead($id)) {
                throw new ApiException(ApiException::ACCESS_DENIED, $this->cms->snippet('error_access_denied'));
            }
        }
        $single = count($areaIds) === 1 && $areaIds[0] > 0 ? $areaIds[0] : 0;
        $areaData = $single > 0 ? $this->backend->areaData($single) : null;
        if ($single > 0 && !$areaData) {
            throw new ApiException(ApiException::NOT_FOUND, $this->cms->snippet('area.error_area_not_found'));
        }

        // Bereichsbedingung als SQL (nicht im Filtertext, wie RecordBackendList)
        $sql = '';
        if ($areaIds) {
            $sql = '`record`.`area_id` IN (' . implode(',', array_map('intval', $areaIds)) . ')';
        }
        if ($single === 0) {
            $accessSql = $this->backend->areaAccessSql(false, '`record`.`area_id`');
            if ($accessSql !== '') {
                $accessSql = substr($accessSql, 5); // führendes " AND "
                $sql = ($sql === '') ? $accessSql : $sql . ' AND ' . $accessSql;
            }
        }

        // Schema: bei einem Bereich mit virtuellen Feldern
        if ($single > 0) {
            $schemaVirtual = $this->record->virtualSchema($single, false);
            $completedSchema = $this->record->virtualSchema($single, true);
        }
        else {
            $schemaVirtual = [];
            $completedSchema = $this->cms->schema;
        }
        $isTree = isset($schemaVirtual['parent_id']);

        // Sortierung: ohne Angabe die des Bereichs (sonst changed_at DESC); position im Baum = lft
        if ($sort === '' || $sort === []) {
            $sort = 'changed_at';
            $order = 'DESC';
            if ($areaData && ($sortFieldId = (int)($areaData['sort_field_id'] ?? 0))) {
                if ($fieldData = $this->record->fieldData($sortFieldId)) {
                    $sort = (string)$fieldData['name'];
                    $order = (string)($areaData['sort_order'] ?? 'DESC');
                }
            }
        }
        if ($sort === 'position' && $isTree) {
            $sort = 'lft';
            $order = 'ASC';
        }

        $maxLimit = max(1, (int)$this->config->get('api.max_limit'));
        $limit = $limit < 1 ? (int)$this->cms->list_rows : min($limit, $maxLimit);
        $skip = max(0, $skip);

        $param = [
            'filter' => $filter, 'filterop' => $filterop, 'filtermode' => $filtermode,
            'sort' => $sort, 'order' => $order,
            'skip' => $skip, 'limit' => $limit,
            'count' => 'true', 'filtertext' => 'true',
            'sql' => $sql,
        ];
        if (trim($relation) !== '') {
            if (!preg_match('/^(any|[a-z0-9_]+(,[a-z0-9_]+)*)$/', trim($relation))) {
                throw new ApiException(ApiException::INVALID_PARAM, 'invalid parameter "relation"');
            }
            $param['relation'] = trim($relation);
            if ($srcId > 0) $param['src_id'] = $srcId;
            if ($destId > 0) $param['dest_id'] = $destId;
        }

        $previousMap = $this->cms->languageMap;
        $languageCode = $this->applySearchLanguage($language);
        try {
            $f = $this->cms->select($param, null, $completedSchema);
            if ($this->cms->hasErrors) throw $this->context->exceptionFromCms(ApiException::INVALID_PARAM);
            $total = (int)$this->db->num_rows_unlimited;

            // Spalten
            $columns = [];
            if ($single > 0) {
                $visible = array_values(array_filter(
                    $this->presenter->listFields($schemaVirtual),
                    fn($name) => $this->record->relationTargetReadable($schemaVirtual[$name])
                ));
                foreach ($visible as $name) {
                    $columns[$name] = [
                        'label' => $this->context->plain($this->cms->textOrSnippet((string)($schemaVirtual[$name]['label'] ?? $name))),
                        'type'  => (string)($schemaVirtual[$name]['type'] ?? ''),
                    ];
                }
            }
            else {
                $columns['_default'] = ['label' => $this->cms->snippet('record.field_default'), 'type' => 'chars'];
            }

            // Zeilen
            $rows = [];
            $count = (int)$this->db->num_rows;
            for ($i = 0; $i < $count; $i++) {
                $row = $this->cms->fetch();
                $this->db->pushResult();
                $values = [];
                if ($single > 0) {
                    foreach (array_keys($columns) as $name) {
                        $values[$name] = $this->listText($name, $row, $schemaVirtual);
                    }
                }
                else {
                    $defaultField = $this->backend->getDefaultField((int)$row['area_id']);
                    $defaultName = (string)($defaultField['name'] ?? 'record_id');
                    $values['_default'] = $this->context->plain(
                        $this->backend->backendValue($row[$defaultName] ?? '', $defaultField, id: (int)$row['record_id'])
                    );
                }
                $entry = ['record_id' => (int)$row['record_id'], 'area_id' => (int)$row['area_id']];
                if ($isTree) $entry['depth'] = (int)($row['depth'] ?? 0);
                $entry['values'] = $values;
                $rows[] = $entry;
                $this->db->popResult();
            }
        }
        finally {
            $this->cms->languageMap = $previousMap;
        }

        return [
            'area'        => $areaData ? $this->areaRef($single) : null,
            'language'    => $languageCode,
            'columns'     => $columns,
            'rows'        => $rows,
            'filter_text' => $this->context->plain((string)($f['filter_text'] ?? '')),
            'sort'        => (string)($f['used_sort'] ?? ''),
            'paging'      => [
                'total'     => $total,
                'skip'      => $skip,
                'limit'     => $limit,
                'next_skip' => ($skip + $limit < $total) ? $skip + $limit : null,
            ],
        ];
    }

    /**
     * Wert einer Listenspalte als Text (RecordPresenter::listValue() ohne HTML);
     * der Status mit seiner Bezeichnung statt des Symbols.
     */
    private function listText(string $name, array $row, array $schemaVirtual): string
    {
        if ($name === 'status_id') {
            $text = $this->plainTeaser('status', (int)($row['status_id'] ?? 0));
            if (!empty($row['needs_review'])) $text .= ' (' . $this->cms->snippet('user.status_unreviewed') . ')';
            return $text;
        }
        if ($name === 'needs_review') return !empty($row['needs_review']) ? '1' : '0';
        if ((string)($schemaVirtual[$name]['type'] ?? '') === 'file') {
            // die Liste zeigt ein Vorschaubild - als Text der Dateiname
            $file = $this->values->file($row[$name] ?? null);
            if ($file === null) return '';
            return isset($file['filename']) ? (string)$file['filename'] : implode(', ', array_column($file, 'filename'));
        }
        $text = $this->context->plain($this->presenter->listValue($name, $row, $schemaVirtual));
        return $text === $this->context->plain($this->backend->emptyPlaceholder) ? '' : $text;
    }

    /**
     * Sprache der Suche: die Sprache mit diesem Code in jeder Instanz, die sie führt
     * (Cms::$languageMap; der Aufrufer stellt die bisherige wieder her).
     *
     * @return string|null  Code der gesuchten Sprache, null = Sprache des Backends
     */
    private function applySearchLanguage(string $language): ?string
    {
        if (trim($language) === '') return null;
        $found = false;
        foreach ((array)($this->instanceCache->all()['instance_ids'] ?? []) as $instanceId) {
            $languageId = $this->context->languageId($language, (int)$instanceId);
            if ($languageId === null) continue;
            if (!$this->access->languageRead($languageId)) {
                throw new ApiException(ApiException::ACCESS_DENIED, $this->cms->snippet('error_access_denied'));
            }
            $this->cms->languageMap[(int)$instanceId] = $languageId;
            $found = true;
        }
        if (!$found) throw new ApiException(ApiException::INVALID_PARAM, "unknown language \"{$language}\"");
        return $language;
    }

    // ====================
    // record.info

    /**
     * Detailansicht eines Datensatzes.
     *
     * @param int    $recordId  Datensatz
     * @param string $tab       content (Felder), meta (Metafelder), history (die letzten 20 Änderungen aus dem Audit-Log, neueste zuerst; mehr: audit.history), fulltext (Volltextfelder)
     * @param string $language  Sprachcode, 'all' (alle lesbaren Sprachen) oder leer (Sprache des Backends)
     * @param array  $fields    nur diese Felder (Namen); leer = alle
     * @param int    $truncate  Texte ab so vielen Zeichen kürzen (0 = nie); gekürzte Felder tragen truncated = true, den vollen Text liefert record.field_value
     * @return array  record (Kopf: Bereich, Status, Zeiten, Sperre, offene Maske, Rechte, Sprachen) und je nach Reiter fields, meta oder history
     */
    #[ApiMethod('record.info', '#record.api_info')]
    public function info(int $recordId, string $tab = 'content', string $language = '', array $fields = [], int $truncate = 500): array
    {
        $this->context->require();
        if (!in_array($tab, ['content', 'meta', 'history', 'fulltext'], true)) {
            throw new ApiException(ApiException::INVALID_PARAM, "invalid tab \"{$tab}\"");
        }
        $data = $this->loadReadable($recordId);
        $result = ['record' => $this->head($data)];

        if ($tab === 'meta') {
            $result['meta'] = $this->meta($data);
            return $result;
        }
        if ($tab === 'history') {
            $result['history'] = $this->history($recordId, $truncate);
            return $result;
        }

        $areaId = (int)$data['area_id'];
        $languages = $this->contentLanguages((int)$data['instance_id'], $language);
        $caches = $this->caches($recordId, $languages);
        $schemaVirtual = $this->record->virtualSchema($areaId, false);
        $fields = array_values(array_filter($fields, 'is_string'));
        $entries = [];
        foreach ($schemaVirtual as $name => $colDef) {
            if ($fields && !in_array($name, $fields, true)) continue;
            $isFulltext = ((string)($colDef['type'] ?? '') === 'fulltext');
            if ($tab === 'fulltext' ? !$isFulltext : ($isFulltext || in_array($name, ['status_id', 'template_id'], true))) continue;
            $entry = $this->fieldEntry($data, $name, $colDef, $languages, $caches, $truncate);
            if ($entry !== null) $entries[$name] = $entry;
        }
        $result['language'] = count($languages) > 1 ? 'all' : (string)reset($languages);
        $result['fields'] = $entries;
        return $result;
    }

    // ====================
    // record.field_value

    /**
     * Der volle Inhalt eines Feldes - das "mehr" der Detailansicht.
     *
     * @param int    $recordId  Datensatz
     * @param string $field     Feldname
     * @param string $language  Sprachcode, 'all' oder leer (Sprache des Backends)
     * @param int    $skip      Verknüpfungen: Versatz
     * @param int    $limit     Verknüpfungen: je Seite (höchstens api.max_limit)
     * @return array  field, label, type, value bzw. bei Verknüpfungen items (record_id, label; bei 'link' mit den Feldern der eingebetteten Inhalte) und paging
     */
    #[ApiMethod('record.field_value', '#record.api_field_value')]
    public function fieldValue(int $recordId, string $field, string $language = '', int $skip = 0, int $limit = 50): array
    {
        $this->context->require();
        $data = $this->loadReadable($recordId);
        $schemaVirtual = $this->record->virtualSchema((int)$data['area_id'], false);
        $colDef = $schemaVirtual[$field] ?? null;
        if ($colDef === null || !$this->record->relationTargetReadable($colDef)) {
            throw new ApiException(ApiException::INVALID_PARAM, "unknown field \"{$field}\"");
        }
        $type = (string)($colDef['type'] ?? '');
        $result = [
            'field'        => $field,
            'label'        => $this->label($colDef, $field),
            'type'         => $type,
            'multilingual' => $this->cms->isMultilingualField($colDef),
        ];

        if (in_array($type, $this->cms->relationTypes, true)) {
            $limit = min(max(1, $limit), max(1, (int)$this->config->get('api.max_limit')));
            $skip = max(0, $skip);
            [$ids, $total] = $this->backend->relatedPage($recordId, $colDef, $skip, $limit);
            $isLink = in_array($type, $this->cms->dependentTypes, true);
            $languages = $isLink ? $this->contentLanguages((int)$data['instance_id'], $language) : [];
            $items = [];
            foreach ($ids as $id) {
                $item = $this->recordRef($id);
                if ($isLink && ($embedded = $this->record->load($id))) {
                    $item['area_id'] = (int)$embedded['area_id'];
                    $item['fields'] = $this->embeddedFields($embedded, $languages);
                }
                $items[] = $item;
            }
            $this->cms->errorsReset();
            $result['items'] = $items;
            $result['paging'] = [
                'total' => $total, 'skip' => $skip, 'limit' => $limit,
                'next_skip' => ($skip + $limit < $total) ? $skip + $limit : null,
            ];
            return $result;
        }

        $languages = $this->contentLanguages((int)$data['instance_id'], $language);
        $entry = $this->fieldEntry($data, $field, $colDef, $languages, $this->caches($recordId, $languages), 0);
        return $result + ['value' => $entry['value'] ?? null] + array_intersect_key((array)$entry, ['fallback' => 1]);
    }


    // ====================
    // record.update / create / copy

    /**
     * Einen Datensatz ändern - Auschecken, Ändern, Einchecken in einem Schritt.
     *
     * @param int   $recordId    Datensatz
     * @param array $data        Feld => Wert im neutralen Format; mehrsprachig {Code: Wert}
     * @param array $fields      nur diese Felder aus data schreiben (leer = alle Schlüssel von data)
     * @param array $relations   Feld => [id, …] ersetzt alle | {"set": [...]} | {"add": [...]} | {"remove": [...]} | {"order": [...]}
     * @param array $files       Feld => {"serverfile": Pfad in var/upload} | {"content": Base64, "filename": Name} | {"tmpfile": Pfad unter var/tmp} | null (entfernen); mehrsprachig {Code: …}
     * @param int   $parentId    Vaterdatensatz (Baum; 0 = oberste Ebene, -1 = unverändert)
     * @param int   $previousId  vorangehender Datensatz (Position; 0 = an den Anfang, -1 = unverändert)
     * @param int   $ownerId     Owner (nur mit dem Recht "Owner anpassen"; 0 = unverändert)
     * @param bool  $unlock      die Bearbeitungssperre eines anderen Admins übergehen (wie in der Maske)
     * @param bool  $simulate    nur prüfen, nichts ändern
     * @return array  record_id, simulated, record (Kopfdaten wie record.info)
     */
    #[ApiMethod('record.update', '#record.api_update', write: true, level: ApiMethod::LEVEL_CHANGE)]
    public function update(int $recordId, array $data = [], array $fields = [], array $relations = [], array $files = [],
        int $parentId = -1, int $previousId = -1, int $ownerId = 0, bool $unlock = false, bool $simulate = false): array
    {
        $this->context->require();
        $record = $this->requireAction($recordId, 'edit', $unlock);
        $input = $this->writeInput((int)$record['area_id'], (int)$record['instance_id'], $data, $fields, $relations, $files, $parentId, $previousId, $ownerId);
        try {
            $id = $this->service->updateOne($recordId, $input['fields'], $input['data'], $input['relations'], $input['files'],
                ['unlock' => $unlock, 'simulate' => $simulate, 'stored' => $input['stored']]);
        }
        finally {
            $this->removeDir($input['tmp_dir']);
        }
        if ($id === null) throw $this->context->exceptionFromCms(ApiException::VALIDATION);
        return $this->writeResult($recordId, $simulate);
    }

    /**
     * Einen Datensatz anlegen.
     *
     * @param int   $areaId      Bereich (nicht abhängig - deren Inhalte entstehen nur über ein Feld vom Typ link)
     * @param array $data        Feld => Wert (wie record.update)
     * @param array $relations   wie record.update
     * @param array $files       wie record.update
     * @param int   $parentId    Vaterdatensatz (Baum; 0 = oberste Ebene)
     * @param int   $previousId  vorangehender Datensatz (Position; 0 = an den Anfang, -1 = Vorgabe)
     * @param int   $ownerId     Owner (nur mit dem Recht "Owner anpassen"; 0 = der angemeldete Admin)
     * @param bool  $simulate    nur prüfen, nichts anlegen
     * @return array  record_id (bei Simulation 0), simulated, record
     */
    #[ApiMethod('record.create', '#record.api_create', write: true)]
    public function create(int $areaId, array $data = [], array $relations = [], array $files = [],
        int $parentId = -1, int $previousId = -1, int $ownerId = 0, bool $simulate = false): array
    {
        $this->context->require();
        $areaData = $areaId > 0 ? $this->backend->areaData($areaId) : null;
        if (!$areaData || !\Camindo\Core\Modules\Area\Area::isContentType((string)$areaData['type'])) {
            throw new ApiException(ApiException::NOT_FOUND, $this->cms->snippet('area.error_area_not_found'));
        }
        if (!$this->access->areaCreate($areaId) || (int)($areaData['dependent'] ?? 0) === 1) {
            throw new ApiException(ApiException::ACCESS_DENIED, $this->cms->snippet('error_access_denied'));
        }
        $input = $this->writeInput($areaId, (int)$areaData['instance_id'], $data, [], $relations, $files, $parentId, $previousId, $ownerId);
        try {
            $id = $this->service->createOne($areaId, $input['fields'], $input['data'], $input['relations'], $input['files'],
                ['simulate' => $simulate, 'stored' => $input['stored']]);
        }
        finally {
            $this->removeDir($input['tmp_dir']);
        }
        if ($id === null) throw $this->context->exceptionFromCms(ApiException::VALIDATION);
        return $this->writeResult($id, $simulate);
    }

    /**
     * Einen Datensatz kopieren und dabei ändern (wie "Kopieren" in der Maske: samt
     * Dateien und eingebetteten Inhalten, direkt hinter dem Vorbild, ohne Kinder).
     *
     * @param int   $recordId   Vorbild
     * @param array $data       Änderungen an der Kopie (wie record.update)
     * @param array $relations  wie record.update
     * @param array $files      wie record.update
     * @param bool  $simulate   nur prüfen, nichts anlegen
     * @return array  record_id der Kopie (bei Simulation 0), simulated, record
     */
    #[ApiMethod('record.copy', '#record.api_copy', write: true)]
    public function copy(int $recordId, array $data = [], array $relations = [], array $files = [], bool $simulate = false): array
    {
        $this->context->require();
        $record = $this->requireAction($recordId, 'copy', checkLocks: false);
        $input = $this->writeInput((int)$record['area_id'], (int)$record['instance_id'], $data, [], $relations, $files, -1, -1, 0);
        try {
            $id = $this->service->copyOne($recordId, $input['fields'], $input['data'], $input['relations'], $input['files'],
                ['simulate' => $simulate, 'stored' => $input['stored']]);
        }
        finally {
            $this->removeDir($input['tmp_dir']);
        }
        if ($id === null) throw $this->context->exceptionFromCms(ApiException::VALIDATION);
        return $this->writeResult($id, $simulate);
    }

    // ====================
    // record.move

    /**
     * Die möglichen Zielfelder je Feld des Datensatzes für ein Verschieben - die
     * Gegenüberstellung der Verschieben-Maske.
     *
     * @param int $recordId      Datensatz
     * @param int $targetAreaId  Zielbereich
     * @return array  target_area, fields: Liste aus name, label, type, has_value, default (vorgewähltes Zielfeld oder null), targets [{name, label}]
     */
    #[ApiMethod('record.move_options', '#record.api_move_options')]
    public function moveOptions(int $recordId, int $targetAreaId): array
    {
        $this->context->require();
        $record = $this->requireAction($recordId, 'move', checkLocks: false);
        [$sourceFields, $targetFields] = $this->moveFieldSets((int)$record['area_id'], $targetAreaId);
        $defaults = $this->record->moveDefaultMapping($sourceFields, $targetFields);
        $list = [];
        foreach ($sourceFields as $name => $colDef) {
            $targets = [];
            foreach ($this->record->moveTargets($colDef, $targetFields) as $targetName => $targetDef) {
                $targets[] = ['name' => (string)$targetName, 'label' => $this->label($targetDef, (string)$targetName)];
            }
            $list[] = [
                'name'      => (string)$name,
                'label'     => $this->label($colDef, (string)$name),
                'type'      => (string)($colDef['type'] ?? ''),
                'has_value' => $this->hasValue($record, (string)$name, $colDef),
                'default'   => ($defaults[$name] ?? '') ?: null,
                'targets'   => $targets,
            ];
        }
        return ['target_area' => $this->areaRef($targetAreaId), 'fields' => $list];
    }

    /**
     * Einen Datensatz in einen anderen Bereich verschieben.
     *
     * @param int   $recordId      Datensatz
     * @param int   $targetAreaId  Zielbereich
     * @param array $mapping       Quellfeld => Zielfeld ('' = Inhalt verwerfen)
     * @param bool  $autoMap       nicht genannte Quellfelder auf das gleichnamige passende Zielfeld abbilden (sonst verwerfen)
     * @param bool  $unlock        die Bearbeitungssperre eines anderen Admins übergehen
     * @param bool  $simulate      nur prüfen, nichts verschieben
     * @return array  record_id, simulated, mapping (verwendet), dropped (Quellfelder mit Inhalt, der verworfen wird), record
     */
    #[ApiMethod('record.move', '#record.api_move', write: true, level: ApiMethod::LEVEL_MOVE)]
    public function move(int $recordId, int $targetAreaId, array $mapping = [], bool $autoMap = true, bool $unlock = false, bool $simulate = false): array
    {
        $this->context->require();
        $record = $this->requireAction($recordId, 'move', $unlock);
        [$sourceFields, $targetFields] = $this->moveFieldSets((int)$record['area_id'], $targetAreaId);
        $given = array_map(fn($v) => is_scalar($v) ? (string)$v : '', $mapping);
        $unknown = array_diff(array_keys($given), array_keys($sourceFields));
        if ($unknown) throw new ApiException(ApiException::INVALID_PARAM, 'unknown source field "' . implode('", "', $unknown) . '"');
        $mapping = $autoMap
            ? array_replace($this->record->moveDefaultMapping($sourceFields, $targetFields), $given)
            : array_replace(array_fill_keys(array_keys($sourceFields), ''), $given);

        $dropped = [];
        foreach ($sourceFields as $name => $colDef) {
            if (($mapping[$name] ?? '') === '' && $this->hasValue($record, (string)$name, $colDef)) $dropped[] = (string)$name;
        }

        $this->cms->errorsReset();
        if (!$this->record->moveToArea($recordId, $targetAreaId, $mapping, $simulate)) {
            throw $this->context->exceptionFromCms(ApiException::VALIDATION);
        }
        $result = $this->writeResult($recordId, $simulate);
        return ['record_id' => $recordId, 'simulated' => $simulate, 'mapping' => $mapping, 'dropped' => $dropped]
            + ['record' => $result['record']];
    }

    // ====================
    // record.delete / restore

    /**
     * Einen Datensatz in den Papierkorb legen (Soft-Delete). Große Teilbäume laufen
     * im Hintergrund.
     *
     * @param int  $recordId    Datensatz
     * @param bool $withChilds  Nachkommen (Baum) mit löschen; sonst rücken sie eine Ebene hoch
     * @param bool $unlock      die Bearbeitungssperre eines anderen Admins übergehen
     * @return array  record_id, status (deleted | background)
     */
    #[ApiMethod('record.delete', '#record.api_delete', write: true, level: ApiMethod::LEVEL_DELETE)]
    public function delete(int $recordId, bool $withChilds = false, bool $unlock = false): array
    {
        $this->context->require();
        $this->requireAction($recordId, 'delete', $unlock);
        $this->cms->errorsReset();
        $status = $this->service->delete($recordId, $withChilds);
        if ($status === null) throw $this->context->exceptionFromCms(ApiException::VALIDATION);
        return ['record_id' => $recordId, 'status' => $status];
    }

    /**
     * Einen Datensatz aus dem Papierkorb zurückholen - samt der mit ihm gelöschten
     * Nachkommen. Wie im Modul Papierkorb: nur, was der Admin dort sieht.
     *
     * @param int $recordId  gelöschter Datensatz
     * @return array  record_id, status (restored | background)
     */
    #[ApiMethod('record.restore', '#record.api_restore', write: true, level: ApiMethod::LEVEL_CHANGE)]
    public function restore(int $recordId): array
    {
        $this->context->require();
        $this->cms->errorsReset();
        $status = $this->container->get(Trash::class)->restore('record', $recordId);
        if ($status === 'denied') throw new ApiException(ApiException::NOT_FOUND, $this->cms->snippet('error_record_not_found'));
        if ($status === 'failed') throw $this->context->exceptionFromCms(ApiException::VALIDATION);
        return ['record_id' => $recordId, 'status' => $status];
    }

    // ====================
    // record.message / record.preview_url

    /**
     * Eine interne Nachricht zu einem Datensatz an einen Administrator schicken
     * (Modul Nachrichten; der Datensatz wird in der Nachricht verlinkt).
     *
     * @param int        $recordId  Datensatz
     * @param int|string $to        Empfänger: admin_id oder Name
     * @param string     $subject   Betreff
     * @param string     $body      Text
     * @return array  message_id
     */
    #[ApiMethod('record.message', '#record.api_message', write: true, level: ApiMethod::LEVEL_CHANGE)]
    public function message(int $recordId, int|string $to, string $subject, string $body): array
    {
        $this->context->require();
        $this->loadReadable($recordId);
        $toId = is_int($to) || ctype_digit($to) ? (int)$to : (int)($this->container->get(Admin::class)->load(trim($to), 'name')['admin_id'] ?? 0);
        $this->cms->errorsReset();
        if ($toId < 1) throw new ApiException(ApiException::NOT_FOUND, $this->cms->snippet('error_record_not_found'));
        $message = $this->container->get(Message::class);
        $data = $message->init();
        $data = array_replace($data, ['to_admin_id' => $toId, 'subject' => $subject, 'body' => $body, 'record_id' => $recordId]);
        $id = $message->save(['to_admin_id', 'subject', 'body', 'record_id'], $data);
        if (!$id) throw $this->context->exceptionFromCms(ApiException::VALIDATION);
        return ['message_id' => (int)$id];
    }

    /**
     * Adresse der Vorschau eines Datensatzes - auch unveröffentlicht. Nur wenn die
     * API in der Backend-Session eines angemeldeten Admins mitarbeitet (attach, etwa
     * im LLM-Chat): die Vorschau hängt an dieser Session und endet mit ihr.
     *
     * @param int    $recordId  Datensatz
     * @param string $language  Sprachcode (leer = Sprache des Backends)
     * @return array  url, language, valid_until (ISO; null, wenn die Adresse die Backend-Anmeldung im Browser voraussetzt)
     */
    #[ApiMethod('record.preview_url', '#record.api_preview_url')]
    public function previewUrl(int $recordId, string $language = ''): array
    {
        $this->context->require();
        if (!$this->context->attached()) {
            throw new ApiException(ApiException::ACCESS_DENIED, $this->cms->snippet('error_access_denied'));
        }
        $data = $this->loadReadable($recordId);
        $instanceId = (int)$data['instance_id'];
        $languages = $this->contentLanguages($instanceId, $language);
        $code = (string)reset($languages);
        $url = $this->container->get(FrontendHandover::class)->previewUrl($instanceId, (string)$recordId, $code);
        $validUntil = null;
        if ($url !== '') {
            $validUntil = $this->context->isoTime(gmdate('Y-m-d H:i:s', time() + FrontendHandover::PREVIEW_SECONDS));
        }
        else {
            // Backend unter einer Domain der Instanz: die Vorschau läuft mit dem
            // Session-Cookie des Browsers (public/backend/preview.php)
            $url = rtrim((string)$this->config->get('paths.backend'), '/') . '/preview.php?instance=' . $instanceId
                . '&request=' . $recordId . ($code !== '' ? '&lang=' . rawurlencode($code) : '');
        }
        return ['url' => $url, 'language' => $code ?: null, 'valid_until' => $validUntil];
    }


    // ====================
    // record.batch

    /**
     * Stapelverarbeitung: viele Datensätze eines Bereichs auf einmal ändern,
     * verschieben oder löschen (Papierkorb) - je Datensatz derselbe Weg wie einzeln
     * (RecordBatch). Braucht das Zusatzrecht "Stapelverarbeitung". Den Volltext
     * baut die Zeitplaner-Aufgabe fulltext neu auf (scheduler.create).
     *
     * @param int    $areaId        Bereich
     * @param string $mode          edit | move | delete
     * @param array  $ids           diese Datensätze (Umfang, alternativ zu filter bzw. all)
     * @param array  $filter        Filter wie record.search (Umfang)
     * @param array  $filterop      Operatoren zu filter
     * @param array  $filtermode    OR | AND je Feld
     * @param bool   $all           alle Datensätze des Bereichs (Umfang)
     * @param array  $data          edit: Feld => Wert (wie record.update; keine Passwörter, keine eindeutigen Felder)
     * @param array  $relations     edit: wie record.update
     * @param array  $files         edit: wie record.update
     * @param int    $targetAreaId  move: Zielbereich
     * @param array  $mapping       move: Quellfeld => Zielfeld ('' = verwerfen)
     * @param bool   $autoMap       move: nicht genannte Felder auf gleichnamige passende Zielfelder abbilden
     * @param bool   $simulate      nur prüfen, nichts ändern
     * @param string $background    auto (ab der Schwelle) | yes | no
     * @return array  total, done, failed, errors (Liste aus record_id, messages) - bzw. background (token, pid)
     */
    #[ApiMethod('record.batch', '#record.api_batch', write: true, level: ApiMethod::LEVEL_MASS)]
    public function batch(int $areaId, string $mode, array $ids = [], array $filter = [], array $filterop = [], array $filtermode = [],
        bool $all = false, array $data = [], array $relations = [], array $files = [], int $targetAreaId = 0, array $mapping = [],
        bool $autoMap = true, bool $simulate = false, string $background = 'auto'): array
    {
        $this->context->require();
        $batch = $this->container->get(RecordBatch::class);
        if (!in_array($mode, RecordBatch::MODES, true)) throw new ApiException(ApiException::INVALID_PARAM, "invalid mode \"{$mode}\"");
        if (!in_array($background, ['auto', 'yes', 'no'], true)) throw new ApiException(ApiException::INVALID_PARAM, 'background must be auto, yes or no');
        if (!$this->access->moduleRight('Record', 'batch')) {
            throw new ApiException(ApiException::ACCESS_DENIED, $this->cms->snippet('error_access_denied'));
        }
        $areaData = $this->readableArea($areaId);

        // Umfang: genau eins von ids, filter, all
        $filterParams = $batch->filterParams(['filter' => $filter, 'filterop' => $filterop, 'filtermode' => $filtermode]);
        $scopes = (int)!empty($ids) + (int)$batch->hasFilter($filterParams) + (int)$all;
        if ($scopes !== 1) throw new ApiException(ApiException::INVALID_PARAM, 'give exactly one of ids, filter or all');
        $recordIds = !empty($ids)
            ? $this->system->sanitizeIds($ids, true)
            : $batch->collectIds($areaId, $all ? [] : $filterParams);

        $job = ['mode' => $mode, 'area_id' => $areaId, 'simulate' => $simulate];
        if ($mode === 'edit') {
            $input = $this->writeInput($areaId, (int)$areaData['instance_id'], $data, [], $relations, $files, -1, -1, 0);
            $allowed = $batch->batchFields($this->record->virtualSchema($areaId, false));
            $fields = array_values(array_unique(array_merge($input['fields'], array_keys($input['relations']), array_keys($input['files']))));
            foreach ($fields as $name) {
                $type = (string)($allowed[$name]['type'] ?? '');
                if (!isset($allowed[$name]) || $type === 'password' || ($allowed[$name]['display_type'] ?? '') === 'password') {
                    $this->removeDir($input['tmp_dir']);
                    throw new ApiException(ApiException::INVALID_PARAM, "field \"{$name}\" cannot be changed in a batch");
                }
            }
            if (!$fields) throw new ApiException(ApiException::INVALID_PARAM, $this->cms->snippet('record.error_batch_no_fields'));
            $job += ['fields' => $fields, 'data' => $input['data'], 'stored' => $input['stored'], 'relations' => $input['relations'],
                'files' => $input['files'], 'files_dir' => $input['tmp_dir'], 'prehashed' => []];
        }
        elseif ($mode === 'move') {
            [$sourceFields, $targetFields] = $this->moveFieldSets($areaId, $targetAreaId);
            $given = array_map(fn($v) => is_scalar($v) ? (string)$v : '', $mapping);
            $mapping = $autoMap
                ? array_replace($this->record->moveDefaultMapping($sourceFields, $targetFields), $given)
                : array_replace(array_fill_keys(array_keys($sourceFields), ''), $given);
            $this->cms->errorsReset();
            if ($this->record->moveMapping($sourceFields, $targetFields, $mapping) === null) {
                throw $this->context->exceptionFromCms(ApiException::VALIDATION);
            }
            $job += ['target_area_id' => $targetAreaId, 'mapping' => $mapping];
        }
        $job['ids'] = $recordIds;

        if ($background === 'yes' || ($background === 'auto' && $batch->needsBackground(count($recordIds)))) {
            $error = '';
            $started = $batch->start($job, $error);
            if ($started === null) {
                $batch->cleanupJob($job);
                throw new ApiException(ApiException::INTERNAL, $this->cms->snippet('record.error_batch_start') . ' ' . $error);
            }
            return ['total' => count($recordIds), 'background' => ['token' => (string)$started['token'], 'pid' => (int)$started['pid']]];
        }
        $result = $batch->run($job);
        $errors = [];
        foreach ($result['errors'] as $id => $entry) {
            $errors[] = ['record_id' => (int)$id ?: null, 'messages' => array_map(fn($m) => $this->context->plain((string)$m), (array)$entry['messages'])];
        }
        return ['total' => $result['total'], 'done' => $result['done'], 'failed' => $result['failed'], 'simulated' => $simulate, 'errors' => $errors];
    }

    // ====================
    // record.export

    /**
     * Die wählbaren Felder eines Exports (wie in der Export-Maske).
     *
     * @param int    $areaId  Bereich
     * @param string $type    csv | docx
     * @return array  Liste aus key (für fields von record.export), label, type, multilingual, checked (Vorauswahl), sub (Unterfelder: key, label, checked)
     */
    #[ApiMethod('record.export_fields', '#record.api_export_fields')]
    public function exportFields(int $areaId, string $type = 'csv'): array
    {
        $this->context->require();
        $this->readableArea($areaId);
        if (!in_array($type, ['csv', 'docx'], true)) throw new ApiException(ApiException::INVALID_PARAM, 'type must be csv or docx');
        $export = $this->container->get(RecordExport::class);
        $entries = $type === 'csv' ? $export->csvFields($areaId) : $export->docxFields($areaId);
        $list = [];
        foreach ($entries as $key => $entry) {
            $sub = [];
            foreach ((array)($entry['sub'] ?? []) as $subKey => $subEntry) {
                $sub[] = ['key' => $key . '.' . $subKey, 'label' => $this->context->plain($this->cms->textOrSnippet((string)($subEntry['label'] ?? $subKey))),
                    'checked' => !empty($subEntry['checked'])];
            }
            $list[] = [
                'key'          => (string)$key,
                'label'        => $this->context->plain($this->cms->textOrSnippet((string)($entry['label'] ?? $key))),
                'type'         => (string)($entry['type'] ?? ''),
                'multilingual' => !empty($entry['multilingual']),
                // DOCX: in der Maske sind alle Felder vorgewählt
                'checked'      => $type === 'docx' || !empty($entry['checked']),
                'sub'          => $sub,
            ];
        }
        return $list;
    }

    /**
     * Datensätze eines Bereichs exportieren - als Zip unter var/export (wie die
     * Export-Maske). XML nur für Superuser, CSV und DOCX mit dem Zusatzrecht.
     *
     * @param int    $areaId      Bereich
     * @param string $type        csv | docx | xml
     * @param array  $ids         diese Datensätze (Umfang, alternativ zu filter)
     * @param array  $filter      Filter wie record.search (Umfang); ohne ids und filter: alle
     * @param array  $filterop    Operatoren zu filter
     * @param array  $filtermode  OR | AND je Feld
     * @param array  $fields      csv/docx: Feldschlüssel aus record.export_fields (leer = Vorauswahl)
     * @param array  $languages   csv: Sprachcodes (leer = Sprache des Backends); docx: [von, nach]
     * @param array  $options     csv: header, bom (bool), separator, enclosure, eol ('\r\n' | '\n'); xml: files (bool);
     *                            docx: split (bool, ein Datensatz je Datei)
     * @param string $background  auto (ab admin.export_background_threshold) | yes | no
     * @return array  zip, path, size, records, files, valid_until - bzw. background (token, pid)
     */
    #[ApiMethod('record.export', '#record.api_export')]
    public function export(int $areaId, string $type = 'csv', array $ids = [], array $filter = [], array $filterop = [], array $filtermode = [],
        array $fields = [], array $languages = [], array $options = [], string $background = 'auto'): array
    {
        $this->context->require();
        if (!in_array($type, $this->service->transferTypes('export'), true)) {
            throw new ApiException(ApiException::ACCESS_DENIED, $this->cms->snippet('error_access_denied'));
        }
        if (!in_array($background, ['auto', 'yes', 'no'], true)) throw new ApiException(ApiException::INVALID_PARAM, 'background must be auto, yes or no');
        $areaData = $this->readableArea($areaId);
        $instanceId = (int)$areaData['instance_id'];
        $export = $this->container->get(RecordExport::class);
        $batch = $this->container->get(RecordBatch::class);

        [$sort, $order] = $export->exportSort($areaId);
        $filterParams = $batch->filterParams(['filter' => $filter, 'filterop' => $filterop, 'filtermode' => $filtermode]);
        $recordIds = $batch->collectIds($areaId, $filterParams, $sort, $order);
        if ($ids) {
            $wanted = $this->system->sanitizeIds($ids, true);
            $recordIds = array_values(array_filter($recordIds, fn($id) => in_array($id, $wanted, true)));
        }

        // Sprachen: Codes -> language_ids
        $languageIds = [];
        foreach ($languages as $code) {
            $languageId = $this->context->languageId((string)$code, $instanceId);
            if ($languageId === null) throw new ApiException(ApiException::INVALID_PARAM, "unknown language \"{$code}\"");
            $languageIds[] = $languageId;
        }
        if ($type === 'csv' && !$languageIds) $languageIds = [array_key_first($this->contentLanguages($instanceId, ''))];
        if ($type === 'docx' && count($languageIds) !== 2) throw new ApiException(ApiException::INVALID_PARAM, 'docx needs languages [from, to]');

        // Felder: ohne Angabe die Vorauswahl der Maske
        if ($type !== 'xml' && !$fields) {
            foreach ($this->exportFields($areaId, $type) as $entry) {
                if ($entry['checked']) $fields[] = $entry['key'];
                foreach ($entry['sub'] as $sub) if ($sub['checked']) $fields[] = $sub['key'];
            }
        }

        $this->cms->errorsReset();
        $job = $export->job($type, $areaId, $recordIds, [
            'files'     => !empty($options['files']),
            'header'    => (bool)($options['header'] ?? true),
            'bom'       => !empty($options['bom']),
            'separator' => (string)($options['separator'] ?? ';'),
            'enclosure' => (string)($options['enclosure'] ?? '"'),
            'eol'       => (string)($options['eol'] ?? '\r\n'),
            'languages' => $languageIds,
            'fields'    => $fields,
            'from'      => (int)($languageIds[0] ?? 0),
            'to'        => (int)($languageIds[1] ?? 0),
            'split'     => !empty($options['split']),
        ]);
        if ($job === null) throw $this->context->exceptionFromCms(ApiException::VALIDATION);

        if ($background === 'yes' || ($background === 'auto' && $export->needsBackground(count($job['ids'])))) {
            $error = '';
            $started = $export->start($job, $error);
            if ($started === null) throw new ApiException(ApiException::INTERNAL, $this->cms->snippet('record.error_batch_start') . ' ' . $error);
            return ['records' => count($job['ids']), 'background' => ['token' => (string)$started['token'], 'pid' => (int)$started['pid']]];
        }
        $result = $export->run($job);
        if (!empty($result['error'])) throw new ApiException(ApiException::VALIDATION, $this->context->plain((string)$result['error']));
        return [
            'zip'         => (string)$result['zip'],
            'path'        => rtrim((string)$this->config->get('paths.var_dir'), '/') . '/export/' . $result['zip'],
            'size'        => (int)$result['size'],
            'records'     => (int)$result['records'],
            'files'       => (int)$result['files'],
            'valid_until' => $this->context->isoTime($export->validUntil()),
        ];
    }

    /**
     * Die vorhandenen Exportdateien (var/export), neueste zuerst.
     *
     * @return array  Liste aus zip, path, size, created_at, valid_until
     */
    #[ApiMethod('record.exports', '#record.api_exports')]
    public function exports(): array
    {
        $this->context->require();
        if (!$this->service->transferTypes('export')) {
            throw new ApiException(ApiException::ACCESS_DENIED, $this->cms->snippet('error_access_denied'));
        }
        $export = $this->container->get(RecordExport::class);
        $dir = rtrim((string)$this->config->get('paths.var_dir'), '/') . '/export/';
        $list = [];
        foreach ($export->zipFiles() as $zip => $info) {
            $list[] = [
                'zip'         => (string)$zip,
                'path'        => $dir . $zip,
                'size'        => (int)$info['size'],
                'created_at'  => $this->context->isoTime(gmdate('Y-m-d H:i:s', (int)$info['time'])),
                'valid_until' => $this->context->isoTime($export->validUntil((int)$info['time'])),
            ];
        }
        return $list;
    }

    /** Lesbarer Inhaltsbereich - sonst not_found bzw. access_denied */
    private function readableArea(int $areaId): array
    {
        $areaData = $areaId > 0 ? $this->backend->areaData($areaId) : null;
        if (!$areaData || !\Camindo\Core\Modules\Area\Area::isContentType((string)$areaData['type'])) {
            throw new ApiException(ApiException::NOT_FOUND, $this->cms->snippet('area.error_area_not_found'));
        }
        if (!$this->access->areaRead($areaId)) {
            throw new ApiException(ApiException::ACCESS_DENIED, $this->cms->snippet('error_access_denied'));
        }
        return $areaData;
    }

    // ====================
    // Eingaben

    /**
     * Eingaben einer Schreib-Methode prüfen und in die Form von RecordService
     * bringen: Felder, Werte im Datenbankformat ($stored), Sprachen als language_id
     * (nur mit Schreibrecht), Verknüpfungen, Dateien (Base64 als Datei unter var/tmp).
     * Einzelfehler sammeln, dann VALIDATION.
     *
     * @return array  fields, data, stored, relations, files, tmp_dir
     */
    private function writeInput(int $areaId, int $instanceId, array $data, array $fields, array $relations, array $files,
        int $parentId, int $previousId, int $ownerId): array
    {
        $this->cms->errorsReset();
        $schemaVirtual = $this->record->virtualSchema($areaId, false);
        $languages = $this->instanceCache->languages($instanceId);
        $primaryId = (int)array_key_first($languages);
        $fields = $fields ? array_values(array_filter($fields, 'is_string')) : array_map('strval', array_keys($data));

        $out = ['fields' => [], 'data' => [], 'stored' => [], 'relations' => [], 'files' => [], 'tmp_dir' => ''];
        foreach ($fields as $name) {
            if (!array_key_exists($name, $data)) {
                $this->cms->error($this->cms->snippet('error_value_mandatory'), $name);
                continue;
            }
            $colDef = $schemaVirtual[$name] ?? null;
            $type = (string)($colDef['type'] ?? '');
            if ($colDef === null || in_array($name, ['parent_id', 'position', 'previous_id'], true)
                || in_array($type, $this->cms->relationTypes, true) || $type === 'file') {
                // Verknüpfungen, Dateien und Baum haben eigene Parameter
                $this->cms->error(strtr($this->cms->snippet('error_field_unknown_type'), ['{name}' => $name]), $name);
                continue;
            }
            $label = $this->label($colDef, $name);
            $isMultilingual = $this->cms->isMultilingualField($colDef);
            $values = $this->byLanguage($data[$name], $isMultilingual && !($type === 'json' && array_is_list((array)$data[$name])), $instanceId, $primaryId, $name, $label);
            if ($values === null) continue;
            $stored = false;
            $converted = [];
            foreach ($values as $languageId => $value) {
                $errName = $isMultilingual ? $name . '.' . $languageId : $name;
                try {
                    [$converted[$languageId], $isStored] = $this->values->input($value, $colDef);
                    $stored = $stored || $isStored;
                }
                catch (\InvalidArgumentException $e) {
                    $this->cms->error($e->getMessage(), $errName, $label);
                }
            }
            $out['fields'][] = $name;
            $out['data'][$name] = $isMultilingual ? $converted : reset($converted);
            if ($stored) $out['stored'][] = $name;
        }

        // Baum und Position
        if ($parentId >= 0) {
            if (!isset($schemaVirtual['parent_id'])) $this->cms->error(strtr($this->cms->snippet('error_field_unknown_type'), ['{name}' => 'parent_id']), 'parent_id');
            else { $out['fields'][] = 'parent_id'; $out['data']['parent_id'] = $parentId; }
        }
        if ($previousId >= 0) {
            if (!isset($schemaVirtual['position'])) $this->cms->error(strtr($this->cms->snippet('error_field_unknown_type'), ['{name}' => 'previous_id']), 'previous_id');
            else { $out['fields'][] = 'position'; $out['data']['previous_id'] = $previousId; }
        }
        if ($ownerId > 0) {
            if (!$this->access->areaSetOwner($areaId)) {
                throw new ApiException(ApiException::ACCESS_DENIED, $this->cms->snippet('error_access_denied'));
            }
            $out['fields'][] = 'owner_id';
            $out['data']['owner_id'] = $ownerId;
        }

        // Verknüpfungen
        foreach ($relations as $name => $ops) {
            $colDef = $schemaVirtual[$name] ?? null;
            if ($colDef === null || !in_array((string)$colDef['type'], $this->cms->relationTypes, true) || !is_array($ops)
                || (!array_is_list($ops) && array_diff(array_keys($ops), ['set', 'add', 'remove', 'order']))) {
                $this->cms->error(strtr($this->cms->snippet('error_field_unknown_type'), ['{name}' => (string)$name]), (string)$name);
                continue;
            }
            $out['relations'][(string)$name] = $ops;
        }

        // Dateien
        foreach ($files as $name => $spec) {
            $colDef = $schemaVirtual[$name] ?? null;
            if ($colDef === null || (string)$colDef['type'] !== 'file' || (int)($colDef['parent_field_id'] ?? 0) > 0) {
                $this->cms->error(strtr($this->cms->snippet('error_field_unknown_type'), ['{name}' => (string)$name]), (string)$name);
                continue;
            }
            $label = $this->label($colDef, (string)$name);
            $isMultilingual = $this->cms->isMultilingualField($colDef);
            $isSpec = $spec === null || (is_array($spec) && array_intersect(array_keys($spec), ['serverfile', 'content', 'tmpfile']));
            $specs = $this->byLanguage($isSpec ? [$spec] : $spec, $isMultilingual && !$isSpec, $instanceId, $primaryId, (string)$name, $label, singleIsList: $isSpec);
            if ($specs === null) continue;
            $entries = [];
            foreach ($specs as $languageId => $one) {
                $langKey = $isMultilingual ? (string)$languageId : '';
                $entries[$langKey] = $this->fileInput($one, (string)$name, $label, $out['tmp_dir']);
            }
            $out['files'][(string)$name] = $entries;
        }

        if ($this->cms->hasErrors) {
            $this->removeDir($out['tmp_dir']);
            throw $this->context->exceptionFromCms(ApiException::VALIDATION);
        }
        return $out;
    }

    /**
     * Wert je Sprache: {Code: Wert} bei mehrsprachigen Feldern (ein einzelner Wert gilt
     * der Hauptsprache), sonst der Wert selbst - nur Sprachen mit Schreibrecht.
     *
     * @return array|null  language_id => Wert (einsprachig: [0 => Wert]); null bei Fehlern
     */
    private function byLanguage(mixed $value, bool $isMultilingual, int $instanceId, int $primaryId, string $name, string $label, bool $singleIsList = false): ?array
    {
        if (!$isMultilingual) return [0 => $singleIsList ? reset($value) : $value];
        if (!is_array($value) || array_is_list($value)) $value = [$primaryId => $singleIsList ? reset($value) : $value];
        $result = [];
        foreach ($value as $code => $one) {
            $languageId = $this->context->languageId((string)$code, $instanceId);
            if ($languageId === null) {
                $this->cms->error("unknown language \"{$code}\"", $name, $label);
                return null;
            }
            if (!$this->access->languageWrite($languageId)) {
                $this->cms->error($this->cms->snippet('error_access_denied'), $name . '.' . $languageId, $label);
                return null;
            }
            $result[$languageId] = $one;
        }
        return $result;
    }

    /**
     * Eine Datei-Angabe für RecordService: serverfile bleibt, tmpfile wird zur
     * importfile (nur unter paths.tmp_dir), content (Base64) landet als Datei in
     * einem eigenen Verzeichnis unter paths.tmp_dir; null = entfernen.
     */
    private function fileInput(mixed $spec, string $name, string $label, string &$tmpDir): ?array
    {
        if ($spec === null) return null;
        if (!is_array($spec)) {
            $this->cms->error($this->cms->snippet('error_invalid_format'), $name, $label);
            return null;
        }
        if (isset($spec['serverfile']) && is_string($spec['serverfile'])) {
            return ['serverfile' => $spec['serverfile']];
        }
        if (isset($spec['tmpfile']) && is_string($spec['tmpfile'])) {
            $filename = is_string($spec['filename'] ?? null) ? $spec['filename'] : basename($spec['tmpfile']);
            return ['importfile' => $spec['tmpfile'], 'filename' => $filename];
        }
        if (isset($spec['content']) && is_string($spec['content'])) {
            $max = (int)($this->config->get('api.base64_max_bytes') ?? 0);
            $content = base64_decode($spec['content'], true);
            if ($content === false || $content === '') {
                $this->cms->error($this->cms->snippet('error_invalid_format'), $name, $label);
                return null;
            }
            if ($max > 0 && strlen($content) > $max) {
                $this->cms->error($this->cms->snippet('error_file_upload'), $name, $label);
                return null;
            }
            $filename = basename(str_replace('\\', '/', (string)($spec['filename'] ?? '')));
            if ($filename === '' || $filename === '.' || $filename === '..') $filename = 'file';
            if ($tmpDir === '') {
                $base = (string)$this->config->get('paths.tmp_dir');
                $dirName = 'api-files-' . $this->crypt->randomKey(16);
                if (!$this->system->createDir($base, $dirName)) throw new \RuntimeException("cannot create {$base}/{$dirName}");
                $tmpDir = $base . '/' . $dirName;
            }
            $path = $tmpDir . '/' . count((array)glob($tmpDir . '/*')) . '-' . $filename;
            if (file_put_contents($path, $content) === false) throw new \RuntimeException("cannot write {$path}");
            return ['importfile' => $path, 'filename' => $filename];
        }
        $this->cms->error($this->cms->snippet('error_invalid_format'), $name, $label);
        return null;
    }

    /** Ein Verzeichnis unter paths.tmp_dir samt Inhalt entfernen */
    private function removeDir(string $dir): void
    {
        $base = realpath((string)$this->config->get('paths.tmp_dir'));
        $real = $dir !== '' ? realpath($dir) : false;
        if ($real === false || $base === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) return;
        foreach (glob($real . '/*') ?: [] as $file) @unlink($file);
        @rmdir($real);
    }

    /**
     * Vor einer Änderung: lesen dürfen, die Aktion dürfen, nicht von einem anderen
     * gesperrt (außer $unlock), nicht in der eigenen Maske offen.
     */
    private function requireAction(int $recordId, string $action, bool $unlock = false, bool $checkLocks = true): array
    {
        $data = $this->loadReadable($recordId);
        $this->cms->errorsReset();
        if (!$this->service->loadForAction($recordId, $action)) {
            throw $this->context->exceptionFromCms(ApiException::ACCESS_DENIED);
        }
        if ($checkLocks) {
            if (!$unlock && ($lock = $this->service->lockInfo($data))) {
                throw new ApiException(ApiException::LOCKED, $this->service->lockMessage($lock));
            }
            if ($open = $this->service->ownOpenCopy($recordId)) {
                throw new ApiException(ApiException::OPEN_IN_BACKEND, $this->service->openCopyMessage($open));
            }
        }
        return $data;
    }

    /** Antwort einer Schreib-Methode */
    private function writeResult(int $recordId, bool $simulate): array
    {
        $record = null;
        if ($recordId > 0 && ($data = $this->record->load($recordId))) $record = $this->head($data);
        $this->cms->errorsReset();
        return ['record_id' => $recordId, 'simulated' => $simulate, 'record' => $record];
    }

    /**
     * Felder für das Verschieben: [moveFields() Quelle, moveFields() Ziel] - der
     * Zielbereich muss ein anderer, lesbarer Inhaltsbereich sein.
     */
    private function moveFieldSets(int $sourceAreaId, int $targetAreaId): array
    {
        $target = $targetAreaId > 0 ? $this->backend->areaData($targetAreaId) : null;
        if (!$target || !\Camindo\Core\Modules\Area\Area::isContentType((string)$target['type'])) {
            throw new ApiException(ApiException::NOT_FOUND, $this->cms->snippet('area.error_area_not_found'));
        }
        if ($targetAreaId === $sourceAreaId) {
            throw new ApiException(ApiException::INVALID_PARAM, $this->cms->snippet('record.error_move_same_area'));
        }
        if (!$this->access->areaRead($targetAreaId)) {
            throw new ApiException(ApiException::ACCESS_DENIED, $this->cms->snippet('error_access_denied'));
        }
        return [
            $this->record->moveFields($this->record->virtualSchema($sourceAreaId, false)),
            $this->record->moveFields($this->record->virtualSchema($targetAreaId, false)),
        ];
    }

    /** Hat der Datensatz in diesem Feld einen Inhalt? (Verknüpfungen: mindestens eine) */
    private function hasValue(array $data, string $name, array $colDef): bool
    {
        $type = (string)($colDef['type'] ?? '');
        if (in_array($type, $this->cms->relationTypes, true)) {
            return $this->backend->relatedPage((int)$data['record_id'], $colDef, 0, 1)[1] > 0;
        }
        if ($this->cms->isMultilingualField($colDef)) {
            foreach ($this->instanceCache->languageIds((int)$data['instance_id']) as $languageId) {
                $cache = $this->cms->loadRecordCache((int)$data['record_id'], $languageId);
                $entry = $cache[$name] ?? null;
                if ($entry !== null && ($entry[1] ?? '') !== '' && ($entry[1] ?? null) !== [] && empty($entry[2])) return true;
            }
            return false;
        }
        $value = $data[$name] ?? null;
        return !($value === null || $value === '' || $value === []);
    }

    // ====================
    // Felder

    /**
     * Ein Feld für record.info / record.field_value.
     *
     * @param array $languages  language_id => Code (contentLanguages())
     * @param array $caches     language_id => Inhalts-Cache
     * @return array|null       null: Feld nicht zeigen (Zielbereich nicht lesbar)
     */
    private function fieldEntry(array $data, string $name, array $colDef, array $languages, array $caches, int $truncate): ?array
    {
        $type = (string)($colDef['type'] ?? '');
        $recordId = (int)$data['record_id'];
        $isMultilingual = $this->cms->isMultilingualField($colDef);
        $entry = ['label' => $this->label($colDef, $name), 'type' => $type, 'multilingual' => $isMultilingual];

        // Verknüpfungen: die ersten RELATED_MAX, Gesamtzahl
        if (in_array($type, $this->cms->relationTypes, true)) {
            if (!$this->record->relationTargetReadable($colDef)) return null;
            [$ids, $total] = $this->backend->relatedPage($recordId, $colDef, 0, self::RELATED_MAX);
            $entry['value'] = array_map(fn($id) => $this->recordRef($id), $ids);
            $entry['total'] = $total;
            $entry['more'] = $total > count($ids);
            return $entry;
        }
        if ($name === 'parent_id') {
            $entry['value'] = $this->recordRef((int)($data['parent_id'] ?? 0));
            return $entry;
        }
        if ($name === 'position') {
            $entry['type'] = 'previous';
            $entry['value'] = $this->recordRef((int)$this->presenter->previousId($data, $this->record->isTreeArea((int)$data['area_id'])));
            return $entry;
        }

        if ($isMultilingual && $caches) {
            $values = [];
            $fallback = [];
            foreach ($languages as $languageId => $code) {
                [$value, $isFallback] = $this->values->fromCache($caches[$languageId][$name] ?? null, $colDef);
                $values[$code] = $this->values->output($value, $colDef);
                if ($isFallback) $fallback[] = $code;
            }
            if (count($languages) > 1) {
                $entry['value'] = $values;
                $entry['fallback'] = $fallback;
            }
            else {
                $entry['value'] = reset($values);
                $entry['fallback'] = !empty($fallback);
            }
        }
        else {
            $entry['value'] = $this->values->output($data[$name] ?? null, $colDef);
        }

        // lange Texte kürzen
        if ($truncate > 0 && in_array($type, self::TEXT_TYPES, true)) {
            $cut = false;
            $shorten = function ($text) use ($truncate, &$cut) {
                if (!is_string($text) || mb_strlen($text) <= $truncate) return $text;
                $cut = true;
                return mb_substr($text, 0, $truncate) . '…';
            };
            $entry['value'] = is_array($entry['value']) ? array_map($shorten, $entry['value']) : $shorten($entry['value']);
            if ($cut) $entry['truncated'] = true;
        }
        return $entry;
    }

    /**
     * Die Eingabefelder eines eingebetteten Inhalts ('link'), ungekürzt.
     */
    private function embeddedFields(array $data, array $languages): array
    {
        $schemaVirtual = $this->record->virtualSchema((int)$data['area_id'], false);
        $caches = $this->caches((int)$data['record_id'], $languages);
        $fields = [];
        foreach ($schemaVirtual as $name => $colDef) {
            if ((int)($colDef['visibility_input'] ?? 0) !== 1) continue;
            if ((string)($colDef['type'] ?? '') === 'fulltext') continue;
            $entry = $this->fieldEntry($data, $name, $colDef, $languages, $caches, 0);
            if ($entry !== null) $fields[$name] = $entry;
        }
        return $fields;
    }

    /**
     * Sprachen der Inhalte: language_id => Code, nur lesbare.
     *
     * @param string $language  Code, 'all' oder '' (Sprache des Backends)
     */
    private function contentLanguages(int $instanceId, string $language): array
    {
        $all = [];
        foreach ($this->instanceCache->languages($instanceId) as $languageId => $row) {
            if ($this->access->languageRead((int)$languageId)) $all[(int)$languageId] = (string)$row['code'];
        }
        if ($language === 'all') return $all;
        if (trim($language) === '') {
            $languageId = $this->cms->recordLanguageId($instanceId);
            if (isset($all[$languageId])) return [$languageId => $all[$languageId]];
            return $all ? [array_key_first($all) => reset($all)] : [];
        }
        $languageId = $this->context->languageId($language, $instanceId);
        if ($languageId === null) throw new ApiException(ApiException::INVALID_PARAM, "unknown language \"{$language}\"");
        if (!isset($all[$languageId])) throw new ApiException(ApiException::ACCESS_DENIED, $this->cms->snippet('error_access_denied'));
        return [$languageId => $all[$languageId]];
    }

    /**
     * Inhalts-Caches (`record_cache`) der Sprachen - nur bei mehrsprachigen Instanzen
     * (sonst liefert Record::load() alles).
     */
    private function caches(int $recordId, array $languages): array
    {
        $caches = [];
        foreach (array_keys($languages) as $languageId) {
            $cache = $this->cms->loadRecordCache($recordId, (int)$languageId);
            if ($cache !== null) $caches[(int)$languageId] = $cache;
        }
        return $caches;
    }

    // ====================
    // Kopf, Metafelder, Verlauf

    /**
     * Kopfdaten eines Datensatzes (in jeder Antwort von record.info).
     */
    private function head(array $data): array
    {
        $recordId = (int)$data['record_id'];
        $areaId = (int)$data['area_id'];
        $ownerId = isset($data['owner_id']) ? (int)$data['owner_id'] : null;
        $lock = $this->service->lockInfo($data);
        $open = $this->service->ownOpenCopy($recordId);
        // Ändern: auch der aktuelle Status muss setzbar sein (Access::recordWrite())
        $areaWrite = $this->access->areaWrite($areaId, $ownerId);
        $write = $this->access->recordWrite($areaId, $ownerId, (int)($data['status_id'] ?? 0));
        $delete = $this->access->areaDelete($areaId, $ownerId);
        $targetsReadable = $this->record->relationTargetsReadable($areaId);

        $languages = [];
        foreach ($this->instanceCache->languages((int)$data['instance_id']) as $languageId => $row) {
            if (!$this->access->languageRead((int)$languageId)) continue;
            $languages[] = ['code' => (string)$row['code'], 'write' => $this->access->languageWrite((int)$languageId)];
        }

        return [
            'record_id'       => $recordId,
            'label'           => $this->plainTeaser('record', $recordId),
            'area'            => $this->areaRef($areaId),
            'instance_id'     => (int)$data['instance_id'],
            'status'          => ['status_id' => (int)$data['status_id'], 'label' => $this->plainTeaser('status', (int)$data['status_id'])],
            'needs_review'    => !empty($data['needs_review']),
            'created_at'      => $this->values->output($data['created_at'] ?? null, ['type' => 'timestampl']),
            'created_by'      => $this->adminRef((int)($data['created_by'] ?? 0)),
            'changed_at'      => $this->values->output($data['changed_at'] ?? null, ['type' => 'timestampl']),
            'changed_by'      => $this->adminRef((int)($data['changed_by'] ?? 0)),
            'owner'           => $this->adminRef((int)($ownerId ?? 0)),
            'lock'            => $lock ? ['admin' => $this->adminRef($lock['admin_id']), 'since' => $this->context->isoTime(substr($lock['changed_at'], 0, 19))] : null,
            'open_in_backend' => $open ? ['since' => $this->context->isoTime(substr($open['changed_at'], 0, 19))] : null,
            'can'             => [
                'write'  => $write,
                'delete' => $delete,
                'copy'   => $this->access->areaCreate($areaId) && $targetsReadable,
                'move'   => $areaWrite && $delete && $targetsReadable,
            ],
            'languages'       => $languages,
        ];
    }

    /**
     * Reiter meta: die festen Spalten von `record` (Baumfelder nur im Baum).
     */
    private function meta(array $data): array
    {
        $recordId = (int)$data['record_id'];
        $isTree = $this->record->isTreeArea((int)$data['area_id']);
        $meta = [
            'record_id'   => $recordId,
            'area_id'     => (int)$data['area_id'],
            'instance_id' => (int)$data['instance_id'],
            'status'      => ['status_id' => (int)$data['status_id'], 'label' => $this->plainTeaser('status', (int)$data['status_id'])],
            'template'    => (int)($data['template_id'] ?? 0) > 0
                ? ['template_id' => (int)$data['template_id'], 'label' => $this->plainTeaser('template', (int)$data['template_id'])]
                : null,
            'online_at'   => $this->values->output($data['online_at'] ?? null, ['type' => 'timestampl']),
            'offline_at'  => $this->values->output($data['offline_at'] ?? null, ['type' => 'timestampl']),
            'created_at'  => $this->values->output($data['created_at'] ?? null, ['type' => 'timestampl']),
            'created_by'  => $this->adminRef((int)($data['created_by'] ?? 0)),
            'changed_at'  => $this->values->output($data['changed_at'] ?? null, ['type' => 'timestampl']),
            'changed_by'  => $this->adminRef((int)($data['changed_by'] ?? 0)),
            'owner'       => $this->adminRef((int)($data['owner_id'] ?? 0)),
            'user_id'     => (int)($data['user_id'] ?? 0) ?: null,
            'needs_review'=> !empty($data['needs_review']),
        ];
        if ($isTree) {
            $meta['parent'] = $this->recordRef((int)($data['parent_id'] ?? 0));
            $meta['previous'] = $this->recordRef((int)$this->presenter->previousId($data, true));
            $meta['depth'] = (int)($data['depth'] ?? 0);
        }

        // "Verwendet von": Datensätze, die über irgendein Verknüpfungsfeld hierher zeigen
        // (nur in lesbaren Bereichen - Cms::select() schränkt ohnehin ein)
        $this->db->pushResult();
        $this->cms->select(['relation' => 'any', 'dest_id' => $recordId, 'count' => 'true', 'result' => 'false',
            'sql' => substr($this->backend->areaAccessSql(false, '`record`.`area_id`'), 5) ?: '']);
        $meta['used_by'] = (int)$this->db->num_rows_unlimited;
        $this->db->popResult();
        $this->cms->errorsReset();
        return $meta;
    }

    /**
     * Reiter history: die letzten Einträge aus dem Audit-Log mit den Änderungen je
     * Feld (AuditDiff::plainChanges()). Die IP-Adresse nur für Superuser. Ältere
     * Einträge und den ganzen Verlauf (auch gelöschter Datensätze) liefert
     * audit.history.
     */
    private function history(int $recordId, int $truncate): array
    {
        $rows = $this->auditLog->historyPage('record', $recordId, self::HISTORY_MAX + 1, 0, false);
        $superuser = $this->access->isSuperuser();
        $entries = [];
        foreach (array_slice($rows, 0, self::HISTORY_MAX) as $row) {
            $entry = [
                'audit_id' => (int)$row['audit_id'],
                'time'     => $this->context->isoTime(substr((string)$row['created_at'], 0, 19)),
                'action'   => (string)$row['action'],
                'actor'    => $this->auditDiff->actorLabel($row),
                'origin'   => (string)$row['origin'],
                'changes'  => $this->auditDiff->plainChanges((array)($row['changes'] ?? []), $truncate),
            ];
            if ($superuser) $entry['ip'] = (string)$row['ip'];
            $entries[] = $entry;
        }
        $more = count($rows) > self::HISTORY_MAX;
        return ['entries' => $entries, 'more' => $more] + ($more ? ['older' => 'audit.history (skip ' . self::HISTORY_MAX . ')'] : []);
    }

    // ====================
    // Hilfen

    /**
     * Datensatz mit Leserecht laden - sonst not_found bzw. access_denied.
     */
    private function loadReadable(int $recordId): array
    {
        $this->cms->errorsReset();
        $data = $this->service->loadForAction($recordId, 'read');
        if ($data !== null) return $data;
        $denied = false;
        foreach ($this->cms->errors as $error) {
            if ((string)($error['text'] ?? '') === $this->cms->snippet('error_access_denied')) $denied = true;
        }
        throw $this->context->exceptionFromCms($denied ? ApiException::ACCESS_DENIED : ApiException::NOT_FOUND);
    }

    private function label(array $colDef, string $name): string
    {
        return $this->context->plain($this->cms->textOrSnippet((string)($colDef['label'] ?? $name)));
    }

    /** Teaser ohne Verweis und Symbol als Text (Status 0 ist ein gültiger Status) */
    private function plainTeaser(string $type, int $id): string
    {
        if ($id < 1 && $type !== 'status') return '';
        return $this->context->plain($this->backend->teaser($type, $id, linked: false, icon: false));
    }

    private function recordRef(int $recordId): ?array
    {
        return $recordId > 0 ? ['record_id' => $recordId, 'label' => $this->plainTeaser('record', $recordId)] : null;
    }

    private function adminRef(int $adminId): ?array
    {
        return $adminId > 0 ? ['admin_id' => $adminId, 'name' => $this->plainTeaser('admin', $adminId)] : null;
    }

    private function areaRef(int $areaId): array
    {
        $areaData = $this->backend->areaData($areaId) ?? [];
        return [
            'area_id' => $areaId,
            'name'    => (string)($areaData['name'] ?? ''),
            'label'   => $this->context->plain($this->cms->textOrSnippet((string)($areaData['label'] ?? ''))),
        ];
    }
}
