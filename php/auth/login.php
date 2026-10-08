<?php
// CORS headers
header("Access-Control-Allow-Origin: https://styled.great-site.net");
header("Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

require_once __DIR__ . '/../session_boot.php';

require_once __DIR__ . '/../db.php';

// ── 1. Read input ─────────────────────────────────────────────────────────────
$input    = json_decode(file_get_contents('php://input'), true);
$email    = trim(as_text($input['email'] ?? $_POST['email']    ?? ''));
$password = as_text($input['password']      ?? $_POST['password'] ?? '');

// ── 2. Basic validation ───────────────────────────────────────────────────────
if (empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Email and password are required.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Please enter a valid email address.']);
    exit;
}

// ── 3. Look up user — now also fetches is_verified ───────────────────────────
$pdo  = getPDO();

// Slow down password guessing. Wrong passwords are counted per account+address, per
// account (any address) and per address (any account); a correct login clears the first.
require_once __DIR__ . '/../ratelimit.php';
$ip = client_ip();
$ek = ratelimit_key($email);
$loginLimits = [
    "login:pair:$ek:$ip" => [5, 900],
    "login:email:$ek"    => [20, 900],
    "login:ip:$ip"       => [40, 900],
];
if ($wait = ratelimit_blocked($pdo, $loginLimits)) {
    ratelimit_fail($wait, 'failed sign-in attempts');
}

$stmt = $pdo->prepare(
    'SELECT user_id, full_name, email, password_hash, role, is_verified
     FROM users WHERE email = ? LIMIT 1'
);
$stmt->execute([$email]);
$user = $stmt->fetch();

// ── 4. Verify password (timing-safe) ─────────────────────────────────────────
// Always call password_verify even when user not found so response time
// does not leak whether the account exists.
$dummy_hash = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
$hash       = $user ? $user['password_hash'] : $dummy_hash;

if (!$user || !password_verify($password, $hash)) {
    foreach (array_keys($loginLimits) as $bucket) {
        ratelimit_hit($pdo, $bucket);
    }
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid credentials.']);
    exit;
}

ratelimit_clear($pdo, "login:pair:$ek:$ip"); // right password: forget the earlier typos

// ── 5. Block login if email not verified ──────────────────────────────────────
// Admin and staff accounts are created internally and do not require
// email verification — only regular customers are blocked here.
$requiresVerification = !in_array($user['role'], ['admin', 'staff'], true);
if ($requiresVerification && (int) $user['is_verified'] === 0) {
    http_response_code(403);
    echo json_encode([
        'success'    => false,
        'unverified' => true,
        'email'      => $email,
        'error'      => 'Please verify your email address first. Check your inbox for the verification code.',
    ]);
    exit;
}

// ── 6. Store session ──────────────────────────────────────────────────────────
$_SESSION['user_id']   = (int) $user['user_id'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['email']     = $user['email'];
$_SESSION['role']      = $user['role'];

session_regenerate_id(true);

$remember = $input['remember'] ?? false;
if ($remember) {
    // Extend the session cookie lifetime to 30 days (30*24*3600 seconds)
    $sessionName = session_name();
    $sessionId   = session_id();
    setcookie($sessionName, $sessionId, [
        'expires'  => time() + 30 * 86400,
        'path'     => '/',
        'secure'   => session_request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// ── 8. Return success ─────────────────────────────────────────────────────────
echo json_encode([
    'success' => true,
    'user'    => [
        'user_id'   => (int) $user['user_id'],
        'full_name' => $user['full_name'],
        'email'     => $user['email'],
        'role'      => $user['role'],
    ],
]);