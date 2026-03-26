<?php
// ── mono.php — Mono API wrapper ───────────────────────
require_once 'config.php';

class Mono {

    // ── Exchange auth code for account ID ─────────────
    // After user connects bank in the Mono widget,
    // the widget gives you a "code". Exchange it here
    // for the permanent account ID.
    public static function exchangeCode($code) {
        $response = self::post('/account/auth', [
            'code' => $code
        ]);
        return $response;
        // Returns: { "id": "60b8e1b0e4b0f0001a6e4b0f" }
    }

    // ── Get account details ───────────────────────────
    // Returns bank name, account number, balance etc.
    public static function getAccount($mono_account_id) {
        return self::get("/accounts/{$mono_account_id}");
        // Returns: { "account": { "name", "accountNumber", "balance", ... } }
    }

    // ── Get transactions ──────────────────────────────
    // Fetches real transactions from the linked account.
    // $start and $end are dates like "01-01-2024"
    public static function getTransactions($mono_account_id, $start = null, $end = null, $paginate = true) {
        $params = ['paginate' => $paginate ? 'true' : 'false'];
        if ($start) $params['start'] = $start;
        if ($end)   $params['end']   = $end;

        $query = http_build_query($params);
        return self::get("/accounts/{$mono_account_id}/transactions?{$query}");
        // Returns: { "data": [ { "_id", "amount", "date", "narration", "type", ... } ] }
    }

    // ── Get account balance (real time) ───────────────
    public static function getBalance($mono_account_id) {
        return self::get("/accounts/{$mono_account_id}/balance");
    }

    // ── Verify webhook signature ───────────────────────
    // Call this in your webhook endpoint to confirm
    // the request genuinely came from Mono
    public static function verifyWebhook($payload, $signature) {
        $expected = hash_hmac('sha512', $payload, MONO_WEBHOOK_SECRET);
        return hash_equals($expected, $signature);
    }

    // ── Internal: GET request to Mono ─────────────────
    private static function get($endpoint) {
        $ch = curl_init(MONO_API_URL . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'mono-sec-key: ' . MONO_SECRET_KEY,
                'Content-Type: application/json'
            ]
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);
        if ($httpCode >= 400) {
            return ['error' => $data['message'] ?? 'Mono API error', 'code' => $httpCode];
        }
        return $data;
    }

    // ── Internal: POST request to Mono ────────────────
    private static function post($endpoint, $body) {
        $ch = curl_init(MONO_API_URL . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => [
                'mono-sec-key: ' . MONO_SECRET_KEY,
                'Content-Type: application/json'
            ]
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);
        if ($httpCode >= 400) {
            return ['error' => $data['message'] ?? 'Mono API error', 'code' => $httpCode];
        }
        return $data;
    }
}
?>
