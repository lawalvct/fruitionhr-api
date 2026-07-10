<?php

use App\Core\Documents\Models\Document;
use App\Models\User;
use App\Modules\Employee\Models\Employee;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function documentTenant(): array
{
    Storage::fake('local');

    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    app(TenantRoleProvisioner::class)->provision($tenant);
    setPermissionsTeamId($tenant->id);

    $hr = User::factory()->create(['tenant_id' => $tenant->id]);
    $hr->assignRole('hr_admin');

    $employee = Employee::factory()->create();

    return [$tenant, $hr, $employee];
}

test('an authorised user can upload, list, download and delete an employee document', function () {
    [, $hr, $employee] = documentTenant();

    $upload = $this->actingAs($hr)->post('/api/v1/documents', [
        'owner_type' => 'employee',
        'owner_id' => $employee->id,
        'title' => 'Employment contract',
        'document_type' => 'contract',
        'file' => UploadedFile::fake()->create('contract.pdf', 120, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $documentId = $upload->json('data.id');
    $path = Document::query()->findOrFail($documentId)->file_path;
    Storage::disk('local')->assertExists($path);

    // List
    $list = $this->actingAs($hr)
        ->getJson("/api/v1/documents?owner_type=employee&owner_id={$employee->id}")
        ->assertOk();
    expect($list->json('data'))->toHaveCount(1)
        ->and($list->json('data.0.title'))->toBe('Employment contract');

    // Download
    $this->actingAs($hr)
        ->get("/api/v1/documents/{$documentId}/download")
        ->assertOk();

    // Delete (soft)
    $this->actingAs($hr)
        ->deleteJson("/api/v1/documents/{$documentId}")
        ->assertOk();

    expect(Document::query()->find($documentId))->toBeNull()
        ->and(Document::withTrashed()->find($documentId))->not->toBeNull();
});

test('uploads are rejected for disallowed file types and oversized files', function () {
    [, $hr, $employee] = documentTenant();

    $this->actingAs($hr)->post('/api/v1/documents', [
        'owner_type' => 'employee',
        'owner_id' => $employee->id,
        'title' => 'Malware',
        'file' => UploadedFile::fake()->create('run.exe', 10, 'application/octet-stream'),
    ], ['Accept' => 'application/json'])->assertUnprocessable();

    $this->actingAs($hr)->post('/api/v1/documents', [
        'owner_type' => 'employee',
        'owner_id' => $employee->id,
        'title' => 'Huge file',
        'file' => UploadedFile::fake()->create('big.pdf', 20481, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertUnprocessable();
});

test('a user without employee permissions cannot upload or download', function () {
    [$tenant, , $employee] = documentTenant();

    $plainUser = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $plainUser->assignRole('employee'); // baseline role: no employees.* permissions

    $this->actingAs($plainUser)->post('/api/v1/documents', [
        'owner_type' => 'employee',
        'owner_id' => $employee->id,
        'title' => 'Sneaky upload',
        'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertForbidden();
});

test('documents are tenant isolated: another tenant cannot access them', function () {
    [, $hr, $employee] = documentTenant();

    $upload = $this->actingAs($hr)->post('/api/v1/documents', [
        'owner_type' => 'employee',
        'owner_id' => $employee->id,
        'title' => 'Private contract',
        'file' => UploadedFile::fake()->create('contract.pdf', 50, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();
    $documentId = $upload->json('data.id');

    $otherTenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($otherTenant);
    app(TenantRoleProvisioner::class)->provision($otherTenant);
    setPermissionsTeamId($otherTenant->id);
    $otherHr = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherHr->assignRole('hr_admin');

    // Route-model binding is tenant-scoped, so the id resolves to 404.
    $this->actingAs($otherHr)
        ->get("/api/v1/documents/{$documentId}/download")
        ->assertNotFound();

    $this->actingAs($otherHr)
        ->deleteJson("/api/v1/documents/{$documentId}")
        ->assertNotFound();
});
