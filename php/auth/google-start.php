<?php

session_start();

require_once __DIR__ . '/../config/google_oauth.php';

// ── Guard: config not filled in yet ──────────────────────────
// GOOGLE_CLIENT_ID falls back to the literal string 'GOOGLE_CLIENT_ID'
// (see php/config/google_oauth.php) when GOOGLE_CLIENT_ID isn't set in
// .env — that's the actual placeholder value to check for, not
// 'PASTE_YOUR...', which this file never contained.
if (GOOGLE_CLIENT_ID === 'GOOGLE_CLIENT_ID' || GOOGLE_CLIENT_ID === '') {
    header('Location: ' . GOOGLE_RETURN_PAGE . '?oauth_error=config');
    exit;
}

// ── Already logged in? Nothing to do. ────────────────────────
if (isset($_SESSION['user_id'])) {
    header('Location: ' . GOOGLE_RETURN_PAGE . '?oauth=success');
    exit;
}

// ── Generate one-time security values ────────────────────────
$state         = bin2hex(random_bytes(16));
$nonce         = bin2hex(random_bytes(16));
$code_verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
$code_challenge = rtrim(strtr(base64_encode(hash('sha256', $code_verifier, true)), '+/', '-_'), '=');

$_SESSION['oauth_google'] = [
    'state'         => $state,
    'nonce'         => $nonce,
    'code_verifier' => $code_verifier,
    'created_at'    => time(),
];

// ── Build the authorization URL ──────────────────────────────
$params = [
    'client_id'             => GOOGLE_CLIENT_ID,
    'redirect_uri'          => GOOGLE_REDIRECT_URI,
    'response_type'         => 'code',              // Authorization Code flow
    'scope'                 => GOOGLE_SCOPES,
    'state'                 => $state,
    'nonce'                 => $nonce,
    'code_challenge'        => $code_challenge,
    'code_challenge_method' => 'S256',
    'access_type'           => 'online',
    'prompt'                => 'select_account',
];

header('Location: ' . GOOGLE_AUTH_ENDPOINT . '?' . http_build_query($params));
exit;
