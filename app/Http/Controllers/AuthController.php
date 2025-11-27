<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use Illuminate\Support\Facades\Storage;

class AuthController extends Controller
{
    /**
     * Register/Login with Firebase (Google Sign-In)
     * Frontend sudah login via Firebase, kirim ID token ke sini
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
            // Verify Firebase ID token
            $auth = app('firebase.auth');
            $verifiedIdToken = $auth->verifyIdToken($request->firebase_token);

            // Get Firebase UID dan claims
            $firebaseUid = $verifiedIdToken->claims()->get('sub');
            $email = $verifiedIdToken->claims()->get('email');
            $name = $verifiedIdToken->claims()->get('name');
            $picture = $verifiedIdToken->claims()->get('picture');

            // Cek apakah user sudah ada di database
            $user = User::where('firebase_uid', $firebaseUid)->first();

            if (!$user) {
                // User baru - create account
                $user = User::create([
                    'firebase_uid' => $firebaseUid,
                    'email' => $email,
                    'nama' => $name ?? 'User',
                    'phone' => '', // Bisa diisi nanti
                    'photo' => $picture,
                    'status' => 'active',
                ]);

                // Assign default role
                $user->assignRole('User');

                $message = 'Account created successfully';
            } else {
                // User sudah ada - update info jika perlu
                $user->update([
                    'email' => $email,
                    'nama' => $name ?? $user->nama,
                    'photo' => $picture ?? $user->photo,
                ]);

                $message = 'Login successful';
            }

            // Check if user is active
            if ($user->status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'Your account is not active'
                ], 403);
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'user' => $user,
                    'roles' => $user->getRoleNames(),
                    'permissions' => $user->getAllPermissions()->pluck('name'),
                ],
                'token' => $request->firebase_token // Return back untuk disimpan di frontend
            ], 200);
        } catch (FailedToVerifyToken $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Firebase token',
                'error' => $e->getMessage()
            ], 401);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get authenticated user info
     */
    public function me(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        // Convert photo path to full URL
        $userData = $user->toArray();
        if (!empty($userData['photo']) && strpos($userData['photo'], '/storage/') === 0) {
            $userData['photo'] = env('APP_URL') . $userData['photo'];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $userData,
                'roles' => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
            ]
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'nama' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20',
            'photo' => 'sometimes|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // 📸 Handle Photo Upload (Base64)
        if ($request->has('photo') && !empty($request->photo)) {
            if (strpos($request->photo, 'data:image') === 0) {
                try {
                    preg_match('/data:image\/(\w+);base64,/', $request->photo, $matches);
                    $imageFormat = $matches[1] ?? 'png';

                    $image = $request->photo;
                    $image = preg_replace('/^data:image\/\w+;base64,/', '', $image);
                    $image = str_replace(' ', '+', $image);

                    $imageName = 'profile_' . $user->id . '_' . time() . '.' . $imageFormat;

                    Storage::disk('public')->put('profiles/' . $imageName, base64_decode($image));

                    // Hapus foto lama
                    if ($user->photo) {
                        // Extract path dari URL lengkap atau path relatif
                        $oldPath = $user->photo;
                        if (strpos($oldPath, 'http') === 0) {
                            $oldPath = parse_url($oldPath, PHP_URL_PATH);
                        }
                        $oldPath = str_replace('/storage/', '', $oldPath);

                        if (Storage::disk('public')->exists($oldPath)) {
                            Storage::disk('public')->delete($oldPath);
                        }
                    }

                    // Simpan path relatif di database
                    $user->photo = '/storage/profiles/' . $imageName;
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Failed to upload photo: ' . $e->getMessage()
                    ], 500);
                }
            } else if (strpos($request->photo, 'http') === 0) {
                $user->photo = $request->photo;
            }
        }

        if ($request->has('nama')) {
            $user->nama = $request->nama;
        }

        if ($request->has('phone')) {
            $user->phone = $request->phone;
        }

        $user->save();

        // Return dengan full URL
        $userData = $user->toArray();
        if (!empty($userData['photo']) && strpos($userData['photo'], '/storage/') === 0) {
            $userData['photo'] = env('APP_URL') . $userData['photo'];
        }

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => $userData
        ]);
    }

    /**
     * Logout - revoke Firebase token di frontend
     * Backend tidak perlu handle logout karena stateless
     */
    public function logout(Request $request)
    {
        return response()->json([
            'success' => true,
            'message' => 'Logout successful. Please clear Firebase token on client side.'
        ]);
    }

    /**
     * Verify Firebase token (untuk testing)
     */
    public function verifyToken(Request $request)
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
            $auth = app('firebase.auth');
            $verifiedIdToken = $auth->verifyIdToken($request->firebase_token);

            return response()->json([
                'success' => true,
                'message' => 'Token is valid',
                'data' => [
                    'uid' => $verifiedIdToken->claims()->get('sub'),
                    'email' => $verifiedIdToken->claims()->get('email'),
                    'name' => $verifiedIdToken->claims()->get('name'),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token is invalid',
                'error' => $e->getMessage()
            ], 401);
        }
    }
}
