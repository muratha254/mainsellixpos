<?php

namespace App\Services;

use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\MoneyEntry;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Supplier;
use Illuminate\Support\Collection;

class AccountingLedger
{
    public function accountBalance(LedgerAccount $account, ?string $asOf = null): float
    {
        $opening = (float) $account->opening_balance;
        $journalDebit = (float) $account->journalLines()
            ->whereHas('entry', function ($query) use ($asOf) {
                if ($asOf) {
                    $query->whereDate('entry_date', '<=', $asOf);
                }
            })
            ->sum('debit');
        $journalCredit = (float) $account->journalLines()
            ->whereHas('entry', function ($query) use ($asOf) {
                if ($asOf) {
                    $query->whereDate('entry_date', '<=', $asOf);
                }
            })
            ->sum('credit');
        $moneyIn = (float) $account->moneyEntries()
            ->where('type', '!=', MoneyEntry::TYPE_OPEN_BAL)
            ->when($asOf, fn ($query) => $query->whereDate('payment_date', '<=', $asOf))
            ->sum('amount_in');
        $moneyOut = (float) $account->moneyEntries()
            ->where('type', '!=', MoneyEntry::TYPE_OPEN_BAL)
            ->when($asOf, fn ($query) => $query->whereDate('payment_date', '<=', $asOf))
            ->sum('amount_out');

        $raw = $opening + $journalDebit - $journalCredit + $moneyIn - $moneyOut;

        return $account->isDebitNormal() ? round($raw, 2) : round(-$raw, 2);
    }

