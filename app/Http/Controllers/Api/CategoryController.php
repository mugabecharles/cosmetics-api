<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Helpers\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = Category::withCount('products');
        if ($request->search) $query->where('name', 'like', "%{$request->search}%");
        if ($request->status) $query->where('status', $request->status);
        return response()->json($query->orderBy('name')->paginate(50));
    }

    public function all()
    {
        return response()->json(Category::where('status', 'active')->orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $v = Validator::make($request->all(), [
            'name'        => 'required|string|unique:categories|max:100',
            'description' => 'nullable|string',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $cat = Category::create(['name' => $request->name, 'description' => $request->description, 'status' => 'active']);
        AuditLogger::log('CREATE_CATEGORY', 'Categories', $cat->id, [], $cat->toArray());
        return response()->json($cat, 201);
    }

    public function update(Request $request, Category $category)
    {
        $v = Validator::make($request->all(), [
            'name'   => 'required|string|unique:categories,name,' . $category->id . '|max:100',
            'status' => 'required|in:active,inactive',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $old = $category->toArray();
        $category->update($request->only(['name', 'description', 'status']));
        AuditLogger::log('UPDATE_CATEGORY', 'Categories', $category->id, $old, $category->toArray());
        return response()->json($category);
    }

    public function destroy(Category $category)
    {
        if ($category->products()->count() > 0) {
            $category->update(['status' => 'inactive']);
            return response()->json(['message' => 'Category has products — marked inactive']);
        }
        AuditLogger::log('DELETE_CATEGORY', 'Categories', $category->id, $category->toArray(), []);
        $category->delete();
        return response()->json(['message' => 'Category deleted']);
    }
}
