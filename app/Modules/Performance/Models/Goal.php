<?php

namespace App\Modules\Performance\Models;

use App\Models\User;
use App\Modules\Company\Models\Department;
use App\Modules\Employee\Models\Employee;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\GoalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['level', 'title', 'description', 'department_id', 'employee_id', 'parent_id', 'owner_user_id', 'weight', 'target_value', 'current_value', 'measurement_unit', 'progress', 'status', 'starts_at', 'due_at', 'created_by'])]
class Goal extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;
    protected static string $factory = GoalFactory::class;
    protected function casts(): array { return ['starts_at' => 'date:Y-m-d', 'due_at' => 'date:Y-m-d', 'weight' => 'integer', 'progress' => 'integer', 'target_value' => 'integer', 'current_value' => 'integer']; }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_user_id'); }
    public function parent(): BelongsTo { return $this->belongsTo(self::class, 'parent_id'); }
    public function checkins(): HasMany { return $this->hasMany(GoalCheckin::class); }
}
