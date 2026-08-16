<?php

use App\Core\Workflow\Console\BackfillWorkflowsCommand;
use App\Modules\Billing\Console\ExpireLapsedSubscriptions;
use App\Modules\Billing\Console\ReconcilePendingPayments;
use App\Support\Http\EnsureActiveSubscription;
use App\Support\Http\EnsureEmailVerified;
use App\Support\Http\EnsureSuperAdmin;
use App\Support\Tenancy\SetCurrentTenant;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        BackfillWorkflowsCommand::class,
        ReconcilePendingPayments::class,
        ExpireLapsedSubscriptions::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        $middleware->alias([
            'tenant' => SetCurrentTenant::class,
            'super-admin' => EnsureSuperAdmin::class,
            'verified.email' => EnsureEmailVerified::class,
            'subscribed' => EnsureActiveSubscription::class,
        ]);

        // SECURITY: tenant context MUST be established before route-model
        // binding runs, otherwise {employee}-style bindings resolve without
        // the tenant scope and leak cross-tenant records.
        $middleware->priority([
            HandlePrecognitiveRequests::class,
            EnsureFrontendRequestsAreStateful::class,
            EncryptCookies::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ValidateCsrfToken::class,
            Authenticate::class,
            AuthenticateSession::class,
            SetCurrentTenant::class,
            SubstituteBindings::class,
            Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
