<?php

namespace Sellmate\Laravel\MultiTenant\Commands\Migrate;

use Illuminate\Database\Console\Migrations\InstallCommand;
use Illuminate\Support\Facades\DB;
use Sellmate\Laravel\MultiTenant\DatabaseManager;
use Symfony\Component\Console\Input\InputOption;

class MigrateInstallCommand extends InstallCommand
{
    use TenantCommand;

    /**
     * Create a new migration install command instance.
     *
     * @param  \Illuminate\Database\Migrations\MigrationRepositoryInterface  $repository
     * @return void
     */
    public function __construct(\Illuminate\Database\Migrations\MigrationRepositoryInterface $repository)
    {
        parent::__construct($repository);

        $this->manager = new DatabaseManager();
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        DB::setDefaultConnection($this->manager->systemConnectionName);
        
        if ($this->option('tenant')) {
            $tenants = $this->getTenants();
            $progressBar = $this->output->createProgressBar(count($tenants));
            $this->setTenantDatabase();
            foreach ($tenants as $tenant) {
                $this->manager->setConnection($tenant);
                $this->info("Creating migration table for '{$tenant->name}' database...");
                $progressBar->advance();
                parent::handle();
            }
        } else {
            if ($this->option('database') == $this->manager->tenantConnectionName) {
                $this->setTenantDatabase();
            } else {
                $this->setSystemDatabase();
            }
            parent::handle();
        }
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return array_merge([
            ['tenant', 'T', InputOption::VALUE_NONE, "Create the migration repository for tenant database."],
            ['domain', NULL, InputOption::VALUE_OPTIONAL, "The domain for tenant. 'all' or null value for all tenants."],
        ], parent::getOptions());
    }
}