    /**
     * @return array{debit:float,credit:float}
     */
    public function accountPeriod(LedgerAccount $account, ?string $from = null, ?string $to = null): array
    {
        $journal = JournalLine::query()
            ->where('ledger_account_id', $account->id)
            ->whereHas('entry', function ($query) use ($from, $to) {
                if ($from) {
                    $query->whereDate('entry_date', '>=', $from);
                }
                if ($to) {
                    $query->whereDate('entry_date', '<=', $to);
                }
            })
            ->selectRaw('COALESCE(SUM(debit),0) as debit, COALESCE(SUM(credit),0) as credit')
            ->first();

        $money = MoneyEntry::query()
            ->where('ledger_account_id', $account->id)
            ->where('type', '!=', MoneyEntry::TYPE_OPEN_BAL)
            ->when($from, fn ($query) => $query->whereDate('payment_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('payment_date', '<=', $to))
            ->selectRaw('COALESCE(SUM(amount_in),0) as amount_in, COALESCE(SUM(amount_out),0) as amount_out')
            ->first();

        return [
            'debit' => round((float) optional($journal)->debit + (float) optional($money)->amount_in, 2),
            'credit' => round((float) optional($journal)->credit + (float) optional($money)->amount_out, 2),
        ];
    }

    public function accountsWithBalances(?string $asOf = null): Collection
    {
        return LedgerAccount::query()
            ->with(['subType.accountType'])
            ->orderBy('name')
            ->get()
            ->map(function (LedgerAccount $account) use ($asOf) {
                $account->current_balance = $this->accountBalance($account, $asOf);

                return $account;
            });
    }

    /**
     * @return array{
     *     income:array<int,array<string,mixed>>,
     *     expenses:array<int,array<string,mixed>>,
     *     cogs:array<int,array<string,mixed>>,
     *     operating_expenses:array<int,array<string,mixed>>,
     *     income_total:float,
     *     cogs_total:float,
     *     gross_profit:float,
     *     operating_total:float,
     *     expense_total:float,
     *     net:float
     * }
     */
    public function profitAndLoss(string $from, string $to): array
    {
        $income = [];
        $hasSalesRevenue = false;

        foreach ($this->accountsWithType(['Income/Revenue']) as $account) {
            $period = $this->accountPeriod($account, $from, $to);
            $amount = round($period['credit'] - $period['debit'], 2);
            if ($account->name === 'Sales Revenue') {
                $hasSalesRevenue = true;
                // Journals already contain finalized sale revenue (net of VAT per posting).
                // Do not add sales-table totals on top — that double-counted revenue (e.g. 86.21 + 100 = 186.21).
                if (abs($amount) < 0.0001) {
                    $amount = $this->documentSalesNet($from, $to);
                }
            }
            if (abs($amount) < 0.0001) {
                continue;
            }
            $income[] = ['name' => $account->name, 'amount' => $amount];
        }

        if (! $hasSalesRevenue || ! collect($income)->contains(function ($row) {
            return ($row['name'] ?? '') === 'Sales Revenue';
        })) {
            $sales = $this->documentSalesNet($from, $to);
            if ($sales) {
                $income[] = ['name' => 'Sales Revenue', 'amount' => $sales];
            }
        }

        $income = $this->expandSalesWithVat($income, $from, $to);

        $cogs = [];
        $operating = [];
        $hasOperating = false;
        foreach ($this->accountsWithType(['EXPENSES']) as $account) {
            $period = $this->accountPeriod($account, $from, $to);
            $amount = round($period['debit'] - $period['credit'], 2);
            if (abs($amount) < 0.0001) {
                continue;
            }
            $row = ['name' => $account->name, 'amount' => $amount];
            if ($account->name === 'Cost Of Goods Sold') {
                $cogs[] = $row;
            } else {
                if ($account->name === 'Operating Expenses') {
                    $hasOperating = true;
                }
                $operating[] = $row;
            }
        }

        $recorded = $this->expenseTotal($from, $to);
        if ($recorded && ! $hasOperating) {
            $operating[] = ['name' => 'Recorded Expenses', 'amount' => $recorded];
        }

        $incomeTotal = round(collect($income)->sum('amount'), 2);
        $cogsTotal = round(collect($cogs)->sum('amount'), 2);
        $operatingTotal = round(collect($operating)->sum('amount'), 2);
        $expenseTotal = round($cogsTotal + $operatingTotal, 2);
        $gross = round($incomeTotal - $cogsTotal, 2);

        return [
            'income' => $income,
            'cogs' => $cogs,
            'operating_expenses' => $operating,
            'expenses' => array_merge($cogs, $operating),
            'income_total' => $incomeTotal,
            'cogs_total' => $cogsTotal,
            'gross_profit' => $gross,
            'operating_total' => $operatingTotal,
            'expense_total' => $expenseTotal,
            'net' => round($gross - $operatingTotal, 2),
        ];
    }

    /**
     * @return array{assets:array<int,array<string,mixed>>,liabilities:array<int,array<string,mixed>>,equity:array<int,array<string,mixed>>,asset_total:float,liability_total:float,equity_total:float,from:string,to:string,as_of:string}
     */
    public function balanceSheet(string $from, string $to): array
    {
        if ($from > $to) {
            $swap = $from;
            $from = $to;
            $to = $swap;
        }

        $assets = $this->groupBalances(['ASSETS'], $to);
        $liabilities = $this->groupBalances(['LIABILITIES'], $to);
        $equity = $this->groupBalances(['Equity Capital'], $to);

        $priorEnd = \Illuminate\Support\Carbon::parse($from)->subDay()->toDateString();
        $priorProfit = $from > '2000-01-01'
            ? (float) $this->profitAndLoss('2000-01-01', $priorEnd)['net']
            : 0.0;
        $periodProfit = (float) $this->profitAndLoss($from, $to)['net'];

        $this->addEquityLine($equity, 'Retained Earnings', $priorProfit);
        $this->addEquityLine($equity, 'Profit for the period', $periodProfit);

        $assetTotal = round(collect($assets)->sum('amount'), 2);
        $liabilityTotal = round(collect($liabilities)->sum('amount'), 2);
        $equityTotal = round(collect($equity)->sum('amount'), 2);

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'asset_total' => $assetTotal,
            'liability_total' => $liabilityTotal,
            'equity_total' => $equityTotal,
            'from' => $from,
            'to' => $to,
            'as_of' => $to,
            'period_profit' => round($periodProfit, 2),
        ];
    }

    /**
     * @param array<int, array{name:string,amount:float}> $equity
     */
    private function addEquityLine(array &$equity, string $name, float $amount): void
    {
        if (abs($amount) < 0.0001) {
            return;
        }
        foreach ($equity as &$row) {
            if (($row['name'] ?? '') === $name) {
                $row['amount'] = round((float) $row['amount'] + $amount, 2);

                return;
            }
        }
        unset($row);
        $equity[] = ['name' => $name, 'amount' => round($amount, 2)];
    }

    public function trialBalance(string $asOf): Collection
    {
        return LedgerAccount::query()
            ->with(['subType.accountType'])
            ->orderBy('gl_code')
            ->get()
            ->map(function (LedgerAccount $account) use ($asOf) {
                $period = $this->accountPeriod($account, null, $asOf);
                $opening = (float) $account->opening_balance;
                $debit = $period['debit'] + ($account->isDebitNormal() && $opening > 0 ? $opening : 0);
                $credit = $period['credit'] + ((! $account->isDebitNormal()) && $opening > 0 ? $opening : 0);
                if ($opening < 0) {
                    if ($account->isDebitNormal()) {
                        $credit += abs($opening);
                    } else {
                        $debit += abs($opening);
                    }
                }

                return [
                    'name' => $account->name,
                    'gl_code' => $account->gl_code,
                    'type' => optional(optional($account->subType)->accountType)->name,
                    'debit' => round($debit, 2),
                    'credit' => round($credit, 2),
                ];
            })
            ->filter(fn ($row) => abs($row['debit']) >= 0.0001 || abs($row['credit']) >= 0.0001)
            ->values();
    }

    public function combinedGl(string $from, string $to, ?int $accountId = null): Collection
    {
        $journals = JournalLine::query()
            ->with(['account', 'entry'])
            ->when($accountId, fn ($query) => $query->where('ledger_account_id', $accountId))
            ->whereHas('entry', function ($query) use ($from, $to) {
                $query->whereDate('entry_date', '>=', $from)->whereDate('entry_date', '<=', $to);
            })
            ->get()
            ->map(function (JournalLine $line) {
                return [
                    'date' => optional($line->entry)->entry_date,
                    'account' => optional($line->account)->name,
                    'reference' => optional($line->entry)->reference ?: optional($line->entry)->number,
                    'description' => $line->description ?: optional($line->entry)->description,
                    'debit' => (float) $line->debit,
                    'credit' => (float) $line->credit,
                ];
            });

        $money = MoneyEntry::query()
            ->with('account')
            ->when($accountId, fn ($query) => $query->where('ledger_account_id', $accountId))
            ->whereDate('payment_date', '>=', $from)
            ->whereDate('payment_date', '<=', $to)
            ->get()
            ->map(function (MoneyEntry $entry) {
                return [
                    'date' => $entry->payment_date,
                    'account' => optional($entry->account)->name,
                    'reference' => $entry->voucher_no ?: $entry->number,
                    'description' => $entry->description,
                    'debit' => (float) $entry->amount_in,
                    'credit' => (float) $entry->amount_out,
                ];
            });

        return $journals->concat($money)->sortBy('date')->values();
    }

    private function accountsWithType(array $names): Collection
    {
        return LedgerAccount::query()
            ->with(['subType.accountType'])
            ->whereHas('subType.accountType', function ($query) use ($names) {
                $query->whereIn('name', $names);
            })
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<int, string>  $types
     * @return array<int, array{name:string,amount:float}>
     */
    private function groupBalances(array $types, string $asOf): array
    {
        $rows = [];
        foreach ($this->accountsWithType($types) as $account) {
            $amount = $this->accountBalance($account, $asOf);
            if (abs($amount) < 0.0001) {
                continue;
            }
            $rows[] = ['name' => $account->name, 'amount' => $amount];
        }

        return $rows;
    }

    /**
     * Show till/sales-list totals, then deduct VAT so profit stays net of tax.
     *
     * @param  array<int, array<string, mixed>>  $income
     * @return array<int, array<string, mixed>>
     */
    private function expandSalesWithVat(array $income, string $from, string $to): array
    {
        $vat = $this->vatOutput($from, $to);
        $grossDocs = $this->documentSalesNet($from, $to);
        $out = [];
        $replaced = false;

        foreach ($income as $row) {
            if (! $replaced && ($row['name'] ?? '') === 'Sales Revenue') {
                $net = (float) $row['amount'];
                $gross = abs($grossDocs) > 0.009
                    ? $grossDocs
                    : round($net + $vat, 2);
                $out[] = ['name' => 'Sales', 'amount' => $gross];
                if (abs($vat) > 0.009) {
                    $out[] = ['name' => 'Less: VAT Output', 'amount' => round(-$vat, 2)];
                }
                $replaced = true;
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    private function vatOutput(string $from, string $to): float
    {
        $account = LedgerAccount::query()
            ->where('name', 'Tax Payable(VAT OUTPUT)')
            ->first();
        if (! $account) {
            return 0.0;
        }

        $period = $this->accountPeriod($account, $from, $to);

        return round((float) $period['credit'] - (float) $period['debit'], 2);
    }

    private function documentSalesNet(string $from, string $to): float
    {
        $sales = 0.0;
        $rows = Sale::query()
            ->whereIn('status', [Sale::STATUS_COMPLETED, Sale::STATUS_RETURNED])
            ->whereDate('sale_date', '>=', $from)
            ->whereDate('sale_date', '<=', $to)
            ->get(['subtotal', 'discount_amount', 'total']);
        foreach ($rows as $sale) {
            $original = round(max(0, (float) $sale->subtotal - (float) $sale->discount_amount), 2);
            if ($original <= 0) {
                $original = round((float) $sale->total, 2);
            }
            $sales += $original;
        }

        $credits = round((float) CreditNote::query()
            ->whereIn('status', [CreditNote::STATUS_POSTED, CreditNote::STATUS_COMPLETED])
            ->whereDate('credit_date', '>=', $from)
            ->whereDate('credit_date', '<=', $to)
            ->sum('total'), 2);
        $returns = round((float) SaleReturn::query()
            ->where('status', 'completed')
            ->whereDate('return_date', '>=', $from)
            ->whereDate('return_date', '<=', $to)
            ->sum('refund_amount'), 2);

        return round($sales - $credits - $returns, 2);
    }

    private function expenseTotal(string $from, string $to): float
    {
        return round((float) Expense::query()
            ->whereDate('expense_date', '>=', $from)
            ->whereDate('expense_date', '<=', $to)
            ->sum('amount'), 2);
    }

    public function customerRows(): Collection
    {
        return Customer::query()
            ->with('branch')
            ->withSum(['sales as credit_sales' => function ($query) {
                $query->where('status', Sale::STATUS_COMPLETED);
            }], 'balance')
            ->orderBy('name')
            ->get();
    }

    public function supplierRows(): Collection
    {
        return Supplier::query()->with('branch')->orderBy('name')->get();
    }
}
