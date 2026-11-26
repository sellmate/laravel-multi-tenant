<?php

namespace Sellmate\Laravel\MultiTenant\Commands\Passport;

use Laravel\Passport\ClientRepository;
use Laravel\Passport\Console\ClientCommand as BaseClientCommand;
use Sellmate\Laravel\MultiTenant\Commands\AddsTenantOptions;
use Sellmate\Laravel\MultiTenant\Commands\RunsForEachTenant;
use Sellmate\Laravel\MultiTenant\Commands\TenantCommand;
use Sellmate\Laravel\MultiTenant\DatabaseManager;
use Symfony\Component\Console\Input\InputOption;

class ClientCommand extends BaseClientCommand
{
    use TenantCommand, AddsTenantOptions, RunsForEachTenant;

    public function __construct()
    {
        parent::__construct();

        $this->manager = new DatabaseManager();
    }

    protected function tenantOptions(): array
    {
        return [
            ['tenant', 'T', InputOption::VALUE_NONE, "Create a client for tenant. Use '--domain' option to specify tenant."],
            ['domain', null, InputOption::VALUE_OPTIONAL, "The domain for tenant. 'all' or null value for all tenants."],
            $this->continueOnErrorOption(),
        ];
    }

    public function handle(ClientRepository $clients): void
    {
        if ($this->option('tenant')) {
            $tenants = $this->getTenants();
            $this->setTenantDatabase();
            $progressBar = $this->output->createProgressBar(count($tenants));
            $this->forEachTenant($tenants, function ($tenant) use ($progressBar, $clients) {
                $this->manager->setTenantConnection($tenant);
                $this->info("Create passport client for '{$tenant->name}'...");
                $progressBar->advance();
                parent::handle($clients);
            });
        } else {
            $this->setSystemDatabase();
            parent::handle($clients);
        }
    }
}
