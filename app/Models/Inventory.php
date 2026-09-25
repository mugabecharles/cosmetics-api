<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    protected $table = 'inventory';

    protected $fillable = ['product_id', 'quantity', 'location'];

    protected $casts = ['quantity' => 'decimal:2'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
