<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuoteItem extends Model
{
    use HasFactory;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'quote_id',
        'product_id',
        'variant_id',
        'unit_id',
        'quantity',
        'unit_quantity',
        'base_quantity',
        'packaging_breakdown',
        'unit_price',
        'total_price',
        'company_id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'id' => 'string',
        'quote_id' => 'string',
        'product_id' => 'string',
        'variant_id' => 'string',
        'unit_id' => 'string',
        'unit_quantity' => 'decimal:4',
        'base_quantity' => 'integer',
        'packaging_breakdown' => 'array',
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function quote()
    {
        return $this->belongsTo(Quote::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function packagingUnit()
    {
        return $this->belongsTo(\App\Models\ProductPackagingUnit::class, 'unit_id');
    }

    /**
     * Get display quantity with unit
     */
    public function getQuantityDisplayAttribute()
    {
        if ($this->unit_id && $this->packagingUnit) {
            return "{$this->unit_quantity} {$this->packagingUnit->unit_abbreviation}";
        }
        return (string) $this->quantity;
    }

    /**
     * Get packaging breakdown display
     */
    public function getPackagingBreakdownDisplayAttribute()
    {
        if ($this->packaging_breakdown && isset($this->packaging_breakdown['display_text'])) {
            return $this->packaging_breakdown['display_text'];
        }
        return null;
    }
}