<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\SoftDeletes;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Order extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    // Always append item_count to model output
    protected $appends = ['item_count'];

    /**
     * Get the count of items in the order.
     * @return int
     */
    public function getItemCountAttribute(): int
    {
        // Use loaded orderItems if available, otherwise query
        $items = $this->relationLoaded('orderItems') ? $this->orderItems : $this->orderItems()->get();
        return $items->sum('quantity');
    }

    protected $dates = ['deleted_at'];

    /**
     * Always return the short order number when accessing order_number
     */
    public function getOrderNumberAttribute($value)
    {
        // If order_number is like ORD-853b296e-0074, return ORD-0074
        if (preg_match('/^(ORD)-(?:[\w-]+)-(\d{4})$/', $value, $matches)) {
            return $matches[1] . '-' . $matches[2];
        }
        // Fallback: return original
        return $value;
    }

    /**
     * Handle below_minimum_price boolean conversion for PostgreSQL
     */
    public function setBelowMinimumPriceAttribute($value)
    {
        $this->attributes['below_minimum_price'] = $value ? 'true' : 'false';
    }

    /**
     * Handle requires_approval boolean conversion for PostgreSQL
     */
    public function setRequiresApprovalAttribute($value)
    {
        $this->attributes['requires_approval'] = $value ? 'true' : 'false';
    }

    /**
     * Get below_minimum_price as boolean when retrieving
     */
    public function getBelowMinimumPriceAttribute($value)
    {
        return $value === 'true' || $value === true || $value === 1;
    }

    /**
     * Get requires_approval as boolean when retrieving
     */
    public function getRequiresApprovalAttribute($value)
    {
        return $value === 'true' || $value === true || $value === 1;
    }
    use HasFactory;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'order_number',
        'customer_id',
        'total_amount',
        'status',
        'company_id',
        'notes',
        'discount',
        'tax',
        'final_amount',
        'delivery_location_id',
        'tracking_number',
        'amount_paid',
        'currency',
        'payment_status',
        'dispatch_status',
        'below_minimum_price',
        'requires_approval',
        'created_at',
        'updated_at',
        'order_date',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax' => 'decimal:2',
        'final_amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'below_minimum_price' => 'boolean',
        'requires_approval' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'order_date' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function deliveryLocation()
    {
        return $this->belongsTo(DeliveryLocation::class);
    }

    public function deliveryDetails()
    {
        return $this->hasMany(DeliveryDetail::class, 'order_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function orderDispatches()
    {
        return $this->hasMany(OrderDispatch::class, 'order_id');
    }

    public function latestOrderDispatch()
    {
        return $this->hasOne(OrderDispatch::class, 'order_id')->latestOfMany();
    }

    /**
     * Get the short order number (e.g., ORD-0074)
     */
    public function getShortOrderNumberAttribute()
    {
        // If order_number is like ORD-853b296e-0074, return ORD-0074
        if (preg_match('/^(ORD)-(?:[\w-]+)-(\d{4})$/', $this->order_number, $matches)) {
            return $matches[1] . '-' . $matches[2];
        }
        // Fallback: return original
        return $this->order_number;
    }
}