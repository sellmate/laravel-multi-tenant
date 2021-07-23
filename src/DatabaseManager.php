<?php

namespace Sellmate\Laravel\MultiTenant;

use App\Models\System\Tenant;
use Exception;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Sellmate\Laravel\MultiTenant\DatabaseDrivers\MySqlDriver;
use Sellmate\Laravel\MultiTenant\DatabaseDrivers\SQLiteDriver;
use Sellmate\Laravel\MultiTenant\DatabaseDrivers\SqlServerDriver;

class DatabaseManager
{
    public $systemConnectionName;
    public $tenantConnectionName;
    public $tenantAdminConnectionName;

    protected $tenant;

    public function __construct(Tenant $tenant = NULL)
    {
        $this->systemConnectionName = Config('multitenancy.system-connection', 'system');
        $this->tenantConnectionName = Config('multitenancy.tenant-connection', 'tenant');
        $this->tenantAdminConnectionName = Config('multitenancy.tenant-admin-connection', 'tenant_admin');
        if (!is_null($tenant)) $this->setConnection($tenant);
    }

    public function setConnection(Tenant $tenant)
    {
        if (!is_null($tenant)) $this->tenant = $tenant;
        DB::purge($this->tenantAdminConnectionName);
        DB::purge($this->tenantConnectionName);
        Config::set('database.connections.'.$this->tenantAdminConnectionName, $this->getTenantAdminConfig());
        Config::set('database.connections.'.$this->tenantConnectionName, $this->getTenantConfig());
        DB::reconnect($this->tenantAdminConnectionName);
        DB::reconnect($this->tenantConnectionName);
    }

    protected function getTenantAdminConfig()
    {
        $config = Config::get('database.connections.'.$this->tenantAdminConnectionName);
        $config['host'] = $this->tenant->db_host;
        $config['port'] = $this->tenant->db_port;

        return $config;
    }

    protected function getTenantConfig()
    {
        $config = $this->getTenantAdminConfig();
        $config['database'] = $this->getTenantDatabaseName();
        $config['username'] = $this->getTenantDatabaseUsername();
        $config['password'] = $this->getTenantDatabasePassword();

        if ($config['driver'] == 'sqlite') {
            $config['database'] = 'database/'.$config['database'].'.sqlite';
        }

        return $config;
    }

    protected function getTenantDatabaseName()
    {
        if (method_exists($this->tenant, 'getDatabaseName')) {
            return $this->tenant->getDatabaseName();
        } else {
            return str_replace(' ', '_', strtolower(Config::get('app.name', 'multitenancy'))).'_'.$this->tenant->domain;
        }
    }

    protected function getTenantDatabaseUsername()
    {
        if (method_exists($this->tenant, 'getDatabaseUsername')) {
            return $this->tenant->getDatabaseUsername();
        } else {
            return str_replace(' ', '_', strtolower(Config::get('app.name', 'multitenancy'))).'_'.$this->tenant->domain;
        }
    }

    protected function getTenantDatabasePassword()
    {
        if (method_exists($this->tenant, 'getDatabasePassword')) {
            return $this->tenant->getDatabasePassword();
        } else {
            return sha1($this->tenant->id.$this->tenant->domain.Config::get('multitenancy.key', Config::get('app.key')));
        }
    }

    public function create()
    {
        $config = $this->getTenantConfig();

        switch ($config['driver']) {
            case 'sqlite':
                $driver = new SQLiteDriver;
                break;
            case 'sqlsrv':
                $driver = new SqlServerDriver;
                break;
            case 'mysql':
                $driver = new MySqlDriver;
                break;
            default:
                throw new Exception('Unknown database driver');
        }

        $driver->create(DB::connection($this->tenantAdminConnectionName), $config);
    }

    public function delete()
    {
        $config = $this->getTenantConfig();

        switch ($config['driver']) {
            case 'sqlite':
                $driver = new SQLiteDriver;
                break;
            case 'sqlsrv':
                $driver = new SqlServerDriver;
                break;
            case 'mysql':
                $driver = new MySqlDriver;
                break;
            default:
                throw new Exception('Unknown database driver');
        }

        $driver->delete(DB::connection($this->tenantAdminConnectionName), $config);
    }
}
