<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('employees')
            ->whereNull('country')
            ->where('state', 'Lagos')
            ->update(['country' => 'Nigeria']);
    }

    public function down(): void
    {
        DB::table('employees')
            ->where('country', 'Nigeria')
            ->where('state', 'Lagos')
            ->update(['country' => null]);
    }
};
