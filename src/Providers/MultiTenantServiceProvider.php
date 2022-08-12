<?php

namespace Sellmate\Laravel\MultiTenant\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Sellmate\Laravel\MultiTenant\Commands\Migrate\MigrateCommand;
use Sellmate\Laravel\MultiTenant\Commands\Migrate\MigrateInstallCommand;
use Sellmate\Laravel\MultiTenant\Commands\Migrate\MigrateMakeCommand;
use Sellmate\Laravel\MultiTenant\Commands\Migrate\MigrateRefreshCommand;
use Sellmate\Laravel\MultiTenant\Commands\Migrate\MigrateResetCommand;
use Sellmate\Laravel\MultiTenant\Commands\Migrate\MigrateRollbackCommand;
use Sellmate\Laravel\MultiTenant\Commands\Migrate\MigrateStatusCommand;
use Sellmate\Laravel\MultiTenant\Commands\ModelMakeCommand;
use Sellmate\Laravel\MultiTenant\Commands\Seeds\SeedCommand;
use Sellmate\Laravel\MultiTenant\Commands\Seeds\SeederMakeCommand;
use Sellmate\Laravel\MultiTenant\Commands\TenantDestroyCommand;
use Sellmate\Laravel\MultiTenant\Commands\TenantSetupCommand;

class MultiTenantServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../../config/multitenancy.php' => config_path('multitenancy.php'),
            __DIR__ . '/../../database/migrations/' => database_path('migrations'),
            __DIR__ . '/../../database/Tenant.php' => app_path('Models/System/Tenant.php'),
            __DIR__ . '/../Commands/stubs/model.stub' => base_path('stubs/model.stub'),
            __DIR__ . '/../Commands/stubs/model.pivot.stub' => base_path('stubs/model.pivot.stub'),
            __DIR__ . '/../Commands/Seeds/stubs/seeder.stub' => base_path('stubs/seeder.stub'),
        ]);
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->commands([
            TenantSetupCommand::class,
            TenantDestroyCommand::class,
        ]);

        $this->app->extend('command.migrate', function ($object, $app) {
            return new MigrateCommand($app['migrator'], $app[Dispatcher::class]);
        });

        $this->app->extend('command.migrate.install', function ($object, $app) {
            return new MigrateInstallCommand($app['migration.repository']);
        });

        $this->app->extend('command.migrate.make', function ($object, $app) {
            return new MigrateMakeCommand($app['migration.creator'], $app['composer']);
        });

        $this->app->extend('command.migrate.refresh', function ($object, $app) {
            return new MigrateRefreshCommand;
        });

        $this->app->extend('command.migrate.reset', function ($object, $app) {
            return new MigrateResetCommand($app['migrator']);
        });

        $this->app->extend('command.migrate.rollback', function ($object, $app) {
            return new MigrateRollbackCommand($app['migrator']);
        });

        $this->app->extend('command.migrate.status', function ($object, $app) {
            return new MigrateStatusCommand($app['migrator']);
        });

        $this->app->extend('command.seed', function ($object, $app) {
            return new SeedCommand($app['db']);
        });

        $this->app->extend('command.seeder.make', function ($object, $app) {
            return new SeederMakeCommand($app['files'], $app['composer']);
        });

        $this->app->extend('command.model.make', function ($object, $app) {
            return new ModelMakeCommand($app['files']);
        });
    }
}
