<?php
// ============================================================
// SECURE SESSION START — php/session_boot.php
//
// Every endpoint that needs the login session loads this instead of calling
// session_start() directly, so the session cookie is always created with:
//   HttpOnly  - page scripts (and so any XSS) cannot read it
//   SameSite  - Lax: the browser does not send it on cross-site POSTs
//   Secure    - only over HTTPS, when the request arrived over HTTPS
// and strict mode, which refuses session ids the server never issued.
// ============================================================

if (!function_exists('session_request_is_https')) {
    function session_request_is_https(): bool {
        return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') === '443')
            || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
            || (stripos((string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''), 'https') !== false);
    }
}

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => session_request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
