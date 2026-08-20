<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_formula_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_component_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 20); // draft | published
            $table->json('definition');
            $table->string('summary', 500);
            $table->char('checksum', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['salary_component_id', 'version'], 'salary_formula_component_version_unique');
            $table->index(['tenant_id', 'status']);
        });

        Schema::table('salary_structure_components', function (Blueprint $table): void {
            $table->foreignId('formula_revision_id')
                ->nullable()
                ->after('salary_component_id')
                ->constrained('salary_formula_revisions')
                ->restrictOnDelete();
        });

        Schema::table('employee_salary_component_overrides', function (Blueprint $table): void {
            $table->foreignId('formula_revision_id')
                ->nullable()
                ->after('salary_component_id')
                ->constrained('salary_formula_revisions')
                ->restrictOnDelete();
        });

        Schema::table('employee_salaries', function (Blueprint $table): void {
            $table->boolean('uses_advanced_formula')->default(false)->after('basic_salary');
            $table->json('definition_snapshot')->nullable()->after('uses_advanced_formula');
        });
    }

    public function down(): void
    {
        Schema::table('employee_salaries', function (Blueprint $table): void {
            $table->dropColumn(['uses_advanced_formula', 'definition_snapshot']);
        });

        Schema::table('employee_salary_component_overrides', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('formula_revision_id');
        });

        Schema::table('salary_structure_components', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('formula_revision_id');
        });

        Schema::dropIfExists('salary_formula_revisions');
    }
};
