<?php

namespace Sellmate\Laravel\MultiTenant\Commands\Migrate;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Console\Migrations\MigrateCommand as BaseMigrateCommand;
use Illuminate\Database\Events\SchemaLoaded;
use Sellmate\Laravel\MultiTenant\Commands\AddsTenantOptions;
use Sellmate\Laravel\MultiTenant\Commands\EnvCheck;
use Sellmate\Laravel\MultiTenant\Commands\RunsForEachTenant;
use Sellmate\Laravel\MultiTenant\Commands\TenantCommand;
use Sellmate\Laravel\MultiTenant\DatabaseManager;
use Sellmate\Laravel\MultiTenant\Exceptions\TenantCommandException;
use Sellmate\Laravel\MultiTenant\Exceptions\TenantsFailedException;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

class MigrateCommand extends BaseMigrateCommand
{
    use TenantCommand, EnvCheck, AddsTenantOptions, RunsForEachTenant;

    protected DatabaseManager $manager;

    protected function tenantOptions(): array
    {
        return [
            ['tenant', 'T', InputOption::VALUE_NONE, "Run migrations for tenant. '--database' option will be ignored. use '--domain' instead."],
            ['domain', null, InputOption::VALUE_OPTIONAL, "The domain for tenant. 'all' or null value for all tenants."],
            ['without-root', null, InputOption::VALUE_NONE, 'Run migrations without root migrations. Migrate only path with database name.'],
            ['secret', null, InputOption::VALUE_OPTIONAL, 'Secret for production'],
            $this->continueOnErrorOption(),
        ];
    }

    /**
     * Create a new migration command instance.
     *
     * @param  \Illuminate\Database\Migrations\Migrator  $migrator
     * @return void
     */
    public function __construct(\Illuminate\Database\Migrations\Migrator $migrator, Dispatcher $dispatcher)
    {
        parent::__construct($migrator, $dispatcher);

        $this->manager = new DatabaseManager();
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        // 병렬 테스트 수행시 시도되는 migration을 스킵하기
        if ($this->laravel->environment('testing') && config('multitenancy.skip-migrations-in-parallel-tests')) {
            return;
        }

        if (! $this->confirmToProceed()) {
            return 1;
        }

        try {
            if ($this->option('tenant')) {
                $tenants = $this->getTenants();
                $progressBar = $this->output->createProgressBar(count($tenants));
                $this->setTenantDatabase();
                $this->forEachTenant($tenants, function ($tenant) use ($progressBar) {
                    $this->info("Migrating for '{$tenant->{config('multitenancy.tenant-id-column', 'domain')}}'...");
                    $this->manager->setTenantConnection($tenant);
                    $this->checkEnv($this->manager->tenantConnectionName, $this->option('secret'));
                    $progressBar->advance();
                    $this->newLine();
                    parent::handle();
                });
            } else {
                $database = $this->option('database') ?? 'system';
                $this->setDefaultConnection($database);
                $this->checkEnv($database, $this->option('secret'));
                parent::handle();
            }
        } catch (TenantsFailedException $e) {
            // Each tenant's migration error was already reported, and --graceful tolerates those.
            if ($this->option('graceful')) {
                $this->components->warn($e->getMessage());

                return 0;
            }

            throw $e;
        } catch (TenantCommandException $e) {
            // --graceful tolerates migration errors, not a refused environment check.
            throw $e;
        } catch (Throwable $e) {
            if ($this->option('graceful')) {
                $this->components->warn($e->getMessage());

                return 0;
            }

            throw $e;
        }

        return 0;
    }

    /**
     * Prepare the migration database for running.
     *
     * @return void
     */
    protected function prepareDatabase()
    {
        // INFO: 스키마 상태 불러오기 순서를 변경해서 migrations 테이블을 불필요하게 만들었다 지우는 과정 생략
        if (!$this->migrator->hasRunAnyMigrations() && !$this->option('pretend')) {
            $this->loadSchemaState();
        }

        if (!$this->migrator->repositoryExists()) {
            $this->call('migrate:install', array_filter([
                '--database' => $this->option('database'),
            ]));
        }
    }

    /**
     * Load the schema state to seed the initial database schema structure.
     *
     * @return void
     */
    protected function loadSchemaState()
    {
        $connection = $this->migrator->resolveConnection($this->option('database'));

        // First, we will make sure that the connection supports schema loading and that
        // the schema file exists before we proceed any further. If not, we will just
        // continue with the standard migration operation as normal without errors.
        if (!is_file($path = $this->schemaPath($connection))) {
            return;
        }

        $this->line('<info>Loading stored database schema:</info> ' . $path);

        $startTime = microtime(true);

        $connection->getSchemaState()->handleOutputUsing(function ($type, $buffer) {
            $this->output->write($buffer);
        })->load($path);

        $runTime = number_format((microtime(true) - $startTime) * 1000, 2);

        // Finally, we will fire an event that this schema has been loaded so developers
        // can perform any post schema load tasks that are necessary in listeners for
        // this event, which may seed the database tables with some necessary data.
        $this->dispatcher->dispatch(
            new SchemaLoaded($connection, $path)
        );

        $this->line('<info>Loaded stored database schema.</info> (' . $runTime . 'ms)');
    }
}
