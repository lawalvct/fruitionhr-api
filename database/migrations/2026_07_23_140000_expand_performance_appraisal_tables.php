<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expands the performance module to the client appraisal build spec:
 * KPI descriptors + mandatory flags, template passing floors, cycle appraisal
 * types with self-review/calibration toggles, result approval workflow
 * (calibration → HR approval → acknowledgment → appeal), calibration audit
 * trail, appeals, and performance improvement plans with milestones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performance_kpis', function (Blueprint $table): void {
            $table->string('department')->nullable()->after('name');
            $table->string('type')->default('qualitative')->after('department'); // qualitative | quantitative
            $table->text('descriptor_low')->nullable()->after('target_description');
            $table->text('descriptor_mid')->nullable()->after('descriptor_low');
            $table->text('descriptor_high')->nullable()->after('descriptor_mid');
            $table->boolean('is_mandatory')->default(true)->after('descriptor_high');
        });

        Schema::table('appraisal_templates', function (Blueprint $table): void {
            $table->string('department')->nullable()->after('name');
            $table->string('target_role')->nullable()->after('department');
            $table->unsignedInteger('min_passing_basis_points')->nullable()->after('target_role');
        });

        Schema::table('appraisal_template_items', function (Blueprint $table): void {
            $table->boolean('is_mandatory')->default(true)->after('weight');
        });

        Schema::table('appraisal_cycles', function (Blueprint $table): void {
            $table->string('appraisal_type')->default('annual')->after('name');
            $table->boolean('self_review_enabled')->default(true)->after('status');
            $table->boolean('calibration_enabled')->default(false)->after('self_review_enabled');
            $table->unsignedTinyInteger('appeal_window_days')->default(7)->after('calibration_enabled');
        });

        Schema::table('appraisal_results', function (Blueprint $table): void {
            // Workflow: pending_calibration → pending_approval → approved →
            // acknowledged | appealed → appeal_resolved. 'rejected' loops back
            // via return-for-revision. Legacy rows keep 'final'.
            $table->unsignedInteger('raw_score_basis_points')->nullable()->after('final_score_basis_points');
            $table->unsignedInteger('calibrated_score_basis_points')->nullable()->after('raw_score_basis_points');
            $table->foreignId('approved_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->timestamp('acknowledged_at')->nullable()->after('approved_at');
            $table->text('rejected_reason')->nullable()->after('acknowledged_at');
        });

        Schema::create('calibration_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appraisal_result_id')->constrained()->cascadeOnDelete();
            $table->foreignId('adjusted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('old_score_basis_points');
            $table->unsignedInteger('new_score_basis_points');
            $table->text('justification');
            $table->timestamps();
            $table->index(['tenant_id', 'appraisal_result_id'], 'calibrations_tenant_result_idx');
        });

        Schema::create('appraisal_appeals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appraisal_result_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->text('reason');
            $table->string('status')->default('open'); // open | upheld | rejected
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status'], 'appeals_tenant_status_idx');
        });

        Schema::create('performance_improvement_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appraisal_result_id')->nullable()->constrained()->nullOnDelete();
            $table->text('reason');
            $table->string('status')->default('draft'); // draft | active | closed_successful | closed_unsuccessful
            $table->date('starts_at');
            $table->date('ends_at');
            $table->text('outcome_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'employee_id', 'status'], 'pips_tenant_employee_status_idx');
        });

        Schema::create('pip_milestones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('performance_improvement_plan_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->date('due_at');
            $table->string('status')->default('pending'); // pending | completed | missed
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'performance_improvement_plan_id', 'due_at'], 'pip_milestones_tenant_pip_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pip_milestones');
        Schema::dropIfExists('performance_improvement_plans');
        Schema::dropIfExists('appraisal_appeals');
        Schema::dropIfExists('calibration_adjustments');

        Schema::table('appraisal_results', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['raw_score_basis_points', 'calibrated_score_basis_points', 'approved_at', 'acknowledged_at', 'rejected_reason']);
        });

        Schema::table('appraisal_cycles', function (Blueprint $table): void {
            $table->dropColumn(['appraisal_type', 'self_review_enabled', 'calibration_enabled', 'appeal_window_days']);
        });

        Schema::table('appraisal_template_items', function (Blueprint $table): void {
            $table->dropColumn('is_mandatory');
        });

        Schema::table('appraisal_templates', function (Blueprint $table): void {
            $table->dropColumn(['department', 'target_role', 'min_passing_basis_points']);
        });

        Schema::table('performance_kpis', function (Blueprint $table): void {
            $table->dropColumn(['department', 'type', 'descriptor_low', 'descriptor_mid', 'descriptor_high', 'is_mandatory']);
        });
    }
};
