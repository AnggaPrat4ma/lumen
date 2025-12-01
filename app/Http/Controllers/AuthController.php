<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use Kreait\Firebase\Contract\Auth as FirebaseAuth;

class AuthController extends Controller
{
    protected $firebaseAuth;

    public function __construct(FirebaseAuth $firebaseAuth)
    {
        $this->firebaseAuth = $firebaseAuth;
    }

    /**
     * 🔥 FIREBASE AUTH - Convert Firebase Token to JWT
     * 
     * Flow:
     * 1. Frontend kirim Firebase ID Token
     * 2. Backend verify Firebase token
     * 3. Backend cari/buat user
     * 4. Backend generate JWT token
     * 5. JWT token disimpan di field api_token
     * 6. Return JWT token ke frontend
     * 
     * POST /api/auth/firebase
     * Body: { firebase_token: "..." }
     */
    public function firebaseAuth(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'firebase_token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // 1. Verify Firebase Token
            Log::info('🔥 Firebase Auth: Verifying token...');
            
            $verifiedIdToken = $this->firebaseAuth->verifyIdToken($request->firebase_token);
            $firebaseUid = $verifiedIdToken->claims()->get('sub');
            
            Log::info('✅ Firebase token verified', ['uid' => $firebaseUid]);
            
            // 2. Ambil data user dari Firebase
            $firebaseUser = $this->firebaseAuth->getUser($firebaseUid);
            
            Log::info('📋 Firebase user data', [
                'uid' => $firebaseUser->uid,
                'email' => $firebaseUser->email,
                'name' => $firebaseUser->displayName,
            ]);

            // 3. Cari user di database berdasarkan firebase_uid ATAU email
            $user = User::where('firebase_uid', $firebaseUid)
                        ->orWhere('email', $firebaseUser->email)
                        ->first();

            if (!$user) {
                // Buat user baru jika belum ada
                Log::info('👤 Creating new user in database...');
                
                $user = new User();
                $user->firebase_uid = $firebaseUid;
                $user->nama = $firebaseUser->displayName ?? 'User';
                $user->email = $firebaseUser->email;
                $user->phone = $firebaseUser->phoneNumber;
                $user->photo = $firebaseUser->photoUrl;
                $user->status = 'active';
                $user->save();
                
                Log::info('✅ User created', ['id_user' => $user->id_user]);

                // Assign role default 'User'
                try {
                    $user->assignRole('User');
                    Log::info('✅ Role "User" assigned');
                } catch (\Exception $e) {
                    Log::warning('⚠️  Failed to assign role: ' . $e->getMessage());
                }
                
            } else {
                Log::info('✅ User found in database', ['id_user' => $user->id_user]);
                
                // Update firebase_uid jika user ditemukan by email tapi firebase_uid kosong
                if (empty($user->firebase_uid)) {
                    $user->firebase_uid = $firebaseUid;
                    $user->save();
                    Log::info('✅ Firebase UID updated for existing user');
                }
            }

            // 4. Cek status user
            if ($user->status !== 'active') {
                Log::warning('❌ User account is not active', ['id_user' => $user->id_user]);
                return response()->json([
                    'success' => false,
                    'message' => 'Akun Anda tidak aktif'
                ], 403);
            }

            // 5. Generate JWT token
            Log::info('🔑 Generating JWT token...');
            $jwtToken = JWTAuth::fromUser($user);
            Log::info('✅ JWT token generated');

            // 6. Simpan JWT token ke database
            $expiresAt = Carbon::now()->addMinutes(config('jwt.ttl', 60));
            $user->api_token = $jwtToken;
            $user->token_expires_at = $expiresAt;
            $user->save();
            
            Log::info('✅ JWT token saved to database', [
                'id_user' => $user->id_user,
                'expires_at' => $expiresAt->toDateTimeString()
            ]);

            // 7. Return response
            Log::info('✅ Firebase authentication complete');
            
            return response()->json([
                'success' => true,
                'message' => 'Login berhasil',
                'data' => [
                    'user' => [
                        'id_user' => $user->id_user,
                        'nama' => $user->nama,
                        'email' => $user->email,
                        'phone' => $user->phone,
                        'photo' => $user->photo,
                        'status' => $user->status,
                        'roles' => $user->getRoleNames()->toArray(),
                        'permissions' => $user->getAllPermissions()->pluck('name')->toArray(),
                    ],
                    'token' => $jwtToken,
                    'token_type' => 'Bearer',
                    'expires_in' => config('jwt.ttl', 60) * 60, // dalam detik
                    'expires_at' => $expiresAt->toIso8601String(),
                ]
            ], 200);

        } catch (\Kreait\Firebase\Exception\Auth\FailedToVerifyToken $e) {
            Log::error('❌ Firebase token verification failed', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Firebase token tidak valid',
                'error' => $e->getMessage()
            ], 401);

        } catch (\Exception $e) {
            Log::error('❌ Firebase authentication failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Authentication gagal: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 🔐 EMAIL/PASSWORD LOGIN - Generate JWT
     * 
     * POST /api/auth/login
     * Body: { email, password }
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $credentials = $request->only(['email', 'password']);

        if (!$token = JWTAuth::attempt($credentials)) {
            return response()->json([
                'success' => false,
                'message' => 'Email atau password salah'
            ], 401);
        }

        $user = JWTAuth::user();

        if ($user->status !== 'active') {
            JWTAuth::invalidate(JWTAuth::getToken());
            return response()->json([
                'success' => false,
                'message' => 'Akun Anda tidak aktif'
            ], 403);
        }

        // Simpan token ke database
        $expiresAt = Carbon::now()->addMinutes(config('jwt.ttl', 60));
        $user->api_token = $token;
        $user->token_expires_at = $expiresAt;
        $user->save();

        return $this->respondWithToken($token);
    }

    /**
     * 📝 REGISTER
     * POST /api/auth/register
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nama' => 'required|string|max:255',
            'email' => 'required|email|unique:user,email',
            'password' => 'required|string|min:6|confirmed',
            'phone' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = User::create([
                'nama' => $request->nama,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'phone' => $request->phone,
                'status' => 'active',
            ]);

            $user->assignRole('User');

            $token = JWTAuth::fromUser($user);

            // Simpan token ke database
            $expiresAt = Carbon::now()->addMinutes(config('jwt.ttl', 60));
            $user->api_token = $token;
            $user->token_expires_at = $expiresAt;
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Registrasi berhasil',
                'data' => [
                    'user' => [
                        'id_user' => $user->id_user,
                        'nama' => $user->nama,
                        'email' => $user->email,
                        'phone' => $user->phone,
                        'photo' => $user->photo,
                        'status' => $user->status,
                        'roles' => $user->getRoleNames()->toArray(),
                    ],
                    'token' => $token,
                    'token_type' => 'Bearer',
                    'expires_in' => config('jwt.ttl', 60) * 60
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Registrasi gagal: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 👤 GET AUTHENTICATED USER
     * GET /api/auth/me
     */
    public function me()
    {
        try {
            $user = JWTAuth::user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id_user' => $user->id_user,
                    'nama' => $user->nama,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'photo' => $user->photo,
                    'status' => $user->status,
                    'roles' => $user->getRoleNames()->toArray(),
                    'permissions' => $user->getAllPermissions()->pluck('name')->toArray(),
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }
    }

    /**
     * ✏️ UPDATE PROFILE
     * PUT /api/auth/profile
     */
    public function updateProfile(Request $request)
    {
        try {
            $user = JWTAuth::user();

            $validator = Validator::make($request->all(), [
                'nama' => 'nullable|string|max:255',
                'phone' => 'nullable|string|max:20',
                'photo' => 'nullable|url',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            if ($request->has('nama')) $user->nama = $request->nama;
            if ($request->has('phone')) $user->phone = $request->phone;
            if ($request->has('photo')) $user->photo = $request->photo;
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Profile berhasil diupdate',
                'data' => [
                    'id_user' => $user->id_user,
                    'nama' => $user->nama,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'photo' => $user->photo,
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Update profile gagal: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 🚪 LOGOUT - Invalidate token
     * POST /api/auth/logout
     */
    public function logout()
    {
        try {
            $user = JWTAuth::user();

            // Hapus token dari database
            $user->api_token = null;
            $user->token_expires_at = null;
            $user->save();

            // Invalidate JWT
            JWTAuth::invalidate(JWTAuth::getToken());

            return response()->json([
                'success' => true,
                'message' => 'Logout berhasil'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Logout gagal: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 🔄 REFRESH TOKEN
     * POST /api/auth/refresh
     */
    public function refresh()
    {
        try {
            $user = JWTAuth::user();
            $newToken = JWTAuth::refresh(JWTAuth::getToken());

            // Update token di database
            $expiresAt = Carbon::now()->addMinutes(config('jwt.ttl', 60));
            $user->api_token = $newToken;
            $user->token_expires_at = $expiresAt;
            $user->save();

            return $this->respondWithToken($newToken);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Refresh token gagal: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ VERIFY TOKEN
     * POST /api/auth/verify-token
     */
    public function verifyToken(Request $request)
    {
        try {
            $user = JWTAuth::user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token tidak valid'
                ], 401);
            }

            // Cek apakah token di database cocok
            $providedToken = $request->bearerToken();
            if ($user->api_token !== $providedToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token tidak cocok di database'
                ], 401);
            }

            // Cek expiry
            if ($user->token_expires_at && $user->token_expires_at->isPast()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token sudah expired'
                ], 401);
            }

            return response()->json([
                'success' => true,
                'message' => 'Token valid',
                'data' => [
                    'id_user' => $user->id_user,
                    'nama' => $user->nama,
                    'email' => $user->email,
                    'expires_at' => $user->token_expires_at,
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token tidak valid'
            ], 401);
        }
    }

    /**
     * Helper: Format response dengan token
     */
    protected function respondWithToken($token)
    {
        $user = JWTAuth::user();

        return response()->json([
            'success' => true,
            'message' => 'Login berhasil',
            'data' => [
                'user' => [
                    'id_user' => $user->id_user,
                    'nama' => $user->nama,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'photo' => $user->photo,
                    'status' => $user->status,
                    'roles' => $user->getRoleNames()->toArray(),
                    'permissions' => $user->getAllPermissions()->pluck('name')->toArray(),
                ],
                'token' => $token,
                'token_type' => 'Bearer',
                'expires_in' => config('jwt.ttl', 60) * 60,
                'expires_at' => $user->token_expires_at,
            ]
        ], 200);
    }
}