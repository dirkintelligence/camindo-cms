<?php
/**
 * Basis der CLI-Befehle (Cli::COMMANDS).
 *
 * run() bekommt Argumente und Optionen, wie Cli::parse() sie liefert, und gibt den
 * Exit-Code zurück. Dienste kommen aus dem Container ($this->get()), Ausgabe über
 * $this->cli->out()/err().
 */
namespace Camindo\Core\Cli;

use Camindo\Core\ContainerInterface;

abstract class Command {

    public function __construct(
        protected ContainerInterface $container,
        protected Cli $cli,
    )
    {
    }

    /**
     * @param string[]                   $args
     * @param array<string, string|true> $options
     */
    abstract public function run(array $args, array $options): int;

    /**
     * @template T
     * @param class-string<T> $class
     * @return T
     */
    protected function get(string $class): mixed
    {
        return $this->container->get($class);
    }

    protected function out(string $line = ''): void
    {
        $this->cli->out($line);
    }

    protected function err(string $line): void
    {
        $this->cli->err($line);
    }

    /**
     * Bedienfehler: Meldung + Exit-Code 2
     */
    protected function usage(string $message): int
    {
        $this->err($message);
        return Cli::USAGE;
    }
}
