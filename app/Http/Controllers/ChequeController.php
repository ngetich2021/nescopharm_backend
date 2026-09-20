<?php

namespace App\Http\Controllers;

use App\Models\Cheque;
use App\Models\Invoice;
use App\Services\InvoicePaymentApplicationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ChequeController extends Controller
{
    protected InvoicePaymentApplicationService $paymentApplication;

    public function __construct(InvoicePaymentApplicationService $paymentApplication)
    {
        $this->middleware('auth:sanctum');
        $this->paymentApplication = $paymentApplication;
    }

    protected function hasPermission(Request $request, $permission, $resourceCompanyId = null)
    {
        $user = $request->user();
        $role = $user->role;
        if (!$role) {
            return false;
        }
        if ($role->hasPermission('can_manage_system')) {
            return true;
        }
        if ($role->hasPermission('can_manage_company')) {
            if ($resourceCompanyId !== null) {
                return $user->company_id === $resourceCompanyId;
            }
            return true;
        }
        return $role->hasPermission($permission);
    }

    /**
     * List PD cheques for the company, most-imminent maturity first.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$this->hasPermission($request, 'can_view_invoices', $user->company_id)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = Cheque::with(['customer:id,name', 'invoice:id,invoice_number'])
            ->where('company_id', $user->company_id);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }
        if ($request->filled('invoice_id')) {
            $query->where('invoice_id', $request->invoice_id);
        }

        $cheques = $query->orderBy('maturity_date')->get();

        return response()->json(['data' => $cheques]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$this->hasPermission($request, 'can_view_invoices', $user->company_id)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $cheque = Cheque::with(['customer:id,name', 'invoice:id,invoice_number'])
            ->where('company_id', $user->company_id)
            ->findOrFail($id);

        return response()->json(['data' => $cheque]);
    }

    /**
     * Record a post-dated cheque received against an invoice. Stays 'pending' and
     * does not touch the invoice balance until it is approved (i.e. matured/cleared).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$this->hasPermission($request, 'can_update_invoices', $user->company_id)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'invoice_id' => 'required|uuid',
            'cheque_number' => 'required|string|max:100',
            'bank_name' => 'required|string|max:150',
            'amount' => 'required|numeric|min:0.01',
            'issue_date' => 'required|date',
            'maturity_date' => 'required|date|after_or_equal:issue_date',
            'notes' => 'nullable|string',
            'attachment' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $invoice = Invoice::where('company_id', $user->company_id)->findOrFail($request->invoice_id);

        $balance = $invoice->total_amount - $invoice->getTotalAllocatedAmount();
        if ($request->amount > $balance) {
            return response()->json([
                'message' => 'Cheque amount exceeds invoice balance',
                'balance_due' => $balance,
            ], 400);
        }

        $attachmentPath = null;
        if ($request->hasFile('attachment')) {
            $attachmentPath = $request->file('attachment')->store('cheques', 's3');
        }

        $cheque = Cheque::create([
            'id' => Str::uuid(),
            'company_id' => $user->company_id,
            'customer_id' => $invoice->customer_id,
            'invoice_id' => $invoice->id,
            'cheque_number' => $request->cheque_number,
            'bank_name' => $request->bank_name,
            'amount' => $request->amount,
            'issue_date' => $request->issue_date,
            'maturity_date' => $request->maturity_date,
            'status' => 'pending',
            'notes' => $request->notes,
            'attachment_path' => $attachmentPath,
            'created_by' => $user->id,
        ]);

        return response()->json([
            'message' => 'Cheque recorded as pending. It will be applied to the invoice once approved.',
            'data' => $cheque,
        ], 201);
    }

    /**
     * Approve a matured cheque: applies it as a real payment against its invoice
     * (creating the Payment/allocation and accounting entry), i.e. it is now a receivable.
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$this->hasPermission($request, 'can_update_invoices', $user->company_id)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $cheque = Cheque::where('company_id', $user->company_id)->findOrFail($id);

        if ($cheque->status !== 'pending') {
            return response()->json(['message' => "Only pending cheques can be approved (current status: {$cheque->status})"], 400);
        }

        try {
            $invoice = Invoice::where('company_id', $user->company_id)->findOrFail($cheque->invoice_id);

            $result = $this->paymentApplication->applyPayment(
                $invoice,
                $user,
                (float) $cheque->amount,
                'cheque',
                $cheque->cheque_number,
                "Cheque {$cheque->cheque_number} ({$cheque->bank_name}), matured " . $cheque->maturity_date->toDateString(),
                $cheque->maturity_date->toDateString(),
            );

            $cheque->update([
                'status' => 'approved',
                'payment_id' => $result['payment']->id,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);

            return response()->json([
                'message' => 'Cheque approved and applied to invoice',
                'data' => $cheque->fresh(['customer:id,name', 'invoice:id,invoice_number']),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            Log::error('Failed to approve cheque', ['cheque_id' => $cheque->id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to approve cheque', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Mark a pending cheque as bounced (dishonoured). Since it was never applied
     * to the invoice, no reversal of payments/accounting entries is needed.
     */
    public function bounce(Request $request, string $id): JsonResponse
    {
        return $this->transitionPendingOnly($request, $id, 'bounced');
    }

    /**
     * Cancel a pending cheque (e.g. recorded in error, or returned to the customer).
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        return $this->transitionPendingOnly($request, $id, 'cancelled');
    }

    protected function transitionPendingOnly(Request $request, string $id, string $newStatus): JsonResponse
    {
        $user = $request->user();
        if (!$this->hasPermission($request, 'can_update_invoices', $user->company_id)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $cheque = Cheque::where('company_id', $user->company_id)->findOrFail($id);

        if ($cheque->status !== 'pending') {
            return response()->json(['message' => "Only pending cheques can be marked as {$newStatus} (current status: {$cheque->status})"], 400);
        }

        $cheque->update(['status' => $newStatus]);

        return response()->json([
            'message' => "Cheque marked as {$newStatus}",
            'data' => $cheque->fresh(['customer:id,name', 'invoice:id,invoice_number']),
        ]);
    }
}
