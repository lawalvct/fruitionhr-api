<?php

namespace App\Modules\Tenancy\Actions;

use App\Core\Notifications\NotificationTemplates;
use App\Core\Workflow\WorkflowProvisioner;
use App\Models\User;
use App\Modules\Payroll\Support\StatutoryProvisioner;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Company sign-up: creates the tenant, provisions default roles, default
 * approval workflows, default statutory rules, and creates the owner user —
 * atomically.
 */
class RegisterTenant
{
    public function __construct(
        private readonly TenantRoleProvisioner $roleProvisioner,
        private readonly WorkflowProvisioner $workflowProvisioner,
        private readonly StatutoryProvisioner $statutoryProvisioner,
    ) {
    }

    /**
     * @param array{company_name: string, name: string, email: string, phone?: ?string, password: string} $input
     */
    public function execute(array $input): User
    {
        return DB::transaction(function () use ($input): User {
            $tenant = Tenant::query()->create([
                'name' => $input['company_name'],
                'slug' => $this->uniqueSlug($input['company_name']),
                'email' => $input['email'],
                'phone' => $input['phone'] ?? null,
                'status' => Tenant::STATUS_ACTIVE,
            ]);

            $this->roleProvisioner->provision($tenant);
            $this->workflowProvisioner->provision($tenant);
            $this->statutoryProvisioner->provision($tenant);

            $user = User::query()->create([
                'tenant_id' => $tenant->id,
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
                'status' => User::STATUS_ACTIVE,
            ]);

            setPermissionsTeamId($tenant->id);
            $user->assignRole('owner');

            // Greet the new owner with an in-app notification (database channel,
            // sent synchronously so it's waiting in their bell on first login).
            $user->notify(NotificationTemplates::make('welcome', [
                'name' => Str::of($input['name'])->trim()->explode(' ')->first(),
                'company' => $tenant->name,
            ]));

            return $user;
        });
    }

    private function uniqueSlug(string $companyName): string
    {
        $base = Str::slug($companyName) ?: 'company';
        $slug = $base;
        $suffix = 1;

        while (Tenant::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.++$suffix;
        }

        return $slug;
    }
}
