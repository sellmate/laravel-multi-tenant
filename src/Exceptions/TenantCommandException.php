<?php

namespace Sellmate\Laravel\MultiTenant\Exceptions;

use RuntimeException;

/**
 * A tenant console command refused to run, found nothing to run against, or failed a step;
 * Artisan reports it and exits with a non-zero status.
 */
class TenantCommandException extends RuntimeException
{
}
