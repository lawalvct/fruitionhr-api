<?php

namespace App\Modules\Content\Controllers;

use App\Modules\Content\Models\BlogPost;
use App\Modules\Content\Resources\PublicBlogPostResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Unauthenticated marketing endpoints. Only ever reads through the `visible`
 * scope, so drafts and future-dated posts can never leak.
 */
class PublicBlogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $posts = BlogPost::query()
            ->visible()
            ->with('author:id,name')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(min((int) $request->integer('per_page', 12), 50))
            ->appends($request->query());

        return PublicBlogPostResource::collection($posts);
    }

    public function show(string $slug): PublicBlogPostResource
    {
        $post = BlogPost::query()
            ->visible()
            ->with('author:id,name')
            ->where('slug', $slug)
            ->firstOrFail();

        $post->recordView();

        return new PublicBlogPostResource($post, withBody: true);
    }
}
