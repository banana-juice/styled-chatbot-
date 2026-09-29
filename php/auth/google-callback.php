<?php
session_start();

// TEMPORARY DEBUG — remove before final submission
define('OAUTH_DEBUG', true);
function oauth_log($m) {
    if (defined('OAUTH_DEBUG') && OAUTH_DEBUG) {
        file_put_contents(__DIR__ . '/oauth_debug.txt',
            date('c') . ' ' . $m . "\n", FILE_APPEND);
    }
}

require_once __DIR__ . '/../config/google_oauth.php';
require_once __DIR__ . '/../db.php';

/**
 * Abort: log the real reason server-side, show the user a code only.
 */
function oauth_fail(string $code, string $logDetail = ''): void {
    if ($logDetail !== '') {
        error_log('[STYLED google-oauth] ' . $code . ' :: ' . $logDetail);
        oauth_log($code . ' :: ' . $logDetail);
    }
    unset($_SESSION['oauth_google']);
    header('Location: ' . GOOGLE_RETURN_PAGE . '?oauth_error=' . urlencode($code));
    exit;
}

/** Decode a JWT payload segment (no signature check — see note below). */
function jwt_payload(string $jwt): ?array {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return null;
    $json = base64_decode(strtr($parts[1], '-_', '+/'), false);
    if ($json === false) return null;
    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

// ── 0. Config sanity ─────────────────────────────────────────
// Same real placeholder value as google-start.php's guard - see the
// comment there.
if (GOOGLE_CLIENT_ID === 'GOOGLE_CLIENT_ID' || GOOGLE_CLIENT_ID === '') {
    oauth_fail('config', 'google_oauth.php still has placeholder credentials');
}

// ── 1. Did the user cancel or did Google report an error? ────
if (isset($_GET['error'])) {
    $err = $_GET['error'];
    if ($err === 'access_denied' || $err === 'user_cancelled') {
        oauth_fail('cancelled');
    }
    oauth_fail('provider', 'Google returned error=' . $err);
}

if (empty($_GET['code']) || empty($_GET['state'])) {
    oauth_fail('invalid_response', 'Missing code or state on callback');
}

// ── 2. CSRF: the state must match what we stored in step 1 ───
$stash = $_SESSION['oauth_google'] ?? null;
if (!$stash || !isset($stash['state'])) {
    oauth_fail('expired', 'No oauth_google in session (session lost or direct hit)');
}
if (!hash_equals($stash['state'], $_GET['state'])) {
    oauth_fail('state', 'State mismatch — possible CSRF');
}
if (time() - ($stash['created_at'] ?? 0) > 900) {     // 15-minute window
    oauth_fail('expired', 'Authorization request older than 15 minutes');
}

// The state is single-use.
unset($_SESSION['oauth_google']);

// ── 3. Exchange the code for tokens (back channel) ───────────
$postFields = http_build_query([
    'code'          => $_GET['code'],
    'client_id'     => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,     // stays on the server
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'grant_type'    => 'authorization_code',
    'code_verifier' => $stash['code_verifier'],  // PKCE proof
]);

$ch = curl_init(GOOGLE_TOKEN_ENDPOINT);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $postFields,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
]);
$tokenRaw  = curl_exec($ch);
$tokenCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr   = curl_error($ch);
curl_close($ch);

if ($tokenRaw === false || $curlErr !== '') {
    oauth_fail('network', 'Token endpoint cURL error: ' . $curlErr);
}
$token = json_decode($tokenRaw, true);
if ($tokenCode !== 200 || !is_array($token) || empty($token['id_token'])) {
    oauth_fail('token', "Token endpoint HTTP $tokenCode :: $tokenRaw");
}

$claims = jwt_payload($token['id_token']);
if (!$claims) {
    oauth_fail('token', 'id_token was not decodable');
}

if (!in_array($claims['iss'] ?? '', GOOGLE_ISSUERS, true)) {
    oauth_fail('token', 'Bad issuer: ' . ($claims['iss'] ?? 'none'));
}
if (($claims['aud'] ?? '') !== GOOGLE_CLIENT_ID) {
    oauth_fail('token', 'Bad audience');
}
if ((int) ($claims['exp'] ?? 0) < time()) {
    oauth_fail('token', 'id_token expired');
}
if (isset($claims['nonce']) && !hash_equals($stash['nonce'], $claims['nonce'])) {
    oauth_fail('token', 'Nonce mismatch — possible replay');
}

