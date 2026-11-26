<?php

namespace Sellmate\Laravel\MultiTenant\Exceptions;

/**
 * The environment check refused to run a command against a database (wrong or missing admin secret in
 * production, or a non-development database host outside production). It always stops the whole run,
 * even with --continue-on-error.
 */
class EnvironmentRefusedException extends TenantCommandException
{
}
