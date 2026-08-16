<?php

namespace App\Modules\Reports\Requests;

use App\Modules\Attendance\Models\AttendanceSummary;
use App\Modules\Employee\Models\Employee;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Performance\Models\AppraisalResult;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Vacancy;
use App\Support\Authorization\Permissions;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportAnalysisRequest extends FormRequest
{
    /** @var array<string, string> */
    private const SOURCE_PERMISSIONS = [
        'workforce' => Permissions::EMPLOYEES_VIEW,
        'attendance' => Permissions::ATTENDANCE_VIEW,
        'leave' => Permissions::LEAVE_VIEW,
        'payroll' => Permissions::PAYROLL_VIEW,
        'performance' => Permissions::PERFORMANCE_VIEW,
        'recruitment' => Permissions::RECRUITMENT_VIEW,
    ];

    public function authorize(): bool
    {
        $user = $this->user();
        $sourcePermission = self::SOURCE_PERMISSIONS[$this->route('module')] ?? null;

        return $user !== null
            && $sourcePermission !== null
            && $user->can(Permissions::REPORTS_VIEW)
            && $user->can($sourcePermission);
    }

    public function rules(): array
    {
        $module = (string) $this->route('module');
        $year = (int) $this->input('year', now()->year);
        $tenantId = (int) $this->user()->tenant_id;
        $supportsDepartment = in_array($module, ['workforce', 'attendance', 'leave', 'performance', 'recruitment'], true);
        $supportsPeriod = in_array($module, ['attendance', 'leave', 'payroll', 'performance', 'recruitment'], true);

        return [
            'year' => ['sometimes', 'integer', 'min:2000', 'max:'.(now()->year + 1)],
            'department_id' => [
                'sometimes',
                'nullable',
                Rule::prohibitedIf(! $supportsDepartment),
                'integer',
                Rule::exists('departments', 'id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')),
            ],
            'period' => [
                'sometimes',
                'nullable',
                Rule::prohibitedIf(! $supportsPeriod),
                'date_format:Y-m',
                function (string $attribute, mixed $value, Closure $fail) use ($year): void {
                    if (is_string($value) && ! str_starts_with($value, $year.'-')) {
                        $fail('The selected period must belong to the reporting year.');
                    }
                },
            ],
            'status' => ['sometimes', 'nullable', Rule::in($this->statuses($module))],
            'stage' => [
                'sometimes',
                'nullable',
                Rule::prohibitedIf($module !== 'recruitment'),
                Rule::in(Application::STAGES),
            ],
        ];
    }

    /** @return list<string> */
    private function statuses(string $module): array
    {
        return match ($module) {
            'workforce' => [Employee::STATUS_ACTIVE, Employee::STATUS_ON_LEAVE, Employee::STATUS_SUSPENDED, Employee::STATUS_EXITED],
            'attendance' => [AttendanceSummary::STATUS_OPEN, AttendanceSummary::STATUS_FINALIZED],
            'leave' => [LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_REJECTED, LeaveRequest::STATUS_CANCELLED],
            'payroll' => [
                PayrollRun::STATUS_DRAFT,
                PayrollRun::STATUS_CALCULATING,
                PayrollRun::STATUS_REVIEW,
                PayrollRun::STATUS_PENDING_APPROVAL,
                PayrollRun::STATUS_APPROVED,
                PayrollRun::STATUS_LOCKED,
                PayrollRun::STATUS_PAID,
                PayrollRun::STATUS_REVERSED,
            ],
            'performance' => [
                AppraisalResult::STATUS_PENDING_CALIBRATION,
                AppraisalResult::STATUS_PENDING_APPROVAL,
                AppraisalResult::STATUS_APPROVED,
                AppraisalResult::STATUS_REJECTED,
                AppraisalResult::STATUS_ACKNOWLEDGED,
                AppraisalResult::STATUS_APPEALED,
                AppraisalResult::STATUS_APPEAL_RESOLVED,
            ],
            'recruitment' => [Vacancy::STATUS_DRAFT, Vacancy::STATUS_OPEN, Vacancy::STATUS_CLOSED],
            default => [],
        };
    }
}
