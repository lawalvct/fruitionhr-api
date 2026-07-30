<?php

use App\Models\User;
use App\Modules\Company\Models\Branch;
use App\Modules\Company\Models\Department;
use App\Modules\Company\Models\EmploymentType;
use App\Modules\Company\Models\JobGrade;
use App\Modules\Company\Models\Position;
use App\Modules\Employee\Models\Employee;
use App\Modules\Employee\Models\EmployeeEmploymentRecord;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\UploadedFile;
use App\Modules\Auth\Notifications\EssInvitationNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

function actingAsEmployeeOwner(): array
{
    $tenant = Tenant::factory()->create();
    app(TenantRoleProvisioner::class)->provision($tenant);
    app(CurrentTenant::class)->set($tenant);
    setPermissionsTeamId($tenant->id);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => User::STATUS_ACTIVE,
    ]);
    $user->assignRole('owner');

    test()->actingAs($user);

    return [$tenant, $user];
}

beforeEach(function (): void {
    [$this->tenant, $this->user] = actingAsEmployeeOwner();
});

test('admin can invite an employee to ESS and employee can set a password', function (): void {
    Notification::fake();
    $employee = Employee::factory()->create([
        'first_name' => 'Ada',
        'last_name' => 'Okafor',
        'official_email' => 'ada.ess@example.test',
        'user_id' => null,
    ]);

    $this->postJson("/api/v1/employees/{$employee->id}/ess-access")
        ->assertOk()
        ->assertJsonPath('data.status', User::STATUS_INVITED)
        ->assertJsonPath('data.email', 'ada.ess@example.test');

    $employee->refresh();
    $essUser = User::query()->findOrFail($employee->user_id);
    expect($essUser->hasRole('employee'))->toBeTrue();

    $token = null;
    Notification::assertSentTo($essUser, EssInvitationNotification::class, function (EssInvitationNotification $notification) use (&$token): bool {
        parse_str((string) parse_url($notification->setupUrl, PHP_URL_QUERY), $query);
        $token = $query['token'] ?? null;
        return ($query['email'] ?? null) === 'ada.ess@example.test' && is_string($token);
    });

    $this->postJson('/api/v1/ess-invitations/accept', [
        'email' => $essUser->email,
        'token' => $token,
        'password' => 'SecurePass123!',
        'password_confirmation' => 'SecurePass123!',
    ])->assertOk();

    $essUser->refresh();
    expect($essUser->status)->toBe(User::STATUS_ACTIVE)
        ->and($essUser->email_verified_at)->not->toBeNull()
        ->and(Hash::check('SecurePass123!', $essUser->password))->toBeTrue();
});

