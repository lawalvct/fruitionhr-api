<?php

namespace App\Modules\Recruitment\Controllers;

use App\Modules\Recruitment\Models\ManpowerRequisition;
use App\Modules\Recruitment\Requests\RequisitionRequest;
use App\Modules\Recruitment\Resources\RequisitionResource;
use App\Modules\Recruitment\Services\RecruitmentService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RequisitionController extends Controller
{
    public function __construct(private readonly RecruitmentService $recruitment) {}

    public function index(Request $request): mixed
    {
        abort_unless($request->user()->can(Permissions::RECRUITMENT_VIEW), 403);

        $query = ManpowerRequisition::query()
            ->with(['department', 'position', 'employmentType', 'requester'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where(fn ($inner) => $inner->where('title', 'like', '%'.$search.'%')->orWhere('reason', 'like', '%'.$search.'%'));
            })
            ->latest();

        return RequisitionResource::collection($query->paginate(min($request->integer('per_page', 15), 100))->appends($request->query()));
    }

    public function store(RequisitionRequest $request): JsonResponse
    {
        return (new RequisitionResource($this->recruitment->createRequisition($request->validated(), $request->user())->load(['department', 'position', 'employmentType', 'requester'])))
            ->response()->setStatusCode(201);
    }

    public function update(RequisitionRequest $request, ManpowerRequisition $requisition): RequisitionResource
    {
        if ($requisition->status !== ManpowerRequisition::STATUS_DRAFT) {
            throw new ConflictHttpException('Only draft requisitions can be edited.');
        }

        $requisition->update($request->validated());

        return new RequisitionResource($requisition->refresh()->load(['department', 'position', 'employmentType', 'requester']));
    }

    public function submit(Request $request, ManpowerRequisition $requisition): RequisitionResource
    {
        abort_unless($request->user()->can(Permissions::RECRUITMENT_MANAGE), 403);

        return new RequisitionResource($this->recruitment->submitRequisition($requisition, $request->user())->load(['department', 'position', 'employmentType', 'requester']));
    }

    public function destroy(Request $request, ManpowerRequisition $requisition): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::RECRUITMENT_MANAGE), 403);
        if ($requisition->status !== ManpowerRequisition::STATUS_DRAFT) {
            throw new ConflictHttpException('Only draft requisitions can be deleted.');
        }
        $requisition->delete();

        return response()->json(null, 204);
    }
}
