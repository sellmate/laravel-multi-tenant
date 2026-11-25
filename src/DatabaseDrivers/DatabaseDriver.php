<?php

namespace Sellmate\Laravel\MultiTenant\DatabaseDrivers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Sellmate\Laravel\MultiTenant\Exceptions\TenantDatabaseException;

class DatabaseDriver
{
    protected ConnectionInterface $connection;
    protected Builder $schema;

    public function __construct($connectionName)
    {
        $this->connection = DB::connection($connectionName);
        $this->schema = Schema::connection($connectionName);
    }

    public function setup(array $config)
    {
        throw new LogicException('This database driver does not support setup tenant');
    }

    public function destroy(array $config)
    {
        throw new LogicException('This database driver does not support destroy tenant');
    }

    /**
     * Characters allowed in database and account names, which are interpolated into DDL because
     * DDL cannot bind identifiers. MySQL database names cannot contain dots.
     */
    protected string $identifierPattern = '/^[A-Za-z0-9_-]+\z/';

    /**
     * @throws TenantDatabaseException when the name does not match $identifierPattern
     */
    protected function identifier(string $name): string
    {
        if (! preg_match($this->identifierPattern, $name)) {
            throw new TenantDatabaseException("Invalid database identifier '{$name}'");
        }

        return $name;
    }

    public function createDatabase($name)
    {
        try {
            $this->schema->createDatabase($name);
        } catch (\Throwable $th) {
            throw new TenantDatabaseException("Could not create database '{$name}'", 0, $th);
        }
    }

    public function dropDatabase($name)
    {
        try {
            $this->schema->dropDatabaseIfExists($name);
        } catch (\Throwable $th) {
            throw new TenantDatabaseException("Could not drop database '{$name}'", 0, $th);
        }
    }
}
