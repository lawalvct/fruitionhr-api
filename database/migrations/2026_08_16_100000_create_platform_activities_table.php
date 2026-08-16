<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100);
            $table->string('subject_type', 50);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label');
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->text('reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 1024)->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id'], 'platform_activity_subject_idx');
            $table->index(['action', 'created_at'], 'platform_activity_action_created_idx');
            $table->index(['actor_user_id', 'created_at'], 'platform_activity_actor_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_activities');
    }
};
