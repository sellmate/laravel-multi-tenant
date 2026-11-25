<?php

namespace Sellmate\Laravel\MultiTenant\Exceptions;

use RuntimeException;

/**
 * A tenant console command refused to run or found nothing to run against; Artisan reports it
 * and exits with a non-zero status.
 */
class TenantCommandException extends RuntimeException
{
}
