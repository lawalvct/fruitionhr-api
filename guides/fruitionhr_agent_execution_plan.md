# FruitionHR — Agent Execution Plan

**Audience:** an AI coding agent continuing development of FruitionHR.
**Read this whole file before writing any code.** Then read, in order:

1. `CLAUDE.md` (workspace root — cross-repo rules)
2. `fruitionhr-api/CLAUDE.md` (backend conventions)
3. `fruitionhr-web/AGENTS.md` (frontend conventions + Next.js 16 gotchas)
4. The section of `fruitionhr_saas_development_plan.md` for the phase you are building (it contains table lists and business rules)

---

## 1. Golden rules (violating any of these is a critical failure)

1. **Tenancy** — every new tenant-owned Eloquent model MUST `use App\Support\Tenancy\BelongsToTenant`. Every new tenant table migration MUST have `$table->foreignId('tenant_id')->constrained()->cascadeOnDelete();` and a composite index starting with `tenant_id`. Every queued job MUST use the `TenantAware` trait.
2. **Money is integer kobo.** Columns are `BIGINT` (`$table->bigInteger('amount')`). Never `FLOAT`, never `DECIMAL`, never PHP floats in calculations. Use `brick/money` for arithmetic.
3. **Status changes are action endpoints** — `POST /leave-requests/{id}/approve`, never `PATCH { status: ... }`.
4. **Locked/approved records are immutable** — corrections create new reversal/adjustment records.
5. **Never edit released migrations.** Add new ones.
6. **Never hand-edit** `src/types/api.ts` once generation exists, `src/components/ui/*` (shadcn), or anything in `vendor/`/`node_modules/`.
7. **Do not change** the auth architecture, `src/proxy.ts` host logic, package versions, or `.env` session/CORS settings unless the task explicitly says so.
8. **Every phase ends with:** all Pest tests passing, `npm run build` passing, and a git commit in each changed repo. Never commit with failing tests.

## 2. How to work (repeat for every module)

Work in this exact order — backend first, then frontend:

```
1. Migrations            (fruitionhr-api/database/migrations/)
2. Models + factories    (app/Modules/<Module>/Models/ + database/factories/)
3. Actions/Services      (business logic, no logic in controllers)
4. Form Requests         (validation)
5. Policies              (authorization per permission)
6. Controllers + Resources (thin; JSON envelope { data, meta })
7. Routes                (inside the tenant group in routes/api.php)
8. Pest tests            (feature tests + tenant isolation test) → RUN THEM
9. Frontend feature      (fruitionhr-web/src/features/<module>/)
10. Pages                (src/app/app/(protected)/<module>/)
11. npm run build        → must pass
12. Commit both repos
```

### Copy these existing files as your templates

| To create a... | Copy the pattern from |
|---|---|
| Tenant-owned model | `fruitionhr-api/app/Modules/Tenancy/Models/Tenant.php` (+ add `BelongsToTenant`) |
| Factory for a module model | `database/factories/TenantFactory.php` (note the explicit `$factory` property on the model) |
| Action class | `app/Modules/Tenancy/Actions/RegisterTenant.php` |
| Form Request | `app/Modules/Tenancy/Requests/RegisterTenantRequest.php` |
| Controller | `app/Modules/Auth/Controllers/AuthController.php` |
| API Resource | `app/Modules/Auth/Resources/MeResource.php` |
| Feature test | `tests/Feature/Auth/RegistrationTest.php` |
| Tenant isolation test | `tests/Feature/Tenancy/TenantIsolationTest.php` |
| React Query hooks | `fruitionhr-web/src/features/auth/use-auth.ts` |
| Form component | `src/features/auth/register-form.tsx` |
| Protected page | `src/app/app/(protected)/dashboard/page.tsx` |

### Commands

```powershell
# Backend (run from fruitionhr-api/)
php artisan serve --port=8010        # dev API
.\vendor\bin\pest                    # tests — must pass before commit
php artisan migrate                  # local MySQL db "fruitionhr" (root, no password)

# Frontend (run from fruitionhr-web/)
npm run dev                          # localhost:3000 = website, app.localhost:3000 = tenant app
npm run build                        # must pass before commit
```

