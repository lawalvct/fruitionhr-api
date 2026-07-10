# fruitionhr-api — Laravel REST API

Multi-tenant HR & Payroll SaaS backend. Laravel 13, PHP 8.3, MySQL 8, Sanctum, Spatie permission (teams mode). Sibling repo: `../fruitionhr-web` (Next.js). Cross-repo rules live in `../CLAUDE.md`; architecture decisions in `../fruitionhr_architecture_plan.md`.

## Commands

```
php artisan serve --port=8010     # dev server (matches web repo's .env.local)
.\vendor\bin\pest                 # run tests (SQLite in-memory)
php artisan migrate               # MySQL db "fruitionhr" (Laragon root, no password)
```

## Structure

```
app/
├── Core/         # cross-cutting engines (Workflow, Rules, Audit, Documents, Notifications) — no business rules
├── Modules/      # domain modules: Tenancy, Auth, Company, Employee, Attendance, Leave, Payroll, …
│   └── <Module>/{Controllers,Requests,Actions,Services,Models,Policies,Resources,Jobs,Events}
└── Support/      # Tenancy (BelongsToTenant, CurrentTenant, TenantAware), Http, Authorization, Money
```

Module boundary rule: modules call each other **only via Services or Events** — never query another module's models directly.

## Non-negotiable rules

1. **Every tenant-owned model** uses `App\Support\Tenancy\BelongsToTenant` (global scope + auto-fill; throws `MissingTenantContextException` if created without context). Add a composite index starting with `tenant_id` on every tenant table.
2. **Every queued job** touching tenant data uses the `TenantAware` trait: `captureTenantContext()` in the constructor, `restoreTenantContext()` first line of `handle()`.
3. **Money is integer kobo** (`BIGINT` columns, `brick/money`). Never floats, never DECIMAL.
4. **State changes are explicit action endpoints** (`POST /payroll-runs/{id}/approve`), never `PATCH status=...`.
5. **Locked payroll is immutable** — corrections via reversal/adjustment records only.
6. Roles are per-tenant (Spatie teams mode, `team_foreign_key = tenant_id`). Call `setPermissionsTeamId($tenantId)` before role/permission operations outside the request cycle. Permission catalogue: `App\Support\Authorization\Permissions` — add there, roles seed via `TenantRoleProvisioner`.
7. Models outside `App\Models` must name their factory: `protected static string $factory = ...`.

## Routes

`routes/api.php`: `/api/v1` public (register, login) → authenticated (`auth:sanctum`) → tenant group (`auth:sanctum` + `tenant` middleware) → `/api/admin/v1` (`super-admin` middleware). New module routes go in the tenant group.

## Auth

Sanctum SPA cookie auth (`statefulApi()`). Frontend calls `/sanctum/csrf-cookie` first. Tests inherit an `Origin: http://localhost:3000` header from `tests/TestCase.php` to make requests stateful. Controllers guard `$request->hasSession()` so token clients (future mobile) don't crash.

## Testing

Pest 4. Feature tests auto-use `RefreshDatabase`. Every new tenant-owned model/module needs a tenant-isolation test (see `tests/Feature/Tenancy/TenantIsolationTest.php` for the pattern). Payroll calculations get table-driven tests with real client figures.
