<?php

namespace App\Providers;

use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped (not singleton): resets per request/job so tenant context can
        // never leak between requests under Octane or between queued jobs.
        $this->app->scoped(CurrentTenant::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Surface missing $fillable/relationship mistakes early in dev.
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