### Adding permissions

Add constants to `app/Support/Authorization/Permissions.php`, include them in `all()` and in the right roles in `defaultRoles()`. Gate routes with `->middleware('permission:<name>')` or in Policies. On the frontend wrap UI in `<Can permission="...">`.

### Adding a nav item (tenant app)

Edit the `nav` array in `fruitionhr-web/src/app/app/(protected)/layout.tsx`.

### API response conventions

- List endpoints: support `?filter[...]`, `?sort=`, pagination via `spatie/laravel-query-builder`; return `{ data: [...], meta: {...}, links: {...} }` (paginated resource collection).
- Single: `{ data: {...} }`. Errors: Laravel default `{ message, errors }`.
- JSON keys are `snake_case`. Frontend types mirror them exactly.

---

## 3. Phase order

Build in this order. Do not start a phase until the previous one's Definition of Done is met.

- **P1b** Account activation + company onboarding (email code verification, optional resumable setup, starter master data)
- **P2a** Shared UI kit (frontend only)
- **P2b** Company Setup module
- **P2c** Employee module
- **P3** Workflow, Documents, Notifications engines
- **P4a** Attendance
- **P4b** Leave
- **P5** Payroll core ← Milestone 1
- **P6** Payroll advanced
- **P7** Self-service (ESS/MSS)
- **P8+** Recruitment, Performance, Training, Enterprise — consult `fruitionhr_saas_development_plan.md` §13.3, §13.8–13.14 when you get there.

---

## Phase 1b — Account activation and company onboarding

**Status:** implemented 2026-07-11.

- Registration signs the owner in and sends a six-digit email verification code. Codes are hashed, expire after 10 minutes, allow five attempts, and resend after 60 seconds.
- Unverified users may access `/me`, logout, verification, and resend endpoints only. Every tenant module route uses `verified.email` middleware.
- Company onboarding is owner-only, server-backed, resumable, and skippable. Progress lives on the tenant (`onboarding_status`, step, data, timestamps, version).
- Completing or skipping provisions editable starter data exactly once: Main Office, common departments and employment types, standard leave types, salary components, and a Nigeria holiday-calendar shell.
- Never seed fake employees, salaries, attendance, leave requests, payroll runs, or statutory submissions into a real tenant.
- Skipped onboarding remains available from the dashboard. Saving later changes it back to `in_progress`; completion finalises it.
- Local development currently uses `MAIL_MAILER=log`; retrieve codes from `storage/logs/laravel.log` or Telescope Mail. Production requires a real mail transport.

**Definition of Done:** verification security tests, onboarding/idempotency tests, all Pest tests, TypeScript, ESLint, and frontend build pass.

---

## Phase 2a — Shared UI kit (build once, reuse everywhere)

**Repo:** `fruitionhr-web` only. These components are used by every later phase — build them well.

Create in `src/components/`:

1. **`data-table.tsx`** — generic server-driven table on TanStack Table:
   props: `columns`, `queryKey`, `endpoint` (fetches via `api` from `src/lib/api.ts`), built-in search input, pagination controls, empty state, loading skeleton. It must send `?page=`, `?filter[search]=`, `?sort=` params matching the spatie/laravel-query-builder conventions.
2. **`form-dialog.tsx`** — modal wrapper (shadcn Sheet or a dialog) around a react-hook-form + Zod form with a standard footer (Cancel / Save with pending state) and a helper that maps Laravel 422 `errors` onto RHF `setError` (extract the logic already written in `src/features/auth/register-form.tsx` into `src/lib/forms.ts` and reuse it).
3. **`money-text.tsx`** — renders integer kobo as `₦1,234,567.89` (`new Intl.NumberFormat("en-NG", { style: "currency", currency: "NGN" }).format(kobo / 100)`). All money display goes through this.
4. **`status-badge.tsx`** — coloured badge mapping status → colour: approved/active = fruition-600, pending = amber, rejected/failed = red, draft = slate, info = blue.
5. **`confirm-dialog.tsx`** — "Are you sure?" wrapper for destructive/irreversible actions.
6. **`page-header.tsx`** — title + description + action button slot, used at the top of every module page.

