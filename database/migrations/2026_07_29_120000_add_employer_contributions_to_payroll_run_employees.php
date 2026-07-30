<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_run_employees', function (Blueprint $table) {
            $table->bigInteger('employer_contributions')->default(0)->after('nsitf');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_run_employees', function (Blueprint $table) {
            $table->dropColumn('employer_contributions');
        });
    }
};
