<?php

namespace App\Modules\Performance\Controllers;

use App\Modules\Performance\Models\AppraisalCycle;
use App\Modules\Performance\Models\AppraisalTemplate;
use App\Modules\Performance\Models\PerformanceCategory;
use App\Modules\Performance\Models\PerformanceKpi;
use App\Modules\Performance\Models\RatingScale;
use App\Modules\Performance\Requests\CategoryRequest;
use App\Modules\Performance\Requests\CycleRequest;
use App\Modules\Performance\Requests\KpiRequest;
use App\Modules\Performance\Requests\RatingScaleRequest;
use App\Modules\Performance\Requests\TemplateRequest;
use App\Modules\Performance\Resources\CategoryResource;
use App\Modules\Performance\Resources\CycleResource;
use App\Modules\Performance\Resources\KpiResource;
use App\Modules\Performance\Resources\RatingScaleResource;
use App\Modules\Performance\Resources\TemplateResource;
use App\Modules\Performance\Services\PerformanceService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PerformanceSetupController extends Controller
{
    public function __construct(private readonly PerformanceService $performance) {}

    public function categories(Request $request): mixed
    {
        $this->view($request);
        return CategoryResource::collection(PerformanceCategory::query()->orderBy('name')->get());
    }

    public function storeCategory(CategoryRequest $request): JsonResponse
    {
        $category = PerformanceCategory::query()->create([...$request->validated(), 'created_by' => $request->user()->id]);
        return (new CategoryResource($category))->response()->setStatusCode(201);
    }

    public function kpis(Request $request): mixed
    {
        $this->view($request);
        return KpiResource::collection(PerformanceKpi::query()->with('category')->orderBy('name')->get());
    }

    public function storeKpi(KpiRequest $request): JsonResponse
    {
        $kpi = PerformanceKpi::query()->create([...$request->validated(), 'created_by' => $request->user()->id]);
        return (new KpiResource($kpi->load('category')))->response()->setStatusCode(201);
    }

    public function ratingScales(Request $request): mixed
    {
        $this->view($request);
        return RatingScaleResource::collection(RatingScale::query()->with('options')->orderBy('name')->get());
    }

    public function storeRatingScale(RatingScaleRequest $request): JsonResponse
    {
        return (new RatingScaleResource($this->performance->createRatingScale($request->validated(), $request->user())))
            ->response()->setStatusCode(201);
    }

    public function templates(Request $request): mixed
    {
        $this->view($request);
        return TemplateResource::collection(AppraisalTemplate::query()->with(['ratingScale', 'items.kpi.category'])->orderBy('name')->get());
    }

    public function storeTemplate(TemplateRequest $request): JsonResponse
    {
        return (new TemplateResource($this->performance->createTemplate($request->validated(), $request->user())))
            ->response()->setStatusCode(201);
    }

    public function cycles(Request $request): mixed
    {
        $this->view($request);
        return CycleResource::collection(AppraisalCycle::query()->withCount('assignments')->latest('starts_at')->get());
    }

    public function storeCycle(CycleRequest $request): JsonResponse
    {
        $cycle = AppraisalCycle::query()->create([...$request->validated(), 'status' => 'draft', 'created_by' => $request->user()->id]);
        return (new CycleResource($cycle->loadCount('assignments')))->response()->setStatusCode(201);
    }

    public function cycleAction(Request $request, AppraisalCycle $cycle, string $action): CycleResource
    {
        abort_unless($request->user()->can(Permissions::PERFORMANCE_MANAGE), 403);
        if (($action === 'open' && $cycle->status !== 'draft') || ($action === 'close' && $cycle->status !== 'open')) {
            throw new ConflictHttpException('This cycle action is not valid for its current status.');
        }
        abort_unless(in_array($action, ['open', 'close'], true), 404);
        $cycle->update(['status' => $action === 'open' ? 'open' : 'closed']);

        return new CycleResource($cycle->refresh()->loadCount('assignments'));
    }

    private function view(Request $request): void
    {
        abort_unless($request->user()->can(Permissions::PERFORMANCE_VIEW) || $request->user()->can(Permissions::PERFORMANCE_REVIEW), 403);
    }
}
