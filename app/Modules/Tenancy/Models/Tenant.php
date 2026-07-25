<?php

namespace App\Modules\Tenancy\Models;

use App\Models\User;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'name', 'slug', 'email', 'phone', 'logo_path', 'status', 'settings', 'trial_ends_at',
    'onboarding_status', 'onboarding_step', 'onboarding_data',
    'onboarding_completed_at', 'onboarding_skipped_at', 'onboarding_version',
])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, SoftDeletes;

    // Modules keep models outside App\Models, so name the factory explicitly.
    protected static string $factory = TenantFactory::class;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CANCELLED = 'cancelled';

    public const ONBOARDING_NOT_STARTED = 'not_started';

    public const ONBOARDING_IN_PROGRESS = 'in_progress';

    public const ONBOARDING_COMPLETED = 'completed';

    public const ONBOARDING_SKIPPED = 'skipped';

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'trial_ends_at' => 'datetime',
            'onboarding_step' => 'integer',
            'onboarding_data' => 'array',
            'onboarding_completed_at' => 'datetime',
            'onboarding_skipped_at' => 'datetime',
            'onboarding_version' => 'integer',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
