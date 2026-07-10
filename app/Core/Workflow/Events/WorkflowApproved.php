<?php

namespace App\Core\Workflow\Events;

use App\Core\Workflow\Models\WorkflowRequest;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when the final step approves a request. Owning modules listen and
 * apply their domain effect (e.g. Leave updates balances + attendance).
 */
class WorkflowApproved
{
    use Dispatchable;

    public function __construct(public readonly WorkflowRequest $request)
    {
    }
}
