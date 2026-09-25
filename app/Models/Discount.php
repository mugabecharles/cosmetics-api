<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    protected $fillable = [
        'name', 'type', 'value', 'product_id', 'customer_id',
        'start_date', 'end_date', 'minimum_amount', 'requires_approval', 'status',
    ];

    protected $casts = [
        'value'              => 'decimal:2',
        'minimum_amount'     => 'decimal:2',
        'requires_approval'  => 'boolean',
        'start_date'         => 'date',
        'end_date'           => 'date',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
