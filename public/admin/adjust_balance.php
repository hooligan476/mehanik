<?php
// mehanik/public/admin/adjust_balance.php
session_start();
require_once __DIR__ . '/../../middleware.php';
require_admin(); // page visible to admin/superadmin

// only superadmin can change balances
$currentRole = $_SESSION['user']['role'] ?? '';
$isSuper = ($currentRole === 'superadmin') || (isset($_SESSION['user']['is_superadmin']) && (int)$_SESSION['user']['is_superadmin'] === 1);
if (!$isSuper) {
    header('Location: /mehanik/public/admin/users.php?err=' . urlencode('Требуется супер-админ'));
    exit;
}

require_once __DIR__ . '/../../db.php';
$basePublic = '/mehanik/public';

$uid = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if ($uid <= 0) {
    header('Location: ' . $basePublic . '/admin/users.php?err=' . urlencode('Нет user_id'));
    exit;
}

$msg = $err = '';

try {
    $st = $pdo->prepare("SELECT id,name,phone,role,balance FROM users WHERE id = ? LIMIT 1");
    $st->execute([$uid]);
    $user = $st->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        header('Location: ' . $basePublic . '/admin/users.php?err=' . urlencode('Пользователь не найден'));
        exit;
    }
} catch (Throwable $e) {
    header('Location: ' . $basePublic . '/admin/users.php?err=' . urlencode('DB error'));
    exit;
}

// POST: apply adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0.0;
    $note = trim((string)($_POST['note'] ?? ''));
    if ($amount <= 0) {
        $err = 'Некорректная сумма';
    } else {
        try {
            $pdo->beginTransaction();
            if ($action === 'set') {
                // set exact balance
                $update = $pdo->prepare("UPDATE users SET balance = :bal WHERE id = :uid");
                $update->execute([':bal' => $amount, ':uid' => $uid]);

                // record transaction as 'adjust' with note
                $ins = $pdo->prepare("INSERT INTO balance_transactions (user_id, amount, type, provider, provider_id, note) VALUES (:uid, :amt, 'adjust', 'admin', NULL, :note)");
                $ins->execute([':uid' => $uid, ':amt' => $amount, ':note' => 'Set by superadmin. ' . $note]);
            } else {
                // add/subtract: we use signed amount (button adds positive, subtract uses negative)
                // The form will send positive value and a mode add/sub
                $mode = $_POST['mode'] ?? 'add';
                $signed = ($mode === 'sub') ? -abs($amount) : abs($amount);

                $upd = $pdo->prepare("UPDATE users SET balance = balance + :delta WHERE id = :uid");
                $upd->execute([':delta' => $signed, ':uid' => $uid]);

                $ins = $pdo->prepare("INSERT INTO balance_transactions (user_id, amount, type, provider, provider_id, note) VALUES (:uid, :amt, 'adjust', 'admin', NULL, :note)");
                $ins->execute([':uid' => $uid, ':amt' => $signed, ':note' => 'Adjust by superadmin. ' . $note]);
            }
            $pdo->commit();
            header('Location: ' . $basePublic . '/admin/adjust_balance.php?user_id=' . $uid . '&msg=' . urlencode('Баланс обновлён'));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $err = 'DB error: ' . $e->getMessage();
        }
    }
}

// fetch recent transactions
$transactions = [];
try {
    $r = $pdo->prepare("SELECT * FROM balance_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
    $r->execute([$uid]);
    $transactions = $r->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // ignore
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Коррекция баланса — Пользователь #<?= htmlspecialchars($uid) ?></title>
<link rel="stylesheet" href="/mehanik/public/assets/css/style.css">
<style>
.container{max-width:900px;margin:18px auto;background:#fff;padding:16px;border-radius:10px;box-shadow:0 6px 18px rgba(2,6,23,0.04)}
.row{display:flex;gap:12px;align-items:center}
input[type=number]{padding:8px;border-radius:6px;border:1px solid #ddd}
.btn{padding:8px 12px;border-radius:8px;background:#0b57a4;color:#fff;border:0;cursor:pointer}
small.muted{color:#6b7280}
.tx-table{width:100%;border-collapse:collapse;margin-top:12px}
.tx-table th, .tx-table td{padding:8px;border-bottom:1px solid #eef2f6;text-align:left}
</style>
</head>
<body>
<?php require_once __DIR__ . '/header.php'; ?>

<div class="container">
  <a href="/mehanik/public/admin/users.php">← Назад к пользователям</a>
  <h2>Баланс — <?= htmlspecialchars($user['name'] ?? ('#'.$uid)) ?> (ID <?= $uid ?>)</h2>

  <?php if ($msg = ($_GET['msg'] ?? '')): ?>
    <div style="padding:10px;background:#eaf7ef;color:#116530;border-radius:8px;margin-bottom:10px"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div style="padding:10px;background:#fff6f6;color:#7a1f1f;border-radius:8px;margin-bottom:10px"><?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <p>Текущий баланс: <strong><?= htmlspecialchars(number_format((float)$user['balance'],2,'.',' ')) ?> TMT</strong></p>

  <section style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
    <form method="post" style="background:#fafafa;padding:12px;border-radius:8px">
      <h4>Установить баланс</h4>
      <input type="hidden" name="action" value="set">
      <div class="row" style="margin-top:8px">
        <input type="number" step="0.01" name="amount" placeholder="Сумма TMT" required>
        <button class="btn" type="submit">Установить</button>
      </div>
      <div style="margin-top:8px">
        <label>Примечание</label>
        <input type="text" name="note" placeholder="Причина (необязательно)" style="width:100%;padding:8px;border-radius:6px;border:1px solid #ddd">
      </div>
    </form>

    <form method="post" style="background:#fafafa;padding:12px;border-radius:8px">
      <h4>Прибавить / Списать</h4>
      <input type="hidden" name="action" value="adjust">
      <div class="row" style="margin-top:8px">
        <input type="number" step="0.01" name="amount" placeholder="Сумма TMT" required>
        <select name="mode" style="padding:8px;border-radius:6px;border:1px solid #ddd">
          <option value="add">Прибавить</option>
          <option value="sub">Списать</option>
        </select>
        <button class="btn" type="submit">Применить</button>
      </div>
      <div style="margin-top:8px">
        <label>Примечание</label>
        <input type="text" name="note" placeholder="Причина (необязательно)" style="width:100%;padding:8px;border-radius:6px;border:1px solid #ddd">
      </div>
    </form>
  </section>

  <h3 style="margin-top:18px">История транзакций (последние 50)</h3>
  <table class="tx-table" aria-live="polite">
    <thead>
      <tr><th>Дата</th><th>Сумма</th><th>Тип</th><th>Примечание</th></tr>
    </thead>
    <tbody>
      <?php if (!$transactions): ?>
        <tr><td colspan="4"><small class="muted">Транзакции не найдены.</small></td></tr>
      <?php else: foreach ($transactions as $t): ?>
        <tr>
          <td><?= htmlspecialchars(date('d.m.Y H:i', strtotime($t['created_at']))) ?></td>
          <td><?= htmlspecialchars(number_format((float)$t['amount'],2,'.',' ')) ?> TMT</td>
          <td><?= htmlspecialchars($t['type']) ?></td>
          <td><?= htmlspecialchars($t['note'] ?? '') ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
</body>
</html>
