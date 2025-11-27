<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class PermissionMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  $permission
     * @return mixedfem;
     */
    public function handle(Request $request, Closure $next, $permission)
    {
        if (!$request->user()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        if (!$request->user()->can($permission)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to access this resource',
                'required_permission' => $permission
            ], 403);
        }

        return $next($request);
    }
}