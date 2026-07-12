<?php

namespace App\Modules\Employee\Imports;

use App\Modules\Company\Models\Branch;
use App\Modules\Company\Models\Department;
use App\Modules\Company\Models\EmploymentType;
use App\Modules\Company\Models\JobGrade;
use App\Modules\Company\Models\Position;
use App\Modules\Employee\Actions\CreateEmployee;
use App\Modules\Employee\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

class EmployeesImport implements ToCollection, WithHeadingRow
{
    public int $imported = 0;

    public int $skipped = 0;

    /** @var list<string> */
    public array $errors = [];

    public function __construct(private readonly int $userId)
    {
    }

    public function collection(Collection $rows): void
    {
        $employeeNumbers = Employee::query()->pluck('id', 'employee_number');
        $branches = $this->lookup(Branch::query()->get());
        $departments = $this->lookup(Department::query()->get());
        $positions = $this->lookup(Position::query()->get());
        $jobGrades = $this->lookup(JobGrade::query()->get());
        $employmentTypes = $this->lookup(EmploymentType::query()->get());

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $firstName = $this->text($row['first_name'] ?? null);
            $lastName = $this->text($row['last_name'] ?? null);
            $hireDate = $this->date($row['hired_at'] ?? null);

            if ($firstName === null && $lastName === null && $hireDate === null) {
                continue;
            }

            if ($firstName === null || $lastName === null || $hireDate === null) {
                $this->reject($rowNumber, 'first_name, last_name, and a valid hired_at date are required.');
                continue;
            }

            $number = $this->text($row['employee_number'] ?? null);
            if ($number !== null && $employeeNumbers->has($number)) {
                $this->reject($rowNumber, "employee_number '{$number}' already exists.");
                continue;
            }

            $status = strtolower($this->text($row['employment_status'] ?? null) ?? Employee::STATUS_ACTIVE);
            if (! in_array($status, [Employee::STATUS_ACTIVE, Employee::STATUS_ON_LEAVE, Employee::STATUS_SUSPENDED, Employee::STATUS_EXITED], true)) {
                $this->reject($rowNumber, "invalid employment_status '{$status}'.");
                continue;
            }

            $branch = $this->resolve($branches, $row['branch'] ?? null);
            $department = $this->resolve($departments, $row['department'] ?? null);
            $position = $this->resolve($positions, $row['position'] ?? null);
            $jobGrade = $this->resolve($jobGrades, $row['job_grade'] ?? null);
            $employmentType = $this->resolve($employmentTypes, $row['employment_type'] ?? null);

            foreach ([['branch', $row['branch'] ?? null, $branch], ['department', $row['department'] ?? null, $department], ['position', $row['position'] ?? null, $position], ['job_grade', $row['job_grade'] ?? null, $jobGrade], ['employment_type', $row['employment_type'] ?? null, $employmentType]] as [$field, $raw, $model]) {
                if ($this->text($raw) !== null && $model === null) {
                    $this->reject($rowNumber, "unknown {$field} '{$raw}'.");
                    continue 2;
                }
            }

            if ($position && $department && $position->department_id && $position->department_id !== $department->id) {
                $this->reject($rowNumber, "position '{$position->title}' does not belong to department '{$department->name}'.");
                continue;
            }

            try {
                $employee = DB::transaction(fn () => app(CreateEmployee::class)->execute(
                    array_filter([
                        'employee_number' => $number,
                        'first_name' => $firstName,
                        'middle_name' => $this->text($row['middle_name'] ?? null),
                        'last_name' => $lastName,
                        'official_email' => $this->text($row['official_email'] ?? null),
                        'personal_email' => $this->text($row['personal_email'] ?? null),
                        'phone' => $this->text($row['phone'] ?? null),
                        'gender' => $this->text($row['gender'] ?? null),
                        'date_of_birth' => $this->date($row['date_of_birth'] ?? null),
                        'marital_status' => $this->text($row['marital_status'] ?? null),
                        'country' => $this->text($row['country'] ?? null),
                        'state' => $this->text($row['state'] ?? null),
                        'city' => $this->text($row['city'] ?? null),
                        'address' => $this->text($row['address'] ?? null),
                        'employment_status' => $status,
                        'hired_at' => $hireDate,
                    ], fn ($value) => $value !== null),
                    [
                        'branch_id' => $branch?->id,
                        'department_id' => $department?->id,
                        'position_id' => $position?->id,
                        'job_grade_id' => $jobGrade?->id,
                        'employment_type_id' => $employmentType?->id,
                        'effective_from' => $hireDate,
                    ],
                    $this->userId,
                ));
                $employeeNumbers->put($employee->employee_number, $employee->id);
                $this->imported++;
            } catch (Throwable $exception) {
                report($exception);
                $this->reject($rowNumber, 'could not be imported. Check duplicate values and field formats.');
            }
        }
    }

    private function lookup(Collection $models): Collection
    {
        $lookup = collect();
        foreach ($models as $model) {
            $attributes = $model->getAttributes();
            $label = $attributes['name'] ?? $attributes['title'] ?? null;
            $code = $attributes['code'] ?? null;

            if ($label !== null) {
                $lookup->put(strtolower(trim((string) $label)), $model);
            }
            if ($code !== null && trim((string) $code) !== '') {
                $lookup->put(strtolower(trim((string) $code)), $model);
            }
        }
        return $lookup;
    }

    private function resolve(Collection $lookup, mixed $value): mixed
    {
        $key = $this->text($value);
        return $key === null ? null : $lookup->get(strtolower($key));
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        try {
            if (is_numeric($value)) return Carbon::createFromTimestamp(ExcelDate::excelToTimestamp((float) $value))->toDateString();
            return Carbon::parse((string) $value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function reject(int $row, string $message): void
    {
        $this->skipped++;
        $this->errors[] = "Row {$row}: {$message}";
    }
}
