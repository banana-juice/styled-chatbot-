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

require_once __DIR__ . '/../db.php';

// ── 1. Read input ─────────────────────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);
$email = trim($input['email'] ?? '');
$code  = trim($input['code']  ?? '');

if (empty($email) || empty($code)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Email and verification code are required.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid email address.']);
    exit;
}

// Must be exactly 6 digits
if (!preg_match('/^\d{6}$/', $code)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Verification code must be 6 digits.']);
    exit;
}

// ── 2. Look up user by email ──────────────────────────────────────────────────
$pdo  = getPDO();
$stmt = $pdo->prepare('SELECT user_id FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Account not found.']);
    exit;
}

$user_id = (int) $user['user_id'];

// ── 3. Check code exists and is not expired ───────────────────────────────────
// expires_at > NOW() ensures only unexpired codes pass
$stmt = $pdo->prepare(
    'SELECT verification_id FROM email_verifications
     WHERE user_id = ? AND code = ? AND expires_at > NOW()
     LIMIT 1'
);
$stmt->execute([$user_id, $code]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid or expired code. Please request a new one.']);
    exit;
}

// ── 4. Mark user as verified, delete the used code ───────────────────────────
$pdo->prepare('UPDATE users SET is_verified = 1 WHERE user_id = ?')->execute([$user_id]);
$pdo->prepare('DELETE FROM email_verifications WHERE user_id = ?')->execute([$user_id]);

// ── 5. Return success ─────────────────────────────────────────────────────────
echo json_encode([
    'success' => true,
    'message' => 'Email verified! Please log in.',
]);