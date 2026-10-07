<?php
// ============================================
// ENV LOADER — php/config/env.php
// ============================================
// Loads KEY=VALUE pairs from the project's .env file (git-ignored, see
// .gitignore) into getenv()/$_ENV. Real environment variables (e.g. set by
// the OS or a hosting panel) always take priority and are never overwritten.
//
// Usage: require_once __DIR__ . '/env.php'; loadEnv();

/**
 * Read one config value. Looks in the real environment first, then in the
 * values loadEnv() parsed from .env. This does NOT depend on putenv()/getenv()
 * working: shared hosts commonly disable putenv(), and when that happened
 * every setting read back empty (the app fell back to localhost/root for the
 * database and to "not configured" for the payment webhook).
 */
function env_value(string $key, $default = null) {
    $v = getenv($key);
    if ($v !== false && $v !== '') {
        return $v;
    }
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return $_ENV[$key];
    }
    if (isset($_SERVER[$key]) && is_string($_SERVER[$key]) && $_SERVER[$key] !== '') {
        return $_SERVER[$key];
    }
    return $default;
}

function loadEnv(): void {
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $path = __DIR__ . '/../../.env';
    $raw  = @file_get_contents($path);
    if ($raw === false) {
        error_log('loadEnv: could not read ' . $path);
        return;
    }
    $lines = preg_split('/\r\n|\r|\n/', $raw);

    foreach ($lines as $line) {
        $line = trim($line);
        // A UTF-8 byte-order mark would otherwise glue itself to the first key.
        $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
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

        // A real environment variable always wins over the file.
        if ($key === '' || getenv($key) !== false) {
            continue;
        }

        // First occurrence wins, so a duplicated line later in the file
        // can't silently change a value.
        if (isset($_ENV[$key])) {
            continue;
        }

        $_ENV[$key]    = $value;
        $_SERVER[$key] = $value;
        if (function_exists('putenv')) {
            @putenv("$key=$value"); // best effort only; env_value() doesn't need it
        }
    }
}
