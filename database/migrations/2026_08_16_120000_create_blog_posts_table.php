<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Marketing content owned by FruitionHR itself, not by a tenant, so
        // this table deliberately has no tenant_id and no BelongsToTenant.
        Schema::create('blog_posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('excerpt')->nullable();
            $table->longText('body'); // sanitised HTML from the admin editor
            $table->string('cover_image_url', 2048)->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Drives the public listing: published posts, newest first.
            $table->index(['status', 'published_at'], 'blog_posts_status_published_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_posts');
    }
};
