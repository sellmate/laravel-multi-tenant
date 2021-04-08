<?php

namespace Sellmate\Laravel\MultiTenant\Middleware;

use App\Models\System\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Sellmate\Laravel\MultiTenant\DatabaseManager;

class HandleTenantConnection
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $parameters = $request->route()->parameters();
        if (isset($parameters[config('multitenancy.tenant-id-parameter', 'domain')])) {
            $tenant = Tenant::where(config('multitenancy.tenant-id-column', 'domain'), $parameters[config('multitenancy.tenant-id-parameter', 'domain')])->get()->first();
            if ($tenant) {
                $manager = new DatabaseManager();
                $manager->setConnection($tenant);
                DB::setDefaultConnection($manager->tenantConnectionName);
                
                return $next($request);
            }
        }

        return abort(404);
    }
}
