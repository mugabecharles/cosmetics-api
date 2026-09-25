<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Product;
use App\Models\Inventory;
use App\Models\StockMovement;
use App\Models\Payment;
use App\Models\Supplier;
use App\Helpers\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PurchaseController extends Controller
{
    public function index(Request $request)
    {
        $query = Purchase::with(['supplier', 'user', 'items.product']);
        if ($request->supplier_id) $query->where('supplier_id', $request->supplier_id);
        if ($request->status)      $query->where('status', $request->status);
        if ($request->date_from)   $query->where('purchase_date', '>=', $request->date_from);
        if ($request->date_to)     $query->where('purchase_date', '<=', $request->date_to);
        return response()->json($query->latest()->paginate(20));
    }

    public function store(Request $request)
    {
        $v = Validator::make($request->all(), [
            'supplier_id'          => 'required|exists:suppliers,id',
            'purchase_date'        => 'required|date',
            'items'                => 'required|array|min:1',
            'items.*.product_id'   => 'required|exists:products,id',
            'items.*.quantity'     => 'required|numeric|min:0.01',
            'items.*.unit_cost'    => 'required|numeric|min:0',
            'amount_paid'          => 'required|numeric|min:0',
            'payment_method'       => 'required|in:cash,mtn_momo,airtel_money,bank,card,credit,other',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        DB::beginTransaction();
        try {
            $subtotal = 0;
            $itemsData = [];
            foreach ($request->items as $item) {
                $lineTotal = $item['quantity'] * $item['unit_cost'];
                $subtotal += $lineTotal;
                $itemsData[] = [
                    'product_id' => $item['product_id'],
                    'quantity'   => $item['quantity'],
                    'unit_cost'  => $item['unit_cost'],
                    'total'      => $lineTotal,
                ];
            }

            $discount    = $request->discount ?? 0;
            $total       = $subtotal - $discount;
            $amountPaid  = min($request->amount_paid, $total);
            $balance     = $total - $amountPaid;

            // Purchase number
            $last = Purchase::latest()->first();
            $num  = $last ? ((int) substr($last->purchase_number, -5)) + 1 : 1;
            $purchaseNumber = 'PO-' . str_pad($num, 5, '0', STR_PAD_LEFT);

            $purchase = Purchase::create([
                'purchase_number' => $purchaseNumber,
                'supplier_id'     => $request->supplier_id,
                'user_id'         => auth('api')->id(),
                'invoice_number'  => $request->invoice_number,
                'subtotal'        => $subtotal,
                'discount'        => $discount,
                'total'           => $total,
                'amount_paid'     => $amountPaid,
                'balance'         => $balance,
                'payment_method'  => $request->payment_method,
                'status'          => 'received',
                'purchase_date'   => $request->purchase_date,
                'notes'           => $request->notes,
            ]);

            // Create items & increase stock
            foreach ($itemsData as $itemData) {
                PurchaseItem::create(['purchase_id' => $purchase->id] + $itemData);

                $inv = Inventory::where('product_id', $itemData['product_id'])->first();
                $before = $inv ? (float)$inv->quantity : 0;
                $after  = $before + $itemData['quantity'];

                if ($inv) {
                    $inv->update(['quantity' => $after]);
                } else {
                    Inventory::create(['product_id' => $itemData['product_id'], 'quantity' => $after]);
                }

                // Update product purchase price if changed
                Product::where('id', $itemData['product_id'])
                    ->update(['purchase_price' => $itemData['unit_cost']]);

                StockMovement::create([
                    'product_id'      => $itemData['product_id'],
                    'user_id'         => auth('api')->id(),
                    'type'            => 'purchase',
                    'quantity'        => $itemData['quantity'],
                    'quantity_before' => $before,
                    'quantity_after'  => $after,
                    'reference'       => $purchaseNumber,
                ]);
            }

            // Supplier balance
            if ($balance > 0) {
                Supplier::find($request->supplier_id)->increment('balance', $balance);
            }

            // Record payment
            if ($amountPaid > 0) {
                Payment::create([
                    'reference'        => 'PAY-' . strtoupper(uniqid()),
                    'payment_type'     => 'purchase_payment',
                    'payment_method'   => $request->payment_method,
                    'amount'           => $amountPaid,
                    'supplier_id'      => $request->supplier_id,
                    'purchase_id'      => $purchase->id,
                    'user_id'          => auth('api')->id(),
                    'transaction_date' => now()->toDateString(),
                    'status'           => 'completed',
                ]);
            }

            DB::commit();
            AuditLogger::log('CREATE_PURCHASE', 'Purchases', $purchase->id, [], ['number' => $purchaseNumber, 'total' => $total]);
            return response()->json($purchase->load(['supplier', 'user', 'items.product']), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function show(Purchase $purchase)
    {
        return response()->json($purchase->load(['supplier', 'user', 'items.product', 'payments']));
    }

    public function todayStats()
    {
        $today = now()->toDateString();
        return response()->json([
            'total_purchases' => Purchase::where('purchase_date', $today)->sum('total'),
            'count'           => Purchase::where('purchase_date', $today)->count(),
        ]);
    }
}
