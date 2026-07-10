<?php

namespace App\Modules\Employee\Actions;

use App\Modules\Employee\Models\Employee;
use App\Modules\Employee\Models\EmployeeEmploymentRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AssignEmployee
{
    public function execute(Employee $employee, array $data, ?int $createdBy = null): EmployeeEmploymentRecord
    {
        return DB::transaction(function () use ($employee, $data, $createdBy): EmployeeEmploymentRecord {
            $effectiveFrom = Carbon::parse($data['effective_from'])->toDateString();
            $previousEffectiveTo = Carbon::parse($effectiveFrom)->subDay()->toDateString();

            $employee->employmentRecords()
                ->where('is_current', true)
                ->update([
                    'is_current' => false,
                    'effective_to' => $previousEffectiveTo,
                ]);

            return $employee->employmentRecords()->create([
                ...$data,
                'effective_from' => $effectiveFrom,
                'effective_to' => null,
                'is_current' => true,
                'created_by' => $createdBy,
            ]);
        });
    }
}
