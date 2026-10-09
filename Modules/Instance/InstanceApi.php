<?php
/**
 * API-Methoden der Instanzen (Websites): auflisten, lesen, ändern - Domains,
 * Sprachen, Startseite, Content-Security-Policy. Neue Instanzen legt nur die
 * Oberfläche an.
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
     * Alle Instanzen.
     *
     * @param bool $withSchema  dazu die Beschreibung der Felder
     */
    #[ApiMethod('instance.list', '#instance.api_list')]
    public function list(bool $withSchema = false): array
    {
        return $this->apiList($withSchema);
    }

    /**
     * Eine Instanz mit allen Einstellungen.
     *
     * @param int  $id          instance_id
     * @param bool $withSchema  dazu die Beschreibung der Felder
     */
    #[ApiMethod('instance.info', '#instance.api_info')]
    public function info(int $id, bool $withSchema = false): array
    {
        return $this->apiInfo($id, $withSchema);
    }

    /**
     * Eine Instanz ändern.
     *
     * @param int   $id        instance_id
     * @param array $data      Feld => Wert, nur die zu ändernden (Sprachen und Domains als Liste)
     * @param bool  $simulate  nur prüfen, nichts ändern
     */
    #[ApiMethod('instance.update', '#instance.api_update', write: true, level: ApiMethod::LEVEL_ADMIN)]
    public function update(int $id, array $data, bool $simulate = false): array
    {
        return $this->apiUpdate($id, $data, $simulate);
    }
}
