<?php
// ============================================================
// PayMongo API credentials — STYLED payment gateway integration
// ============================================================
// Real values come from .env (git-ignored), never from this file directly —
// same pattern as ANTHROPIC_API_KEY in php/chatbot.php and DB_* in
// php/db.php. This keeps a real key from ever landing in a commit even if
// someone edits the constant here directly out of habit.
//
// Get these from the PayMongo Dashboard: https://dashboard.paymongo.com
//   Developers > API Keys        -> secret key & public key
//   Developers > Webhooks        -> signing secret (after you register
//                                    the webhook URL below)
//
// TEST mode keys look like: pk_test_xxx / sk_test_xxx
// LIVE mode keys look like: pk_live_xxx / sk_live_xxx
// Start in TEST mode. Do not switch to live keys until you've verified
// the full flow (checkout -> payment -> webhook -> order paid) works
// end-to-end with test cards / test GCash in PayMongo's sandbox.
//
// Add to .env (project root):
//   PAYMONGO_SECRET_KEY=sk_test_...
//   PAYMONGO_PUBLIC_KEY=pk_test_...
//   PAYMONGO_WEBHOOK_SECRET=whsec_...
// ============================================================

require_once __DIR__ . '/env.php';
loadEnv();

define('PAYMONGO_SECRET_KEY', env_value('PAYMONGO_SECRET_KEY') ?: 'PAYMONGO_SECRET_KEY');
define('PAYMONGO_PUBLIC_KEY', env_value('PAYMONGO_PUBLIC_KEY') ?: 'PAYMONGO_PUBLIC_KEY');

// Dashboard > Developers > Webhooks > (your webhook) > Signing Secret
// No default on purpose. This used to fall back to the literal string
// 'PAYMONGO_WEBHOOK_SECRET', which silently made every real PayMongo webhook
// fail signature verification (so no order ever became "paid" on its own)
// while also being a publicly guessable signing key. An unset secret now
// makes the webhook refuse to run and say why.
define('PAYMONGO_WEBHOOK_SECRET', env_value('PAYMONGO_WEBHOOK_SECRET') ?: '');

// Base URL of the deployed site, NO trailing slash. Used to build the
// success_url / cancel_url PayMongo redirects the customer back to.
// The app is served from the /styled folder. A SITE_URL set to just the domain
// (https://styled.great-site.net) made the PayMongo success/cancel links point
// at /orders.html instead of /styled/orders.html, so normalise it here.
$__siteUrl = rtrim((string) (env_value('SITE_URL') ?: 'https://styled.great-site.net/styled'), '/');
if (!preg_match('#/styled$#', $__siteUrl)) {
    $__siteUrl .= '/styled';
}
define('SITE_URL', $__siteUrl);
unset($__siteUrl);