test('employee email is required before ESS access can be enabled', function (): void {
    $employee = Employee::factory()->create(['official_email' => null, 'personal_email' => null]);

    $this->postJson("/api/v1/employees/{$employee->id}/ess-access")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

test('employees can be created listed shown updated and deleted', function (): void {
    $branch = Branch::factory()->create();
    $department = Department::factory()->create(['branch_id' => $branch->id]);
    $grade = JobGrade::factory()->create(['level' => 1]);
    $position = Position::factory()->create(['department_id' => $department->id, 'job_grade_id' => $grade->id]);
    $type = EmploymentType::factory()->create(['name' => 'Full-time']);

    $response = $this->postJson('/api/v1/employees', [
        'first_name' => 'Ada',
        'last_name' => 'Okafor',
        'official_email' => 'ada.okafor@example.test',
        'phone' => '+2348012345678',
        'employment_status' => 'active',
        'hired_at' => '2026-01-15',
        'assignment' => [
            'branch_id' => $branch->id,
            'department_id' => $department->id,
            'position_id' => $position->id,
            'job_grade_id' => $grade->id,
            'employment_type_id' => $type->id,
            'effective_from' => '2026-01-15',
        ],
        'contacts' => [
            ['type' => 'emergency', 'name' => 'Nneka Okafor', 'relationship' => 'Sibling', 'phone' => '+2348099999999'],
        ],
        'bank_accounts' => [
            ['bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'Ada Okafor', 'is_primary' => true],
        ],
        'statutory' => [
            'tax_id' => 'TIN123',
            'pension_pin' => 'PEN123',
        ],
    ])->assertCreated()
        ->assertJsonPath('data.employee_number', 'EMP-0001')
        ->assertJsonPath('data.full_name', 'Ada Okafor')
        ->assertJsonPath('data.current_assignment.department.id', $department->id)
        ->assertJsonPath('data.contacts.0.name', 'Nneka Okafor')
        ->assertJsonPath('data.bank_accounts.0.bank_name', 'GTBank')
        ->assertJsonPath('data.statutory_details.tax_id', 'TIN123');

    $id = $response->json('data.id');

    $this->getJson('/api/v1/employees?filter[search]=ada')
        ->assertOk()
        ->assertJsonPath('data.0.id', $id);

    $this->getJson("/api/v1/employees/{$id}")
        ->assertOk()
        ->assertJsonPath('data.employment_records.0.department.id', $department->id);

    $this->putJson("/api/v1/employees/{$id}", [
        'employee_number' => 'EMP-0001',
        'first_name' => 'Adaobi',
        'last_name' => 'Okafor',
        'employment_status' => 'active',
        'hired_at' => '2026-01-15',
    ])->assertOk()
        ->assertJsonPath('data.full_name', 'Adaobi Okafor');

    $this->deleteJson("/api/v1/employees/{$id}")->assertNoContent();
    expect(Employee::query()->find($id))->toBeNull();
});

test('employee validation catches missing names', function (): void {
    $this->postJson('/api/v1/employees', [
        'first_name' => '',
        'hired_at' => '2026-01-01',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['first_name', 'last_name']);
});

test('employees can be imported from the accepted csv template', function (): void {
    $department = Department::factory()->create(['name' => 'Engineering']);
    Position::factory()->create(['title' => 'Software Engineer', 'department_id' => $department->id]);

    $this->get('/api/v1/employees/import-template.xlsx')
        ->assertOk()
        ->assertDownload('employees-import-template.xlsx');

    $csv = implode("\n", [
        'employee_number,first_name,last_name,official_email,employment_status,hired_at,department,position',
        'SAMPLE-001,Ada,Example,sample@example.test,active,2026-01-01,Engineering,Software Engineer',
        ',Tola,Adeyemi,tola@example.test,active,2026-02-01,Engineering,Software Engineer',
    ]);

    $this->post('/api/v1/employees/import', [
        'file' => UploadedFile::fake()->createWithContent('employees.csv', $csv),
    ])->assertOk()
        ->assertJsonPath('data.imported', 2)
        ->assertJsonPath('data.skipped', 0);

    $employee = Employee::query()->where('official_email', 'tola@example.test')->firstOrFail();
    expect($employee->full_name)->toBe('Tola Adeyemi')
        ->and($employee->currentAssignment?->department?->name)->toBe('Engineering')
        ->and($employee->currentAssignment?->position?->title)->toBe('Software Engineer')
        ->and(Employee::query()->where('employee_number', 'SAMPLE-001')->exists())->toBeTrue();
});

test('employee numbers are unique per tenant sequence', function (): void {
    $this->postJson('/api/v1/employees', [
        'first_name' => 'Tenant',
        'last_name' => 'One',
        'hired_at' => '2026-01-01',
    ])->assertCreated()
        ->assertJsonPath('data.employee_number', 'EMP-0001');

    [$tenantB, $userB] = actingAsEmployeeOwner();

    $this->actingAs($userB);
    app(CurrentTenant::class)->set($tenantB);
    setPermissionsTeamId($tenantB->id);

    $this->postJson('/api/v1/employees', [
        'first_name' => 'Tenant',
        'last_name' => 'Two',
        'hired_at' => '2026-01-01',
    ])->assertCreated()
        ->assertJsonPath('data.employee_number', 'EMP-0001');
});

test('auto-numbering skips existing numbers when the newest employee has a custom number', function (): void {
    // Existing EMP-0001..EMP-0002, then imported/custom numbers as the newest rows.
    Employee::factory()->create(['employee_number' => 'EMP-0001', 'hired_at' => '2026-01-01']);
    Employee::factory()->create(['employee_number' => 'EMP-0002', 'hired_at' => '2026-01-01']);
    Employee::factory()->create(['employee_number' => 'SAMPLE-001', 'hired_at' => '2026-01-01']);
    Employee::factory()->create(['employee_number' => 'SAMPLE-002', 'hired_at' => '2026-01-01']);

    // A soft-deleted EMP number still owns its slot in the unique index.
    Employee::factory()->create(['employee_number' => 'EMP-0003', 'hired_at' => '2026-01-01'])->delete();

    $this->postJson('/api/v1/employees', [
        'first_name' => 'Next',
        'last_name' => 'Hire',
        'hired_at' => '2026-01-01',
    ])->assertCreated()
        ->assertJsonPath('data.employee_number', 'EMP-0004');
});

test('assignment history closes the old current record', function (): void {
    $departmentA = Department::factory()->create(['name' => 'Finance']);
    $departmentB = Department::factory()->create(['name' => 'Engineering']);
    $employee = Employee::factory()->create(['employee_number' => 'EMP-0001']);

    $this->postJson("/api/v1/employees/{$employee->id}/assignments", [
        'department_id' => $departmentA->id,
        'effective_from' => '2026-01-01',
    ])->assertCreated()
        ->assertJsonPath('data.department.id', $departmentA->id);

    $this->postJson("/api/v1/employees/{$employee->id}/assignments", [
        'department_id' => $departmentB->id,
        'effective_from' => '2026-03-01',
    ])->assertCreated()
        ->assertJsonPath('data.department.id', $departmentB->id);

    $records = EmployeeEmploymentRecord::query()
        ->where('employee_id', $employee->id)
        ->orderBy('effective_from')
        ->get();

    expect($records)->toHaveCount(2)
        ->and($records[0]->is_current)->toBeFalse()
        ->and($records[0]->effective_to->format('Y-m-d'))->toBe('2026-02-28')
        ->and($records[1]->is_current)->toBeTrue()
        ->and($records[1]->effective_to)->toBeNull();
});
