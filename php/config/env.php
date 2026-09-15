<?php
// ============================================
// ENV LOADER — php/config/env.php
// ============================================
// Loads KEY=VALUE pairs from the project's .env file (git-ignored, see
// .gitignore) into getenv()/$_ENV. Real environment variables (e.g. set by
// the OS or a hosting panel) always take priority and are never overwritten.
//
// Usage: require_once __DIR__ . '/env.php'; loadEnv();

function loadEnv(): void {
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $path = __DIR__ . '/../../.env';
    if (!is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);

        // Strip matching surrounding quotes, if present.
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last  = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        // Never override a value already set in the real environment.
        if ($key === '' || getenv($key) !== false) {
            continue;
        }

        putenv("$key=$value");
        $_ENV[$key] = $value;
    }
}
