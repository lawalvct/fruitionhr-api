# FruitionHR — Technical Architecture Plan

> Companion document to [fruitionhr_saas_development_plan.md](fruitionhr_saas_development_plan.md) (modules, database tables, phases) and `Fruition_PRD.pdf` (client requirements).
> This document defines **how the system is built**: stack, repo layout, multi-tenancy, API design, frontend architecture, deployment on aaPanel VPS.

---

## 1. Architecture Decisions (Summary)

| Decision | Choice | Why |
|---|---|---|
| Backend | **Laravel 13 REST API** (API-only, no Blade views) | Fast development, mature ecosystem, one API serves web + future mobile |
| Frontend | **Next.js 16 (App Router) + TypeScript** | Modern SPA/SSR, SEO for the marketing site, React skills transfer to React Native later. Note: Next 16 renamed `middleware.ts` → `proxy.ts` |
| Database | **MySQL 8.0+** (InnoDB, utf8mb4) | Your hosting choice; fully capable for this workload |
| Cache / Queue | **Redis** | Sessions, cache, queues, rate limiting |
| Auth | **Laravel Sanctum** — SPA cookie auth for web, personal access tokens for mobile later | One auth system, two modes |
| Permissions | **Spatie laravel-permission** (team/tenant mode enabled) | Battle-tested RBAC, per-tenant roles |
| Multi-tenancy | **Single database, `tenant_id` column** + global scopes | Simplest to build, cheapest to host, easy reporting (as agreed in dev plan §6) |
| Architecture style | **Modular monolith** | One deployable API, module folders per domain; split services only if/when needed |
| Repo layout | **Two repos, one local workspace**: `fruitionhr-api/` + `fruitionhr-web/` side by side inside `fruitionhr/` | Independent deploys (API → VPS, web → Vercel or VPS); AI tooling opened at `fruitionhr/` still sees both codebases |
| Deployment | **VPS + aaPanel**: Nginx, PHP-FPM, MySQL, Redis, Supervisor, PM2 (Node for Next.js) | Your infrastructure choice |
| Surfaces | Website (public) + Tenant dashboard + Super admin | Single Next.js app with route groups, split by subdomain |

**One important trade-off, stated once:** your first plan recommended Inertia+Vue to avoid building a separate API. Since the client wants a mobile app in the future and you've chosen Next.js, the API-first approach is the right call — but it means every screen needs both an endpoint and a frontend page. The mitigations in this document (consistent API conventions, generated TypeScript client, shared module vocabulary between `apps/api/app/Modules/*` and `apps/web/src/features/*`) exist specifically to keep that overhead low.

---

## 2. Workspace Structure (Two Repos, One Local Folder)

`fruitionhr/` is a plain **workspace folder** (not a git repo itself). Each app is its own git repository with its own history, CI, and deploy pipeline — but locally they sit side by side, so opening `fruitionhr/` in your editor/AI tool gives full visibility into both.

```txt
fruitionhr/                          ← local workspace folder (NOT a git repo)
├── CLAUDE.md                        ← workspace-level AI context: points to both repos, shared conventions
├── fruitionhr_saas_development_plan.md
├── fruitionhr_architecture_plan.md
│
├── fruitionhr-api/                  ← GIT REPO 1 — Laravel 12 (REST API only) → deploys to VPS
│   ├── CLAUDE.md                    ← API conventions, commands, module rules
│   ├── docs/
│   │   ├── erd/                     ← database diagrams
│   │   └── decisions/               ← ADRs (architecture decision records)
│   ├── app/
│   │   ├── Modules/                 ← domain modules (see §4)
│   │   ├── Core/                    ← shared engines: Workflow, Rules, Audit, Documents, Notifications
│   │   └── Support/                 ← tenancy, helpers, base classes
│   ├── routes/
│   │   ├── api_v1.php               ← tenant-facing API
│   │   ├── admin_v1.php             ← super-admin API
│   │   └── public_v1.php            ← unauthenticated (careers portal, webhooks)
│   ├── config/
│   ├── database/
│   ├── tests/
│   └── .github/workflows/           ← API CI/CD (pest → deploy to VPS)
│
└── fruitionhr-web/                  ← GIT REPO 2 — Next.js 15 (all three surfaces) → Vercel or VPS
    ├── CLAUDE.md                    ← frontend conventions, feature-module map
    ├── src/
    │   ├── app/
    │   │   ├── (marketing)/         ← fruitionhr.com — public website (root paths)
    │   │   ├── app/                 ← app.fruitionhr.com — login/register + (protected) dashboard
    │   │   └── admin/               ← admin.fruitionhr.com — super admin login + (protected) dashboard
    │   ├── features/                ← per-module UI (mirrors API modules: auth/, employees/, payroll/…)
    │   ├── components/              ← shared UI (shadcn/ui on Base UI)
    │   ├── lib/                     ← api client (axios + CSRF), utils
    │   ├── types/                   ← hand-written now; api.ts GENERATED from OpenAPI spec later
    │   └── proxy.ts                 ← host-based rewrites (Next 16 rename of middleware.ts)
    └── .github/workflows/           ← Web CI (typecheck/lint/build; Vercel handles deploys if used)
```

