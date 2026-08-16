<?php

namespace App\Modules\Content\Controllers;

use App\Models\User;
use App\Modules\Admin\Services\PlatformActivityService;
use App\Modules\Content\Requests\ListBlogPostsRequest;
use App\Modules\Content\Requests\StoreBlogPostRequest;
use App\Modules\Content\Requests\UpdateBlogPostRequest;
use App\Modules\Content\Resources\BlogPostResource;
use App\Modules\Content\Services\BlogPostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Super-admin blog management. Mounted under /api/admin/v1, so the
 * super-admin middleware already gates every action here.
 */
class BlogPostController extends Controller
{
    public function index(
        ListBlogPostsRequest $request,
        BlogPostService $service,
    ): AnonymousResourceCollection {
        return BlogPostResource::collection($service->paginate($request->validated()));
    }

    public function show(int $post, BlogPostService $service): BlogPostResource
    {
        return new BlogPostResource($service->find($post));
    }

    public function store(
        StoreBlogPostRequest $request,
        BlogPostService $service,
        PlatformActivityService $activity,
    ): JsonResponse {
        /** @var User $author */
        $author = $request->user();
        $post = $service->create($request->validated(), $author);

        $activity->record(
            request: $request,
            action: 'blog_post.created',
            subjectType: 'blog_post',
            subjectId: $post->id,
            subjectLabel: $post->title,
            before: [],
            after: ['status' => $post->status, 'slug' => $post->slug],
        );

        return (new BlogPostResource($post))
            ->additional(['message' => 'Post saved.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        UpdateBlogPostRequest $request,
        int $post,
        BlogPostService $service,
        PlatformActivityService $activity,
    ): BlogPostResource {
        $before = $service->find($post);
        $beforeSnapshot = ['status' => $before->status, 'slug' => $before->slug];

        $updated = $service->update($post, $request->validated());

        $activity->record(
            request: $request,
            action: 'blog_post.updated',
            subjectType: 'blog_post',
            subjectId: $updated->id,
            subjectLabel: $updated->title,
            before: $beforeSnapshot,
            after: ['status' => $updated->status, 'slug' => $updated->slug],
        );

        return new BlogPostResource($updated);
    }

    public function destroy(
        int $post,
        BlogPostService $service,
        PlatformActivityService $activity,
    ): JsonResponse {
        $deleted = $service->delete($post);

        $activity->record(
            request: request(),
            action: 'blog_post.deleted',
            subjectType: 'blog_post',
            subjectId: $deleted->id,
            subjectLabel: $deleted->title,
            before: ['status' => $deleted->status, 'slug' => $deleted->slug],
            after: [],
        );

        return response()->json(['message' => 'Post deleted.']);
    }
}
