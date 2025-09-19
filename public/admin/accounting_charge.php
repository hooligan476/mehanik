<?php
// mehanik/public/admin/accounting_charge.php
if (session_status() === PHP_SESSION_NONE) session_start();

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/db.php';
require_once __DIR__ . '/header.php';

$user = $_SESSION['user'] ?? null;
$role = strtolower((string)($user['role'] ?? ''));
$isSuperFlag = ((int)($user['is_superadmin'] ?? 0) === 1);
if (!$user || !($role === 'admin' || $role === 'superadmin' || $isSuperFlag)) {
    http_response_code(403); echo "Доступ запрещён."; exit;
}

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $target_id = (int)($_POST['user_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $note = trim($_POST['note'] ?? '');

    if ($target_id <= 0) $errors[] = 'Выберите пользователя';
    if ($amount <= 0) $errors[] = 'Сумма должна быть больше 0';

    if (empty($errors)) {
        try {
            if (isset($mysqli) && $mysqli instanceof mysqli) {
                $mysqli->begin_transaction();
                $st = $mysqli->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
                $st->bind_param('i', $target_id);
                $st->execute();
                $res = $st->get_result();
                $row = $res->fetch_assoc();
                $st->close();
                if (!$row) throw new Exception('Пользователь не найден');

                $before = (float)($row['balance'] ?? 0.0);
                if ($before < $amount) throw new Exception('Недостаточно средств на балансе пользователя');

                $after  = $before - $amount;

                $up = $mysqli->prepare("UPDATE users SET balance = ? WHERE id = ? LIMIT 1");
                $up->bind_param('di', $after, $target_id);
                if (!$up->execute()) throw new Exception('Ошибка обновления баланса: '.$up->error);
                $up->close();

                $ins = $mysqli->prepare("INSERT INTO accounting_transactions (user_id,type,amount,balance_before,balance_after,admin_id,note,status,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())");
                $type = 'debit';
                $admin_id = (int)($user['id'] ?? 0);
                $status = 'completed';
                $ins->bind_param('isdddiss', $target_id, $type, $amount, $before, $after, $admin_id, $note, $status);
                if (!$ins->execute()) throw new Exception('Ошибка записи транзакции: '.$ins->error);
                $ins->close();

                $mysqli->commit();
                $success = "Списание выполнено. Новый баланс: " . number_format($after,2,'.',' ') . " TMT";
            } elseif (isset($pdo) && $pdo instanceof PDO) {
                $pdo->beginTransaction();
                $st = $pdo->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
                $st->execute([$target_id]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if (!$row) throw new Exception('Пользователь не найден');
                $before = (float)$row['balance'];
                if ($before < $amount) throw new Exception('Недостаточно средств на балансе пользователя');
                $after = $before - $amount;
                $up = $pdo->prepare("UPDATE users SET balance = :b WHERE id = :id");
                $up->execute([':b'=>$after, ':id'=>$target_id]);
                $ins = $pdo->prepare("INSERT INTO accounting_transactions (user_id,type,amount,balance_before,balance_after,admin_id,note,status,created_at) VALUES (:uid,:type,:amt,:bb,:ba,:aid,:note,:status,NOW())");
                $ins->execute([':uid'=>$target_id, ':type'=>'debit', ':amt'=>$amount, ':bb'=>$before, ':ba'=>$after, ':aid'=>$user['id'] ?? null, ':note'=>$note, ':status'=>'completed']);
                $pdo->commit();
                $success = "Списание выполнено. Новый баланс: " . number_format($after,2,'.',' ') . " TMT";
            } else {
                $errors[] = 'Нет подключения к БД';
            }
        } catch (Throwable $e) {
            if (isset($mysqli) && $mysqli instanceof mysqli) $mysqli->rollback();
            if (isset($pdo) && $pdo instanceof PDO) $pdo->rollBack();
            $errors[] = 'Ошибка: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Списать платёж — Admin</title>
<link rel="stylesheet" href="<?= esc('/mehanik/public') ?>/assets/css/style.css">
<style>
.container{max-width:900px;margin:20px auto;padding:16px}
.form-row{margin-top:10px;display:flex;flex-direction:column;gap:6px}
input,select,textarea{padding:8px;border-radius:8px;border:1px solid #e6e9ef}
.btn{background:#0b57a4;color:#fff;padding:8px 12px;border-radius:8px;border:0}
.notice{padding:10px;border-radius:8px;margin-top:10px}
.notice.ok{background:#eafaf0;border:1px solid #cfead1;color:#116530}
.notice.err{background:#fff6f6;border:1px solid #f5c2c2;color:#8a1f1f}

/* reuse search styles */
.search-wrap{position:relative}
.search-input{width:100%;padding:10px;border-radius:8px;border:1px solid #e6e9ef}
.results{position:absolute;left:0;right:0;top:100%;background:#fff;border:1px solid #e6e9ef;border-radius:8px;margin-top:6px;z-index:40;max-height:260px;overflow:auto;box-shadow:0 8px 30px rgba(2,6,23,0.06)}
.result-item{padding:8px 10px;border-bottom:1px solid #f1f3f5;cursor:pointer}
.result-item:last-child{border-bottom:none}
.result-item:hover{background:#f6fbff}
.result-item strong{background:#fff7cc;padding:0 2px;border-radius:3px}
.user-card{margin-top:10px;padding:10px;border-radius:8px;background:#fbfdff;border:1px solid #eef3f7}
.small{color:#6b7280}
</style>
</head>
<body>
<?php require_once __DIR__ . '/header.php'; ?>

<main class="container">
  <h1>Списать платёж (charge)</h1>

  <?php if ($errors): ?><div class="notice err"><?= esc(implode('; ', $errors)) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="notice ok"><?= esc($success) ?></div><?php endif; ?>

  <form method="post" novalidate id="formCharge">
    <div class="form-row">
      <label>Пользователь (по ID / имени / телефону)</label>
      <div class="search-wrap">
        <input id="userSearch" class="search-input" type="search" placeholder="Введите #ID, имя или телефон...">
        <div id="results" class="results" style="display:none" role="listbox" aria-label="Результаты поиска"></div>
      </div>
      <input type="hidden" name="user_id" id="user_id">
      <div id="userCard" class="user-card" style="display:none">
        <div><strong id="uTitle"># — —</strong></div>
        <div class="small" id="uPhone"></div>
        <div style="margin-top:6px"><strong>Статус:</strong> <span id="uStatus"></span> · <strong>Роль:</strong> <span id="uRole"></span></div>
        <div style="margin-top:6px"><strong>Баланс:</strong> <span id="uBalance"></span> TMT</div>
      </div>
    </div>

    <div class="form-row">
      <label>Сумма (TMT)</label>
      <input type="number" name="amount" step="0.01" min="0.01" required>
    </div>

    <div class="form-row">
      <label>Примечание</label>
      <textarea name="note" rows="3"></textarea>
    </div>

    <div style="margin-top:12px">
      <a class="btn" href="accounting.php" style="background:transparent;border:1px solid #e6eef7;color:#0b57a4;padding:8px 12px;border-radius:8px;text-decoration:none">Отмена</a>
      <button class="btn" type="submit">Выполнить списание</button>
    </div>
  </form>
</main>

<script>
// Reuse the same JS as in accounting_add for debounce + highlight
(function(){
  const inEl = document.getElementById('userSearch');
  const results = document.getElementById('results');
  const hid = document.getElementById('user_id');
  const userCard = document.getElementById('userCard');
  const uTitle = document.getElementById('uTitle');
  const uPhone = document.getElementById('uPhone');
  const uStatus = document.getElementById('uStatus');
  const uRole = document.getElementById('uRole');
  const uBalance = document.getElementById('uBalance');

  let timer = null;
  let lastQuery = '';

  const searchPath = '/mehanik/api/user_search.php';
  const getPath    = '/mehanik/api/user_get.php';

  function clearResults(){ results.innerHTML=''; results.style.display='none'; }
  function escapeHtml(s){ return String(s||'').replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":"&#39;"}[c])); }
  function escapeRegExp(s){ return String(s||'').replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

  function highlightLabel(label, q){
    if (!q) return escapeHtml(label);
    const re = new RegExp(escapeRegExp(q), 'ig');
    return escapeHtml(label).replace(re, m => '<strong>'+escapeHtml(m)+'</strong>');
  }

  async function fetchSearch(q){
    if (!q || q.length < 1) { clearResults(); return; }
    try {
      const resp = await fetch(searchPath + '?q=' + encodeURIComponent(q), { credentials: 'same-origin' });
      if (!resp.ok) { clearResults(); return; }
      const j = await resp.json().catch(()=>null);
      if (!j || !j.ok || !Array.isArray(j.items)) { clearResults(); return; }
      renderResults(j.items, q);
    } catch (err) {
      console.error('user_search error', err);
      clearResults();
    }
  }

  function renderResults(items, q){
    results.innerHTML = '';
    if (!items.length) { clearResults(); return; }
    for (const it of items) {
      const div = document.createElement('div');
      div.className = 'result-item';
      div.tabIndex = 0;
      const label = '#' + (it.id||'-') + ' — ' + (it.name||'-') + (it.phone ? ' · ' + it.phone : '');
      div.innerHTML = highlightLabel(label, q);
      div.dataset.id = it.id;
      div.addEventListener('click', ()=> selectUser(it.id));
      div.addEventListener('keydown', (e)=> { if (e.key === 'Enter') selectUser(it.id); });
      results.appendChild(div);
    }
    results.style.display = 'block';
  }

  function selectUser(id){
    hid.value = id;
    clearResults();
    inEl.value = '';
    (async ()=>{
      try {
        const resp = await fetch(getPath + '?id=' + encodeURIComponent(id), { credentials: 'same-origin' });
        if (!resp.ok) { alert('Ошибка загрузки пользователя: ' + resp.status); return; }
        const j = await resp.json().catch(()=>null);
        if (!j || !j.ok || !j.user) { alert('Пользователь не найден'); return; }
        const u = j.user;
        userCard.style.display = 'block';
        uTitle.textContent = '#' + u.id + ' — ' + (u.name || '-');
        uPhone.textContent = (u.phone || '-');
        uStatus.textContent = (u.status || '-');
        uRole.textContent = (u.role || '-');
        uBalance.textContent = Number(u.balance || 0).toFixed(2);
      } catch (err) {
        console.error('user_get error', err);
        alert('Ошибка при загрузке пользователя (см. консоль).');
      }
    })();
  }

  inEl.addEventListener('input', function(){
    const q = this.value.trim();
    hid.value = '';
    userCard.style.display = 'none';
    if (timer) clearTimeout(timer);
    timer = setTimeout(()=> {
      if (q === lastQuery) return;
      lastQuery = q;
      fetchSearch(q);
    }, 250);
  });

  document.addEventListener('click', function(e){
    if (!results.contains(e.target) && e.target !== inEl) clearResults();
  });
})();
</script>
</body>
</html>
