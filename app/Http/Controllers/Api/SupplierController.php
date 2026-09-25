<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\Payment;
use App\Helpers\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $query = Supplier::query();
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('phone', 'like', "%{$request->search}%")
                  ->orWhere('supplier_code', 'like', "%{$request->search}%");
            });
        }
        if ($request->status) $query->where('status', $request->status);
        return response()->json($query->orderBy('name')->paginate(20));
    }

    public function all()
    {
        return response()->json(Supplier::where('status', 'active')->orderBy('name')->get(['id', 'name', 'phone', 'supplier_code', 'balance']));
    }

    public function store(Request $request)
    {
        $v = Validator::make($request->all(), [
            'name'  => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $code = 'SUP-' . str_pad(Supplier::count() + 1, 4, '0', STR_PAD_LEFT);
        $supplier = Supplier::create([
            'supplier_code'  => $code,
            'name'           => $request->name,
            'contact_person' => $request->contact_person,
            'phone'          => $request->phone,
            'email'          => $request->email,
            'address'        => $request->address,
            'tax_number'     => $request->tax_number,
            'bank_details'   => $request->bank_details,
            'balance'        => 0,
            'status'         => 'active',
        ]);

        AuditLogger::log('CREATE_SUPPLIER', 'Suppliers', $supplier->id, [], $supplier->toArray());
        return response()->json($supplier, 201);
    }

    public function show(Supplier $supplier)
    {
        $supplier->load(['purchases' => fn($q) => $q->latest()->limit(10)]);
        return response()->json($supplier);
    }

    public function update(Request $request, Supplier $supplier)
    {
        $v = Validator::make($request->all(), [
            'name'   => 'required|string|max:255',
            'status' => 'required|in:active,inactive',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $old = $supplier->toArray();
        $supplier->update($request->only(['name', 'contact_person', 'phone', 'email', 'address', 'tax_number', 'bank_details', 'status']));
        AuditLogger::log('UPDATE_SUPPLIER', 'Suppliers', $supplier->id, $old, $supplier->toArray());
        return response()->json($supplier);
    }

    public function statement(Supplier $supplier)
    {
        return response()->json([
            'supplier'  => $supplier,
            'purchases' => $supplier->purchases()->with('items.product')->latest()->get(),
            'payments'  => $supplier->payments()->latest()->get(),
            'balance'   => $supplier->balance,
        ]);
    }

    public function recordPayment(Request $request, Supplier $supplier)
    {
        $v = Validator::make($request->all(), [
            'amount'         => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,mtn_momo,airtel_money,bank,card,other',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        DB::beginTransaction();
        try {
            Payment::create([
                'reference'        => 'PAY-' . strtoupper(uniqid()),
                'payment_type'     => 'supplier_payment',
                'payment_method'   => $request->payment_method,
                'amount'           => $request->amount,
                'supplier_id'      => $supplier->id,
                'user_id'          => auth('api')->id(),
                'transaction_date' => now()->toDateString(),
                'status'           => 'completed',
                'notes'            => $request->notes,
            ]);

            $supplier->decrement('balance', $request->amount);
            DB::commit();
            return response()->json(['message' => 'Payment recorded', 'new_balance' => $supplier->fresh()->balance]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
