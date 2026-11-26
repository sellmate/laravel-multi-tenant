#!/bin/sh
# Runs every scenario against a running local SQL Server container (see README.md).
# Usage: sh tests/sqlserver/destroy-rerun/run.sh [container-name]
set -eu

CONTAINER="${1:-lmt-mssql}"
DIR="$(cd "$(dirname "$0")" && pwd)"
SQLCMD="/opt/mssql-tools18/bin/sqlcmd -S localhost -C"
TENANT_PASSWORD="Lt-verify-24-tenant" # set by setup.sql

as_sa() { docker exec "$CONTAINER" $SQLCMD -U sa -b "$@"; }
# Holds a session open in the background, like a connected tenant app.
hold_session() { docker exec -d "$CONTAINER" $SQLCMD "$@" -Q "WAITFOR DELAY '00:02:00'"; }

# docker cp leaves root-owned files, which the container's default (mssql) user cannot remove on a rerun.
docker exec -u root "$CONTAINER" rm -rf /tmp/destroy-rerun
docker cp "$DIR" "$CONTAINER:/tmp/destroy-rerun"

as_sa -i /tmp/destroy-rerun/verify.sql

echo "== S5: destroy while the tenant login holds a session"
as_sa -i /tmp/destroy-rerun/setup.sql
hold_session -U lmt_t24 -P "$TENANT_PASSWORD" -d lmt_t24
sleep 5
as_sa -Q "IF NOT EXISTS (SELECT 1 FROM sys.dm_exec_sessions WHERE login_name = N'lmt_t24') RAISERROR('FAIL: S5 precondition, no tenant session', 16, 1)"
as_sa -i /tmp/destroy-rerun/destroy.sql
as_sa -i /tmp/destroy-rerun/assert-gone.sql

echo "== S6: rerun while another session holds the only slot of a SINGLE_USER database"
as_sa -i /tmp/destroy-rerun/setup.sql
as_sa -Q "ALTER DATABASE [lmt_t24] SET SINGLE_USER WITH ROLLBACK IMMEDIATE"
hold_session -U sa -d lmt_t24
sleep 5
as_sa -Q "IF NOT EXISTS (SELECT 1 FROM sys.dm_exec_sessions WHERE database_id = DB_ID(N'lmt_t24')) RAISERROR('FAIL: S6 precondition, nobody holds the database', 16, 1)"
as_sa -i /tmp/destroy-rerun/destroy.sql
as_sa -i /tmp/destroy-rerun/assert-gone.sql

echo "== S7: once disabled, the tenant login cannot reconnect"
as_sa -i /tmp/destroy-rerun/setup.sql
as_sa -i /tmp/destroy-rerun/step-1-disable.sql
if docker exec "$CONTAINER" $SQLCMD -U lmt_t24 -P "$TENANT_PASSWORD" -b -Q "SELECT 1" > /dev/null 2>&1; then
    echo "FAIL: S7 the disabled tenant login still connects"
    exit 1
fi
as_sa -i /tmp/destroy-rerun/destroy.sql
as_sa -i /tmp/destroy-rerun/assert-gone.sql

echo "== ALL SCENARIOS PASSED"

echo "== Probes (errors below are expected observations)"
docker exec "$CONTAINER" $SQLCMD -U sa -i /tmp/destroy-rerun/probe.sql
