<?php
/**
 * Contract of the dependency injection container.
 *
 * Deliberately minimal and modeled on PSR-11 (there: Psr\Container\ContainerInterface),
 * but without its exception interfaces - camindo does not ship a container library.
 *
 * Classes type-hint their container dependency on THIS interface, not on the concrete
 * class Container (cf. ProcessorFactory). The container registers itself in its
 * constructor under both names, so both spellings work.
 */
namespace Camindo\Core;

interface ContainerInterface
{
    /**
     * Get a service. Creates it on the first call and returns the same instance afterwards.
     *
     * @param string $id  Class or interface name, usually via MyClass::class
     * @return mixed      The instance
     * @throws \Exception if $id is neither registered nor resolvable
     */
    public function get(string $id);

    /**
     * Can get($id) return something?
     *
     * Note: true only means "resolvable", not "already created" - for any existing
     * class has() returns true, because autowiring would apply.
     *
     * @param string $id  Class or interface name
     */
    public function has(string $id): bool;
}
?>