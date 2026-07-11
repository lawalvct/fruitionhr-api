<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('states', function (Blueprint $table) {
            $table->dropUnique(['country_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('states', function (Blueprint $table) {
            $table->unique(['country_id', 'name']);
        });
    }
};
