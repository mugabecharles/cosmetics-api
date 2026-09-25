<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Purchase;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Inventory;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\Payment;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    // ── Dashboard stats ──────────────────────────────────────────
    public function dashboard()
    {
        $today = now()->toDateString();

        $todaySales     = Sale::whereDate('created_at', $today)->where('sale_status', 'completed');
        $todayPurchases = Purchase::where('purchase_date', $today);
        $todayExpenses  = Expense::where('expense_date', $today);

        $totalProducts   = Product::where('status', 'active')->count();
        $lowStock        = Product::where('status', 'active')
                                  ->whereHas('inventory', fn($q) => $q->whereRaw('quantity <= products.reorder_level AND quantity > 0'))
                                  ->count();
        $outOfStock      = Product::where('status', 'active')
                                  ->whereHas('inventory', fn($q) => $q->where('quantity', '<=', 0))
                                  ->count();

        $salesTotal    = (clone $todaySales)->sum('total');
        $purchTotal    = (clone $todayPurchases)->sum('total');
        $expTotal      = (clone $todayExpenses)->sum('amount');
        $todayProfit   = SaleItem::whereHas('sale', fn($q) => $q->whereDate('created_at', $today)->where('sale_status', 'completed'))->sum('profit');

        return response()->json([
            'today_sales'           => $salesTotal,
            'today_sales_count'     => (clone $todaySales)->count(),
            'today_purchases'       => $purchTotal,
            'today_expenses'        => $expTotal,
            'today_profit'          => $todayProfit,
            'total_products'        => $totalProducts,
            'low_stock'             => $lowStock,
            'out_of_stock'          => $outOfStock,
            'total_customers'       => Customer::where('status', 'active')->count(),
            'outstanding_credit'    => Customer::sum('balance'),
            'supplier_balances'     => Supplier::sum('balance'),
            'cash_sales_today'      => (clone $todaySales)->where('payment_method', 'cash')->sum('total'),
            'momo_sales_today'      => (clone $todaySales)->whereIn('payment_method', ['mtn_momo', 'airtel_money'])->sum('total'),
        ]);
    }

    // ── Sales charts ─────────────────────────────────────────────
    public function salesChart(Request $request)
    {
        $period = $request->period ?? 'weekly'; // daily, weekly, monthly, yearly

        switch ($period) {
            case 'daily':
                $data = Sale::where('sale_status', 'completed')
                    ->whereDate('created_at', now())
                    ->selectRaw('HOUR(created_at) as label, SUM(total) as total, COUNT(*) as count')
                    ->groupBy('label')->orderBy('label')->get();
                break;
            case 'weekly':
                $data = Sale::where('sale_status', 'completed')
                    ->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
                    ->selectRaw('DATE(created_at) as label, SUM(total) as total, COUNT(*) as count')
                    ->groupBy('label')->orderBy('label')->get();
                break;
            case 'monthly':
                $data = Sale::where('sale_status', 'completed')
                    ->whereYear('created_at', now()->year)
                    ->whereMonth('created_at', now()->month)
                    ->selectRaw('DATE(created_at) as label, SUM(total) as total, COUNT(*) as count')
                    ->groupBy('label')->orderBy('label')->get();
                break;
            case 'yearly':
            default:
                $data = Sale::where('sale_status', 'completed')
                    ->whereYear('created_at', now()->year)
                    ->selectRaw('MONTH(created_at) as label, SUM(total) as total, COUNT(*) as count')
                    ->groupBy('label')->orderBy('label')->get();
                break;
        }
        return response()->json($data);
    }

    // ── Sales report ──────────────────────────────────────────────
    public function sales(Request $request)
    {
        $query = Sale::with(['customer', 'user'])
            ->where('sale_status', 'completed');
        $this->applyDateFilter($query, $request);
        if ($request->user_id)        $query->where('user_id', $request->user_id);
        if ($request->payment_method) $query->where('payment_method', $request->payment_method);

        $sales = $query->latest()->paginate(50);
        $totals = Sale::where('sale_status', 'completed')
            ->when($request->date_from, fn($q) => $q->where('created_at', '>=', $request->date_from))
            ->when($request->date_to,   fn($q) => $q->where('created_at', '<=', $request->date_to . ' 23:59:59'));

        return response()->json([
            'sales'         => $sales,
            'total_revenue' => (clone $totals)->sum('total'),
            'total_profit'  => SaleItem::whereHas('sale', fn($q) => $q->where('sale_status', 'completed'))->sum('profit'),
            'count'         => (clone $totals)->count(),
        ]);
    }

    // ── Top products ──────────────────────────────────────────────
    public function topProducts(Request $request)
    {
        $query = SaleItem::select('product_id',
                DB::raw('SUM(quantity) as total_qty'),
                DB::raw('SUM(total) as total_revenue'),
                DB::raw('SUM(profit) as total_profit'))
            ->with('product:id,name,sku')
            ->groupBy('product_id')
            ->orderByDesc('total_qty')
            ->limit($request->limit ?? 10);

        $this->applyDateFilterOnRelation($query, $request);
        return response()->json($query->get());
    }

    // ── Inventory report ──────────────────────────────────────────
    public function inventory(Request $request)
    {
        $query = Product::with(['category', 'brand', 'inventory'])
            ->where('status', 'active');
        if ($request->category_id) $query->where('category_id', $request->category_id);
        if ($request->brand_id)    $query->where('brand_id', $request->brand_id);

        $products = $query->get();
        $stockValue = $products->sum(fn($p) => ($p->inventory ? $p->inventory->quantity : 0) * $p->purchase_price);
        $retailValue = $products->sum(fn($p) => ($p->inventory ? $p->inventory->quantity : 0) * $p->selling_price);

        return response()->json([
            'products'    => $products,
            'stock_value' => $stockValue,
            'retail_value'=> $retailValue,
        ]);
    }

    // ── Profit & Loss ─────────────────────────────────────────────
    public function profitLoss(Request $request)
    {
        $dateFrom = $request->date_from ?? now()->startOfMonth()->toDateString();
        $dateTo   = $request->date_to   ?? now()->toDateString();

        $revenue  = Sale::where('sale_status', 'completed')
            ->whereBetween(DB::raw('DATE(created_at)'), [$dateFrom, $dateTo])
            ->sum('total');

        $cogs = SaleItem::whereHas('sale', fn($q) => $q
            ->where('sale_status', 'completed')
            ->whereBetween(DB::raw('DATE(created_at)'), [$dateFrom, $dateTo]))
            ->selectRaw('SUM(cost_price * quantity) as cogs')
            ->value('cogs') ?? 0;

        $grossProfit = $revenue - $cogs;

        $expenses = Expense::whereBetween('expense_date', [$dateFrom, $dateTo])->sum('amount');

        $netProfit = $grossProfit - $expenses;

        return response()->json([
            'date_from'    => $dateFrom,
            'date_to'      => $dateTo,
            'revenue'      => $revenue,
            'cogs'         => $cogs,
            'gross_profit' => $grossProfit,
            'expenses'     => $expenses,
            'net_profit'   => $netProfit,
        ]);
    }

    // ── Expenses report ───────────────────────────────────────────
    public function expenses(Request $request)
    {
        $query = Expense::with(['category', 'user']);
        $this->applyDateFilterExpense($query, $request);

        $byCategory = Expense::select('category_id', DB::raw('SUM(amount) as total'))
            ->with('category:id,name')
            ->when($request->date_from, fn($q) => $q->where('expense_date', '>=', $request->date_from))
            ->when($request->date_to,   fn($q) => $q->where('expense_date', '<=', $request->date_to))
            ->groupBy('category_id')
            ->get();

        return response()->json([
            'expenses'    => $query->latest('expense_date')->paginate(50),
            'total'       => $query->sum('amount'),
            'by_category' => $byCategory,
        ]);
    }

    // ── Customer balances ─────────────────────────────────────────
    public function customerBalances()
    {
        $customers = Customer::where('balance', '>', 0)
            ->where('status', 'active')
            ->orderByDesc('balance')
            ->get(['id', 'customer_code', 'name', 'phone', 'balance', 'credit_limit']);
        return response()->json([
            'customers' => $customers,
            'total'     => $customers->sum('balance'),
        ]);
    }

    // ── Supplier balances ─────────────────────────────────────────
    public function supplierBalances()
    {
        $suppliers = Supplier::where('balance', '>', 0)
            ->where('status', 'active')
            ->orderByDesc('balance')
            ->get(['id', 'supplier_code', 'name', 'phone', 'balance']);
        return response()->json([
            'suppliers' => $suppliers,
            'total'     => $suppliers->sum('balance'),
        ]);
    }

    // ── Employee performance ──────────────────────────────────────
    public function employeePerformance(Request $request)
    {
        $query = Sale::select('user_id',
                DB::raw('COUNT(*) as transactions'),
                DB::raw('SUM(total) as total_sales'),
                DB::raw('SUM(discount) as total_discounts'),
                DB::raw('SUM(balance) as credit_given'))
            ->with('user:id,name,username')
            ->where('sale_status', 'completed')
            ->groupBy('user_id');

        if ($request->date_from) $query->whereDate('created_at', '>=', $request->date_from);
        if ($request->date_to)   $query->whereDate('created_at', '<=', $request->date_to);

        return response()->json($query->get());
    }

    // ── Stock movements ───────────────────────────────────────────
    public function stockMovements(Request $request)
    {
        $query = StockMovement::with(['product:id,name,sku', 'user:id,name']);
        if ($request->product_id) $query->where('product_id', $request->product_id);
        if ($request->type)       $query->where('type', $request->type);
        if ($request->date_from)  $query->whereDate('created_at', '>=', $request->date_from);
        if ($request->date_to)    $query->whereDate('created_at', '<=', $request->date_to);
        return response()->json($query->latest()->paginate(50));
    }

    // ── Purchases report ──────────────────────────────────────────
    public function purchases(Request $request)
    {
        $query = Purchase::with(['supplier', 'user']);
        if ($request->supplier_id) $query->where('supplier_id', $request->supplier_id);
        if ($request->date_from)   $query->where('purchase_date', '>=', $request->date_from);
        if ($request->date_to)     $query->where('purchase_date', '<=', $request->date_to);

        $data   = $query->latest('purchase_date')->paginate(50);
        $total  = Purchase::when($request->date_from, fn($q) => $q->where('purchase_date', '>=', $request->date_from))
                          ->when($request->date_to,   fn($q) => $q->where('purchase_date', '<=', $request->date_to))
                          ->sum('total');

        return response()->json(['purchases' => $data, 'total' => $total]);
    }

    // ── Helpers ───────────────────────────────────────────────────
    private function applyDateFilter($query, Request $request)
    {
        if ($request->date_from) $query->whereDate('created_at', '>=', $request->date_from);
        if ($request->date_to)   $query->whereDate('created_at', '<=', $request->date_to);
    }

    private function applyDateFilterExpense($query, Request $request)
    {
        if ($request->date_from) $query->where('expense_date', '>=', $request->date_from);
        if ($request->date_to)   $query->where('expense_date', '<=', $request->date_to);
    }

    private function applyDateFilterOnRelation($query, Request $request)
    {
        if ($request->date_from || $request->date_to) {
            $query->whereHas('sale', function ($q) use ($request) {
                if ($request->date_from) $q->whereDate('created_at', '>=', $request->date_from);
                if ($request->date_to)   $q->whereDate('created_at', '<=', $request->date_to);
            });
        }
    }
}
