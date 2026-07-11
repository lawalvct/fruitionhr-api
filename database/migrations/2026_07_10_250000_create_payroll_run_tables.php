<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7); // YYYY-MM
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('status')->default('open'); // open | closed
            $table->timestamps();

            $table->unique(['tenant_id', 'period']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pay_period_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7);
            // draft | calculating | review | pending_approval | approved | locked | paid | reversed
            $table->string('status')->default('draft');
            $table->unsignedInteger('employee_count')->default(0);
            $table->bigInteger('total_gross')->default(0);          // kobo
            $table->bigInteger('total_statutory')->default(0);
            $table->bigInteger('total_deductions')->default(0);
            $table->bigInteger('total_net')->default(0);
            $table->bigInteger('total_employer_cost')->default(0);  // pension employer + nsitf
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'period', 'status']);
        });

        Schema::create('payroll_run_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->json('snapshot'); // reproducible: basic, structure, attendance, config
            $table->bigInteger('gross')->default(0);
            $table->bigInteger('taxable_pay')->default(0);
            $table->bigInteger('pensionable_pay')->default(0);
            $table->bigInteger('total_statutory')->default(0);   // employee: paye+pension+nhf
            $table->bigInteger('total_deductions')->default(0);  // statutory + other + absence
            $table->bigInteger('net')->default(0);
            $table->bigInteger('pension_employer')->default(0);
            $table->bigInteger('nsitf')->default(0);
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id'], 'run_employee_unique');
            $table->index('tenant_id');
        });

        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_run_employee_id')->constrained()->cascadeOnDelete();
            $table->string('category'); // earning | statutory | deduction | employer
            $table->string('code');
            $table->string('name');
            $table->bigInteger('amount'); // kobo (magnitude; category gives sign meaning)
            $table->timestamps();

            $table->index(['tenant_id', 'payroll_run_employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_items');
        Schema::dropIfExists('payroll_run_employees');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('pay_periods');
    }
};
