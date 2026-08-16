<?php

namespace App\Modules\Content\Models;

use App\Models\User;
use App\Modules\Content\Controllers\BlogMediaController;
use Database\Factories\BlogPostFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * A marketing blog post. Platform-owned content — deliberately not tenant
 * scoped, so it carries no BelongsToTenant trait.
 *
 * @property string $body sanitised HTML — see BlogHtmlSanitizer
 */
class BlogPost extends Model
{
    /** @use HasFactory<BlogPostFactory> */
    use HasFactory, SoftDeletes;

    protected static string $factory = BlogPostFactory::class;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'title',
        'slug',
        'excerpt',
        'body',
        'cover_image_url',
        'cover_image_path',
        'status',
        'published_at',
        'author_user_id',
        'seo_title',
        'seo_description',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /**
     * The URL to render. A locally uploaded cover wins over an external one,
     * so replacing a URL with an upload takes effect immediately.
     */
    public function coverImageUrl(): ?string
    {
        if ($this->cover_image_path !== null && $this->cover_image_path !== '') {
            return Storage::disk(
                BlogMediaController::DISK
            )->url($this->cover_image_path);
        }

        return $this->cover_image_url;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && $this->published_at !== null
            && ! $this->published_at->isFuture();
    }

    /**
     * Only posts a visitor may see. Guards against a published post with a
     * future published_at leaking early.
     *
     * @param  Builder<BlogPost>  $query
     * @return Builder<BlogPost>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }
}
