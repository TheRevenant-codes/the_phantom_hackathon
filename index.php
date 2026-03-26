<?php
// ══════════════════════════════════════════════════════
// PARASITE API — index.php
// Main router — all requests go through here
//
// ENDPOINTS:
// POST /register           — create account
// POST /login              — get session token
// POST /link-account       — connect bank via Mono
// POST /sync               — fetch latest transactions
// GET  /dashboard          — recovery summary
// GET  /transactions       — all scanned transactions
// GET  /subscriptions      — detected subscriptions
// GET  /disputes           — filed disputes
// GET  /cashback           — cashback claims
// GET  /swarms             — active swarm clusters
// POST /webhook/mono       — receives Mono notifications
// ══════════════════════════════════════════════════════

require_once 'db.php';
require_once 'mono.php';
require_once 'scanner.php';
require_once 'config.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ── ROUTE ─────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri    = str_replace('/parasite_backend/', '/', $uri);
$uri    = trim($uri, '/');

// Read JSON body if sent as application/json
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$data = array_merge($_POST, $body);

// ══════════════════════════════════════════════════════
// 1. REGISTER
// POST /register
// Body: { name, email, password, phone }
// ══════════════════════════════════════════════════════
if ($uri === 'register' && $method === 'POST') {
    $name     = trim($data['name']     ?? '');
    $email    = trim($data['email']    ?? '');
    $password = trim($data['password'] ?? '');
    $phone    = trim($data['phone']    ?? '');

    if (!$name || !$email || !$password) {
        fail("Name, email and password are required");
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail("Invalid email address");
    }
    if (strlen($password) < 6) {
        fail("Password must be at least 6 characters");
    }

    $db    = getDB();
    $hash  = password_hash($password, PASSWORD_DEFAULT);
    $token = bin2hex(random_bytes(32));

    try {
        $db->prepare("
            INSERT INTO users (name, email, password_hash, phone, session_token)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$name, $email, $hash, $phone, $token]);

        $user_id = $db->lastInsertId();

        ok([
            "token"   => $token,
            "user_id" => $user_id,
            "name"    => $name,
            "email"   => $email,
            "message" => "Account created. Connect your bank to start hunting."
        ]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) {
            fail("An account with this email already exists");
        }
        fail("Registration failed: " . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════
// 2. LOGIN
// POST /login
// Body: { email, password }
// ══════════════════════════════════════════════════════
if ($uri === 'login' && $method === 'POST') {
    $email    = trim($data['email']    ?? '');
    $password = trim($data['password'] ?? '');

    if (!$email || !$password) {
        fail("Email and password required");
    }

    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        fail("Invalid email or password", 401);
    }

    $token = bin2hex(random_bytes(32));
    $db->prepare("UPDATE users SET session_token = ? WHERE id = ?")->execute([$token, $user['id']]);

    $acc_stmt = $db->prepare("SELECT COUNT(*) as n FROM accounts WHERE user_id = ?");
    $acc_stmt->execute([$user['id']]);
    $account_count = (int)$acc_stmt->fetch()['n'];

    ok([
        "token"         => $token,
        "user_id"       => $user['id'],
        "name"          => $user['name'],
        "email"         => $user['email'],
        "has_accounts"  => $account_count > 0,
        "account_count" => $account_count
    ]);
}

// ══════════════════════════════════════════════════════
// 3. LINK BANK ACCOUNT
// POST /link-account
// Body: { code }  ← code comes from Mono Connect widget
// Auth: Bearer token
// ══════════════════════════════════════════════════════
if ($uri === 'link-account' && $method === 'POST') {
    $user = getAuthUser();
    $code = trim($data['code'] ?? '');

    if (!$code) {
        fail("Mono auth code required");
    }

    $mono_response = Mono::exchangeCode($code);

    if (isset($mono_response['error'])) {
        fail("Mono error: " . $mono_response['error']);
    }

    $mono_account_id = $mono_response['id'] ?? null;
    if (!$mono_account_id) {
        fail("Failed to get account ID from Mono");
    }

    $account_details = Mono::getAccount($mono_account_id);
    $account_data    = $account_details['account'] ?? [];

    $db = getDB();

    try {
        $db->prepare("
            INSERT INTO accounts
            (user_id, mono_account_id, bank_name, account_number, account_name, account_type, balance, currency)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                balance     = VALUES(balance),
                last_synced = NOW()
        ")->execute([
            $user['id'],
            $mono_account_id,
            $account_data['institution']['name'] ?? 'UBA',
            $account_data['accountNumber']       ?? '',
            $account_data['name']                ?? $user['name'],
            $account_data['type']                ?? 'checking',
            ($account_data['balance'] ?? 0) / 100,
            $account_data['currency']            ?? 'NGN'
        ]);

        $account_id = $db->lastInsertId();

        if (!$account_id) {
            $existing = $db->prepare("SELECT id FROM accounts WHERE mono_account_id = ?");
            $existing->execute([$mono_account_id]);
            $row        = $existing->fetch();
            $account_id = $row ? $row['id'] : null;
        }

        ok([
            "account_id"      => $account_id,
            "mono_account_id" => $mono_account_id,
            "bank"            => $account_data['institution']['name'] ?? 'UBA',
            "account_number"  => $account_data['accountNumber']       ?? '',
            "account_name"    => $account_data['name']                ?? '',
            "balance"         => ($account_data['balance'] ?? 0) / 100,
            "message"         => "Bank account linked. Run /sync to start scanning transactions."
        ]);
    } catch (PDOException $e) {
        fail("Failed to save account: " . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════
// 4. SYNC TRANSACTIONS
// POST /sync
// Auth: Bearer token
// ══════════════════════════════════════════════════════
if ($uri === 'sync' && $method === 'POST') {
    $user = getAuthUser();
    $db   = getDB();

    $stmt = $db->prepare("SELECT * FROM accounts WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    $accounts = $stmt->fetchAll();

    if (empty($accounts)) {
        fail("No bank accounts linked. Call /link-account first.");
    }

    $total_fetched = 0;
    $total_new     = 0;

    foreach ($accounts as $account) {
        $start     = date('d-m-Y', strtotime('-3 months'));
        $end       = date('d-m-Y');
        $mono_txns = Mono::getTransactions($account['mono_account_id'], $start, $end);

        if (isset($mono_txns['error'])) {
            continue;
        }

        $transactions   = $mono_txns['data'] ?? [];
        $total_fetched += count($transactions);

        foreach ($transactions as $txn) {
            $mono_txn_id = $txn['_id']      ?? '';
            $amount      = abs($txn['amount'] ?? 0) / 100;
            $type        = ($txn['type']      ?? '') === 'debit' ? 'debit' : 'credit';
            $date        = date('Y-m-d', strtotime($txn['date'] ?? 'now'));
            $narration   = $txn['narration']  ?? '';
            $merchant    = self_extract_merchant($narration);
            $balance     = ($txn['balance']   ?? 0) / 100;
            $category    = self_categorize($narration);

            try {
                $db->prepare("
                    INSERT INTO transactions
                    (user_id, account_id, mono_txn_id, merchant, amount, type,
                     currency, category, narration, txn_date, balance_after)
                    VALUES (?, ?, ?, ?, ?, ?, 'NGN', ?, ?, ?, ?)
                ")->execute([
                    $user['id'], $account['id'], $mono_txn_id,
                    $merchant, $amount, $type,
                    $category, $narration, $date, $balance
                ]);
                $total_new++;
            } catch (PDOException $e) {
                continue;
            }
        }

        $db->prepare("UPDATE accounts SET last_synced = NOW() WHERE id = ?")
           ->execute([$account['id']]);
    }

    $scan_results = Scanner::scanUser($user['id']);
    $recoverable  = Scanner::getTotalRecoverable($user['id']);

    ok([
        "transactions_fetched" => $total_fetched,
        "transactions_new"     => $total_new,
        "scan_results"         => $scan_results,
        "recoverable"          => $recoverable,
        "message"              => "Sync complete. PARASITE is hunting."
    ]);
}

// ══════════════════════════════════════════════════════
// 5. DASHBOARD
// GET /dashboard
// Auth: Bearer token
// ══════════════════════════════════════════════════════
if ($uri === 'dashboard' && $method === 'GET') {
    $user        = getAuthUser();
    $db          = getDB();
    $recoverable = Scanner::getTotalRecoverable($user['id']);

    $accs = $db->prepare("SELECT * FROM accounts WHERE user_id = ?");
    $accs->execute([$user['id']]);
    $accounts = $accs->fetchAll();

    $flagged_stmt = $db->prepare("
        SELECT * FROM transactions
        WHERE user_id = ? AND flagged = 1
        ORDER BY txn_date DESC
        LIMIT 10
    ");
    $flagged_stmt->execute([$user['id']]);
    $flagged = $flagged_stmt->fetchAll();

    $sub_stmt = $db->prepare("
        SELECT COUNT(*) as n, COALESCE(SUM(amount), 0) as total
        FROM subscriptions
        WHERE user_id = ? AND status = 'active'
    ");
    $sub_stmt->execute([$user['id']]);
    $subs = $sub_stmt->fetch();

    $dis_stmt = $db->prepare("
        SELECT COUNT(*) as n, COALESCE(SUM(amount), 0) as total
        FROM disputes
        WHERE user_id = ?
    ");
    $dis_stmt->execute([$user['id']]);
    $disputes = $dis_stmt->fetch();

    $cb_stmt = $db->prepare("
        SELECT COUNT(*) as n, COALESCE(SUM(amount), 0) as total
        FROM cashback_claims
        WHERE user_id = ? AND status = 'pending'
    ");
    $cb_stmt->execute([$user['id']]);
    $cashback = $cb_stmt->fetch();

    $swarm_stmt = $db->prepare("
        SELECT sc.*
        FROM swarm_clusters sc
        INNER JOIN disputes d ON d.swarm_id = sc.id
        WHERE d.user_id = ?
    ");
    $swarm_stmt->execute([$user['id']]);
    $swarms = $swarm_stmt->fetchAll();

    ok([
        "user" => [
            "name"  => $user['name'],
            "email" => $user['email']
        ],
        "accounts"    => $accounts,
        "recoverable" => $recoverable,
        "subscriptions" => [
            "count"        => (int)$subs['n'],
            "monthly_cost" => (float)$subs['total']
        ],
        "disputes" => [
            "count"        => (int)$disputes['n'],
            "total_amount" => (float)$disputes['total']
        ],
        "cashback" => [
            "count"        => (int)$cashback['n'],
            "total_amount" => (float)$cashback['total']
        ],
        "swarms"       => $swarms,
        "recent_flags" => $flagged
    ]);
}

// ══════════════════════════════════════════════════════
// 6. TRANSACTIONS
// GET /transactions?page=1&flag=1
// Auth: Bearer token
// ══════════════════════════════════════════════════════
if ($uri === 'transactions' && $method === 'GET') {
    $user         = getAuthUser();
    $db           = getDB();
    $page         = max(1, intval($_GET['page'] ?? 1));
    $limit        = 50;
    $offset       = ($page - 1) * $limit;
    $flagged_only = isset($_GET['flag']) && $_GET['flag'] == '1';

    $where = "WHERE user_id = ?";
    if ($flagged_only) {
        $where .= " AND flagged = 1";
    }

    $stmt = $db->prepare("
        SELECT * FROM transactions
        $where
        ORDER BY txn_date DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute([$user['id']]);
    $txns = $stmt->fetchAll();

    $count_stmt = $db->prepare("SELECT COUNT(*) as n FROM transactions $where");
    $count_stmt->execute([$user['id']]);
    $total = (int)$count_stmt->fetch()['n'];

    ok([
        "transactions" => $txns,
        "total"        => $total,
        "page"         => $page,
        "pages"        => ceil($total / $limit)
    ]);
}

// ══════════════════════════════════════════════════════
// 7. SUBSCRIPTIONS
// GET /subscriptions
// Auth: Bearer token
// ══════════════════════════════════════════════════════
if ($uri === 'subscriptions' && $method === 'GET') {
    $user = getAuthUser();
    $db   = getDB();

    $stmt = $db->prepare("SELECT * FROM subscriptions WHERE user_id = ? ORDER BY amount DESC");
    $stmt->execute([$user['id']]);
    $subs = $stmt->fetchAll();

    $total = array_sum(array_column($subs, 'amount'));

    ok([
        "subscriptions" => $subs,
        "monthly_cost"  => $total,
        "annual_cost"   => $total * 12
    ]);
}

// ══════════════════════════════════════════════════════
// 8. DISPUTES
// GET /disputes
// Auth: Bearer token
// ══════════════════════════════════════════════════════
if ($uri === 'disputes' && $method === 'GET') {
    $user = getAuthUser();
    $db   = getDB();

    $stmt = $db->prepare("SELECT * FROM disputes WHERE user_id = ? ORDER BY filed_at DESC");
    $stmt->execute([$user['id']]);
    $disputes = $stmt->fetchAll();

    ok([
        "disputes" => $disputes,
        "count"    => count($disputes)
    ]);
}

// ══════════════════════════════════════════════════════
// 9. CASHBACK CLAIMS
// GET /cashback
// Auth: Bearer token
// ══════════════════════════════════════════════════════
if ($uri === 'cashback' && $method === 'GET') {
    $user = getAuthUser();
    $db   = getDB();

    $stmt = $db->prepare("SELECT * FROM cashback_claims WHERE user_id = ? ORDER BY claimed_at DESC");
    $stmt->execute([$user['id']]);
    $claims = $stmt->fetchAll();

    $total = array_sum(array_column($claims, 'amount'));

    ok([
        "claims"        => $claims,
        "total_pending" => $total
    ]);
}

// ══════════════════════════════════════════════════════
// 10. SWARMS
// GET /swarms
// Public — no auth needed
// ══════════════════════════════════════════════════════
if ($uri === 'swarms' && $method === 'GET') {
    $db   = getDB();
    $stmt = $db->query("SELECT * FROM swarm_clusters ORDER BY affected_users DESC");
    $swarms = $stmt->fetchAll();

    ok([
        "swarms" => $swarms,
        "count"  => count($swarms)
    ]);
}

// ══════════════════════════════════════════════════════
// 11. MONO WEBHOOK
// POST /webhook/mono
// ══════════════════════════════════════════════════════
if ($uri === 'webhook/mono' && $method === 'POST') {
    $payload   = file_get_contents('php://input');
    $signature = $_SERVER['HTTP_MONO_WEBHOOK_SECRET'] ?? '';

    if (MONO_WEBHOOK_SECRET && !Mono::verifyWebhook($payload, $signature)) {
        http_response_code(401);
        echo json_encode(["error" => "Invalid webhook signature"]);
        exit();
    }

    $event   = json_decode($payload, true);
    $ev_type = $event['event'] ?? '';
    $db      = getDB();

    if ($ev_type === 'mono.events.account_updated') {
        $mono_id = $event['data']['account']['id'] ?? null;

        if ($mono_id) {
            $acc_stmt = $db->prepare("SELECT * FROM accounts WHERE mono_account_id = ?");
            $acc_stmt->execute([$mono_id]);
            $account = $acc_stmt->fetch();

            if ($account) {
                $mono_txns    = Mono::getTransactions($mono_id, date('d-m-Y', strtotime('-7 days')), date('d-m-Y'));
                $transactions = $mono_txns['data'] ?? [];

                foreach ($transactions as $txn) {
                    $amount    = abs($txn['amount'] ?? 0) / 100;
                    $type_t    = ($txn['type']      ?? '') === 'debit' ? 'debit' : 'credit';
                    $date      = date('Y-m-d', strtotime($txn['date'] ?? 'now'));
                    $narration = $txn['narration']  ?? '';
                    $merchant  = self_extract_merchant($narration);

                    try {
                        $db->prepare("
                            INSERT INTO transactions
                            (user_id, account_id, mono_txn_id, merchant, amount, type, narration, txn_date)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ")->execute([
                            $account['user_id'],
                            $account['id'],
                            $txn['_id'] ?? '',
                            $merchant,
                            $amount,
                            $type_t,
                            $narration,
                            $date
                        ]);
                    } catch (PDOException $e) {
                        continue;
                    }
                }

                Scanner::scanUser($account['user_id']);
            }
        }
    }

    http_response_code(200);
    echo json_encode(["received" => true]);
    exit();
}

// ══════════════════════════════════════════════════════
// DEFAULT — API info
// ══════════════════════════════════════════════════════
ok([
    "name"      => "PARASITE API",
    "version"   => "1.0",
    "status"    => "running",
    "endpoints" => [
        "POST /register"      => "Create account",
        "POST /login"         => "Login and get token",
        "POST /link-account"  => "Link bank via Mono (auth required)",
        "POST /sync"          => "Fetch and scan transactions (auth required)",
        "GET  /dashboard"     => "Recovery summary (auth required)",
        "GET  /transactions"  => "All transactions (auth required)",
        "GET  /subscriptions" => "Detected subscriptions (auth required)",
        "GET  /disputes"      => "Filed disputes (auth required)",
        "GET  /cashback"      => "Cashback claims (auth required)",
        "GET  /swarms"        => "Active swarm clusters (public)",
        "POST /webhook/mono"  => "Mono webhook receiver"
    ]
]);

// ══════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ══════════════════════════════════════════════════════
function self_extract_merchant($narration) {
    $narration = strtoupper(trim($narration));
    $prefixes  = ['POS/', 'WEB/', 'NIP/', 'USSD/', 'MOB/', 'ATM/', 'POS ', 'TRF FROM ', 'TRF TO '];
    foreach ($prefixes as $prefix) {
        if (str_starts_with($narration, $prefix)) {
            $narration = substr($narration, strlen($prefix));
        }
    }
    $parts = preg_split('/[\s\/\-\|]+/', $narration);
    return trim($parts[0] ?? $narration);
}

function self_categorize($narration) {
    $n = strtoupper($narration);
    if (str_contains($n, 'NETFLIX')  || str_contains($n, 'SPOTIFY') ||
        str_contains($n, 'DSTV')     || str_contains($n, 'SHOWMAX'))  return 'streaming';
    if (str_contains($n, 'UBER')     || str_contains($n, 'BOLT')    ||
        str_contains($n, 'TAXIFY'))                                    return 'transport';
    if (str_contains($n, 'JUMIA')    || str_contains($n, 'KONGA'))    return 'shopping';
    if (str_contains($n, 'AIRTIME')  || str_contains($n, 'DATA')    ||
        str_contains($n, 'MTN')      || str_contains($n, 'AIRTEL'))   return 'telecom';
    if (str_contains($n, 'CHARGE')   || str_contains($n, 'FEE')     ||
        str_contains($n, 'MAINTENANCE'))                               return 'bank_fee';
    if (str_contains($n, 'TRANSFER') || str_contains($n, 'TRF'))      return 'transfer';
    if (str_contains($n, 'POS'))                                       return 'pos_payment';
    return 'other';
}
?>