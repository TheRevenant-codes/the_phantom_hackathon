<?php
// ══════════════════════════════════════════════════════
// PARASITE CONFIG — config.php
// ══════════════════════════════════════════════════════

// ── DATABASE ──────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'parasite_db');
define('DB_USER', 'root');
define('DB_PASS', '');  // Change if your MySQL has a password

// ── MONO API (get these from app.mono.co) ─────────────
// Sign up free at https://app.mono.co
// Go to: Settings → API Keys → copy your Sandbox keys
define('MONO_SECRET_KEY',    'test_pk_ktz887xu36h3sz260gpd');
define('MONO_PUBLIC_KEY',    'test_sk_je9iwyq1w0rfkro7ht3t');
define('MONO_WEBHOOK_SECRET','');  // leave empty for now
// Mono API base URL — sandbox (no real money)
define('MONO_API_URL', 'https://api.withmono.com/v2');

// ── APP SETTINGS ──────────────────────────────────────
define('PLATFORM_FEE_PERCENT', 10);   // We keep 10% of what we recover
define('APP_URL', 'http://localhost/parasite');  // Change in production

// ── SCANNER RULES ─────────────────────────────────────
// Known subscription merchants — used to detect recurring charges
define('SUBSCRIPTION_MERCHANTS', json_encode([
    'NETFLIX', 'SPOTIFY', 'DSTV', 'GOTV', 'AMAZON', 'APPLE',
    'GOOGLE', 'YOUTUBE', 'SHOWMAX', 'STARTIMES', 'MTN', 'AIRTEL',
    'GLO', '9MOBILE', 'CANVA', 'CHATGPT', 'OPENAI', 'MICROSOFT',
    'ZOOM', 'DROPBOX', 'ADOBE', 'NOTION', 'SLACK', 'GITHUB'
]));

// Known cashback merchants
define('CASHBACK_MERCHANTS', json_encode([
    'JUMIA', 'KONGA', 'SLOT', 'PAYPORTE', 'JIJI',
    'UBER', 'BOLT', 'OPAY', 'MONIEPOINT'
]));

// Overcharge detection — flag transactions with these keywords
define('OVERCHARGE_KEYWORDS', json_encode([
    'CHARGE', 'FEE', 'LEVY', 'DEDUCTION', 'VAT', 'STAMP DUTY',
    'MAINTENANCE', 'SMS ALERT', 'CARD MAINTENANCE'
]));
?>
