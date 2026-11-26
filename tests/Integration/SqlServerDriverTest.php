<?php

namespace Sellmate\Laravel\MultiTenant\Tests\Integration;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Sellmate\Laravel\MultiTenant\DatabaseDrivers\SqlServerDriver;
use Sellmate\Laravel\MultiTenant\Exceptions\TenantDatabaseException;
use Sellmate\Laravel\MultiTenant\Tests\TestCase;

/**
 * Runs SqlServerDriver through the real Laravel connection and pdo_sqlsrv against a SQL Server.
 * Skipped unless pdo_sqlsrv is loaded and MULTITENANT_SQLSRV_HOST / MULTITENANT_SQLSRV_PASSWORD
 * point at a disposable server (see tests/sqlserver/integration/README.md).
 */
#[Group('sqlserver')]
class SqlServerDriverTest extends TestCase
{
    private const TENANT = [
        'database' => 'lmt_it_alpha',
        'username' => 'lmt_it_alpha',
        'password' => 'Lt-It-Tenant-9xQ!',
    ];

    private bool $parentSetUp = false;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlsrv') || ! getenv('MULTITENANT_SQLSRV_HOST') || ! getenv('MULTITENANT_SQLSRV_PASSWORD')) {
            $this->markTestSkipped('Needs pdo_sqlsrv and MULTITENANT_SQLSRV_HOST / MULTITENANT_SQLSRV_PASSWORD.');
        }

        parent::setUp();
        $this->parentSetUp = true;

        config(['database.connections.tenant_admin' => $this->sqlServerConfig('sa', getenv('MULTITENANT_SQLSRV_PASSWORD'), 'master')]);
        DB::purge('tenant_admin');

        $this->driver()->destroy(self::TENANT);
    }

    protected function tearDown(): void
    {
        // PHPUnit calls tearDown() even when setUp() skipped before the application existed.
        if (! $this->parentSetUp) {
            return;
        }

        DB::purge('tenant_as_tenant');
        DB::purge('tenant_admin');
        $this->driver()->destroy(self::TENANT);

        parent::tearDown();
    }

    private function sqlServerConfig(string $username, string $password, string $database): array
    {
        return [
            'driver' => 'sqlsrv',
            'host' => getenv('MULTITENANT_SQLSRV_HOST'),
            'port' => 1433,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'prefix' => '',
            'trust_server_certificate' => true,
        ];
    }

    private function driver(): SqlServerDriver
    {
        return new SqlServerDriver('tenant_admin');
    }

    private function admin(): Connection
    {
        return DB::connection('tenant_admin');
    }

    private function connectAsTenant(): Connection
    {
        config(['database.connections.tenant_as_tenant' => $this->sqlServerConfig(self::TENANT['username'], self::TENANT['password'], self::TENANT['database'])]);
        DB::purge('tenant_as_tenant');

        return DB::connection('tenant_as_tenant');
    }

    private function databaseExists(): bool
    {
        return $this->admin()->selectOne('SELECT DB_ID(?) AS id', [self::TENANT['database']])->id !== null;
    }

    private function loginExists(): bool
    {
        return $this->admin()->selectOne('SELECT COUNT(*) AS n FROM sys.server_principals WHERE name = ?', [self::TENANT['username']])->n > 0;
    }

    private function assertGone(): void
    {
        $this->assertFalse($this->databaseExists(), 'database should be gone');
        $this->assertFalse($this->loginExists(), 'login should be gone');
    }

    public function test_setup_creates_a_database_the_tenant_login_can_use(): void
    {
        $this->assertTrue($this->driver()->setup(self::TENANT));

        $this->assertTrue($this->databaseExists());
        $this->assertTrue($this->loginExists());
        $this->assertSame(self::TENANT['database'], $this->connectAsTenant()->selectOne('SELECT DB_NAME() AS name')->name);
    }

    public function test_setup_leaves_the_admin_connection_in_master(): void
    {
        $this->driver()->setup(self::TENANT);

        $this->assertSame('master', $this->admin()->selectOne('SELECT DB_NAME() AS name')->name);
    }

    public function test_destroy_removes_everything_and_can_run_again(): void
    {
        $this->driver()->setup(self::TENANT);

        $this->assertTrue($this->driver()->destroy(self::TENANT));
        $this->assertGone();

        $this->assertTrue($this->driver()->destroy(self::TENANT));
        $this->assertGone();
    }

    public function test_destroy_ends_a_connected_tenant_session(): void
    {
        $this->driver()->setup(self::TENANT);
        $tenant = $this->connectAsTenant();
        $tenant->selectOne('SELECT 1 AS one');

        $this->assertTrue($this->driver()->destroy(self::TENANT));
        $this->assertGone();

        $this->expectException(\Throwable::class);
        $tenant->selectOne('SELECT 1 AS one');
    }

    /**
     * Runs an arrange batch and reads every result set, so an error after its first statement fails the
     * test instead of being lost (see test_laravel_statement_does_not_report_an_error_after_the_first_statement).
     */
    private function adminRun(string $sql): void
    {
        $statement = $this->admin()->getPdo()->prepare($sql);
        $statement->execute();
        while ($statement->nextRowset()) {
        }
        $statement->closeCursor();
    }

    /**
     * Creates a helper login (optionally in server roles) and a connection named after it.
     */
    private function createHelperLogin(string $name, array $serverRoles = []): string
    {
        $roles = implode(' ', array_map(fn ($role) => "ALTER SERVER ROLE [{$role}] ADD MEMBER [{$name}];", $serverRoles));
        $this->adminRun("USE [master]; IF SUSER_ID(N'{$name}') IS NULL CREATE LOGIN [{$name}] WITH PASSWORD = N'Lt-It-Helper-9xQ!', CHECK_POLICY = OFF; {$roles}");
        config(["database.connections.{$name}" => $this->sqlServerConfig($name, 'Lt-It-Helper-9xQ!', 'master')]);

        return $name;
    }

    private function dropHelperLogin(string $name): void
    {
        DB::purge($name);
        // The purged connection's session can outlive the PHP handle briefly; DROP LOGIN fails while it is open.
        $this->adminRun(<<<SQL
            DECLARE @spid int, @kill nvarchar(30);
            DECLARE helper_sessions CURSOR LOCAL FAST_FORWARD FOR
                SELECT session_id FROM sys.dm_exec_sessions WHERE login_name = N'{$name}' AND session_id <> @@SPID;
            OPEN helper_sessions;
            FETCH NEXT FROM helper_sessions INTO @spid;
            WHILE @@FETCH_STATUS = 0
            BEGIN
                SET @kill = N'KILL ' + CAST(@spid AS nvarchar(11));
                BEGIN TRY EXEC(@kill); END TRY BEGIN CATCH IF ERROR_NUMBER() <> 6106 THROW; END CATCH;
                FETCH NEXT FROM helper_sessions INTO @spid;
            END;
            CLOSE helper_sessions;
            DEALLOCATE helper_sessions;
            IF SUSER_ID(N'{$name}') IS NOT NULL DROP LOGIN [{$name}];
        SQL);
    }

    public function test_destroy_reruns_after_only_the_login_was_dropped(): void
    {
        $this->driver()->setup(self::TENANT);
        $this->adminRun('USE [master]; DROP LOGIN [lmt_it_alpha];');
        $this->assertFalse($this->loginExists());

        $this->assertTrue($this->driver()->destroy(self::TENANT));
        $this->assertGone();
    }

    public function test_destroy_reruns_after_only_the_database_was_dropped(): void
    {
        $this->driver()->setup(self::TENANT);
        $this->adminRun('USE [master]; ALTER DATABASE [lmt_it_alpha] SET SINGLE_USER WITH ROLLBACK IMMEDIATE; DROP DATABASE [lmt_it_alpha];');
        $this->assertFalse($this->databaseExists());

        $this->assertTrue($this->driver()->destroy(self::TENANT));
        $this->assertGone();
    }

    public function test_destroy_leaves_the_admin_connection_in_master(): void
    {
        $this->driver()->setup(self::TENANT);
        $this->driver()->destroy(self::TENANT);

        $this->assertSame('master', $this->admin()->selectOne('SELECT DB_NAME() AS name')->name);
    }

    public function test_destroy_refuses_an_admin_login_without_the_required_permissions(): void
    {
        $this->driver()->setup(self::TENANT);
        $low = $this->createHelperLogin('lmt_it_low');

        try {
            (new SqlServerDriver($low))->destroy(self::TENANT);
            $this->fail('Expected destroy to fail without permission');
        } catch (TenantDatabaseException $e) {
            $this->assertStringContainsString('lacks permissions', $e->getMessage());
            $this->assertStringContainsString('Missing permission', $this->rootCause($e)->getMessage());
        } finally {
            $this->dropHelperLogin($low);
        }

        $this->assertTrue($this->databaseExists(), 'a refused destroy must not drop the database');
        $this->assertTrue($this->loginExists(), 'a refused destroy must not drop the login');
        $this->assertSame(
            0,
            (int) $this->admin()->selectOne('SELECT is_disabled AS d FROM sys.server_principals WHERE name = ?', [self::TENANT['username']])->d,
            'a refused destroy must not disable the tenant login'
        );
    }

    /**
     * securityadmin (ALTER ANY LOGIN), processadmin (ALTER ANY CONNECTION, server state) and dbcreator
     * (CREATE ANY DATABASE, and CONTROL on the databases it creates). dbcreator has no ALTER ANY DATABASE,
     * so such a login can only destroy tenants it set up itself.
     */
    private function createOperatorLogin(): string
    {
        return $this->createHelperLogin('lmt_it_ops', ['securityadmin', 'processadmin', 'dbcreator']);
    }

    public function test_an_admin_without_sysadmin_can_set_up_and_destroy_its_own_tenant(): void
    {
        $operator = $this->createOperatorLogin();

        try {
            $this->assertTrue((new SqlServerDriver($operator))->setup(self::TENANT));
            $this->assertTrue($this->databaseExists());
            $this->assertTrue((new SqlServerDriver($operator))->destroy(self::TENANT));
        } finally {
            // The operator owns the tenant database while it exists, and DROP LOGIN fails for an owner.
            $this->driver()->destroy(self::TENANT);
            $this->dropHelperLogin($operator);
        }

        $this->assertGone();
    }

    public function test_an_admin_without_sysadmin_cannot_destroy_a_tenant_it_does_not_own(): void
    {
        $this->driver()->setup(self::TENANT);
        $operator = $this->createOperatorLogin();

        try {
            (new SqlServerDriver($operator))->destroy(self::TENANT);
            $this->fail('Expected destroy to be refused for a database the login neither owns nor can alter');
        } catch (TenantDatabaseException $e) {
            $this->assertStringContainsString('Missing permission', $this->rootCause($e)->getMessage());
        } finally {
            $this->dropHelperLogin($operator);
        }

        $this->assertTrue($this->databaseExists());
        $this->assertTrue($this->loginExists());
    }

    /**
     * Pins the pdo_sqlsrv behaviour that SqlServerDriver::run() works around: an error raised after the
     * first statement of a batch is not reported by Connection::statement().
     */
    public function test_laravel_statement_does_not_report_an_error_after_the_first_statement(): void
    {
        try {
            $result = $this->admin()->statement("USE [master]; THROW 50000, N'raised by the second statement', 1;");
        } catch (\Illuminate\Database\QueryException) {
            $this->markTestSkipped('Connection::statement() now reports the error; run() reading every result set is no longer required but stays harmless.');
        }

        $this->assertTrue($result);
    }

    /**
     * Pins why destroy() checks permissions first: without them its IF EXISTS guards see nothing.
     */
    public function test_a_login_without_permissions_cannot_see_other_logins(): void
    {
        $this->driver()->setup(self::TENANT);
        $low = $this->createHelperLogin('lmt_it_low');

        try {
            $visible = DB::connection($low)->selectOne('SELECT COUNT(*) AS n FROM sys.server_principals WHERE name = ?', [self::TENANT['username']])->n;
        } finally {
            $this->dropHelperLogin($low);
        }

        $this->assertSame(0, (int) $visible);
        $this->assertTrue($this->loginExists());
    }

    /**
     * Opens a sysadmin session inside the tenant database and makes it SINGLE_USER from there, so this
     * session holds the only slot. Returns the connection name; purge it to release the slot.
     */
    private function holdSingleUserSlot(): string
    {
        config(['database.connections.slot_holder' => $this->sqlServerConfig('sa', getenv('MULTITENANT_SQLSRV_PASSWORD'), self::TENANT['database'])]);
        DB::purge('slot_holder');
        $holder = DB::connection('slot_holder');
        $holder->getPdo()->exec('ALTER DATABASE [' . self::TENANT['database'] . '] SET SINGLE_USER WITH ROLLBACK IMMEDIATE');

        return 'slot_holder';
    }

    public function test_destroy_evicts_a_session_holding_a_single_user_database(): void
    {
        $this->driver()->setup(self::TENANT);
        $holder = $this->holdSingleUserSlot();

        try {
            $this->assertTrue($this->driver()->destroy(self::TENANT));
        } finally {
            DB::purge($holder);
        }

        $this->assertGone();
    }

    /**
     * Pins why destroy() retries on 5069 rather than 5064: when another session holds the only slot of a
     * SINGLE_USER database, ALTER DATABASE raises 5064 followed by 5069, and TRY/CATCH sees 5069.
     */
    public function test_a_single_user_conflict_reaches_catch_as_5069(): void
    {
        $this->driver()->setup(self::TENANT);
        $holder = $this->holdSingleUserSlot();

        $database = self::TENANT['database'];

        try {
            $statement = $this->admin()->getPdo()->prepare(<<<SQL
                SET NOCOUNT ON;
                USE [master];
                BEGIN TRY
                    EXEC(N'ALTER DATABASE [{$database}] SET SINGLE_USER WITH ROLLBACK IMMEDIATE');
                    SELECT CAST(NULL AS int) AS caught;
                END TRY
                BEGIN CATCH
                    SELECT ERROR_NUMBER() AS caught;
                END CATCH;
            SQL);
            $statement->execute();
            do {
                $row = $statement->fetch(\PDO::FETCH_ASSOC);
            } while ($row === false && $statement->nextRowset());
            $statement->closeCursor();
        } finally {
            DB::purge($holder);
        }

        $this->assertSame(5069, (int) $row['caught']);
    }

    private function rootCause(\Throwable $e): \Throwable
    {
        while ($e->getPrevious() !== null) {
            $e = $e->getPrevious();
        }

        return $e;
    }
}
