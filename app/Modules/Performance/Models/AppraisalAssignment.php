<?php

namespace App\Modules\Performance\Models;

use App\Modules\Employee\Models\Employee;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\AppraisalAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['appraisal_cycle_id', 'appraisal_template_id', 'employee_id', 'status', 'due_date', 'assigned_by'])]
class AppraisalAssignment extends Model
{
    use BelongsToTenant, HasFactory;
    protected static string $factory = AppraisalAssignmentFactory::class;
    protected function casts(): array { return ['due_date' => 'date:Y-m-d']; }
    public function cycle(): BelongsTo { return $this->belongsTo(AppraisalCycle::class, 'appraisal_cycle_id'); }
    public function template(): BelongsTo { return $this->belongsTo(AppraisalTemplate::class, 'appraisal_template_id'); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function reviewers(): HasMany { return $this->hasMany(AppraisalReviewer::class); }
    public function result(): HasOne { return $this->hasOne(AppraisalResult::class); }
}
