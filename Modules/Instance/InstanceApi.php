<?php
/**
 * API methods of the instances (websites): list, read, change - domains,
 * languages, start page, Content Security Policy. Only the UI creates new instances.
 * UI.
 *
 * @see Camindo\Core\Api\DatasetApi
 */
namespace Camindo\Core\Modules\Instance;

use Camindo\Core\Api\ApiMethod;
use Camindo\Core\Api\DatasetApi;
use Camindo\Core\Api\DatasetWriter;

class InstanceApi extends DatasetApi
{
    protected const MODULE = 'Instance';
    protected const SCRIPTS = ['list' => 'instance_list.php', 'info' => 'instance_info.php', 'edit' => 'instance_edit.php'];
    protected const FIELDS = [
        'label', 'position', 'primary_language_id', 'additional_languages', 'primary_domain', 'protocol', 'canonical',
        'additional_domains', 'readable_instances', 'record_id', 'template_id', 'details',
    ];

    public function __construct(Instance $instance, DatasetWriter $writer)
    {
        parent::__construct($instance, $writer);
    }

    /**
     * All instances.
     *
     * @param bool $withSchema  plus the description of the fields
     */
    #[ApiMethod('instance.list', '#instance.api_list')]
    public function list(bool $withSchema = false): array
    {
        return $this->apiList($withSchema);
    }

    /**
     * An instance with all settings.
     *
     * @param int  $id          instance_id
     * @param bool $withSchema  plus the description of the fields
     */
    #[ApiMethod('instance.info', '#instance.api_info')]
    public function info(int $id, bool $withSchema = false): array
    {
        return $this->apiInfo($id, $withSchema);
    }

    /**
     * Change an instance.
     *
     * @param int   $id        instance_id
     * @param array $data      Field => value, only the ones to change (languages and domains as list)
     * @param bool  $simulate  only check, change nothing
     */
    #[ApiMethod('instance.update', '#instance.api_update', write: true, level: ApiMethod::LEVEL_ADMIN)]
    public function update(int $id, array $data, bool $simulate = false): array
    {
        return $this->apiUpdate($id, $data, $simulate);
    }
}
