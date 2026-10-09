<?php
/**
 * API-Methoden der Templates: Template-Bereiche und Templates auflisten, ein
 * Template samt Quelltext lesen, anlegen, ändern.
 *
 * Gespeichert wird wie in der Maske: der Quelltext wird dabei kompiliert
 * (Template::hookOnSave()); Fehler des Compilers verhindern das Speichern nicht,
 * sie stehen danach in error_text. Der Probelauf kompiliert nur und nennt sie
 * (compile_errors). Rechte: Modul Templates und das Schreibrecht auf den
 * Template-Bereich (Dataset::save()).
 *
 * @see Camindo\Core\Api\DatasetApi
 */
namespace Camindo\Core\Modules\Template;

use Camindo\Core\Access;
use Camindo\Core\Cms;
use Camindo\Core\Database;
use Camindo\Core\History;
use Camindo\Core\Api\ApiContext;
use Camindo\Core\Api\ApiException;
use Camindo\Core\Api\ApiMethod;
use Camindo\Core\Api\DatasetApi;
use Camindo\Core\Api\DatasetWriter;

class TemplateApi extends DatasetApi
{
    protected const MODULE = 'Template';
    protected const SCRIPTS = ['list' => 'template_list.php', 'info' => 'template_info.php', 'edit' => 'template_edit.php'];
    protected const FIELDS = ['area_id', 'instance_id', 'type', 'name', 'label', 'standalone', 'error_text', 'changed_at', 'code'];
    protected const WRITABLE = ['area_id', 'name', 'label', 'code'];
    protected const HINTS = ['instance_id' => 'derived from area_id', 'error_text' => 'set by the compiler', 'changed_at' => 'set by the server'];
    /** In der Liste ohne Quelltext */
    private const LIST_FIELDS = ['area_id', 'type', 'name', 'label', 'standalone', 'error_text', 'changed_at'];

    public function __construct(
        private Template $template,
        DatasetWriter $writer,
        private Access $access,
        private Cms $cms,
        private Database $db,
        private History $history,
        private ApiContext $context,
    )
    {
        parent::__construct($template, $writer);
    }

    /**
     * Die Template-Bereiche, die der Admin lesen darf.
     */
    #[ApiMethod('template.areas', '#template.api_areas')]
    public function areas(): array
    {
        $this->writer->requireScript(self::MODULE, 'template_areas.php');
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `area_id`, `name`, `label`, `instance_id` FROM `area` WHERE `type` = 'template' AND `deleted` = 0 ORDER BY `lft`");
            $rows = $this->db->fetchAll();
        }
        finally {
            $this->db->popResult();
        }
        $list = [];
        foreach ($rows as $row) {
            if (!$this->access->areaRead((int)$row['area_id'])) continue;
            $list[] = ['area_id' => (int)$row['area_id'], 'name' => (string)$row['name'],
                'label' => $this->context->plain($this->cms->textOrSnippet((string)$row['label'])), 'instance_id' => (int)$row['instance_id'],
                'write' => $this->access->areaWrite((int)$row['area_id'], null)];
        }
        return ['areas' => $list];
    }

    /**
     * Die Templates eines Template-Bereichs (ohne Quelltext).
     *
     * @param int $areaId  Template-Bereich (template.areas)
     */
    #[ApiMethod('template.list', '#template.api_list')]
    public function list(int $areaId): array
    {
        $this->readableArea($areaId);
        $this->writer->requireScript(self::MODULE, self::SCRIPTS['list']);
        return ['items' => $this->writer->list($this->template, self::LIST_FIELDS, '`area_id` = ?', 'i', [$areaId])];
    }

    /**
     * Ein Template mit Quelltext und Compiler-Meldungen.
     *
     * @param int  $id          template_id
     * @param bool $withSchema  dazu die Beschreibung der Felder
     */
    #[ApiMethod('template.info', '#template.api_info')]
    public function info(int $id, bool $withSchema = false): array
    {
        $data = $this->writer->load($this->template, $id);
        $this->readableArea((int)$data['area_id']);
        return $this->apiInfo($id, $withSchema);
    }

    /**
     * Ein Template anlegen.
     *
     * @param int   $areaId    Template-Bereich
     * @param array $data      name (Pflicht), label, code
     * @param bool  $simulate  nur prüfen und kompilieren, nichts anlegen
     */
    #[ApiMethod('template.create', '#template.api_create', write: true, level: ApiMethod::LEVEL_ADMIN)]
    public function create(int $areaId, array $data, bool $simulate = false): array
    {
        $this->readableArea($areaId);
        unset($data['area_id']);
        $result = $this->apiCreate($data, $simulate, ['defaults' => ['area_id' => $areaId]]);
        return $this->finish($result, $simulate, (string)($data['code'] ?? ''), $areaId);
    }

    /**
     * Ein Template ändern (Quelltext, Name, Bezeichnung, Bereich).
     *
     * @param int   $id        template_id
     * @param array $data      Feld => Wert, nur die zu ändernden
     * @param bool  $simulate  nur prüfen und kompilieren, nichts ändern
     */
    #[ApiMethod('template.update', '#template.api_update', write: true, level: ApiMethod::LEVEL_ADMIN)]
    public function update(int $id, array $data, bool $simulate = false): array
    {
        $current = $this->writer->load($this->template, $id);
        $this->readableArea((int)$current['area_id']);
        $result = $this->apiUpdate($id, $data, $simulate, ['also' => ['styled_code', 'error_text']]);
        $code = array_key_exists('code', $data) ? (string)$data['code'] : (string)$current['code'];
        return $this->finish($result, $simulate, $code, (int)($data['area_id'] ?? $current['area_id']));
    }

    // ====================

    /** Probelauf: Compiler-Meldungen; gespeichert: "Zuletzt geändert" */
    private function finish(array $result, bool $simulate, string $code, int $areaId): array
    {
        if ($simulate) {
            $parsed = $this->template->parse($code, $areaId);
            $result['compile_errors'] = array_values(array_map(fn($e) => $this->context->plain((string)$e), (array)($parsed['errors'] ?? [])));
        }
        elseif ($result['id'] > 0) {
            $this->history->touch('template', (int)$result['id']);
        }
        return $result;
    }

    /** Template-Bereich mit Leserecht - sonst not_found bzw. access_denied */
    private function readableArea(int $areaId): void
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT 1 FROM `area` WHERE `area_id` = ? AND `type` = 'template' AND `deleted` = 0", 'i', [$areaId]);
            $found = $this->db->num_rows > 0;
        }
        finally {
            $this->db->popResult();
        }
        if (!$found) throw new ApiException(ApiException::NOT_FOUND, $this->cms->snippet('area.error_area_not_found'));
        if (!$this->access->areaRead($areaId)) throw new ApiException(ApiException::ACCESS_DENIED, $this->cms->snippet('error_access_denied'));
    }
}
