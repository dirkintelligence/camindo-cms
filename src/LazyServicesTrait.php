<?php
/**
 * Fetch optional dependencies from the container on first access.
 *
 * (A special case for rarely used classes. Mandatory dependencies still belong
 * in the constructor.)
 * Benefit: the instance is not built AND the class file is not even loaded.
 * The Composer autoloader only becomes active once the container touches the class.
 * "Lazy loaded" instances also come from the container and are therefore shared services.
 *
 * EXAMPLES
 * --------------------
 *     Admin      as the only Dataset subclass needs Email (two-factor sign-in)
 *     Cms        should load the community classes only when a CMS:VISITOR_ command
 *                actually occurs in the template
 *
 * USAGE
 * --------------------
 * The using class needs a container instance in $this->container
 * (for Dataset it is a constructor parameter). Then:
 *
 *     class Admin extends Dataset {
 *         protected array $lazyServices = [
 *             'email' => Email::class,   // Property-Name => Klasse
 *         ];
 *
 *         private function foo() {
 *             $this->email->send($mail); // Email is built here, not before
 *         }
 *     }
 *
 * $lazyServices is deliberately a declared list, not free container access.
 * A class's dependencies stay readable and easy to find at the top of the class,
 * even if they are not in the constructor.
 * A @property-read entry in the class docblock also makes them visible to the IDE.
 *
 * @see Camindo\Core\Container
 */
namespace Camindo\Core;

trait LazyServicesTrait
{
    /**
     * Lazily loadable services: property name => class/interface name.
     * Override in the using class (keep the same visibility and type).
     */
    protected array $lazyServices = [];

    /** Services already fetched: property name => instance */
    private array $lazyInstances = [];

    /**
     * Access to an undeclared property - lazy loading takes effect here.
     *
     * Only called if there is no visible property of this name. If a class later
     * declares a real property 'email', that one takes precedence and the trait
     * has no effect for this name.
     *
     * @param string $name  Key from $lazyServices
     * @return mixed        The instance from the container
     * @throws \Exception   if the name is not in $lazyServices or there is no container
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
     * isset()/empty() on a lazily loadable service.
     *
     * Answers from $lazyServices, WITHOUT creating the service - otherwise an incidental
     * isset() would trigger exactly the loading that the trait is meant to avoid.
     */
    public function __isset(string $name): bool
    {
        return isset($this->lazyServices[$name]);
    }
}
