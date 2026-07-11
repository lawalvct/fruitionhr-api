<?php

namespace App\Modules\Tenancy\Controllers;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Requests\SaveOnboardingRequest;
use App\Modules\Tenancy\Services\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class OnboardingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole('owner'), 403);

        return response()->json(['data' => $this->resource($request->user()->tenant)]);
    }

    public function update(SaveOnboardingRequest $request, OnboardingService $service): JsonResponse
    {
        $tenant = $service->save($request->user(), $request->validated());

        return response()->json(['data' => $this->resource($tenant)]);
    }

    public function complete(Request $request, OnboardingService $service): JsonResponse
    {
        abort_unless($request->user()->hasRole('owner'), 403);

        $tenant = $service->finish($request->user(), skipped: false);

        return response()->json(['data' => $this->resource($tenant)]);
    }

    public function skip(Request $request, OnboardingService $service): JsonResponse
    {
        abort_unless($request->user()->hasRole('owner'), 403);

        $tenant = $service->finish($request->user(), skipped: true);

        return response()->json(['data' => $this->resource($tenant)]);
    }

    private function resource(Tenant $tenant): array
    {
        return [
            'status' => $tenant->onboarding_status,
            'step' => $tenant->onboarding_step,
            'data' => $tenant->onboarding_data ?? [],
            'completed_at' => $tenant->onboarding_completed_at?->toISOString(),
            'skipped_at' => $tenant->onboarding_skipped_at?->toISOString(),
            'version' => $tenant->onboarding_version,
        ];
    }
}
