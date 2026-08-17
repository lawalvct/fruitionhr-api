<?php

use App\Support\Authorization\PlatformAbilities;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Named jobs inside FruitionHR itself — "Support agent", "Content editor" —
 * each carrying a set of admin abilities.
 *
 * Deliberately not Spatie: those tables are keyed on tenant_id and describe
 * roles inside a customer's company. A platform administrator has no tenant,
 * and the two vocabularies must never be confused with one another.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->json('abilities');
            // System roles are structural: Owner must always exist, and must
            // always hold every ability, or nobody can hand out access again.
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table): void {
            // restrictOnDelete: a role still assigned to somebody cannot be
            // deleted out from under them, leaving an admin with no access.
            $table->foreignId('platform_role_id')
                ->nullable()
                ->after('is_super_admin')
                ->constrained('platform_roles')
                ->restrictOnDelete();
        });

        $now = now();
        DB::table('platform_roles')->insert([
            [
                'name' => 'Owner',
                'slug' => 'owner',
                'description' => 'Full run of the platform, including adding administrators and setting what they can reach.',
                'abilities' => json_encode(PlatformAbilities::all()),
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Support agent',
                'slug' => 'support-agent',
                'description' => 'Works the support queue and can look up users to help them.',
                'abilities' => json_encode([PlatformAbilities::SUPPORT, PlatformAbilities::USERS]),
                'is_system' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Content editor',
                'slug' => 'content-editor',
                'description' => 'Writes and publishes posts on the marketing blog.',
                'abilities' => json_encode([PlatformAbilities::BLOG]),
                'is_system' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // Everyone who already had the run of the platform keeps it.
        DB::table('users')->where('is_super_admin', true)->update([
            'platform_role_id' => DB::table('platform_roles')->where('slug', 'owner')->value('id'),
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['platform_role_id']);
            $table->dropColumn('platform_role_id');
        });

        Schema::dropIfExists('platform_roles');
    }
};
