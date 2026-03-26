<?php
// ── db.php — Database connection ──────────────────────
require_once 'config.php';

function getDB() {
    static $db = null;
    if ($db === null) {
        try {
            $db = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8",
                DB_USER, DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode([
                "success" => false,
                "error"   => "Database connection failed",
                "detail"  => $e->getMessage()
            ]));
        }
    }
    return $db;
}

// ── Quick response helpers ─────────────────────────────
function ok($data = []) {
    echo json_encode(array_merge(["success" => true], $data));
    exit();
}

function fail($message, $code = 400) {
    http_response_code($code);
    echo json_encode(["success" => false, "error" => $message]);
    exit();
}

// ── Get authenticated user from session token ──────────
function getAuthUser() {
    $headers = getallheaders();
    $token   = $headers['Authorization'] ?? '';
    $token   = str_replace('Bearer ', '', $token);

    // Also accept from POST/GET for simplicity
    if (!$token) $token = $_POST['token'] ?? $_GET['token'] ?? '';
    if (!$token) fail("Not authenticated — send token in Authorization header", 401);

    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE session_token = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) fail("Invalid or expired token", 401);
    return $user;
}
?>
