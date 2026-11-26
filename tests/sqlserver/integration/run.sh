#!/bin/sh
# Runs tests/Integration/SqlServerDriverTest.php through pdo_sqlsrv against a disposable SQL Server.
# Usage: SQLSRV_SA_PASSWORD='<strong password>' sh tests/sqlserver/integration/run.sh
set -eu

: "${SQLSRV_SA_PASSWORD:?set SQLSRV_SA_PASSWORD to a strong throwaway password}"

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
NETWORK=lmt-sqlsrv-it
SERVER=lmt-sqlsrv-it-db
IMAGE=lmt-php-sqlsrv:8.4

# Passed by name (-e VAR) so the password does not appear in the docker command line.
export MSSQL_SA_PASSWORD="$SQLSRV_SA_PASSWORD"
export SQLCMDPASSWORD="$SQLSRV_SA_PASSWORD"
export MULTITENANT_SQLSRV_PASSWORD="$SQLSRV_SA_PASSWORD"

docker network inspect "$NETWORK" > /dev/null 2>&1 || docker network create "$NETWORK" > /dev/null

# The server is only reachable on this network; no port is published to the host.
if ! docker container inspect "$SERVER" > /dev/null 2>&1; then
    docker run -d --name "$SERVER" --network "$NETWORK" --platform linux/amd64 \
        -e ACCEPT_EULA=Y -e MSSQL_SA_PASSWORD -e SQLCMDPASSWORD \
        mcr.microsoft.com/mssql/server:2022-latest > /dev/null
elif [ "$(docker container inspect -f '{{.State.Running}}' "$SERVER")" != "true" ]; then
    # e.g. after a Docker restart
    docker start "$SERVER" > /dev/null
fi

echo "== waiting for SQL Server (up to 3 minutes)"
attempts=0
until docker exec "$SERVER" /opt/mssql-tools18/bin/sqlcmd -S localhost -U sa -C -Q "SELECT 1" > /dev/null 2>&1; do
    attempts=$((attempts + 1))
    if [ "$attempts" -ge 90 ]; then
        docker logs --tail 30 "$SERVER" >&2
        echo "SQL Server did not accept sa. If $SERVER was created with another password, remove it: docker rm -f $SERVER" >&2
        exit 1
    fi
    sleep 2
done

echo "== building $IMAGE"
docker build --platform linux/amd64 -q -t "$IMAGE" "$ROOT/tests/sqlserver/integration" > /dev/null

echo "== running the sqlserver test group"
docker run --rm --platform linux/amd64 --network "$NETWORK" \
    -v "$ROOT:/app" -w /app \
    -e MULTITENANT_SQLSRV_HOST="$SERVER" -e MULTITENANT_SQLSRV_PASSWORD \
    "$IMAGE" vendor/bin/phpunit --group sqlserver

echo "== done. Remove with: docker rm -f $SERVER && docker network rm $NETWORK"
