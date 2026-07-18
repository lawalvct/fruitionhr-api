<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7);                 // YYYY-MM — the month it belongs to
            $table->date('work_date')->nullable();       // when the overtime was worked (Sat/Sun/etc.)

            $table->string('source')->default('manual'); // manual | attendance
            $table->string('pay_type')->default('hourly'); // hourly | fixed

            $table->decimal('hours', 6, 2)->nullable();  // for hourly
            $table->decimal('multiplier', 3, 2)->default(1.00); // 1.00 | 1.50 | 2.00
            $table->bigInteger('hourly_rate')->nullable(); // kobo/hour snapshot (hourly)
            $table->bigInteger('amount')->default(0);    // kobo — final payable

            $table->string('disbursement_mode')->default('in_payroll'); // in_payroll | off_cycle
            $table->string('status')->default('draft');  // draft | pending | approved | rejected | paid
            $table->string('reason')->nullable();

            $table->foreignId('attendance_summary_id')->nullable()->constrained('attendance_summaries')->nullOnDelete();
            $table->foreignId('payroll_run_id')->nullable()->constrained('payroll_runs')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'period']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'period', 'disbursement_mode', 'status'], 'ot_tenant_period_mode_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_payments');
    }
};
