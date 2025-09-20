<?php
// mehanik/public/admin/accounting.php
if (session_status() === PHP_SESSION_NONE) session_start();

// header.php должен подключать DB ($mysqli или $pdo) и определять $base
require_once __DIR__ . '/header.php';

$user = $_SESSION['user'] ?? null;
$role = strtolower((string)($user['role'] ?? ''));
$isSuperFlag = ((int)($user['is_superadmin'] ?? 0) === 1);
if (!$user || !($role === 'admin' || $role === 'superadmin' || $isSuperFlag)) {
    http_response_code(403);
    echo "Доступ запрещён.";
    exit;
}

$debug = (isset($_GET['debug']) && $_GET['debug'] == '1');

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function sortLink(string $col, string $title): string {
    $qs = $_GET;
    $currentSort = isset($qs['sort']) ? (string)$qs['sort'] : '';
    $currentDir  = isset($qs['dir']) ? strtolower((string)$qs['dir']) : 'desc';
    $newDir = 'desc';
    $arrow = '';
    if ($currentSort === $col) {
        $newDir = ($currentDir === 'asc') ? 'desc' : 'asc';
        $arrow = $currentDir === 'asc' ? ' ▲' : ' ▼';
    } else {
        $newDir = 'asc';
    }
    $qs['sort'] = $col;
    $qs['dir']  = $newDir;
    $url = $_SERVER['PHP_SELF'] . '?' . http_build_query($qs);
    return '<a class="sort-link" href="' . esc($url) . '">' . esc($title) . esc($arrow) . '</a>';
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
.sort-link, .sort-link:visited { color: inherit; text-decoration: none; font-weight:700; }
.sort-link:hover { text-decoration: underline; }
.debug { background:#111827;color:#fff;padding:10px;border-radius:8px;margin-top:12px; white-space:pre-wrap; font-family: monospace; font-size:13px; }
.user-link { color:#0b57a4; text-decoration: none; font-weight:700; }
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
      <input class="input" type="text" name="user" placeholder="ID / имя / телефон" value="<?= esc($_GET['user'] ?? '') ?>">
      <select name="type" class="input">
        <option value="">Все типы</option>
        <option value="credit" <?= (($_GET['type'] ?? '')==='credit') ? 'selected' : '' ?>>Пополнение</option>
        <option value="debit" <?= (($_GET['type'] ?? '')==='debit') ? 'selected' : '' ?>>Списание</option>
      </select>
      <input class="input" type="date" name="from" value="<?= esc($_GET['from'] ?? '') ?>">
      <input class="input" type="date" name="to" value="<?= esc($_GET['to'] ?? '') ?>">
      <label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" name="debug" value="1" <?= $debug ? 'checked' : '' ?>> Debug</label>
      <button class="btn" type="submit">Применить</button>
    </form>
  </div>

<?php
$q_user = trim($_GET['user'] ?? '');
$q_type = trim($_GET['type'] ?? '');
$from = trim($_GET['from'] ?? '');
$to   = trim($_GET['to'] ?? '');

$items = [];
$info = [];
$lastQuery = '';
$lastParams = [];
$lastError = '';

$dbEngine = 'none';
if (isset($mysqli) && $mysqli instanceof mysqli) $dbEngine = 'mysqli';
elseif (isset($pdo) && $pdo instanceof PDO) $dbEngine = 'pdo';
$info[] = "DB engine: {$dbEngine}";

try {
    // check table
    $hasAccounting = false;
    if ($dbEngine === 'mysqli') {
        $r = $mysqli->query("SHOW TABLES LIKE 'accounting_transactions'");
        $hasAccounting = ($r && $r->num_rows > 0);
        $info[] = $hasAccounting ? "accounting_transactions: FOUND" : "accounting_transactions: NOT FOUND";
    } elseif ($dbEngine === 'pdo') {
        $st = $pdo->query("SHOW TABLES LIKE 'accounting_transactions'"); $hasAccounting = (bool)$st->fetchColumn();
        $info[] = $hasAccounting ? "accounting_transactions: FOUND" : "accounting_transactions: NOT FOUND";
    } else {
        $info[] = "Нет подключенной БД (проверьте header.php).";
    }

    if ($hasAccounting) {
        // allowed sort columns (добавлен executor)
        $allowedSort = ['id','user_id','type','amount','balance_before','balance_after','admin_id','executor','status','created_at'];
        $sort = trim($_GET['sort'] ?? 'created_at');
        $dir  = strtolower(trim($_GET['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        if (!in_array($sort, $allowedSort, true)) $sort = 'created_at';

        // строим SELECT с поддержкой executor как id или как строка
        // executor_raw - оригинальное значение из t.executor
        // executor_display - приоритет: имя исполнителя из users (e.name) -> t.executor (если строка) -> admin_name
        $baseSelect = "t.*, t.executor AS executor_raw, u.name AS user_name, u.phone AS user_phone, a.name AS admin_name, e.name AS executor_name";
        $fromClause = "FROM accounting_transactions t
                    LEFT JOIN users u ON u.id = t.user_id
                    LEFT JOIN users a ON a.id = t.admin_id
                    LEFT JOIN users e ON e.id = t.executor";

        $sql = "SELECT {$baseSelect} {$fromClause} WHERE 1=1";
        $params = [];

        if ($q_user !== '') {
            if (ctype_digit($q_user)) {
                $sql .= " AND (t.user_id = ? OR u.name LIKE CONCAT('%',?,'%') OR u.phone LIKE CONCAT('%',?,'%'))";
                $params[] = $q_user; $params[] = $q_user; $params[] = $q_user;
            } else {
                $sql .= " AND (u.name LIKE CONCAT('%',?,'%') OR u.phone LIKE CONCAT('%',?,'%'))";
                $params[] = $q_user; $params[] = $q_user;
            }
        }
        if ($q_type !== '' ) { $sql .= " AND t.type = ?"; $params[] = $q_type; }
        if ($from !== '') { $sql .= " AND t.created_at >= ?"; $params[] = $from . ' 00:00:00'; }
        if ($to   !== '') { $sql .= " AND t.created_at <= ?"; $params[] = $to . ' 23:59:59'; }

        // Если сортировка по executor — используем выражение, иначе обычный ORDER BY t.<col>
        if ($sort === 'executor') {
            // используем ту же логику, что и для вывода: сначала e.name, затем t.executor, затем a.name
            $orderExpr = "COALESCE(e.name, NULLIF(t.executor, ''), a.name)";
            $sql .= " ORDER BY {$orderExpr} {$dir} LIMIT 200";
        } else {
            // защитим имя колонки (разрешённые перечислены выше)
            $sql .= " ORDER BY t." . $sort . " " . $dir . " LIMIT 200";
        }

        $lastQuery = $sql; $lastParams = $params;

        if ($dbEngine === 'mysqli') {
            $st = $mysqli->prepare($sql);
            if ($st === false) {
                if (empty($params)) {
                    $res = $mysqli->query($sql);
                    if ($res) while ($r = $res->fetch_assoc()) $items[] = $r;
                    else $lastError = $mysqli->error;
                } else {
                    $lastError = 'Prepare failed: ' . $mysqli->error;
                }
            } else {
                if (!empty($params)) {
                    // простая логика типов: целые как i, остальное как s
                    $types = '';
                    foreach ($params as $p) {
                        $types .= (is_numeric($p) && (string)(int)$p === (string)$p) ? 'i' : 's';
                    }
                    $bind = [$types];
                    foreach ($params as $i => $v) $bind[] = &$params[$i];
                    call_user_func_array([$st, 'bind_param'], $bind);
                }
                if (!$st->execute()) { $lastError = $st->error; }
                else {
                    $res = $st->get_result();
                    while ($r = $res->fetch_assoc()) $items[] = $r;
                }
                $st->close();
            }
        } elseif ($dbEngine === 'pdo') {
            $st = $pdo->prepare($sql);
            if (!$st->execute($params)) {
                $lastError = implode(' | ', $st->errorInfo());
            } else $items = $st->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Throwable $e) {
    $lastError = $e->getMessage();
}
?>

  <section>
    <div class="table">
      <table style="width:100%;border-collapse:collapse;font-size:.95rem">
        <thead>
          <tr style="text-align:left;border-bottom:1px solid #eee">
            <th style="padding:8px;width:160px"><?= sortLink('created_at','Дата') ?></th>
            <th style="padding:8px">Пользователь</th>
            <th style="padding:8px"><?= sortLink('type','Тип') ?></th>
            <th style="padding:8px"><?= sortLink('amount','Сумма') ?></th>
            <th style="padding:8px">Баланс (до → после)</th>
            <th style="padding:8px"><?= sortLink('executor','Исполнитель') ?></th>
            <th style="padding:8px">Примечание</th>
            <th style="padding:8px"><?= sortLink('status','Статус') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($items)): ?>
            <tr><td colspan="8" style="padding:16px;text-align:center;color:#6b7280">Транзакций не найдено</td></tr>
          <?php else: foreach ($items as $it): ?>
            <tr>
              <td style="padding:8px;vertical-align:top"><?= esc($it['created_at'] ?? '') ?></td>
              <td style="padding:8px;vertical-align:top">
                #<?= (int)($it['user_id'] ?? 0) ?> —
                <?php if (!empty($it['user_name'])): ?>
                  <a class="user-link" href="<?= esc($base ?? '/mehanik/public') ?>/admin/users.php?user_id=<?= (int)$it['user_id'] ?>"><?= esc($it['user_name']) ?></a>
                <?php else: ?>
                  <?= esc($it['user_phone'] ?? '-') ?>
                <?php endif; ?>
              </td>
              <td style="padding:8px;vertical-align:top"><?= esc(($it['type'] ?? '') === 'credit' ? 'Пополнение' : (($it['type'] ?? '') === 'debit' ? 'Списание' : ($it['type'] ?? '-'))) ?></td>
              <td style="padding:8px;vertical-align:top"><?= number_format((float)($it['amount'] ?? 0),2,'.',' ') ?> TMT</td>
              <td style="padding:8px;vertical-align:top">
                <?= number_format((float)($it['balance_before'] ?? 0),2,'.',' ') ?> → <?= number_format((float)($it['balance_after'] ?? 0),2,'.',' ') ?>
              </td>
              <td style="padding:8px;vertical-align:top">
                <?php
                  // Приоритет вывода: name из e (если был JOIN по id) -> если executor_raw непустой — вывести его -> admin_name -> fallback #admin_id
                  if (!empty($it['executor_name'])) {
                      echo esc($it['executor_name']);
                  } elseif (!empty($it['executor_raw'])) {
                      echo esc($it['executor_raw']);
                  } elseif (!empty($it['admin_name'])) {
                      echo esc($it['admin_name']);
                  } else {
                      echo esc('#' . ((int)($it['admin_id'] ?? 0)));
                  }
                ?>
              </td>
              <td style="padding:8px;vertical-align:top"><?= nl2br(esc($it['note'] ?? '')) ?></td>
              <td style="padding:8px;vertical-align:top"><?= esc($it['status'] ?? '-') ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </section>

<?php if ($debug): ?>
  <div class="debug">
DIAGNOSTICS:
<?php foreach ($info as $line) echo esc($line) . "\n"; ?>
Using table: accounting_transactions
Last SQL:
<?= esc($lastQuery) . "\n\n" ?>
Last Params:
<?= esc(var_export($lastParams, true)) . "\n\n" ?>
Last Error:
<?= esc($lastError ?: 'none') . "\n\n" ?>
Items fetched: <?= esc((string)count($items)) . "\n" ?>
<?php if (count($items) > 0): ?>
First item: <?= esc(var_export(array_slice($items,0,1), true)) . "\n" ?>
<?php endif; ?>
  </div>
<?php endif; ?>

  <p class="small" style="margin-top:12px">Показываются последние 200 транзакций.</p>
</main>
</body>
</html>
