<?php

namespace Sellmate\Laravel\MultiTenant\Commands\Seeds;

use App\Models\System\Tenant;
use Illuminate\Database\Console\Seeds\SeedCommand as BaseCommand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Sellmate\Laravel\MultiTenant\DatabaseManager;
use Symfony\Component\Console\Input\InputOption;

class SeedCommand extends BaseCommand
{
    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        if (! $this->confirmToProceed()) {
            return 1;
        }

        $manager = new DatabaseManager();
        DB::setDefaultConnection($manager->systemConnectionName);

        if ($this->input->getOption('tenant')) {

            $domain = $this->input->getOption('domain') ?: 'all';
            if ($domain == 'all') $tenants = Tenant::all();
            else $tenants = Tenant::where('domain', $domain)->get();

            $drawBar = (count($tenants) > 1);

            if ($drawBar) $bar = $this->output->createProgressBar(count($tenants));

            foreach ($tenants as $tenant) {
                $this->info('');
                $this->info("Seeding to '{$tenant->name}'...");

                $manager->setTenantConnection($tenant);
                $this->resolver->setDefaultConnection($manager->tenantConnectionName);

                Model::unguarded(function () {
                    $this->getSeeder()->__invoke();
                });

                if ($drawBar) $bar->advance();
                $this->info(($drawBar ? '  ' : '') . "Seed '{$tenant->name}' succeed.");
            }
            if ($drawBar) $bar->finish();

        } else {

            $this->resolver->setDefaultConnection($manager->systemConnectionName);

            Model::unguarded(function () {
                $this->getSeeder()->__invoke();
            });

        }
    }
    
    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return array_merge(parent::getOptions(), [
            ['tenant', 'T', InputOption::VALUE_NONE, "Seed the database with records for tenant database."],
            ['domain', NULL, InputOption::VALUE_OPTIONAL, "The domain for tenant. 'all' or null value for all tenants."]
        ]);
    }
}
