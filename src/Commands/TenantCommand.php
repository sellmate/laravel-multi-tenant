<?php

namespace Sellmate\Laravel\MultiTenant\Commands;

use App\Models\System\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sellmate\Laravel\MultiTenant\DatabaseManager;

trait TenantCommand
{
    protected DatabaseManager $manager;

    protected function getTenants($setup = TRUE)
    {
        DB::setDefaultConnection($this->manager->systemConnectionName);

        if (Schema::hasTable(Tenant::getTableName())) {
            $qb = Tenant::where('setup_has_done', $setup);
            if ($this->option('domain')) $qb->where(config('multitenancy.tenant-id-column', 'domain'), $this->option('domain'));
            $tenants = $qb->get();

            if (count($tenants) == 0) {
                $this->error('No available tenants found');
                exit;
            }
            return $tenants;
        } else {
            $this->error('tenants table is not exists in system database');
            exit;
        }
    }

    protected function setSystemDatabase()
    {
        config(['passport.storage.database.connection' => $this->manager->systemConnectionName]);
        $this->input->setOption('database', $this->manager->systemConnectionName);
        DB::setDefaultConnection($this->manager->systemConnectionName);
    }

    protected function setTenantDatabase()
    {
        config(['passport.storage.database.connection' => $this->manager->tenantConnectionName]);
        $this->input->setOption('database', $this->manager->tenantConnectionName);
        DB::setDefaultConnection($this->manager->tenantConnectionName);
    }

    /**
     * Get all of the migration paths.
     *
     * @return array
     */
    protected function getMigrationPaths()
    {
        $type = $this->option('tenant') ? 'tenant' : 'system';
        $paths = parent::getMigrationPaths();
        foreach ($paths as $path) $paths[] = $path . DIRECTORY_SEPARATOR . $type;

        return $paths;
    }
}
