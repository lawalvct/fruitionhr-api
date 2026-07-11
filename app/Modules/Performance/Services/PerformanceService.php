<?php

namespace App\Modules\Performance\Services;

use App\Models\User;
use App\Modules\Performance\Models\AppraisalAssignment;
use App\Modules\Performance\Models\AppraisalCycle;
use App\Modules\Performance\Models\AppraisalResult;
use App\Modules\Performance\Models\AppraisalReviewer;
use App\Modules\Performance\Models\AppraisalTemplate;
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

        $template = AppraisalTemplate::query()->with('items')->findOrFail($data['appraisal_template_id']);
        if ($template->items->sum('weight') !== 100) {
            throw new ConflictHttpException('The appraisal template KPI weights must total 100%.');
        }

        return DB::transaction(function () use ($data, $reviewers, $user): AppraisalAssignment {
            unset($data['reviewers']);
            $assignment = AppraisalAssignment::query()->create([...$data, 'assigned_by' => $user->id, 'status' => 'pending']);
            foreach ($reviewers as $reviewer) {
                $assignment->reviewers()->create([...$reviewer, 'status' => 'pending']);
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
            $expected = $assignment->template->items->pluck('id')->sort()->values()->all();
            $received = collect($data['scores'])->pluck('appraisal_template_item_id')->sort()->values()->all();

            if ($expected !== $received) {
                throw ValidationException::withMessages(['scores' => 'Submit one score for every KPI in the template.']);
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

    private function calculateResult(AppraisalAssignment $assignment): AppraisalResult
    {
        $assignment->load(['template.ratingScale.options', 'template.items', 'reviewers.review.scores']);
        $itemWeights = $assignment->template->items->pluck('weight', 'id');
        $finalScore = 0;

        foreach ($assignment->reviewers as $reviewer) {
            $reviewScore = 0;
            foreach ($reviewer->review->scores as $score) {
                $reviewScore += intdiv($score->score_basis_points * $itemWeights[$score->appraisal_template_item_id], 100);
            }
            $finalScore += intdiv($reviewScore * $reviewer->weight, 100);
        }

        $grade = $assignment->template->ratingScale->options
            ->first(fn ($option) => $finalScore >= $option->min_score_basis_points && $finalScore <= $option->max_score_basis_points)?->label
            ?? 'Unrated';

        $result = $assignment->result()->create([
            'final_score_basis_points' => $finalScore,
            'grade' => $grade,
            'status' => 'final',
            'finalised_at' => now(),
        ]);
        $assignment->update(['status' => 'completed']);

        if ($finalScore >= 8500) {
            $result->outcomes()->create(['type' => 'recognition', 'notes' => 'High-performance recognition recommended.']);
        } elseif ($finalScore < 6000) {
            $result->outcomes()->create(['type' => 'improvement_plan', 'notes' => 'Performance improvement plan recommended.']);
            $result->outcomes()->create(['type' => 'training', 'notes' => 'Targeted development intervention recommended.']);
        }

        return $result;
    }

    private function relations(): array
    {
        return ['cycle', 'template.ratingScale.options', 'template.items.kpi.category', 'employee', 'reviewers.user', 'reviewers.review.scores', 'result.outcomes'];
    }
}
