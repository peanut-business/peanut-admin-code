<?php

declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;

/** Native command names enter the same application lifecycle as installation and upgrade. */
final class NativeMigrationEntry extends Command
{
    public function __construct(private readonly string $entry)
    {
        parent::__construct();
    }

    protected function configure()
    {
        $schema = new ApplicationSchema();
        $this->setName($this->entry)->setDescription('Use the admitted Peanut application schema lifecycle');
        $this->setDefinition($this->entry === 'migrate:run'
            ? $schema->getDefinition()
            : array_values($schema->getDefinition()->getOptions()));
    }

    protected function execute(Input $input, Output $output): int
    {
        $schema = $this->getApp()->console->find('schema:application');
        if (!$schema instanceof ApplicationSchema) {
            throw new \RuntimeException('APPLICATION_SCHEMA_COMMAND_UNAVAILABLE');
        }
        $arguments = ['schema:application', $this->entry === 'migrate:status'
            ? 'preflight' : (string) $input->getArgument('operation')];
        foreach ($schema->getDefinition()->getOptions() as $name => $option) {
            if (!$input->hasOption($name)) { continue; }
            $value = $input->getOption($name);
            if ($value !== null && $value !== '' && $value !== false) {
                $arguments[] = '--' . $name . ($value === true ? '' : '=' . $value);
            }
        }
        // This forwarding command does not establish a competing execution context.
        // The real schema command owns console.schema:application and all native gates.
        return $schema->run(new Input($arguments), $output);
    }
}
