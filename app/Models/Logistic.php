<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Logistic extends Model
{
    use HasFactory;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'order_dispatch_id',
        'company_id',
        'delivery_person_id',
        'driver_name',
        'driver_contact',
        'vehicle_registration',
        'vehicle_type',
        'logistics_provider',
        'delivery_cost',
        'amount_paid',
        'payment_method',
        'payment_status',
        'payment_reference',
        'payment_date',
        'cheque_number',
        'bank_name',
        'cheque_maturity_date',
        'delivery_method',
        'vehicle_id',
        'tracking_number',
        'delivery_status',
        'recipient_name',
        'recipient_phone',
        'delivery_address',
        'city',
        'state',
        'region',
        'country',
        'dispatch_time',
        'actual_delivery_time',
        'signature',
        'estimated_delivery_time',
        'pickup_location',
        'delivery_location',
        'notes',
        'status', // Internal status
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'estimated_delivery_time' => 'datetime',
        'dispatch_time' => 'datetime',
        'actual_delivery_time' => 'datetime',
        'payment_date' => 'date',
        'cheque_maturity_date' => 'date',
        'delivery_cost' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function orderDispatch()
    {
        return $this->belongsTo(OrderDispatch::class);
    }

    public function deliveryPerson()
    {
        return $this->belongsTo(DeliveryPerson::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}