Rules:
- The repos never import each other's code. They communicate **only via HTTP + the generated types file**.
- The API contract crosses the repo boundary like this: the API repo's CI publishes/commits its OpenAPI spec (Scribe output); the web repo has a script (`npm run gen:api-types`) that fetches `https://api.fruitionhr.com/openapi.json` (or the local dev server) and regenerates `src/types/api.ts` with `openapi-typescript`. Regenerate after every API change; the TypeScript compiler then catches frontend breakage.
- Three `CLAUDE.md` files: one per repo (deep conventions) plus a small one at the workspace root that maps the two repos and states cross-repo rules (module name mirroring, API contract workflow). Keep planning docs (like this file) at the workspace root or move them into `fruitionhr-api/docs/`.
- Version the contract, not the repos together: when an API change breaks the web app, ship the API change behind `v1` compatibility or coordinate the two deploys (API first, web second — the API must always be backward-compatible with the currently deployed frontend).

---

## 3. Domains & Surfaces

| Surface | Domain | Next.js route group | Auth |
|---|---|---|---|
| Marketing website | `fruitionhr.com` | `(marketing)` | none (public, SSG/ISR for SEO) |
| Tenant dashboard | `app.fruitionhr.com` | `(tenant)` | Sanctum cookie, tenant users |
| Super admin | `admin.fruitionhr.com` | `(admin)` | Sanctum cookie, `is_super_admin` guard |
| API | `api.fruitionhr.com` | — | Sanctum (stateful for web SPA, tokens for mobile) |
| Careers portal (later) | `jobs.fruitionhr.com` or `{tenant}.fruitionhr.com/careers` | `(marketing)` | public + applicant accounts |

- All share the root domain, so **Sanctum SPA cookie auth works**: set `SESSION_DOMAIN=.fruitionhr.com` and add the web domains to `sanctum.stateful`. No token storage in the browser — httpOnly cookies, CSRF protected.
- `middleware.ts` in Next.js inspects the `Host` header and rewrites to the correct route group, so one deployment serves all surfaces.
- For the future React Native app, the **same API** issues Sanctum personal access tokens — nothing to rebuild.

Local development (Laragon):
```txt
api.fruitionhr.test      → fruitionhr-api  (Laragon vhost or php artisan serve)
app.fruitionhr.test      → fruitionhr-web  (next dev, port 3000)
fruitionhr.test          → apps/web
admin.fruitionhr.test    → apps/web
```

---

## 4. Backend Architecture (apps/api)

### 4.1 Modular monolith layout

Follow the module structure from the dev plan §5, under `app/Modules`:

```txt
app/
├── Core/                            ← cross-cutting engines (reusable, no business rules)
│   ├── Workflow/                    ← generic approval engine (dev plan §8)
│   ├── Rules/                       ← rule engine (dev plan §9)
│   ├── Audit/                       ← before/after audit logging (dev plan §10)
│   ├── Documents/                   ← polymorphic attachments + versions (dev plan §11)
│   └── Notifications/               ← event-driven, multi-channel (dev plan §12)
│
├── Modules/
│   ├── Tenancy/                     ← tenants, subscriptions, plans, onboarding
│   ├── Company/                     ← branches, departments, positions, grades, shifts, holidays, statutory config
│   ├── Employee/
│   ├── Attendance/
│   ├── Leave/
│   ├── Payroll/
│   ├── Loans/                       ← loans & salary advances (Ballie-style)
│   ├── Recruitment/
│   ├── Performance/
│   ├── Goals/
│   ├── Training/
│   ├── Discipline/
│   ├── Exit/
│   ├── Assets/
│   ├── Reports/
│   └── Admin/                       ← super-admin: tenant management, plans, platform metrics
│
└── Support/
    ├── Tenancy/                     ← BelongsToTenant trait, TenantScope, SetCurrentTenant middleware
    ├── Http/                        ← ApiController base, ApiResponse helper, pagination
    └── Money/                       ← integer-kobo money handling (never floats for payroll!)
```

