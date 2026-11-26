<?php

namespace Sellmate\Laravel\MultiTenant\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use Mockery;
use Sellmate\Laravel\MultiTenant\Commands\Passport\ClientCommand;
use Sellmate\Laravel\MultiTenant\Commands\Passport\InstallCommand;
use Sellmate\Laravel\MultiTenant\DatabaseManager;
use Sellmate\Laravel\MultiTenant\Exceptions\TenantCommandException;
use Sellmate\Laravel\MultiTenant\RefreshTokenRepository;
use Sellmate\Laravel\MultiTenant\Tests\TestCase;

class PassportTest extends TestCase
{
    private function passportMigrationsPath(): string
    {
        return realpath(__DIR__ . '/../../vendor/laravel/passport/database/migrations');
    }

    private function setUpTenants(string ...$domains): void
    {
        $this->migrateSystemTenantsTable();
        foreach ($domains as $domain) {
            $this->createTenant($domain);
        }

        $this->artisan('migrate', [
            '--tenant' => true,
            '--path' => $this->passportMigrationsPath(),
            '--realpath' => true,
        ])->assertSuccessful();
    }

    public function test_passport_commands_are_replaced_and_keep_passport_13_options(): void
    {
        $commands = $this->app[Kernel::class]->all();

        $client = $commands['passport:client'];
        $this->assertInstanceOf(ClientCommand::class, $client);
        foreach (['tenant', 'domain', 'personal', 'password', 'client', 'implicit', 'device', 'name', 'provider', 'redirect_uri', 'public'] as $option) {
            $this->assertTrue($client->getDefinition()->hasOption($option), "passport:client is missing --{$option}");
        }

        $install = $commands['passport:install'];
        $this->assertInstanceOf(InstallCommand::class, $install);
        foreach (['tenant', 'domain', 'force', 'length'] as $option) {
            $this->assertTrue($install->getDefinition()->hasOption($option), "passport:install is missing --{$option}");
        }
        $this->assertFalse($install->getDefinition()->hasOption('uuids'));

        $this->assertInstanceOf(\Sellmate\Laravel\MultiTenant\Commands\Passport\HashCommand::class, $commands['passport:hash']);
        $this->assertInstanceOf(\Sellmate\Laravel\MultiTenant\Commands\Passport\PurgeCommand::class, $commands['passport:purge']);
        foreach (['revoked', 'expired', 'hours', 'tenant', 'domain'] as $option) {
            $this->assertTrue($commands['passport:purge']->getDefinition()->hasOption($option), "passport:purge is missing --{$option}");
        }
    }

    public function test_client_command_without_tenant_writes_to_system_database(): void
    {
        $this->artisan('migrate', ['--path' => $this->passportMigrationsPath(), '--realpath' => true])->assertSuccessful();

        $this->artisan('passport:client', ['--client' => true, '--name' => 'system client'])->assertSuccessful();

        $this->assertSame(1, DB::connection('system')->table('oauth_clients')->count());
    }

    public function test_client_command_with_tenant_writes_only_to_the_selected_tenant(): void
    {
        $this->setUpTenants('alpha', 'beta');

        $this->artisan('passport:client', ['--tenant' => true, '--domain' => 'alpha', '--client' => true, '--name' => 'alpha client'])
            ->assertSuccessful();

        $this->assertSame(1, $this->tenantDatabase('alpha')->table('oauth_clients')->count());
        $this->assertSame(0, $this->tenantDatabase('beta')->table('oauth_clients')->count());
        $this->assertFalse(Schema::connection('system')->hasTable('oauth_clients'));
    }

    public function test_client_command_with_tenant_ignores_a_configured_passport_connection(): void
    {
        $this->setUpTenants('alpha');
        config(['passport.connection' => 'system']);

        $this->artisan('passport:client', ['--tenant' => true, '--domain' => 'alpha', '--client' => true, '--name' => 'alpha client'])
            ->assertSuccessful();

        $this->assertSame(1, $this->tenantDatabase('alpha')->table('oauth_clients')->count());
    }

