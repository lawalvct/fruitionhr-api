<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manpower_requisitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employment_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->unsignedInteger('headcount')->default(1);
            $table->date('target_start_date')->nullable();
            $table->text('reason');
            $table->string('status')->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'department_id']);
        });

        Schema::create('vacancies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('manpower_requisition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employment_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('code')->nullable();
            $table->text('description');
            $table->text('requirements')->nullable();
            $table->string('location')->nullable();
            $table->unsignedInteger('positions_available')->default(1);
            $table->date('opens_at')->nullable();
            $table->date('closes_at')->nullable();
            $table->string('status')->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status', 'closes_at']);
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('applicants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('linkedin_url')->nullable();
            $table->string('resume_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'email']);
            $table->index(['tenant_id', 'last_name', 'first_name']);
        });

        Schema::create('applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vacancy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('applicant_id')->constrained()->cascadeOnDelete();
            $table->string('stage')->default('applied');
            $table->string('source')->nullable();
            $table->text('cover_letter')->nullable();
            $table->timestamp('applied_at');
            $table->timestamp('hired_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'vacancy_id', 'applicant_id']);
            $table->index(['tenant_id', 'stage', 'applied_at']);
        });

        Schema::create('application_stage_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('from_stage')->nullable();
            $table->string('to_stage');
            $table->text('notes')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'application_id', 'created_at'], 'stage_history_tenant_application_created_idx');
        });

        Schema::create('interviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('interview');
            $table->timestamp('scheduled_at');
            $table->string('location')->nullable();
            $table->string('meeting_url')->nullable();
            $table->json('panel_user_ids')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('recommendation')->nullable();
            $table->string('status')->default('scheduled');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'scheduled_at', 'status']);
        });

        Schema::create('assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('instructions')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->unsignedTinyInteger('maximum_score')->default(100);
            $table->string('status')->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'application_id', 'status']);
        });

        Schema::create('offers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('annual_salary')->nullable();
            $table->date('start_date');
            $table->date('expires_at')->nullable();
            $table->text('terms')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'expires_at']);
        });

        Schema::create('onboarding_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('due_date')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'application_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_tasks');
        Schema::dropIfExists('offers');
        Schema::dropIfExists('assessments');
        Schema::dropIfExists('interviews');
        Schema::dropIfExists('application_stage_history');
        Schema::dropIfExists('applications');
        Schema::dropIfExists('applicants');
        Schema::dropIfExists('vacancies');
        Schema::dropIfExists('manpower_requisitions');
    }
};
