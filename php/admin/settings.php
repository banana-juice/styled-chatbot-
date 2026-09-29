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

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../php/db.php';

header('Content-Type: application/json');

// This file previously had its auth check commented out with a
// "replace this if needed" placeholder — meaning ANY unauthenticated
// request could read every store setting and, via POST, rewrite any of
// them (shipping fee, VAT rate, payment methods, store email...) with
// no login at all. Reproduced live during QA: an anonymous curl POST
// changed the real shipping fee to 99999. Read access is left open to
// any logged-in admin/staff (matches other admin/*.php GETs); writes
// are restricted to admin only, since changing site-wide financial
// settings is the same tier of action as cancel/refund elsewhere.
requireAuth();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAuth('admin');
}

$method = $_SERVER['REQUEST_METHOD'];

// ── GET ──────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $group = trim($_GET['group'] ?? '');

    if ($group === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '`group` parameter is required.']);
        exit;
    }

    try {
        $pdo  = getPDO();
        $stmt = $pdo->prepare('SELECT `key`, `value` FROM settings WHERE `group` = ?');
        $stmt->execute([$group]);
        $rows = $stmt->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $out[$row['key']] = $row['value'];
        }

        echo json_encode(['success' => true, 'settings' => $out]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error.']);
    }
    exit;
}

// ── POST ─────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);

    if (!is_array($body) || empty($body['group'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid payload. `group` is required.']);
        exit;
    }

    $group = trim($body['group']);

    // Build list of [ key => value ] pairs to upsert
    $pairs = [];

    if (isset($body['settings']) && is_array($body['settings'])) {
        // Bulk form: { group, settings: { key: value, … } }
        foreach ($body['settings'] as $k => $v) {
            $pairs[trim($k)] = ($v === null) ? null : (string) $v;
        }
    } elseif (isset($body['key'])) {
        // Single pair: { group, key, value }
        $pairs[trim($body['key'])] = ($body['value'] === null) ? null : (string) $body['value'];
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Provide `key`/`value` or `settings` object.']);
        exit;
    }

    if (empty($pairs)) {
        echo json_encode(['success' => true]);
        exit;
    }

    try {
        $pdo = getPDO();
        $sql = 'INSERT INTO settings (`group`, `key`, `value`)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)';
        $stmt = $pdo->prepare($sql);

        $pdo->beginTransaction();
        foreach ($pairs as $key => $value) {
            $stmt->execute([$group, $key, $value]);
        }
        $pdo->commit();

        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error.']);
    }
    exit;
}

// ── Method not allowed ────────────────────────────────────────────────────────
http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed.']);