<?php

namespace Sellmate\Laravel\MultiTenant\Commands\Migrate;

use Illuminate\Database\Console\Migrations\MigrateMakeCommand as BaseCommand;
use Sellmate\Laravel\MultiTenant\Commands\AddsTenantOptions;
use Symfony\Component\Console\Input\InputOption;

class MigrateMakeCommand extends BaseCommand
{
    use AddsTenantOptions;

    protected function tenantOptions(): array
    {
        return [
            ['system', 'S', InputOption::VALUE_NONE, 'Create migration for system database.'],
            ['tenant', 'T', InputOption::VALUE_NONE, 'Create migration for tenant databases.'],
            ['database', null, InputOption::VALUE_OPTIONAL, 'Create migration for databases.'],
        ];
    }

    protected function getMigrationPath()
    {
        $path = parent::getMigrationPath();
        if ($this->input->getOption('database')) {
            $path .= DIRECTORY_SEPARATOR . $this->input->getOption('database');
        } else if ($this->input->getOption('system')) {
            $path .= DIRECTORY_SEPARATOR . 'system';
        } elseif ($this->input->getOption('tenant')) {
            $path .= DIRECTORY_SEPARATOR . 'tenant';
        }
        if (!file_exists($path) || !is_dir($path)) {
            mkdir($path);
        }

        return $path;
    }
}
