<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quote extends Model
{
    use HasFactory, SoftDeletes;

    // Always append item_count to model output
    protected $appends = ['item_count'];

    /**
     * Get the count of items in the quote.
     * @return int
     */
    public function getItemCountAttribute(): int
    {
        // Use loaded quoteItems if available, otherwise query
        $items = $this->relationLoaded('quoteItems') ? $this->quoteItems : $this->quoteItems()->get();
        return $items->sum('quantity');
    }

    protected $dates = ['deleted_at'];

    /**
     * Always return the short quote number when accessing quote_number
     */
    public function getQuoteNumberAttribute($value)
    {
        // If quote_number is like QUO-853b296e-0074, return QUO-0074
        if (preg_match('/^(QUO)-(?:[\w-]+)-(\d{4})$/', $value, $matches)) {
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

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'quote_number',
        'customer_id',
        'submitted_by_id',
        'submitted_at',
        'original_submitted_by_id',
        'total_amount',
        'status',
        'company_id',
        'notes',
        'discount',
        'final_amount',
        'delivery_location_id',
        'currency',
        'below_minimum_price',
        'requires_approval',
        'valid_until',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'discount' => 'decimal:2',
        'final_amount' => 'decimal:2',
        'below_minimum_price' => 'boolean',
        'requires_approval' => 'boolean',
        'valid_until' => 'date',
        'submitted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * When set, this quote was submitted directly by a Sales Rep from POS
     * rather than built by staff - shown as "From: {rep} - {time}" until a
     * can_create_quotes user opens and edits it, which clears this field.
     */
    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }

    /**
     * The rep who originally submitted this quote from POS, if any. Unlike
     * submitted_by_id (cleared on staff's first edit), this is stamped once
     * at creation and kept forever, so the quote can always be routed back
     * to its owner for the confirm/request-changes step.
     */
    public function originalSubmittedBy()
    {
        return $this->belongsTo(User::class, 'original_submitted_by_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function quoteItems()
    {
        return $this->hasMany(QuoteItem::class, 'quote_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function deliveryLocation()
    {
        return $this->belongsTo(DeliveryLocation::class);
    }

    public function quoteNotes()
    {
        return $this->hasMany(QuoteNote::class, 'quote_id');
    }

    /**
     * Get the short quote number (e.g., QUO-0074)
     */
    public function getShortQuoteNumberAttribute()
    {
        // If quote_number is like QUO-853b296e-0074, return QUO-0074
        if (preg_match('/^(QUO)-(?:[\w-]+)-(\d{4})$/', $this->quote_number, $matches)) {
            return $matches[1] . '-' . $matches[2];
        }
        // Fallback: return original
        return $this->quote_number;
    }

    /**
     * Mark the quote as sent
     */
    public function markAsSent()
    {
        // Update status to 'pending' if it's not already approved/rejected
        if ($this->status === 'pending') {
            $this->update([
                'status' => 'pending',
            ]);
        }
    }
}
