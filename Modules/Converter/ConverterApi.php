<?php
/**
 * API-Methoden der Medienkonverter: Prozessoren, auflisten, lesen, anlegen, ändern.
 * Listen sind knapp (converter.list, converter.processors); die Einstellungen unter
 * details hängen vom Prozessor ab - converter.processor_info nennt sie.
 *
 * @see Camindo\Core\Api\DatasetApi
 */
namespace Camindo\Core\Modules\Converter;

use Camindo\Core\Cms;
use Camindo\Core\Api\ApiContext;
use Camindo\Core\Api\ApiException;
use Camindo\Core\Api\ApiMethod;
use Camindo\Core\Api\DatasetApi;
use Camindo\Core\Api\DatasetWriter;
use Camindo\Core\Modules\Area\Field;

class ConverterApi extends DatasetApi
{
    protected const MODULE = 'Converter';
    protected const SCRIPTS = ['list' => 'converter_list.php', 'info' => 'converter_info.php', 'edit' => 'converter_edit.php'];
    protected const FIELDS = ['processor', 'type', 'label', 'extension', 'media_types_incl', 'media_types_excl', 'details'];
    protected const WRITABLE = ['label', 'extension', 'media_types_incl', 'media_types_excl', 'details'];
    protected const HINTS = ['processor' => 'fixed since creation', 'type' => 'derived from the processor'];

    public function __construct(
        private Converter $converter,
        DatasetWriter $writer,
        private Field $field,
        private Cms $cms,
        private ApiContext $context,
    )
    {
        parent::__construct($converter, $writer);
    }

    /** Thumbnail-Felder, die den Konverter nutzen */
    protected function extra(int $id, array $data): array
    {
        return ['field_ids' => $this->field->getFieldIdsByConverter($id)];
    }

    /**
     * Die installierten Prozessoren - knapp: Name, Bezeichnung, Medienart,
     * Beschreibung. Die Einstellungen eines Prozessors nennt converter.processor_info.
     */
    #[ApiMethod('converter.processors', '#converter.api_processors')]
    public function processors(): array
    {
        $this->writer->requireScript(self::MODULE, 'converter_create.php');
        $list = [];
        foreach ($this->converter->processors as $name => $settings) {
            $list[] = [
                'processor'   => (string)$name,
                'label'       => $this->context->plain($this->cms->textOrSnippet((string)($settings['label'] ?? $name))),
                'type'        => (string)($settings['type'] ?? 'other'),
                'description' => $this->context->plain($this->cms->textOrSnippet((string)($settings['description'] ?? ''))),
            ];
        }
        return ['processors' => $list];
    }

    /**
     * Ein Prozessor mit seinen Einstellungen (Felder unter details) und den
     * vorbelegten Medientypen - für converter.create.
     *
     * @param string $processor  Prozessor (converter.processors)
     */
    #[ApiMethod('converter.processor_info', '#converter.api_processor_info')]
    public function processorInfo(string $processor): array
    {
        $this->writer->requireScript(self::MODULE, 'converter_create.php');
        if (!isset($this->converter->processors[$processor])) {
            throw new ApiException(ApiException::INVALID_PARAM, "unknown processor \"{$processor}\" (see converter.processors)");
        }
        $defaults = $this->converter->init(['processor' => $processor]) ?? [];
        return [
            'processor' => $processor,
            'type'      => (string)($this->converter->processors[$processor]['type'] ?? 'other'),
            'defaults'  => array_intersect_key($defaults, array_flip(['media_types_incl', 'media_types_excl', 'details'])),
            'schema'    => $this->writer->schema($this->converter, self::WRITABLE),
        ];
    }

    /**
     * Alle Konverter - knapp wie die Liste im Backend: Bezeichnung, Prozessor,
     * Medienart, Dateiendung und Zielgröße (Breite, Höhe, soweit der Prozessor sie
     * kennt). Alle Einstellungen nennt converter.info.
     */
    #[ApiMethod('converter.list', '#converter.api_list')]
    public function list(): array
    {
        $items = [];
        foreach ($this->apiList()['items'] as $item) {
            $entry = ['id' => (int)$item['id'],
                'label' => $this->context->plain($this->cms->textOrSnippet((string)($item['label'] ?? ''))),
                'processor' => (string)($item['processor'] ?? ''), 'type' => (string)($item['type'] ?? ''),
                'extension' => (string)($item['extension'] ?? '')];
            // Unterfelder kommen zerlegt (details.width)
            foreach (['width', 'height'] as $key) {
                $value = (int)($item['details.' . $key] ?? 0);
                if ($value > 0) $entry[$key] = $value;
            }
            $entry['fields'] = count($this->field->getFieldIdsByConverter((int)$item['id']));
            $items[] = $entry;
        }
        return ['items' => $items];
    }

    /**
     * Ein Konverter mit allen Einstellungen und den Feldern, die ihn nutzen.
     *
     * @param int  $id          converter_id
     * @param bool $withSchema  dazu die Beschreibung der Felder (samt denen des Prozessors)
     */
    #[ApiMethod('converter.info', '#converter.api_info')]
    public function info(int $id, bool $withSchema = false): array
    {
        return $this->apiInfo($id, $withSchema);
    }

    /**
     * Einen Konverter anlegen.
     *
     * @param string $processor  Prozessor (converter.processors)
     * @param array  $data       Feld => Wert, Einstellungen des Prozessors unter details
     * @param bool   $simulate   nur prüfen, nichts anlegen
     */
    #[ApiMethod('converter.create', '#converter.api_create', write: true, level: ApiMethod::LEVEL_ADMIN)]
    public function create(string $processor, array $data, bool $simulate = false): array
    {
        if (!isset($this->converter->processors[$processor])) {
            throw new ApiException(ApiException::INVALID_PARAM, "unknown processor \"{$processor}\" (see converter.processors)");
        }
        // type wie hookOnSave() - so darf ein passender Wert in data stehen (Werte aus converter.info)
        $type = (string)($this->converter->processors[$processor]['type'] ?? 'other');
        return $this->apiCreate($data, $simulate, ['defaults' => ['processor' => $processor, 'type' => $type]]);
    }

    /**
     * Einen Konverter ändern.
     *
     * @param int   $id        converter_id
     * @param array $data      Feld => Wert, nur die zu ändernden
     * @param bool  $simulate  nur prüfen, nichts ändern
     */
    #[ApiMethod('converter.update', '#converter.api_update', write: true, level: ApiMethod::LEVEL_ADMIN)]
    public function update(int $id, array $data, bool $simulate = false): array
    {
        return $this->apiUpdate($id, $data, $simulate);
    }
}
