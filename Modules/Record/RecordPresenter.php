<?php
/**
 * Datensätze für Listen aufbereiten - dieselbe Darstellung im Backend
 * (RecordBackendList, Lookup-Vorschau, "Zuletzt geändert") und in der API
 * (RecordApi::search(), dort ohne HTML).
 *
 * WELCHE SPALTEN
 * --------------------
 * field.visibility_list entscheidet (listFields()); status_id steht immer vorn.
 * Ohne sichtbares Feld bleibt die record_id.
 *
 * WIE EIN WERT AUSSIEHT
 * --------------------
 * listValue(): status_id als Symbol (mit Gegenlese-Hinweis), template_id,
 * parent_id und position (= der vorangehende Datensatz) als Klartext, alles andere
 * über Backend::backendValue() in der Kurzform der Liste. Ergebnis ist HTML.
 *
 * Dazu kann je Bereich ein Frontend-Template die letzte Spalte liefern
 * (`area`.`list_template_id`, listTemplateHtml()).
 *
 * Der Aufrufer klammert jede Zeile mit Database::pushResult()/popResult():
 * teaser(), previousId() und backendValue() setzen selbst Abfragen ab.
 *
 * @see Camindo\Core\Backend::backendValue()
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\Backend;
use Camindo\Core\Database;
use Camindo\Core\Page;

class RecordPresenter
{
    public function __construct(
        private Database $db,
        private Backend $backend,
    )
    {
    }

    /**
     * Felder eines Bereichs, die in der Datensatzliste stehen (visibility_list = 1) -
     * status_id immer vorn (Schnellfilter im Spaltenkopf). Ohne sichtbares Feld
     * bleibt die record_id, die dazu ins Schema eingetragen wird.
     *
     * @param array &$schemaVirtual  Schema des Bereichs (Record::virtualSchema())
     * @return string[]              Feldnamen in Listenreihenfolge
     */
    public function listFields(array &$schemaVirtual): array
    {
        $fields = [];
        foreach ($schemaVirtual as $name => $colDef) {
            if ((int)($colDef['visibility_list'] ?? 0) !== 1) continue;
            if ($name === 'status_id') array_unshift($fields, $name);
            else $fields[] = $name;
        }
        if (empty($fields)) {
            $fields = ['record_id'];
            $schemaVirtual['record_id'] = [
                'type' => 'integer',
                'label' => 'record_id'
            ];
        }
        return $fields;
    }

    /**
     * Wert eines Feldes für die Datensatzliste aufbereiten (siehe Klassenkommentar).
     *
     * @param string $name           Feldname
     * @param array  $row            Datensatz (Cms::fetch())
     * @param array  $schemaVirtual  Schema des Bereichs
     * @return string                HTML
     */
    public function listValue(string $name, array $row, array $schemaVirtual): string
    {
        if ($name === 'status_id') {
            $value = $this->backend->teaser('status', (int)($row['status_id'] ?? 0), icon:true, label:false);
            if (!empty($row['needs_review'])) {
                $value .= $this->backend->template('user.icon_unreviewed');
            }
            return $value;
        }
        if ($name === 'needs_review') {
            return !empty($row['needs_review'])
                ? $this->backend->template('user.icon_unreviewed')
                : $this->backend->emptyPlaceholder;
        }
        if ($name === 'template_id') {
            return $this->backend->teaser('template', (int)($row['template_id'] ?? 0), linked:false, icon:false);
        }
        if ($name === 'parent_id') {
            return $this->backend->teaser('record', (int)($row['parent_id'] ?? 0), linked:false, icon:false);
        }
        if ($name === 'position') {
            // vorangehenden Datensatz listen
            if ($previousId = $this->previousId($row, isset($schemaVirtual['parent_id']))) {
                return $this->backend->teaser('record', $previousId, linked:false, icon:false);
            }
            return '';
        }
        return $this->backend->backendValue($row[$name] ?? null, $schemaVirtual[$name] ?? [], id: (int)$row['record_id']);
    }

    /**
     * Listen-Template des Bereichs (`area`.`list_template_id`) für einen Datensatz
     * ausführen - '' wenn der Bereich keins gewählt hat. Das Template darf eigene
     * Abfragen absetzen (CMS:SELECT) - der Aufrufer klammert.
     *
     * @param int   $areaId  Bereich des Datensatzes
     * @param array $row     Datensatz (Cms::fetch())
     */
    public function listTemplateHtml(int $areaId, array $row): string
    {
        $templateId = (int)($this->backend->areaData($areaId)['list_template_id'] ?? 0);
        if ($templateId < 1) return '';
        return $GLOBALS['app']->get(Page::class)->renderRecord($templateId, $row);
    }

    /**
     * Id des vorangehenden Datensatzes - im Baum nach `lft` unter demselben
     * Vaterdatensatz, sonst nach `position` im Bereich.
     *
     * @param array $data  Datensatz (Record::load() / Cms::fetch())
     * @param bool  $tree  Bereich mit Eingabefeld parent_id (Baum)
     * @return int|null    record_id oder null (erster)
     */
    public function previousId(array $data, bool $tree): ?int
    {
        if ($tree) {
            if ($parentId = (int)$data['parent_id']) {
                $sql = "SELECT `record_id` FROM `record` WHERE `parent_id` = ? AND `lft` < ? AND `area_id`= ?"
                    ." ORDER BY `lft` DESC LIMIT 1";
                $this->db->query($sql, 'iii', [$parentId, (int)$data['lft'], (int)$data['area_id']]);
            }
            else {
                $sql = "SELECT `record_id` FROM `record` WHERE `parent_id` IS NULL AND `lft` < ? AND `area_id`= ?"
                    ." ORDER BY `lft` DESC LIMIT 1";
                $this->db->query($sql, 'ii', [(int)$data['lft'], (int)$data['area_id']]);
            }
        }
        else {
            $sql = "SELECT `record_id` FROM `record` WHERE `position` < ? AND `area_id`= ?"
                ." ORDER BY `position` DESC LIMIT 1";
            $this->db->query($sql, 'ii', [(int)$data['position'], (int)$data['area_id']]);
        }
        if ($previousRecord = $this->db->fetch()) {
            return (int)$previousRecord['record_id'];
        }
        return null;
    }
}
