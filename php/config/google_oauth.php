<?php
// ============================================================
// Google OAuth credentials — "Sign in with Google"
// ============================================================
// Real values come from .env (git-ignored), never from this file directly —
// same pattern as every other credential in this project. Fill these in
// from Google Cloud Console > APIs & Services > Credentials.
//
// Add to .env (project root):
//   GOOGLE_CLIENT_ID=...
//   GOOGLE_CLIENT_SECRET=...
// ============================================================

require_once __DIR__ . '/env.php';
loadEnv();

define('GOOGLE_CLIENT_ID',     env_value('GOOGLE_CLIENT_ID') ?: 'GOOGLE_CLIENT_ID');
define('GOOGLE_CLIENT_SECRET', env_value('GOOGLE_CLIENT_SECRET') ?: 'GOOGLE_CLIENT_SECRET');

// Must byte-for-byte match the redirect URI registered in Google Console.
define('GOOGLE_REDIRECT_URI', env_value('GOOGLE_REDIRECT_URI') ?: 'https://styled.great-site.net/styled/php/auth/google-callback.php');

// Where the browser is sent back to after the OAuth round-trip.
define('GOOGLE_RETURN_PAGE', env_value('GOOGLE_RETURN_PAGE') ?: 'https://styled.great-site.net/styled/auth.html');

// --- OpenID Connect endpoints (Google's discovery doc) ---------
define('GOOGLE_AUTH_ENDPOINT',     'https://accounts.google.com/o/oauth2/v2/auth');
define('GOOGLE_TOKEN_ENDPOINT',    'https://oauth2.googleapis.com/token');
define('GOOGLE_USERINFO_ENDPOINT', 'https://openidconnect.googleapis.com/v1/userinfo');
define('GOOGLE_ISSUERS', ['https://accounts.google.com', 'accounts.google.com']);

// Scopes requested: a stable user id (sub), email, display name, picture.
define('GOOGLE_SCOPES', 'openid email profile');
