<?php

namespace App\Modules\Payroll\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'salary_component_id', 'version', 'status', 'definition', 'summary',
    'checksum', 'created_by', 'published_by', 'published_at',
])]
class SalaryFormulaRevision extends Model
{
    use BelongsToTenant;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected static function booted(): void
    {
        static::updating(function (SalaryFormulaRevision $revision): void {
            if ($revision->getOriginal('status') === self::STATUS_PUBLISHED) {
                throw new LogicException('Published salary formula revisions are immutable.');
            }
        });

        static::deleting(function (SalaryFormulaRevision $revision): void {
            if ($revision->status === self::STATUS_PUBLISHED) {
                throw new LogicException('Published salary formula revisions cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'version' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }
}
