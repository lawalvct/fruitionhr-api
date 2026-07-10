<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('module'); // leave | payroll | profile_update | ...
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // One active definition per module per tenant is resolved at submit
            $table->index(['tenant_id', 'module', 'is_active']);
        });

        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_definition_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('step_order');
            $table->string('step_name');
            // Any tenant user holding this role may act on the step
            $table->string('approver_role');
            $table->timestamps();

            $table->index(['tenant_id', 'workflow_definition_id', 'step_order']);
        });

        Schema::create('workflow_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_definition_id')->constrained()->cascadeOnDelete();
            $table->string('module');
            $table->morphs('record'); // record_type + record_id
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('current_step_id')->nullable()->constrained('workflow_steps');
            $table->string('status')->default('pending'); // pending|approved|rejected|returned|cancelled
            $table->timestamp('submitted_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'record_type', 'record_id']);
        });

        Schema::create('workflow_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_step_id')->constrained('workflow_steps');
            $table->foreignId('action_by')->constrained('users');
            $table->string('action'); // approve|reject|return
            $table->text('comments')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'workflow_request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_actions');
        Schema::dropIfExists('workflow_requests');
        Schema::dropIfExists('workflow_steps');
        Schema::dropIfExists('workflow_definitions');
    }
};
