<?php

namespace App\Http\Controllers;

use App\Models\SupplierPayment;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\AccountingIntegrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SupplierPaymentController extends Controller
{
    protected $accountingService;

    public function __construct(AccountingIntegrationService $accountingService)
    {
        $this->middleware('auth:sanctum');
        $this->accountingService = $accountingService;
    }

    protected function hasPermission(Request $request, $permission, $resourceCompanyId = null)
    {
        $user = $request->user();
        if (!$user->role) {
            return false;
        }
        if ($user->role->hasPermission('can_manage_system')) {
            return true;
        }
        if ($user->role->hasPermission('can_manage_company')) {
            if ($resourceCompanyId !== null) {
                return $user->company_id === $resourceCompanyId;
            }
            return true;
        }
        return $user->role->hasPermission($permission);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$this->hasPermission($request, 'can_view_payments', $user->company_id)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized to view supplier payments.',
            ], 403);
        }

        $query = SupplierPayment::with(['supplier', 'purchaseOrder'])
            ->where('company_id', $user->company_id);

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->input('supplier_id'));
        }

        if ($request->filled('purchase_order_id')) {
            $query->where('purchase_order_id', $request->input('purchase_order_id'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('payment_date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('payment_date', '<=', $request->input('date_to'));
        }

        $payments = $query->orderBy('payment_date', 'desc')->paginate(20);

        return response()->json([
            'status' => 'success',
            'message' => 'Supplier payments retrieved successfully.',
            'data' => $payments,
        ], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if (!$this->hasPermission($request, 'can_create_payments', $user->company_id)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'supplier_id' => 'required|uuid|exists:suppliers,id',
            'purchase_order_id' => 'nullable|uuid|exists:purchase_orders,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_date' => 'required|date',
            'payment_method' => 'required|string',
            'transaction_reference' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $companyId = $user->company_id;
            $purchaseOrder = null;

            if ($request->filled('purchase_order_id')) {
                $purchaseOrder = PurchaseOrder::lockForUpdate()->find($request->input('purchase_order_id'));
                if ($purchaseOrder->company_id !== $companyId) {
                    return response()->json(['message' => 'Unauthorized PO access'], 403);
                }
            }

            // Create Payment Record
            $payment = SupplierPayment::create([
                'company_id' => $companyId,
                'supplier_id' => $request->input('supplier_id'),
                'purchase_order_id' => $request->input('purchase_order_id'),
                'amount' => $request->input('amount'),
                'payment_date' => $request->input('payment_date'),
                'payment_method' => $request->input('payment_method'),
                'transaction_reference' => $request->input('transaction_reference'),
                'notes' => $request->input('notes'),
                'status' => 'completed',
            ]);

            // Update Purchase Order if exists
            if ($purchaseOrder) {
                $newAmountPaid = $purchaseOrder->amount_paid + $payment->amount;
                // Ensure we don't exceed total (optional, but good practice. Maybe warn or allow overpayment?) 
                // For now, let's allow it but just update status.

                $status = 'unpaid';
                if ($newAmountPaid >= $purchaseOrder->total_amount && $purchaseOrder->total_amount > 0) {
                    $status = 'paid';
                } elseif ($newAmountPaid > 0) {
                    $status = 'partial';
                }

                $purchaseOrder->update([
                    'amount_paid' => $newAmountPaid,
                    'payment_status' => $status,
                ]);
            }

            // Accounting Integration
            try {
                $supplier = Supplier::find($request->input('supplier_id'));
                $this->accountingService->setCompany($companyId);
                $this->accountingService->setUser($user->id);

                $this->accountingService->recordSupplierPayment([
                    'company_id' => $companyId,
                    'supplier_id' => $supplier->id,
                    'supplier_name' => $supplier->supplier_name,
                    'amount' => $payment->amount,
                    'payment_method' => $payment->payment_method,
                    'date' => $payment->payment_date->toDateString(),
                    'reference' => $payment->payment_number,
                    'source_id' => $payment->id,
                    'source_type' => SupplierPayment::class,
                ]);
            } catch (\Exception $e) {
                // Should we fail the whole transaction if accounting fails?
                // Yes, to ensure data consistency.
                throw $e;
            }

            DB::commit();

            return response()->json([
                'message' => 'Payment recorded successfully',
                'payment' => $payment->load(['supplier', 'purchaseOrder']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create supplier payment', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to record payment: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        $payment = SupplierPayment::with(['supplier', 'purchaseOrder'])->find($id);

        if (!$payment) {
            return response()->json(['message' => 'Payment not found'], 404);
        }

        if (!$this->hasPermission($request, 'can_view_payments', $payment->company_id)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($payment->company_id !== $user->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($payment);
    }
}
