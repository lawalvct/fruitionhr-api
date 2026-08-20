<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->string('calculation_error_code', 100)->nullable()->after('status');
            $table->string('calculation_error_message', 500)->nullable()->after('calculation_error_code');
            $table->timestamp('calculation_failed_at')->nullable()->after('calculation_error_message');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'calculation_error_code',
                'calculation_error_message',
                'calculation_failed_at',
            ]);
        });
    }
};
