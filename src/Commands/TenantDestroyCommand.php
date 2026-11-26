<?php

namespace Sellmate\Laravel\MultiTenant\Commands;

use Illuminate\Console\Command;
use Sellmate\Laravel\MultiTenant\DatabaseManager;

class TenantDestroyCommand extends Command
{
    use TenantCommand, EnvCheck, RunsForEachTenant;

    protected DatabaseManager $manager;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = "tenant:destroy
                            {--domain= : The domain for tenant. 'all' or null value for all tenants.}
                            {--secret= : Secret for production}
                            {--continue-on-error : Keep going with the remaining tenants when one fails; exits non-zero if any failed.}";

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Drop database and user for tenant.';

    /**
     * Create a new command instance.
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
     * @return mixed
     */
    public function handle()
    {
        $tenants = $this->getTenants();

        $progressBar = $this->output->createProgressBar(count($tenants));

        $this->forEachTenant($tenants, function ($tenant) use ($progressBar) {
            $this->info("Deleting database and user for '{$tenant->name}'...");

            $this->manager->setTenantConnection($tenant);
            $this->checkEnv($this->manager->tenantConnectionName, $this->option('secret'));
            $this->manager->destroyTenant();

            $tenant->setup_has_done = false;
            $tenant->save();

            $progressBar->advance();
            $this->info("  Database and user for '{$tenant->name}' deleted successfully.");
        });
    }
}
