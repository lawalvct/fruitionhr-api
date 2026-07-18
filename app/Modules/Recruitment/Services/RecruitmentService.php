<?php

namespace App\Modules\Recruitment\Services;

use App\Core\Workflow\WorkflowService;
use App\Models\User;
use App\Modules\Employee\Models\Employee;
use App\Modules\Employee\Services\EmployeeService;
use App\Modules\Recruitment\Models\Applicant;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\ManpowerRequisition;
use App\Modules\Recruitment\Models\Offer;
use App\Modules\Recruitment\Models\OnboardingTask;
use App\Modules\Recruitment\Models\Vacancy;
use App\Support\Tenancy\CurrentTenant;
use App\Support\Tenancy\TenantScope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RecruitmentService
{
    public function __construct(
        private readonly WorkflowService $workflow,
        private readonly EmployeeService $employees,
    ) {}

    public function createRequisition(array $data, User $user): ManpowerRequisition
    {
        return ManpowerRequisition::query()->create([
            ...$data,
            'requested_by' => $user->id,
            'created_by' => $user->id,
            'status' => ManpowerRequisition::STATUS_DRAFT,
        ]);
    }

    public function submitRequisition(ManpowerRequisition $requisition, User $user): ManpowerRequisition
    {
        if ($requisition->status !== ManpowerRequisition::STATUS_DRAFT) {
            throw new ConflictHttpException('Only draft requisitions can be submitted.');
        }

        return DB::transaction(function () use ($requisition, $user): ManpowerRequisition {
            $requisition->update(['status' => ManpowerRequisition::STATUS_PENDING, 'submitted_at' => now()]);
            $this->workflow->submit($requisition, 'recruitment_requisition', $user);

            return $requisition->refresh();
        });
    }

    public function markRequisition(ManpowerRequisition $requisition, string $status): void
    {
        $requisition->update(['status' => $status, 'completed_at' => now()]);
    }

    public function createVacancy(array $data, User $user): Vacancy
    {
        $requisition = ManpowerRequisition::query()->findOrFail($data['manpower_requisition_id']);

        if ($requisition->status !== ManpowerRequisition::STATUS_APPROVED) {
            throw new ConflictHttpException('The manpower requisition must be approved before a vacancy is created.');
        }

        $allocated = (int) $requisition->vacancies()->sum('positions_available');
        if ($allocated + (int) $data['positions_available'] > $requisition->headcount) {
            throw new ConflictHttpException('Vacancy positions exceed the approved requisition headcount.');
        }

        $tenantSlug = app(CurrentTenant::class)->get()?->slug ?? 'company';

        return Vacancy::query()->create([
            ...$data,
            'employment_type_id' => $data['employment_type_id'] ?? $requisition->employment_type_id,
            'public_slug' => $this->uniqueVacancySlug($tenantSlug, $data['title']),
            'visibility' => $data['visibility'] ?? Vacancy::VISIBILITY_PRIVATE,
            'created_by' => $user->id,
            'status' => Vacancy::STATUS_DRAFT,
        ]);
    }

    public function publish(Vacancy $vacancy): Vacancy
    {
        if (! $vacancy->public_slug) {
            $tenantSlug = app(CurrentTenant::class)->get()?->slug ?? 'company';
            $vacancy->public_slug = $this->uniqueVacancySlug($tenantSlug, $vacancy->title, $vacancy->id);
        }

        $vacancy->visibility = Vacancy::VISIBILITY_PUBLIC;
        $vacancy->save();

        return $vacancy->refresh();
    }

    public function apply(array $data, ?User $user, ?UploadedFile $resume = null): Application
    {
        return DB::transaction(function () use ($data, $user, $resume): Application {
            $vacancy = Vacancy::query()->findOrFail($data['vacancy_id']);
            if ($vacancy->status !== Vacancy::STATUS_OPEN) {
                throw new ConflictHttpException('Applications are only accepted for open vacancies.');
            }

            $applicant = Applicant::query()->firstOrCreate(
                ['email' => mb_strtolower($data['email'])],
                [
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'phone' => $data['phone'] ?? null,
                    'city' => $data['city'] ?? null,
                    'state' => $data['state'] ?? null,
                    'linkedin_url' => $data['linkedin_url'] ?? null,
                    'created_by' => $user?->id,
                ],
            );

            if (Application::query()->where('vacancy_id', $vacancy->id)->where('applicant_id', $applicant->id)->exists()) {
                throw new ConflictHttpException('An application for this vacancy already exists for this email address.');
            }

            $applicant->fill([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'phone' => $data['phone'] ?? $applicant->phone,
                'city' => $data['city'] ?? $applicant->city,
                'state' => $data['state'] ?? $applicant->state,
                'linkedin_url' => $data['linkedin_url'] ?? $applicant->linkedin_url,
            ])->save();

            if ($resume) {
                $oldPath = $applicant->resume_path;
                $tenantId = app(CurrentTenant::class)->id();
                $path = $resume->store("tenants/{$tenantId}/recruitment/applicants/{$applicant->id}/resume", 'local');
                $applicant->update([
                    'resume_path' => $path,
                    'resume_original_name' => $resume->getClientOriginalName(),
                ]);

                if ($oldPath && $oldPath !== $path) {
                    DB::afterCommit(fn () => Storage::disk('local')->delete($oldPath));
                }
            }

            $application = Application::query()->create([
                'vacancy_id' => $vacancy->id,
                'applicant_id' => $applicant->id,
                'stage' => 'applied',
                'source' => $data['source'] ?? null,
                'cover_letter' => $data['cover_letter'] ?? null,
                'applied_at' => now(),
                'created_by' => $user?->id,
            ]);

            $application->stageHistory()->create(['to_stage' => 'applied', 'changed_by' => $user?->id]);

            return $application;
        });
    }

    private function uniqueVacancySlug(string $tenantSlug, string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($tenantSlug.'-'.$title) ?: 'vacancy';
        $slug = $base;
        $suffix = 2;

        while (Vacancy::withoutGlobalScope(TenantScope::class)
            ->withTrashed()
            ->where('public_slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    public function move(Application $application, string $stage, User $user, ?string $notes = null): Application
    {
        if ($application->stage === $stage) {
            return $application;
        }

        return DB::transaction(function () use ($application, $stage, $user, $notes): Application {
            $from = $application->stage;
            $application->update(['stage' => $stage]);
            $application->stageHistory()->create(['from_stage' => $from, 'to_stage' => $stage, 'notes' => $notes, 'changed_by' => $user->id]);

            return $application->refresh();
        });
    }

    public function createOffer(Application $application, array $data, User $user): Offer
    {
        $offer = $application->offers()->create([...$data, 'status' => 'draft', 'created_by' => $user->id]);
        $this->move($application, 'offer', $user, 'Offer prepared');

        return $offer;
    }

    public function actOnOffer(Offer $offer, string $action, User $user): Offer
    {
        if ($action === 'send' && $offer->status === 'draft') {
            $offer->update(['status' => 'sent', 'sent_at' => now()]);
        } elseif ($action === 'accept' && $offer->status === 'sent') {
            DB::transaction(function () use ($offer, $user): void {
                $offer->update(['status' => 'accepted', 'responded_at' => now()]);
                $this->move($offer->application, 'accepted', $user, 'Offer accepted');
                foreach (['Verify employee documents', 'Complete employee profile', 'Prepare first-day access'] as $title) {
                    $offer->application->onboardingTasks()->firstOrCreate(['title' => $title], ['created_by' => $user->id]);
                }
            });
        } elseif ($action === 'decline' && $offer->status === 'sent') {
            $offer->update(['status' => 'declined', 'responded_at' => now()]);
            $this->move($offer->application, 'rejected', $user, 'Offer declined');
        } else {
            throw new ConflictHttpException('This offer action is not valid for its current status.');
        }

        return $offer->refresh();
    }

    public function completeTask(OnboardingTask $task): OnboardingTask
    {
        if ($task->status !== 'pending') {
            throw new ConflictHttpException('Only pending onboarding tasks can be completed.');
        }

        $task->update(['status' => 'completed', 'completed_at' => now()]);

        return $task->refresh();
    }

    public function hire(Application $application, User $user): Employee
    {
        if ($application->stage !== 'accepted' || $application->latestOffer?->status !== 'accepted') {
            throw new ConflictHttpException('Only a candidate with an accepted offer can be hired.');
        }

        if ($application->onboardingTasks()->where('status', '!=', 'completed')->exists()) {
            throw new ConflictHttpException('Complete all onboarding tasks before hiring the candidate.');
        }

        return DB::transaction(function () use ($application, $user): Employee {
            $application->loadMissing(['applicant', 'vacancy.requisition', 'latestOffer']);
            $applicant = $application->applicant;
            $requisition = $application->vacancy->requisition;
            $hiredAt = $application->latestOffer->start_date->toDateString();

            $employee = $this->employees->create([
                'first_name' => $applicant->first_name,
                'last_name' => $applicant->last_name,
                'personal_email' => $applicant->email,
                'phone' => $applicant->phone,
                'city' => $applicant->city,
                'state' => $applicant->state,
                'employment_status' => Employee::STATUS_ACTIVE,
                'hired_at' => $hiredAt,
            ], [
                'department_id' => $requisition->department_id,
                'position_id' => $requisition->position_id,
                'employment_type_id' => $requisition->employment_type_id,
                'effective_from' => $hiredAt,
            ], $user->id);

            $this->move($application, 'hired', $user, 'Employee record created');
            $application->update(['hired_at' => now()]);

            return $employee;
        });
    }
}
