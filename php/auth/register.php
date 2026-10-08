<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

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

$input = json_decode(file_get_contents('php://input'), true);
$full_name = trim(as_text($input['full_name'] ?? ''));
$email = trim(as_text($input['email'] ?? ''));
$password = as_text($input['password'] ?? '');

// Validation
$errors = [];
if (empty($full_name)) $errors[] = 'Full name required.';
if (mb_strlen($full_name) > 100) $errors[] = 'Full name must be 100 characters or fewer.';
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email required.';
if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => implode(' ', $errors)]);
    exit;
}

$pdo = getPDO();

require_once __DIR__ . '/../ratelimit.php';
if ($wait = ratelimit_blocked($pdo, ['register:ip:' . client_ip() => [15, 3600]])) {
    ratelimit_fail($wait, 'sign-ups from this connection');
}
ratelimit_hit($pdo, 'register:ip:' . client_ip());

// Check duplicate
$stmt = $pdo->prepare('SELECT user_id FROM users WHERE email = ?');
$stmt->execute([$email]);
if ($stmt->fetch()) {
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'Email already exists.']);
    exit;
}

// Insert user
$hashed = password_hash($password, PASSWORD_BCRYPT);
$insert = $pdo->prepare('INSERT INTO users (full_name, email, password_hash, role, is_verified, created_at) VALUES (?, ?, ?, "customer", 0, NOW())');
try {
    $insert->execute([$full_name, $email, $hashed]);
} catch (PDOException $e) {
    // Two sign-ups for the same email at the same instant both pass the check above;
    // the unique index lets exactly one win and the other lands here.
    if ((string) $e->getCode() === '23000') {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Email already exists.']);
        exit;
    }
    error_log('register.php insert failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not create the account. Please try again.']);
    exit;
}
$user_id = $pdo->lastInsertId();

// Generate code
$safeName = htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8');
$code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$pdo->prepare('DELETE FROM email_verifications WHERE user_id = ?')->execute([$user_id]);
$pdo->prepare('INSERT INTO email_verifications (user_id, code, expires_at) VALUES (?, ?, NOW() + INTERVAL 15 MINUTE)')->execute([$user_id, $code]);

// Send email
$subject = 'Your Styled verification code';
$htmlBody = "
<div style='font-family:sans-serif;max-width:480px;margin:auto;padding:32px;'>
    <h2 style='color:#2c1f14;margin-bottom:8px;font-family:Georgia,serif;font-weight:400;'>Verify your email</h2>
    <p style='color:#8a7f74;font-size:14px;line-height:1.7;'>Hi {$safeName}, thanks for signing up to Styled. Use this code to verify your email address:</p>
    <div style='font-size:40px;font-weight:400;letter-spacing:14px;text-align:center;padding:28px;background:#f5f0e8;margin:28px 0;color:#2c1f14;font-family:Georgia,serif;'>{$code}</div>
    <p style='color:#8a7f74;font-size:12px;'>This code expires in <strong style='color:#2c1f14;'>15 minutes</strong>. If you didn't create an account, you can safely ignore this email.</p>
</div>";
$textBody = "Your Styled verification code is: {$code}. It expires in 15 minutes.";
$result = sendEmailViaBrevo($email, $full_name, $subject, $htmlBody, $textBody);

// The account is created either way — don't block signup over email
// delivery. But the previous version ignored $result entirely and always
// told the frontend "success", even when the email never sent, which left
// a customer on verify-email.html with a code that would never arrive and
// no indication anything was wrong. Surface it instead.
if (!$result['success']) {
    error_log('register.php: verification email failed to send to ' . $email . ': ' . ($result['error'] ?? 'unknown error'));
}

http_response_code(201);
echo json_encode(['success' => true, 'email_sent' => $result['success']]);