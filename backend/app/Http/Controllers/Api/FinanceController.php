<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesCompany;
use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Services\FinanceService;
use Illuminate\Http\Request;
use RuntimeException;

class FinanceController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly FinanceService $finance) {}

    public function index(Request $request)
    {
        $company = $this->company($request);
        $cfg = config('transoria.finance');

        $loans = Loan::where('company_id', $company->id)
            ->where('status', Loan::STATUS_ACTIVE)->get()
            ->map(fn (Loan $l) => [
                'id' => $l->id,
                'principal' => (int) $l->principal,
                'balance' => (int) $l->balance,
                'interest_rate' => (float) $l->interest_rate,
            ]);

        return response()->json([
            'loans' => $loans,
            'debt' => (int) $company->debt,
            'cash' => (int) $company->cash,
            'borrow_limit' => max($cfg['min_loan'], (int) ($company->cash * $cfg['max_loan_multiple'])),
            'min_loan' => (int) $cfg['min_loan'],
            'interest_per_tick' => (float) $cfg['interest_per_tick'],
        ]);
    }

    public function borrow(Request $request)
    {
        $company = $this->company($request);
        $data = $request->validate(['amount' => ['required', 'integer', 'min:1']]);

        try {
            $this->finance->takeLoan($company, $data['amount']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Loan disbursed to your account.'], 201);
    }

    public function repay(Request $request, Loan $loan)
    {
        $company = $this->company($request);
        $data = $request->validate(['amount' => ['required', 'integer', 'min:1']]);

        try {
            $this->finance->repay($company, $loan, $data['amount']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Repayment applied.']);
    }
}
