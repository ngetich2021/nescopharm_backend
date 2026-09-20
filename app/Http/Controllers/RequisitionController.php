<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;


use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class RequisitionController extends Controller
{


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
     * Generate a unique requisition number for the company.
     *
     * @param string $companyId
     * @return string
     */
    protected function generateRequisitionNumber($companyId)
    {
        $prefix = 'REQ-' . substr($companyId, 0, 8) . '-';

        // Use raw query to avoid model accessors interfering
        $lastRequisition = DB::table('requisitions')
            ->select('requisition_number')
            ->where('requisition_number', 'like', $prefix . '%')
            ->orderBy('requisition_number', 'desc')
            ->lockForUpdate()
            ->first();

        $nextNumber = $lastRequisition ? (int)substr($lastRequisition->requisition_number, strlen($prefix)) + 1 : 1;
        return $prefix . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    }


    public function store(Request $request)
    {
        if (!$this->hasPermission($request, 'can_create_requisitions')) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized to create a requisition.'
            ], 403);
        }
        $data = $request->all();
        $validator = Validator::make($data, [
            'approver_id' => 'nullable|uuid|exists:users,id',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|uuid|exists:products,id',
            'items.*.variant_id' => 'nullable|uuid|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.notes' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Validation failed.' . $validator->errors(),
                'message' => $validator->errors(), 'errors' => $validator->errors()
            ], 422);
        }
        $validated = $validator->validated();

        // Always set company_id and requester_id from the authenticated user
        $user = $request->user();
        $validated['company_id'] = $user->company_id;
        $validated['requester_id'] = $user->id;

        // Stock check for each item
        foreach ($validated['items'] as $item) {
            $product = Product::find($item['product_id']);
            if (!$product) {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'Product not found.',
                    'product_id' => $item['product_id']
                ], 404);
            }
            if (isset($item['variant_id'])) {
                $variant = ProductVariant::find($item['variant_id']);
                if (!$variant) {
                    return response()->json([
                        'status' => 'failed',
                        'message' => 'Variant not found.',
                        'variant_id' => $item['variant_id']
                    ], 404);
                }
                if ($variant->stock_quantity < $item['quantity']) {
                    return response()->json([
                        'status' => 'failed',
                        'message' => 'Insufficient stock for variant.',
                        'product_id' => $item['product_id'],
                        'variant_id' => $item['variant_id'],
                        'available' => $variant->stock_quantity
                    ], 422);
                }
            } else {
                if ($product->stock_quantity < $item['quantity']) {
                    return response()->json([
                        'status' => 'failed',
                        'message' => 'Insufficient stock for product.',
                        'product_id' => $item['product_id'],
                        'available' => $product->stock_quantity
                    ], 422);
                }
            }
        }

        // Create requisition and items in a transaction
        DB::beginTransaction();
        try {
            $requisitionNumber = $this->generateRequisitionNumber($validated['company_id']);
            $requisition = Requisition::create([
                'id' => (string) Str::uuid(),
                'requisition_number' => $requisitionNumber,
                'company_id' => $validated['company_id'],
                'requester_id' => $validated['requester_id'],
                'approver_id' => $validated['approver_id'] ?? null,
                'approval_status' => 'pending',
                'notes' => $validated['notes'] ?? null,
            ]);

            // Create requisition items
            foreach ($validated['items'] as $item) {
                RequisitionItem::create([
                    'id' => (string) Str::uuid(),
                    'requisition_id' => $requisition->id,
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'] ?? null,
                    'quantity' => $item['quantity'],
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            DB::commit();
            return response()->json([
                'status' => 'success',
                'message' => 'Requisition created successfully.',
                'requisition' => $requisition->fresh(['items.product', 'items.variant', 'requester', 'company', 'approver'])
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create requisition', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 'failed',
                'message' => 'Failed to create requisition.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // List requisitions pending approval for the current user
    public function toApprove(Request $request)
    {
        $user = $request->user();
        $query = Requisition::with(['items.product', 'items.variant', 'requester', 'company', 'approver'])
            ->where('approval_status', 'pending')
            ->where('approver_id', $user->id);
        $requisitions = $query->orderByDesc('created_at')->paginate(20);
        return response()->json([
            'status' => 'success',
            'message' => 'Requisitions pending your approval fetched successfully.',
            'data' => $requisitions
        ]);
    }


    // List all requisitions
    public function index(Request $request)
    {
        if (!$this->hasPermission($request, 'can_view_requisitions')) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized to view requisitions.'
            ], 403);
        }
        $user = $request->user();
        $query = Requisition::with(['items.product', 'items.variant', 'requester', 'company', 'approver']);
        // Always filter by company unless blanket permission
        if (!$this->hasPermission($request, 'can_manage_all_requisitions')) {
            $query->where('company_id', $user->company_id);
        }
        $requisitions = $query->orderByDesc('created_at')->paginate(20);
        return response()->json([
            'status' => 'success',
            'message' => 'Requisitions fetched successfully.',
            'data' => $requisitions
        ]);
    }

    // Get a single requisition
    public function show($id)
    {
        $user = request()->user();
        $requisition = Requisition::with(['items.product', 'items.variant', 'requester', 'company', 'approver'])
            ->where('id', $id)
            ->when(!$this->hasPermission(request(), 'can_manage_all_requisitions'), function ($q) use ($user) {
                $q->where('company_id', $user->company_id);
            })
            ->first();
        if (!$requisition) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Requisition not found or not accessible.'
            ], 404);
        }
        $companyId = $requisition->company_id;
        if (!$this->hasPermission(request(), 'can_view_requisitions') || !$this->canManageCompany(request(), $companyId)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized to view this requisition.'
            ], 403);
        }
        return response()->json([
            'status' => 'success',
            'message' => 'Requisition fetched successfully.',
            'data' => $requisition
        ]);
    }

    // Update a requisition (metadata only)
    public function update(Request $request, $id)
    {
        $user = $request->user();
        $requisition = Requisition::where('id', $id)
            ->when(!$this->hasPermission($request, 'can_manage_all_requisitions'), function ($q) use ($user) {
                $q->where('company_id', $user->company_id);
            })
            ->first();
        if (!$requisition) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Requisition not found or not accessible.'
            ], 404);
        }
        $companyId = $requisition->company_id;
        if (!$this->hasPermission($request, 'can_update_requisitions') || !$this->canManageCompany($request, $companyId)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized to update this requisition.'
            ], 403);
        }
        $data = $request->all();
        $validator = Validator::make($data, [
            'notes' => 'nullable|string',
            'status' => 'nullable|in:pending,approved,dispatched,acknowledged,rejected',
            'approval_status' => 'nullable|in:pending,in_review,approved,rejected',
            'approver_id' => 'nullable|uuid|exists:users,id',
            'dispatch_id' => 'nullable|uuid|exists:dispatches,id',
            'items' => 'nullable|array|min:1',
            'items.*.product_id' => 'required_with:items|uuid|exists:products,id',
            'items.*.variant_id' => 'nullable|uuid|exists:product_variants,id',
            'items.*.quantity' => 'required_with:items|integer|min:1',
            'items.*.notes' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            $firstError = $validator->errors()->first();
            return response()->json([
                'status' => 'failed',
                'message' => 'Validation failed: ' . $firstError,
                'message' => $validator->errors(), 'errors' => $validator->errors()
            ], 422);
        }
        $validated = $validator->validated();

        DB::beginTransaction();
        try {
            // Audit log before update
            Log::info('Requisition update initiated', [
                'requisition_id' => $requisition->id,
                'updated_by' => $user->id,
                'updated_at' => now(),
                'fields_updated' => array_keys($data),
                'items_updated' => $data['items'] ?? null,
            ]);

            // Update main requisition fields
            $requisition->update([
                'notes' => $validated['notes'] ?? $requisition->notes,
                'status' => $validated['status'] ?? $requisition->status,
                'approval_status' => $validated['approval_status'] ?? $requisition->approval_status,
                'approver_id' => $validated['approver_id'] ?? $requisition->approver_id,
                'dispatch_id' => $validated['dispatch_id'] ?? $requisition->dispatch_id,
            ]);

            // If items are provided, update them (delete old, add new)
            if (isset($validated['items'])) {
                $requisition->items()->delete();
                foreach ($validated['items'] as $item) {
                    RequisitionItem::create([
                        'id' => (string) Str::uuid(),
                        'requisition_id' => $requisition->id,
                        'product_id' => $item['product_id'],
                        'variant_id' => $item['variant_id'] ?? null,
                        'quantity' => $item['quantity'],
                        'notes' => $item['notes'] ?? null,
                    ]);
                }
            }

            // Audit log after update
            Log::info('Requisition update completed', [
                'requisition_id' => $requisition->id,
                'updated_by' => $user->id,
                'updated_at' => now(),
            ]);

            DB::commit();
            return response()->json([
                'status' => 'success',
                'message' => 'Requisition updated successfully.',
                'data' => $requisition->fresh(['items.product', 'items.variant', 'requester', 'company', 'approver'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update requisition', [
                'requisition_id' => $requisition->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'status' => 'failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Delete a requisition and its items
    public function destroy($id)
    {
        $user = request()->user();
        $requisition = Requisition::where('id', $id)
            ->when(!$this->hasPermission(request(), 'can_manage_all_requisitions'), function ($q) use ($user) {
                $q->where('company_id', $user->company_id);
            })
            ->first();
        if (!$requisition) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Requisition not found or not accessible.'
            ], 404);
        }
        $companyId = $requisition->company_id;
        if (!$this->hasPermission(request(), 'can_delete_requisitions') || !$this->canManageCompany(request(), $companyId)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized to delete this requisition.'
            ], 403);
        }
        $requisition->items()->delete();
        $requisition->delete();
        return response()->json([
            'status' => 'success',
            'message' => 'Requisition deleted successfully.'
        ]);
    }

    // Approve a requisition
    public function approve(Request $request, $id)
    {
        $user = $request->user();
        $requisition = Requisition::where('id', $id)
            ->when(!$this->hasPermission($request, 'can_manage_all_requisitions'), function ($q) use ($user) {
                $q->where('company_id', $user->company_id);
            })
            ->first();
        if (!$requisition) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Requisition not found or not accessible.'
            ], 404);
        }
        $companyId = $requisition->company_id;
        if (!$this->hasPermission($request, 'can_approve_requisitions') || !$this->canManageCompany($request, $companyId)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized to approve this requisition.'
            ], 403);
        }
        $validated = $request->validate([
            'approver_id' => 'required|uuid|exists:users,id',
            'approval_status' => 'required|in:approved,rejected,in_review',
            'notes' => 'nullable|string',
        ]);
        $requisition->update([
            'approver_id' => $validated['approver_id'],
            'approval_status' => $validated['approval_status'],
            'notes' => $validated['notes'] ?? $requisition->notes,
        ]);
        return response()->json([
            'status' => 'success',
            'message' => 'Requisition approved successfully.',
            'data' => $requisition->fresh(['items.product', 'items.variant', 'requester', 'company', 'approver'])
        ]);
    }

    // Acknowledge receipt
    public function acknowledge(Request $request, $id)
    {
        $user = $request->user();
        $requisition = Requisition::where('id', $id)
            ->when(!$this->hasPermission($request, 'can_manage_all_requisitions'), function ($q) use ($user) {
                $q->where('company_id', $user->company_id);
            })
            ->first();
        if (!$requisition) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Requisition not found or not accessible.'
            ], 404);
        }
        $companyId = $requisition->company_id;
        if (!$this->hasPermission($request, 'can_acknowledge_requisitions') || !$this->canManageCompany($request, $companyId)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized to acknowledge this requisition.'
            ], 403);
        }
        $requisition->update(['status' => 'acknowledged']);
        return response()->json([
            'status' => 'success',
            'message' => 'Requisition acknowledged successfully.',
            'data' => $requisition->fresh(['items.product', 'items.variant', 'requester', 'company', 'approver'])
        ]);
    }
}
