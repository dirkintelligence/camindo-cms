<?php
/**
 * Der Converter-Klasse (Dataset-Kindklasse) die Prozessor-Klasse zuteilen
 *
 * Ein Konverter (Tabelle `converter`) speichert seinen Prozessor als schlichten String,
 * z. B. "ImageGd". Diese Factory macht daraus die passende Klasseninstanz. Sie ist nötig,
 * weil der Container nur feste Klassennamen auflöst - hier steht der Name erst zur
 * Laufzeit fest.
 *
 * Verwendet von Dataset (Property $processor) und von Backend / ConverterBackend, um die
 * Zusatz-Eingabefelder eines Prozessors zu holen (MediaProcessor::getSchema()).
 *
 * LAUFZEITLISTE
 * --------------------
 * Welche Prozessoren es gibt und wo ihre Klasse liegt, sagt Cms::$processors
 * (var/config/components/processors.php, ComponentManager): 'namespace' ist
 * Camindo\Core\Processors\{Name} für Core-Prozessoren und Camindo\Processors\{Name}
 * für Zusatzkomponenten. Ein Zusatz-Prozessor gleichen Namens ersetzt den Core-
 * Prozessor ('overrides'); ein Prozessor, der in der Liste fehlt (nicht installiert,
 * inaktiv, Update wartet), existiert für die Factory nicht.
 *
 * Konvention: Verzeichnis, Namespace-Segment und Klassenname sind identisch
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
     * Prozessor-Instanz zu einem Prozessor-Namen liefern.
     *
     * Die Instanz kommt aus dem Container, ist also ein Shared Service: derselbe
     * Prozessor-Name liefert pro Request immer dasselbe Objekt.
     *
     * @param string $name  Prozessor-Name aus `converter`.`processor`, z. B. 'ImageGd'
     * @return MediaProcessor
     * @throws \RuntimeException wenn der Prozessor nicht in der Laufzeitliste steht oder
     *                           seine Klasse fehlt (z. B. Konverter-Datensatz vorhanden,
     *                           Komponente aber deinstalliert oder inaktiv)
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
