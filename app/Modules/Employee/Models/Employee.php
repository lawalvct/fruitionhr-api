<?php

namespace App\Modules\Employee\Models;

use App\Models\User;
use App\Modules\Payroll\Models\EmployeeSalary;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'employee_number',
    'user_id',
    'first_name',
    'middle_name',
    'last_name',
    'official_email',
    'personal_email',
    'phone',
    'gender',
    'date_of_birth',
    'marital_status',
    'address',
    'city',
    'state',
    'country',
    'photo_path',
    'employment_status',
    'hired_at',
    'exited_at',
    'created_by',
])]
class Employee extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected static string $factory = EmployeeFactory::class;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ON_LEAVE = 'on_leave';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_EXITED = 'exited';

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date:Y-m-d',
            'hired_at' => 'date:Y-m-d',
            'exited_at' => 'date:Y-m-d',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employmentRecords(): HasMany
    {
        return $this->hasMany(EmployeeEmploymentRecord::class);
    }

    public function currentAssignment(): HasOne
    {
        return $this->hasOne(EmployeeEmploymentRecord::class)->where('is_current', true);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(EmployeeContact::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(EmployeeBankAccount::class);
    }

    public function statutoryDetails(): HasOne
    {
        return $this->hasOne(EmployeeStatutoryDetail::class);
    }

    public function currentSalary(): HasOne
    {
        $today = today()->toDateString();

        return $this->hasOne(EmployeeSalary::class)
            ->whereDate('effective_from', '<=', $today)
            ->where(function ($query) use ($today): void {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today);
            })
            ->orderByDesc('effective_from');
    }

    public function getFullNameAttribute(): string
    {
        return collect([$this->first_name, $this->middle_name, $this->last_name])
            ->filter()
            ->join(' ');
    }
}
