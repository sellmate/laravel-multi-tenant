<?php

namespace Sellmate\Laravel\MultiTenant\Commands\Passport;

use Laravel\Passport\Console\HashCommand as BaseHashCommand;
use Sellmate\Laravel\MultiTenant\Commands\AddsTenantOptions;
use Sellmate\Laravel\MultiTenant\Commands\TenantCommand;
use Sellmate\Laravel\MultiTenant\DatabaseManager;
use Sellmate\Laravel\MultiTenant\Exceptions\TenantCommandException;
use Symfony\Component\Console\Input\InputOption;

class HashCommand extends BaseHashCommand
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
            ['tenant', 'T', InputOption::VALUE_NONE, "Hash client secrets in tenant databases. Use '--domain' option to specify tenant."],
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

        // Confirm once for the whole run instead of once per tenant. Without a terminal the prompt
        // auto-declines, which would leave secrets unhashed behind a successful exit code.
        if (! $this->option('force')) {
            if (! $this->input->isInteractive()) {
                throw new TenantCommandException('passport:hash --tenant needs --force when it cannot prompt for confirmation.');
            }

            if (! $this->components->confirm('Are you sure you want to hash all client secrets in every selected tenant? This cannot be undone.')) {
                $this->components->warn('Not confirmed; no client secrets were hashed. Pass --force to run without the prompt.');

                return;
            }
            $this->input->setOption('force', true);
        }

        $this->setTenantDatabase();
        foreach ($tenants as $tenant) {
            $this->manager->setTenantConnection($tenant);
            $this->info("Hashing client secrets for '{$tenant->name}'...");
            parent::handle();
        }
    }
}
