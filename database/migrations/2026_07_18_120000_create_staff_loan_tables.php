<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type');                      // advance | loan
            $table->bigInteger('principal');             // kobo — amount borrowed
            $table->unsignedSmallInteger('months');      // repayment months (advance = 1)
            $table->bigInteger('monthly_installment');   // kobo — scheduled per-run recovery
            $table->bigInteger('balance');               // kobo — outstanding (starts = principal)
            $table->string('start_period', 7);           // YYYY-MM — first period recovered from
            $table->bigInteger('next_deduction_override')->nullable(); // kobo — one-time override for the coming run
            $table->string('status')->default('draft');  // draft | pending | active | rejected | closed
            $table->string('reason')->nullable();
            $table->timestamp('disbursed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'status']);
            $table->index(['tenant_id', 'status', 'start_period'], 'loan_tenant_status_start_idx');
        });

        Schema::create('loan_repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_loan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->nullable()->constrained('payroll_runs')->nullOnDelete();
            $table->string('period', 7);
            $table->bigInteger('amount');        // kobo recovered this run
            $table->bigInteger('balance_after'); // kobo outstanding after this recovery
            $table->timestamps();

            $table->index(['tenant_id', 'staff_loan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_repayments');
        Schema::dropIfExists('staff_loans');
    }
};
