<?php

namespace Sellmate\Laravel\MultiTenant\Commands\Passport;

use Laravel\Passport\Console\InstallCommand as BaseInstallCommand;
use Sellmate\Laravel\MultiTenant\Commands\AddsTenantOptions;
use Sellmate\Laravel\MultiTenant\Commands\TenantCommand;
use Sellmate\Laravel\MultiTenant\DatabaseManager;
use Symfony\Component\Console\Input\InputOption;

class InstallCommand extends BaseInstallCommand
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
            ['tenant', 'T', InputOption::VALUE_NONE, "Install Passport for tenant. Use '--domain' option to specify tenant."],
            ['domain', null, InputOption::VALUE_OPTIONAL, "The domain for tenant. 'all' or null value for all tenants."],
        ];
    }

    /**
     * With --tenant, only creates the personal access and password grant clients in each
     * tenant database; keys, published files and migrations are shared and handled without it.
     */
    public function handle(): void
    {
        if (! $this->option('tenant')) {
            $this->setSystemDatabase();
            parent::handle();

            return;
        }

        if ($this->option('force') || $this->input->hasParameterOption('--length')) {
            $this->warn('--force/--length are ignored with --tenant. Run passport:keys to generate keys.');
        }

        $provider = array_key_exists('users', config('auth.providers', [])) ? 'users' : null;
        $idColumn = config('multitenancy.tenant-id-column', 'domain');

        $tenants = $this->getTenants();
        $this->setTenantDatabase();
        $progressBar = $this->output->createProgressBar(count($tenants));
        foreach ($tenants as $tenant) {
            $this->manager->setTenantConnection($tenant);
            $this->info("Install passport clients for '{$tenant->name}'...");
            $progressBar->advance();

            $tenantOptions = ['--tenant' => true, '--domain' => $tenant->{$idColumn}, '--provider' => $provider];
            $this->call('passport:client', $tenantOptions + ['--personal' => true, '--name' => config('app.name') . ' Personal Access Client']);
            $this->call('passport:client', $tenantOptions + ['--password' => true, '--name' => config('app.name') . ' Password Grant Client']);
        }
    }
}
