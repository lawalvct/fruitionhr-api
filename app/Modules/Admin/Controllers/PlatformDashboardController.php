<?php

namespace App\Modules\Admin\Controllers;

use App\Modules\Admin\Resources\PlatformActivityResource;
use App\Modules\Admin\Resources\PlatformTenantResource;
use App\Modules\Admin\Services\PlatformDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PlatformDashboardController extends Controller
{
    public function __invoke(Request $request, PlatformDashboardService $service): JsonResponse
    {
        $dashboard = $service->dashboard();

        $dashboard['recent_tenants'] = PlatformTenantResource::collection(
            $dashboard['recent_tenants']
        )->resolve($request);
        $dashboard['recent_activity'] = PlatformActivityResource::collection(
            $dashboard['recent_activity']
        )->resolve($request);

        return response()->json(['data' => $dashboard]);
    }
}
