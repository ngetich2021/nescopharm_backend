<?php

namespace App\Services;

use App\Models\Cheque;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use Carbon\Carbon;

class CustomerStatementService
{
    /**
     * A customer's account statement for an arbitrary period: opening
     * balance, every invoice/payment/credit-note/PD-cheque within the
     * period, and the resulting closing balance.
     *
     * Opening/closing balance are derived rather than stored: the
     * customer's current total outstanding balance is reconstructed back
     * to the period boundaries using invoices/payments/credit notes dated
     * after the period, then the period's own movements peel back further
     * to the opening figure.
     */
    public function generate(string $companyId, string $customerId, Carbon $from, Carbon $to): array
    {
        $customer = Customer::where('company_id', $companyId)->findOrFail($customerId);

        $invoices = Invoice::where('customer_id', $customerId)
            ->whereBetween('invoice_date', [$from, $to])
            ->orderBy('invoice_date')
            ->get(['id', 'invoice_number', 'invoice_date', 'total_amount', 'balance_amount']);

        $payments = Payment::where('customer_id', $customerId)
            ->where('status', 'completed')
            ->whereBetween('payment_date', [$from, $to])
            ->orderBy('payment_date')
            ->get(['id', 'transaction_id', 'payment_date', 'amount_paid', 'payment_method']);

        $creditNotes = CreditNote::where('customer_id', $customerId)
            ->whereIn('status', ['issued', 'applied', 'refunded'])
            ->whereBetween('credit_note_date', [$from, $to])
            ->orderBy('credit_note_date')
            ->get(['id', 'credit_note_number', 'credit_note_date', 'total_amount']);

        $pdCheques = Cheque::where('customer_id', $customerId)
            ->where('direction', 'received')
            ->whereBetween('issue_date', [$from, $to])
            ->orderBy('issue_date')
            ->get(['id', 'cheque_number', 'bank_name', 'issue_date', 'maturity_date', 'amount', 'status']);

        $currentBalance = (float) Invoice::where('customer_id', $customerId)->sum('balance_amount');

        $invoicesAfter = (float) Invoice::where('customer_id', $customerId)
            ->where('invoice_date', '>', $to)->sum('total_amount');
        $paymentsAfter = (float) Payment::where('customer_id', $customerId)
            ->where('status', 'completed')->where('payment_date', '>', $to)->sum('amount_paid');
        $creditNotesAfter = (float) CreditNote::where('customer_id', $customerId)
            ->whereIn('status', ['issued', 'applied', 'refunded'])
            ->where('credit_note_date', '>', $to)->sum('total_amount');

        // Roll the current balance back to what it was at the period end,
        // then back again to what it was at the period start.
        $closingBalance = $currentBalance - $invoicesAfter + $paymentsAfter + $creditNotesAfter;

        $invoicesTotal = (float) $invoices->sum('total_amount');
        $paymentsTotal = (float) $payments->sum('amount_paid');
        $creditNotesTotal = (float) $creditNotes->sum('total_amount');

        $openingBalance = $closingBalance - $invoicesTotal + $paymentsTotal + $creditNotesTotal;

        return [
            'customer' => $customer,
            // Sourced directly from the customer's own company_id, not a
            // separate frontend fetch gated by its own permission check -
            // the letterhead should always render, not "when available".
            'company' => Company::find($companyId, ['id', 'name', 'logo_url', 'letterhead_url']),
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'label' => $this->formatPeriodLabel($from, $to),
            ],
            'opening_balance' => round($openingBalance, 2),
            'closing_balance' => round($closingBalance, 2),
            'invoices' => $invoices,
            'payments' => $payments,
            'credit_notes' => $creditNotes,
            'pd_cheques' => $pdCheques,
            'summary' => [
                'invoices_count' => $invoices->count(),
                'invoices_total' => round($invoicesTotal, 2),
                'payments_count' => $payments->count(),
                'payments_total' => round($paymentsTotal, 2),
                'credit_notes_count' => $creditNotes->count(),
                'credit_notes_total' => round($creditNotesTotal, 2),
                'pd_cheques_count' => $pdCheques->count(),
                'pd_cheques_total' => round((float) $pdCheques->sum('amount'), 2),
            ],
        ];
    }

    /**
     * "1st - 30th September 2026" for a period within one calendar month,
     * or a full "1st January 2026 - 15th February 2026" style range
     * otherwise.
     */
    public function formatPeriodLabel(Carbon $from, Carbon $to): string
    {
        if ($from->month === $to->month && $from->year === $to->year) {
            return $from->format('jS') . ' - ' . $to->format('jS') . ' ' . $to->format('F Y');
        }

        return $from->format('jS F Y') . ' - ' . $to->format('jS F Y');
    }

    /**
     * Plain-text summary used for the auto-generated monthly note.
     */
    public function toText(array $statement): string
    {
        $summary = $statement['summary'];

        $lines = [
            'ACCOUNT STATEMENT - ' . $statement['period']['label'],
            '',
            'Opening balance: KES ' . number_format((float) $statement['opening_balance'], 2),
            "Invoices issued: {$summary['invoices_count']} (KES " . number_format($summary['invoices_total'], 2) . ')',
            "Payments received: {$summary['payments_count']} (KES " . number_format($summary['payments_total'], 2) . ')',
            "Credit notes issued: {$summary['credit_notes_count']} (KES " . number_format($summary['credit_notes_total'], 2) . ')',
            "PD cheques received: {$summary['pd_cheques_count']} (KES " . number_format($summary['pd_cheques_total'], 2) . ')',
            '',
            'Closing balance: KES ' . number_format((float) $statement['closing_balance'], 2),
        ];

        return implode("\n", $lines);
    }
}
