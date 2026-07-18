<?php

namespace App\Modules\Payroll\Controllers;

use App\Modules\Payroll\Models\StaffLoan;
use App\Modules\Payroll\Requests\StoreStaffLoanRequest;
use App\Modules\Payroll\Resources\StaffLoanResource;
use App\Modules\Payroll\Services\LoanService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class LoanController extends Controller
{
    public function __construct(private readonly LoanService $service)
    {
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->can(Permissions::LOANS_VIEW), 403);

        $loans = StaffLoan::query()
            ->with('employee')
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('employee_id'), fn ($q, $id) => $q->where('employee_id', $id))
            ->latest('id')
            ->paginate(50);

        return StaffLoanResource::collection($loans);
    }

    public function store(StoreStaffLoanRequest $request)
    {
        $loan = $this->service->create($request->validated(), $request->user());

        return StaffLoanResource::make($loan->load('employee'))->response()->setStatusCode(201);
    }

    public function show(Request $request, StaffLoan $loan)
    {
        abort_unless($request->user()->can(Permissions::LOANS_VIEW), 403);

        return StaffLoanResource::make($loan->load('employee'));
    }

    public function update(StoreStaffLoanRequest $request, StaffLoan $loan)
    {
        $updated = $this->service->update($loan, $request->validated());

        return StaffLoanResource::make($updated->load('employee'));
    }

    public function destroy(Request $request, StaffLoan $loan)
    {
        abort_unless($request->user()->can(Permissions::LOANS_MANAGE), 403);

        if (! $loan->isEditable()) {
            throw new ConflictHttpException('Only draft or rejected loans can be deleted.');
        }

        $loan->delete();

        return response()->noContent();
    }

    public function submit(Request $request, StaffLoan $loan)
    {
        abort_unless($request->user()->can(Permissions::LOANS_MANAGE), 403);

        $this->service->submit($loan, $request->user());

        return StaffLoanResource::make($loan->refresh()->load('employee'));
    }

    /** Set (or clear) a one-time deduction for the coming run. amount=null → full balance. */
    public function planDeduction(Request $request, StaffLoan $loan)
    {
        abort_unless($request->user()->can(Permissions::LOANS_MANAGE), 403);

        $data = $request->validate([
            'amount' => ['nullable', 'integer', 'gt:0'],
        ]);

        $this->service->planNextDeduction($loan, $data['amount'] ?? null);

        return StaffLoanResource::make($loan->refresh()->load('employee'));
    }

    public function clearDeduction(Request $request, StaffLoan $loan)
    {
        abort_unless($request->user()->can(Permissions::LOANS_MANAGE), 403);

        $this->service->clearNextDeduction($loan);

        return StaffLoanResource::make($loan->refresh()->load('employee'));
    }

    /** Permanently change the monthly installment on an active loan. */
    public function setInstallment(Request $request, StaffLoan $loan)
    {
        abort_unless($request->user()->can(Permissions::LOANS_MANAGE), 403);

        $data = $request->validate([
            'amount' => ['required', 'integer', 'gt:0'],
        ]);

        $this->service->setInstallment($loan, $data['amount']);

        return StaffLoanResource::make($loan->refresh()->load('employee'));
    }

    public function repayments(Request $request, StaffLoan $loan)
    {
        abort_unless($request->user()->can(Permissions::LOANS_VIEW), 403);

        $rows = $loan->repayments()
            ->latest('id')
            ->get(['id', 'period', 'amount', 'balance_after', 'payroll_run_id', 'created_at']);

        return response()->json(['data' => $rows]);
    }
}