**Definition of Done:** `npm run build` passes; a temporary demo usage compiles (can be removed after); committed.

---

## Phase 2b — Company Setup module

**Goal:** organisation structure master data. Read dev plan §13.1.

### Backend (`app/Modules/Company/`)

Tables (all tenant-owned, all soft-delete, all with `created_by` nullable FK to users):

- `branches` — name, code (nullable), address, city, state, is_active
- `departments` — name, code, branch_id (nullable FK), parent_id (nullable self-FK), is_active
- `positions` — title, code, department_id (nullable FK), job_grade_id (nullable FK), description, is_active
- `job_grades` — name, code, level (int, for ordering), min_salary (bigint kobo, nullable), max_salary (bigint kobo, nullable), is_active
- `employment_types` — name (e.g. Full-time, Contract, Intern), is_active
- `holiday_calendars` — year (int), name; `holiday_dates` — holiday_calendar_id FK, date, name, is_recurring

Endpoints (all inside the tenant route group, standard REST):
`/api/v1/branches`, `/departments`, `/positions`, `/job-grades`, `/employment-types`, `/holiday-calendars` (+ nested dates).

Permissions: use existing `company.view` / `company.manage`.

Seeder: `TenantDemoSeeder` that creates a demo tenant with 2 branches, 5 departments, 8 positions, 3 grades — used for local demo and later phases' tests.

Tests: CRUD feature test per resource (create/list/update/delete + validation error) **plus** one tenant-isolation test asserting tenant B cannot see or update tenant A's branch (copy `TenantIsolationTest` pattern but against the real endpoints).

### Frontend (`src/features/company/`, pages under `src/app/app/(protected)/settings/`)

- `/settings/organisation` page with tabs (Branches, Departments, Positions, Grades, Employment types, Holidays)
- Each tab: `DataTable` list + "Add" button opening `FormDialog`; edit + delete (with `ConfirmDialog`) per row
- Gate the page with `<Can permission="company.manage">` for write actions
- Add "Settings" to the sidebar nav (icon: `Settings`)

**Definition of Done:** all Pest tests green; can create a branch → department → position chain in the browser at `app.localhost:3000`; build passes; both repos committed.

---

## Phase 2c — Employee module

**Goal:** the employee master record. Read dev plan §13.2 — follow its "don't put everything in one table" advice.

### Backend (`app/Modules/Employee/`)

Tables:

- `employees` — employee_number (unique per tenant: index `[tenant_id, employee_number]`), user_id (nullable FK — set when the employee gets a login), first_name, middle_name, last_name, official_email, personal_email, phone, gender (enum string), date_of_birth, marital_status, address, city, state, photo_path, employment_status (`active|on_leave|suspended|exited`), hired_at, exited_at (nullable)
- `employee_employment_records` — employee_id FK, branch_id, department_id, position_id, job_grade_id, employment_type_id (all nullable FKs), supervisor_id (nullable FK to employees), effective_from (date), effective_to (nullable date), is_current (bool). **Rule:** creating a new record sets `effective_to`/`is_current=false` on the previous one (do this in an Action, in a transaction).
- `employee_contacts` — employee_id, type (`emergency|next_of_kin`), name, relationship, phone, email, address
- `employee_bank_accounts` — employee_id, bank_name, bank_code, account_number, account_name, is_primary
- `employee_statutory_details` — employee_id, tax_id (TIN), pension_pin, pension_fund_administrator, nhf_number

Employee number generation: `EMP-0001` style, per-tenant sequence — max existing number + 1 inside a transaction.

