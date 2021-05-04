<?php

namespace Sellmate\Laravel\MultiTenant\Commands\Passport;

use Laravel\Passport\ClientRepository;
use Laravel\Passport\Console\ClientCommand as BaseClientCommand;
use Sellmate\Laravel\MultiTenant\Commands\TenantCommand;
use Sellmate\Laravel\MultiTenant\DatabaseManager;

class ClientCommand extends BaseClientCommand
{
    use TenantCommand;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = "passport:client
            {--T|tenant : Create a client for tenant. User '--domain' option for specify tenant.}
            {--domain= : The domain for tenant. 'all' or null value for all tenants.}
            {--personal : Create a personal access token client}
            {--password : Create a password grant client}
            {--client : Create a client credentials grant client}
            {--name= : The name of the client}
            {--provider= : The name of the user provider}
            {--redirect_uri= : The URI to redirect to after authorization }
            {--user_id= : The user ID the client should be assigned to }
            {--public : Create a public client (Auth code grant type only) }";

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
     * @param  \Laravel\Passport\ClientRepository  $clients
     * @return void
     */
    public function handle(ClientRepository $clients)
    {
        if ($this->option('tenant')) {
            $tenants = $this->getTenants();
            $this->setTenantDatabase(true);
            $progressBar = $this->output->createProgressBar(count($tenants));
            foreach ($tenants as $tenant) {
                $this->manager->setConnection($tenant);
                $this->info("Create passport client for '{$tenant->name}'...");
                $progressBar->advance();
                parent::handle($clients);
            }
        } else {
            $this->setSystemDatabase(true);
            parent::handle($clients);
        }
    }
}
