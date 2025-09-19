<?php
// public/admin/accounting.php
if (session_status() === PHP_SESSION_NONE) session_start();

// header.php должен находиться рядом (как у вас было) и подключать $mysqli/$pdo, а также определять $base
require_once __DIR__ . '/header.php';

$user = $_SESSION['user'] ?? null;
$role = strtolower((string)($user['role'] ?? ''));
$isSuperFlag = ((int)($user['is_superadmin'] ?? 0) === 1);
if (!$user || !($role === 'admin' || $role === 'superadmin' || $isSuperFlag)) {
    http_response_code(403);
    echo "Доступ запрещён.";
    exit;
}

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$q_user = trim($_GET['user'] ?? '');
$q_type = trim($_GET['type'] ?? '');
$from = trim($_GET['from'] ?? '');
$to   = trim($_GET['to'] ?? '');

$items = [];

try {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $sql = "SELECT t.*, u.name AS user_name, u.phone AS user_phone, a.name AS admin_name
                FROM accounting_transactions t
                LEFT JOIN users u ON u.id = t.user_id
                LEFT JOIN users a ON a.id = t.admin_id
                WHERE 1=1 ";
        $params = [];
        $types = '';

        if ($q_user !== '') {
            $sql .= " AND (t.user_id = ? OR u.name LIKE CONCAT('%',?,'%') OR u.phone LIKE CONCAT('%',?,'%')) ";
            $params[] = $q_user; $params[] = $q_user; $params[] = $q_user;
            $types .= 'iss';
        }
        if ($q_type === 'credit' || $q_type === 'debit') {
            $sql .= " AND t.type = ? "; $params[] = $q_type; $types .= 's';
        }
        if ($from !== '') {
            $sql .= " AND t.created_at >= ? "; $params[] = $from . ' 00:00:00'; $types .= 's';
        }
        if ($to !== '') {
            $sql .= " AND t.created_at <= ? "; $params[] = $to . ' 23:59:59'; $types .= 's';
        }
        $sql .= " ORDER BY t.created_at DESC LIMIT 200";

        $st = $mysqli->prepare($sql);
        if ($st) {
            if ($params) {
                // bind params dynamically
                $bindNames = [];
                $bindNames[] = $types ?: str_repeat('s', count($params));
                foreach ($params as $i => $p) {
                    $bindNames[] = &$params[$i];
                }
                call_user_func_array([$st, 'bind_param'], $bindNames);
            }
            $st->execute();
            $res = $st->get_result();
            while ($r = $res->fetch_assoc()) $items[] = $r;
            $st->close();
        }
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        $sql = "SELECT t.*, u.name AS user_name, u.phone AS user_phone, a.name AS admin_name
                FROM accounting_transactions t
                LEFT JOIN users u ON u.id = t.user_id
                LEFT JOIN users a ON a.id = t.admin_id
                WHERE 1=1 ";
        $params = [];
        if ($q_user !== '') {
            $sql .= " AND (t.user_id = :uid OR u.name LIKE :uq OR u.phone LIKE :uq) ";
            $params[':uid'] = $q_user; $params[':uq'] = "%{$q_user}%";
        }
        if ($q_type === 'credit' || $q_type === 'debit') {
            $sql .= " AND t.type = :type "; $params[':type'] = $q_type;
        }
        if ($from !== '') {
            $sql .= " AND t.created_at >= :from "; $params[':from'] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $sql .= " AND t.created_at <= :to "; $params[':to'] = $to . ' 23:59:59';
        }
        $sql .= " ORDER BY t.created_at DESC LIMIT 200";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $items = $st->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    // silent fail: $items stays empty
}

?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Бухгалтерия — Admin</title>
<link rel="stylesheet" href="<?= esc($base ?? '/mehanik/public') ?>/assets/css/style.css">
<style>
.container{max-width:1200px;margin:20px auto;padding:0 16px}
.controls{display:flex;gap:12px;align-items:center;margin-top:12px}
.table{background:#fff;border-radius:10px;padding:12px;box-shadow:0 4px 12px rgba(2,6,23,0.06);margin-top:12px}
.small{color:#6b7280}
.btn{background:#0b57a4;color:#fff;padding:8px 12px;border-radius:8px;text-decoration:none;font-weight:700}
.input{padding:8px;border-radius:8px;border:1px solid #e6e9ef}
</style>
</head>
<body>
<?php require_once __DIR__ . '/header.php'; ?>

<main class="container">
  <h1>Бухгалтерия</h1>

  <div class="controls">
    <a class="btn" href="<?= esc($base ?? '/mehanik/public') ?>/admin/accounting_add.php">➕ Добавить платёж</a>
    <a class="btn" href="<?= esc($base ?? '/mehanik/public') ?>/admin/accounting_charge.php">➖ Списать платёж</a>

    <form method="get" style="margin-left:auto;display:flex;gap:8px;align-items:center;">
      <input class="input" type="text" name="user" placeholder="ID / имя / телефон" value="<?= esc($q_user) ?>">
      <select name="type" class="input">
        <option value="">Все типы</option>
        <option value="credit" <?= $q_type==='credit' ? 'selected' : '' ?>>Пополнение</option>
        <option value="debit" <?= $q_type==='debit' ? 'selected' : '' ?>>Списание</option>
      </select>
      <input class="input" type="date" name="from" value="<?= esc($from) ?>">
      <input class="input" type="date" name="to" value="<?= esc($to) ?>">
      <button class="btn" type="submit">Применить</button>
    </form>
  </div>

  <section>
    <div class="table">
      <table style="width:100%;border-collapse:collapse;font-size:.95rem">
        <thead>
          <tr style="text-align:left;border-bottom:1px solid #eee">
            <th style="padding:8px;width:160px">Дата</th>
            <th style="padding:8px">Пользователь</th>
            <th style="padding:8px">Тип</th>
            <th style="padding:8px">Сумма</th>
            <th style="padding:8px">Баланс (до → после)</th>
            <th style="padding:8px">Админ</th>
            <th style="padding:8px">Примечание</th>
            <th style="padding:8px">Статус</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($items)): ?>
            <tr><td colspan="8" style="padding:16px;text-align:center;color:#6b7280">Транзакций не найдено</td></tr>
          <?php else: foreach ($items as $it): ?>
            <tr>
              <td style="padding:8px;vertical-align:top"><?= esc($it['created_at'] ?? $it['created']) ?></td>
              <td style="padding:8px;vertical-align:top">#<?= (int)($it['user_id'] ?? 0) ?> — <?= esc($it['user_name'] ?? ($it['user_phone'] ?? '-')) ?></td>
              <td style="padding:8px;vertical-align:top"><?= esc($it['type'] === 'credit' ? 'Пополнение' : 'Списание') ?></td>
              <td style="padding:8px;vertical-align:top"><?= number_format((float)($it['amount'] ?? 0),2,'.',' ') ?> TMT</td>
              <td style="padding:8px;vertical-align:top"><?= number_format((float)($it['balance_before'] ?? 0),2,'.',' ') ?> → <?= number_format((float)($it['balance_after'] ?? 0),2,'.',' ') ?></td>
              <td style="padding:8px;vertical-align:top"><?= esc($it['admin_name'] ?? '-') ?></td>
              <td style="padding:8px;vertical-align:top"><?= nl2br(esc($it['note'] ?? '')) ?></td>
              <td style="padding:8px;vertical-align:top"><?= esc($it['status'] ?? '') ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <p class="small" style="margin-top:12px">Показываются последние 200 транзакций. Для экспорта/доп.фильтров — можно добавить CSV/Excel.</p>
</main>
</body>
</html>
