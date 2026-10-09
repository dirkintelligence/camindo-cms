<?php
/**
 * API-Methoden der Sprachen: auflisten, lesen, anlegen, ändern. Welche Sprachen eine
 * Website führt, legt die Instanz fest (instance.update, Feld languages).
 *
 * @see Camindo\Core\Api\DatasetApi
 */
namespace Camindo\Core\Modules\Language;

use Camindo\Core\Cms;
use Camindo\Core\Api\ApiMethod;
use Camindo\Core\Api\DatasetApi;
use Camindo\Core\Api\DatasetWriter;
use Camindo\Core\Database;

class LanguageApi extends DatasetApi
{
    protected const MODULE = 'Language';
    protected const SCRIPTS = ['list' => 'language_list.php', 'info' => 'language_info.php', 'edit' => 'language_edit.php'];
    protected const FIELDS = ['code', 'backend_code', 'label', 'endonym', 'timezone', 'position'];

    public function __construct(Language $language, DatasetWriter $writer, private Cms $cms, private Database $db)
    {
        parent::__construct($language, $writer);
    }

    protected function options(): array
    {
        return ['backend_code' => $this->cms->backendLanguages()];
    }

    /** Instanzen, die die Sprache führen */
    protected function extra(int $id, array $data): array
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `instance_id` FROM `instance` WHERE `primary_language_id` = ?"
                . " UNION SELECT `instance_id` FROM `rel_instance_language` WHERE `language_id` = ?", 'ii', [$id, $id]);
            $instances = array_map('intval', $this->db->fetchAll());
        }
        finally {
            $this->db->popResult();
        }
        return ['instance_ids' => $instances];
    }

    /**
     * Alle Sprachen.
     *
     * @param bool $withSchema  dazu die Beschreibung der Felder (für language.create)
     */
    #[ApiMethod('language.list', '#language.api_list')]
    public function list(bool $withSchema = false): array
    {
        return $this->apiList($withSchema);
    }

    /**
     * Eine Sprache mit den Instanzen, die sie führen.
     *
     * @param int  $id          language_id
     * @param bool $withSchema  dazu die Beschreibung der Felder
     */
    #[ApiMethod('language.info', '#language.api_info')]
    public function info(int $id, bool $withSchema = false): array
    {
        return $this->apiInfo($id, $withSchema);
    }

    /**
     * Eine Sprache anlegen.
     *
     * @param array $data      Feld => Wert (siehe language.list with_schema)
     * @param bool  $simulate  nur prüfen, nichts anlegen
     */
    #[ApiMethod('language.create', '#language.api_create', write: true, level: ApiMethod::LEVEL_ADMIN)]
    public function create(array $data, bool $simulate = false): array
    {
        return $this->apiCreate($data, $simulate);
    }

    /**
     * Eine Sprache ändern.
     *
     * @param int   $id        language_id
     * @param array $data      Feld => Wert, nur die zu ändernden
     * @param bool  $simulate  nur prüfen, nichts ändern
     */
    #[ApiMethod('language.update', '#language.api_update', write: true, level: ApiMethod::LEVEL_ADMIN)]
    public function update(int $id, array $data, bool $simulate = false): array
    {
        return $this->apiUpdate($id, $data, $simulate);
    }
}
