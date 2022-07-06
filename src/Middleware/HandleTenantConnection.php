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
        $idParameter = config('multitenancy.tenant-id-parameter', 'domain');
        $idColumn = config('multitenancy.tenant-id-column', 'domain');
        $tenantId = $request->route($idParameter);
        
        if (is_null($tenantId)) {
            return abort(404);
        }

        $tenant = Tenant::where($idColumn, $tenantId)->get()->first();
        if ($tenant) {
            $manager = new DatabaseManager();
            $manager->setTenantConnection($tenant);
            DB::setDefaultConnection($manager->tenantConnectionName);
            
            return $next($request);
        }
    }
}
