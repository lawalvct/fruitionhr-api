<?php

namespace App\Modules\Recruitment\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['application_id', 'annual_salary', 'start_date', 'expires_at', 'terms', 'status', 'sent_at', 'responded_at', 'created_by'])]
class Offer extends Model
{
    use BelongsToTenant;

    protected function casts(): array { return ['annual_salary' => 'integer', 'start_date' => 'date:Y-m-d', 'expires_at' => 'date:Y-m-d', 'sent_at' => 'datetime', 'responded_at' => 'datetime']; }
    public function application(): BelongsTo { return $this->belongsTo(Application::class); }
}
