<?php

namespace Sellmate\Laravel\MultiTenant\Commands\Passport;

use Laravel\Passport\Console\InstallCommand as BaseInstallCommand;
use Sellmate\Laravel\MultiTenant\Commands\TenantCommand;
use Sellmate\Laravel\MultiTenant\DatabaseManager;

class InstallCommand extends BaseInstallCommand
{
    use TenantCommand;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = "passport:install
            {--T|tenant : Install Passport for tenant. User '--domain' option for specify tenant.}
            {--domain= : The domain for tenant. 'all' or null value for all tenants.}
            {--uuids : Use UUIDs for all client IDs}
            {--force : Overwrite keys they already exist}
            {--length=4096 : The length of the private key}";

    /**
     * Create a new console command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->manager = new DatabaseManager();
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        if ($this->option('tenant')) {
            $provider = in_array('users', array_keys(config('auth.providers'))) ? 'users' : null;

            if ($this->option('uuids')) {
                $this->configureUuids();
            }

            $tenants = $this->getTenants();
            $this->setTenantDatabase(true);
            $progressBar = $this->output->createProgressBar(count($tenants));
            foreach ($tenants as $tenant) {
                $this->manager->setConnection($tenant);
                $this->info("Install passport clients for '{$tenant->name}'...");
                $progressBar->advance();
                $tenantOptions = ['--tenant' => true];
                if ($this->option('domain')) {
                    $tenantOptions['--domain'] = $this->option('domain');
                }
                $this->call('passport:client', array_merge($tenantOptions, ['--personal' => true, '--name' => config('app.name').' Personal Access Client']));
                $this->call('passport:client', array_merge($tenantOptions, ['--password' => true, '--name' => config('app.name').' Password Grant Client', '--provider' => $provider]));
            }
        } else {
            $this->setSystemDatabase(true);
            parent::handle();
        }
    }
}
