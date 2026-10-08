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
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/db.php';

// Get and sanitize input
$name    = trim(is_string($_POST['name'] ?? null) ? $_POST['name'] : '');
$email   = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
$subject = trim(is_string($_POST['subject'] ?? null) ? $_POST['subject'] : '');
$message = trim(is_string($_POST['message'] ?? null) ? $_POST['message'] : '');

// Validate: all fields required
if (empty($name) || empty($email) || empty($subject) || empty($message)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'All fields are required.']);
    exit;
}

// Sensible lengths (the columns are not unlimited, and a 100 KB "message" is not a message)
if (mb_strlen($name) > 100 || mb_strlen($email) > 150 || mb_strlen($subject) > 200 || mb_strlen($message) > 5000) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'That message is too long. Please shorten it and try again.']);
    exit;
}

// Validate: email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please enter a valid email address.']);
    exit;
}

// Insert into contact_messages table
try {
    $pdo  = getPDO();

    // Flood limit: a few messages per visitor per hour is plenty for a real customer.
    require_once __DIR__ . '/ratelimit.php';
    $rules = ['contact:ip:' . client_ip() => [5, 3600], 'contact:email:' . ratelimit_key($email) => [3, 3600]];
    if ($wait = ratelimit_blocked($pdo, $rules)) {
        ratelimit_fail($wait, 'messages');
    }
    foreach (array_keys($rules) as $bucket) {
        ratelimit_hit($pdo, $bucket);
    }

    $stmt = $pdo->prepare("
        INSERT INTO contact_messages (name, email, subject, message, status, sent_at)
        VALUES (:name, :email, :subject, :message, 'unread', NOW())
    ");
    $stmt->execute([
        ':name'    => $name,
        ':email'   => $email,
        ':subject' => $subject,
        ':message' => $message,
    ]);

    $messageId = $pdo->lastInsertId();

    echo json_encode([
        'success'    => true,
        'message_id' => (int) $messageId,
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to send message. Please try again.']);
    exit;
}