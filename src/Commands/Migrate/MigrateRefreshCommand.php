<?php

namespace Sellmate\Laravel\MultiTenant\Commands\Migrate;

use Illuminate\Database\Console\Migrations\RefreshCommand;
use Sellmate\Laravel\MultiTenant\Commands\AddsTenantOptions;
use Sellmate\Laravel\MultiTenant\Commands\RunsForEachTenant;
use Sellmate\Laravel\MultiTenant\Commands\EnvCheck;
use Sellmate\Laravel\MultiTenant\Commands\TenantCommand;
use Sellmate\Laravel\MultiTenant\DatabaseManager;
use Symfony\Component\Console\Input\InputOption;

class MigrateRefreshCommand extends RefreshCommand
{
    use TenantCommand, EnvCheck, AddsTenantOptions, RunsForEachTenant;

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
        if (!$this->confirmToProceed()) {
            return;
        }

        if ($this->input->getOption('tenant')) {
            $tenants = $this->getTenants();
            $progressBar = $this->output->createProgressBar(count($tenants));
            $this->setTenantDatabase();
            $this->forEachTenant($tenants, function ($tenant) use ($progressBar) {
                $this->manager->setTenantConnection($tenant);
                $this->checkEnv($this->manager->tenantConnectionName, $this->option('secret'));
                $this->info("Refreshing migrations for '{$tenant->name}'...");

                $domain = $tenant->{config('multitenancy.tenant-id-column', 'domain')};

                // The check above already authenticated this process (migration_admin_authenticated),
                // which is what lets the nested commands run; --secret is forwarded only so they do not
                // depend on that shortcut.

                $path = $this->input->getOption('path');

                // If the "step" option is specified it means we only want to rollback a small
                // number of migrations before migrating again. For example, the user might
                // only rollback and remigrate the latest four migrations instead of all.
                $step = $this->input->getOption('step') ?: 0;

                if ($step > 0) {
                    $this->call('migrate:rollback', [
                        '--tenant' => true,
                        '--domain' => $domain,
                        '--path' => $path,
                        '--realpath' => $this->input->getOption('realpath'),
                        '--step' => $step,
                        '--force' => true,
                        '--secret' => $this->option('secret'),
                    ]);
                } else {
                    $this->call('migrate:reset', [
                        '--tenant' => true,
                        '--domain' => $domain,
                        '--path' => $path,
                        '--realpath' => $this->input->getOption('realpath'),
                        '--force' => true,
                        '--secret' => $this->option('secret'),
                    ]);
                }

                // The refresh command is essentially just a brief aggregate of a few other of
                // the migration commands and just provides a convenient wrapper to execute
                // them in succession. We'll also see if we need to re-seed the database.
                $this->call('migrate', [
                    '--tenant' => true,
                    '--domain' => $domain,
                    '--path' => $path,
                    '--realpath' => $this->input->getOption('realpath'),
                    '--force' => true,
                    '--secret' => $this->option('secret'),
                ]);

                if ($this->needsSeeding()) {
                    $this->runSeeder($this->manager->tenantConnectionName);
                }

                $progressBar->advance();
                $this->info("  Migrations for '{$tenant->name}' refreshed.");
            });
        } else {
            $database = $this->option('database') ?? 'system';
            $this->checkEnv($database, $this->option('secret'));
            $this->setDefaultConnection($database);
            parent::handle();
        }
    }

    protected function tenantOptions(): array
    {
        return [
            ['tenant', 'T', InputOption::VALUE_NONE, "Reset and re-run all migrations for tenant database."],
            ['domain', null, InputOption::VALUE_OPTIONAL, "The domain for tenant. 'all' or null value for all tenants."],
            ['without-root', null, InputOption::VALUE_NONE, "Run migrations without root migrations. Migrate only path with database name."],
            ['secret', null, InputOption::VALUE_OPTIONAL, 'Secret for production'],
            $this->continueOnErrorOption(),
        ];
    }
}
