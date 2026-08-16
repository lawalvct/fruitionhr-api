<?php

namespace App\Modules\Content\Resources;

use App\Modules\Content\Models\BlogPost;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Visitor-facing projection. Deliberately narrower than BlogPostResource —
 * no draft state, no internal ids, and the body only on the detail route.
 *
 * @mixin BlogPost
 */
class PublicBlogPostResource extends JsonResource
{
    public function __construct($resource, private readonly bool $withBody = false)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return array_filter([
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'body' => $this->withBody ? $this->body : null,
            'cover_image_url' => $this->coverImageUrl(),
            'published_at' => $this->published_at?->toIso8601String(),
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'author_name' => $this->whenLoaded('author', fn () => $this->author?->name),
        ], static fn ($value) => $value !== null);
    }
}
