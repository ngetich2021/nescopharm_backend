<?php

namespace App\Services;

use App\Models\CreditNote;
use App\Models\Product;
use App\Models\Order;
use App\Models\Customer;
use App\Models\OrderDispatch;
use App\Models\InventoryMovement;
use App\Models\StockAdjustment;
use App\Models\PurchaseOrder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;

class ReportService
{
    protected ?string $companyId = null;

    public function forCompany(string $companyId): self
    {
        $this->companyId = $companyId;
        return $this;
    }

    /**
     * 1. INVENTORY REPORTS
     */

    public function getStockBalanceReport(array $filters = []): array
    {
        $query = Product::query()->where('company_id', $this->companyId);

        $this->applyInventoryFilters($query, $filters);

        $summaryQuery = clone $query;

        $items = $query->select(
            'id',
            'name',
            'sku',
            'stock_quantity',
            'on_hand',
            'allocated',
            'low_stock_threshold',
            'category',
            'unit_cost',
            'price'
        )->get();

        return [
            'data' => $items->toArray(),
            'summary' => [
                'total_items' => $summaryQuery->count(),
                'total_stock' => $summaryQuery->sum('stock_quantity'),
                'total_on_hand' => $summaryQuery->sum('on_hand'),
                'total_allocated' => $summaryQuery->sum('allocated'),
                'total_value' => $summaryQuery->select(DB::raw('SUM(unit_cost * stock_quantity) as total_value'))->value('total_value') ?? 0,
            ]
        ];
    }

    public function getLowStockReport(array $filters = []): array
    {
        $query = Product::query()->where('company_id', $this->companyId)
            ->whereRaw('stock_quantity <= low_stock_threshold');

        $this->applyInventoryFilters($query, $filters);

        $summaryQuery = clone $query;

        return [
            'data' => $query->get()->toArray(),
            'summary' => [
                'total_low_stock_items' => $summaryQuery->count(),
                'total_stock' => $summaryQuery->sum('stock_quantity'),
                'total_on_hand' => $summaryQuery->sum('on_hand'),
            ]
        ];
    }

