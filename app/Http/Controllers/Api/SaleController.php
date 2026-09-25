<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Product;
use App\Models\Inventory;
use App\Models\StockMovement;
use App\Models\Payment;
use App\Models\Customer;
use App\Models\Setting;
use App\Helpers\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        $query = Sale::with(['customer', 'user', 'items.product']);

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('receipt_number', 'like', "%{$request->search}%");
            });
        }
        if ($request->customer_id)     $query->where('customer_id', $request->customer_id);
        if ($request->user_id)         $query->where('user_id', $request->user_id);
        if ($request->payment_method)  $query->where('payment_method', $request->payment_method);
        if ($request->payment_status)  $query->where('payment_status', $request->payment_status);
        if ($request->sale_status)     $query->where('sale_status', $request->sale_status);
        if ($request->date_from)       $query->whereDate('created_at', '>=', $request->date_from);
        if ($request->date_to)         $query->whereDate('created_at', '<=', $request->date_to);

        return response()->json($query->latest()->paginate(20));
    }

    public function store(Request $request)
    {
        $v = Validator::make($request->all(), [
            'items'                => 'required|array|min:1',
            'items.*.product_id'   => 'required|exists:products,id',
            'items.*.quantity'     => 'required|numeric|min:0.01',
            'items.*.unit_price'   => 'required|numeric|min:0',
            'items.*.discount'     => 'nullable|numeric|min:0',
            'payment_method'       => 'required|in:cash,mtn_momo,airtel_money,bank,card,credit,other',
            'amount_paid'          => 'required|numeric|min:0',
            'customer_id'          => 'nullable|exists:customers,id',
            'discount'             => 'nullable|numeric|min:0',
            'tax'                  => 'nullable|numeric|min:0',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        // Credit sales require a registered customer
        if ($request->payment_method === 'credit' && !$request->customer_id) {
            return response()->json(['message' => 'Credit sales require a registered customer'], 400);
        }

        DB::beginTransaction();
        try {
            // Validate stock availability
            foreach ($request->items as $item) {
                $product = Product::with('inventory')->findOrFail($item['product_id']);
                $available = $product->inventory ? (float)$product->inventory->quantity : 0;
                $allowNegative = Setting::get('allow_negative_stock', 'no') === 'yes';
                if (!$allowNegative && $available < $item['quantity']) {
                    DB::rollBack();
                    return response()->json([
                        'message' => "Insufficient stock for {$product->name}. Available: {$available}"
                    ], 400);
                }
            }

            // Calculate totals
            $subtotal = 0;
            $saleItemsData = [];
            foreach ($request->items as $item) {
                $product = Product::findOrFail($item['product_id']);
                $itemDiscount = $item['discount'] ?? 0;
                $lineTotal = ($item['quantity'] * $item['unit_price']) - $itemDiscount;
                $profit = ($item['unit_price'] - $product->purchase_price) * $item['quantity'] - $itemDiscount;
                $subtotal += $lineTotal;
                $saleItemsData[] = [
                    'product_id' => $item['product_id'],
                    'quantity'   => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount'   => $itemDiscount,
                    'cost_price' => $product->purchase_price,
                    'total'      => $lineTotal,
                    'profit'     => $profit,
                ];
            }

            $saleDiscount = $request->discount ?? 0;
            $tax          = $request->tax ?? 0;
            $total        = $subtotal - $saleDiscount + $tax;
            $amountPaid   = min($request->amount_paid, $total);
            $balance      = $total - $amountPaid;

            $paymentStatus = $balance <= 0 ? 'paid' : ($amountPaid > 0 ? 'partial' : 'unpaid');

            // Generate receipt number
            $prefix = Setting::get('receipt_prefix', 'CS');
            $lastSale = Sale::latest()->first();
            $nextNum = $lastSale ? ((int) substr($lastSale->receipt_number, -6)) + 1 : 1;
            $receiptNumber = $prefix . '-' . str_pad($nextNum, 6, '0', STR_PAD_LEFT);

            $sale = Sale::create([
                'receipt_number' => $receiptNumber,
                'customer_id'    => $request->customer_id,
                'user_id'        => auth('api')->id(),
                'subtotal'       => $subtotal,
                'discount'       => $saleDiscount,
                'tax'            => $tax,
                'total'          => $total,
                'amount_paid'    => $amountPaid,
                'balance'        => $balance,
                'payment_method' => $request->payment_method,
                'payment_status' => $paymentStatus,
                'sale_status'    => 'completed',
                'notes'          => $request->notes,
            ]);

            // Create sale items & deduct stock
            foreach ($saleItemsData as $itemData) {
                SaleItem::create(['sale_id' => $sale->id] + $itemData);

                $inv = Inventory::where('product_id', $itemData['product_id'])->first();
                $before = $inv ? (float)$inv->quantity : 0;
                $after  = $before - $itemData['quantity'];
                if ($inv) {
                    $inv->update(['quantity' => max(0, $after)]);
                }

                StockMovement::create([
                    'product_id'      => $itemData['product_id'],
                    'user_id'         => auth('api')->id(),
                    'type'            => 'sale',
                    'quantity'        => -$itemData['quantity'],
                    'quantity_before' => $before,
                    'quantity_after'  => max(0, $after),
                    'reference'       => $receiptNumber,
                ]);
            }

            // Record payment
            if ($amountPaid > 0) {
                Payment::create([
                    'reference'        => 'PAY-' . strtoupper(uniqid()),
                    'payment_type'     => 'sale_payment',
                    'payment_method'   => $request->payment_method,
                    'amount'           => $amountPaid,
                    'customer_id'      => $request->customer_id,
                    'sale_id'          => $sale->id,
                    'user_id'          => auth('api')->id(),
                    'transaction_date' => now()->toDateString(),
                    'status'           => 'completed',
                ]);
            }

            // Update customer balance if credit
            if ($balance > 0 && $request->customer_id) {
                Customer::find($request->customer_id)->increment('balance', $balance);
            }

            DB::commit();
            AuditLogger::log('CREATE_SALE', 'Sales', $sale->id, [], ['receipt' => $receiptNumber, 'total' => $total]);
            return response()->json($sale->load(['customer', 'user', 'items.product']), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function show(Sale $sale)
    {
        return response()->json($sale->load(['customer', 'user', 'items.product', 'payments']));
    }

    public function cancel(Request $request, Sale $sale)
    {
        if ($sale->sale_status === 'cancelled') {
            return response()->json(['message' => 'Sale already cancelled'], 400);
        }

        DB::beginTransaction();
        try {
            // Restore stock
            foreach ($sale->items as $item) {
                $inv = Inventory::where('product_id', $item->product_id)->first();
                if ($inv) {
                    $before = (float)$inv->quantity;
                    $inv->increment('quantity', $item->quantity);
                    StockMovement::create([
                        'product_id'      => $item->product_id,
                        'user_id'         => auth('api')->id(),
                        'type'            => 'return_in',
                        'quantity'        => $item->quantity,
                        'quantity_before' => $before,
                        'quantity_after'  => $before + $item->quantity,
                        'reference'       => 'CANCEL:' . $sale->receipt_number,
                    ]);
                }
            }

            // Restore customer balance
            if ($sale->balance > 0 && $sale->customer_id) {
                Customer::find($sale->customer_id)->decrement('balance', $sale->balance);
            }

            $sale->update(['sale_status' => 'cancelled']);
            DB::commit();
            AuditLogger::log('CANCEL_SALE', 'Sales', $sale->id, ['status' => 'completed'], ['status' => 'cancelled']);
            return response()->json(['message' => 'Sale cancelled']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function todayStats()
    {
        $today = now()->toDateString();
        $sales = Sale::whereDate('created_at', $today)->where('sale_status', 'completed');
        return response()->json([
            'total_sales'    => $sales->sum('total'),
            'total_count'    => $sales->count(),
            'cash_sales'     => (clone $sales)->where('payment_method', 'cash')->sum('total'),
            'momo_sales'     => (clone $sales)->where('payment_method', 'mtn_momo')->sum('total'),
            'credit_sales'   => (clone $sales)->where('payment_method', 'credit')->sum('total'),
            'total_profit'   => SaleItem::whereHas('sale', fn($q) => $q->whereDate('created_at', $today)->where('sale_status', 'completed'))->sum('profit'),
        ]);
    }
}
