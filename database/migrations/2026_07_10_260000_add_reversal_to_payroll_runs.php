<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            // A reversal run mirrors a locked run with negated figures.
            $table->boolean('is_reversal')->default(false)->after('status');
            $table->foreignId('reversed_of_run_id')->nullable()->after('is_reversal')
                ->constrained('payroll_runs')->nullOnDelete();
            $table->text('reversal_reason')->nullable()->after('reversed_of_run_id');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversed_of_run_id');
            $table->dropColumn(['is_reversal', 'reversal_reason']);
        });
    }
};
