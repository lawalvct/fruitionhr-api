<?php

namespace App\Modules\Attendance\Support;

/**
 * Per-day attendance outcome. A single primary status for display, plus the
 * minute figures payroll later consumes.
 */
final class DayStatus
{
    public const PRESENT = 'present';

    public const LATE = 'late';

    public const EARLY_EXIT = 'early_exit';

    public const ABSENT = 'absent';

    public const HOLIDAY = 'holiday';

    public const WEEKEND = 'weekend'; // non-working weekday per the shift

    public const ON_LEAVE = 'on_leave';

    public const NO_SHIFT = 'no_shift'; // employee has no shift assigned that day

    public function __construct(
        public readonly string $status,
        public readonly int $lateMinutes = 0,
        public readonly int $earlyMinutes = 0,
        public readonly int $overtimeMinutes = 0,
    ) {
    }

    /** A day that requires attendance (used to count working days). */
    public function isWorkingDay(): bool
    {
        return in_array($this->status, [
            self::PRESENT, self::LATE, self::EARLY_EXIT, self::ABSENT,
        ], true);
    }

    /** The employee actually showed up. */
    public function isPresent(): bool
    {
        return in_array($this->status, [self::PRESENT, self::LATE, self::EARLY_EXIT], true);
    }
}
