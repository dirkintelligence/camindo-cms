<?php
/**
 * Basis der API-Klassen für Konfigurations-Datasets (Sprache, Status, Instanz,
 * Konverter, Zeitplaner, Template …): Liste, Detail, Anlegen, Ändern - über
 * DatasetWriter, mit denselben Rechten wie die Scripte der Oberfläche.
 *
 * Eine Unterklasse legt das Dataset, die Scripte und die Felder fest und
 * deklariert die API-Methoden selbst (das Attribut #[ApiMethod] trägt den Namen):
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
 * Antworten: list -> items (id + Felder), schema; info -> id, values, schema;
 * create/update -> id, simulated, fields (geschriebene Felder), values (Stand danach).
 *
 * Gezeigte, aber nicht schreibbare Felder stehen im Schema mit readonly (bzw.
 * create_only, wenn nur das Anlegen sie schreibt); HINTS erklärt in der
 * Fehlermeldung, woher ihr Wert kommt.
 */
namespace Camindo\Core\Api;

use Camindo\Core\Dataset;

abstract class DatasetApi
{
    /** Modul (Rechte über seine Scripte) */
    protected const MODULE = '';
    /** Scripte der Oberfläche je Aufgabe: list, info, edit */
    protected const SCRIPTS = [];
    /** Felder, die gezeigt werden */
    protected const FIELDS = [];
    /** Felder, die geschrieben werden dürfen ([] = FIELDS) */
    protected const WRITABLE = [];
    /** Felder, die nur das Anlegen schreibt (zusätzlich zu WRITABLE) */
    protected const CREATE_ONLY = [];
    /** Feld => Hinweis für die Fehlermeldung, wenn es nicht geschrieben werden darf */
    protected const HINTS = [];

    public function __construct(
        protected Dataset $dataset,
        protected DatasetWriter $writer,
    )
    {
    }

    /** Auswahlwerte, die erst zur Laufzeit feststehen (Feld => Liste) - für schema */
    protected function options(): array
    {
        return [];
    }

    /** Zusätzliche Angaben zu einem Datensatz (info), z. B. Verwendung */
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

    /** Optionen für DatasetWriter::write()/prepare() um die Hinweise ergänzt */
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

    /** Ergebnis von write() mit dem Stand danach */
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
