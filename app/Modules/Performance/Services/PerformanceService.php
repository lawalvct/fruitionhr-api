<?php

namespace App\Modules\Performance\Services;

use App\Core\Notifications\SystemNotification;
use App\Models\User;
use App\Modules\Performance\Models\AppraisalAppeal;
use App\Modules\Performance\Models\AppraisalAssignment;
use App\Modules\Performance\Models\AppraisalCycle;
use App\Modules\Performance\Models\AppraisalResult;
use App\Modules\Performance\Models\AppraisalReviewer;
use App\Modules\Performance\Models\AppraisalTemplate;
use App\Modules\Performance\Models\PerformanceImprovementPlan;
use App\Modules\Performance\Models\RatingScale;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PerformanceService
{
    public function createRatingScale(array $data, User $user): RatingScale
    {
        return DB::transaction(function () use ($data, $user): RatingScale {
            $options = $data['options'];
            unset($data['options']);
            $scale = RatingScale::query()->create([...$data, 'created_by' => $user->id]);
            foreach ($options as $index => $option) {
                $scale->options()->create([...$option, 'sort_order' => $index + 1]);
            }

            return $scale->load('options');
        });
    }

    public function createTemplate(array $data, User $user): AppraisalTemplate
    {
        $items = $data['items'];
        if (array_sum(array_column($items, 'weight')) !== 100) {
            throw ValidationException::withMessages(['items' => 'KPI weights must total exactly 100%.']);
        }

        return DB::transaction(function () use ($data, $items, $user): AppraisalTemplate {
            unset($data['items']);
            $template = AppraisalTemplate::query()->create([...$data, 'created_by' => $user->id]);
            foreach ($items as $item) {
                $template->items()->create($item);
            }

            return $template->load(['ratingScale.options', 'items.kpi.category']);
        });
    }

    /**
     * Templates are immutable once created (creation validates weights), so a
     * live cycle can never have a template changed under its reviewers. Cloning
     * is how HR iterates (spec §6).
     */
    public function cloneTemplate(AppraisalTemplate $template, User $user): AppraisalTemplate
    {
        return DB::transaction(function () use ($template, $user): AppraisalTemplate {
            $template->load('items');
            $copy = AppraisalTemplate::query()->create([
                'rating_scale_id' => $template->rating_scale_id,
                'name' => $this->uniqueTemplateName($template->name),
                'department' => $template->department,
                'target_role' => $template->target_role,
                'min_passing_basis_points' => $template->min_passing_basis_points,
                'description' => $template->description,
                'created_by' => $user->id,
            ]);

            foreach ($template->items as $item) {
                $copy->items()->create($item->only(['performance_kpi_id', 'weight', 'is_mandatory']));
            }

            return $copy->load(['ratingScale.options', 'items.kpi.category']);
        });
    }

    public function createAssignment(array $data, User $user): AppraisalAssignment
    {
        $reviewers = $data['reviewers'];
        if (array_sum(array_column($reviewers, 'weight')) !== 100) {
            throw ValidationException::withMessages(['reviewers' => 'Reviewer weights must total exactly 100%.']);
        }

        $cycle = AppraisalCycle::query()->findOrFail($data['appraisal_cycle_id']);
        if ($cycle->status !== 'open') {
            throw new ConflictHttpException('Assignments can only be created in an open appraisal cycle.');
        }

        if (! $cycle->self_review_enabled && in_array('self', array_column($reviewers, 'reviewer_type'), true)) {
            throw ValidationException::withMessages(['reviewers' => 'Self review is disabled for this cycle.']);
        }

        $template = AppraisalTemplate::query()->with('items')->findOrFail($data['appraisal_template_id']);
        if ($template->items->sum('weight') !== 100) {
            throw new ConflictHttpException('The appraisal template KPI weights must total 100%.');
        }

        return DB::transaction(function () use ($data, $reviewers, $user): AppraisalAssignment {
            unset($data['reviewers']);
            $assignment = AppraisalAssignment::query()->create([...$data, 'assigned_by' => $user->id, 'status' => 'pending']);
            foreach ($reviewers as $reviewer) {
                $created = $assignment->reviewers()->create([...$reviewer, 'status' => 'pending']);
                $created->user?->notify(new SystemNotification(
                    'Appraisal review assigned',
                    'You have been assigned as '.$created->reviewer_type.' reviewer for an appraisal.',
                    '/performance',
                ));
            }

            return $assignment->load($this->relations());
        });
    }

    public function submitReview(AppraisalReviewer $reviewer, array $data): AppraisalAssignment
    {
        if ($reviewer->status !== 'pending') {
            throw new ConflictHttpException('This review has already been submitted.');
        }

        return DB::transaction(function () use ($reviewer, $data): AppraisalAssignment {
            $reviewer = AppraisalReviewer::query()->lockForUpdate()->findOrFail($reviewer->id);
            $assignment = $reviewer->assignment()->with(['template.items'])->firstOrFail();
            $items = $assignment->template->items->keyBy('id');
            $received = collect($data['scores'])->pluck('appraisal_template_item_id');

            // Every score must belong to the template; every mandatory KPI must
            // be scored. Optional KPIs may be skipped (weights re-normalize).
            if ($received->diff($items->keys())->isNotEmpty()) {
                throw ValidationException::withMessages(['scores' => 'Scores must belong to the assignment template.']);
            }

            $missingMandatory = $items->filter(fn ($item) => $item->is_mandatory)->keys()->diff($received);
            if ($missingMandatory->isNotEmpty()) {
                throw ValidationException::withMessages(['scores' => 'Every mandatory KPI must be scored before submitting.']);
            }

            $review = $reviewer->review()->create(['comments' => $data['comments'] ?? null, 'submitted_at' => now()]);
            foreach ($data['scores'] as $score) {
                $review->scores()->create($score);
            }
            $reviewer->update(['status' => 'submitted', 'submitted_at' => now()]);
            $assignment->update(['status' => 'in_progress']);

            if (! $assignment->reviewers()->where('status', 'pending')->exists()) {
                $this->calculateResult($assignment);
            }

            return $assignment->refresh()->load($this->relations());
        });
    }

    /**
     * Return a submitted review to its reviewer for revision (spec §5).
     * Deletes their scores and any computed result so the workflow restarts
     * from the reviewer's resubmission.
     */
    public function returnReviewer(AppraisalReviewer $reviewer): AppraisalAssignment
    {
        if ($reviewer->status !== 'submitted') {
            throw new ConflictHttpException('Only a submitted review can be returned for revision.');
        }

        return DB::transaction(function () use ($reviewer): AppraisalAssignment {
            $assignment = $reviewer->assignment()->firstOrFail();

            if ($result = $assignment->result()->first()) {
                if (in_array($result->status, [AppraisalResult::STATUS_ACKNOWLEDGED, AppraisalResult::STATUS_APPEAL_RESOLVED], true)) {
                    throw new ConflictHttpException('An acknowledged result can no longer be reopened.');
                }
                $result->outcomes()->delete();
                $result->calibrationAdjustments()->delete();
                $result->delete();
            }

            $reviewer->review()->first()?->scores()->delete();
            $reviewer->review()->delete();
            $reviewer->update(['status' => 'pending', 'submitted_at' => null]);
            $assignment->update(['status' => 'in_progress']);

            $reviewer->user?->notify(new SystemNotification(
                'Appraisal review returned',
                'Your appraisal review was returned for revision. Please rescore and resubmit.',
                '/performance',
                'warning',
            ));

            return $assignment->refresh()->load($this->relations());
        });
    }

    public function calibrate(AppraisalResult $result, int $newScore, string $justification, User $user): AppraisalResult
    {
        if (! in_array($result->status, [AppraisalResult::STATUS_PENDING_CALIBRATION, AppraisalResult::STATUS_PENDING_APPROVAL], true)) {
            throw new ConflictHttpException('Only results awaiting calibration or approval can be adjusted.');
        }

        return DB::transaction(function () use ($result, $newScore, $justification, $user): AppraisalResult {
            $result->calibrationAdjustments()->create([
                'adjusted_by' => $user->id,
                'old_score_basis_points' => $result->calibrated_score_basis_points ?? $result->final_score_basis_points,
                'new_score_basis_points' => $newScore,
                'justification' => $justification,
            ]);

            $result->update([
                'calibrated_score_basis_points' => $newScore,
                'final_score_basis_points' => $newScore,
                'grade' => $this->gradeFor($result->assignment()->firstOrFail(), $newScore),
            ]);

            return $result->refresh();
        });
    }

    /** Move every calibrated result in the cycle into the HR approval queue. */
    public function finalizeCalibration(AppraisalCycle $cycle): int
    {
        return AppraisalResult::query()
            ->whereHas('assignment', fn ($query) => $query->where('appraisal_cycle_id', $cycle->id))
            ->where('status', AppraisalResult::STATUS_PENDING_CALIBRATION)
            ->update(['status' => AppraisalResult::STATUS_PENDING_APPROVAL]);
    }

    public function approve(AppraisalResult $result, User $user): AppraisalResult
    {
        if (! in_array($result->status, [AppraisalResult::STATUS_PENDING_APPROVAL, AppraisalResult::STATUS_REJECTED], true)) {
            throw new ConflictHttpException('Only results pending approval can be approved.');
        }

        $result->update([
            'status' => AppraisalResult::STATUS_APPROVED,
            'approved_by' => $user->id,
            'approved_at' => now(),
            'rejected_reason' => null,
        ]);

        $result->assignment()->first()?->employee?->user?->notify(new SystemNotification(
            'Appraisal result ready',
            'Your appraisal result has been approved. Please review and acknowledge it.',
            '/performance',
            'success',
        ));

        return $result->refresh();
    }

    public function reject(AppraisalResult $result, string $reason): AppraisalResult
    {
        if ($result->status !== AppraisalResult::STATUS_PENDING_APPROVAL) {
            throw new ConflictHttpException('Only results pending approval can be rejected.');
        }

        $result->update(['status' => AppraisalResult::STATUS_REJECTED, 'rejected_reason' => $reason]);

        return $result->refresh();
    }

    public function acknowledge(AppraisalResult $result): AppraisalResult
    {
        if ($result->status !== AppraisalResult::STATUS_APPROVED) {
            throw new ConflictHttpException('Only an approved result can be acknowledged.');
        }

        $result->update(['status' => AppraisalResult::STATUS_ACKNOWLEDGED, 'acknowledged_at' => now()]);

        return $result->refresh();
    }

    public function appeal(AppraisalResult $result, string $reason): AppraisalAppeal
    {
        if (! in_array($result->status, [AppraisalResult::STATUS_APPROVED, AppraisalResult::STATUS_ACKNOWLEDGED], true)) {
            throw new ConflictHttpException('Appeals can only be raised against an approved result.');
        }

        $windowDays = $result->assignment()->first()?->cycle?->appeal_window_days ?? 7;
        if ($result->approved_at !== null && $result->approved_at->addDays($windowDays)->isPast()) {
            throw new ConflictHttpException("The appeal window of {$windowDays} days has closed.");
        }

        return DB::transaction(function () use ($result, $reason): AppraisalAppeal {
            $appeal = $result->appeals()->create([
                'employee_id' => $result->assignment()->firstOrFail()->employee_id,
                'reason' => $reason,
                'status' => AppraisalAppeal::STATUS_OPEN,
            ]);
            $result->update(['status' => AppraisalResult::STATUS_APPEALED]);

            return $appeal;
        });
    }

    public function resolveAppeal(AppraisalAppeal $appeal, array $data, User $user): AppraisalAppeal
    {
        if ($appeal->status !== AppraisalAppeal::STATUS_OPEN) {
            throw new ConflictHttpException('This appeal has already been resolved.');
        }

        return DB::transaction(function () use ($appeal, $data, $user): AppraisalAppeal {
            $result = $appeal->result()->firstOrFail();

            // Upholding with a revised score writes the same audit trail as a
            // calibration adjustment, so every score change stays traceable.
            if ($data['outcome'] === AppraisalAppeal::STATUS_UPHELD && isset($data['new_score_basis_points'])) {
                $result->calibrationAdjustments()->create([
                    'adjusted_by' => $user->id,
                    'old_score_basis_points' => $result->final_score_basis_points,
                    'new_score_basis_points' => $data['new_score_basis_points'],
                    'justification' => 'Appeal upheld: '.$data['resolution_note'],
                ]);
                $result->update([
                    'calibrated_score_basis_points' => $data['new_score_basis_points'],
                    'final_score_basis_points' => $data['new_score_basis_points'],
                    'grade' => $this->gradeFor($result->assignment()->firstOrFail(), $data['new_score_basis_points']),
                ]);
            }

            $result->update(['status' => AppraisalResult::STATUS_APPEAL_RESOLVED]);
            $appeal->update([
                'status' => $data['outcome'],
                'resolution_note' => $data['resolution_note'],
                'resolved_by' => $user->id,
                'resolved_at' => now(),
            ]);

            $result->assignment()->first()?->employee?->user?->notify(new SystemNotification(
                'Appraisal appeal resolved',
                'Your appraisal appeal was '.($data['outcome'] === AppraisalAppeal::STATUS_UPHELD ? 'upheld' : 'not upheld').'.',
                '/performance',
            ));

            return $appeal->refresh();
        });
    }

    private function calculateResult(AppraisalAssignment $assignment): AppraisalResult
    {
        $assignment->load(['cycle', 'template.ratingScale.options', 'template.items', 'reviewers.review.scores']);
        $itemWeights = $assignment->template->items->pluck('weight', 'id');
        $finalScore = 0;

        foreach ($assignment->reviewers as $reviewer) {
            // Optional KPIs may be unscored — re-normalize over the weights of
            // the items this reviewer actually scored (spec §4.2).
            $weighted = 0;
            $totalWeight = 0;
            foreach ($reviewer->review->scores as $score) {
                $weight = $itemWeights[$score->appraisal_template_item_id];
                $weighted += $score->score_basis_points * $weight;
                $totalWeight += $weight;
            }
            $reviewScore = $totalWeight > 0 ? intdiv($weighted, $totalWeight) : 0;
            $finalScore += $reviewScore * $reviewer->weight;
        }

        $finalScore = intdiv($finalScore, 100);
        $status = $assignment->cycle->calibration_enabled
            ? AppraisalResult::STATUS_PENDING_CALIBRATION
            : AppraisalResult::STATUS_PENDING_APPROVAL;

        $result = $assignment->result()->create([
            'final_score_basis_points' => $finalScore,
            'raw_score_basis_points' => $finalScore,
            'calibrated_score_basis_points' => $finalScore,
            'grade' => $this->gradeFor($assignment, $finalScore),
            'status' => $status,
            'finalised_at' => now(),
        ]);
        $assignment->update(['status' => 'completed']);

        if ($finalScore >= 8500) {
            $result->outcomes()->create(['type' => 'recognition', 'notes' => 'High-performance recognition recommended.']);
        }

        // Below the template's passing floor → PIP suggested automatically
        // (spec §4.4). Runs in parallel with HR approval, does not block it.
        $minPassing = $assignment->template->min_passing_basis_points;
        if ($minPassing !== null && $finalScore < $minPassing) {
            $result->outcomes()->create(['type' => 'improvement_plan', 'notes' => 'Score below the template passing floor — PIP suggested.']);
            $result->outcomes()->create(['type' => 'training', 'notes' => 'Targeted development intervention recommended.']);

            PerformanceImprovementPlan::query()->create([
                'employee_id' => $assignment->employee_id,
                'appraisal_result_id' => $result->id,
                'reason' => sprintf(
                    'Auto-suggested: final score %.1f%% fell below the %.1f%% passing floor for "%s".',
                    $finalScore / 100,
                    $minPassing / 100,
                    $assignment->template->name,
                ),
                'status' => PerformanceImprovementPlan::STATUS_DRAFT,
                'starts_at' => now()->toDateString(),
                'ends_at' => now()->addMonths(3)->toDateString(),
            ]);
        } elseif ($minPassing === null && $finalScore < 6000) {
            $result->outcomes()->create(['type' => 'improvement_plan', 'notes' => 'Performance improvement plan recommended.']);
            $result->outcomes()->create(['type' => 'training', 'notes' => 'Targeted development intervention recommended.']);
        }

        return $result;
    }

    private function gradeFor(AppraisalAssignment $assignment, int $score): string
    {
        $assignment->loadMissing('template.ratingScale.options');

        return $assignment->template->ratingScale->options
            ->first(fn ($option) => $score >= $option->min_score_basis_points && $score <= $option->max_score_basis_points)?->label
            ?? 'Unrated';
    }

    private function uniqueTemplateName(string $base): string
    {
        $name = $base.' (copy)';
        $suffix = 2;
        while (AppraisalTemplate::withTrashed()->where('name', $name)->exists()) {
            $name = $base.' (copy '.$suffix++.')';
        }

        return $name;
    }

    private function relations(): array
    {
        return ['cycle', 'template.ratingScale.options', 'template.items.kpi.category', 'employee', 'reviewers.user', 'reviewers.review.scores', 'result.outcomes', 'result.appeals'];
    }
}
