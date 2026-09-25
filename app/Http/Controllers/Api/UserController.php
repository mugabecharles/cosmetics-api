<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use App\Helpers\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::with('role');
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('username', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%");
            });
        }
        if ($request->role_id) $query->where('role_id', $request->role_id);
        if ($request->status)  $query->where('status', $request->status);

        return response()->json($query->orderBy('name')->paginate(20));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:255',
            'username' => 'required|string|unique:users|max:100',
            'email'    => 'nullable|email|unique:users',
            'phone'    => 'nullable|string|max:20',
            'role_id'  => 'required|exists:roles,id',
            'password' => 'required|string|min:6',
        ]);
        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);

        $employeeId = 'EMP-' . str_pad(User::count() + 1, 4, '0', STR_PAD_LEFT);
        $user = User::create([
            'employee_id' => $employeeId,
            'name'        => $request->name,
            'username'    => $request->username,
            'email'       => $request->email,
            'phone'       => $request->phone,
            'role_id'     => $request->role_id,
            'password'    => Hash::make($request->password),
            'status'      => 'active',
        ]);

        AuditLogger::log('CREATE_USER', 'Users', $user->id, [], $user->toArray());
        return response()->json($user->load('role'), 201);
    }

    public function show(User $user)
    {
        return response()->json($user->load('role'));
    }

    public function update(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:255',
            'username' => 'required|string|unique:users,username,' . $user->id . '|max:100',
            'email'    => 'nullable|email|unique:users,email,' . $user->id,
            'phone'    => 'nullable|string|max:20',
            'role_id'  => 'required|exists:roles,id',
            'status'   => 'required|in:active,inactive',
        ]);
        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);

        $old = $user->toArray();
        $user->update($request->only(['name', 'username', 'email', 'phone', 'role_id', 'status']));

        if ($request->filled('password')) {
            $user->update(['password' => Hash::make($request->password)]);
        }

        AuditLogger::log('UPDATE_USER', 'Users', $user->id, $old, $user->fresh()->toArray());
        return response()->json($user->load('role'));
    }

    public function destroy(User $user)
    {
        if ($user->id === auth('api')->id()) {
            return response()->json(['message' => 'Cannot delete your own account'], 400);
        }
        AuditLogger::log('DELETE_USER', 'Users', $user->id, $user->toArray(), []);
        $user->update(['status' => 'inactive']);
        return response()->json(['message' => 'User deactivated']);
    }

    public function roles()
    {
        return response()->json(Role::all());
    }
}
