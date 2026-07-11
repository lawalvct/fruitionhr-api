<?php

namespace App\Modules\Recruitment\Models;

use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['vacancy_id', 'applicant_id', 'stage', 'source', 'cover_letter', 'applied_at', 'hired_at', 'created_by'])]
class Application extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected static string $factory = ApplicationFactory::class;
    public const STAGES = ['applied', 'shortlisted', 'interview_scheduled', 'interviewed', 'second_interview', 'assessment', 'offer', 'accepted', 'rejected', 'hired'];

    protected function casts(): array { return ['applied_at' => 'datetime', 'hired_at' => 'datetime']; }
    public function vacancy(): BelongsTo { return $this->belongsTo(Vacancy::class); }
    public function applicant(): BelongsTo { return $this->belongsTo(Applicant::class); }
    public function stageHistory(): HasMany { return $this->hasMany(ApplicationStageHistory::class); }
    public function interviews(): HasMany { return $this->hasMany(Interview::class); }
    public function assessments(): HasMany { return $this->hasMany(Assessment::class); }
    public function offers(): HasMany { return $this->hasMany(Offer::class); }
    public function latestOffer(): HasOne { return $this->hasOne(Offer::class)->latestOfMany(); }
    public function onboardingTasks(): HasMany { return $this->hasMany(OnboardingTask::class); }
}
