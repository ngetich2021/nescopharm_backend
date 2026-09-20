<?php

namespace App\Services;

use App\Models\Customer;
use Carbon\Carbon;

/**
 * Works out payment_type/credit_terms_days/due_date/payment_terms for a new
 * order or invoice from the customer's registered payment method and credit
 * terms, unless the caller explicitly supplied a due_date/payment_terms to
 * override it. Shared so orders and invoices stay consistent about which
 * customers are eligible for credit and on what terms.
 */
class CustomerCreditTermsResolver
{
    public function resolve(
        Customer $customer,
        string $referenceDate,
        ?string $explicitDueDate = null,
        ?string $explicitPaymentTerms = null,
    ): array {
        $isCredit = $customer->payment_method === 'credit';
        $creditDays = $isCredit ? $customer->account?->credit_days : null;

        if ($isCredit) {
            $days = $creditDays ?? 30;
            $dueDate = $explicitDueDate ?? Carbon::parse($referenceDate)->addDays($days)->toDateString();
            $paymentTerms = $explicitPaymentTerms ?? "Net {$days}";
        } else {
            $dueDate = $explicitDueDate ?? $referenceDate;
            $paymentTerms = $explicitPaymentTerms ?? 'Due on Receipt';
        }

        return [
            'payment_type' => $isCredit ? 'credit' : 'cash',
            'credit_terms_days' => $isCredit ? $creditDays : null,
            'due_date' => $dueDate,
            'payment_terms' => $paymentTerms,
        ];
    }
}
