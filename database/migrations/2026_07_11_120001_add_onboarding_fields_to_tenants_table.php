<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('onboarding_status')->default('not_started')->after('settings');
            $table->unsignedTinyInteger('onboarding_step')->default(1)->after('onboarding_status');
            $table->json('onboarding_data')->nullable()->after('onboarding_step');
            $table->timestamp('onboarding_completed_at')->nullable()->after('onboarding_data');
            $table->timestamp('onboarding_skipped_at')->nullable()->after('onboarding_completed_at');
            $table->unsignedSmallInteger('onboarding_version')->default(1)->after('onboarding_skipped_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'onboarding_status',
                'onboarding_step',
                'onboarding_data',
                'onboarding_completed_at',
                'onboarding_skipped_at',
                'onboarding_version',
            ]);
        });
    }
};
