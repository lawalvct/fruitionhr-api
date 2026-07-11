<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('performance_kpis', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('performance_category_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('measurement_unit')->nullable();
            $table->string('target_description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'performance_category_id', 'is_active'], 'kpis_tenant_category_active_idx');
        });

        Schema::create('rating_scales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('rating_scale_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rating_scale_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->unsignedInteger('min_score_basis_points');
            $table->unsignedInteger('max_score_basis_points');
            $table->unsignedInteger('sort_order')->default(1);
            $table->timestamps();
            $table->index(['tenant_id', 'rating_scale_id', 'sort_order'], 'rating_options_tenant_scale_order_idx');
        });

        Schema::create('appraisal_cycles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('starts_at');
            $table->date('ends_at');
            $table->date('review_starts_at')->nullable();
            $table->date('review_ends_at')->nullable();
            $table->string('status')->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'status', 'starts_at']);
        });

        Schema::create('appraisal_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rating_scale_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('appraisal_template_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appraisal_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('performance_kpi_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weight');
            $table->timestamps();
            $table->unique(['tenant_id', 'appraisal_template_id', 'performance_kpi_id'], 'template_items_tenant_template_kpi_uq');
        });

        Schema::create('appraisal_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appraisal_cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appraisal_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->date('due_date')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'appraisal_cycle_id', 'employee_id'], 'assignments_tenant_cycle_employee_uq');
            $table->index(['tenant_id', 'status', 'due_date']);
        });

        Schema::create('appraisal_reviewers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appraisal_assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reviewer_type');
            $table->unsignedTinyInteger('weight');
            $table->string('status')->default('pending');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'appraisal_assignment_id', 'reviewer_user_id', 'reviewer_type'], 'reviewers_assignment_user_type_uq');
            $table->index(['tenant_id', 'reviewer_user_id', 'status'], 'reviewers_tenant_user_status_idx');
        });

        Schema::create('appraisal_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appraisal_reviewer_id')->constrained()->cascadeOnDelete();
            $table->text('comments')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'appraisal_reviewer_id']);
        });

        Schema::create('appraisal_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appraisal_review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appraisal_template_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('score_basis_points');
            $table->text('comments')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'appraisal_review_id', 'appraisal_template_item_id'], 'scores_tenant_review_item_uq');
        });

        Schema::create('appraisal_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appraisal_assignment_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('final_score_basis_points');
            $table->string('grade');
            $table->string('status')->default('final');
            $table->timestamp('finalised_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'appraisal_assignment_id']);
            $table->index(['tenant_id', 'grade', 'final_score_basis_points'], 'results_tenant_grade_score_idx');
        });

        Schema::create('performance_outcomes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appraisal_result_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'type']);
        });

        Schema::create('goals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('level');
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('goals')->nullOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('weight')->default(0);
            $table->bigInteger('target_value')->nullable();
            $table->bigInteger('current_value')->nullable();
            $table->string('measurement_unit')->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('status')->default('draft');
            $table->date('starts_at')->nullable();
            $table->date('due_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'level', 'status']);
            $table->index(['tenant_id', 'employee_id', 'status']);
        });

        Schema::create('goal_checkins', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('progress');
            $table->bigInteger('current_value')->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'goal_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goal_checkins');
        Schema::dropIfExists('goals');
        Schema::dropIfExists('performance_outcomes');
        Schema::dropIfExists('appraisal_results');
        Schema::dropIfExists('appraisal_scores');
        Schema::dropIfExists('appraisal_reviews');
        Schema::dropIfExists('appraisal_reviewers');
        Schema::dropIfExists('appraisal_assignments');
        Schema::dropIfExists('appraisal_template_items');
        Schema::dropIfExists('appraisal_templates');
        Schema::dropIfExists('appraisal_cycles');
        Schema::dropIfExists('rating_scale_options');
        Schema::dropIfExists('rating_scales');
        Schema::dropIfExists('performance_kpis');
        Schema::dropIfExists('performance_categories');
    }
};
