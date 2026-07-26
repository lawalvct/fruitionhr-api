<?php

namespace App\Modules\Attendance\Models;

use App\Modules\Employee\Models\Employee;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\AttendanceLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'date', 'clock_in', 'clock_out', 'source', 'note', 'kiosk_id', 'created_by'])]
class AttendanceLog extends Model
{
    use BelongsToTenant, HasFactory;

    protected static string $factory = AttendanceLogFactory::class;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_IMPORT = 'import';

    public const SOURCE_SELF = 'self';

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function kiosk(): BelongsTo
    {
        return $this->belongsTo(AttendanceKiosk::class);
    }
}
