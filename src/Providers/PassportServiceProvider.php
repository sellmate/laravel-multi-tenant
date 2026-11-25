<?php

namespace Sellmate\Laravel\MultiTenant\Providers;

use Laravel\Passport\Console\KeysCommand;
use Laravel\Passport\PassportServiceProvider as BaseProvider;
use Sellmate\Laravel\MultiTenant\Commands\Passport\ClientCommand;
use Sellmate\Laravel\MultiTenant\Commands\Passport\HashCommand;
use Sellmate\Laravel\MultiTenant\Commands\Passport\InstallCommand;
use Sellmate\Laravel\MultiTenant\Commands\Passport\PurgeCommand;

/**
 * Register instead of Laravel\Passport\PassportServiceProvider (exclude it from package discovery).
 *
 * Precondition: `passport.connection` (PASSPORT_CONNECTION) must stay null. Passport's models then
 * use the default connection, which HandleTenantConnection switches to the tenant; a fixed
 * connection would store every tenant's clients and tokens in that one database.
 */
class PassportServiceProvider extends BaseProvider
{
    /**
     * OAuth routes need the tenant's `{domain}` parameter and HandleTenantConnection, so the app
     * registers them inside its tenant route group; registering them globally would issue and
     * look up tokens in the system database.
     */
    protected function registerRoutes(): void
    {
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                ClientCommand::class,
                HashCommand::class,
                KeysCommand::class,
                PurgeCommand::class,
            ]);
        }
    }
}
