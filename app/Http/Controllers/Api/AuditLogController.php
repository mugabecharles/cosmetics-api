<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $query = AuditLog::with('user:id,name,username');
        if ($request->module)   $query->where('module', $request->module);
        if ($request->user_id)  $query->where('user_id', $request->user_id);
        if ($request->action)   $query->where('action', 'like', "%{$request->action}%");
        if ($request->date_from) $query->whereDate('created_at', '>=', $request->date_from);
        if ($request->date_to)   $query->whereDate('created_at', '<=', $request->date_to);
        return response()->json($query->latest()->paginate(50));
    }
}
