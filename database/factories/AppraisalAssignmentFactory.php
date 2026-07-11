<?php

namespace Database\Factories;

use App\Modules\Employee\Models\Employee;
use App\Modules\Performance\Models\AppraisalAssignment;
use App\Modules\Performance\Models\AppraisalCycle;
use App\Modules\Performance\Models\AppraisalTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AppraisalAssignment> */
class AppraisalAssignmentFactory extends Factory
{
    protected $model = AppraisalAssignment::class;
    public function definition(): array { return ['appraisal_cycle_id' => AppraisalCycle::factory(), 'appraisal_template_id' => AppraisalTemplate::factory(), 'employee_id' => Employee::factory(), 'status' => 'pending']; }
}
