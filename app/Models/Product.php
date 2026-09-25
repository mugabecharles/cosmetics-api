<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'sku', 'barcode', 'name', 'category_id', 'brand_id', 'supplier_id',
        'description', 'unit', 'purchase_price', 'selling_price', 'wholesale_price',
        'reorder_level', 'expiry_tracking', 'expiry_date', 'batch_number',
        'image', 'status',
    ];

    protected $casts = [
        'expiry_tracking' => 'boolean',
        'expiry_date'     => 'date',
        'purchase_price'  => 'decimal:2',
        'selling_price'   => 'decimal:2',
        'wholesale_price' => 'decimal:2',
    ];

    // Relationships
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function inventory()
    {
        return $this->hasOne(Inventory::class);
    }

    public function saleItems()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function purchaseItems()
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

    // Helper: current stock
    public function currentStock(): float
    {
        return $this->inventory ? (float) $this->inventory->quantity : 0;
    }

    // Helper: is low stock
    public function isLowStock(): bool
    {
        return $this->currentStock() <= $this->reorder_level && $this->currentStock() > 0;
    }

    // Helper: is out of stock
    public function isOutOfStock(): bool
    {
        return $this->currentStock() <= 0;
    }
}
