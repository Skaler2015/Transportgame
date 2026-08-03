<?php

namespace App\Services;

use App\Models\Company;
use App\Models\LedgerEntry;
use Illuminate\Database\Eloquent\Model;

/**
 * The single authority for moving a company's money. Nothing else should
 * mutate Company::cash directly — every credit/debit is journaled here so
 * balances are reconstructable and tamper-evident (server-authoritative).
 */
class LedgerService
{
    /**
     * Post a signed amount (cents) against a company and return the entry.
     * Positive = income, negative = expense. Updates cash and lifetime totals.
     */
    public function post(
        Company $company,
        string $category,
        string $description,
        int $amount,
        ?Model $source = null,
    ): LedgerEntry {
        $company->cash += $amount;

        if ($amount > 0) {
            $company->lifetime_revenue += $amount;
        } else {
            $company->lifetime_expenses += -$amount;
        }

        $company->save();

        return LedgerEntry::create([
            'company_id' => $company->id,
            'category' => $category,
            'description' => $description,
            'amount' => $amount,
            'balance_after' => $company->cash,
            'source_type' => $source ? $source::class : null,
            'source_id' => $source?->getKey(),
            'occurred_at' => now(),
        ]);
    }
}
