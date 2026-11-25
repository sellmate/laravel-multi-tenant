<?php

namespace Sellmate\Laravel\MultiTenant\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use Sellmate\Laravel\MultiTenant\Commands;
use Sellmate\Laravel\MultiTenant\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public static function overriddenCommands(): array
    {
        return [
            'migrate' => ['migrate', Commands\Migrate\MigrateCommand::class, ['tenant', 'domain', 'without-root']],
            'migrate:install' => ['migrate:install', Commands\Migrate\MigrateInstallCommand::class, ['tenant', 'domain']],
            'migrate:refresh' => ['migrate:refresh', Commands\Migrate\MigrateRefreshCommand::class, ['tenant', 'domain']],
            'migrate:reset' => ['migrate:reset', Commands\Migrate\MigrateResetCommand::class, ['tenant', 'domain']],
            'migrate:rollback' => ['migrate:rollback', Commands\Migrate\MigrateRollbackCommand::class, ['tenant', 'domain']],
            'migrate:status' => ['migrate:status', Commands\Migrate\MigrateStatusCommand::class, ['tenant', 'domain']],
            'make:migration' => ['make:migration', Commands\Migrate\MigrateMakeCommand::class, ['tenant']],
            'db:seed' => ['db:seed', Commands\Seeds\SeedCommand::class, ['tenant', 'domain']],
            'make:seeder' => ['make:seeder', Commands\Seeds\SeederMakeCommand::class, ['tenant']],
            'make:model' => ['make:model', Commands\ModelMakeCommand::class, ['tenant']],
            'tenant:setup' => ['tenant:setup', Commands\TenantSetupCommand::class, ['domain']],
            'tenant:destroy' => ['tenant:destroy', Commands\TenantDestroyCommand::class, ['domain']],
        ];
    }

    #[DataProvider('overriddenCommands')]
    public function test_command_is_replaced_by_package_command(string $name, string $class, array $options): void
    {
        $command = $this->app[Kernel::class]->all()[$name] ?? null;

        $this->assertInstanceOf($class, $command);
        foreach ($options as $option) {
            $this->assertTrue($command->getDefinition()->hasOption($option), "{$name} is missing --{$option}");
        }
    }
}