Inside each module (per dev plan §5): `Controllers/ Requests/ Actions/ Services/ Models/ Policies/ Resources/ Jobs/ Events/ Listeners/`.

Module boundary rule: modules may call each other **only through Services or Events**, never reach into another module's models directly. Example: Payroll calls `AttendanceService::finalizedSummaryFor($period)` — it never queries `attendance_logs` itself.

### 4.2 Multi-tenancy implementation

Single DB + `tenant_id` (dev plan §6), enforced in code, never by convention:

```php
// Every tenant-owned model:
class Employee extends Model
{
    use BelongsToTenant;   // adds TenantScope global scope + auto-fills tenant_id on create
}
```

- `SetCurrentTenant` middleware resolves the tenant from the **authenticated user** (users belong to one tenant; super admins to none). Store in a `CurrentTenant` singleton, never in a static/global you forget to reset in queue workers.
- **Queued jobs must carry `tenant_id` explicitly** and re-establish tenant context in `handle()` — global scopes based on auth don't exist inside workers. This is the #1 tenancy leak source.
- Composite indexes: every tenant table indexes `(tenant_id, ...)` first, e.g. `(tenant_id, employee_id, date)` on attendance.
- Write an automated test per module asserting cross-tenant queries return nothing (dev plan §19 "Tenant data isolation").
- Spatie permission with `teams` enabled → roles/permissions are per-tenant, so each company defines its own roles.
- Path to per-tenant databases later stays open because no query ever hard-codes tenant joins.

### 4.3 API conventions

```txt
Base:        https://api.fruitionhr.com
Tenant API:  /api/v1/...          (auth:sanctum + tenant middleware)
Admin API:   /api/admin/v1/...    (auth:sanctum + super-admin guard)
Public API:  /api/public/v1/...   (careers portal, webhooks)
```

- **Versioned from day one** (`v1`) — cheap now, painful to retrofit.
- Standard REST resources: `GET /employees`, `POST /employees`, `GET /employees/{id}`, plus action endpoints for domain verbs: `POST /payroll-runs/{id}/submit`, `POST /payroll-runs/{id}/approve`, `POST /leave-requests/{id}/cancel`. State changes are explicit verbs, never `PATCH status=approved`.
- Every response through `JsonResource`; consistent envelope `{ data, meta, links }`; errors follow one shape `{ message, errors: {field: []} }` (Laravel default).
- Filtering/sorting via **spatie/laravel-query-builder** (`?filter[department_id]=3&sort=-hired_at`), cursor pagination for large lists.
- **API documentation generated with Scribe** (`knuckleswtf/scribe`) → OpenAPI spec, served at a known URL. The web repo regenerates `src/types/api.ts` from it with `openapi-typescript` (see §2). This is the contract between the two repos; regenerating on API change catches frontend breakage at compile time.
- Rate limiting per user + per tenant; idempotency keys on payroll run creation.

### 4.4 Key backend packages

```txt
laravel/sanctum                  auth (SPA cookies + mobile tokens)
spatie/laravel-permission        RBAC with teams (per-tenant roles)
spatie/laravel-query-builder     filters/sorts/includes
spatie/laravel-activitylog       base for the audit engine (extend with old/new values)
spatie/laravel-medialibrary      document engine storage layer
knuckleswtf/scribe               OpenAPI docs generation
maatwebsite/excel                imports (employees, attendance) + exports (bank schedule, statutory)
barryvdh/laravel-dompdf          payslips, letters (or spatie/laravel-pdf with headless chrome)
laravel/horizon                  queue dashboard
brick/money                      money as integers — payroll must never use floats
laravel/telescope (local only)   debugging
pestphp/pest                     testing
```

### 4.5 Payroll engine specifics (the module the client cares most about)

Mirror Ballie's guided flow but with your PRD's Nigerian statutory depth:

