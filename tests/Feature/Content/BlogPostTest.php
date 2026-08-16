<?php

use App\Models\User;
use App\Modules\Content\Models\BlogPost;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Blog posts are platform-owned marketing content rendered to the public
 * internet, so the two things that matter most are that drafts never leak
 * and that author-supplied HTML can never carry script.
 */
function actingAsPlatformAdmin(): User
{
    $admin = User::factory()->platformAdministrator()->create();
    test()->actingAs($admin);

    return $admin;
}

test('a super admin can create a post and it starts as a draft', function (): void {
    actingAsPlatformAdmin();

    $this->postJson('/api/admin/v1/blog-posts', [
        'title' => 'Payroll in Nigeria: a practical guide',
        'body' => '<p>PAYE, pension and NHF explained.</p>',
    ])->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.is_published', false)
        ->assertJsonPath('data.slug', 'payroll-in-nigeria-a-practical-guide');
});

test('the excerpt falls back to the body when none is supplied', function (): void {
    actingAsPlatformAdmin();

    $this->postJson('/api/admin/v1/blog-posts', [
        'title' => 'Excerptless',
        'body' => '<p>Everything you need to know about pensions.</p>',
    ])->assertCreated()
        ->assertJsonPath('data.excerpt', 'Everything you need to know about pensions.');
});

test('script tags and javascript hrefs are stripped from the body', function (): void {
    actingAsPlatformAdmin();

    $response = $this->postJson('/api/admin/v1/blog-posts', [
        'title' => 'Hostile input',
        'body' => '<p>Safe copy</p>'
            .'<script>alert(document.cookie)</script>'
            .'<a href="javascript:alert(1)">tap me</a>'
            .'<img src="x" onerror="alert(1)">'
            .'<iframe src="https://evil.test"></iframe>',
    ])->assertCreated();

    $body = $response->json('data.body');

    expect($body)
        ->toContain('Safe copy')
        ->not->toContain('<script')
        ->not->toContain('javascript:')
        ->not->toContain('onerror')
        ->not->toContain('<iframe');
});

test('safe formatting and links survive sanitising', function (): void {
    actingAsPlatformAdmin();

    $response = $this->postJson('/api/admin/v1/blog-posts', [
        'title' => 'Rich formatting',
        'body' => '<h2>Heading</h2><p><strong>Bold</strong> and <em>italic</em></p>'
            .'<ul><li>One</li></ul><a href="https://fruitionhr.com">Link</a>',
    ])->assertCreated();

    $body = $response->json('data.body');

    expect($body)
        ->toContain('<h2>')
        ->toContain('<strong>')
        ->toContain('<li>')
        ->toContain('https://fruitionhr.com')
        // Outbound links must not leak window.opener.
        ->toContain('noopener');
});

test('a body that is only unsafe markup is rejected', function (): void {
    actingAsPlatformAdmin();

    $this->postJson('/api/admin/v1/blog-posts', [
        'title' => 'Nothing but script',
        'body' => '<script>alert(1)</script>',
    ])->assertUnprocessable()->assertJsonValidationErrors('body');
});

test('slugs stay unique', function (): void {
    actingAsPlatformAdmin();

    foreach (range(1, 2) as $_) {
        $this->postJson('/api/admin/v1/blog-posts', [
            'title' => 'Same title',
            'body' => '<p>Body</p>',
        ])->assertCreated();
    }

    expect(BlogPost::query()->pluck('slug')->all())->toBe(['same-title', 'same-title-2']);
});

test('publishing stamps a date and keeps it across later edits', function (): void {
    actingAsPlatformAdmin();
    $post = BlogPost::factory()->create();

    $this->putJson("/api/admin/v1/blog-posts/{$post->id}", ['status' => 'published'])
        ->assertOk()
        ->assertJsonPath('data.is_published', true);

    $publishedAt = $post->fresh()->published_at;

    $this->putJson("/api/admin/v1/blog-posts/{$post->id}", ['title' => 'Retitled'])->assertOk();

    expect($post->fresh()->published_at->toIso8601String())->toBe($publishedAt->toIso8601String());
});

test('the public feed shows published posts and hides drafts and scheduled ones', function (): void {
    BlogPost::factory()->published()->create(['title' => 'Live post']);
    BlogPost::factory()->create(['title' => 'Draft post']);
    BlogPost::factory()->scheduled()->create(['title' => 'Future post']);

    $response = $this->getJson('/api/v1/blog')->assertOk();
    $titles = collect($response->json('data'))->pluck('title');

    expect($titles)->toContain('Live post')
        ->not->toContain('Draft post')
        ->not->toContain('Future post');
});

test('a draft is not reachable by slug', function (): void {
    $draft = BlogPost::factory()->create(['slug' => 'secret-draft']);

    $this->getJson("/api/v1/blog/{$draft->slug}")->assertNotFound();
});

