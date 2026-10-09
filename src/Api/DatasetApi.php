<?php
/**
 * Base of the API classes for configuration datasets (language, status, instance,
 * converter, scheduler, template …): list, detail, create, change - via
 * DatasetWriter, with the same rights as the scripts of the interface.
 *
 * A subclass defines the dataset, the scripts and the fields and
 * declares the API methods itself (the attribute #[ApiMethod] carries the name):
 *
 *     class StatusApi extends DatasetApi
 *     {
 *         protected const MODULE = 'Status';
 *         protected const SCRIPTS = ['list' => 'status_list.php', 'info' => 'status_info.php', 'edit' => 'status_edit.php'];
 *         protected const FIELDS = ['label', 'icon', 'color_class', 'position'];
 *
 *         public function __construct(Status $status, DatasetWriter $writer) { parent::__construct($status, $writer); }
 *
 *         #[ApiMethod('status.list', '#status.api_list')]
 *         public function list(bool $withSchema = false): array { return $this->apiList($withSchema); }
 *         …
 *     }
 *
 * Responses: list -> items (id + fields), schema; info -> id, values, schema;
 * create/update -> id, simulated, fields (fields written), values (state afterwards).
 *
 * Shown but not writable fields are in the schema with readonly (or
 * create_only, if only creating writes them); HINTS explains in the
 * error message where their value comes from.
 */
namespace Camindo\Core\Api;

use Camindo\Core\Dataset;

abstract class DatasetApi
{
    /** Module (rights via its scripts) */
    protected const MODULE = '';
    /** Scripts of the interface per task: list, info, edit */
    protected const SCRIPTS = [];
    /** Fields that are shown */
    protected const FIELDS = [];
    /** Fields that may be written ([] = FIELDS) */
    protected const WRITABLE = [];
    /** Fields that only creating writes (in addition to WRITABLE) */
    protected const CREATE_ONLY = [];
    /** Field => note for the error message if it may not be written */
    protected const HINTS = [];

    public function __construct(
        protected Dataset $dataset,
        protected DatasetWriter $writer,
    )
    {
    }

    /** Choice values that are only fixed at runtime (field => list) - for schema */
    protected function options(): array
    {
        return [];
    }

    /** Additional details about a record (info), e.g. usage */
    protected function extra(int $id, array $data): array
    {
        return [];
    }

    protected function writable(): array
    {
        return static::WRITABLE ?: static::FIELDS;
    }

    protected function writableOnCreate(): array
    {
        return array_values(array_unique(array_merge($this->writable(), static::CREATE_ONLY)));
    }

    /** Options for DatasetWriter::write()/prepare() supplemented by the hints */
    protected function writeOptions(array $options = []): array
    {
        return static::HINTS ? $options + ['hints' => static::HINTS] : $options;
    }

    // ====================

    protected function apiList(bool $withSchema = false, string $where = '1', string $types = '', array $params = []): array
    {
        $this->writer->requireScript(static::MODULE, static::SCRIPTS['list']);
        $result = ['items' => $this->writer->list($this->dataset, static::FIELDS, $where, $types, $params)];
        if ($withSchema) $result['schema'] = $this->writer->schema($this->dataset, static::FIELDS, $this->options(), $this->writable(), static::CREATE_ONLY);
        return $result;
    }

    protected function apiInfo(int $id, bool $withSchema = false): array
    {
        $this->writer->requireScript(static::MODULE, static::SCRIPTS['info']);
        $data = $this->writer->load($this->dataset, $id);
        $result = ['id' => $id, 'values' => $this->writer->values($this->dataset, $data, static::FIELDS)] + $this->extra($id, $data);
        if ($withSchema) $result['schema'] = $this->writer->schema($this->dataset, static::FIELDS, $this->options(), $this->writable(), static::CREATE_ONLY);
        return $result;
    }

    /**
     * @param array $options  DatasetWriter::write() (defaults, extra, also, all)
     */
    protected function apiCreate(array $data, bool $simulate, array $options = []): array
    {
        $this->writer->requireScript(static::MODULE, static::SCRIPTS['edit']);
        return $this->result($this->writer->write($this->dataset, 0, $data, $this->writableOnCreate(), $simulate, $this->writeOptions($options)));
    }

    protected function apiUpdate(int $id, array $data, bool $simulate, array $options = []): array
    {
        $this->writer->requireScript(static::MODULE, static::SCRIPTS['edit']);
        return $this->result($this->writer->write($this->dataset, $id, $data, $this->writable(), $simulate, $this->writeOptions($options)));
    }

    /** Result of write() with the state afterwards */
    protected function result(array $written): array
    {
        $values = null;
        if ($written['id'] > 0) {
            $data = $this->dataset->load($written['id']);
            if ($data) $values = $this->writer->values($this->dataset, $data, static::FIELDS);
        }
        return $written + ['values' => $values];
    }
}
