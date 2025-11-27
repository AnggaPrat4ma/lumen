<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot()
    {
        // ✅ Firebase Authentication via API Guard
        $this->app['auth']->viaRequest('api', function ($request) {
            $authHeader = $request->header('Authorization');

            // ✅ Debug: Log semua request header
            Log::info('Auth Middleware Check', [
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'has_auth_header' => $authHeader !== null,
                'auth_header' => $authHeader ? substr($authHeader, 0, 30) . '...' : 'null',
            ]);

            if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
                Log::warning('No valid Authorization header found');
                return null;
            }

            $idToken = str_replace('Bearer ', '', $authHeader);

            try {
                // Verify Firebase ID Token
                $auth = app('firebase.auth');
                $verifiedIdToken = $auth->verifyIdToken($idToken, false, 300);

                $firebaseUid = $verifiedIdToken->claims()->get('sub');
                $email = $verifiedIdToken->claims()->get('email');
                $name = $verifiedIdToken->claims()->get('name');
                $picture = $verifiedIdToken->claims()->get('picture');

                Log::info('Firebase token verified', [
                    'firebase_uid' => $firebaseUid,
                    'email' => $email,
                ]);

                // Find user by Firebase UID
                $user = User::where('firebase_uid', $firebaseUid)->first();

                if (!$user) {
                    // Try find by email
                    $user = User::where('email', $email)->first();

                    if ($user) {
                        // Update existing user dengan Firebase UID
                        $user->update(['firebase_uid' => $firebaseUid]);
                        Log::info('User updated with Firebase UID', ['user_id' => $user->id_user]);
                    } else {
                        // Create new user
                        $user = User::create([
                            'firebase_uid' => $firebaseUid,
                            'email' => $email,
                            'nama' => $name ?? 'User',
                            'phone' => '',
                            'photo' => $picture,
                            'status' => 'active',
                        ]);

                        // Assign default role
                        $user->assignRole('User');

                        Log::info('New user created via Firebase', [
                            'user_id' => $user->id_user,
                            'email' => $email,
                        ]);
                    }
                }

                // Check if user is active
                if ($user->status !== 'active') {
                    Log::warning('User is not active', ['user_id' => $user->id_user]);
                    return null;
                }

                Log::info('User authenticated successfully', [
                    'user_id' => $user->id_user,
                    'roles' => $user->getRoleNames()->toArray(),
                ]);

                return $user;
            } catch (\Exception $e) {
                Log::error('Firebase Auth Error', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return null;
            }
        });

        // ✅ Admin Bypass untuk semua permissions
        Gate::before(function ($user, $ability) {
            // Debug log
            Log::info('Gate check', [
                'user_id' => $user->id_user ?? null,
                'ability' => $ability,
                'roles' => $user->getRoleNames()->toArray() ?? [],
                'is_admin' => $user->hasRole('Admin') ?? false,
            ]);

            // Admin bypass
            if ($user && method_exists($user, 'hasRole') && $user->hasRole('Admin')) {
                Log::info('Admin bypass granted for: ' . $ability);
                return true;
            }

            // Let Spatie Permission handle the rest
            return null;
        });
    }
}