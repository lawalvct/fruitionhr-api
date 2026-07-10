<?php

namespace App\Core\Workflow\Events;

use App\Core\Workflow\Models\WorkflowRequest;
use Illuminate\Foundation\Events\Dispatchable;

class WorkflowRejected
{
    use Dispatchable;

    public function __construct(public readonly WorkflowRequest $request)
    {
    }
}
