<?php
// ============================================================
// PayMongo API credentials — STYLED payment gateway integration
// ============================================================
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
// SECURITY: this file must NEVER be committed to a public repo with
// real keys filled in, and must never be requested from the frontend.
// It follows the same pattern as php/config/smtp.php in this project.
// ============================================================

define('PAYMONGO_SECRET_KEY', 'PAYMONGO_SECRET_KEY');
define('PAYMONGO_PUBLIC_KEY', 'PAYMONGO_PUBLIC_KEY');

// Dashboard > Developers > Webhooks > (your webhook) > Signing Secret
define('PAYMONGO_WEBHOOK_SECRET', 'PAYMONGO_WEBHOOK_SECRET');

// Base URL of the deployed site, NO trailing slash. Used to build the
// success_url / cancel_url PayMongo redirects the customer back to.
define('SITE_URL', 'https://styled.great-site.net/styled');