test('a published post is readable by slug with its body', function (): void {
    $post = BlogPost::factory()->published()->create([
        'slug' => 'live-one',
        'body' => '<p>Readable body</p>',
    ]);

    $this->getJson("/api/v1/blog/{$post->slug}")
        ->assertOk()
        ->assertJsonPath('data.slug', 'live-one')
        ->assertJsonPath('data.body', '<p>Readable body</p>');
});

test('blog management is closed to tenant users and guests', function (): void {
    $this->getJson('/api/admin/v1/blog-posts')->assertUnauthorized();

    $this->actingAs(User::factory()->create());
    $this->getJson('/api/admin/v1/blog-posts')->assertForbidden();
});

test('a cover image can be uploaded and is served from public storage', function (): void {
    // Keep the configured URL: Storage::fake() otherwise drops it and the
    // absolute-URL assertion below would pass vacuously.
    Storage::fake('public', ['url' => config('filesystems.disks.public.url')]);
    actingAsPlatformAdmin();

    $response = $this->postJson('/api/admin/v1/blog-posts/media', [
        'file' => UploadedFile::fake()->image('cover.jpg', 1200, 630),
    ])->assertCreated();

    $path = $response->json('data.path');

    expect($path)->toStartWith('blog/covers/');
    Storage::disk('public')->assertExists($path);
    // Must be absolute — the marketing site is a different origin to the API.
    expect($response->json('data.url'))->toStartWith('http');
});

test('non-images and oversized files are rejected', function (): void {
    Storage::fake('public');
    actingAsPlatformAdmin();

    $this->postJson('/api/admin/v1/blog-posts/media', [
        'file' => UploadedFile::fake()->create('payload.php', 10, 'application/x-php'),
    ])->assertUnprocessable()->assertJsonValidationErrors('file');

    $this->postJson('/api/admin/v1/blog-posts/media', [
        'file' => UploadedFile::fake()->image('huge.jpg')->size(6000),
    ])->assertUnprocessable()->assertJsonValidationErrors('file');
});

test('uploads are closed to tenant users', function (): void {
    Storage::fake('public');
    $this->actingAs(User::factory()->create());

    $this->postJson('/api/admin/v1/blog-posts/media', [
        'file' => UploadedFile::fake()->image('cover.jpg'),
    ])->assertForbidden();
});

test('an uploaded cover is used ahead of an external url and reaches the public feed', function (): void {
    Storage::fake('public');
    actingAsPlatformAdmin();

    $path = $this->postJson('/api/admin/v1/blog-posts/media', [
        'file' => UploadedFile::fake()->image('cover.jpg'),
    ])->json('data.path');

    $post = $this->postJson('/api/admin/v1/blog-posts', [
        'title' => 'Post with a local cover',
        'body' => '<p>Body</p>',
        'cover_image_url' => 'https://example.test/external.jpg',
        'cover_image_path' => $path,
        'status' => 'published',
    ])->assertCreated();

    // The upload wins over the external URL.
    expect($post->json('data.cover_image_url'))->toContain($path);

    $this->getJson('/api/v1/blog/'.$post->json('data.slug'))
        ->assertOk()
        ->assertJsonPath('data.cover_image_url', $post->json('data.cover_image_url'));
});

test('replacing a cover deletes the file it replaced', function (): void {
    Storage::fake('public');
    actingAsPlatformAdmin();

    $first = $this->postJson('/api/admin/v1/blog-posts/media', [
        'file' => UploadedFile::fake()->image('one.jpg'),
    ])->json('data.path');

    $post = $this->postJson('/api/admin/v1/blog-posts', [
        'title' => 'Swappable cover',
        'body' => '<p>Body</p>',
        'cover_image_path' => $first,
    ])->assertCreated()->json('data.id');

    $second = $this->postJson('/api/admin/v1/blog-posts/media', [
        'file' => UploadedFile::fake()->image('two.jpg'),
    ])->json('data.path');

    $this->putJson("/api/admin/v1/blog-posts/{$post}", ['cover_image_path' => $second])->assertOk();

    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);
});

test('deleting a post removes its uploaded cover', function (): void {
    Storage::fake('public');
    actingAsPlatformAdmin();

    $path = $this->postJson('/api/admin/v1/blog-posts/media', [
        'file' => UploadedFile::fake()->image('bye.jpg'),
    ])->json('data.path');

    $post = $this->postJson('/api/admin/v1/blog-posts', [
        'title' => 'Doomed',
        'body' => '<p>Body</p>',
        'cover_image_path' => $path,
    ])->assertCreated()->json('data.id');

    $this->deleteJson("/api/admin/v1/blog-posts/{$post}")->assertOk();

    Storage::disk('public')->assertMissing($path);
});

test('a cover path outside the upload tree is rejected', function (): void {
    actingAsPlatformAdmin();

    $this->postJson('/api/admin/v1/blog-posts', [
        'title' => 'Path traversal attempt',
        'body' => '<p>Body</p>',
        'cover_image_path' => '../../.env',
    ])->assertUnprocessable()->assertJsonValidationErrors('cover_image_path');
});
