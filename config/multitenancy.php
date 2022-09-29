<?php

return [
    'key' => env('MULTITENANCY_KEY', env('APP_KEY', 'mt_secret_key')),
    'system-connection' => env('MULTITENANCY_SYSTEM_CONNECTION', 'system'),
    'tenant-connection' => env('MULTITENANCY_TENANT_CONNECTION', 'tenant'),
    'tenant-admin-connection' => env('MULTITENANCY_TENANT_ADMIN_CONNECTION', 'tenant_admin'),
    'tenant-id-column' => env('MULTITENANCY_TENANT_ID_COLUMN', 'domain'),
    'tenant-id-parameter' => env('MULTITENANCY_TENANT_ID_PARAMETER', 'domain'),
    'without-root' => [env('MULTITENANCY_TENANT_ADMIN_CONNECTION', 'tenant_admin')], //Migrate only path with database name
];