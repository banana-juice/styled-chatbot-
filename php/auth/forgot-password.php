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
require_once __DIR__ . '/../brevo_email.php';

// ── 1. Read input ─────────────────────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);
$email = trim(as_text($input['email'] ?? ''));

// Always return a generic "success" response for security, even if email is missing or invalid.
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => true]);
    exit;
}

$pdo = getPDO();

require_once __DIR__ . '/../ratelimit.php';
if ($wait = ratelimit_blocked($pdo, ['forgot:ip:' . client_ip() => [10, 3600]])) {
    ratelimit_fail($wait, 'reset requests');
}
ratelimit_hit($pdo, 'forgot:ip:' . client_ip());

// ── 2. Look up user ───────────────────────────────────────────────────────────
$stmt = $pdo->prepare('SELECT user_id, full_name FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    // Silently succeed — do not leak account existence
    echo json_encode(['success' => true]);
    exit;
}

$user_id   = $user['user_id'];
$full_name = $user['full_name'];
$first_name = explode(' ', $full_name)[0];

// ── 2b. Cooldown: at most one reset email per account per minute ──────────────
// Without this the endpoint could be looped to mail-bomb a known address and
// burn the shared email quota that order confirmations also depend on. The
// reply stays the generic success so it reveals nothing about the account.
$recent = $pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > (NOW() - INTERVAL 60 SECOND)');
$recent->execute([$user_id]);
if ((int) $recent->fetchColumn() > 0) {
    echo json_encode(['success' => true]);
    exit;
}

// ── 3. Invalidate any existing unused tokens for this user ───────────────────
$pdo->prepare('UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0')
    ->execute([$user_id]);

// ── 4. Generate a secure token ──────────────────────────────────────────────────
$token = bin2hex(random_bytes(32));

// ── 5. Store token in password_resets ───────────────────────────────────────────
$pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$user_id]); // Clean up old entries
$stmt = $pdo->prepare(
    'INSERT INTO password_resets (user_id, token, expires_at, used)
     VALUES (?, ?, NOW() + INTERVAL 1 HOUR, 0)'
);
$stmt->execute([$user_id, $token]);

// ── 6. Send reset email via Brevo API ────────────────────────────────────────
$resetLink = 'https://styled.great-site.net/styled/reset-password.html?token=' . $token;
$subject = 'Reset your Styled password';

$htmlBody = "
<div style='font-family:Georgia,serif;max-width:520px;margin:0 auto;padding:40px 24px;color:#1a1a1a;'>
    <p style='font-size:22px;font-weight:400;margin:0 0 8px;'>Reset your password</p>
    <p style='font-size:14px;color:#666;margin:0 0 28px;'>Hi {$first_name},</p>
    <p style='font-size:14px;color:#444;line-height:1.7;margin:0 0 28px;'>
        We received a request to reset your <strong>Styled</strong> account password.
        Click the button below — this link is valid for <strong>1 hour</strong>.
    </p>
    <a href='{$resetLink}'
       style='display:inline-block;background:#1a1a1a;color:#fff;text-decoration:none;
              padding:14px 32px;font-size:13px;letter-spacing:.08em;text-transform:uppercase;'>
        Reset Password
    </a>
    <p style='font-size:12px;color:#999;margin-top:32px;line-height:1.6;'>
        If you didn't request a password reset, you can safely ignore this email.<br>
        This link will expire in 1 hour.
    </p>
    <hr style='border:none;border-top:1px solid #eee;margin:32px 0;'>
    <p style='font-size:11px;color:#bbb;margin:0;'>— The Styled Team</p>
</div>";
$textBody = "Hi {$first_name},\n\nReset your password here (valid for 1 hour):\n{$resetLink}\n\nIf you didn't request this, ignore this email.\n\n— The Styled Team";

$sent = sendEmailViaBrevo($email, $full_name, $subject, $htmlBody, $textBody);
if (empty($sent['success'])) {
    error_log("forgot-password: email to {$email} FAILED: " . ($sent['error'] ?? 'unknown error'));
    // Do not disclose the error to the client for security reasons.
}

echo json_encode(['success' => true]);