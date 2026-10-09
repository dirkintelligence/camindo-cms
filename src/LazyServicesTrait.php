<?php
/**
 * Optionale Abhängigkeiten beim ersten Zugriff aus dem Container holen.
 * 
 * (Sonderfall für selten gebrauchte Klassen. Pflicht-Abhängigkeiten gehören weiterhin
 * in den Konstruktor.)
 * Gewinn: Die Instanz wird nicht gebaut UND die Klassendatei wird gar nicht erst eingelesen.
 * Der Composer-Autoloader wird erst aktiv wenn der Container die Klasse anfasst.
 * Auch die "lazy loaded" Instanzen kommen aus dem Container und sind damit Shared Services
 * 
 * BEISPIELE
 * --------------------
 *     Admin      braucht als einzige Dataset-Kindklasse Email (Zwei-Faktor-Anmeldung)
 *     Cms        soll die Community-Klassen nur laden, wenn ein CMS:VISITOR_-Befehl
 *                im Template tatsächlich vorkommt
 *
 * VERWENDUNG
 * --------------------
 * Die nutzende Klasse braucht eine Container-Instanz in $this->container
 * (bei Dataset ist sie Konstruktor-Parameter). Dann:
 *
 *     class Admin extends Dataset {
 *         protected array $lazyServices = [
 *             'email' => Email::class,   // Property-Name => Klasse
 *         ];
 *
 *         private function foo() {
 *             $this->email->send($mail); // hier wird Email gebaut, vorher nicht
 *         }
 *     }
 *
 * $lazyServices ist bewusst eine deklarierte Liste und kein freier Container-Zugriff.
 * Die Abhängigkeiten einer Klasse bleiben oben in der Klasse ablesbar und auffindbar,
 * auch wenn sie nicht im Konstruktor stehen.
 * Ein @property-read-Eintrag im Klassen-Docblock macht sie zusätzlich für die IDE sichtbar.
 *
 * @see Camindo\Core\Container
 */
namespace Camindo\Core;

trait LazyServicesTrait
{
    /**
     * Nachladbare Dienste: Property-Name => Klassen-/Interface-Name.
     * In der nutzenden Klasse überschreiben (gleiche Sichtbarkeit und Typ beibehalten).
     */
    protected array $lazyServices = [];

    /** Bereits geholte Dienste: Property-Name => Instanz */
    private array $lazyInstances = [];

    /**
     * Zugriff auf ein nicht deklariertes Property - hier greift das Nachladen.
     *
     * Wird nur aufgerufen, wenn es kein sichtbares Property dieses Namens gibt. Deklariert
     * eine Klasse später ein echtes Property 'email', hat dieses Vorrang und der Trait
     * bleibt für diesen Namen wirkungslos.
     *
     * @param string $name  Schlüssel aus $lazyServices
     * @return mixed        Die Instanz aus dem Container
     * @throws \Exception   wenn der Name nicht in $lazyServices steht oder kein Container da ist
     */
    public function __get(string $name)
    {
        if (!isset($this->lazyInstances[$name])) {

            if (!isset($this->lazyServices[$name])) {
                throw new \Exception('Undefined property "' . $name . '" in class "' . static::class . '". Lazy services must be listed in $lazyServices.');
            }

            if (!isset($this->container) || !$this->container instanceof ContainerInterface) {
                throw new \Exception('Class "' . static::class . '" uses LazyServicesTrait but has no container in $this->container.');
            }

            $this->lazyInstances[$name] = $this->container->get($this->lazyServices[$name]);
        }

        return $this->lazyInstances[$name];
    }

    /**
     * isset()/empty() auf einem nachladbaren Dienst.
     *
     * Antwortet aus $lazyServices heraus, OHNE den Dienst zu erzeugen - sonst würde ein
     * beiläufiges isset() genau das Laden auslösen, das der Trait vermeiden soll.
     */
    public function __isset(string $name): bool
    {
        return isset($this->lazyServices[$name]);
    }
}
