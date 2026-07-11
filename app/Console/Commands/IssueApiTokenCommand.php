<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Mints a Sanctum personal access token for a user, for testing the API via
 * the /docs "Try It Out" panel or any HTTP client. The auth:sanctum guard
 * accepts this bearer token exactly like the SPA session cookie.
 *
 *   php artisan api:token owner@zenith.test
 */
class IssueApiTokenCommand extends Command
{
    protected $signature = 'api:token {email : The user email} {--name=api-docs : Token name}';

    protected $description = 'Issue a Sanctum API token for a user (for API testing)';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error("No user found with email [{$this->argument('email')}].");

            return self::FAILURE;
        }

        $token = $user->createToken($this->option('name'))->plainTextToken;

        $this->newLine();
        $this->info("Token for {$user->email} (tenant: ".($user->tenant_id ?? 'super-admin')."):");
        $this->line($token);
        $this->newLine();
        $this->comment('Use it as a header:  Authorization: Bearer '.$token);
        $this->comment('In the /docs page, paste it into the "Authorization" / bearer field.');

        return self::SUCCESS;
    }
}
