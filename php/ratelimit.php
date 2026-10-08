<?php
// ============================================================
// RATE LIMITING — php/ratelimit.php
//
// A small sliding-window limiter backed by one table. Used to slow down
// password guessing, verification-code guessing and form flooding.
//
//   $wait = ratelimit_blocked($pdo, ['login:ip:1.2.3.4' => [40, 900]]);   // [max, window seconds]
//   if ($wait) { ... answer 429 ... }
//   ratelimit_hit($pdo, 'login:ip:1.2.3.4');                                // record one event
// ============================================================

require_once __DIR__ . '/schema_flags.php';

function ratelimit_ensure(PDO $pdo): void {
    schema_once($pdo, 'ratelimit_v1', function () use ($pdo) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS rate_limit_hits (
                id         BIGINT AUTO_INCREMENT PRIMARY KEY,
                bucket     VARCHAR(190) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_bucket_time (bucket, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    });
}

/** The visitor's address as the web server sees it (never a client-supplied header, which could be forged). */
function client_ip(): string {
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/** A short stable key for an email, so buckets don't store the address itself. */
function ratelimit_key(string $value): string {
    return substr(hash('sha256', strtolower(trim($value))), 0, 20);
}

/**
 * Test-only switch: RATELIMIT_DISABLED=1 in .env turns every limit off so automated tests
 * can create dozens of accounts from one machine. It is NOT set in production.
 */
function ratelimit_disabled(): bool {
    return env_value('RATELIMIT_DISABLED') === '1';
}

/** Record one event in a bucket. Old rows are purged now and then. */
function ratelimit_hit(PDO $pdo, string $bucket): void {
    if (ratelimit_disabled()) {
        return;
    }
    ratelimit_ensure($pdo);
    $pdo->prepare('INSERT INTO rate_limit_hits (bucket) VALUES (?)')->execute([substr($bucket, 0, 190)]);
    if (random_int(1, 50) === 1) {
        $pdo->exec('DELETE FROM rate_limit_hits WHERE created_at < (NOW() - INTERVAL 1 DAY)');
    }
}

/** Forget a bucket (e.g. after a successful login). */
function ratelimit_clear(PDO $pdo, string $bucket): void {
    ratelimit_ensure($pdo);
    $pdo->prepare('DELETE FROM rate_limit_hits WHERE bucket = ?')->execute([substr($bucket, 0, 190)]);
}

/**
 * Check several buckets at once. $rules = [bucket => [max, windowSeconds], ...].
 * Returns null when everything is under its limit, otherwise the number of
 * seconds until the strictest exceeded bucket frees up (at least 1).
 */
function ratelimit_blocked(PDO $pdo, array $rules): ?int {
    if (ratelimit_disabled()) {
        return null;
    }
    ratelimit_ensure($pdo);
    $worst = null;
    foreach ($rules as $bucket => [$max, $window]) {
        $st = $pdo->prepare('
            SELECT COUNT(*) AS n,
                   TIMESTAMPDIFF(SECOND, NOW(), MIN(created_at) + INTERVAL ? SECOND) AS frees
            FROM (
                SELECT created_at FROM rate_limit_hits
                WHERE bucket = ? AND created_at > (NOW() - INTERVAL ? SECOND)
                ORDER BY created_at DESC LIMIT ?
            ) recent');
        $st->execute([$window, substr((string) $bucket, 0, 190), $window, $max]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ((int) $row['n'] >= $max) {
            $wait = max(1, (int) $row['frees']);
            $worst = $worst === null ? $wait : max($worst, $wait);
        }
    }
    return $worst;
}

/** Answer 429 with a friendly message and stop. */
function ratelimit_fail(int $waitSeconds, string $what = 'attempts'): void {
    http_response_code(429);
    header('Retry-After: ' . $waitSeconds);
    header('Content-Type: application/json');
    $mins = max(1, (int) ceil($waitSeconds / 60));
    echo json_encode([
        'success'     => false,
        'error'       => "Too many $what. Please try again in $mins minute" . ($mins === 1 ? '' : 's') . '.',
        'retry_after' => $waitSeconds,
    ]);
    exit;
}
