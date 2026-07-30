<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_salary_component_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_salary_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_component_id')->constrained()->restrictOnDelete();
            $table->string('mode'); // override | additional | excluded
            $table->bigInteger('amount')->nullable(); // fixed kobo value
            $table->unsignedInteger('percent')->nullable(); // optional percent of basic
            $table->timestamps();

            $table->unique(['employee_salary_id', 'salary_component_id'], 'employee_salary_component_unique');
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_salary_component_overrides');
    }
};
