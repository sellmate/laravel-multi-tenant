<?php

namespace Sellmate\Laravel\MultiTenant\Commands;

use Illuminate\Console\Command;
use Sellmate\Laravel\MultiTenant\DatabaseManager;
use Sellmate\Laravel\MultiTenant\Exceptions\TenantCommandException;

class TenantSetupCommand extends Command
{
    use TenantCommand, EnvCheck;

    protected DatabaseManager $manager;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = "tenant:setup
                            {--domain= : The domain for tenant. 'all' or null value for all tenants.}
                            {--m|migrate : Run migration after setup.}";

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create database and user for tenant.';

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
        $tenants = $this->getTenants(false);
        $progressBar = $this->output->createProgressBar(count($tenants));
        foreach ($tenants as $tenant) {
            $this->info("Setting up database for '{$tenant->name}'...");

            $this->manager->setTenantConnection($tenant);
            $this->manager->setupTenant();

            // setup_has_done means the database and account exist. Tenant commands only target such
            // tenants, so it is set before migrating and kept if migrating fails: `migrate --tenant`
            // can then retry and `tenant:destroy` can clean up.
            $tenant->setup_has_done = true;
            $tenant->save();

            if ($this->option('migrate')) {
                $exitCode = $this->call('migrate', [
                    '--tenant' => true,
                    '--domain' => $tenant->{config('multitenancy.tenant-id-column', 'domain')},
                    '--force' => true,
                ]);

                if ($exitCode !== self::SUCCESS) {
                    throw new TenantCommandException("Migration failed for '{$tenant->name}'. Retry with migrate --tenant.");
                }
            }

            $progressBar->advance();
            $this->info("  Database and user for '{$tenant->name}' created successfully.");
        }
    }
}
