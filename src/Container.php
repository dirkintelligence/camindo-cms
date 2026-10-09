<?php
/**
 * Dependency-Injection-Container mit Autowiring und Shared Services (Singletons).
 *
 * Wird in Core.php einmal erzeugt und unter $GLOBALS['app'] abgelegt. Jedes Einstiegsscript
 * holt sich seine Klassen darüber:
 *
 *     $adminBackend = $app->get(AdminBackend::class);
 *
 * DREI AUFLÖSUNGSWEGE (in dieser Reihenfolge, siehe get())
 * --------------------
 * 1. Bereits erzeugte Instanz -> wird zurückgegeben
 * 2. Manuell registrierte Factory (set()) -> wird einmal aufgerufen, Ergebnis gemerkt
 * 3. Autowiring -> Konstruktor per Reflection analysieren, Abhängigkeiten rekursiv
 *    aus dem Container holen, Klasse instanziieren
 *
 * SHARED SERVICES
 * --------------------
 * Jede Klasse existiert pro Request genau einmal. Das gilt ausdrücklich auch für
 * Dataset-Kindklassen: zwei $app->get(Admin::class) liefern dieselbe Instanz. Wer in einer
 * Methode Zustand am Dataset ablegt, teilt ihn also mit allen anderen Aufrufern.
 * Für mehrere unabhängige Instanzen derselben Klasse ist der Container nicht gedacht.
 *
 * GRENZEN DES AUTOWIRING
 * --------------------
 * Aufgelöst werden nur Konstruktor-Parameter, die auf eine Klasse oder ein Interface
 * typisiert sind. Skalare Parameter (string, int, array, ...) und untypisierte Parameter
 * führen zu einer Exception - solche Klassen müssen mit set() registriert werden.
 * Im Bestand betrifft das nur Config (bekommt das Konfigurations-Array aus .camindo.php).
 *
 * Ein Interface lässt sich nicht instanziieren; wird es als Abhängigkeit verlangt, muss es
 * entweder per set() auf eine konkrete Klasse gemappt oder - wie ContainerInterface - im
 * Konstruktor direkt in $instances hinterlegt sein.
 */
namespace Camindo\Core;

use ReflectionClass;
use ReflectionNamedType;

class Container implements ContainerInterface
{
    /** Registrierte Factories: id => callable(Container): object - erst bei Bedarf aufgerufen */
    private array $services = [];

    /** Fertige Instanzen: id => object - der eigentliche Singleton-Speicher */
    private array $instances = [];

    public function __construct()
    {
        // Registriere den Container selbst für das Interface.
        $this->instances[ContainerInterface::class] = $this;

        // Optional: Auch für den konkreten Klassennamen registrieren,
        // falls jemand "Container" statt "ContainerInterface" als Typ nutzt.
        $this->instances[self::class] = $this;
    }

    /**
     * Factory für einen Service hinterlegen (überschreibt eine vorhandene Registrierung).
     *
     * Nötig für Klassen, die das Autowiring nicht bauen kann - typischerweise, weil ihr
     * Konstruktor skalare Werte erwartet. Die Factory wird erst beim ersten get() aufgerufen.
     *
     * Achtung: Wurde die Instanz bereits erzeugt, kommt sie weiterhin aus $instances -
     * ein set() danach bleibt wirkungslos. Also immer vor der ersten Verwendung registrieren
     * (in camindo: in Core.php).
     *
     * @param string   $id       Klassen-/Interface-Name, i. d. R. MeineKlasse::class
     * @param callable $factory  Erhält den Container als einzigen Parameter
     */
    public function set(string $id, callable $factory): void
    {
        $this->services[$id] = $factory;
    }

    /**
     * Ist $id auflösbar?
     *
     * Liefert auch dann true, wenn nichts registriert ist, die Klasse aber existiert -
     * dann würde Autowiring greifen. Ob das tatsächlich gelingt (instanziierbar,
     * auflösbare Konstruktor-Parameter), prüft has() nicht.
     */
    public function has(string $id): bool
    {
        return isset($this->services[$id]) || isset($this->instances[$id]) || class_exists($id);
    }

    /**
     * Service holen - erzeugt ihn beim ersten Aufruf, danach immer dieselbe Instanz.
     *
     * @param string $id  Klassen-/Interface-Name, i. d. R. MeineKlasse::class
     * @return mixed
     * @throws \Exception wenn weder Instanz noch Factory noch Klasse existiert
     */
    public function get(string $id)
    {
        // 1. Wurde die Instanz bereits erstellt (oder im Konstruktor hinterlegt)?
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        // 2. Gibt es eine manuell registrierte Factory?
        if (isset($this->services[$id])) {
            $this->instances[$id] = $this->services[$id]($this);
            return $this->instances[$id];
        }

        // 3. Autowiring: Versuche die Klasse automatisch aufzulösen
        if (class_exists($id)) {
            $this->instances[$id] = $this->autowire($id);
            return $this->instances[$id];
        }

        throw new \Exception("Service '$id' not found and cannot be resolved.");
    }

    /**
     * Autowiring: Konstruktor analysieren und die Klasse mit aufgelösten Abhängigkeiten bauen.
     *
     * Hinweis zur Rekursion: Da jede Abhängigkeit wieder über get() läuft, landen auch die
     * Zwischenstufen in $instances. Eine zyklische Abhängigkeit (A braucht B, B braucht A)
     * wird NICHT erkannt und führt zu einer Endlosrekursion bis zum Stack Overflow -
     * die Klassen im Bestand sind zyklenfrei aufgebaut.
     *
     * @param string $className
     * @return object
     * @throws \Exception bei abstrakten Klassen/Interfaces oder nicht auflösbaren Parametern
     */
    private function autowire(string $className)
    {
        $reflector = new ReflectionClass($className);

        // Prüfen, ob die Klasse instanziiert werden darf (nicht abstrakt, kein Interface)
        if (!$reflector->isInstantiable()) {
            throw new \Exception("Instance of Class '$className' cannot be created.");
        }

        $constructor = $reflector->getConstructor();

        // Wenn es keinen Konstruktor gibt, einfach instanziieren
        if (is_null($constructor)) {
            return new $className();
        }

        $parameters = $constructor->getParameters();
        $dependencies = [];

        // Gehe alle Parameter des Konstruktors durch
        foreach ($parameters as $parameter) {
            $type = $parameter->getType();

            // Wir können nur Typen auflösen, die echte Klassen sind (keine Strings/Ints)
            // Nicht erfasst: Union-/Intersection-Typen (kein ReflectionNamedType) und
            // untypisierte Parameter (getType() liefert null) - beides landet hier ebenfalls.
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                throw new \Exception("Cannot automatically resolve parameter '{$parameter->getName()}' of class '$className'. Please register it manually.");
            }

            // Rekursiver Aufruf: Hole die Abhängigkeit ebenfalls aus dem Container
            $dependencies[] = $this->get($type->getName());
        }

        // Erstelle die Klasse mit den gesammelten Abhängigkeiten
        return $reflector->newInstanceArgs($dependencies);
    }
}
