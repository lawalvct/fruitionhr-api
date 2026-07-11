<?php

namespace App\Modules\Recruitment\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['application_id', 'title', 'instructions', 'due_at', 'score', 'maximum_score', 'status', 'created_by'])]
class Assessment extends Model
{
    use BelongsToTenant;

    protected function casts(): array { return ['due_at' => 'datetime', 'score' => 'integer', 'maximum_score' => 'integer']; }
    public function application(): BelongsTo { return $this->belongsTo(Application::class); }
}