1. **Money = integers (kobo)** everywhere via `brick/money`. Round only at defined points (per component, per statutory rule), and store the rounding policy in config.
2. **Snapshot on calculation**: when a payroll run computes an employee's pay, copy the salary structure, component amounts, tax params, and attendance summary into `payroll_run_employees` + child tables. A payslip must be reproducible forever even after salary/config changes.
3. **State machine** on `payroll_runs`: `draft → calculating → review → pending_approval → approved → locked → paid` (+ `reversed`). Guard transitions in one place (a `PayrollRunState` class); the workflow engine drives approvals between `pending_approval → approved`.
4. **Preconditions gate** (from PRD): a run cannot leave `draft` until attendance finalized, leave processed, loans updated, overtime approved — implement as a `PayrollPreflightCheck` returning a checklist the UI renders (Ballie-style).
5. **Calculation as pure, testable steps**: `GrossPayCalculator → StatutoryCalculator (PAYE, Pension, NHF, NSITF) → DeductionsCalculator (loans, lateness, absence) → NetPayAssembler`. Each is a class with table-driven Pest tests using real figures from the client's Excel sheets (dev plan §19).
6. **PAYE etc. as versioned config**, not code: `statutory_rules` rows with `effective_from`/`effective_to` so a tax law change is a data update, and old payslips still reproduce with old rules.
7. Heavy runs execute in **queued batch jobs** (`Bus::batch` per employee chunk) with progress reported to the UI via polling endpoint (`GET /payroll-runs/{id}/progress`).

### 4.6 Background processing & scheduler

- Redis queues, separate queues: `default`, `payroll` (long-running), `notifications`, `imports`.
- Supervisor (via aaPanel) runs `php artisan horizon`.
- Scheduler (cron `* * * * * php artisan schedule:run`) covers dev plan §20 tasks: leave accruals, document/contract expiry alerts, birthdays/anniversaries, payroll period reminders, backups.

---

## 5. Frontend Architecture (apps/web)

### 5.1 Stack

```txt
Next.js 15 (App Router) + React 19 + TypeScript (strict)
Tailwind CSS 4 + shadcn/ui        ← accessible components you own, ideal for dense HR forms/tables
TanStack Query v5                 ← all server state (caching, optimistic updates, invalidation)
TanStack Table                    ← every list screen (employees, payroll, attendance…)
React Hook Form + Zod             ← forms & validation (Zod schemas can derive from API types)
Axios instance in lib/api.ts      ← withCredentials for Sanctum cookies + CSRF bootstrap
Recharts                          ← dashboard analytics (Ballie-style 12-month pay trends, dept costs)
next-intl (later)                 ← if multi-language becomes a requirement
```

### 5.2 Structure principles

- `src/features/<module>/` mirrors the API modules one-to-one: `features/payroll/` contains its components, hooks (`usePayrollRuns()`), and route-level pieces. Pages in `app/(tenant)/payroll/...` stay thin and compose from features. This mirroring is what lets AI (and you) navigate both codebases by the same vocabulary.
- **Auth**: server components read the session by forwarding cookies to `GET /api/v1/me`; a `useAuth()` provider exposes user, tenant, permissions. Route-group layouts enforce access (`(admin)` layout rejects non-super-admins).
- **Permission-aware UI**: `/me` returns the user's permission list; a `<Can permission="payroll.approve">` component hides/disables actions. UI hiding is UX only — the API is the enforcement point.
- Rendering strategy: `(marketing)` = static/ISR for SEO; `(tenant)` and `(admin)` = client-heavy with TanStack Query (dashboard data is per-user, no SEO need).
- Shared building blocks to invest in early (they pay off across all 15+ modules):
  - `DataTable` (server pagination/filter/sort wired to the spatie query-builder conventions)
  - `FormDialog` / `FormPage` wrappers (RHF + Zod + API error mapping)
  - `ApprovalTimeline` (renders any workflow_request history)
  - `StatusBadge`, `MoneyText` (kobo → ₦ display), `EmptyState`, `ConfirmDialog`
  - `ImportWizard` (upload → column map → validate → commit; reused for employees, attendance)

### 5.3 Tenant onboarding UX (steal Ballie's best idea)

Ballie's docs push a **15-step guided setup**. Build a setup checklist into the tenant dashboard from day one: company info → branches/departments → grades/positions → shifts & holidays → statutory config → salary components → employees → payroll. Store completion in `tenant_onboarding_steps`; show progress on the dashboard until complete. This dramatically reduces "empty dashboard" churn for new companies.

---

## 6. Database Notes (MySQL specifics)

The table designs in the dev plan §13–14 stand. MySQL-specific additions:

