<?php

namespace App\Modules\Recruitment\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['application_id', 'from_stage', 'to_stage', 'notes', 'changed_by'])]
class ApplicationStageHistory extends Model
{
    use BelongsToTenant;

    protected $table = 'application_stage_history';
    public function application(): BelongsTo { return $this->belongsTo(Application::class); }
    public function changedBy(): BelongsTo { return $this->belongsTo(User::class, 'changed_by'); }
}
