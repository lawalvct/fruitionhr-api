<?php

namespace App\Modules\Recruitment\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['application_id', 'type', 'scheduled_at', 'location', 'meeting_url', 'panel_user_ids', 'notes', 'score', 'recommendation', 'status', 'created_by'])]
class Interview extends Model
{
    use BelongsToTenant;

    protected function casts(): array { return ['scheduled_at' => 'datetime', 'panel_user_ids' => 'array', 'score' => 'integer']; }
    public function application(): BelongsTo { return $this->belongsTo(Application::class); }
}
