<?php

namespace App\Http\Controllers;

use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\Company;
use App\Models\Store;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockCountController extends Controller
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
     * Generate a unique count number for the stock count.
     *
     * @param string $companyId
     * @param string $storeId
     * @return string
     */
    protected function generateCountNumber($companyId, $storeId)
    {
        $prefix = 'SC-' . substr($companyId, 0, 4) . '-' . substr($storeId, 0, 4) . '-';
        $lastCount = StockCount::where('company_id', $companyId)
            ->where('store_id', $storeId)
            ->where('count_number', 'like', $prefix . '%')
            ->orderBy('count_number', 'desc')
            ->first();

        if ($lastCount) {
            // Use getRawOriginal to get the actual database value, not the transformed one
            $rawCountNumber = $lastCount->getRawOriginal('count_number');
            $nextNumber = (int)substr($rawCountNumber, strlen($prefix)) + 1;
        } else {
            $nextNumber = 1;
        }
        
        return $prefix . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    }

    /**
     * List all stock counts for the user's company/store.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$this->hasPermission($request, 'can_view_stock_counts', $user->company_id)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized to view stock counts.',
            ], 403);
        }

        try {
            $user = $request->user();
            $query = StockCount::with(['store' => function ($query) {
                $query->select('id', 'name');
            }]);

            if (!$this->hasPermission($request, 'can_manage_all_quotes')) {
                $query->where('company_id', $user->company_id);
            } else {
                if ($request->filled('company_id')) {
                    $query->where('company_id', $request->input('company_id'));
                }
            }

            if ($request->filled('store_id')) {
                $query->where('store_id', $request->input('store_id'));
            }

            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }

            if ($request->filled('count_type')) {
                $query->where('count_type', $request->input('count_type'));
            }

            $stockCounts = $query->select(
                'id',
                'company_id',
                'store_id',
                'count_number',
                'name',
                'count_type',
                'status',
                'scheduled_date',
                'total_products_expected',
                'total_products_counted',
                'total_variances',
                'total_variance_value',
                'created_at',
                'updated_at'
            )
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => 'success',
                'stock_counts' => $stockCounts,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve stock counts', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 'failed',
                'message' => 'Failed to retrieve stock counts: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * View details of a single stock count.
     *
     * @param Request $request
     * @param string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        if (!$this->hasPermission($request, 'can_view_stock_counts', $user->company_id)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized to view stock counts.',
            ], 403);
        }

        try {
            $user = $request->user();
            $query = StockCount::where('id', $id)
                ->with([
                    'store' => function ($query) {
                        $query->select('id', 'name');
                    },
                    'creator' => function ($query) {
                        $query->select('id', 'email', 'first_name', 'last_name', 'phone', 'avatar_url');
                    },
                    'assignedUser' => function ($query) {
                        $query->select('id', 'email', 'first_name', 'last_name', 'phone', 'avatar_url');
                    },
                    'approver' => function ($query) {
                        $query->select('id', 'email', 'first_name', 'last_name', 'phone', 'avatar_url');
                    },
                    'items' => function ($query) {
                        $query->select(
                            'id',
                            'stock_count_id',
                            'product_id',
                            'product_name',
                            'product_sku',
                            'product_category',
                            'unit_cost',
                            'expected_quantity',
                            'counted_quantity',
                            'variance_quantity',
                            'variance_value',
                            'is_counted',
                            'requires_recount',
                            'is_variance',
                            'counted_by',
                            'counted_at',
                            'notes'
                        )
                            ->with(['product' => function ($query) {
                                $query->select('id', 'name', 'unit_cost');
                            }]);
                    }
                ]);

            if (!$this->hasPermission($request, 'can_manage_all_quotes')) {
                $query->where('company_id', $user->company_id);
            }

            $stockCount = $query->first();

            if (!$stockCount) {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'Stock count not found or not authorized.',
                ], 404);
            }

            // Calculate metrics from items
            $totalProducts = $stockCount->items->count();
            $productsCounted = $stockCount->items->where('is_counted', true)->count();
            $variances = $stockCount->items->where('is_variance', true)->count();
            $varianceValue = $stockCount->items->sum('variance_value');

            // Update the stock count with calculated metrics
            $stockCount->total_products_expected = $totalProducts;
            $stockCount->total_products_counted = $productsCounted;
            $stockCount->total_variances = $variances;
            $stockCount->total_variance_value = $varianceValue;

            return response()->json([
                'status' => 'success',
                'message' => 'Stock count details retrieved successfully.',
                'stock_count' => $stockCount,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve stock count details', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 'failed',
                'message' => 'Failed to retrieve stock count details: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new stock count with items.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|uuid|exists:companies,id',
            'store_id' => 'required|uuid|exists:stores,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'count_type' => 'required|string|in:cycle_count,full_count',
            'status' => 'required|string|in:draft,in_progress',
            'location' => 'nullable|string|max:255',
            'category_filter' => 'nullable|string|max:255',
            'scheduled_date' => 'nullable|date',
            'assigned_to' => 'nullable|string|max:255',
            'items' => 'required_if:status,in_progress|array|min:1',
            'items.*.product_id' => 'required|uuid|exists:products,id',
            'items.*.expected_quantity' => 'required|integer|min:0',
            'items.*.counted_quantity' => 'nullable|integer|min:0',
            'items.*.notes' => 'nullable|string',
            'items.*.requires_recount' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'failed',
                'message' => $validator->errors(),
            ], 400);
        }

        $user = $request->user();
        if (!$this->hasPermission($request, 'can_create_stock_counts', $user->company_id)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized to create stock counts.',
            ], 403);
        }

        try {
            $user = $request->user();
            if (!$this->hasPermission($request, "can_create_stock_counts", $request->input('company_id'))) {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'Unauthorized to create stock counts for this company.',
                ], 403);
            }

            $store = Store::find($request->input('store_id'));
            if ($store->company_id !== $request->input('company_id')) {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'Store does not belong to the specified company.',
                ], 400);
            }

            if (!$store->is_active) {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'Store is not active.',
                ], 400);
            }

            // Validate items
            $items = $request->input('items', []);
            foreach ($items as $index => $item) {
                $product = Product::find($item['product_id']);
                if ($product->company_id !== $request->input('company_id')) {
                    return response()->json([
                        'status' => 'failed',
                        'message' => "Item at index {$index}: Product does not belong to the company.",
                    ], 403);
                }
                if ($product->store_id !== $request->input('store_id')) {
                    return response()->json([
                        'status' => 'failed',
                        'message' => "Item at index {$index}: Product does not belong to the store.",
                    ], 400);
                }
            }

            return DB::transaction(function () use ($request, $user, $items) {
                $countNumber = $this->generateCountNumber($request->input('company_id'), $request->input('store_id'));

                // Date logic fix for DB constraint
                $now = now()->setTimezone('UTC');
                $status = $request->input('status');
                $scheduledDate = $request->input('scheduled_date');
                $startedAt = $status === 'in_progress' ? $now : null;
                // If scheduled_date is not set, default to started_at or now
                if (!$scheduledDate) {
                    $scheduledDate = $startedAt ?? $now;
                } else {
                    // If status is in_progress, scheduled_date must be >= started_at
                    if ($startedAt && $scheduledDate < $startedAt) {
                        $scheduledDate = $startedAt;
                    }
                }

                $stockCount = StockCount::create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $request->input('company_id'),
                    'store_id' => $request->input('store_id'),
                    'count_number' => $countNumber,
                    'name' => $request->input('name'),
                    'description' => $request->input('description'),
                    'count_type' => $request->input('count_type'),
                    'status' => $status,
                    'location' => $request->input('location'),
                    'category_filter' => $request->input('category_filter'),
                    // 'scheduled_date' => $scheduledDate,
                    'started_at' => $startedAt,
                    'created_by' => $user->email,
                    'assigned_to' => $request->input('assigned_to'),
                    'total_products_expected' => count($items),
                ]);

                foreach ($items as $item) {
                    $product = Product::find($item['product_id']);
                    StockCountItem::create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $stockCount->company_id,
                        'store_id' => $stockCount->store_id,
                        'stock_count_id' => $stockCount->id,
                        'product_id' => $item['product_id'],
                        'product_name' => $product->name,
                        'product_sku' => $product->sku ?? null,
                        'product_category' => $product->category ?? null,
                        'unit_cost' => $product->unit_cost,
                        'expected_quantity' => $item['expected_quantity'],
                        'counted_quantity' => $item['counted_quantity'],
                        'counted_by' => $item['counted_quantity'] ? $user->email : null,
                        'counted_at' => $item['counted_quantity'] ? now() : null,
                        'notes' => $item['notes'],
                        'is_counted' => !is_null($item['counted_quantity']) ? true : false,
                        'requires_recount' => ($item['requires_recount'] ?? false) ? true : false,
                    ]);
                }

                // Calculate metrics after creating all items
                $stockCount->refresh();
                $stockCount->total_products_counted = $stockCount->items->where('is_counted', true)->count();
                $stockCount->total_variances = $stockCount->items->where('is_variance', true)->count();
                $stockCount->total_variance_value = $stockCount->items->sum('variance_value');
                $stockCount->save();

                return response()->json([
                    'status' => 'success',
                    'message' => 'Stock count created successfully.',
                    'stock_count' => $stockCount->load(['store', 'items.product', 'creator', 'assignedUser']),
                ], 201);
            });
        } catch (\Exception $e) {
            Log::error('Failed to create stock count', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 'failed',
                'message' => 'Failed to create stock count: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update an existing stock count.
     *
     * @param Request $request
     * @param string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        if (!$this->hasPermission($request, 'can_update_stock_counts', $user->company_id)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized to edit stock counts.',
            ], 403);
        }

        $stockCount = StockCount::where('id', $id)->first();
        if (!$stockCount) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Stock count not found.',
            ], 404);
        }

        if (!$this->hasPermission($request, "can_update_stock_counts", $stockCount->company_id)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized to update this stock count.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'count_type' => 'sometimes|string|in:cycle_count,full_count',
            'status' => 'sometimes|string|in:draft,in_progress,completed,approved,cancelled',
            'location' => 'nullable|string|max:255',
            'category_filter' => 'nullable|string|max:255',
            // 'scheduled_date' => 'nullable|date',
            'started_at' => 'nullable|date',
            'completed_at' => 'nullable|date',
            'approved_at' => 'nullable|date',
            'assigned_to' => 'nullable|string|max:255',
            'approved_by' => 'nullable|string|max:255',
            'items' => 'sometimes|array',
            'items.*.id' => 'sometimes|uuid|exists:stock_count_items,id',
            'items.*.product_id' => 'required|uuid|exists:products,id',
            'items.*.expected_quantity' => 'required|integer|min:0',
            'items.*.counted_quantity' => 'nullable|integer|min:0',
            'items.*.notes' => 'nullable|string',
            'items.*.requires_recount' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'failed',
                'message' => $validator->errors(),
            ], 400);
        }

        try {
            $user = $request->user();
            return DB::transaction(function () use ($request, $stockCount, $user) {
                $input = $request->only([
                    'name',
                    'description',
                    'count_type',
                    'status',
                    'location',
                    'category_filter',
                    'assigned_to',
                ]);

                // Date logic fix for DB constraint
                $now = now()->setTimezone('UTC');
                $status = $request->input('status', $stockCount->status);
                $scheduledDate = $request->input('scheduled_date', $stockCount->scheduled_date);
                $startedAt = $stockCount->started_at;
                if ($request->has('status')) {
                    $newStatus = $request->input('status');
                    if ($newStatus === 'in_progress' && !$stockCount->started_at) {
                        $startedAt = $now;
                        $input['started_at'] = $startedAt;
                    } elseif ($newStatus === 'completed' && !$stockCount->completed_at) {
                        $input['completed_at'] = $now;
                    } elseif ($newStatus === 'approved') {
                        $input['approved_at'] = $now;
                        $input['approved_by'] = $user->email;
                    }
                }
                // If scheduled_date is not set, default to started_at or now
                if (!$scheduledDate) {
                    $scheduledDate = $startedAt ?? $now;
                } else {
                    // If status is in_progress, scheduled_date must be >= started_at
                    if ($startedAt && $scheduledDate < $startedAt) {
                        $scheduledDate = $startedAt;
                    }
                }
                $input['scheduled_date'] = $scheduledDate;

                $stockCount->update($input);

                if ($request->has('items')) {
                    $items = $request->input('items');
                    $existingItemIds = [];
                    foreach ($items as $item) {
                        $product = Product::find($item['product_id']);
                        if ($product->company_id !== $stockCount->company_id || $product->store_id !== $stockCount->store_id) {
                            return response()->json([
                                'status' => 'failed',
                                'message' => "Item product_id {$item['product_id']}: Invalid company or store.",
                            ], 400);
                        }

                        $itemData = [
                            'company_id' => $stockCount->company_id,
                            'store_id' => $stockCount->store_id,
                            'stock_count_id' => $stockCount->id,
                            'product_id' => $item['product_id'],
                            'product_name' => $product->name,
                            'product_sku' => $product->sku ?? null,
                            'product_category' => $product->category ?? null,
                            'unit_cost' => $product->unit_cost,
                            'expected_quantity' => $item['expected_quantity'],
                            'counted_quantity' => $item['counted_quantity'],
                            'counted_by' => $item['counted_quantity'] ? $user->email : null,
                            'counted_at' => $item['counted_quantity'] ? now() : null,
                            'notes' => $item['notes'],
                            'is_counted' => !is_null($item['counted_quantity']) ? true : false,
                            'requires_recount' => ($item['requires_recount'] ?? false) ? true : false,
                        ];

                        if (isset($item['id'])) {
                            $stockCountItem = StockCountItem::where('id', $item['id'])
                                ->where('stock_count_id', $stockCount->id)
                                ->first();
                            if ($stockCountItem) {
                                $stockCountItem->update($itemData);
                                $existingItemIds[] = $item['id'];
                            }
                        } else {
                            $newItem = StockCountItem::create(array_merge(['id' => (string) Str::uuid()], $itemData));
                            $existingItemIds[] = $newItem->id;
                        }
                    }

                    // Delete items not included in the update
                    StockCountItem::where('stock_count_id', $stockCount->id)
                        ->whereNotIn('id', $existingItemIds)
                        ->delete();
                }

                // Update stock if approved
                if ($stockCount->status === 'approved' && $stockCount->wasChanged('status')) {
                    foreach ($stockCount->items as $item) {
                        if ($item->is_variance && $item->product->track_inventory) {
                            $product = Product::find($item->product_id);
                            $product->stock_quantity = $item->counted_quantity;
                            $product->save();
                        }
                    }
                }

                // Recalculate metrics after updating items
                $stockCount->refresh();
                $stockCount->total_products_expected = $stockCount->items->count();
                $stockCount->total_products_counted = $stockCount->items->where('is_counted', true)->count();
                $stockCount->total_variances = $stockCount->items->where('is_variance', true)->count();
                $stockCount->total_variance_value = $stockCount->items->sum('variance_value');
                $stockCount->save();

                return response()->json([
                    'status' => 'success',
                    'message' => 'Stock count updated successfully.',
                    'stock_count' => $stockCount->load(['store', 'items.product', 'creator', 'assignedUser', 'approver']),
                ], 200);
            });
        } catch (\Exception $e) {
            Log::error('Failed to update stock count', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 'failed',
                'message' => 'Failed to update stock count: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a stock count.
     *
     * @param Request $request
     * @param string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Request $request, $id)
    {
        if (!$this->hasPermission($request, 'can_delete_stock_counts')) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized to delete stock counts.',
            ], 403);
        }

        $stockCount = StockCount::where('id', $id)->first();
        if (!$stockCount) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Stock count not found.',
            ], 404);
        }

        if (!$this->hasPermission($request, "can_delete_stock_counts", $stockCount->company_id)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized to delete this stock count.',
            ], 403);
        }

        if ($stockCount->status === 'approved') {
            return response()->json([
                'status' => 'failed',
                'message' => 'Cannot delete an approved stock count.',
            ], 400);
        }

        try {
            return DB::transaction(function () use ($stockCount) {
                $stockCount->items()->delete();
                $stockCount->delete();

                return response()->json([
                    'status' => 'success',
                    'message' => 'Stock count deleted successfully.',
                ], 200);
            });
        } catch (\Exception $e) {
            Log::error('Failed to delete stock count', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 'failed',
                'message' => 'Failed to delete stock count: ' . $e->getMessage(),
            ], 500);
        }
    }
}
