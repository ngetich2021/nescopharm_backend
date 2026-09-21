<?php

namespace App\Http\Controllers;

use App\Models\Logistic;
use App\Models\Order;
use App\Models\DeliveryPerson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LogisticController extends Controller
{
    /**
     * Initialize the controller with middleware for authentication.
     */
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    protected function hasPermission(Request $request, $permission, $resourceCompanyId = null)
    {
        $user = $request->user();
        if (!$user) {
            return false;
        }
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
     * List all logistics records for the user's company.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthenticated. Please log in.',
            ], 401);
        }
        if (!$this->hasPermission($request, 'can_view_logistics', $user->company_id)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized to view logistics records.',
            ], 403);
        }

        // Eager load orderDispatch and its related order
        $query = Logistic::with(['orderDispatch.order', 'deliveryPerson']);
        $query->where('company_id', $user->company_id);

        if ($request->filled('delivery_status')) {
            $query->where('delivery_status', $request->input('delivery_status'));
        }
        if ($request->filled('delivery_person_id')) {
            $query->where('delivery_person_id', $request->input('delivery_person_id'));
        }

        // Filter by order_id via the orderDispatch relationship
        if ($request->filled('order_id')) {
            $query->whereHas('orderDispatch', function ($q) use ($request) {
                $q->where('order_id', $request->input('order_id'));
            });
        }

        // Filter by order_dispatch_id directly if provided
        if ($request->filled('order_dispatch_id')) {
            $query->where('order_dispatch_id', $request->input('order_dispatch_id'));
        }

        if ($request->filled('tracking_number')) {
            $query->where('tracking_number', 'like', '%' . $request->input('tracking_number') . '%');
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('recipient_name', 'ilike', "%{$search}%")
                  ->orWhere('recipient_phone', 'ilike', "%{$search}%")
                  ->orWhere('tracking_number', 'ilike', "%{$search}%")
                  ->orWhere('driver_name', 'ilike', "%{$search}%")
                  ->orWhere('delivery_address', 'ilike', "%{$search}%")
                  ->orWhereHas('orderDispatch', function ($q2) use ($search) {
                      $q2->where('dispatch_number', 'ilike', "%{$search}%");
                  });
            });
        }

        $perPage = $request->input('per_page', 15);
        $logistics = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'message' => 'Logistics records retrieved successfully.',
            'logistics' => $logistics->items(),
            'meta' => [
                'current_page' => $logistics->currentPage(),
                'last_page' => $logistics->lastPage(),
                'per_page' => $logistics->perPage(),
                'total' => $logistics->total(),
            ],
        ], 200);
    }

    /**
     * View details of a single logistics record.
     *
     * @param Request $request
     * @param string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthenticated. Please log in.',
            ], 401);
        }
        if (!$this->hasPermission($request, 'can_view_logistics', $user->company_id)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized to view logistics records.',
            ], 403);
        }
        $query = Logistic::where('id', $id)
            ->with(['orderDispatch.order', 'deliveryPerson', 'company'])
            ->where('company_id', $user->company_id);
        $logistic = $query->first();
        if (!$logistic) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Logistics record not found or not authorized.',
            ], 404);
        }
        return response()->json([
            'status' => 'success',
            'message' => 'Logistics record retrieved successfully.',
            'logistic' => $logistic,
        ], 200);
    }

    /**
     * Create a new logistics record for order dispatch.
     * This would typically be called from OrderController when an order is dispatched.
     *
     * @param Order $order
     * @param array $data
     * @return Logistic
     */
    public function storeForOrderDispatch(Order $order, array $data)
    {
        try {
            return DB::transaction(function () use ($order, $data) {
                $logistic = Logistic::create(array_merge($data, [
                    'id' => (string) Str::uuid(),
                    'order_id' => $order->id,
                    'company_id' => $order->company_id,
                    'dispatch_time' => now(),
                    'delivery_status' => $data['delivery_status'] ?? 'dispatched',
                ]));

                // Update order status if needed
                if (isset($data['update_order_status']) && $data['update_order_status']) {
                    $order->update(['status' => 'dispatched']);
                }

                return $logistic;
            });
        } catch (\Exception $e) {
            Log::error('Failed to create logistics record', [
                'error' => $e->getMessage(),
                'order_id' => $order->id
            ]);
            throw $e;
        }
    }

    /**
     * Update an existing logistics record.
     *
     * @param Request $request
     * @param string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthenticated. Please log in.',
            ], 401);
        }
        if (!$this->hasPermission($request, 'can_update_logistics', $user->company_id)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized to update logistics records.',
            ], 403);
        }
        $logistic = Logistic::find($id);
        if (!$logistic) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Logistics record not found.',
            ], 404);
        }
        if ($logistic->company_id !== $user->company_id) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized to update this logistics record.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'delivery_person_id' => 'sometimes|uuid|exists:delivery_persons,id',
            'logistics_provider' => 'sometimes|string|max:255',
            'delivery_method' => 'sometimes|string|max:255',
            'vehicle_type' => 'sometimes|string|max:255',
            'vehicle_id' => 'sometimes|string|max:255',
            'tracking_number' => 'sometimes|string|max:255',
            'delivery_status' => 'sometimes|string|in:dispatched,in_transit,delivered,failed,returned,cancelled',
            'recipient_name' => 'sometimes|string|max:255',
            'recipient_phone' => 'sometimes|string|max:50',
            'delivery_address' => 'sometimes|string',
            'delivery_location' => 'sometimes|string|max:255',
            'city' => 'sometimes|string|max:100',
            'state' => 'sometimes|string|max:100',
            'region' => 'sometimes|string|max:100',
            'country' => 'sometimes|string|max:100',
            'estimated_delivery_time' => 'sometimes|date',
            'actual_delivery_time' => 'sometimes|date',
            'signature' => 'sometimes',
            'notes' => 'sometimes|string',
            'update_order_status' => 'sometimes|boolean',
            'delivery_cost' => 'sometimes|numeric|min:0',
            'amount_paid' => 'sometimes|numeric|min:0',
            'payment_method' => 'sometimes|string|max:100',
            'payment_reference' => 'sometimes|string|max:255',
            'payment_date' => 'sometimes|date',
            'cheque_number' => 'sometimes|string|max:100',
            'bank_name' => 'sometimes|string|max:150',
            'cheque_maturity_date' => 'sometimes|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'failed',
                'message' => $validator->errors(),
            ], 400);
        }

        try {
            return DB::transaction(function () use ($request, $logistic) {
                $updates = $request->except(['update_order_status']);
                if ($request->has('delivery_cost') || $request->has('amount_paid')) {
                    $updates['payment_status'] = $this->resolvePaymentStatus(
                        $request->input('delivery_cost', $logistic->delivery_cost),
                        $request->input('amount_paid', $logistic->amount_paid)
                    );
                }
                $logistic->update($updates);

                // Update order status if requested
                if ($request->input('update_order_status', false)) {
                    $order = Order::find($logistic->order_id);
                    if ($order) {
                        $deliveryStatus = $request->input('delivery_status');

                        if ($deliveryStatus === 'delivered') {
                            $order->update(['status' => 'completed']);
                        } elseif (in_array($deliveryStatus, ['failed', 'returned', 'cancelled'])) {
                            $order->update(['status' => 'cancelled']);
                        } elseif ($deliveryStatus === 'in_transit') {
                            $order->update(['status' => 'dispatched']);
                        }
                    }
                }

                return response()->json([
                    'status' => 'success',
                    'message' => 'Logistics record updated successfully.',
                    'logistic' => $logistic->fresh(),
                ], 200);
            });
        } catch (\Exception $e) {
            Log::error('Failed to update logistics record', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 'failed',
                'message' => 'Failed to update logistics record: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a logistics record for manual dispatch.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthenticated. Please log in.',
            ], 401);
        }
        if (!$this->hasPermission($request, 'can_create_logistics', $user->company_id)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized to create logistics records.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'order_dispatch_id' => 'required|uuid|exists:order_dispatches,id',
            'delivery_person_id' => 'nullable|uuid|exists:delivery_persons,id',
            'logistics_provider' => 'nullable|string|max:255',
            'delivery_method' => 'nullable|string|max:255',
            'vehicle_type' => 'nullable|string|max:255',
            'vehicle_id' => 'nullable|string|max:255',
            'tracking_number' => 'nullable|string|max:255',
            'delivery_status' => 'required|string|in:dispatched,in_transit,delivered,failed,returned,cancelled',
            'recipient_name' => 'nullable|string|max:255',
            'recipient_phone' => 'nullable|string|max:50',
            'delivery_address' => 'nullable|string',
            'delivery_location' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'region' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'estimated_delivery_time' => 'nullable|date',
            'notes' => 'nullable|string',
            'update_order_status' => 'sometimes|boolean',
            // What was paid to the delivery/logistics provider for this
            // dispatch - distinct from the customer's payment for the goods.
            'delivery_cost' => 'nullable|numeric|min:0',
            'amount_paid' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|string|max:100',
            'payment_reference' => 'nullable|string|max:255',
            'payment_date' => 'nullable|date',
            'cheque_number' => 'nullable|string|max:100',
            'bank_name' => 'nullable|string|max:150',
            'cheque_maturity_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'failed',
                'message' => $validator->errors(),
            ], 400);
        }

        try {
            $user = $request->user();
            $dispatch = \App\Models\OrderDispatch::with('order')->find($request->input('order_dispatch_id'));

            if (!$dispatch) {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'Order Dispatch not found.',
                ], 404);
            }

            // Allow if user can manage all logistics OR matches company
            if (!$this->hasPermission($request, 'can_manage_all_logistics') && $dispatch->company_id !== $user->company_id) {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'Unauthorized to create logistics for this dispatch.',
                ], 403);
            }

            return DB::transaction(function () use ($request, $dispatch, $user) {
                $logistic = Logistic::create([
                    'id' => (string) Str::uuid(),
                    'order_dispatch_id' => $dispatch->id,
                    'company_id' => $dispatch->company_id,
                    // 'dispatcher_id' => $user->id, // dispatcher_id does not exist in migration, relying on created_by/audits if present or ignoring
                    'delivery_person_id' => $request->input('delivery_person_id'),
                    'logistics_provider' => $request->input('logistics_provider'),
                    'delivery_method' => $request->input('delivery_method'),
                    'vehicle_type' => $request->input('vehicle_type'),
                    'vehicle_id' => $request->input('vehicle_id'),
                    'tracking_number' => $request->input('tracking_number'),
                    'delivery_status' => $request->input('delivery_status'),
                    'recipient_name' => $request->input('recipient_name'),
                    'recipient_phone' => $request->input('recipient_phone'),
                    'delivery_address' => $request->input('delivery_address'),
                    'delivery_location' => $request->input('delivery_location'),
                    'city' => $request->input('city'),
                    'state' => $request->input('state'),
                    'region' => $request->input('region'),
                    'country' => $request->input('country', 'Kenya'),
                    'dispatch_time' => now(),
                    'estimated_delivery_time' => $request->input('estimated_delivery_time'),
                    'notes' => $request->input('notes'),
                    'delivery_cost' => $request->input('delivery_cost'),
                    'amount_paid' => $request->input('amount_paid'),
                    'payment_method' => $request->input('payment_method'),
                    'payment_reference' => $request->input('payment_reference'),
                    'payment_date' => $request->input('payment_date'),
                    'cheque_number' => $request->input('cheque_number'),
                    'bank_name' => $request->input('bank_name'),
                    'cheque_maturity_date' => $request->input('cheque_maturity_date'),
                    'payment_status' => $this->resolvePaymentStatus($request->input('delivery_cost'), $request->input('amount_paid')),
                ]);

                // Update order status if requested and order exists
                if ($request->input('update_order_status', true) && $dispatch->order) {
                    $order = $dispatch->order;
                    $deliveryStatus = $request->input('delivery_status');
                    if ($deliveryStatus === 'delivered') {
                        $order->update(['status' => 'completed']);
                    } elseif (in_array($deliveryStatus, ['failed', 'returned', 'cancelled'])) {
                        // Decide if order should be cancelled or just flagged; usually depends on workflow
                        // Keeping it simple as per previous logic logic, but strict 'cancelled' might be aggressive
                        $order->update(['status' => 'cancelled']);
                    } elseif ($deliveryStatus === 'dispatched' || $deliveryStatus === 'in_transit') {
                        $order->update(['status' => 'dispatched']);
                    }
                }

                // Update Dispatch Status as well to keep them in sync
                // Map 'dispatched' from logistics to 'in_transit' for order_dispatch
                $dispatchStatus = $request->input('delivery_status');
                if ($dispatchStatus === 'dispatched') {
                    $dispatchStatus = 'in_transit';
                }

                // Ensure the status is valid for order_dispatches
                if (in_array($dispatchStatus, ['pending', 'approved', 'in_transit', 'delivered', 'cancelled'])) {
                    $dispatch->status = $dispatchStatus;
                    $dispatch->save();
                }

                return response()->json([
                    'status' => 'success',
                    'message' => 'Logistics record created successfully.',
                    'logistic' => $logistic->load(['orderDispatch', 'deliveryPerson']),
                ], 201);
            });
        } catch (\Exception $e) {
            Log::error('Failed to create logistics record', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 'failed',
                'message' => 'Failed to create logistics record: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Derive the delivery payment status from what's owed vs what's paid.
     */
    protected function resolvePaymentStatus($deliveryCost, $amountPaid): string
    {
        $cost = $deliveryCost !== null ? (float) $deliveryCost : null;
        $paid = (float) ($amountPaid ?? 0);

        if ($cost === null || $cost <= 0) {
            return $paid > 0 ? 'paid' : 'unpaid';
        }

        if ($paid <= 0) {
            return 'unpaid';
        }

        return $paid >= $cost ? 'paid' : 'partial';
    }
}
