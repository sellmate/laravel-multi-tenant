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
        $this->setDefaultConnection($this->manager->systemConnectionName);
    }

    protected function setTenantDatabase()
    {
        $this->setDefaultConnection($this->manager->tenantConnectionName);
    }

    protected function setDefaultConnection($connectionName)
    {
        config(['passport.storage.database.connection' => $connectionName]);
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
        $withoutRoot = $this->option('without-root') || in_array($database,$withoutPassportConfig);
        
        if($this->option('without-root') && !in_array($database,$withoutPassportConfig)){
            $this->warn('Database not in without-root config. Recommend to add database to without-root config.');
        }
        
        $paths = [];
        
        foreach (parent::getMigrationPaths() as $path){
            if(!$withoutRoot) $paths[] = $path;
            $paths[] = $path . DIRECTORY_SEPARATOR . $database;
        }

        return $paths;
    }
}
