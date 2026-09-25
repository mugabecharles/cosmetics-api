<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Inventory;
use App\Models\StockMovement;
use App\Helpers\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::with(['category', 'brand', 'supplier', 'inventory']);

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('sku', 'like', "%{$request->search}%")
                  ->orWhere('barcode', 'like', "%{$request->search}%");
            });
        }
        if ($request->category_id) $query->where('category_id', $request->category_id);
        if ($request->brand_id)    $query->where('brand_id', $request->brand_id);
        if ($request->status)      $query->where('status', $request->status);
        if ($request->low_stock)   $query->whereHas('inventory', function ($q) {
            $q->whereColumn('quantity', '<=', 'products.reorder_level');
        });

        return response()->json($query->orderBy('name')->paginate(20));
    }

    public function all(Request $request)
    {
        $query = Product::with(['category', 'brand', 'inventory'])
            ->where('status', 'active');
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('sku', 'like', "%{$request->search}%")
                  ->orWhere('barcode', $request->search);
            });
        }
        return response()->json($query->orderBy('name')->limit(50)->get());
    }

    public function store(Request $request)
    {
        $v = Validator::make($request->all(), [
            'name'            => 'required|string|max:255',
            'category_id'     => 'nullable|exists:categories,id',
            'brand_id'        => 'nullable|exists:brands,id',
            'supplier_id'     => 'nullable|exists:suppliers,id',
            'unit'            => 'required|string',
            'purchase_price'  => 'required|numeric|min:0',
            'selling_price'   => 'required|numeric|min:0',
            'wholesale_price' => 'nullable|numeric|min:0',
            'reorder_level'   => 'required|integer|min:0',
            'opening_stock'   => 'required|integer|min:0',
            'expiry_tracking' => 'boolean',
            'expiry_date'     => 'nullable|date',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        DB::beginTransaction();
        try {
            $sku = 'SKU-' . strtoupper(Str::random(6));
            while (Product::where('sku', $sku)->exists()) {
                $sku = 'SKU-' . strtoupper(Str::random(6));
            }

            $data = $request->only([
                'name', 'category_id', 'brand_id', 'supplier_id', 'description',
                'unit', 'purchase_price', 'selling_price', 'wholesale_price',
                'reorder_level', 'expiry_tracking', 'expiry_date', 'batch_number',
            ]);
            $data['sku'] = $sku;
            $data['barcode'] = $request->barcode ?: $sku;
            $data['status'] = 'active';

            $product = Product::create($data);

            // Create inventory record
            $qty = (float)($request->opening_stock ?? 0);
            Inventory::create(['product_id' => $product->id, 'quantity' => $qty]);

            if ($qty > 0) {
                StockMovement::create([
                    'product_id'      => $product->id,
                    'user_id'         => auth('api')->id(),
                    'type'            => 'opening',
                    'quantity'        => $qty,
                    'quantity_before' => 0,
                    'quantity_after'  => $qty,
                    'reference'       => 'Opening stock',
                ]);
            }

            DB::commit();
            AuditLogger::log('CREATE_PRODUCT', 'Products', $product->id, [], $product->toArray());
            return response()->json($product->load(['category', 'brand', 'inventory']), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to create product: ' . $e->getMessage()], 500);
        }
    }

    public function show(Product $product)
    {
        return response()->json($product->load(['category', 'brand', 'supplier', 'inventory']));
    }

    public function update(Request $request, Product $product)
    {
        $v = Validator::make($request->all(), [
            'name'            => 'required|string|max:255',
            'category_id'     => 'nullable|exists:categories,id',
            'brand_id'        => 'nullable|exists:brands,id',
            'supplier_id'     => 'nullable|exists:suppliers,id',
            'unit'            => 'required|string',
            'purchase_price'  => 'required|numeric|min:0',
            'selling_price'   => 'required|numeric|min:0',
            'wholesale_price' => 'nullable|numeric|min:0',
            'reorder_level'   => 'required|integer|min:0',
            'status'          => 'required|in:active,inactive',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $old = $product->toArray();
        $product->update($request->only([
            'name', 'category_id', 'brand_id', 'supplier_id', 'description',
            'unit', 'purchase_price', 'selling_price', 'wholesale_price',
            'reorder_level', 'expiry_tracking', 'expiry_date', 'batch_number',
            'barcode', 'status',
        ]));

        AuditLogger::log('UPDATE_PRODUCT', 'Products', $product->id, $old, $product->fresh()->toArray());
        return response()->json($product->load(['category', 'brand', 'inventory']));
    }

    public function destroy(Product $product)
    {
        if ($product->saleItems()->count() > 0 || $product->purchaseItems()->count() > 0) {
            $product->update(['status' => 'inactive']);
            return response()->json(['message' => 'Product has transaction history — marked inactive']);
        }
        AuditLogger::log('DELETE_PRODUCT', 'Products', $product->id, $product->toArray(), []);
        $product->inventory()->delete();
        $product->delete();
        return response()->json(['message' => 'Product deleted']);
    }

    public function adjust(Request $request, Product $product)
    {
        $v = Validator::make($request->all(), [
            'type'     => 'required|in:adjustment,damaged,expired',
            'quantity' => 'required|numeric',
            'notes'    => 'nullable|string',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        DB::beginTransaction();
        try {
            $inv = $product->inventory;
            $before = $inv ? (float)$inv->quantity : 0;
            $after  = $before + (float)$request->quantity;

            if ($after < 0) return response()->json(['message' => 'Stock cannot go below zero'], 400);

            if ($inv) {
                $inv->update(['quantity' => $after]);
            } else {
                Inventory::create(['product_id' => $product->id, 'quantity' => $after]);
            }

            StockMovement::create([
                'product_id'      => $product->id,
                'user_id'         => auth('api')->id(),
                'type'            => $request->type,
                'quantity'        => (float)$request->quantity,
                'quantity_before' => $before,
                'quantity_after'  => $after,
                'notes'           => $request->notes,
            ]);

            DB::commit();
            AuditLogger::log('STOCK_ADJUSTMENT', 'Inventory', $product->id, ['qty' => $before], ['qty' => $after]);
            return response()->json(['message' => 'Stock adjusted', 'new_quantity' => $after]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function lowStock()
    {
        $products = Product::with(['inventory', 'category'])
            ->where('status', 'active')
            ->whereHas('inventory', function ($q) {
                $q->whereRaw('quantity <= products.reorder_level');
            })
            ->get();
        return response()->json($products);
    }

    public function expiring(Request $request)
    {
        $days = $request->days ?? 30;
        $products = Product::with(['inventory', 'category'])
            ->where('status', 'active')
            ->where('expiry_tracking', true)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', now()->addDays($days))
            ->orderBy('expiry_date')
            ->get();
        return response()->json($products);
    }
}
