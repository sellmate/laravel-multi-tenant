<?php

namespace Sellmate\Laravel\MultiTenant\Commands\Seeds;

use Illuminate\Database\ConnectionResolverInterface as Resolver;
use Illuminate\Database\Console\Seeds\SeedCommand as BaseCommand;
use Illuminate\Database\Eloquent\Model;
use Sellmate\Laravel\MultiTenant\Commands\AddsTenantOptions;
use Sellmate\Laravel\MultiTenant\Commands\EnvCheck;
use Sellmate\Laravel\MultiTenant\Commands\RunsForEachTenant;
use Sellmate\Laravel\MultiTenant\Commands\TenantCommand;
use Sellmate\Laravel\MultiTenant\DatabaseManager;
use Symfony\Component\Console\Input\InputOption;

class SeedCommand extends BaseCommand
{
    use TenantCommand, EnvCheck, AddsTenantOptions, RunsForEachTenant;

    public function __construct(Resolver $resolver)
    {
        parent::__construct($resolver);

        $this->manager = new DatabaseManager();
    }

    public function handle()
    {
        if ($this->isProhibited() || ! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        if (! $this->option('tenant')) {
            // `migrate --tenant --seed` and `migrate:refresh --tenant --seed` seed each tenant through
            // `--database=tenant`. A named connection is checked like any other command that writes to it;
            // the nested call is not asked again, as its parent command already passed the check.
            $database = $this->option('database');
            if ($database !== null) {
                $this->checkEnv($database, $this->option('secret'));
            }
            $this->setDefaultConnection($database ?? $this->manager->systemConnectionName);
            $this->seed();

            return self::SUCCESS;
        }

        $tenants = $this->getTenants();
        $this->setTenantDatabase();
        $progressBar = $this->output->createProgressBar(count($tenants));

        try {
            $this->forEachTenant($tenants, function ($tenant) use ($progressBar) {
                $this->manager->setTenantConnection($tenant);
                $this->checkEnv($this->manager->tenantConnectionName, $this->option('secret'));
                $this->info("Seeding to '{$tenant->name}'...");
                try {
                    $this->seed();
                } finally {
                    $progressBar->advance();
                    $this->newLine();
                }
            });
        } finally {
            $progressBar->finish();
            $this->newLine();
        }

        return self::SUCCESS;
    }

    private function seed(): void
    {
        Model::unguarded(function () {
            $this->getSeeder()->__invoke();
        });
    }

    protected function tenantOptions(): array
    {
        return [
            ['tenant', 'T', InputOption::VALUE_NONE, "Seed the database with records for tenant database."],
            ['domain', null, InputOption::VALUE_OPTIONAL, "The domain for tenant. 'all' or null value for all tenants."],
            ['secret', null, InputOption::VALUE_OPTIONAL, 'Secret for production'],
            $this->continueOnErrorOption(),
        ];
    }
}
