<?php
/**
 * Assigns the processor class to the converter class (dataset subclass)
 *
 * A converter (table `converter`) stores its processor as a plain string,
 * e.g. "ImageGd". This factory turns it into the matching class instance. It is needed
 * because the container only resolves fixed class names - here the name is only fixed
 * at runtime.
 *
 * Used by Dataset (property $processor) and by Backend / ConverterBackend to fetch the
 * additional input fields of a processor (MediaProcessor::getSchema()).
 *
 * RUNTIME LIST
 * --------------------
 * Which processors exist and where their class lives is defined by Cms::$processors
 * (var/config/components/processors.php, ComponentManager): 'namespace' is
 * Camindo\Core\Processors\{Name} for core processors and Camindo\Processors\{Name}
 * for add-on components. An add-on processor with the same name replaces the core
 * processor ('overrides'); a processor missing from the list (not installed,
 * inactive, update pending) does not exist for the factory.
 *
 * Convention: directory, namespace segment and class name are identical
 * ("ImageGd" -> Processors/ImageGd/ImageGd.php).
 */
namespace Camindo\Core;

use Camindo\Core\ContainerInterface;

class ProcessorFactory {
    private ContainerInterface $container;

    public function __construct(ContainerInterface $container) {
        $this->container = $container;
    }

    /**
     * Return the processor instance for a processor name.
     *
     * The instance comes from the container, so it is a shared service: the same
     * processor name always yields the same object per request.
     *
     * @param string $name  Processor name from `converter`.`processor`, e.g. 'ImageGd'
     * @return MediaProcessor
     * @throws \RuntimeException if the processor is not in the runtime list or its
     *                           class is missing (e.g. converter record exists, but the
     *                           component is uninstalled or inactive)
     */
    public function create(string $name) {
        $entry = $this->container->get(Cms::class)->processors[$name] ?? null;
        if ($entry === null) {
            throw new \RuntimeException("Error: Processor \"{$name}\" not installed or inactive.");
        }
        $class = ($entry['namespace'] ?? '') . "\\{$name}";
        if (!class_exists($class)) {
            throw new \RuntimeException("Error: Processor class \"{$class}\" not found.");
        }
        return $this->container->get($class);
    }
}