Endpoints:
- `GET/POST /api/v1/employees`, `GET/PUT /employees/{id}` (list supports `filter[search]` on name/number/email, `filter[department_id]`, `filter[employment_status]`, sort by name/hired_at)
- `POST /employees/{id}/assignments` (new employment record = transfer/promotion)
- CRUD for nested contacts and bank accounts: `POST/PUT/DELETE /employees/{id}/contacts/...` etc.
- `GET /employees/{id}` returns the full profile (current assignment + contacts + bank + statutory) in one response.

Permissions: `employees.view/create/update/delete` exist already. Salary fields don't exist yet — but when they arrive (P5), they are NEVER included in employee resources without `employees.view_salary`.

Policies: `EmployeePolicy` mapping abilities to those permissions; register and use `authorize()` in controllers.

Tests: employee CRUD, employee-number uniqueness per tenant (two tenants can both have EMP-0001), assignment history rule (old record closed), isolation test.

### Frontend (`src/features/employees/`)

- `/employees` — DataTable (columns: number, name with avatar initials, department, position, status badge, hired date) + search + filters + "Add employee"
- Add employee: multi-section form page `/employees/new` (Personal → Employment → Contacts → Bank). Keep it one page with sections, not a wizard.
- `/employees/[id]` — profile page: header card (photo initials, name, number, status) + tabs (Overview, Employment history, Contacts, Bank & statutory, Documents placeholder)
- Sidebar: add "Employees" (icon: `Users`)

**Definition of Done:** create an employee in the browser, transfer them to another department, see both records under Employment history; tests green; build passes; committed.

---

## Phase 3 — Workflow, Documents, Notifications engines

**Goal:** reusable engines. Read dev plan §8, §11, §12. Build in `app/Core/`, not `app/Modules/`.

### 3.1 Workflow engine (`app/Core/Workflow/`)

Tables: exactly as dev plan §8 (`workflow_definitions`, `workflow_steps`, `workflow_requests`, `workflow_actions`) — all tenant-owned.

Design contract (keep it this simple):
- Any model can be approvable: `workflow_requests.record_type` / `record_id` is a polymorphic reference.
- `WorkflowService::submit(Model $record, string $module)` — finds the active definition for the module, creates the request at step 1, notifies the step's approvers.
- `WorkflowService::act(WorkflowRequest $r, User $by, 'approve'|'reject'|'return', ?string $comments)` — validates the user may act on the current step (role-based approver), records a `workflow_action`, advances to next step or finalises. On final approval/rejection it fires an event (`WorkflowApproved`, `WorkflowRejected`) that the owning module listens to.
- Statuses: `pending`, `approved`, `rejected`, `returned`, `cancelled`.
- Seed one default definition per module when a tenant is created (extend `TenantRoleProvisioner` or a new provisioner): e.g. Leave = Supervisor → HR Admin.

Endpoints: `GET /api/v1/approvals` (my pending approvals inbox), `POST /approvals/{id}/approve|reject|return`.

Tests: two-step flow approves end-to-end; a non-approver gets 403; rejection stops the flow; isolation test.

### 3.2 Document engine (`app/Core/Documents/`)

Table `documents` as dev plan §11 (polymorphic `owner_type`/`owner_id`, tenant-owned). Store files on the `local` (private) disk under `tenants/{tenant_id}/...`. Endpoints: `POST /api/v1/documents` (multipart: owner_type, owner_id, document_type, title, file), `GET /documents/{id}/download` (must check permission + tenant), `DELETE /documents/{id}`. Validate: max 10 MB; allow pdf/png/jpg/docx/xlsx. Wire into the employee profile Documents tab (upload + list + download).

### 3.3 Notifications (`app/Core/Notifications/`)

Use Laravel's `database` notifications channel (`php artisan make:notifications-table`). One generic `SystemNotification` (title, body, action_url, type). Endpoints: `GET /api/v1/notifications` (latest 20 + unread count), `POST /notifications/read-all`. Frontend: bell icon with unread badge in `app-shell.tsx` header, dropdown list. Workflow engine sends notifications on submit/approve/reject.

