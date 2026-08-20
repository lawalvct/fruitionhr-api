<?php

namespace App\Modules\Attendance\Requests;

use App\Modules\Attendance\Support\DayStatus;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class BulkAttendanceLogRequest extends FormRequest
{
    /**
     * Only these are settable by hand. The rest of DayStatus is owned by other
     * sources — leave by the Leave module, holidays by the calendar, weekend
     * and no_shift by the shift configuration — so offering them here would
     * only let attendance contradict them.
     */
    public const SETTABLE = [DayStatus::PRESENT, DayStatus::LATE, DayStatus::ABSENT];

    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::ATTENDANCE_MANAGE) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'employee_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'employee_ids.*' => [
                'integer',
                Rule::exists('employees', 'id')->where('tenant_id', $tenantId),
            ],
            'status' => ['required', Rule::in(self::SETTABLE)],

            // Either a single date, or a from/to range — never both.
            'date' => ['required_without:from', 'prohibits:from', 'date_format:Y-m-d'],
            'from' => ['required_without:date', 'date_format:Y-m-d'],
            'to' => [
                'required_with:from',
                'date_format:Y-m-d',
                'after_or_equal:from',
                function (string $attribute, mixed $value, Closure $fail): void {
                    // One period per call: finalization, the grid and the
                    // summary tables are all scoped to a single YYYY-MM.
                    if (substr((string) $this->input('from'), 0, 7) !== substr((string) $value, 0, 7)) {
                        $fail('The date range must stay inside a single month.');
                    }
                },
            ],

            'clock_in' => ['nullable', 'date_format:H:i'],
            'clock_out' => ['nullable', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:255'],
            'overwrite' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Every date this call targets, expanded from either input shape.
     *
     * @return list<string>
     */
    public function dates(): array
    {
        if ($this->filled('date')) {
            return [$this->string('date')->value()];
        }

        $dates = [];
        $cursor = Carbon::createFromFormat('Y-m-d', $this->string('from')->value());
        $end = Carbon::createFromFormat('Y-m-d', $this->string('to')->value());

        while ($cursor->lte($end)) {
            $dates[] = $cursor->toDateString();
            $cursor->addDay();
        }

        return $dates;
    }

    /** The single YYYY-MM period this call belongs to. */
    public function period(): string
    {
        return substr($this->dates()[0], 0, 7);
    }
}
