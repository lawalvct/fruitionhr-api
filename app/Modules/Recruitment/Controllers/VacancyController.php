<?php

namespace App\Modules\Recruitment\Controllers;

use App\Modules\Recruitment\Models\Vacancy;
use App\Modules\Recruitment\Requests\VacancyRequest;
use App\Modules\Recruitment\Resources\VacancyResource;
use App\Modules\Recruitment\Services\RecruitmentService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class VacancyController extends Controller
{
    public function __construct(private readonly RecruitmentService $recruitment) {}

    public function index(Request $request): mixed
    {
        abort_unless($request->user()->can(Permissions::RECRUITMENT_VIEW), 403);

        $query = Vacancy::query()->with(['requisition.position', 'employmentType'])->withCount('applications')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('search'), fn ($query) => $query->where('title', 'like', '%'.$request->string('search').'%'))
            ->latest();

        return VacancyResource::collection($query->paginate(min($request->integer('per_page', 15), 100))->appends($request->query()));
    }

    public function store(VacancyRequest $request): JsonResponse
    {
        return (new VacancyResource($this->recruitment->createVacancy($request->validated(), $request->user())->load(['requisition.position', 'employmentType'])->loadCount('applications')))
            ->response()->setStatusCode(201);
    }

    public function update(VacancyRequest $request, Vacancy $vacancy): VacancyResource
    {
        if ($vacancy->status !== Vacancy::STATUS_DRAFT) {
            throw new ConflictHttpException('Only draft vacancies can be edited.');
        }
        $vacancy->update($request->validated());

        return new VacancyResource($vacancy->refresh()->load(['requisition.position', 'employmentType'])->loadCount('applications'));
    }

    public function open(Request $request, Vacancy $vacancy): VacancyResource
    {
        abort_unless($request->user()->can(Permissions::RECRUITMENT_MANAGE), 403);
        if ($vacancy->status !== Vacancy::STATUS_DRAFT) throw new ConflictHttpException('Only draft vacancies can be opened.');
        $vacancy->update(['status' => Vacancy::STATUS_OPEN, 'opens_at' => $vacancy->opens_at ?? today()]);

        return new VacancyResource($vacancy->refresh()->load(['requisition.position', 'employmentType'])->loadCount('applications'));
    }

    public function close(Request $request, Vacancy $vacancy): VacancyResource
    {
        abort_unless($request->user()->can(Permissions::RECRUITMENT_MANAGE), 403);
        if ($vacancy->status !== Vacancy::STATUS_OPEN) throw new ConflictHttpException('Only open vacancies can be closed.');
        $vacancy->update(['status' => Vacancy::STATUS_CLOSED]);

        return new VacancyResource($vacancy->refresh()->load(['requisition.position', 'employmentType'])->loadCount('applications'));
    }

    public function publish(Request $request, Vacancy $vacancy): VacancyResource
    {
        abort_unless($request->user()->can(Permissions::RECRUITMENT_MANAGE), 403);
        if ($vacancy->status === Vacancy::STATUS_CLOSED) {
            throw new ConflictHttpException('Closed vacancies cannot be published.');
        }

        return new VacancyResource(
            $this->recruitment->publish($vacancy)->load(['requisition.position', 'employmentType'])->loadCount('applications')
        );
    }

    public function unpublish(Request $request, Vacancy $vacancy): VacancyResource
    {
        abort_unless($request->user()->can(Permissions::RECRUITMENT_MANAGE), 403);

        $vacancy->update(['visibility' => Vacancy::VISIBILITY_PRIVATE]);

        return new VacancyResource($vacancy->refresh()->load(['requisition.position', 'employmentType'])->loadCount('applications'));
    }
}
