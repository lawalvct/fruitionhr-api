<?php

namespace App\Modules\Recruitment\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['application_id', 'title', 'description', 'due_date', 'assigned_to', 'status', 'completed_at', 'created_by'])]
class OnboardingTask extends Model
{
    use BelongsToTenant;

    protected function casts(): array { return ['due_date' => 'date:Y-m-d', 'completed_at' => 'datetime']; }
    public function application(): BelongsTo { return $this->belongsTo(Application::class); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
}
