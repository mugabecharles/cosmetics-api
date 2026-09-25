<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\ReturnItem;
use App\Models\Inventory;
use App\Models\StockMovement;
use App\Helpers\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ReturnController extends Controller
{
    public function index(Request $request)
    {
        $query = SaleReturn::with(['sale', 'customer', 'user', 'items.product']);
        if ($request->date_from) $query->whereDate('created_at', '>=', $request->date_from);
        if ($request->date_to)   $query->whereDate('created_at', '<=', $request->date_to);
        return response()->json($query->latest()->paginate(20));
    }

    public function store(Request $request)
    {
        $v = Validator::make($request->all(), [
            'sale_id'              => 'required|exists:sales,id',
            'reason'               => 'required|in:wrong_product,damaged,customer_changed_mind,product_defect,wrong_quantity,other',
            'items'                => 'required|array|min:1',
            'items.*.product_id'   => 'required|exists:products,id',
            'items.*.quantity'     => 'required|numeric|min:0.01',
            'items.*.restock'      => 'boolean',
            'refund_method'        => 'required|in:cash,mtn_momo,airtel_money,bank,credit,other',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $sale = Sale::with('items')->findOrFail($request->sale_id);

        DB::beginTransaction();
        try {
            $total = 0;
            $itemsData = [];

            foreach ($request->items as $item) {
                // Find the original sale item
                $saleItem = $sale->items->where('product_id', $item['product_id'])->first();
                if (!$saleItem) {
                    DB::rollBack();
                    return response()->json(['message' => 'Product not found in original sale'], 400);
                }
                if ($item['quantity'] > $saleItem->quantity) {
                    DB::rollBack();
                    return response()->json(['message' => 'Return quantity exceeds sold quantity'], 400);
                }
                $lineTotal = $item['quantity'] * $saleItem->unit_price;
                $total += $lineTotal;
                $itemsData[] = [
                    'product_id' => $item['product_id'],
                    'quantity'   => $item['quantity'],
                    'unit_price' => $saleItem->unit_price,
                    'amount'     => $lineTotal,
                    'restock'    => $item['restock'] ?? true,
                ];
            }

            $last = SaleReturn::latest()->first();
            $num  = $last ? ((int) substr($last->return_number, -5)) + 1 : 1;
            $returnNumber = 'RET-' . str_pad($num, 5, '0', STR_PAD_LEFT);

            $saleReturn = SaleReturn::create([
                'return_number' => $returnNumber,
                'sale_id'       => $sale->id,
                'customer_id'   => $sale->customer_id,
                'user_id'       => auth('api')->id(),
                'reason'        => $request->reason,
                'notes'         => $request->notes,
                'total_amount'  => $total,
                'refund_method' => $request->refund_method,
                'status'        => 'processed',
            ]);

            foreach ($itemsData as $itemData) {
                ReturnItem::create(['return_id' => $saleReturn->id] + $itemData);

                if ($itemData['restock']) {
                    $inv = Inventory::where('product_id', $itemData['product_id'])->first();
                    $before = $inv ? (float)$inv->quantity : 0;
                    $after  = $before + $itemData['quantity'];
                    if ($inv) {
                        $inv->update(['quantity' => $after]);
                    } else {
                        Inventory::create(['product_id' => $itemData['product_id'], 'quantity' => $after]);
                    }
                    StockMovement::create([
                        'product_id'      => $itemData['product_id'],
                        'user_id'         => auth('api')->id(),
                        'type'            => 'return_in',
                        'quantity'        => $itemData['quantity'],
                        'quantity_before' => $before,
                        'quantity_after'  => $after,
                        'reference'       => $returnNumber,
                    ]);
                }
            }

            DB::commit();
            AuditLogger::log('CREATE_RETURN', 'Returns', $saleReturn->id, [], ['return_number' => $returnNumber, 'total' => $total]);
            return response()->json($saleReturn->load(['sale', 'customer', 'user', 'items.product']), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function show(SaleReturn $return)
    {
        return response()->json($return->load(['sale', 'customer', 'user', 'items.product']));
    }
}
