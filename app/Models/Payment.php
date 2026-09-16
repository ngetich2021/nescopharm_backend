<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'order_id',
        'invoice_id', // Keeping for backward compatibility
        'customer_id',
        'company_id',
        'payment_number',
        'payment_method',
        'transaction_id',
        'amount_paid',
        'status',
        'payment_date',
    ];

    protected $casts = [
        'amount_paid' => 'decimal:2',
        'amount_applied' => 'decimal:2',
        'payment_date' => 'date',
        'applied_date' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Get all allocations for this payment
     */
    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * Get all invoices this payment is allocated to via allocations
     */
    public function allocatedInvoices()
    {
        return $this->belongsToMany(Invoice::class, 'payment_allocations')
                    ->withPivot('amount_allocated', 'allocated_date', 'notes')
                    ->withTimestamps();
    }

    /**
     * Get total amount allocated from this payment
     */
    public function getTotalAllocatedAttribute()
    {
        return $this->allocations()->sum('amount_allocated');
    }

    /**
     * Get remaining unallocated amount
     */
    public function getRemainingAmountAttribute()
    {
        return $this->amount_paid - $this->total_allocated;
    }

    /**
     * Always return the short payment number when accessing payment_number
     */
    public function getPaymentNumberAttribute($value)
    {
        // If payment_number is like PAY-853b296e-0074, return PAY-0074
        if (preg_match('/^(PAY)-(?:[\w-]+)-(\d{4})$/', $value, $matches)) {
            return $matches[1] . '-' . $matches[2];
        }
        // Fallback: return original
        return $value;
    }
}