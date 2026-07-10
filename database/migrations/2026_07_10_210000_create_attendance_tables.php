<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedInteger('grace_minutes')->default(0);
            // ISO weekday numbers that are working days: [1..5] = Mon–Fri
            $table->json('working_days');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_current')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'is_current']);
        });

        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->time('clock_in')->nullable();
            $table->time('clock_out')->nullable();
            $table->string('source')->default('manual'); // manual | import | self
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One log per employee per day
            $table->unique(['tenant_id', 'employee_id', 'date']);
            $table->index(['tenant_id', 'date']);
        });

        Schema::create('attendance_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7); // YYYY-MM
            $table->unsignedInteger('working_days')->default(0);
            $table->unsignedInteger('days_present')->default(0);
            $table->unsignedInteger('days_late')->default(0);
            $table->unsignedInteger('days_absent')->default(0);
            $table->unsignedInteger('days_on_leave')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->string('status')->default('open'); // open | finalized
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_id', 'period']);
            $table->index(['tenant_id', 'period', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_summaries');
        Schema::dropIfExists('attendance_logs');
        Schema::dropIfExists('shift_assignments');
        Schema::dropIfExists('shifts');
    }
};