    public function test_install_with_tenant_creates_personal_and_password_clients_once_per_tenant(): void
    {
        $this->setUpTenants('alpha', 'beta', 'gamma');

        $this->artisan('passport:install', ['--tenant' => true, '--domain' => 'alpha,beta'])->assertSuccessful();

        foreach (['alpha', 'beta'] as $domain) {
            $clients = $this->tenantDatabase($domain)->table('oauth_clients')->get();
            $this->assertCount(2, $clients, "{$domain} should get exactly one personal and one password client");
        }
        $this->assertSame(0, $this->tenantDatabase('gamma')->table('oauth_clients')->count());
    }

    public function test_install_with_tenant_works_without_a_users_provider(): void
    {
        $this->setUpTenants('alpha');
        config(['auth.providers' => ['admins' => ['driver' => 'eloquent', 'model' => 'App\\Models\\Admin']]]);

        $question = 'Which user provider should this client use to retrieve users?';
        $this->artisan('passport:install', ['--tenant' => true, '--domain' => 'alpha'])
            ->expectsChoice($question, 'admins', ['admins'])
            ->expectsChoice($question, 'admins', ['admins'])
            ->assertSuccessful();

        $this->assertSame(2, $this->tenantDatabase('alpha')->table('oauth_clients')->where('provider', 'admins')->count());
    }

    public function test_install_with_tenant_warns_that_key_options_are_ignored(): void
    {
        $this->setUpTenants('alpha');

        $this->artisan('passport:install', ['--tenant' => true, '--domain' => 'alpha', '--force' => true])
            ->expectsOutputToContain('--force/--length are ignored with --tenant')
            ->assertSuccessful();
    }

    public function test_tenant_commands_restore_passport_and_default_connections_afterwards(): void
    {
        $this->setUpTenants('alpha');
        config(['passport.connection' => null]);

        $this->artisan('passport:client', ['--tenant' => true, '--domain' => 'alpha', '--client' => true, '--name' => 'alpha client'])
            ->assertSuccessful();

        $this->assertNull(config('passport.connection'));
        $this->assertSame('system', DB::getDefaultConnection());
    }

    public function test_install_with_tenant_does_not_warn_without_key_options(): void
    {
        $this->setUpTenants('alpha');

        $this->artisan('passport:install', ['--tenant' => true, '--domain' => 'alpha'])
            ->doesntExpectOutputToContain('are ignored with --tenant')
            ->assertSuccessful();
    }

    public function test_oauth_routes_are_not_registered_outside_tenant_routes(): void
    {
        $this->assertFalse($this->app['router']->has('passport.token'));
    }

