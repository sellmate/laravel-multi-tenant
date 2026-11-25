<?php

namespace Sellmate\Laravel\MultiTenant\Commands;

use App\Models\System\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sellmate\Laravel\MultiTenant\DatabaseManager;
use Sellmate\Laravel\MultiTenant\Exceptions\TenantCommandException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

trait TenantCommand
{
    protected DatabaseManager $manager;

    /**
     * The command repoints the default connection and `passport.connection`; restoring both keeps
     * that from leaking into later work in the same process (queue workers, Artisan::call()).
     */
    public function run(InputInterface $input, OutputInterface $output): int
    {
        $passportConnection = config('passport.connection');
        $defaultConnection = DB::getDefaultConnection();

        try {
            return parent::run($input, $output);
        } finally {
            config(['passport.connection' => $passportConnection]);
            DB::setDefaultConnection($defaultConnection);
        }
    }

    protected function getTenants($setup = true)
    {
        DB::setDefaultConnection($this->manager->systemConnectionName);

        if (! Schema::hasTable(Tenant::getTableName())) {
            throw new TenantCommandException('tenants table does not exist in system database');
        }

        $qb = Tenant::where('setup_has_done', $setup);
        $domain = $this->option('domain');
        if ($domain && $domain !== 'all') {
            $qb->whereIn(config('multitenancy.tenant-id-column', 'domain'), explode(',', $domain));
        }

        $tenants = $qb->get();

        if ($tenants->isEmpty()) {
            throw new TenantCommandException('No available tenants found');
        }

        return $tenants;
    }

    protected function setSystemDatabase()
    {
        $this->setDefaultConnection($this->manager->systemConnectionName);
    }

    protected function setTenantDatabase()
    {
        $this->setDefaultConnection($this->manager->tenantConnectionName);
    }

    protected function setDefaultConnection($connectionName)
    {
        config(['passport.connection' => $connectionName]);
        if ($this->hasOption('database')) {
            $this->input->setOption('database', $connectionName);
        }
        DB::setDefaultConnection($connectionName);
    }

    /**
     * Get all of the migration paths.
     *
     * @return array
     */
    protected function getMigrationPaths()
    {
        $database = $this->option('tenant') ? 'tenant' : 'system';
        $database = $this->option('database') ?? $database;
        $withoutPassportConfig = Config('multitenancy.without-root', []);
        $withoutRoot = $this->option('without-root') || in_array($database, $withoutPassportConfig);

        if ($this->option('without-root') && !in_array($database, $withoutPassportConfig)) {
            $this->warn('Database not in without-root config. Recommend to add database to without-root config.');
        }

        $paths = [];

        foreach (parent::getMigrationPaths() as $path) {
            if (!$withoutRoot) {
                $paths[] = $path;
            }

            $paths[] = $path . DIRECTORY_SEPARATOR . $database;
        }

        return $paths;
    }
}
