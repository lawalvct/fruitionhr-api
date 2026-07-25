<?php

namespace App\Modules\Performance\Controllers;

use App\Modules\Performance\Models\AppraisalAssignment;
use App\Modules\Performance\Models\AppraisalResult;
use App\Modules\Performance\Models\AppraisalReviewer;
use App\Modules\Performance\Requests\AssignmentRequest;
use App\Modules\Performance\Requests\ReviewRequest;
use App\Modules\Performance\Resources\AssignmentResource;
use App\Modules\Performance\Services\PerformanceService;
use App\Support\Authorization\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class AppraisalController extends Controller
{
    public function __construct(private readonly PerformanceService $performance) {}

    public function index(Request $request): mixed
    {
        abort_unless($request->user()->can(Permissions::PERFORMANCE_VIEW) || $request->user()->can(Permissions::PERFORMANCE_REVIEW), 403);
        $query = AppraisalAssignment::query()->with($this->relations())->latest();

        if (! $request->user()->can(Permissions::PERFORMANCE_VIEW)) {
            $userId = $request->user()->id;
            $query->where(fn (Builder $inner) => $inner
                ->whereHas('reviewers', fn (Builder $reviewers) => $reviewers->where('reviewer_user_id', $userId))
                ->orWhereHas('employee', fn (Builder $employees) => $employees->where('user_id', $userId)));
        }

        if ($request->filled('cycle_id')) {
            $query->where('appraisal_cycle_id', $request->integer('cycle_id'));
        }

        return AssignmentResource::collection($query->get());
    }

    public function store(AssignmentRequest $request): JsonResponse
    {
        return (new AssignmentResource($this->performance->createAssignment($request->validated(), $request->user())))
            ->response()->setStatusCode(201);
    }

    public function show(Request $request, AppraisalAssignment $assignment): AssignmentResource
    {
        abort_unless($this->canAccess($request, $assignment), 403);
        return new AssignmentResource($assignment->load($this->relations()));
    }

    public function submitReview(ReviewRequest $request, AppraisalAssignment $assignment, AppraisalReviewer $reviewer): AssignmentResource
    {
        abort_unless($reviewer->appraisal_assignment_id === $assignment->id, 404);
        abort_unless($reviewer->reviewer_user_id === $request->user()->id || $request->user()->can(Permissions::PERFORMANCE_MANAGE), 403);

        return new AssignmentResource($this->performance->submitReview($reviewer, $request->validated()));
    }

    /** Return a submitted review to its reviewer for revision (spec §5). */
    public function returnReview(Request $request, AppraisalAssignment $assignment, AppraisalReviewer $reviewer): AssignmentResource
    {
        abort_unless($reviewer->appraisal_assignment_id === $assignment->id, 404);
        abort_unless($request->user()->can(Permissions::PERFORMANCE_MANAGE), 403);

        return new AssignmentResource($this->performance->returnReviewer($reviewer));
    }

    public function addOutcome(Request $request, AppraisalResult $result): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PERFORMANCE_MANAGE), 403);
        $data = $request->validate([
            'type' => ['required', Rule::in(['promotion', 'training', 'improvement_plan', 'recognition'])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $outcome = $result->outcomes()->create([...$data, 'created_by' => $request->user()->id]);

        return response()->json(['data' => $outcome], 201);
    }

    private function canAccess(Request $request, AppraisalAssignment $assignment): bool
    {
        if ($request->user()->can(Permissions::PERFORMANCE_VIEW)) {
            return true;
        }

        return $assignment->reviewers()->where('reviewer_user_id', $request->user()->id)->exists()
            || $assignment->employee()->where('user_id', $request->user()->id)->exists();
    }

    private function relations(): array
    {
        return ['cycle', 'template.ratingScale.options', 'template.items.kpi.category', 'employee', 'reviewers.user', 'reviewers.review.scores', 'result.outcomes', 'result.appeals'];
    }
}
