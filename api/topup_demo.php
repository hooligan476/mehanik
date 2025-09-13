<?php
// mehanik/api/topup_demo.php
// Demo top-up endpoint — требует авторизации (session).
// POST { amount: number } -> прибавляет к балансу и записывает транзакцию.
// WARNING: demo only — в продакшне замените на интеграцию с платёжным провайдером.

if (session_status() === PHP_SESSION_NONE) session_start();

// подключаем DB (mehanik/db.php)
$dbFile = __DIR__ . '/../db.php';
if (!file_exists($dbFile)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB config missing']);
    exit;
}
require_once $dbFile;

// простой JSON-ответ
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user']['id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$uid = (int)$_SESSION['user']['id'];

$amount = isset($_POST['amount']) ? (float)$_POST['amount'] : (float)($_GET['amount'] ?? 0);
if ($amount <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid amount']);
    exit;
}

try {
    // ensure $pdo exists
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'PDO not available']);
        exit;
    }

    $pdo->beginTransaction();

    // update user balance (atomic)
    $upd = $pdo->prepare("UPDATE users SET balance = balance + :amt WHERE id = :uid");
    $upd->execute([':amt' => $amount, ':uid' => $uid]);

    // insert transaction
    $ins = $pdo->prepare("INSERT INTO balance_transactions (user_id, amount, type, provider, provider_id, note)
                          VALUES (:uid, :amt, 'topup', 'demo', NULL, :note)");
    $ins->execute([':uid' => $uid, ':amt' => $amount, ':note' => 'Demo topup via topup_demo.php']);

    // fetch new balance
    $st = $pdo->prepare("SELECT balance FROM users WHERE id = :uid LIMIT 1");
    $st->execute([':uid' => $uid]);
    $new = $st->fetch(PDO::FETCH_ASSOC);
    $newBalance = $new ? (float)$new['balance'] : null;

    $pdo->commit();

    echo json_encode(['ok' => true, 'amount' => $amount, 'balance' => $newBalance]);
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB error: ' . $e->getMessage()]);
    exit;
}
