<?php

namespace App\Auth;

use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class FirebaseGuard implements Guard
{
    protected $request;
    protected $user;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function check()
    {
        return !is_null($this->user());
    }

    public function guest()
    {
        return !$this->check();
    }

    public function user()
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $token = $this->getTokenFromRequest();

        if (!$token) {
            return null;
        }

        try {
            $auth = app('firebase.auth');
            $verifiedIdToken = $auth->verifyIdToken($token);
            $firebaseUid = $verifiedIdToken->claims()->get('sub');

            $this->user = User::where('firebase_uid', $firebaseUid)->first();

            return $this->user;
        } catch (\Exception $e) {
            Log::error('Firebase token verification failed: ' . $e->getMessage());
            return null;
        }
    }

    public function id()
    {
        return $this->user() ? $this->user()->getAuthIdentifier() : null;
    }

    public function validate(array $credentials = [])
    {
        return false;
    }

    public function hasUser()
    {
        return !is_null($this->user);
    }

    public function setUser(Authenticatable $user)
    {
        $this->user = $user;
        return $this;
    }

    protected function getTokenFromRequest()
    {
        // Check Authorization header
        $header = $this->request->header('Authorization');
        
        if ($header && preg_match('/Bearer\s+(.*)$/i', $header, $matches)) {
            return $matches[1];
        }

        // Fallback: check token query parameter
        return $this->request->query('token') ?: $this->request->input('token');
    }
}