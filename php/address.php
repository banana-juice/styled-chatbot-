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
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

ini_set('display_errors', '0');
error_reporting(E_ALL);
require_once __DIR__ . '/session_boot.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/stock.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'logged_in' => false]);
    exit;
}
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $pdo = getPDO();
    stock_ensure_schema($pdo); // makes sure addresses.phone exists
    $uid = (int) $_SESSION['user_id'];

    // ── POST: the customer sets up / changes their delivery address ─────────
    // Always stored as an address row of its own (or an identical existing one is
    // re-used) and made the default. Existing rows are never edited, because past
    // orders point at them and must keep the address they were shipped to.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true);
        $in = is_array($in) ? $in : [];
        $f = [];
        foreach (['street' => 255, 'city' => 100, 'province' => 100, 'zip_code' => 10, 'phone' => 30] as $k => $max) {
            $v = isset($in[$k]) && is_string($in[$k]) ? trim($in[$k]) : '';
            if ($v === '') {
                http_response_code(422);
                echo json_encode(['error' => 'Please fill in every field.', 'field' => $k]);
                exit;
            }
            if (mb_strlen($v) > $max) {
                http_response_code(422);
                echo json_encode(['error' => 'That value is too long.', 'field' => $k]);
                exit;
            }
            $f[$k] = $v;
        }
        if (!preg_match('/^[0-9+()\-\s]{7,30}$/', $f['phone'])) {
            http_response_code(422);
            echo json_encode(['error' => 'Please enter a valid phone number.', 'field' => 'phone']);
            exit;
        }

        $pdo->beginTransaction();
        $find = $pdo->prepare('SELECT address_id FROM addresses
            WHERE user_id = ? AND street = ? AND city = ? AND province = ? AND zip_code = ? LIMIT 1');
        $find->execute([$uid, $f['street'], $f['city'], $f['province'], $f['zip_code']]);
        $aid = $find->fetchColumn();
        if ($aid) {
            $pdo->prepare('UPDATE addresses SET phone = ? WHERE address_id = ?')->execute([$f['phone'], $aid]);
        } else {
            $pdo->prepare('INSERT INTO addresses (user_id, label, street, city, province, zip_code, phone, is_default)
                VALUES (?, "Shipping", ?, ?, ?, ?, ?, 0)')
                ->execute([$uid, $f['street'], $f['city'], $f['province'], $f['zip_code'], $f['phone']]);
            $aid = $pdo->lastInsertId();
        }
        $pdo->prepare('UPDATE addresses SET is_default = (address_id = ?) WHERE user_id = ?')->execute([$aid, $uid]);
        $pdo->commit();

        echo json_encode(['success' => true, 'address' => $f]);
        exit;
    }

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

    // "complete" = checkout can use it as-is (needs a phone number as well).
    $complete = $address && !empty($address['street']) && !empty($address['city'])
        && !empty($address['province']) && !empty($address['zip_code']) && !empty($address['phone']);

    echo json_encode(['success' => true, 'profile' => $profile, 'address' => $address, 'complete' => (bool) $complete]);
} catch (Throwable $e) {
    error_log('address.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
