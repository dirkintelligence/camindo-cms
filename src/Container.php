<?php
/**
 * Dependency injection container with autowiring and shared services (singletons).
 *
 * Created once in Core.php and stored under $GLOBALS['app']. Every entry script
 * fetches its classes through it:
 *
 *     $adminBackend = $app->get(AdminBackend::class);
 *
 * THREE RESOLUTION PATHS (in this order, see get())
 * --------------------
 * 1. Instance already created -> returned
 * 2. Manually registered factory (set()) -> called once, result kept
 * 3. Autowiring -> analyze the constructor via reflection, fetch dependencies recursively
 *    from the container, instantiate the class
 *
 * SHARED SERVICES
 * --------------------
 * Every class exists exactly once per request. This explicitly also applies to
 * dataset subclasses: two $app->get(Admin::class) calls return the same instance. Whoever
 * stores state on the dataset in a method therefore shares it with all other callers.
 * The container is not meant for several independent instances of the same class.
 *
 * LIMITS OF AUTOWIRING
 * --------------------
 * Only constructor parameters typed as a class or an interface are resolved. Scalar
 * parameters (string, int, array, ...) and untyped parameters
 * lead to an exception - such classes must be registered with set().
 * In the codebase this only affects Config (it receives the configuration array from .camindo.php).
 *
 * An interface cannot be instantiated; if it is required as a dependency, it must either
 * be mapped to a concrete class via set() or - like ContainerInterface - be stored
 * directly in $instances in the constructor.
 */
namespace Camindo\Core;

use ReflectionClass;
use ReflectionNamedType;

class Container implements ContainerInterface
{
    /** Registered factories: id => callable(Container): object - only called when needed */
    private array $services = [];

    /** Finished instances: id => object - the actual singleton store */
    private array $instances = [];

    public function __construct()
    {
        // Register the container itself for the interface.
        $this->instances[ContainerInterface::class] = $this;

        // Optional: also register under the concrete class name,
        // in case someone uses "Container" instead of "ContainerInterface" as the type.
        $this->instances[self::class] = $this;
    }

    /**
     * Store a factory for a service (overwrites an existing registration).
     *
     * Needed for classes that autowiring cannot build - typically because their
     * constructor expects scalar values. The factory is only called on the first get().
     *
     * Note: once the instance has been created, it still comes from $instances -
     * a set() afterwards has no effect. So always register before the first use
     * (in camindo: in Core.php).
     *
     * @param string   $id       class/interface name, usually MyClass::class
     * @param callable $factory  receives the container as its only parameter
     */
    public function set(string $id, callable $factory): void
    {
        $this->services[$id] = $factory;
    }

    /**
     * Is $id resolvable?
     *
     * Also returns true when nothing is registered but the class exists -
     * autowiring would then apply. Whether that actually succeeds (instantiable,
     * resolvable constructor parameters) is not checked by has().
     */
    public function has(string $id): bool
    {
        return isset($this->services[$id]) || isset($this->instances[$id]) || class_exists($id);
    }

    /**
     * Get a service - creates it on the first call, the same instance afterwards.
     *
     * @param string $id  class/interface name, usually MyClass::class
     * @return mixed
     * @throws \Exception if neither an instance, a factory nor a class exists
     */
    public function get(string $id)
    {
        // 1. Has the instance already been created (or stored in the constructor)?
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        // 2. Is there a manually registered factory?
        if (isset($this->services[$id])) {
            $this->instances[$id] = $this->services[$id]($this);
            return $this->instances[$id];
        }

        // 3. Autowiring: try to resolve the class automatically
        if (class_exists($id)) {
            $this->instances[$id] = $this->autowire($id);
            return $this->instances[$id];
        }

        throw new \Exception("Service '$id' not found and cannot be resolved.");
    }

    /**
     * Autowiring: analyze the constructor and build the class with resolved dependencies.
     *
     * Note on recursion: since every dependency again goes through get(), the intermediate
     * steps also end up in $instances. A circular dependency (A needs B, B needs A)
     * is NOT detected and leads to endless recursion until a stack overflow -
     * the classes in the codebase are built without cycles.
     *
     * @param string $className
     * @return object
     * @throws \Exception for abstract classes/interfaces or unresolvable parameters
     */
    private function autowire(string $className)
    {
        $reflector = new ReflectionClass($className);

        // Check whether the class may be instantiated (not abstract, not an interface)
        if (!$reflector->isInstantiable()) {
            throw new \Exception("Instance of Class '$className' cannot be created.");
        }

        $constructor = $reflector->getConstructor();

        // If there is no constructor, simply instantiate
        if (is_null($constructor)) {
            return new $className();
        }

        $parameters = $constructor->getParameters();
        $dependencies = [];

        // Go through all constructor parameters
        foreach ($parameters as $parameter) {
            $type = $parameter->getType();

            // We can only resolve types that are real classes (no strings/ints)
            // Not covered: union/intersection types (no ReflectionNamedType) and
            // untyped parameters (getType() returns null) - both also end up here.
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                throw new \Exception("Cannot automatically resolve parameter '{$parameter->getName()}' of class '$className'. Please register it manually.");
            }

            // Recursive call: fetch the dependency from the container as well
            $dependencies[] = $this->get($type->getName());
        }

        // Create the class with the collected dependencies
        return $reflector->newInstanceArgs($dependencies);
    }
}
