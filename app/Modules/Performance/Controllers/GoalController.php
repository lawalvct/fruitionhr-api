<?php

namespace App\Modules\Performance\Controllers;

use App\Modules\Performance\Models\Goal;
use App\Modules\Performance\Requests\GoalCheckinRequest;
use App\Modules\Performance\Requests\GoalRequest;
use App\Modules\Performance\Resources\GoalResource;
use App\Modules\Performance\Services\GoalService;
use App\Support\Authorization\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class GoalController extends Controller
{
    public function __construct(private readonly GoalService $goals) {}

    public function index(Request $request): mixed
    {
        abort_unless($request->user()->can(Permissions::GOALS_VIEW), 403);
        $query = Goal::query()->with($this->relations())->latest();

        if (! $request->user()->can(Permissions::PERFORMANCE_VIEW)) {
            $userId = $request->user()->id;
            $query->where(fn (Builder $inner) => $inner
                ->where('owner_user_id', $userId)
                ->orWhereHas('employee', fn (Builder $employees) => $employees->where('user_id', $userId)));
        }

        if ($request->filled('level')) {
            $query->where('level', $request->string('level'));
        }

        return GoalResource::collection($query->get());
    }

    public function store(GoalRequest $request): JsonResponse
    {
        $data = $this->scopeInput($request, $request->validated());
        $goal = Goal::query()->create([...$data, 'created_by' => $request->user()->id]);

        return (new GoalResource($goal->load($this->relations())))->response()->setStatusCode(201);
    }

    public function update(GoalRequest $request, Goal $goal): GoalResource
    {
        abort_unless($this->canManage($request, $goal), 403);
        if (in_array($goal->status, ['completed', 'cancelled'], true)) {
            throw new ConflictHttpException('Completed or cancelled goals cannot be edited.');
        }
        $goal->update($this->scopeInput($request, $request->validated()));

        return new GoalResource($goal->refresh()->load($this->relations()));
    }

    public function checkin(GoalCheckinRequest $request, Goal $goal): GoalResource
    {
        abort_unless($this->canManage($request, $goal), 403);
        return new GoalResource($this->goals->checkin($goal, $request->validated(), $request->user()));
    }

    private function scopeInput(Request $request, array $data): array
    {
        if ($request->user()->can(Permissions::PERFORMANCE_MANAGE)) {
            return $data;
        }

        $employee = $request->user()->employee()->first();
        abort_if($employee === null, 403, 'A linked employee profile is required.');

        return [
            ...$data,
            'level' => 'individual',
            'department_id' => null,
            'employee_id' => $employee->id,
            'owner_user_id' => $request->user()->id,
        ];
    }

    private function canManage(Request $request, Goal $goal): bool
    {
        return $request->user()->can(Permissions::PERFORMANCE_MANAGE)
            || $goal->owner_user_id === $request->user()->id
            || $goal->employee()->where('user_id', $request->user()->id)->exists();
    }

    private function relations(): array
    {
        return ['department', 'employee', 'owner', 'checkins'];
    }
}
