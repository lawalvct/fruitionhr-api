<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blog_posts', function (Blueprint $table): void {
            // Set when the cover was uploaded to our own storage, so the file
            // can be deleted when it is replaced or the post is removed.
            // cover_image_url stays for externally hosted images.
            $table->string('cover_image_path')->nullable()->after('cover_image_url');
        });
    }

    public function down(): void
    {
        Schema::table('blog_posts', function (Blueprint $table): void {
            $table->dropColumn('cover_image_path');
        });
    }
};