// ── 5. Pull the profile (userinfo endpoint = authoritative) ──
$googleId = $claims['sub']   ?? '';
$email    = strtolower(trim($claims['email'] ?? ''));
$name     = trim($claims['name'] ?? '');
$picture  = $claims['picture'] ?? '';
$emailOk  = !empty($claims['email_verified']);

if (!empty($token['access_token'])) {
    $ch = curl_init(GOOGLE_USERINFO_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token['access_token']],
    ]);
    $uiRaw  = curl_exec($ch);
    $uiCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($uiCode === 200) {
        $ui = json_decode($uiRaw, true);
        if (is_array($ui)) {
            $googleId = $ui['sub']     ?? $googleId;
            $email    = strtolower(trim($ui['email'] ?? $email));
            $name     = trim($ui['name'] ?? $name);
            $picture  = $ui['picture'] ?? $picture;
            $emailOk  = $emailOk || !empty($ui['email_verified']);
        }
    }
    // A failed userinfo call is non-fatal: the id_token already carries
    // everything we need.
}

// ── 6. Provider returned incomplete information? ─────────────
if ($googleId === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    oauth_fail('incomplete', 'Missing sub or email from Google');
}
if (!$emailOk) {
    oauth_fail('unverified_google', 'Google reports email_verified=false for ' . $email);
}
if ($name === '') {
    $name = explode('@', $email)[0];   // sensible fallback display name
}
$name    = mb_substr($name, 0, 150);
$picture = mb_substr($picture, 0, 500);

// ── 7. Find / link / create the STYLED account ───────────────
try {
    $pdo = getPDO();

    // (a) Already linked? Log straight in.
    $stmt = $pdo->prepare(
        'SELECT user_id, full_name, email, role FROM users WHERE google_id = ? LIMIT 1'
    );
    $stmt->execute([$googleId]);
    $user = $stmt->fetch();

    if ($user) {
        // Refresh the cached avatar/name in case it changed on Google's side.
        $pdo->prepare('UPDATE users SET avatar_url = ? WHERE user_id = ?')
            ->execute([$picture, $user['user_id']]);
        $linkMode = 'existing_link';
    } else {
        $stmt = $pdo->prepare(
            'SELECT user_id, full_name, email, role, google_id
             FROM users WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $existing = $stmt->fetch();

        if ($existing) {
            if (!empty($existing['google_id']) && $existing['google_id'] !== $googleId) {
                // That email is bound to a *different* Google account.
                oauth_fail('already_linked', 'Email ' . $email . ' bound to another google_id');
            }
            $pdo->prepare(
                'UPDATE users
                 SET google_id = ?, avatar_url = ?, auth_provider = "google", is_verified = 1
                 WHERE user_id = ?'
            )->execute([$googleId, $picture, $existing['user_id']]);

            $user     = $existing;
            $linkMode = 'linked_to_existing';
        } else {
            $unusable = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);

            $ins = $pdo->prepare(
                'INSERT INTO users
                   (full_name, email, password_hash, role, is_verified,
                    google_id, avatar_url, auth_provider, created_at)
                 VALUES (?, ?, ?, "customer", 1, ?, ?, "google", NOW())'
            );
            $ins->execute([$name, $email, $unusable, $googleId, $picture]);

            $user = [
                'user_id'   => (int) $pdo->lastInsertId(),
                'full_name' => $name,
                'email'     => $email,
                'role'      => 'customer',
            ];
            $linkMode = 'created';
        }
    }
} catch (PDOException $e) {
    oauth_fail('server', 'DB error: ' . $e->getMessage());
}

// ── 8. Establish the session — identical keys to login.php ───
session_regenerate_id(true);

$_SESSION['user_id']       = (int) $user['user_id'];
$_SESSION['full_name']     = $user['full_name'];
$_SESSION['email']         = $user['email'];
$_SESSION['role']          = $user['role'];
$_SESSION['auth_provider'] = 'google';   // extra, ignored by existing code
$_SESSION['avatar_url']    = $picture;

error_log('[STYLED google-oauth] OK ' . $email . ' (' . $linkMode . ')');

// ── 9. Hand control back to the front end ────────────────────
header('Location: ' . GOOGLE_RETURN_PAGE . '?oauth=success&mode=' . $linkMode);
exit;
