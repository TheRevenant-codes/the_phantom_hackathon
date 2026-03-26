<?php
// ── scanner.php — The AI brain of PARASITE ────────────
// This reads real transactions from the database and:
// 1. Detects subscriptions
// 2. Flags overcharges
// 3. Finds cashback opportunities
// 4. Detects swarm patterns (same charge across many users)

require_once 'db.php';
require_once 'config.php';

class Scanner {

    // ── MAIN ENTRY POINT ──────────────────────────────
    // Call this after syncing transactions for a user.
    // It scans everything and returns a summary.
    public static function scanUser($user_id) {
        $db = getDB();

        // Get all unscanned transactions for this user
        $stmt = $db->prepare("
            SELECT * FROM transactions
            WHERE user_id = ? AND type = 'debit'
            ORDER BY txn_date DESC
        ");
        $stmt->execute([$user_id]);
        $transactions = $stmt->fetchAll();

        $results = [
            'subscriptions_found' => 0,
            'overcharges_found'   => 0,
            'cashback_found'      => 0,
            'swarms_found'        => 0,
            'total_recoverable'   => 0,
            'details'             => []
        ];

        foreach ($transactions as $txn) {
            // Check each transaction against all detection rules
            $found = self::detectSubscription($txn, $user_id);
            if ($found) { $results['subscriptions_found']++; $results['details'][] = $found; }

            $found = self::detectOvercharge($txn, $user_id);
            if ($found) { $results['overcharges_found']++; $results['details'][] = $found; $results['total_recoverable'] += $txn['amount']; }

            $found = self::detectCashback($txn, $user_id);
            if ($found) { $results['cashback_found']++; $results['details'][] = $found; }
        }

        // Check for swarm patterns (cross-user)
        $swarms = self::detectSwarms($user_id);
        $results['swarms_found'] = count($swarms);

        return $results;
    }

    // ── 1. SUBSCRIPTION DETECTOR ──────────────────────
    // Finds recurring charges from known streaming/service providers
    private static function detectSubscription($txn, $user_id) {
        $db        = getDB();
        $merchants = json_decode(SUBSCRIPTION_MERCHANTS, true);
        $narration = strtoupper($txn['narration'] ?? '');
        $merchant  = strtoupper($txn['merchant']  ?? '');

        $matched = null;
        foreach ($merchants as $sub_merchant) {
            if (str_contains($narration, $sub_merchant) || str_contains($merchant, $sub_merchant)) {
                $matched = $sub_merchant;
                break;
            }
        }
        if (!$matched) return null;

        // Check if we already know about this subscription
        $existing = $db->prepare("
            SELECT id FROM subscriptions
            WHERE user_id = ? AND merchant LIKE ?
        ");
        $existing->execute([$user_id, "%{$matched}%"]);

        if (!$existing->fetch()) {
            // New subscription found — save it
            $next_renewal = date('Y-m-d', strtotime('+1 month', strtotime($txn['txn_date'])));
            $db->prepare("
                INSERT INTO subscriptions
                (user_id, merchant, amount, currency, last_charged, next_renewal)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([
                $user_id, $matched, $txn['amount'],
                $txn['currency'], $txn['txn_date'], $next_renewal
            ]);

            // Flag the transaction
            self::flagTransaction($txn['id'], 'subscription',
                "Recurring subscription to {$matched} — ₦{$txn['amount']} detected");

            return [
                'type'     => 'subscription',
                'merchant' => $matched,
                'amount'   => $txn['amount'],
                'message'  => "Found subscription: {$matched} — ₦{$txn['amount']}/month"
            ];
        }
        return null;
    }

    // ── 2. OVERCHARGE DETECTOR ────────────────────────
    // Finds bank fees, suspicious deductions, and charges
    // that match known overcharge patterns
    private static function detectOvercharge($txn, $user_id) {
        $db       = getDB();
        $keywords = json_decode(OVERCHARGE_KEYWORDS, true);
        $narration = strtoupper($txn['narration'] ?? '');

        // Rule 1: Known fee keywords
        foreach ($keywords as $keyword) {
            if (str_contains($narration, $keyword)) {
                // Flag it
                self::flagTransaction($txn['id'], 'overcharge',
                    "Possible overcharge: '{$keyword}' detected in transaction");

                // Auto-create a dispute
                $db->prepare("
                    INSERT IGNORE INTO disputes
                    (user_id, transaction_id, merchant, amount, currency, reason, status)
                    VALUES (?, ?, ?, ?, ?, ?, 'filed')
                ")->execute([
                    $user_id, $txn['id'],
                    $txn['merchant'] ?? 'Unknown',
                    $txn['amount'], $txn['currency'],
                    "Possible overcharge detected: {$narration}"
                ]);

                return [
                    'type'    => 'overcharge',
                    'amount'  => $txn['amount'],
                    'reason'  => $keyword,
                    'message' => "Overcharge detected: {$keyword} — ₦{$txn['amount']} being disputed"
                ];
            }
        }

        // Rule 2: Suspiciously round amounts (e.g. exactly 50, 100, 200)
        // that appear multiple times from same merchant = likely systematic
        $amount = (float)$txn['amount'];
        if (in_array($amount, [50, 100, 200, 500]) && $txn['merchant']) {
            $count = $db->prepare("
                SELECT COUNT(*) as n FROM transactions
                WHERE user_id = ? AND merchant = ? AND amount = ?
            ");
            $count->execute([$user_id, $txn['merchant'], $amount]);
            if ((int)$count->fetch()['n'] > 2) {
                self::flagTransaction($txn['id'], 'overcharge',
                    "Repeated charge of ₦{$amount} from {$txn['merchant']}");
                return [
                    'type'    => 'overcharge',
                    'amount'  => $amount,
                    'message' => "Repeated fee of ₦{$amount} from {$txn['merchant']}"
                ];
            }
        }

        return null;
    }

    // ── 3. CASHBACK DETECTOR ──────────────────────────
    // Finds transactions from merchants that offer cashback
    // and creates a claim if one hasn't been made already
    private static function detectCashback($txn, $user_id) {
        $db        = getDB();
        $merchants = json_decode(CASHBACK_MERCHANTS, true);
        $narration = strtoupper($txn['narration'] ?? '');
        $merchant  = strtoupper($txn['merchant']  ?? '');

        foreach ($merchants as $cb_merchant) {
            if (str_contains($narration, $cb_merchant) || str_contains($merchant, $cb_merchant)) {
                // Standard cashback is 1-5% — use 2% as conservative estimate
                $cashback_amount = round($txn['amount'] * 0.02, 2);

                // Don't duplicate
                $existing = $db->prepare("
                    SELECT id FROM cashback_claims
                    WHERE user_id = ? AND transaction_id = ?
                ");
                $existing->execute([$user_id, $txn['id']]);
                if ($existing->fetch()) return null;

                // Create the claim
                $db->prepare("
                    INSERT INTO cashback_claims
                    (user_id, transaction_id, merchant, amount, currency, claim_type)
                    VALUES (?, ?, ?, ?, ?, 'cashback')
                ")->execute([
                    $user_id, $txn['id'], $cb_merchant,
                    $cashback_amount, $txn['currency']
                ]);

                return [
                    'type'     => 'cashback',
                    'merchant' => $cb_merchant,
                    'amount'   => $cashback_amount,
                    'message'  => "Cashback claim filed: ₦{$cashback_amount} from {$cb_merchant}"
                ];
            }
        }
        return null;
    }

    // ── 4. SWARM DETECTOR ─────────────────────────────
    // Looks for the same charge pattern across multiple users
    // When 100+ users have the same overcharge → create/join swarm
    private static function detectSwarms($user_id) {
        $db = getDB();

        // Find this user's flagged transactions
        $flagged = $db->prepare("
            SELECT merchant, amount, flag_reason
            FROM transactions
            WHERE user_id = ? AND flagged = 1 AND flag_type = 'overcharge'
        ");
        $flagged->execute([$user_id]);
        $flagged_txns = $flagged->fetchAll();

        $swarms_joined = [];

        foreach ($flagged_txns as $txn) {
            if (!$txn['merchant']) continue;

            // Count how many OTHER users have same merchant + similar amount
            $count_stmt = $db->prepare("
                SELECT COUNT(DISTINCT user_id) as affected
                FROM transactions
                WHERE merchant = ?
                  AND amount BETWEEN ? AND ?
                  AND flagged = 1
                  AND user_id != ?
            ");
            $count_stmt->execute([
                $txn['merchant'],
                $txn['amount'] * 0.9,  // within 10% of same amount
                $txn['amount'] * 1.1,
                $user_id
            ]);
            $affected = (int)$count_stmt->fetch()['affected'];

            // Threshold: 5 users in sandbox (100 in production)
            $threshold = 5;

            if ($affected >= $threshold) {
                // Check if swarm cluster already exists
                $existing_swarm = $db->prepare("
                    SELECT id FROM swarm_clusters
                    WHERE merchant = ? AND status IN ('building','filing')
                ");
                $existing_swarm->execute([$txn['merchant']]);
                $swarm = $existing_swarm->fetch();

                if ($swarm) {
                    // Update existing swarm count
                    $db->prepare("
                        UPDATE swarm_clusters
                        SET affected_users = affected_users + 1,
                            total_amount   = total_amount + ?
                        WHERE id = ?
                    ")->execute([$txn['amount'], $swarm['id']]);

                    // Link this user's dispute to the swarm
                    $db->prepare("
                        UPDATE disputes SET swarm_id = ?
                        WHERE user_id = ? AND merchant = ?
                    ")->execute([$swarm['id'], $user_id, $txn['merchant']]);

                } else {
                    // Create new swarm cluster
                    $db->prepare("
                        INSERT INTO swarm_clusters
                        (merchant, pattern_type, amount_each, affected_users, total_amount, status)
                        VALUES (?, 'repeated_fee', ?, ?, ?, 'building')
                    ")->execute([
                        $txn['merchant'],
                        $txn['amount'],
                        $affected + 1,
                        $txn['amount'] * ($affected + 1)
                    ]);
                    $swarm_id = $db->lastInsertId();

                    // Link this user's dispute
                    $db->prepare("
                        UPDATE disputes SET swarm_id = ?
                        WHERE user_id = ? AND merchant = ?
                    ")->execute([$swarm_id, $user_id, $txn['merchant']]);

                    $swarms_joined[] = [
                        'merchant'       => $txn['merchant'],
                        'affected_users' => $affected + 1,
                        'total_amount'   => $txn['amount'] * ($affected + 1)
                    ];
                }
            }
        }

        return $swarms_joined;
    }

    // ── HELPER: Flag a transaction ────────────────────
    private static function flagTransaction($txn_id, $type, $reason) {
        $db = getDB();
        $db->prepare("
            UPDATE transactions
            SET flagged = 1, flag_type = ?, flag_reason = ?
            WHERE id = ?
        ")->execute([$type, $reason, $txn_id]);
    }

    // ── CALCULATE TOTAL RECOVERABLE FOR USER ──────────
    public static function getTotalRecoverable($user_id) {
        $db = getDB();

        $disputes = $db->prepare("
            SELECT COALESCE(SUM(amount),0) as total
            FROM disputes WHERE user_id = ? AND status IN ('filed','pending')
        ");
        $disputes->execute([$user_id]);
        $dispute_total = (float)$disputes->fetch()['total'];

        $cashback = $db->prepare("
            SELECT COALESCE(SUM(amount),0) as total
            FROM cashback_claims WHERE user_id = ? AND status = 'pending'
        ");
        $cashback->execute([$user_id]);
        $cashback_total = (float)$cashback->fetch()['total'];

        $recovered = $db->prepare("
            SELECT COALESCE(SUM(net_to_user),0) as total
            FROM recoveries WHERE user_id = ?
        ");
        $recovered->execute([$user_id]);
        $recovered_total = (float)$recovered->fetch()['total'];

        return [
            'disputes_pending'  => $dispute_total,
            'cashback_pending'  => $cashback_total,
            'total_pending'     => $dispute_total + $cashback_total,
            'already_recovered' => $recovered_total,
            'platform_fee'      => round($recovered_total * PLATFORM_FEE_PERCENT / 100, 2),
            'net_to_user'       => round($recovered_total * (100 - PLATFORM_FEE_PERCENT) / 100, 2)
        ];
    }
}
?>
