<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $query = Payment::with(['customer', 'supplier', 'sale', 'user']);
        if ($request->payment_type)   $query->where('payment_type', $request->payment_type);
        if ($request->payment_method) $query->where('payment_method', $request->payment_method);
        if ($request->customer_id)    $query->where('customer_id', $request->customer_id);
        if ($request->supplier_id)    $query->where('supplier_id', $request->supplier_id);
        if ($request->date_from)      $query->where('transaction_date', '>=', $request->date_from);
        if ($request->date_to)        $query->where('transaction_date', '<=', $request->date_to);
        return response()->json($query->latest('transaction_date')->paginate(20));
    }

    public function reconciliation(Request $request)
    {
        $date = $request->date ?? now()->toDateString();
        $methods = ['cash', 'mtn_momo', 'airtel_money', 'bank', 'card', 'other'];
        $data = [];
        foreach ($methods as $method) {
            $total = Payment::where('payment_method', $method)
                ->where('transaction_date', $date)
                ->where('status', 'completed')
                ->sum('amount');
            if ($total > 0) {
                $data[] = ['method' => $method, 'system_total' => $total];
            }
        }
        return response()->json(['date' => $date, 'breakdown' => $data]);
    }
}
