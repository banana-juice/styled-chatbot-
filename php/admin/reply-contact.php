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
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config/smtp.php';

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// Require authentication (admin or staff)
$user = requireAuth();

// Parse input
$input = json_decode(file_get_contents('php://input'), true);
$message_id   = isset($input['message_id'])   ? (int) $input['message_id']   : 0;
$reply_subject = isset($input['reply_subject']) ? trim($input['reply_subject']) : '';
$reply_body    = isset($input['reply_body'])    ? trim($input['reply_body'])    : '';

// Validate
if (!$message_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing message_id.']);
    exit;
}
if (empty($reply_subject)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Subject is required.']);
    exit;
}
if (empty($reply_body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Message body is required.']);
    exit;
}

// Fetch original message
$pdo = getPDO();
$stmt = $pdo->prepare("SELECT * FROM contact_messages WHERE message_id = ?");
$stmt->execute([$message_id]);
$msg = $stmt->fetch();

if (!$msg) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Message not found.']);
    exit;
}

// --- Send email using PHPMailer ---
require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;

    $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
    $mail->addAddress($msg['email'], $msg['name']);

    $mail->isHTML(true);
    $mail->Subject = $reply_subject;
    $mail->Body    = nl2br(htmlspecialchars($reply_body, ENT_QUOTES, 'UTF-8'));
    $mail->AltBody = $reply_body;

    $mail->send();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Email sending failed: ' . $mail->ErrorInfo
    ]);
    exit;
}

// Update status to 'replied'
$pdo->prepare("UPDATE contact_messages SET status = 'replied' WHERE message_id = ?")
    ->execute([$message_id]);

echo json_encode(['success' => true]);