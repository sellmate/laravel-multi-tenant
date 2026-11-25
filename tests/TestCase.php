<?php

namespace Sellmate\Laravel\MultiTenant\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Sellmate\Laravel\MultiTenant\Providers\MultiTenantServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [MultiTenantServiceProvider::class];
    }

    /**
     * Every connection is an in-memory SQLite database so tests never reach a real server.
     */
    protected function defineEnvironment($app)
    {
        // EnvCheck refuses hosts outside dev-host-list; SQLite ignores the host.
        $sqlite = [
            'driver' => 'sqlite',
            'host' => '127.0.0.1',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ];

        $app['config']->set('database.default', 'system');
        $app['config']->set('database.connections.system', $sqlite);
        $app['config']->set('database.connections.tenant', $sqlite);
        $app['config']->set('database.connections.tenant_admin', $sqlite);
        $app['config']->set('multitenancy.dev-host-list', ['127.0.0.1']);
    }
}
