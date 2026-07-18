<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vacancies', function (Blueprint $table): void {
            $table->string('visibility')->default('private')->after('status');
            $table->string('public_slug')->nullable()->unique()->after('code');
            $table->index(
                ['visibility', 'status', 'opens_at', 'closes_at'],
                'vacancies_public_availability_idx'
            );
        });

        Schema::table('applicants', function (Blueprint $table): void {
            $table->string('resume_original_name')->nullable()->after('resume_path');
        });
    }

    public function down(): void
    {
        Schema::table('applicants', function (Blueprint $table): void {
            $table->dropColumn('resume_original_name');
        });

        Schema::table('vacancies', function (Blueprint $table): void {
            $table->dropIndex('vacancies_public_availability_idx');
            $table->dropUnique(['public_slug']);
            $table->dropColumn(['visibility', 'public_slug']);
        });
    }
};
