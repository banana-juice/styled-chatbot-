<?php

require_once __DIR__ . '/config/env.php';
loadEnv();

// One explicit timezone for the whole app. Without this, PHP's date() and
// MySQL's NOW() each fall back to whatever the host happens to default to,
// so the same order could show different times on different pages (or
// shift entirely after a hosting move). Asia/Manila (+08:00, no DST) is the
// store's local time.
// Never print PHP warnings/notices into a response: they expose the server's file
// paths and break the JSON the pages expect. (php/.user.ini is supposed to do
// this but isn't honoured on the free host, so it's enforced here, in the one
// file every endpoint loads. Errors still go to the error log.)
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('APP_TIMEZONE', 'Asia/Manila');
define('APP_TZ_OFFSET', '+08:00');
date_default_timezone_set(APP_TIMEZONE);

// DB_* come from the environment (.env) so the same code works locally and
// in production without ever hardcoding a real credential in a committed
// file — same pattern as ANTHROPIC_API_KEY in php/support.php. Falls back
// to XAMPP's local defaults when nothing is set, so local dev needs no
// .env entries at all.
define('DB_HOST', env_value('DB_HOST') ?: 'localhost');
define('DB_NAME', env_value('DB_NAME') ?: 'styled_db');
define('DB_USER', env_value('DB_USER') ?: 'root');
define('DB_PASS', env_value('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

/**
 * A positive whole number from user input, or 0 if it isn't one.
 * Use this instead of (int): (int) turns the array [1] and the text
 * "1 OR 1=1" into 1, so a malformed product_id silently became product #1
 * (a live stress test created a real order that way).
 */
function as_pos_int($v): int {
    if (is_int($v)) {
        return $v > 0 ? $v : 0;
    }
    if (is_string($v) && ctype_digit($v) && strlen($v) <= 18) {
        return (int) $v;
    }
    return 0;
}

/** Text input only: anything that isn't a string (array, object, number, null) becomes ''. */
function as_text($v): string {
    return is_string($v) ? $v : '';
}

function getPDO(): PDO {
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        DB_HOST,
        DB_NAME,
        DB_CHARSET
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        // Keep NOW()/CURRENT_TIMESTAMP in step with date_default_timezone_set().
        $pdo->exec("SET time_zone = '" . APP_TZ_OFFSET . "'");
    } catch (PDOException $e) {
        // Never expose the real error message to the client in production.
        error_log('Database connection failed: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error'   => 'Database connection failed. Please try again later.',
        ]);
        exit;
    }

    return $pdo;
}
