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

requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$pdo    = getPDO();

// ── GET ───────────────────────────────────────────────────────────────────────
if ($method === 'GET') {

    // Single message
    if (!empty($_GET['id'])) {
        $stmt = $pdo->prepare("SELECT * FROM contact_messages WHERE message_id = ?");
        $stmt->execute([(int) $_GET['id']]);
        $msg = $stmt->fetch();

        if (!$msg) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Message not found.']);
            exit;
        }

        echo json_encode(['success' => true, 'message' => $msg]);
        exit;
    }

    // List
    $status = $_GET['status'] ?? '';
    $page   = max(1, (int) ($_GET['page']  ?? 1));
    $limit  = min(50, max(1, (int) ($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $where  = [];
    $params = [];

    if ($status) {
        $where[]  = 'status = ?';
        $params[] = $status;
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM contact_messages $whereSQL");
    $totalStmt->execute($params);
    $totalCount = (int) $totalStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT * FROM contact_messages
        $whereSQL
        ORDER BY sent_at DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);

    echo json_encode([
        'success'  => true,
        'messages' => $stmt->fetchAll(),
        'total'    => $totalCount,
        'page'     => $page,
        'pages'    => (int) ceil($totalCount / $limit),
    ]);
    exit;
}

// ── PUT: Update status ────────────────────────────────────────────────────────
if ($method === 'PUT') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing message id.']);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    if (!isset($body['status']) || !in_array($body['status'], ['unread', 'read', 'replied'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'status must be unread, read, or replied.']);
        exit;
    }

    $pdo->prepare("UPDATE contact_messages SET status = ? WHERE message_id = ?")
        ->execute([$body['status'], $id]);

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);