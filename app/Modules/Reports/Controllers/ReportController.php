<?php

namespace App\Modules\Reports\Controllers;

use App\Modules\Reports\Requests\ReportOverviewRequest;
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
}
