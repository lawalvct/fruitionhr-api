<?php

namespace App\Modules\Attendance\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'location', 'is_active', 'created_by'])]
class AttendanceKiosk extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
