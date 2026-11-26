<?php

namespace Sellmate\Laravel\MultiTenant\Exceptions;

/**
 * A command run with --continue-on-error finished, but one or more tenants failed. Each failure was
 * reported when it happened; the message names the failed tenants.
 */
class TenantsFailedException extends TenantCommandException
{
    /**
     * @param  array<int, string>  $failedTenants  tenant ids (the `multitenancy.tenant-id-column` value)
     */
    public function __construct(public readonly array $failedTenants, int $total)
    {
        parent::__construct(sprintf('%d of %d tenants failed: %s', count($failedTenants), $total, implode(',', $failedTenants)));
    }
}
