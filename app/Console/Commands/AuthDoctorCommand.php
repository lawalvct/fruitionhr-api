<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/**
 * Explains why SPA cookie authentication is or is not working.
 *
 * "Unauthenticated" from a deployed SPA is nearly always configuration rather
 * than code, and the settings that decide it live in four different files. This
 * gathers them, and — given the origin the browser actually uses — answers the
 * only question that matters: would Sanctum treat that request as stateful?
 *
 *   php artisan auth:doctor --origin=https://app.fruitionhr.com
 */
class AuthDoctorCommand extends Command
{
    protected $signature = 'auth:doctor {--origin= : The browser origin to test, e.g. https://app.fruitionhr.com}';

    protected $description = 'Diagnose Sanctum SPA cookie authentication for this environment';

    public function handle(): int
    {
        $this->components->info('Environment');
        $this->table(['Setting', 'Value'], [
            ['APP_ENV', config('app.env')],
            ['APP_URL', config('app.url')],
            ['FRONTEND_URL', config('app.frontend_url')],
        ]);

        $this->components->info('Session cookie');
        $driver = config('session.driver');
        $this->table(['Setting', 'Value'], [
            ['SESSION_DRIVER', $driver],
            ['SESSION_DOMAIN', config('session.domain') ?: '(not set — cookie is host-only)'],
            ['SESSION_SECURE_COOKIE', var_export(config('session.secure'), true)],
            ['SESSION_SAME_SITE', config('session.same_site')],
        ]);

        $this->components->info('Sanctum stateful domains');
        $stateful = array_filter(array_map('trim', config('sanctum.stateful', [])));
        $this->line('  '.($stateful === [] ? '(none configured)' : implode("\n  ", $stateful)));

        $this->components->info('CORS');
        $this->table(['Setting', 'Value'], [
            ['allowed_origins', implode(', ', config('cors.allowed_origins', [])) ?: '(none)'],
            ['supports_credentials', var_export(config('cors.supports_credentials'), true)],
        ]);

        $problems = $this->checks($driver, $stateful);

        if ($origin = $this->option('origin')) {
            $problems = array_merge($problems, $this->checkOrigin($origin, $stateful));
        } else {
            $this->newLine();
            $this->components->warn('Pass --origin=https://your-app-domain to test a specific browser origin.');
        }

        $this->newLine();

        if ($problems === []) {
            $this->components->info('No configuration problems found.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->components->error($problem);
        }

        return self::FAILURE;
    }

    /** @return list<string> */
    private function checks(string $driver, array $stateful): array
    {
        $problems = [];

        // A database session driver with no table means every request starts a
        // brand new empty session, which looks exactly like "not logged in".
        if ($driver === 'database' && ! Schema::hasTable('sessions')) {
            $problems[] = 'SESSION_DRIVER is "database" but the sessions table does not exist. Run: php artisan migrate';
        }

        if (config('app.env') === 'production') {
            if (config('session.secure') !== true && str_starts_with((string) config('app.url'), 'https://')) {
                $problems[] = 'The site is served over HTTPS but SESSION_SECURE_COOKIE is not true. Set SESSION_SECURE_COOKIE=true.';
            }

            $local = array_filter($stateful, fn (string $d): bool => str_contains($d, 'localhost')
                || str_contains($d, '127.0.0.1')
                || str_contains($d, '.test'));

            if ($local !== []) {
                $problems[] = 'SANCTUM_STATEFUL_DOMAINS still lists development hosts ('.implode(', ', $local).'). Replace them with the production web domains.';
            }
        }

        if (config('cors.supports_credentials') !== true) {
            $problems[] = 'CORS supports_credentials is false — the browser will drop the session cookie on cross-origin calls.';
        }

        return $problems;
    }

    /** @return list<string> */
    private function checkOrigin(string $origin, array $stateful): array
    {
        // Asked of Sanctum itself rather than reimplemented, so this answer
        // cannot drift from what the middleware actually does.
        $request = Request::create('/api/v1/me');
        $request->headers->set('origin', $origin);

        $isStateful = EnsureFrontendRequestsAreStateful::fromFrontend($request);

        $this->newLine();
        $this->components->info("Requests from {$origin}");
        $this->components->twoColumnDetail(
            'Treated as stateful (session cookie honoured)',
            $isStateful ? '<fg=green>yes</>' : '<fg=red>no</>',
        );

        if ($isStateful) {
            return [];
        }

        $host = preg_replace('#^https?://#', '', rtrim($origin, '/'));

        return [
            "Sanctum does not consider {$origin} a frontend domain, so the session cookie is ignored and the request "
            ."falls through to token auth — which is what produces \"Unauthenticated\". Add \"{$host}\" to SANCTUM_STATEFUL_DOMAINS.",
        ];
    }
}
