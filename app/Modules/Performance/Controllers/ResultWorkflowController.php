<?php

namespace App\Modules\Performance\Controllers;

use App\Modules\Performance\Models\AppraisalAppeal;
use App\Modules\Performance\Models\AppraisalCycle;
use App\Modules\Performance\Models\AppraisalResult;
use App\Modules\Performance\Services\PerformanceService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * Post-scoring appraisal workflow (build spec §5): calibration with audit,
 * HR approval/rejection, employee acknowledgment, and time-boxed appeals.
 */
class ResultWorkflowController extends Controller
{
    public function __construct(private readonly PerformanceService $performance) {}

    public function calibrate(Request $request, AppraisalResult $result): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PERFORMANCE_MANAGE), 403);
        $data = $request->validate([
            'score_basis_points' => ['required', 'integer', 'between:0,10000'],
            'justification' => ['required', 'string', 'max:2000'],
        ]);

        $result = $this->performance->calibrate($result, $data['score_basis_points'], $data['justification'], $request->user());

        return response()->json(['data' => $this->resultPayload($result)]);
    }

    public function finalizeCalibration(Request $request, AppraisalCycle $cycle): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PERFORMANCE_MANAGE), 403);

        $moved = $this->performance->finalizeCalibration($cycle);

        return response()->json(['data' => ['finalized' => $moved]]);
    }

    public function approve(Request $request, AppraisalResult $result): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PERFORMANCE_MANAGE), 403);

        return response()->json(['data' => $this->resultPayload($this->performance->approve($result, $request->user()))]);
    }

    public function reject(Request $request, AppraisalResult $result): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PERFORMANCE_MANAGE), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return response()->json(['data' => $this->resultPayload($this->performance->reject($result, $data['reason']))]);
    }

    public function acknowledge(Request $request, AppraisalResult $result): JsonResponse
    {
        abort_unless($this->isOwnResult($request, $result), 403);

        return response()->json(['data' => $this->resultPayload($this->performance->acknowledge($result))]);
    }

    public function appeal(Request $request, AppraisalResult $result): JsonResponse
    {
        abort_unless($this->isOwnResult($request, $result), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:5000']]);

        $appeal = $this->performance->appeal($result, $data['reason']);

        return response()->json(['data' => ['id' => $appeal->id, 'status' => $appeal->status]], 201);
    }

    public function resolveAppeal(Request $request, AppraisalAppeal $appeal): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PERFORMANCE_MANAGE), 403);
        $data = $request->validate([
            'outcome' => ['required', Rule::in([AppraisalAppeal::STATUS_UPHELD, AppraisalAppeal::STATUS_REJECTED])],
            'resolution_note' => ['required', 'string', 'max:5000'],
            'new_score_basis_points' => ['nullable', 'integer', 'between:0,10000'],
        ]);

        $appeal = $this->performance->resolveAppeal($appeal, $data, $request->user());

        return response()->json(['data' => ['id' => $appeal->id, 'status' => $appeal->status, 'resolution_note' => $appeal->resolution_note]]);
    }

    private function isOwnResult(Request $request, AppraisalResult $result): bool
    {
        return $result->assignment()->whereHas('employee', fn ($query) => $query->where('user_id', $request->user()->id))->exists();
    }

    private function resultPayload(AppraisalResult $result): array
    {
        return [
            'id' => $result->id,
            'final_score_basis_points' => $result->final_score_basis_points,
            'raw_score_basis_points' => $result->raw_score_basis_points,
            'grade' => $result->grade,
            'status' => $result->status,
            'approved_at' => $result->approved_at?->toISOString(),
            'acknowledged_at' => $result->acknowledged_at?->toISOString(),
            'rejected_reason' => $result->rejected_reason,
        ];
    }
}
