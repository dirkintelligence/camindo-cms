<?php
/**
 * API-Methoden der Status: auflisten, lesen, anlegen, ändern. Die beiden
 * Grundstatus (Id 0 und 1) lassen sich wie in der Oberfläche nicht ändern.
 *
 * @see Camindo\Core\Api\DatasetApi
 */
namespace Camindo\Core\Modules\Status;

use Camindo\Core\Api\ApiException;
use Camindo\Core\Api\ApiMethod;
use Camindo\Core\Api\DatasetApi;
use Camindo\Core\Api\DatasetWriter;

class StatusApi extends DatasetApi
{
    protected const MODULE = 'Status';
    protected const SCRIPTS = ['list' => 'status_list.php', 'info' => 'status_info.php', 'edit' => 'status_edit.php'];
    protected const FIELDS = ['label', 'icon', 'color_class', 'position'];

    public function __construct(Status $status, DatasetWriter $writer)
    {
        parent::__construct($status, $writer);
    }

    /**
     * Alle Status.
     *
     * @param bool $withSchema  dazu die Beschreibung der Felder (für status.create)
     */
    #[ApiMethod('status.list', '#status.api_list')]
    public function list(bool $withSchema = false): array
    {
        return $this->apiList($withSchema, '1');
    }

    /**
     * Einen Status anlegen.
     *
     * @param array $data      Feld => Wert (siehe status.list with_schema)
     * @param bool  $simulate  nur prüfen, nichts anlegen
     */
    #[ApiMethod('status.create', '#status.api_create', write: true, level: ApiMethod::LEVEL_ADMIN)]
    public function create(array $data, bool $simulate = false): array
    {
        return $this->apiCreate($data, $simulate);
    }

    /**
     * Einen Status ändern.
     *
     * @param int   $id        status_id (nicht 0 oder 1)
     * @param array $data      Feld => Wert, nur die zu ändernden
     * @param bool  $simulate  nur prüfen, nichts ändern
     */
    #[ApiMethod('status.update', '#status.api_update', write: true, level: ApiMethod::LEVEL_ADMIN)]
    public function update(int $id, array $data, bool $simulate = false): array
    {
        if ($id <= 1) throw new ApiException(ApiException::INVALID_PARAM, 'the basic states 0 and 1 cannot be changed');
        return $this->apiUpdate($id, $data, $simulate);
    }
}
