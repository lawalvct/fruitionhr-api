<?php

namespace App\Modules\Recruitment\Controllers;

use App\Modules\Recruitment\Models\Vacancy;
use App\Modules\Recruitment\Requests\PublicApplicationRequest;
use App\Modules\Recruitment\Resources\PublicVacancyResource;
use App\Modules\Recruitment\Services\RecruitmentService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use App\Support\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;

class PublicVacancyController extends Controller
{
    public function __construct(private readonly RecruitmentService $recruitment) {}

    public function index(Request $request): mixed
    {
        $query = $this->publicQuery()
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = trim($request->string('search')->toString());
                $query->where(function (Builder $inner) use ($search): void {
                    $inner->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('requirements', 'like', "%{$search}%")
                        ->orWhereHas('tenant', fn (Builder $tenants) => $tenants->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($request->filled('location'), function (Builder $query) use ($request): void {
                $query->where('location', 'like', '%'.trim($request->string('location')->toString()).'%');
            })
            ->latest('opens_at')
            ->latest('id');

        $vacancies = $query->paginate(min(max($request->integer('per_page', 12), 1), 50))->appends($request->query());
        $vacancies->setCollection($this->hydrateTenantRelations($vacancies->getCollection()));

        return PublicVacancyResource::collection($vacancies);
    }

    public function show(string $slug): PublicVacancyResource
    {
        $vacancy = $this->findPublicVacancy($slug);

        return new PublicVacancyResource($this->hydrateForTenant($vacancy));
    }

    public function apply(PublicApplicationRequest $request, string $slug): JsonResponse
    {
        $vacancy = $this->findPublicVacancy($slug);
        $currentTenant = app(CurrentTenant::class);
        $currentTenant->set($vacancy->tenant);

        try {
            $application = $this->recruitment->apply([
                ...Arr::except($request->validated(), ['resume', 'privacy_consent', 'website']),
                'vacancy_id' => $vacancy->id,
                'source' => 'public_careers',
            ], null, $request->file('resume'));

            return response()->json([
                'message' => 'Your application has been submitted successfully.',
                'data' => ['reference' => 'APP-'.str_pad((string) $application->id, 6, '0', STR_PAD_LEFT)],
            ], 201);
        } finally {
            $currentTenant->forget();
        }
    }

    private function publicQuery(): Builder
    {
        return Vacancy::withoutGlobalScope(TenantScope::class)
            ->where('visibility', Vacancy::VISIBILITY_PUBLIC)
            ->where('status', Vacancy::STATUS_OPEN)
            ->whereNotNull('public_slug')
            ->where(fn (Builder $query) => $query->whereNull('opens_at')->orWhereDate('opens_at', '<=', today()))
            ->where(fn (Builder $query) => $query->whereNull('closes_at')->orWhereDate('closes_at', '>=', today()))
            ->whereHas('tenant', fn (Builder $query) => $query->where('status', Tenant::STATUS_ACTIVE));
    }

    private function findPublicVacancy(string $slug): Vacancy
    {
        return $this->publicQuery()->with('tenant')->where('public_slug', $slug)->firstOrFail();
    }

    private function hydrateForTenant(Vacancy $vacancy): Vacancy
    {
        $currentTenant = app(CurrentTenant::class);
        $currentTenant->set($vacancy->tenant);

        try {
            return Vacancy::query()
                ->with(['tenant', 'employmentType', 'requisition.position', 'requisition.department'])
                ->findOrFail($vacancy->id);
        } finally {
            $currentTenant->forget();
        }
    }

    private function hydrateTenantRelations(Collection $vacancies): Collection
    {
        $hydrated = [];
        $currentTenant = app(CurrentTenant::class);

        try {
            foreach ($vacancies->groupBy('tenant_id') as $tenantId => $tenantVacancies) {
                $tenant = Tenant::query()->where('status', Tenant::STATUS_ACTIVE)->find($tenantId);
                if (! $tenant) {
                    continue;
                }

                $currentTenant->set($tenant);
                foreach (Vacancy::query()
                    ->with(['tenant', 'employmentType', 'requisition.position', 'requisition.department'])
                    ->whereKey($tenantVacancies->pluck('id'))
                    ->get() as $vacancy) {
                    $hydrated[$vacancy->id] = $vacancy;
                }
            }
        } finally {
            $currentTenant->forget();
        }

        return $vacancies->map(fn (Vacancy $vacancy) => $hydrated[$vacancy->id] ?? $vacancy);
    }
}
