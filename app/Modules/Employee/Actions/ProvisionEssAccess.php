<?php

namespace App\Modules\Employee\Actions;

use App\Models\User;
use App\Modules\Auth\Notifications\EssInvitationNotification;
use App\Modules\Employee\Models\Employee;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProvisionEssAccess
{
    public function execute(Employee $employee): User
    {
        $email = Str::lower(trim((string) ($employee->official_email ?: $employee->personal_email)));
        if ($email === '') {
            throw ValidationException::withMessages(['email' => 'Add an official or personal email address before enabling ESS access.']);
        }

        $tenant = app(CurrentTenant::class)->get();
        $user = DB::transaction(function () use ($employee, $email, $tenant): User {
            $linked = $employee->user()->first();
            if ($linked !== null) {
                return $linked;
            }

            $existing = User::query()->where('email', $email)->first();
            if ($existing !== null && (int) $existing->tenant_id !== (int) $tenant?->id) {
                throw ValidationException::withMessages(['email' => 'This email is already used by another FruitionHR account.']);
            }
            if ($existing?->employee()->exists()) {
                throw ValidationException::withMessages(['email' => 'This email is already linked to another employee.']);
            }

            $user = $existing ?? User::query()->create([
                'tenant_id' => $tenant?->id,
                'name' => $employee->full_name,
                'email' => $email,
                'password' => Str::random(64),
                'status' => User::STATUS_INVITED,
            ]);

            $employee->update(['user_id' => $user->id]);
            $user->assignRole('employee');

            return $user;
        });

        if ($user->status === User::STATUS_INVITED) {
            $token = Password::broker()->createToken($user);
            $setupUrl = rtrim((string) config('app.frontend_url'), '/')
                .'/setup-account?token='.urlencode($token).'&email='.urlencode($user->email);
            $user->notify(new EssInvitationNotification($setupUrl, $tenant?->name ?? 'Your company'));
        }

        return $user;
    }
}
