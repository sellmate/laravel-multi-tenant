<?php

namespace Sellmate\Laravel\MultiTenant\Providers;

use Laravel\Passport\Console\HashCommand;
use Laravel\Passport\Console\KeysCommand;
use Laravel\Passport\Console\PurgeCommand;
use Laravel\Passport\PassportServiceProvider as BaseProvider;
use Sellmate\Laravel\MultiTenant\Commands\Passport\ClientCommand;
use Sellmate\Laravel\MultiTenant\Commands\Passport\InstallCommand;
use Sellmate\Laravel\MultiTenant\RefreshTokenRepository;

class PassportServiceProvider extends BaseProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'passport');

        $this->deleteCookieOnLogout();

        if ($this->app->runningInConsole()) {
            $this->registerMigrations();

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'passport-migrations');

            $this->publishes([
                __DIR__.'/../resources/views' => base_path('resources/views/vendor/passport'),
            ], 'passport-views');

            $this->publishes([
                __DIR__.'/../config/passport.php' => config_path('passport.php'),
            ], 'passport-config');

            $this->commands([
                InstallCommand::class,
                ClientCommand::class,
                HashCommand::class,
                KeysCommand::class,
                PurgeCommand::class,
            ]);
        }
    }

    /**
     * Create and configure a Password grant instance.
     *
     * @return PasswordGrant
     */
    protected function makePasswordGrant()
    {
        $grant = new \League\OAuth2\Server\Grant\PasswordGrant(
            $this->app->make(\Laravel\Passport\Bridge\UserRepository::class),
            $this->app->make(RefreshTokenRepository::class)
        );

        $grant->setRefreshTokenTTL(\Laravel\Passport\Passport::refreshTokensExpireIn());

        return $grant;
    }
}