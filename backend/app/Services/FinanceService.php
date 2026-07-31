<?php

namespace App\Services;

use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\Loan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Company financing: take loans (cash now, interest accrues each tick), and
 * repay them. Outstanding balances are mirrored into Company::debt.
 */
class FinanceService
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function takeLoan(Company $company, int $principalCents): Loan
    {
        $cfg = config('transoria.finance');
        $max = (int) ($company->cash * $cfg['max_loan_multiple']);
        $max = max($cfg['min_loan'], $max);

        if ($principalCents < $cfg['min_loan']) {
            throw new RuntimeException('Minimum loan is ₡'.number_format($cfg['min_loan'] / 100).'.');
        }
        if ($principalCents > $max) {
            throw new RuntimeException('That exceeds your borrowing limit.');
        }

        return DB::transaction(function () use ($company, $principalCents, $cfg) {
            $loan = Loan::create([
                'company_id' => $company->id,
                'principal' => $principalCents,
                'balance' => $principalCents,
                'interest_rate' => $cfg['interest_per_tick'],
                'status' => Loan::STATUS_ACTIVE,
            ]);

            $this->ledger->post($company, LedgerEntry::CAT_LOAN, 'Loan disbursed', $principalCents, $loan);
            $this->syncDebt($company);

            return $loan;
        });
    }

    public function repay(Company $company, Loan $loan, int $amountCents): Loan
    {
        if ($loan->company_id !== $company->id || $loan->status !== Loan::STATUS_ACTIVE) {
            throw new RuntimeException('That loan cannot be repaid.');
        }
        $amountCents = min($amountCents, $loan->balance);
        if ($amountCents < 1) {
            throw new RuntimeException('Enter a valid amount.');
        }
        if ($company->cash < $amountCents) {
            throw new RuntimeException('Not enough cash to repay that much.');
        }

        DB::transaction(function () use ($company, $loan, $amountCents) {
            $this->ledger->post($company, LedgerEntry::CAT_LOAN, 'Loan repayment', -$amountCents, $loan);
            $loan->balance -= $amountCents;
            if ($loan->balance <= 0) {
                $loan->balance = 0;
                $loan->status = Loan::STATUS_REPAID;
            }
            $loan->save();
            $this->syncDebt($company);
        });

        return $loan->fresh();
    }

    /** Accrue one tick of interest across all active loans (called by the tick). */
    public function accrueInterest(Company $company): void
    {
        $loans = $company->loans()->where('status', Loan::STATUS_ACTIVE)->get();
        foreach ($loans as $loan) {
            $interest = (int) ceil($loan->balance * $loan->interest_rate);
            $loan->balance += $interest;
            $loan->save();
        }
        if ($loans->isNotEmpty()) {
            $this->syncDebt($company);
        }
    }

    private function syncDebt(Company $company): void
    {
        $company->debt = (int) $company->loans()->where('status', Loan::STATUS_ACTIVE)->sum('balance');
        $company->save();
    }
}
