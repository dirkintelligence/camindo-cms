<?php
/**
 * Vertrag des Dependency-Injection-Containers.
 *
 * Bewusst minimal gehalten und an PSR-11 angelehnt (dort: Psr\Container\ContainerInterface),
 * aber ohne dessen Exception-Interfaces - camindo bringt keine Container-Library mit.
 *
 * Klassen typisieren ihre Container-Abhängigkeit auf DIESES Interface, nicht auf die
 * konkrete Klasse Container (vgl. ProcessorFactory). Der Container registriert sich im
 * Konstruktor unter beiden Namen selbst, beide Schreibweisen funktionieren also.
 */
namespace Camindo\Core;

interface ContainerInterface
{
    /**
     * Service holen. Erzeugt ihn beim ersten Aufruf und liefert danach immer dieselbe Instanz.
     *
     * @param string $id  Klassen- oder Interface-Name, i. d. R. per MeineKlasse::class
     * @return mixed      Die Instanz
     * @throws \Exception wenn $id weder registriert noch auflösbar ist
     */
    public function get(string $id);

    /**
     * Kann get($id) etwas liefern?
     *
     * Achtung: true bedeutet nur "auflösbar", nicht "bereits erzeugt" - für eine
     * beliebige existierende Klasse liefert has() true, weil Autowiring greifen würde.
     *
     * @param string $id  Klassen- oder Interface-Name
     */
    public function has(string $id): bool;
}
?>