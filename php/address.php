<?php
// ============================================================
// SAVED CHECKOUT DETAILS — php/address.php
//
// GET: the signed-in customer's name/email and the address (with phone) they
// used on their last order, so checkout can pre-fill everything and they only
// type an address again if it has changed.
//
// Only ever returns the caller's own data.
// ============================================================

header("Access-Control-Allow-Origin: https://styled.great-site.net");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

ini_set('display_errors', '0');
error_reporting(E_ALL);
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/stock.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'logged_in' => false]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $pdo = getPDO();
    stock_ensure_schema($pdo); // makes sure addresses.phone exists
    $uid = (int) $_SESSION['user_id'];

    $u = $pdo->prepare('SELECT full_name, email FROM users WHERE user_id = ?');
    $u->execute([$uid]);
    $profile = $u->fetch(PDO::FETCH_ASSOC) ?: ['full_name' => '', 'email' => ''];

    // Default (last used) address first, then the newest.
    $a = $pdo->prepare('
        SELECT street, city, province, zip_code, phone
        FROM addresses WHERE user_id = ?
        ORDER BY is_default DESC, address_id DESC LIMIT 1');
    $a->execute([$uid]);
    $address = $a->fetch(PDO::FETCH_ASSOC) ?: null;

    // A phone saved on any earlier address is still the best guess.
    if ($address && empty($address['phone'])) {
        $p = $pdo->prepare("SELECT phone FROM addresses WHERE user_id = ? AND phone IS NOT NULL AND phone <> '' ORDER BY address_id DESC LIMIT 1");
        $p->execute([$uid]);
        $address['phone'] = $p->fetchColumn() ?: null;
    }

    echo json_encode(['success' => true, 'profile' => $profile, 'address' => $address]);
} catch (Throwable $e) {
    error_log('address.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
