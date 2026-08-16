<?php

namespace App\Modules\Content\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

/**
 * Image uploads for blog posts.
 *
 * Unlike tenant logos and avatars (private, streamed through PHP on the
 * `local` disk), these are public marketing assets loaded by anonymous
 * visitors on a different origin, so they live on the `public` disk and are
 * served straight off the symlink at APP_URL/storage.
 */
class BlogMediaController extends Controller
{
    public const DISK = 'public';

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ]);

        // store() names the file from a hash, so a hostile original filename
        // never reaches the filesystem.
        $path = $validated['file']->store('blog/covers/'.now()->format('Y/m'), self::DISK);

        return response()->json([
            'data' => [
                'path' => $path,
                'url' => Storage::disk(self::DISK)->url($path),
            ],
        ], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'path' => ['required', 'string', 'max:2048'],
        ]);

        // Confine deletions to our own upload tree.
        abort_unless(str_starts_with($validated['path'], 'blog/covers/'), 422);

        Storage::disk(self::DISK)->delete($validated['path']);

        return response()->json(['message' => 'Image removed.']);
    }
}
