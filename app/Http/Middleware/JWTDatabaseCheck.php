<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;

class JWTDatabaseCheck
{
    /**
     * Handle an incoming request.
     * 
     * Middleware ini memastikan:
     * 1. Token JWT valid (sudah di-check oleh auth middleware)
     * 2. Token cocok dengan yang ada di database
     * 3. Token belum expired
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }

            // Ambil token dari header
            $providedToken = $request->bearerToken();

            // Cek apakah token cocok dengan yang di database
            if ($user->api_token !== $providedToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token tidak valid atau sudah di-logout'
                ], 401);
            }

            // Cek apakah token sudah expired
            if ($user->token_expires_at && $user->token_expires_at->isPast()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token sudah expired, silakan refresh atau login kembali'
                ], 401);
            }

            return $next($request);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated: ' . $e->getMessage()
            ], 401);
        }
    }
}