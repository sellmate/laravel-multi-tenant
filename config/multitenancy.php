<?php

return [
    'key' => env('MULTITENANCY_KEY', env('APP_KEY')),
    'system-connection' => env('MULTITENANCY_SYSTEM_CONNECTION', 'system'),
    'tenant-connection' => env('MULTITENANCY_TENANT_CONNECTION', 'tenant'),
    'tenant-admin-connection' => env('MULTITENANCY_TENANT_ADMIN_CONNECTION', 'tenant_admin'),
    'tenant-id-column' => env('MULTITENANCY_TENANT_ID_COLUMN', 'domain'),
    'tenant-id-parameter' => env('MULTITENANCY_TENANT_ID_PARAMETER', 'domain'),
    'without-root' => [env('MULTITENANCY_TENANT_ADMIN_CONNECTION', 'tenant_admin')], //Migrate only path with database name
    'dev-host-list' => array_values(array_filter(explode(',', (string) env('MULTITENANCY_TENANT_DEV_HOST_LIST')))),
    // Skips `migrate` when APP_ENV is testing, for parallel test runs that load a schema dump instead.
    'skip-migrations-in-parallel-tests' => (bool) env('PARALLEL_TEST_SKIP_MIGRATIONS', false),
];
