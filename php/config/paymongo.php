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

define('PAYMONGO_SECRET_KEY', getenv('PAYMONGO_SECRET_KEY') ?: 'PAYMONGO_SECRET_KEY');
define('PAYMONGO_PUBLIC_KEY', getenv('PAYMONGO_PUBLIC_KEY') ?: 'PAYMONGO_PUBLIC_KEY');

// Dashboard > Developers > Webhooks > (your webhook) > Signing Secret
define('PAYMONGO_WEBHOOK_SECRET', getenv('PAYMONGO_WEBHOOK_SECRET') ?: 'PAYMONGO_WEBHOOK_SECRET');

// Base URL of the deployed site, NO trailing slash. Used to build the
// success_url / cancel_url PayMongo redirects the customer back to.
define('SITE_URL', getenv('SITE_URL') ?: 'https://styled.great-site.net/styled');
