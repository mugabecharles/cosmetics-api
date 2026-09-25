<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RoleMiddleware
{
    /**
     * Usage: role:administrator  OR  role:administrator,manager
     */
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = auth('api')->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $userRole = strtolower($user->role?->name ?? '');

        foreach ($roles as $role) {
            if ($userRole === strtolower(trim($role))) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'Forbidden — insufficient permissions'], 403);
    }
}
