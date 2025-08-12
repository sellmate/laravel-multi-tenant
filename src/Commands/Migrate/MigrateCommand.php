<?php

namespace Sellmate\Laravel\MultiTenant\Commands\Migrate;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Console\Migrations\MigrateCommand as BaseMigrateCommand;
use Illuminate\Database\Events\SchemaLoaded;
use Sellmate\Laravel\MultiTenant\Commands\EnvCheck;
use Sellmate\Laravel\MultiTenant\Commands\TenantCommand;
use Sellmate\Laravel\MultiTenant\DatabaseManager;

class MigrateCommand extends BaseMigrateCommand
{
    use TenantCommand, EnvCheck;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = "migrate
                {--T|tenant : Run migrations for tenant. '--database' option will be ignored. use '--domain' instead.}
                {--domain= : The domain for tenant. 'all' or null value for all tenants.}
                {--without-root : Run migrations without root migrations. Migrate only path with database name.}
                {--database= : The database connection to use}
                {--force : Force the operation to run when in production}
                {--path=* : The path(s) to the migrations files to be executed}
                {--realpath : Indicate any provided migration file paths are pre-resolved absolute paths}
                {--schema-path= : The path to a schema dump file}
                {--pretend : Dump the SQL queries that would be run}
                {--seed : Indicates if the seed task should be re-run}
                {--step : Force the migrations to be run so they can be rolled back individually}
                {--secret= : Secret for production}";

    protected DatabaseManager $manager;

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
        if (env('APP_ENV') == 'testing' && env('PARALLEL_TEST_SKIP_MIGRATIONS')) {
            return;
        }
        if ($this->option('tenant')) {
            $tenants = $this->getTenants();
            $progressBar = $this->output->createProgressBar(count($tenants));
            $this->setTenantDatabase();
            foreach ($tenants as $tenant) {
                $this->info("Migrating for '{$tenant->{config('multitenancy.tenant-id-column', 'domain')}}'...");
                $this->manager->setTenantConnection($tenant);
                $this->checkEnv($this->manager->tenantConnectionName);
                $progressBar->advance();
                $this->newLine();
                parent::handle();
            }
        } else {
            $database = $this->option('database') ?? 'system';
            $this->setDefaultConnection($database);
            $this->checkEnv($database);
            parent::handle();
        }
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
