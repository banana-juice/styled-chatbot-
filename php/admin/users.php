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
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db.php';

requireAuth('admin');
$method = $_SERVER['REQUEST_METHOD'];
$pdo    = getPDO();

// ── GET ───────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $role = $_GET['role'] ?? '';

    $where  = ["role IN ('admin','staff')"];
    $params = [];

    if ($role) {
        $where[]  = 'role = ?';
        $params[] = $role;
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $where);
    $stmt = $pdo->prepare("
        SELECT user_id, full_name, email, role, is_verified, created_at
        FROM users
        $whereSQL
        ORDER BY created_at DESC
    ");
    $stmt->execute($params);

    echo json_encode(['success' => true, 'users' => $stmt->fetchAll()]);
    exit;
}

// ── POST: Add staff member ────────────────────────────────────────────────────
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    foreach (['full_name', 'email'] as $f) {
        if (empty($body[$f])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "Missing field: $f"]);
            exit;
        }
    }

    if (!filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid email address.']);
        exit;
    }

    // Duplicate check
    $chk = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
    $chk->execute([$body['email']]);
    if ($chk->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Email already in use.']);
        exit;
    }

    // Generate a temporary password
    $tempPassword = bin2hex(random_bytes(8));
    $hash         = password_hash($tempPassword, PASSWORD_DEFAULT);
    $role         = in_array($body['role'] ?? '', ['admin', 'staff']) ? $body['role'] : 'staff';

    $stmt = $pdo->prepare("
        INSERT INTO users (full_name, email, password_hash, role, is_verified, created_at)
        VALUES (:full_name, :email, :password_hash, :role, 0, NOW())
    ");
    $stmt->execute([
        ':full_name'     => $body['full_name'],
        ':email'         => $body['email'],
        ':password_hash' => $hash,
        ':role'          => $role,
    ]);

    $newUserId = (int) $pdo->lastInsertId();

    // Also create staff table entry
    $pdo->prepare("
        INSERT INTO staff (user_id, role, can_manage_products, can_manage_orders, can_manage_promos, can_view_analytics)
        VALUES (?, ?, 0, 1, 0, 0)
    ")->execute([$newUserId, $role]);

    // Send invitation email
    require_once __DIR__ . '/../email-functions.php';
    $emailSent = send_staff_invite_email($body['email'], $body['full_name'], $tempPassword, $role);

    echo json_encode([
        'success'  => true,
        'user_id'  => $newUserId,
        'email_sent' => $emailSent,
        'message'  => $emailSent ? 'Staff added and invitation sent.' : 'Staff added but invitation email failed.',
    ]);
    exit;
}

// ── PUT: Update role / status ─────────────────────────────────────────────────
if ($method === 'PUT') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing user id.']);
        exit;
    }

    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $fields = [];
    $params = [];

    if (isset($body['role']) && in_array($body['role'], ['admin', 'staff', 'customer'])) {
        $fields[] = 'role = ?';
        $params[] = $body['role'];
    }
    if (isset($body['is_verified'])) {
        $fields[] = 'is_verified = ?';
        $params[] = (int) $body['is_verified'];
    }

    if (empty($fields)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Nothing to update.']);
        exit;
    }

    $params[] = $id;
    $pdo->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE user_id = ?")->execute($params);

    echo json_encode(['success' => true]);
    exit;
}

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing user id.']);
        exit;
    }

    // Safety: prevent deleting yourself
    if ($id === (int) ($_SESSION['user_id'] ?? 0)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Cannot delete your own account.']);
        exit;
    }

    $pdo->prepare("DELETE FROM staff WHERE user_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM users WHERE user_id = ? AND role IN ('admin','staff')")->execute([$id]);

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);