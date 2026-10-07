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
$input        = json_decode(file_get_contents('php://input'), true);
$token        = trim(as_text($input['token'] ?? ''));
$new_password = as_text($input['new_password']      ?? '');

if (empty($token) || empty($new_password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Token and new password are required.']);
    exit;
}

if (strlen($new_password) < 8) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Password must be at least 8 characters.']);
    exit;
}

$pdo = getPDO();

// ── 2. Validate token ─────────────────────────────────────────────────────────
$stmt = $pdo->prepare(
    'SELECT reset_id, user_id FROM password_resets
     WHERE token = ?
       AND expires_at > NOW()
       AND used = 0
     LIMIT 1'
);
$stmt->execute([$token]);
$reset = $stmt->fetch();

if (!$reset) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'This reset link is invalid or has expired.']);
    exit;
}

// ── 3. Hash new password ──────────────────────────────────────────────────────
$hash = password_hash($new_password, PASSWORD_BCRYPT);

// ── 4. Update users table ─────────────────────────────────────────────────────
$pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
    ->execute([$hash, $reset['user_id']]);

// ── 5. Mark token as used ─────────────────────────────────────────────────────
$pdo->prepare('UPDATE password_resets SET used = 1 WHERE reset_id = ?')
    ->execute([$reset['reset_id']]);

echo json_encode(['success' => true]);