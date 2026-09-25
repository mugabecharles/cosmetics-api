<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Helpers\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $query = Expense::with(['category', 'user']);
        if ($request->category_id)     $query->where('category_id', $request->category_id);
        if ($request->date_from)        $query->where('expense_date', '>=', $request->date_from);
        if ($request->date_to)          $query->where('expense_date', '<=', $request->date_to);
        if ($request->payment_method)   $query->where('payment_method', $request->payment_method);
        return response()->json($query->latest('expense_date')->paginate(20));
    }

    public function store(Request $request)
    {
        $v = Validator::make($request->all(), [
            'category_id'    => 'required|exists:expense_categories,id',
            'description'    => 'required|string',
            'amount'         => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,mtn_momo,airtel_money,bank,card,other',
            'expense_date'   => 'required|date',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $expense = Expense::create([
            'category_id'     => $request->category_id,
            'user_id'         => auth('api')->id(),
            'description'     => $request->description,
            'amount'          => $request->amount,
            'payment_method'  => $request->payment_method,
            'receipt_number'  => $request->receipt_number,
            'expense_date'    => $request->expense_date,
            'approval_status' => 'approved',
        ]);

        AuditLogger::log('CREATE_EXPENSE', 'Expenses', $expense->id, [], $expense->toArray());
        return response()->json($expense->load(['category', 'user']), 201);
    }

    public function show(Expense $expense)
    {
        return response()->json($expense->load(['category', 'user']));
    }

    public function update(Request $request, Expense $expense)
    {
        $v = Validator::make($request->all(), [
            'category_id' => 'required|exists:expense_categories,id',
            'description' => 'required|string',
            'amount'      => 'required|numeric|min:0.01',
            'expense_date'=> 'required|date',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $old = $expense->toArray();
        $expense->update($request->only(['category_id', 'description', 'amount', 'payment_method', 'receipt_number', 'expense_date']));
        AuditLogger::log('UPDATE_EXPENSE', 'Expenses', $expense->id, $old, $expense->toArray());
        return response()->json($expense->load(['category', 'user']));
    }

    public function destroy(Expense $expense)
    {
        AuditLogger::log('DELETE_EXPENSE', 'Expenses', $expense->id, $expense->toArray(), []);
        $expense->delete();
        return response()->json(['message' => 'Expense deleted']);
    }

    public function todayStats()
    {
        return response()->json([
            'total' => Expense::whereDate('expense_date', now()->toDateString())->sum('amount'),
        ]);
    }

    // Expense categories
    public function categories()
    {
        return response()->json(ExpenseCategory::where('status', 'active')->orderBy('name')->get());
    }

    public function storeCategory(Request $request)
    {
        $v = Validator::make($request->all(), ['name' => 'required|string|unique:expense_categories|max:100']);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);
        $cat = ExpenseCategory::create(['name' => $request->name, 'description' => $request->description, 'status' => 'active']);
        return response()->json($cat, 201);
    }

    public function updateCategory(Request $request, ExpenseCategory $expenseCategory)
    {
        $expenseCategory->update($request->only(['name', 'description', 'status']));
        return response()->json($expenseCategory);
    }
}
