<?php
// ============================================
// STAFF SESSION GUARD — php/auth/staff_guard.php
// ============================================
// Include at the TOP of any PHP page that should
// only be accessible to staff (or admin) users.
//
// Usage:
//   require_once __DIR__ . '/php/auth/staff_guard.php';
//
// Behaviour:
//   • If not logged in                     → redirect to auth.html
//   • If logged in as customer (not staff/admin) → redirect to index.html
//   • If admin                             → redirect to admin.html
//   • If staff                             → continue execution

require_once __DIR__ . '/../session_boot.php';

if (empty($_SESSION['user_id'])) {
    header('Location: /styled/auth.html');
    exit;
}

if ($_SESSION['role'] === 'admin') {
    header('Location: /styled/admin.html');
    exit;
}

if ($_SESSION['role'] !== 'staff') {
    header('Location: /styled/index.html');
    exit;
}