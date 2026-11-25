<?php

namespace Sellmate\Laravel\MultiTenant\Commands;

use Symfony\Component\Console\Input\InputOption;

/**
 * Appends the package's options to an extended framework command.
 *
 * Framework commands that declare `$signature` never consult getOptions(), so overriding
 * getOptions() silently drops the extra options. configureDefaults() runs after the
 * definition is built on both the signature and the getOptions() path.
 */
trait AddsTenantOptions
{
    /**
     * @return array<int, array{0: string, 1: string|null, 2: int, 3: string}> [name, shortcut, mode, description]
     */
    abstract protected function tenantOptions(): array;

    protected function configureDefaults(): void
    {
        parent::configureDefaults();

        foreach ($this->tenantOptions() as [$name, $shortcut, $mode, $description]) {
            $this->getDefinition()->addOption(new InputOption($name, $shortcut, $mode, $description));
        }
    }
}
