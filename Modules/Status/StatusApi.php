<?php
/**
 * API methods of the statuses: list, read, create, change. The two
 * basic statuses (ids 0 and 1) cannot be changed, as in the UI.
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
     * All statuses.
     *
     * @param bool $withSchema  also the description of the fields (for status.create)
     */
    #[ApiMethod('status.list', '#status.api_list')]
    public function list(bool $withSchema = false): array
    {
        return $this->apiList($withSchema, '1');
    }

    /**
     * Create a status.
     *
     * @param array $data      field => value (see status.list with_schema)
     * @param bool  $simulate  only check, create nothing
     */
    #[ApiMethod('status.create', '#status.api_create', write: true, level: ApiMethod::LEVEL_ADMIN)]
    public function create(array $data, bool $simulate = false): array
    {
        return $this->apiCreate($data, $simulate);
    }

    /**
     * Change a status.
     *
     * @param int   $id        status_id (not 0 or 1)
     * @param array $data      field => value, only the ones to change
     * @param bool  $simulate  only check, change nothing
     */
    #[ApiMethod('status.update', '#status.api_update', write: true, level: ApiMethod::LEVEL_ADMIN)]
    public function update(int $id, array $data, bool $simulate = false): array
    {
        if ($id <= 1) throw new ApiException(ApiException::INVALID_PARAM, 'the basic states 0 and 1 cannot be changed');
        return $this->apiUpdate($id, $data, $simulate);
    }
}