    private function insertPlaintextClient(string $domain, string $secret): void
    {
        $this->tenantDatabase($domain)->table('oauth_clients')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => "{$domain} client",
            'secret' => $secret,
            'redirect_uris' => '[]',
            'grant_types' => '["client_credentials"]',
            'revoked' => false,
        ]);
    }

    private function insertRevokedAccessToken(string $domain): void
    {
        $this->tenantDatabase($domain)->table('oauth_access_tokens')->insert([
            'id' => "{$domain}-token",
            'client_id' => (string) \Illuminate\Support\Str::uuid(),
            'revoked' => true,
        ]);
    }

    public function test_hash_with_tenant_hashes_only_the_selected_tenants_secrets(): void
    {
        $this->setUpTenants('alpha', 'beta');
        $this->insertPlaintextClient('alpha', 'alpha-secret');
        $this->insertPlaintextClient('beta', 'beta-secret');

        $this->artisan('passport:hash', ['--tenant' => true, '--domain' => 'alpha', '--force' => true])->assertSuccessful();

        $alphaSecret = $this->tenantDatabase('alpha')->table('oauth_clients')->value('secret');
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('alpha-secret', $alphaSecret));
        $this->assertSame('beta-secret', $this->tenantDatabase('beta')->table('oauth_clients')->value('secret'));
        $this->assertFalse(Schema::connection('system')->hasTable('oauth_clients'));
    }

    public function test_hash_with_tenant_asks_for_confirmation_once(): void
    {
        $this->setUpTenants('alpha', 'beta');
        $this->insertPlaintextClient('alpha', 'alpha-secret');
        $this->insertPlaintextClient('beta', 'beta-secret');

        $this->artisan('passport:hash', ['--tenant' => true])
            ->expectsConfirmation('Are you sure you want to hash all client secrets in every selected tenant? This cannot be undone.', 'yes')
            ->assertSuccessful();

        $this->assertNotSame('alpha-secret', $this->tenantDatabase('alpha')->table('oauth_clients')->value('secret'));
        $this->assertNotSame('beta-secret', $this->tenantDatabase('beta')->table('oauth_clients')->value('secret'));
    }

    public function test_hash_with_tenant_does_nothing_when_not_confirmed(): void
    {
        $this->setUpTenants('alpha');
        $this->insertPlaintextClient('alpha', 'alpha-secret');

        $this->artisan('passport:hash', ['--tenant' => true])
            ->expectsConfirmation('Are you sure you want to hash all client secrets in every selected tenant? This cannot be undone.', 'no')
            ->expectsOutputToContain('no client secrets were hashed')
            ->assertSuccessful();

        $this->assertSame('alpha-secret', $this->tenantDatabase('alpha')->table('oauth_clients')->value('secret'));
    }

    public function test_hash_with_tenant_fails_when_it_cannot_ask_for_confirmation(): void
    {
        $this->setUpTenants('alpha');
        $this->insertPlaintextClient('alpha', 'alpha-secret');

        $this->expectException(TenantCommandException::class);

        \Illuminate\Support\Facades\Artisan::call('passport:hash', ['--tenant' => true, '--no-interaction' => true]);
    }

    public function test_purge_with_tenant_purges_only_the_selected_tenants(): void
    {
        $this->setUpTenants('alpha', 'beta');
        $this->insertRevokedAccessToken('alpha');
        $this->insertRevokedAccessToken('beta');

        $this->artisan('passport:purge', ['--tenant' => true, '--domain' => 'alpha', '--revoked' => true])->assertSuccessful();

        $this->assertSame(0, $this->tenantDatabase('alpha')->table('oauth_access_tokens')->count());
        $this->assertSame(1, $this->tenantDatabase('beta')->table('oauth_access_tokens')->count());
    }

    public function test_purge_with_continue_on_error_purges_the_tenants_after_a_failing_one(): void
    {
        $this->setUpTenants('alpha', 'beta', 'gamma');
        $this->insertRevokedAccessToken('alpha');
        $this->insertRevokedAccessToken('gamma');
        $this->tenantDatabase('beta')->getSchemaBuilder()->drop('oauth_access_tokens');

        try {
            \Illuminate\Support\Facades\Artisan::call('passport:purge', ['--tenant' => true, '--revoked' => true, '--continue-on-error' => true]);
            $this->fail('Expected the run to fail at the end');
        } catch (\Sellmate\Laravel\MultiTenant\Exceptions\TenantsFailedException $e) {
            $this->assertSame(['beta'], $e->failedTenants);
        }

        $this->assertSame(0, $this->tenantDatabase('alpha')->table('oauth_access_tokens')->count());
        $this->assertSame(0, $this->tenantDatabase('gamma')->table('oauth_access_tokens')->count());
    }

    public function test_refresh_tokens_are_persisted_to_the_current_tenant_database(): void
    {
        $this->setUpTenants('alpha');

        // Same switch HandleTenantConnection performs for a tenant request.
        $manager = app(DatabaseManager::class);
        $manager->setTenantConnection(\App\Models\System\Tenant::where('domain', 'alpha')->first());
        DB::setDefaultConnection($manager->tenantConnectionName);

        $accessToken = Mockery::mock(AccessTokenEntityInterface::class);
        $accessToken->shouldReceive('getIdentifier')->andReturn('access-token-1');
        $refreshToken = Mockery::mock(RefreshTokenEntityInterface::class);
        $refreshToken->shouldReceive('getIdentifier')->andReturn('refresh-token-1');
        $refreshToken->shouldReceive('getAccessToken')->andReturn($accessToken);
        $refreshToken->shouldReceive('getExpiryDateTime')->andReturn(new DateTimeImmutable('+1 day'));

        $repository = app(RefreshTokenRepository::class);
        $repository->persistNewRefreshToken($refreshToken);

        $this->assertSame(1, $this->tenantDatabase('alpha')->table('oauth_refresh_tokens')->where('id', 'refresh-token-1')->count());
        $this->assertFalse(Schema::connection('system')->hasTable('oauth_refresh_tokens'));
        $this->assertFalse($repository->isRefreshTokenRevoked('refresh-token-1'));
        $repository->revokeRefreshToken('refresh-token-1');
        $this->assertTrue($repository->isRefreshTokenRevoked('refresh-token-1'));
    }
}
