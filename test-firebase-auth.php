<?php

/**
 * COMPLETE FIREBASE → BACKEND AUTH FLOW TEST
 * 
 * 1. Create test Firebase user
 * 2. Generate custom token
 * 3. Exchange custom token → ID Token
 * 4. Call /api/auth/firebase with ID Token
 * 5. Verify user saved in database
 * 6. Check JWT stored
 * 7. Test protected route
 */

require_once __DIR__.'/vendor/autoload.php';

try {
    (new Laravel\Lumen\Bootstrap\LoadEnvironmentVariables(
        __DIR__
    ))->bootstrap();
} catch (Dotenv\Exception\InvalidPathException $e) {}

$app = require_once __DIR__.'/bootstrap/app.php';

echo "🔥 FIREBASE → BACKEND AUTH TEST\n";
echo "================================\n\n";

try {
    $firebaseAuth = app(\Kreait\Firebase\Contract\Auth::class);
    $db = app('db');

    echo "📝 Step 1: Creating Firebase test user...\n";

    $uid = 'test-user-' . time();
    $email = 'test' . time() . '@example.com';
    $displayName = 'Test User ' . date('His');

    $userRecord = $firebaseAuth->createUser([
        'uid' => $uid,
        'email' => $email,
        'emailVerified' => true,
        'displayName' => $displayName,
        'photoUrl' => 'https://via.placeholder.com/150',
    ]);

    echo "   ✅ Created UID: {$uid}\n\n";

    echo "📝 Step 2: Generating Custom Token...\n";

    $customTokenObj = $firebaseAuth->createCustomToken($uid);
    $customToken = $customTokenObj->toString();

    echo "   🔑 Custom Token (first 60 chars): " . substr($customToken, 0, 60) . "...\n\n";

    echo "📝 Step 3: Exchanging Custom Token → ID Token...\n";

    $apiKey = env('FIREBASE_API_KEY');

    if (!$apiKey) {
        throw new Exception("FIREBASE_API_KEY not set in .env");
    }

    $ch = curl_init("https://identitytoolkit.googleapis.com/v1/accounts:signInWithCustomToken?key={$apiKey}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'token' => $customToken,
        'returnSecureToken' => true
    ]));

    $idTokenResponse = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (!isset($idTokenResponse['idToken'])) {
        print_r($idTokenResponse);
        throw new Exception("Failed to exchange custom token → ID token.");
    }

    $idToken = $idTokenResponse['idToken'];
    echo "   🔐 ID Token generated!\n\n";

    echo "📝 Step 4: Calling Backend /api/auth/firebase...\n";

    $ch = curl_init('http://localhost:8000/api/auth/firebase');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'firebase_token' => $idToken
    ]));

    $response = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "   📡 HTTP Status: {$http}\n";

    if ($http !== 200) {
        echo "Response: {$response}\n";
        exit(1);
    }

    $data = json_decode($response, true);

    $jwtToken = $data['data']['token'];
    $userId = $data['data']['user']['id_user'];

    echo "   ✅ Backend accepted ID Token\n";
    echo "      User ID: {$userId}\n";
    echo "      JWT: " . substr($jwtToken, 0, 50) . "...\n\n";

    echo "📝 Step 5: Checking user in database...\n";

    $dbUser = $db->table('user')->where('id_user', $userId)->first();

    if (!$dbUser) {
        throw new Exception("User not found in database.");
    }

    echo "   ✅ User exists in DB: {$dbUser->email}\n";
    echo "      Firebase UID: {$dbUser->firebase_uid}\n\n";

    echo "📝 Step 6: Testing protected route /api/auth/me...\n";

    $ch = curl_init('http://localhost:8000/api/auth/me');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        "Authorization: Bearer {$jwtToken}"
    ]);

    $meResponse = curl_exec($ch);
    $meHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($meHttp !== 200) {
        echo "   ❌ Failed: {$meResponse}\n";
        exit(1);
    }

    $me = json_decode($meResponse, true);

    echo "   ✅ Protected route OK!\n";
    echo "      Authenticated as: {$me['data']['nama']}\n";
    echo "      Roles: " . implode(', ', $me['data']['roles']) . "\n\n";

    echo "🎉 ALL TESTS COMPLETED SUCCESSFULLY!\n";

    echo "\n🗑 Cleanup Firebase user...\n";
    $firebaseAuth->deleteUser($uid);
    echo "   ✅ Test user deleted.\n\n";

} catch (Exception $e) {
    echo "❌ ERROR: {$e->getMessage()}\n";
    echo $e->getTraceAsString();
    exit(1);
}
