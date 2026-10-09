<?php
/**
 * API methods of the templates: list template areas and templates, read a
 * template including its source code, create and change it.
 *
 * Saved as in the mask: the source code is compiled in the process
 * (Template::hookOnSave()); compiler errors do not prevent saving,
 * they are then stored in error_text. The trial run only compiles and names them
 * (compile_errors). Rights: module Templates and the write right on the
 * template area (Dataset::save()).
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
    /** In the list without source code */
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
     * The template areas that the admin may read.
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
     * The templates of a template area (without source code).
     *
     * @param int $areaId  template area (template.areas)
     */
    #[ApiMethod('template.list', '#template.api_list')]
    public function list(int $areaId): array
    {
        $this->readableArea($areaId);
        $this->writer->requireScript(self::MODULE, self::SCRIPTS['list']);
        return ['items' => $this->writer->list($this->template, self::LIST_FIELDS, '`area_id` = ?', 'i', [$areaId])];
    }

    /**
     * A template with source code and compiler messages.
     *
     * @param int  $id          template_id
     * @param bool $withSchema  also the description of the fields
     */
    #[ApiMethod('template.info', '#template.api_info')]
    public function info(int $id, bool $withSchema = false): array
    {
        $data = $this->writer->load($this->template, $id);
        $this->readableArea((int)$data['area_id']);
        return $this->apiInfo($id, $withSchema);
    }

    /**
     * Create a template.
     *
     * @param int   $areaId    template area
     * @param array $data      name (required), label, code
     * @param bool  $simulate  only check and compile, create nothing
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
     * Change a template (source code, name, label, area).
     *
     * @param int   $id        template_id
     * @param array $data      field => value, only the ones to change
     * @param bool  $simulate  only check and compile, change nothing
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

    /** Trial run: compiler messages; saved: "Last changed" */
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

    /** Template area with read right - otherwise not_found resp. access_denied */
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