**Definition of Done:** employee document upload works in browser; an approval created in a test flows through; bell shows notifications; tests green; build passes; committed.

---

## Phase 4a — Attendance

Read dev plan §13.4. Backend `app/Modules/Attendance/`; key rule: **payroll reads only finalized summaries, never raw logs.**

Tables: `shifts` (name, start_time, end_time, grace_minutes, working_days json), `shift_assignments` (employee_id, shift_id, effective_from/to), `attendance_logs` (employee_id, date, clock_in, clock_out, source `manual|import|self`, index `[tenant_id, employee_id, date]` unique), `attendance_summaries` (employee_id, period `YYYY-MM`, days_present, days_late, days_absent, late_minutes, overtime_minutes, status `open|finalized`, finalized_by, finalized_at).

Logic (pure service class + unit tests): given logs + shift + holidays (P2b) + approved leave (P4b), derive per-day status: present/late/early-exit/absent/holiday/weekend/on-leave.

Endpoints: shifts CRUD, `POST /attendance-logs` (manual entry), `POST /attendance-logs/import` (xlsx via `maatwebsite/excel` — install it now), `GET /attendance` (monthly grid per employee), `POST /attendance-periods/{period}/finalize` (permission `attendance.approve`; computes + locks summaries).

Frontend: `/attendance` month view table (rows = employees, cells = day status dots), shift settings tab under `/settings/organisation`, manual-entry dialog, import dialog, Finalize button with `ConfirmDialog`.

**DoD:** import or enter logs → statuses computed correctly (unit tests cover grace/late/absent cases) → finalize locks the month; tests green; committed.

## Phase 4b — Leave

Read dev plan §13.5. Backend `app/Modules/Leave/`.

Tables: `leave_types` (name, is_paid, requires_document), `leave_policies` (leave_type_id, days_per_year, carry_forward_max, applies_to_employment_type_id nullable), `leave_balances` (employee_id, leave_type_id, year, allocated, carried_forward, taken, unique `[tenant_id, employee_id, leave_type_id, year]`), `leave_requests` (employee_id, leave_type_id, start_date, end_date, days (computed, exclude weekends+holidays), reason, status).

Flow: `POST /leave-requests` (validates balance) → submits to the **workflow engine** (module `leave`) → on `WorkflowApproved`, a listener updates `leave_balances.taken` and marks attendance days as on-leave. Cancel endpoint for pending requests.

Endpoints: leave types/policies CRUD (settings), `GET/POST /leave-requests` (employees see own; `leave.view` sees all), `GET /leave-balances?employee_id=`, approvals flow through the P3 approvals inbox.

Frontend: `/leave` — tabs: My requests (+ "Apply for leave" FormDialog showing live balance), Team calendar (simple month grid of approved leave), Balances table (`leave.view`), Settings (types/policies, `company.manage`).

**DoD:** apply → approve via approvals inbox → balance reduced and attendance shows on-leave; insufficient balance rejected with 422; tests green; committed.

---

## Phase 5 — Payroll core (Milestone 1 — be extremely careful here)

Read dev plan §13.6 and architecture plan §4.5. Backend `app/Modules/Payroll/`. **Every calculation class needs table-driven Pest tests. All money in kobo.**

### Order of work

