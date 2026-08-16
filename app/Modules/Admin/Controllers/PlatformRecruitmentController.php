<?php

namespace App\Modules\Admin\Controllers;

use App\Modules\Admin\Requests\ListPlatformRecruitmentRequest;
use App\Modules\Admin\Resources\PlatformApplicationResource;
use App\Modules\Admin\Resources\PlatformVacancyResource;
use App\Modules\Admin\Services\PlatformRecruitmentService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/** Read-only careers oversight across every tenant on the platform. */
class PlatformRecruitmentController extends Controller
{
    public function vacancies(
        ListPlatformRecruitmentRequest $request,
        PlatformRecruitmentService $service,
    ): AnonymousResourceCollection {
        return PlatformVacancyResource::collection(
            $service->paginateVacancies($request->validated())
        )->additional(['summary' => $service->summary()]);
    }

    public function vacancy(int $vacancy, PlatformRecruitmentService $service): PlatformVacancyResource
    {
        return new PlatformVacancyResource($service->findVacancy($vacancy));
    }

    public function applications(
        ListPlatformRecruitmentRequest $request,
        PlatformRecruitmentService $service,
    ): AnonymousResourceCollection {
        return PlatformApplicationResource::collection(
            $service->paginateApplications($request->validated())
        );
    }
}
