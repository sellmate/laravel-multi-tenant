<?php

namespace Sellmate\Laravel\MultiTenant\Commands\Passport;

use Laravel\Passport\Console\PurgeCommand as BasePurgeCommand;
use Sellmate\Laravel\MultiTenant\Commands\AddsTenantOptions;
use Sellmate\Laravel\MultiTenant\Commands\TenantCommand;
use Sellmate\Laravel\MultiTenant\DatabaseManager;
use Symfony\Component\Console\Input\InputOption;

class PurgeCommand extends BasePurgeCommand
{
    use TenantCommand, AddsTenantOptions;

    public function __construct()
    {
        parent::__construct();

        $this->manager = new DatabaseManager();
    }

    protected function tenantOptions(): array
    {
        return [
            ['tenant', 'T', InputOption::VALUE_NONE, "Purge tokens in tenant databases. Use '--domain' option to specify tenant."],
            ['domain', null, InputOption::VALUE_OPTIONAL, "The domain for tenant. 'all' or null value for all tenants."],
        ];
    }

    public function handle(): void
    {
        if (! $this->option('tenant')) {
            $this->setSystemDatabase();
            parent::handle();

            return;
        }

        $tenants = $this->getTenants();
        $this->setTenantDatabase();
        foreach ($tenants as $tenant) {
            $this->manager->setTenantConnection($tenant);
            $this->info("Purging tokens for '{$tenant->name}'...");
            parent::handle();
        }
    }
}