1. **Salary components & structures**: `salary_components` (name, code, type `earning|deduction`, calc_type `fixed|percent_of_basic`, percent nullable, is_taxable, is_pensionable), `salary_structures` (name) + `salary_structure_components` (structure_id, component_id, amount bigint nullable, percent nullable), `employee_salaries` (employee_id, structure_id, basic_salary bigint, effective_from/to, is_current — history like employment records). Endpoints + settings UI tab + employee profile "Compensation" tab (gated by `employees.view_salary` — the API resource must omit salary fields without it).
2. **Statutory rules** (`statutory_rules` table: type `paye|pension|nhf|nsitf`, config json, effective_from/to). Seed current Nigerian defaults: PAYE annual bands per Finance Act (verify against the client's payroll Excel before trusting): CRA = max(₦200k, 1% gross) + 20% gross; bands 7%/11%/15%/19%/21%/24%. Pension: employee 8% / employer 10% of (basic+housing+transport). NHF: 2.5% of basic. NSITF: employer 1% of monthly emolument. **Calculators are pure classes** in `app/Modules/Payroll/Calculators/`, each taking config + employee snapshot, returning kobo. Unit-test each against hand-computed figures; get client Excel samples if available.
3. **Pay periods & runs**: `pay_periods` (year, month, status), `payroll_runs` (pay_period_id, status `draft|calculating|review|pending_approval|approved|locked|paid`, totals), `payroll_run_employees` (run_id, employee_id, snapshot json of structure+attendance, gross, total_deductions, net — all kobo), `payroll_items` (run_employee_id, component code/name, type, amount) — the snapshot makes payslips reproducible forever.
4. **Preflight check** `PayrollPreflight::check($period)` returns `[ ['label' => 'Attendance finalized', 'passed' => bool], ...]` (attendance finalized, no pending leave in period, all active employees have salaries). Run cannot be created unless all pass.
5. **Processing**: `POST /payroll-runs` (creates + queues `CalculatePayrollRun` job — chunked, `TenantAware`) → `GET /payroll-runs/{id}` with progress → review screen → `POST .../submit` (into workflow, module `payroll`) → on approval `POST .../lock` (permission `payroll.approve`). **After lock: no mutation endpoints work on it — enforce in a single `PayrollRunState` guard class.**
6. **Outputs**: payslip PDF per employee (`barryvdh/laravel-dompdf` — install now; simple clean template with company name, employee, items, YTD later), `GET .../bank-schedule.xlsx`, `GET .../statutory-report?type=paye|pension|nhf|nsitf` (xlsx exports).

Frontend `/payroll`: runs list, "Start payroll run" (shows preflight checklist, Ballie-style), run detail = employee table (gross/deductions/net via `MoneyText`, drill into per-employee items), Submit/Approve/Lock buttons via `<Can>`, download buttons after lock.

**DoD:** with the demo tenant: set structures → finalize attendance → run payroll → figures match the unit-tested expectations → approve + lock → download payslip PDF + bank schedule. Locked run rejects edits (test proves 403/409). Calculator unit tests + full-flow feature test green; committed.

---

## Phase 6 — Payroll advanced

Reversal (creates a mirror-negative run linked to the original), 13th month, final settlement, variance report (`this run vs previous run` per employee with % change), payroll journal export. Same rules as P5. Read dev plan §13.6–13.7.

## Phase 7 — Self-service (ESS/MSS)

Employee role sees a reduced portal: my payslips (list + PDF download of own only — enforce in policy), my leave (apply/history/balance), my attendance, my profile (view + request update via workflow). Managers additionally: approvals inbox (exists from P3), team calendar, team attendance. Mostly frontend + policies; add `ess.*` permissions to the `employee` role in `Permissions::defaultRoles()`.

## Phases 8+ — Recruitment, Performance, Training, Enterprise

Follow dev plan §13.3, §13.8–§13.16 for tables and flows. Apply the identical working rhythm and golden rules. Each phase: backend first, tests, then frontend, then commit.

---

## 4. If something breaks

- Test failures about "Session store not set" → you removed the Origin header behaviour in `tests/TestCase.php`; restore it.
- 419 CSRF in browser → frontend must call `ensureCsrf()` (already automatic via `useLogin`-style hooks; copy that pattern).
- Factory "class not found" → the model is missing `protected static string $factory`.
- `asChild` type errors → this shadcn uses Base UI: `render={<Link href=... />}`.
- Zod `.email()` error → use `z.email()` (Zod 4).
- Never "fix" a failing tenant-isolation test by removing the scope. The scope is correct; your query is wrong.

## 5. Commit style

One commit per completed unit of work, message explains **what and why**, ending with:

```
Co-Authored-By: Claude <noreply@anthropic.com>
```

Never use `--no-verify`, never force-push, never commit failing tests, never commit `.env`.
