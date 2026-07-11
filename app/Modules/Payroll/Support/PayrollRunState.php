<?php

namespace App\Modules\Payroll\Support;

use App\Modules\Payroll\Models\PayrollRun;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Single source of truth for payroll run state transitions. Every status
 * change routes through here so the rules (especially "locked runs are
 * immutable") live in exactly one place.
 */
class PayrollRunState
{
    /** @var array<string, list<string>> allowed next statuses */
    private const TRANSITIONS = [
        PayrollRun::STATUS_DRAFT => [PayrollRun::STATUS_CALCULATING],
        PayrollRun::STATUS_CALCULATING => [PayrollRun::STATUS_REVIEW, PayrollRun::STATUS_DRAFT],
        PayrollRun::STATUS_REVIEW => [PayrollRun::STATUS_PENDING_APPROVAL, PayrollRun::STATUS_CALCULATING],
        PayrollRun::STATUS_PENDING_APPROVAL => [PayrollRun::STATUS_APPROVED, PayrollRun::STATUS_REVIEW],
        PayrollRun::STATUS_APPROVED => [PayrollRun::STATUS_LOCKED],
        PayrollRun::STATUS_LOCKED => [PayrollRun::STATUS_PAID, PayrollRun::STATUS_REVERSED],
        PayrollRun::STATUS_PAID => [PayrollRun::STATUS_REVERSED],
        PayrollRun::STATUS_REVERSED => [],
    ];

    public function transition(PayrollRun $run, string $to): void
    {
        $allowed = self::TRANSITIONS[$run->status] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw new ConflictHttpException(
                "Cannot move a payroll run from [{$run->status}] to [{$to}]."
            );
        }

        $run->update(['status' => $to]);
    }

    /** Guard mutations: recalculation/edits are only valid before approval. */
    public function assertMutable(PayrollRun $run): void
    {
        $mutable = [
            PayrollRun::STATUS_DRAFT,
            PayrollRun::STATUS_CALCULATING,
            PayrollRun::STATUS_REVIEW,
        ];

        if (! in_array($run->status, $mutable, true)) {
            throw new ConflictHttpException(
                "This payroll run is [{$run->status}] and can no longer be edited. Use a reversal or adjustment."
            );
        }
    }
}
