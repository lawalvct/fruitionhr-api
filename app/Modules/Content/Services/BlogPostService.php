<?php

namespace App\Modules\Content\Services;

use App\Models\User;
use App\Modules\Content\Controllers\BlogMediaController;
use App\Modules\Content\Models\BlogPost;
use App\Modules\Content\Support\BlogHtmlSanitizer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BlogPostService
{
    public function __construct(private readonly BlogHtmlSanitizer $sanitizer) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<BlogPost>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = BlogPost::query()->with('author:id,name,email');

        if (($filters['search'] ?? null) !== null) {
            $search = trim((string) $filters['search']);
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if (($filters['status'] ?? null) !== null) {
            $query->where('status', $filters['status']);
        }

        [$column, $direction] = $this->sort((string) ($filters['sort'] ?? '-created_at'));

        return $query
            ->orderBy($column, $direction)
            ->orderBy('id', 'desc')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->appends($filters);
    }

    public function find(int $postId): BlogPost
    {
        return BlogPost::query()->with('author:id,name,email')->findOrFail($postId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $author): BlogPost
    {
        return DB::transaction(function () use ($data, $author): BlogPost {
            $body = $this->sanitizer->sanitize((string) ($data['body'] ?? ''));
            $this->guardAgainstEmptyBody($body);

            $post = new BlogPost;
            $post->fill([
                'title' => $data['title'],
                'slug' => $this->uniqueSlug($data['slug'] ?? $data['title']),
                'excerpt' => $this->resolveExcerpt($data['excerpt'] ?? null, $body),
                'body' => $body,
                'cover_image_url' => $data['cover_image_url'] ?? null,
                'cover_image_path' => $data['cover_image_path'] ?? null,
                'seo_title' => $data['seo_title'] ?? null,
                'seo_description' => $data['seo_description'] ?? null,
                'author_user_id' => $author->id,
            ]);

            $this->applyStatus($post, (string) ($data['status'] ?? BlogPost::STATUS_DRAFT), $data['published_at'] ?? null);
            $post->save();

            return $post->refresh()->load('author:id,name,email');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $postId, array $data): BlogPost
    {
        return DB::transaction(function () use ($postId, $data): BlogPost {
            $post = BlogPost::query()->lockForUpdate()->findOrFail($postId);

            if (array_key_exists('body', $data)) {
                $body = $this->sanitizer->sanitize((string) $data['body']);
                $this->guardAgainstEmptyBody($body);
                $post->body = $body;
            }

            if (array_key_exists('cover_image_path', $data)) {
                // Replacing or clearing an uploaded cover orphans the old file.
                $this->deleteUpload($post->cover_image_path, $data['cover_image_path']);
                $post->cover_image_path = $data['cover_image_path'];
            }

            foreach (['title', 'cover_image_url', 'seo_title', 'seo_description'] as $field) {
                if (array_key_exists($field, $data)) {
                    $post->{$field} = $data[$field];
                }
            }

            // An explicit slug wins; otherwise the existing one is kept so
            // published URLs never shift under a reader's feet.
            if (($data['slug'] ?? null) !== null) {
                $post->slug = $this->uniqueSlug((string) $data['slug'], $post->id);
            }

            if (array_key_exists('excerpt', $data)) {
                $post->excerpt = $this->resolveExcerpt($data['excerpt'], $post->body);
            }

            if (array_key_exists('status', $data)) {
                $this->applyStatus($post, (string) $data['status'], $data['published_at'] ?? null);
            }

            $post->save();

            return $post->refresh()->load('author:id,name,email');
        });
    }

    public function delete(int $postId): BlogPost
    {
        $post = BlogPost::query()->findOrFail($postId);
        $this->deleteUpload($post->cover_image_path);
        $post->delete();

        return $post;
    }

    /**
     * Drop a previously uploaded cover unless it is still in use. Only touches
     * our own upload tree, never an externally hosted image.
     */
    private function deleteUpload(?string $path, ?string $replacement = null): void
    {
        if ($path === null || $path === '' || $path === $replacement) {
            return;
        }

        if (! str_starts_with($path, 'blog/covers/')) {
            return;
        }

        Storage::disk(BlogMediaController::DISK)->delete($path);
    }

    /**
     * Publishing stamps published_at once and then leaves it alone, so the
     * original publication date survives later edits and re-publishing.
     */
    private function applyStatus(BlogPost $post, string $status, mixed $publishedAt): void
    {
        if ($status === BlogPost::STATUS_PUBLISHED) {
            $post->status = BlogPost::STATUS_PUBLISHED;
            $post->published_at = $publishedAt !== null
                ? Carbon::parse((string) $publishedAt)
                : ($post->published_at ?? now());

            return;
        }

        $post->status = BlogPost::STATUS_DRAFT;
    }

    private function guardAgainstEmptyBody(string $body): void
    {
        if (BlogHtmlSanitizer::toPlainText($body) === '') {
            throw ValidationException::withMessages([
                'body' => 'The post body is empty once unsafe formatting is removed.',
            ]);
        }
    }

    private function resolveExcerpt(?string $excerpt, string $body): string
    {
        $excerpt = trim((string) $excerpt);

        return $excerpt !== ''
            ? $excerpt
            : Str::limit(BlogHtmlSanitizer::toPlainText($body), 180);
    }

    private function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'post';
        $slug = $base;
        $suffix = 2;

        while (BlogPost::query()
            ->withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /** @return array{string, 'asc'|'desc'} */
    private function sort(string $sort): array
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $allowed = ['created_at', 'published_at', 'title', 'status'];

        return [in_array($column, $allowed, true) ? $column : 'created_at', $direction];
    }
}
