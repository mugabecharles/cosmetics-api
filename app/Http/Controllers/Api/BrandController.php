<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Helpers\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BrandController extends Controller
{
    public function index(Request $request)
    {
        $query = Brand::withCount('products');
        if ($request->search) $query->where('name', 'like', "%{$request->search}%");
        if ($request->status) $query->where('status', $request->status);
        return response()->json($query->orderBy('name')->paginate(50));
    }

    public function all()
    {
        return response()->json(Brand::where('status', 'active')->orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $v = Validator::make($request->all(), [
            'name'             => 'required|string|unique:brands|max:100',
            'description'      => 'nullable|string',
            'country_of_origin'=> 'nullable|string',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $brand = Brand::create($request->only(['name', 'description', 'country_of_origin']) + ['status' => 'active']);
        AuditLogger::log('CREATE_BRAND', 'Brands', $brand->id, [], $brand->toArray());
        return response()->json($brand, 201);
    }

    public function update(Request $request, Brand $brand)
    {
        $v = Validator::make($request->all(), [
            'name'   => 'required|string|unique:brands,name,' . $brand->id . '|max:100',
            'status' => 'required|in:active,inactive',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $old = $brand->toArray();
        $brand->update($request->only(['name', 'description', 'country_of_origin', 'status']));
        AuditLogger::log('UPDATE_BRAND', 'Brands', $brand->id, $old, $brand->toArray());
        return response()->json($brand);
    }

    public function destroy(Brand $brand)
    {
        if ($brand->products()->count() > 0) {
            $brand->update(['status' => 'inactive']);
            return response()->json(['message' => 'Brand has products — marked inactive']);
        }
        AuditLogger::log('DELETE_BRAND', 'Brands', $brand->id, $brand->toArray(), []);
        $brand->delete();
        return response()->json(['message' => 'Brand deleted']);
    }
}
