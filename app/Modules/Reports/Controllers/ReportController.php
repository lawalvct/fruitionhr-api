<?php

namespace App\Modules\Reports\Controllers;

use App\Modules\Reports\Requests\ReportAnalysisRequest;
use App\Modules\Reports\Requests\ReportOverviewRequest;
use App\Modules\Reports\Services\ReportAnalysisService;
use App\Modules\Reports\Services\ReportOverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class ReportController extends Controller
{
    public function overview(ReportOverviewRequest $request, ReportOverviewService $service): JsonResponse
    {
        $year = (int) ($request->validated('year') ?? now()->year);

        return response()->json([
            'data' => $service->build($request->user(), $year),
        ]);
    }

    public function analysis(
        ReportAnalysisRequest $request,
        string $module,
        ReportAnalysisService $service,
    ): JsonResponse {
        $validated = $request->validated();
        $year = (int) ($validated['year'] ?? now()->year);
        unset($validated['year']);

        return response()->json([
            'data' => $service->build($module, $year, $validated),
        ]);
    }
}
