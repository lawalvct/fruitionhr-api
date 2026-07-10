<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('employee_number');
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('official_email')->nullable();
            $table->string('personal_email')->nullable();
            $table->string('phone')->nullable();
            $table->string('gender')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('marital_status')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('employment_status')->default('active');
            $table->date('hired_at');
            $table->date('exited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'employee_number'], 'employees_tenant_number_unique');
            $table->index(['tenant_id', 'employment_status'], 'employees_tenant_status_idx');
            $table->index(['tenant_id', 'official_email'], 'employees_tenant_email_idx');
        });

        Schema::create('employee_employment_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('job_grade_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employment_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supervisor_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_current')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'employee_id', 'is_current'], 'emp_records_tenant_employee_current_idx');
            $table->index(['tenant_id', 'department_id'], 'emp_records_tenant_department_idx');
            $table->index(['tenant_id', 'position_id'], 'emp_records_tenant_position_idx');
        });

        Schema::create('employee_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('name');
            $table->string('relationship')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'employee_id', 'type'], 'emp_contacts_tenant_employee_type_idx');
        });

        Schema::create('employee_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('bank_name');
            $table->string('bank_code')->nullable();
            $table->string('account_number');
            $table->string('account_name');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'employee_id', 'is_primary'], 'emp_bank_tenant_employee_primary_idx');
        });

        Schema::create('employee_statutory_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('tax_id')->nullable();
            $table->string('pension_pin')->nullable();
            $table->string('pension_fund_administrator')->nullable();
            $table->string('nhf_number')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'employee_id'], 'emp_statutory_tenant_employee_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_statutory_details');
        Schema::dropIfExists('employee_bank_accounts');
        Schema::dropIfExists('employee_contacts');
        Schema::dropIfExists('employee_employment_records');
        Schema::dropIfExists('employees');
    }
};
