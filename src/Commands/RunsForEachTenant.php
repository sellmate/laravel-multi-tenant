<?php

namespace Sellmate\Laravel\MultiTenant\Commands;

use Sellmate\Laravel\MultiTenant\Exceptions\EnvironmentRefusedException;
use Sellmate\Laravel\MultiTenant\Exceptions\TenantsFailedException;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

trait RunsForEachTenant
{
    /**
     * @return array{0: string, 1: null, 2: int, 3: string}
     */
    protected function continueOnErrorOption(): array
    {
        return ['continue-on-error', null, InputOption::VALUE_NONE, 'Keep going with the remaining tenants when one fails; exits non-zero if any failed.'];
    }

    /**
     * Runs $callback for each tenant in order.
     *
     * By default the first failure is rethrown unchanged and the remaining tenants do not run. With
     * --continue-on-error each failure is reported and the remaining tenants still run; a
     * TenantsFailedException naming the failed tenants is thrown at the end. A refused environment
     * check always stops the run: it guards against touching the wrong database.
     *
     * @param  iterable<\App\Models\System\Tenant>  $tenants
     * @param  callable(\App\Models\System\Tenant): void  $callback
     *
     * @throws TenantsFailedException
     */
    protected function forEachTenant(iterable $tenants, callable $callback): void
    {
        $tenants = collect($tenants);
        $idColumn = config('multitenancy.tenant-id-column', 'domain');
        $continue = $this->hasOption('continue-on-error') && $this->option('continue-on-error');
        $failed = [];

        foreach ($tenants->values() as $index => $tenant) {
            try {
                $callback($tenant);
            } catch (EnvironmentRefusedException $e) {
                throw $e;
            } catch (Throwable $e) {
                $id = (string) $tenant->{$idColumn};

                if (! $continue) {
                    if ($tenants->count() > 1) {
                        $notRun = $tenants->slice($index + 1)->pluck($idColumn)->implode(',');
                        $this->components->error("Stopped at '{$id}'; {$index} of {$tenants->count()} tenants were done before it."
                            . ($notRun === '' ? '' : " Not run: {$notRun}. Pass --continue-on-error to run the others despite a failure."));
                    }

                    throw $e;
                }

                // Still logged as if uncaught, so a scheduled run does not lose the failure.
                report($e);
                $this->components->error("'{$id}' failed: {$e->getMessage()}");
                $failed[] = $id;
            }
        }

        if ($failed !== []) {
            throw new TenantsFailedException($failed, $tenants->count());
        }

        if ($continue) {
            $this->components->info("All {$tenants->count()} tenants succeeded.");
        }
    }
}
