<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Helpers\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Allow login by username or email
        $user = User::where('username', $request->username)
            ->orWhere('email', $request->username)
            ->with('role')
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        if ($user->status !== 'active') {
            return response()->json(['message' => 'Account is inactive. Contact administrator.'], 403);
        }

        $token = auth('api')->login($user);

        AuditLogger::log('LOGIN', 'Auth', $user->id, [], [], $user->id);

        return response()->json([
            'token'      => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user'       => $this->userResource($user),
        ]);
    }

    public function me()
    {
        $user = auth('api')->user()->load('role');
        return response()->json($this->userResource($user));
    }

    public function logout()
    {
        AuditLogger::log('LOGOUT', 'Auth', auth('api')->id());
        auth('api')->logout();
        return response()->json(['message' => 'Logged out successfully']);
    }

    public function refresh()
    {
        $token = auth('api')->refresh();
        return response()->json([
            'token'      => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
        ]);
    }

    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = auth('api')->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Current password is incorrect'], 400);
        }

        $user->update(['password' => Hash::make($request->new_password)]);
        AuditLogger::log('CHANGE_PASSWORD', 'Auth', $user->id);

        return response()->json(['message' => 'Password changed successfully']);
    }

    private function userResource(User $user): array
    {
        return [
            'id'       => $user->id,
            'name'     => $user->name,
            'username' => $user->username,
            'email'    => $user->email,
            'phone'    => $user->phone,
            'role'     => $user->role ? $user->role->name : null,
            'role_id'  => $user->role_id,
            'status'   => $user->status,
        ];
    }
}