    public function getInventoryMovementLog(array $filters = []): array
    {
        $query = InventoryMovement::whereHas('product', function ($q) {
            $q->where('company_id', $this->companyId);
        });

        if (isset($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }
        if (isset($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }

        return $query->with('product:id,name,sku')->orderBy('created_at', 'desc')->get()->toArray();
    }

    /**
     * 2. SALES REPORTS
     */

    public function getSalesPerformanceSummary(array $filters = []): array
    {
        $query = Order::query()->where('orders.company_id', $this->companyId)
            ->where('orders.status', '!=', 'cancelled');

        $this->applyOrderFilters($query, $filters);

        $creditNotesQuery = CreditNote::query()
            ->where('company_id', $this->companyId)
            ->whereIn('status', ['issued', 'applied', 'refunded']);

        $this->applyCreditNoteFilters($creditNotesQuery, $filters);
        $creditedRevenue = (float) $creditNotesQuery->sum('total_amount');

        // Summary metrics
        $summary = [
            'total_sales' => (float) $query->sum('orders.final_amount') - $creditedRevenue,
            'total_subtotal' => $query->sum('orders.total_amount'),
            'total_discount' => $query->sum('orders.discount'),
            'total_tax' => $query->sum('orders.tax'),
            'total_credited' => $creditedRevenue,
            'order_count' => $query->count(),
            'average_order_value' => $query->avg('orders.final_amount') ?? 0,
            'total_items_count' => DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->where('orders.company_id', $this->companyId)
                ->where('orders.status', '!=', 'cancelled')
                ->when(isset($filters['date_from']), fn($q) => $q->where('orders.order_date', '>=', $filters['date_from']))
                ->when(isset($filters['date_to']), fn($q) => $q->where('orders.order_date', '<=', $filters['date_to']))
                ->sum('order_items.quantity'),
        ];

        // Summary by date

        $ordersByDate = $query->clone()
            ->select(
                DB::raw('DATE(orders.order_date) as date'),
                DB::raw('SUM(orders.final_amount) as total'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $creditsByDate = CreditNote::query()
            ->where('company_id', $this->companyId)
            ->whereIn('status', ['issued', 'applied', 'refunded']);

        $this->applyCreditNoteFilters($creditsByDate, $filters);

        $creditsByDate = $creditsByDate
            ->select(
                DB::raw('DATE(credit_note_date) as date'),
                DB::raw('SUM(total_amount) as total_credited')
            )
            ->groupBy('date')
            ->pluck('total_credited', 'date');

        // Calculate item counts separately to avoid grouping error
        $itemCountsQuery = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.company_id', $this->companyId)
            ->where('orders.status', '!=', 'cancelled');

        $this->applyOrderFilters($itemCountsQuery, $filters);

        $itemCounts = $itemCountsQuery->select(
            DB::raw('DATE(orders.order_date) as date'),
            DB::raw('SUM(order_items.quantity) as count')
        )
            ->groupBy('date')
            ->pluck('count', 'date');

        // Merge results
        $summaryByDate = $ordersByDate->map(function ($orderSummary) use ($itemCounts, $creditsByDate) {
            $data = $orderSummary->toArray();
            $data['item_count'] = $itemCounts[$orderSummary->date] ?? 0;
            $data['credited_total'] = (float) ($creditsByDate[$orderSummary->date] ?? 0);
            $data['net_total'] = (float) $data['total'] - $data['credited_total'];
            return $data;
        })->toArray();

        // Top Categories - build query with all filters
        $topCategoriesQuery = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('orders.company_id', $this->companyId)
            ->where('orders.status', '!=', 'cancelled');

        $this->applyOrderFilters($topCategoriesQuery, $filters);

        $topCategories = $topCategoriesQuery
            ->select('products.category', DB::raw('SUM(order_items.quantity * order_items.unit_price) as total_revenue'))
            ->groupBy('products.category')
            ->orderBy('total_revenue', 'desc')
            ->limit(5)
            ->get()->toArray();

        // Top Products - build query with all filters
        $topProductsQuery = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('orders.company_id', $this->companyId)
            ->where('orders.status', '!=', 'cancelled');

        $this->applyOrderFilters($topProductsQuery, $filters);

        $topProducts = $topProductsQuery
            ->select('products.name', 'products.sku', DB::raw('SUM(order_items.quantity) as total_quantity'), DB::raw('SUM(order_items.quantity * order_items.unit_price) as total_revenue'))
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->orderBy('total_revenue', 'desc')
            ->limit(5)
            ->get()->toArray();

        // Top Customers - build query with all filters
        $topCustomersQuery = DB::table('orders')
            ->join('customers', 'orders.customer_id', '=', 'customers.id')
            ->leftJoin('delivery_locations', 'orders.delivery_location_id', '=', 'delivery_locations.id')
            ->where('orders.company_id', $this->companyId)
            ->where('orders.status', '!=', 'cancelled');

        $this->applyOrderFilters($topCustomersQuery, $filters);

        $topCustomers = $topCustomersQuery
            ->select(
                DB::raw("CASE WHEN customers.customer_type != 'individual' AND customers.business_name IS NOT NULL THEN customers.business_name ELSE customers.name END as name"),
                DB::raw("COALESCE(delivery_locations.city, customers.city, 'Unknown') as location"),
                DB::raw('COUNT(*) as order_count'),
                DB::raw('SUM(orders.final_amount) as total_spent')
            )
            ->groupBy('customers.id', 'customers.name', 'customers.customer_type', 'customers.business_name', 'delivery_locations.city', 'customers.city')
            ->orderBy('total_spent', 'desc')
            ->limit(5)
            ->get()->toArray();

        // Payment status breakdown - build query with all filters
        $paymentStatusQuery = DB::table('orders')
            ->where('orders.company_id', $this->companyId)
            ->where('orders.status', '!=', 'cancelled');

        $this->applyOrderFilters($paymentStatusQuery, $filters);

        $paymentStatusBreakdown = $paymentStatusQuery
            ->select('payment_status', DB::raw('COUNT(*) as count'), DB::raw('SUM(final_amount) as total'))
            ->groupBy('payment_status')
            ->get()->toArray();

        return array_merge($summary, [
            'summary_by_date' => $summaryByDate,
            'top_categories' => $topCategories,
            'top_products' => $topProducts,
            'top_customers' => $topCustomers,
            'payment_status_breakdown' => $paymentStatusBreakdown,
        ]);
    }

    public function getProductSalesRanking(array $filters = []): array
    {
        $query = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('orders.company_id', $this->companyId)
            ->where('orders.status', '!=', 'cancelled');

        if (isset($filters['date_from'])) {
            $query->where('orders.order_date', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->where('orders.order_date', '<=', $filters['date_to']);
        }

        return $query->select(
            'products.id',
            'products.name',
            'products.sku',
            DB::raw('SUM(order_items.quantity) as total_quantity'),
            DB::raw('SUM(order_items.quantity * order_items.unit_price) as total_revenue')
        )
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->orderBy('total_revenue', 'desc')
            ->get()->toArray();
    }

    public function getQuoteConversionReport(array $filters = []): array
    {
        $totalQuotes = DB::table('quotes')->where('company_id', $this->companyId)->count();
        $convertedQuotes = DB::table('quotes')
            ->where('company_id', $this->companyId)
            ->where('status', 'accepted')
            ->count();

        return [
            'total_quotes' => $totalQuotes,
            'converted_quotes' => $convertedQuotes,
            'conversion_rate' => $totalQuotes > 0 ? ($convertedQuotes / $totalQuotes) * 100 : 0
        ];
    }

    /**
     * 3. LOGISTICS REPORTS
     */

    public function getDispatchEfficiencyReport(array $filters = []): array
    {
        $query = OrderDispatch::where('company_id', $this->companyId)
            ->whereNotNull('dispatch_date')
            ->whereNotNull('final_approved_at');

        if (isset($filters['date_from'])) {
            $query->where('dispatch_date', '>=', $filters['date_from']);
        }

        return $query->select(
            'id',
            'dispatch_number',
            'order_id',
            'final_approved_at',
            'dispatch_date',
            DB::raw('EXTRACT(EPOCH FROM (dispatch_date - final_approved_at))/3600 as hours_to_dispatch')
        )->get()->toArray();
    }

    public function getDeliverySuccessRate(array $filters = []): array
    {
        $query = OrderDispatch::where('company_id', $this->companyId);

        return [
            'total_dispatches' => $query->count(),
            'delivered' => $query->where('status', 'delivered')->count(),
            'failed_or_returned' => $query->whereIn('status', ['returned', 'failed'])->count(),
            'pending' => $query->whereIn('status', ['pending', 'in_transit'])->count()
        ];
    }

    /**
     * 4. PROCUREMENT REPORTS
     */

    public function getPurchaseOrderStatusReport(array $filters = []): array
    {
        $query = DB::table('purchase_orders')
            ->leftJoin('purchase_order_items', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_orders.company_id', $this->companyId);

        return $query->select(
            'purchase_orders.status',
            DB::raw('COUNT(DISTINCT purchase_orders.id) as count'),
            DB::raw('COALESCE(SUM(purchase_order_items.subtotal), 0) as total_value')
        )
            ->groupBy('purchase_orders.status')
            ->get()->toArray();
    }

    /**
     * 5. CUSTOMER REPORTS
     */

    public function getCustomerAcquisitionReport(array $filters = []): array
    {
        $query = Customer::query()->where('company_id', $this->companyId);

        if (isset($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        return $query->select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('COUNT(*) as new_customers')
        )->groupBy('date')->orderBy('date')->get()->toArray();
    }

    /**
     * Helper methods for filters
     */

    protected function applyInventoryFilters(Builder $query, array $filters): void
    {
        if (isset($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        } elseif (isset($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (isset($filters['store_id'])) {
            $query->where('store_id', $filters['store_id']);
        }

        if (isset($filters['status'])) {
            $query->where('is_active', $filters['status'] === 'active' ? 'true' : 'false');
        }

        if (isset($filters['brand'])) {
            $query->where('brand', $filters['brand']);
        }
    }

    protected function applyOrderFilters($query, array $filters): void
    {
        $table = 'orders';

        if (isset($filters['date_from'])) {
            $query->where($table . '.order_date', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->where($table . '.order_date', '<=', $filters['date_to']);
        }
        if (isset($filters['customer_id'])) {
            $query->where($table . '.customer_id', $filters['customer_id']);
        }
        if (isset($filters['status'])) {
            $query->where($table . '.status', $filters['status']);
        }
        if (isset($filters['payment_status'])) {
            $query->where($table . '.payment_status', $filters['payment_status']);
        }
        if (isset($filters['location_id'])) {
            $query->where($table . '.delivery_location_id', $filters['location_id']);
        }
        if (isset($filters['city'])) {
            $query->join('delivery_locations', $table . '.delivery_location_id', '=', 'delivery_locations.id')
                ->where('delivery_locations.city', 'ILIKE', '%' . $filters['city'] . '%');
        }
        if (isset($filters['category_id'])) {
            // For queries that join with products table
            $query->where('products.category_id', $filters['category_id']);
        } elseif (isset($filters['category'])) {
            // For queries that join with products table
            $query->where('products.category', $filters['category']);
        }
    }

    protected function applyCreditNoteFilters($query, array $filters): void
    {
        if (isset($filters['date_from'])) {
            $query->where('credit_note_date', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->where('credit_note_date', '<=', $filters['date_to']);
        }
        if (isset($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
    }
}
