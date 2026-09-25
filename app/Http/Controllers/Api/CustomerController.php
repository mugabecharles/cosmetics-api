<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Payment;
use App\Helpers\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = Customer::query();
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('phone', 'like', "%{$request->search}%")
                  ->orWhere('customer_code', 'like', "%{$request->search}%");
            });
        }
        if ($request->customer_type) $query->where('customer_type', $request->customer_type);
        if ($request->status)        $query->where('status', $request->status);
        return response()->json($query->orderBy('name')->paginate(20));
    }

    public function all()
    {
        return response()->json(Customer::where('status', 'active')->orderBy('name')->get(['id', 'name', 'phone', 'customer_code', 'balance', 'credit_limit', 'customer_type']));
    }

    public function store(Request $request)
    {
        $v = Validator::make($request->all(), [
            'name'          => 'required|string|max:255',
            'phone'         => 'nullable|string|max:20',
            'email'         => 'nullable|email',
            'customer_type' => 'required|in:walk_in,retail,wholesale,vip,credit',
            'credit_limit'  => 'required_if:customer_type,credit|numeric|min:0',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $code = 'CUST-' . str_pad(Customer::count() + 1, 4, '0', STR_PAD_LEFT);
        $customer = Customer::create([
            'customer_code' => $code,
            'name'          => $request->name,
            'phone'         => $request->phone,
            'email'         => $request->email,
            'address'       => $request->address,
            'gender'        => $request->gender,
            'customer_type' => $request->customer_type,
            'credit_limit'  => $request->credit_limit ?? 0,
            'balance'       => 0,
            'status'        => 'active',
        ]);

        AuditLogger::log('CREATE_CUSTOMER', 'Customers', $customer->id, [], $customer->toArray());
        return response()->json($customer, 201);
    }

    public function show(Customer $customer)
    {
        $customer->load(['sales' => fn($q) => $q->latest()->limit(10)]);
        $stats = [
            'total_purchases' => $customer->sales()->where('sale_status', 'completed')->sum('total'),
            'total_paid'      => $customer->payments()->sum('amount'),
            'outstanding'     => $customer->balance,
        ];
        return response()->json(['customer' => $customer, 'stats' => $stats]);
    }

    public function update(Request $request, Customer $customer)
    {
        $v = Validator::make($request->all(), [
            'name'          => 'required|string|max:255',
            'customer_type' => 'required|in:walk_in,retail,wholesale,vip,credit',
            'credit_limit'  => 'numeric|min:0',
            'status'        => 'required|in:active,inactive',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $old = $customer->toArray();
        $customer->update($request->only(['name', 'phone', 'email', 'address', 'gender', 'customer_type', 'credit_limit', 'status']));
        AuditLogger::log('UPDATE_CUSTOMER', 'Customers', $customer->id, $old, $customer->toArray());
        return response()->json($customer);
    }

    public function statement(Customer $customer)
    {
        $sales = $customer->sales()->with('items.product')->latest()->get();
        $payments = $customer->payments()->latest()->get();
        return response()->json([
            'customer' => $customer,
            'sales'    => $sales,
            'payments' => $payments,
            'balance'  => $customer->balance,
        ]);
    }

    public function recordPayment(Request $request, Customer $customer)
    {
        $v = Validator::make($request->all(), [
            'amount'         => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,mtn_momo,airtel_money,bank,card,other',
            'notes'          => 'nullable|string',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        if ($request->amount > $customer->balance) {
            return response()->json(['message' => 'Payment exceeds outstanding balance'], 400);
        }

        DB::beginTransaction();
        try {
            $ref = 'PAY-' . strtoupper(uniqid());
            Payment::create([
                'reference'        => $ref,
                'payment_type'     => 'customer_payment',
                'payment_method'   => $request->payment_method,
                'amount'           => $request->amount,
                'customer_id'      => $customer->id,
                'user_id'          => auth('api')->id(),
                'transaction_date' => now()->toDateString(),
                'status'           => 'completed',
                'notes'            => $request->notes,
            ]);

            $customer->decrement('balance', $request->amount);
            DB::commit();

            AuditLogger::log('CUSTOMER_PAYMENT', 'Customers', $customer->id, ['balance' => $customer->balance + $request->amount], ['balance' => $customer->fresh()->balance]);
            return response()->json(['message' => 'Payment recorded', 'new_balance' => $customer->fresh()->balance]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
