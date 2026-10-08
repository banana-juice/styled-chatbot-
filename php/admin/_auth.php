<?php
// ============================================
// SHARED AUTH HELPER — php/admin/_auth.php
// Include at top of every admin endpoint.
// Usage:
//   require_once __DIR__ . '/_auth.php';
//   $user = requireAuth();           // admin OR staff
//   $user = requireAuth('admin');    // admin only
// ============================================

function requireAuth(string $requiredRole = ''): array {
    if (session_status() === PHP_SESSION_NONE) {
        require_once __DIR__ . '/../session_boot.php';
    }

    $loggedIn = isset($_SESSION['user_id'], $_SESSION['role']);

    if (!$loggedIn) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
        exit;
    }

    $role = $_SESSION['role'];

    if (!in_array($role, ['admin', 'staff'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied.']);
        exit;
    }

    if ($requiredRole === 'admin' && $role !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Admin access required.']);
        exit;
    }

    return [
        'user_id'   => (int) $_SESSION['user_id'],
        'full_name' => $_SESSION['full_name'] ?? '',
        'email'     => $_SESSION['email'] ?? '',
        'role'      => $role,
    ];
}