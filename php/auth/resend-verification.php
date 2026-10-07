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
$email = trim($input['email'] ?? '');

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'A valid email address is required.']);
    exit;
}

// ── 2. Look up unverified user ────────────────────────────────────────────────
$pdo  = getPDO();
$stmt = $pdo->prepare(
    'SELECT user_id, full_name FROM users WHERE email = ? AND is_verified = 0 LIMIT 1'
);
$stmt->execute([$email]);
$user = $stmt->fetch();

// Return success even if account not found — don't leak whether it exists
if (!$user) {
    echo json_encode(['success' => true]);
    exit;
}

$user_id   = (int) $user['user_id'];
$full_name = $user['full_name'];

// ── 3. Rate limit: only one resend per 60 seconds ─────────────────────────────
$stmt = $pdo->prepare(
    'SELECT created_at FROM email_verifications
     WHERE user_id = ? ORDER BY created_at DESC LIMIT 1'
);
$stmt->execute([$user_id]);
$last = $stmt->fetch();

if ($last) {
    $seconds_ago = time() - strtotime($last['created_at']);
    if ($seconds_ago < 60) {
        $wait = 60 - $seconds_ago;
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'error'   => "Please wait {$wait} seconds before requesting a new code.",
        ]);
        exit;
    }
}

// ── 4. Generate new code, delete old, insert new ──────────────────────────────
$code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

$pdo->prepare('DELETE FROM email_verifications WHERE user_id = ?')->execute([$user_id]);
$pdo->prepare(
    'INSERT INTO email_verifications (user_id, code, expires_at)
     VALUES (?, ?, NOW() + INTERVAL 15 MINUTE)'
)->execute([$user_id, $code]);

// ── 5. Send email via Brevo API ───────────────────────────────────────────────
$subject = 'Your new Styled verification code';
$htmlBody = "
<div style='font-family:sans-serif;max-width:480px;margin:auto;padding:32px;'>
    <h2 style='color:#2c1f14;margin-bottom:8px;font-family:Georgia,serif;font-weight:400;'>New verification code</h2>
    <p style='color:#8a7f74;font-size:14px;line-height:1.7;'>Hi {$full_name}, here is your new verification code:</p>
    <div style='font-size:40px;font-weight:400;letter-spacing:14px;text-align:center;padding:28px;background:#f5f0e8;margin:28px 0;color:#2c1f14;font-family:Georgia,serif;'>{$code}</div>
    <p style='color:#8a7f74;font-size:12px;'>This code expires in <strong style='color:#2c1f14;'>15 minutes</strong>.</p>
</div>";
$textBody = "Your new Styled verification code is: {$code}. It expires in 15 minutes.";

$sent = sendEmailViaBrevo($email, $full_name, $subject, $htmlBody, $textBody);
if (empty($sent['success'])) {
    error_log("resend-verification: email to {$email} FAILED: " . ($sent['error'] ?? 'unknown error'));
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not send email. Please try again.']);
    exit;
}

echo json_encode(['success' => true]);