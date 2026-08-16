<?php

namespace App\Modules\Admin\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'actor_user_id', 'action', 'subject_type', 'subject_id', 'subject_label',
    'before_values', 'after_values', 'reason', 'ip_address', 'user_agent',
])]
class PlatformActivity extends Model
{
    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Platform activity records are append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('Platform activity records are append-only.');
        });
    }

    protected function casts(): array
    {
        return [
            'before_values' => 'array',
            'after_values' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
