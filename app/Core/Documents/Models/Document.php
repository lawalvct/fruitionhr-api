<?php

namespace App\Core\Documents\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'owner_type', 'owner_id', 'document_type', 'title',
    'file_path', 'file_name', 'file_size', 'mime_type',
    'uploaded_by', 'expires_at',
])]
class Document extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected function casts(): array
    {
        return ['expires_at' => 'date'];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
