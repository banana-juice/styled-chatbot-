<?php
// ============================================
// ADMIN SESSION GUARD — php/auth/admin_guard.php
// ============================================
// Include at the TOP of any PHP page that should
// only be accessible to admin users.
//
// Usage:
//   require_once __DIR__ . '/php/auth/admin_guard.php';
//
// Behaviour:
//   • If not logged in            → redirect to auth.html
//   • If logged in but not admin  → redirect to index.html
//   • If admin                    → continue execution

require_once __DIR__ . '/../session_boot.php';

if (empty($_SESSION['user_id'])) {
    header('Location: /styled/auth.html');
    exit;
}

if ($_SESSION['role'] !== 'admin') {
    // Staff users go to their own dashboard
    if ($_SESSION['role'] === 'staff') {
        header('Location: /styled/staff.html');
    } else {
        header('Location: /styled/index.html');
    }
    exit;
}