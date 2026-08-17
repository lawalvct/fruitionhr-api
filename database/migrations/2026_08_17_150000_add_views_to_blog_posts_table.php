<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blog_posts', function (Blueprint $table): void {
            $table->unsignedBigInteger('views')->default(0)->after('published_at');

            // Lets the admin list sort by popularity without a filesort.
            $table->index(['status', 'views'], 'blog_posts_status_views_idx');
        });
    }

    public function down(): void
    {
        Schema::table('blog_posts', function (Blueprint $table): void {
            $table->dropIndex('blog_posts_status_views_idx');
            $table->dropColumn('views');
        });
    }
};
