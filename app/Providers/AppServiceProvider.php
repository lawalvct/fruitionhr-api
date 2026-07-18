<?php

namespace App\Providers;

use App\Core\Workflow\Events\WorkflowApproved;
use App\Core\Workflow\Events\WorkflowRejected;
use App\Modules\Employee\Models\Employee;
use App\Modules\Employee\Policies\EmployeePolicy;
use App\Modules\Leave\Listeners\ApplyLeaveWorkflowDecision;
use App\Modules\Payroll\Listeners\ApplyLoanWorkflowDecision;
use App\Modules\Payroll\Listeners\ApplyOvertimeWorkflowDecision;
use App\Modules\Payroll\Listeners\ApplyPayrollWorkflowDecision;
use App\Modules\Recruitment\Listeners\ApplyRequisitionWorkflowDecision;
use App\Modules\SelfService\Listeners\ApplyProfileUpdateWorkflowDecision;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped (not singleton): resets per request/job so tenant context can
        // never leak between requests under Octane or between queued jobs.
        $this->app->scoped(CurrentTenant::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Surface missing $fillable/relationship mistakes early in dev.
        Model::shouldBeStrict(! $this->app->isProduction());

        Gate::policy(Employee::class, EmployeePolicy::class);

        // Workflow engine → module bridges. Each listener no-ops for modules
        // it doesn't own, so registering many here is cheap.
        Event::listen(WorkflowApproved::class, [ApplyLeaveWorkflowDecision::class, 'approved']);
        Event::listen(WorkflowRejected::class, [ApplyLeaveWorkflowDecision::class, 'rejected']);
        Event::listen(WorkflowApproved::class, [ApplyPayrollWorkflowDecision::class, 'approved']);
        Event::listen(WorkflowRejected::class, [ApplyPayrollWorkflowDecision::class, 'rejected']);
        Event::listen(WorkflowApproved::class, [ApplyOvertimeWorkflowDecision::class, 'approved']);
        Event::listen(WorkflowRejected::class, [ApplyOvertimeWorkflowDecision::class, 'rejected']);
        Event::listen(WorkflowApproved::class, [ApplyLoanWorkflowDecision::class, 'approved']);
        Event::listen(WorkflowRejected::class, [ApplyLoanWorkflowDecision::class, 'rejected']);
        Event::listen(WorkflowApproved::class, [ApplyProfileUpdateWorkflowDecision::class, 'approved']);
        Event::listen(WorkflowRejected::class, [ApplyProfileUpdateWorkflowDecision::class, 'rejected']);
        Event::listen(WorkflowApproved::class, [ApplyRequisitionWorkflowDecision::class, 'approved']);
        Event::listen(WorkflowRejected::class, [ApplyRequisitionWorkflowDecision::class, 'rejected']);
    }
}
