<?php
/**
 * Base of the CLI commands (Cli::COMMANDS).
 *
 * run() receives arguments and options as Cli::parse() delivers them, and returns the
 * exit code. Services come from the container ($this->get()), output via
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
     * Usage error: message + exit code 2
     */
    protected function usage(string $message): int
    {
        $this->err($message);
        return Cli::USAGE;
    }
}