- Engine InnoDB, `utf8mb4_unicode_ci`, timezone stored UTC (`app.timezone = UTC`), convert to `Africa/Lagos` at the edge.
- Money columns: `BIGINT` (kobo) — not `DECIMAL`, not `FLOAT`.
- `JSON` columns for: workflow step config, rule definitions, audit old/new values, component formulas.
- Effective-dated tables (`employee_employment_records`, `employee_salary_structures`, `statutory_rules`): index `(tenant_id, employee_id, effective_from)`; enforce non-overlap in the service layer (MySQL lacks exclusion constraints).
- Soft deletes only where restore is a real use case (employees, documents). Approval/transaction records are never deleted — they're cancelled/reversed with status.
- Migration discipline: additive-only per release, `php artisan migrate --force` in deploy script, never edit released migrations.

---

## 7. Security Checklist (delta over dev plan §23)

- Tenant isolation tests in CI for every module (non-negotiable — one leak kills a SaaS reputation).
- Sanctum SPA: strict CORS (`supports_credentials`, explicit origins), `SameSite=Lax`, httpOnly, secure cookies.
- Salary data behind dedicated permissions (`employees.view_salary` separate from `employees.view`) — enforced in Policies **and** JsonResources (resource omits salary fields the user can't see).
- Documents on private disk; downloads only via signed, permission-checked routes (`/api/v1/documents/{id}/download`).
- 2FA (TOTP) for super admin from day one; optional per tenant.
- Audit every auth event + all events in dev plan §10.
- Backups: aaPanel scheduled `mysqldump` daily + offsite copy (S3-compatible, e.g. Backblaze/Wasabi — cheap); monthly test restore.

---

## 8. Deployment on aaPanel VPS

### 8.1 Server layout

```txt
Ubuntu 22.04+ VPS (recommend ≥4GB RAM / 2 vCPU to start)
aaPanel installed:
├── Nginx
├── PHP 8.3 FPM  (extensions: bcmath, intl, redis, gd, zip, pdo_mysql, opcache)
├── MySQL 8.0
├── Redis
├── Supervisor (aaPanel "Supervisor manager" plugin)  → horizon + schedule worker
└── PM2 (via Node manager plugin, Node 20 LTS)        → Next.js server
```

### 8.2 Frontend hosting: Vercel vs VPS

The web repo can deploy to either — the API doesn't care. Two rules make both work:

1. **Custom domains are mandatory on Vercel.** Sanctum cookie auth requires the frontend and API to share the root domain (`SESSION_DOMAIN=.fruitionhr.com`). Attach `fruitionhr.com`, `app.fruitionhr.com`, `admin.fruitionhr.com` to the Vercel project — the default `*.vercel.app` URL **cannot** do cookie auth against `api.fruitionhr.com` (use it only for preview builds with a preview API + token auth, or skip authenticated previews).
2. CORS on the API must list the exact web origins with `supports_credentials = true`.

| Option | Pros | Cons |
|---|---|---|
| **Vercel** (recommended start) | zero-ops deploys, preview URLs per PR, global CDN for the marketing site, free hobby tier to start | authenticated preview builds are awkward; you manage two platforms |
| **VPS (PM2)** | everything on one server, no external dependency | you manage Node process, builds, and CDN yourself |

You can start on Vercel and move to the VPS later (or vice versa) with zero code changes — only DNS and env vars move.

### 8.3 Nginx sites on the VPS (created in aaPanel)

| Site | Root / proxy | Notes |
|---|---|---|
| `api.fruitionhr.com` | PHP site → `fruitionhr-api/public` | standard Laravel vhost — always on the VPS |
| `fruitionhr.com`, `app.`, `admin.` | reverse proxy → `127.0.0.1:3000` | **only if** hosting the web app on the VPS instead of Vercel |

- SSL: Let's Encrypt via aaPanel; issue a **wildcard cert** (`*.fruitionhr.com`, DNS challenge) so future tenant subdomains are free. (Vercel manages its own certs for domains pointed at it.)
- Real client IP: set `X-Forwarded-For` in proxy config; trust proxies in Laravel.

### 8.4 Processes on the VPS

```txt
Supervisor: php artisan horizon                              (autorestart)
Cron:       * * * * * php /www/wwwroot/fruitionhr-api/artisan schedule:run
PM2:        pm2 start "npm run start" --name fruitionhr-web  (only if web is on the VPS)
```

### 8.5 CI/CD — one pipeline per repo

**fruitionhr-api** (GitHub Actions):
```txt
on push to main:
  test:    composer install → pest (MySQL + Redis services) → pint --test
  deploy (needs test, environment: production):
    ssh to VPS →
      cd /www/wwwroot/fruitionhr-api && git pull
      composer install --no-dev --optimize-autoloader
      php artisan migrate --force
      php artisan config:cache route:cache event:cache
      php artisan horizon:terminate        # graceful queue restart
```

**fruitionhr-web**:
```txt
If Vercel:  connect the repo — Vercel builds/deploys on push, previews on PRs.
            CI workflow only runs typecheck + lint + gen:api-types drift check.
If VPS:     on push to main → npm ci → typecheck → lint → next build
            → ssh: git pull, npm ci, npm run build, pm2 reload fruitionhr-web
```

**Deploy ordering rule (two repos):** when a change spans both, deploy the API first and keep it backward-compatible with the live frontend; deploy the web app second. Never ship an API change that breaks the currently deployed frontend.

Add a `staging.` set of subdomains (separate DB on the VPS; a separate Vercel environment or preview branch for web) before onboarding the first real client. Deploy staging from `develop`, production from `main`.

---

## 9. Build Order (aligned to dev plan §15–16, adjusted for API+Next.js)

The module phases in the dev plan remain correct. The API-first stack changes *how* each phase starts:

**Phase 1 — Foundation (do this before any module):**
1. Scaffold the workspace: `fruitionhr-api` repo (Laravel 12), `fruitionhr-web` repo (Next.js 15), `CLAUDE.md` in each + one at the workspace root, CI skeleton per repo.
2. Tenancy core: tenants table, `BelongsToTenant`, middleware, tenant registration + user invitation flow.
3. Auth end-to-end: Sanctum SPA login/logout/me from Next.js — **prove the cookie flow across subdomains first**, it's the fiddliest integration point.
4. RBAC: Spatie with teams, `/me` returns permissions, `<Can>` component.
5. Audit engine (attach to everything from the start — retrofitting is painful).
6. Shared UI kit: `DataTable`, `FormDialog`, layout shells for the three surfaces.
7. Super admin minimal: list/create/suspend tenants, platform metrics.
8. Deploy the skeleton to staging on the VPS **now** — validate aaPanel setup while the app is tiny.

**Then follow the dev plan phases:** Company Setup → Employees → Workflow/Documents/Notifications engines → Attendance & Leave → Payroll Core (MVP 1 sellable point) → Payroll Advanced → ESS/MSS → Recruitment → Performance → Training → Enterprise modules → AI.

For each module, the working rhythm is:
```txt
migrations → models+policies → actions/services (Pest tests) → controllers+resources
→ Scribe docs regenerate → TS client regenerate → Next.js feature (list → form → detail → approvals)
```

**MVP 1 definition of done (sellable):** a company can register, complete the setup checklist, import employees, configure salary components + statutory rules, record/finalize attendance and leave, run a full payroll with the preflight gate, route it through approval, lock it, and download payslips + bank schedule + PAYE/pension/NHF/NSITF reports — with audit trail and tenant isolation tests green.

---

## 10. Conventions That Keep AI (and You) Productive

- Workspace-root `CLAUDE.md` maps the two repos and cross-repo rules (module mirroring, API-first deploy order, how to regenerate API types). Each repo's own `CLAUDE.md` documents its stack versions, folder map, tenancy rules ("every model uses BelongsToTenant"), money rule ("integers/kobo only"), and how to run its tests/dev server.
- Same module names on both sides (`Modules/Leave` ↔ `features/leave`).
- One naming style: DB `snake_case`, PHP `camelCase`, API JSON `snake_case`, TS types generated (never hand-synced).
- Every non-obvious architecture choice gets a short ADR in `docs/decisions/` (e.g. "0001-single-db-tenancy.md") — future contributors (and AI sessions) inherit the reasoning, not just the code.
- Seeders create a full demo tenant (employees, salary structures, one processed payroll) so every dev/staging environment can demo instantly.

---

## 11. Immediate Next Steps

1. Confirm this architecture with the client (esp. subdomain scheme and MVP 1 scope).
2. Register domains / set up DNS with wildcard record.
3. Scaffold the monorepo (`apps/api`, `apps/web`, CLAUDE.md, CI) — one session of work.
4. Build Phase 1 Foundation (§9) — auth + tenancy + RBAC end-to-end is the milestone.
5. In parallel, collect from the client: payroll Excel templates, payslip format, statutory parameters, approval chains (dev plan §27).
