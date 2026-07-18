<?php

namespace App\Modules\Recruitment\Controllers;

use App\Modules\Employee\Resources\EmployeeResource;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Interview;
use App\Modules\Recruitment\Models\Offer;
use App\Modules\Recruitment\Models\OnboardingTask;
use App\Modules\Recruitment\Requests\InterviewRequest;
use App\Modules\Recruitment\Requests\MoveApplicationRequest;
use App\Modules\Recruitment\Requests\OfferRequest;
use App\Modules\Recruitment\Requests\OnboardingTaskRequest;
use App\Modules\Recruitment\Requests\StoreApplicationRequest;
use App\Modules\Recruitment\Resources\ApplicationResource;
use App\Modules\Recruitment\Services\RecruitmentService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ApplicationController extends Controller
{
    public function __construct(private readonly RecruitmentService $recruitment) {}

    public function index(Request $request): mixed
    {
        abort_unless($request->user()->can(Permissions::RECRUITMENT_VIEW), 403);

        $query = Application::query()->with(['applicant', 'vacancy'])
            ->when($request->filled('vacancy_id'), fn ($query) => $query->where('vacancy_id', $request->integer('vacancy_id')))
            ->when($request->filled('stage'), fn ($query) => $query->where('stage', $request->string('stage')))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->whereHas('applicant', fn ($applicants) => $applicants
                    ->where('first_name', 'like', '%'.$search.'%')
                    ->orWhere('last_name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%'));
            })
            ->latest('applied_at');

        return ApplicationResource::collection($query->paginate(min($request->integer('per_page', 20), 100))->appends($request->query()));
    }

    public function store(StoreApplicationRequest $request): JsonResponse
    {
        return (new ApplicationResource($this->load($this->recruitment->apply($request->validated(), $request->user()))))
            ->response()->setStatusCode(201);
    }

    public function show(Request $request, Application $application): ApplicationResource
    {
        abort_unless($request->user()->can(Permissions::RECRUITMENT_VIEW), 403);

        return new ApplicationResource($this->load($application));
    }

    public function resume(Request $request, Application $application): StreamedResponse
    {
        abort_unless($request->user()->can(Permissions::RECRUITMENT_VIEW), 403);
        $application->loadMissing('applicant');

        $path = $application->applicant->resume_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $filename = Str::slug($application->applicant->full_name).'-resume'.($extension ? '.'.$extension : '');

        return Storage::disk('local')->download($path, $filename);
    }

    public function move(MoveApplicationRequest $request, Application $application): ApplicationResource
    {
        $data = $request->validated();

        return new ApplicationResource($this->load($this->recruitment->move($application, $data['stage'], $request->user(), $data['notes'] ?? null)));
    }

    public function scheduleInterview(InterviewRequest $request, Application $application): JsonResponse
    {
        $interview = $application->interviews()->create([...$request->validated(), 'status' => 'scheduled', 'created_by' => $request->user()->id]);
        $this->recruitment->move($application, 'interview_scheduled', $request->user(), 'Interview scheduled');

        return response()->json(['data' => $interview], 201);
    }

    public function completeInterview(Request $request, Interview $interview): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::RECRUITMENT_MANAGE), 403);
        $data = $request->validate([
            'score' => ['required', 'integer', 'between:0,100'],
            'recommendation' => ['required', Rule::in(['strong_yes', 'yes', 'no', 'strong_no'])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $interview->update([...$data, 'status' => 'completed']);
        $this->recruitment->move($interview->application, 'interviewed', $request->user(), 'Interview completed');

        return response()->json(['data' => $interview->refresh()]);
    }

    public function createOffer(OfferRequest $request, Application $application): JsonResponse
    {
        return response()->json(['data' => $this->recruitment->createOffer($application, $request->validated(), $request->user())], 201);
    }

    public function offerAction(Request $request, Application $application, Offer $offer, string $action): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::RECRUITMENT_MANAGE), 403);
        abort_unless($offer->application_id === $application->id && in_array($action, ['send', 'accept', 'decline'], true), 404);

        return response()->json(['data' => $this->recruitment->actOnOffer($offer, $action, $request->user())]);
    }

    public function createTask(OnboardingTaskRequest $request, Application $application): JsonResponse
    {
        abort_unless($application->stage === 'accepted', 409, 'Onboarding tasks can only be added after an offer is accepted.');
        $task = $application->onboardingTasks()->create([...$request->validated(), 'status' => 'pending', 'created_by' => $request->user()->id]);

        return response()->json(['data' => $task], 201);
    }

    public function completeTask(Request $request, Application $application, OnboardingTask $task): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::RECRUITMENT_MANAGE), 403);
        abort_unless($task->application_id === $application->id, 404);

        return response()->json(['data' => $this->recruitment->completeTask($task)]);
    }

    public function hire(Request $request, Application $application): EmployeeResource
    {
        abort_unless($request->user()->can(Permissions::RECRUITMENT_MANAGE), 403);

        return new EmployeeResource($this->recruitment->hire($application, $request->user()));
    }

    private function load(Application $application): Application
    {
        return $application->load(['applicant', 'vacancy', 'stageHistory', 'interviews', 'offers', 'onboardingTasks']);
    }
}
