<?php
// public/admin/accounting_add.php
if (session_status() === PHP_SESSION_NONE) session_start();
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
                $after  = $before + $amount;

                $up = $mysqli->prepare("UPDATE users SET balance = ? WHERE id = ? LIMIT 1");
                $up->bind_param('di', $after, $target_id);
                if (!$up->execute()) throw new Exception('Ошибка обновления баланса: '.$up->error);
                $up->close();

                $ins = $mysqli->prepare("INSERT INTO accounting_transactions (user_id,type,amount,balance_before,balance_after,admin_id,note,status) VALUES (?,?,?,?,?,?,?,?)");
                $type = 'credit';
                $admin_id = (int)($user['id'] ?? 0);
                $status = 'completed';
                $ins->bind_param('isdddiss', $target_id, $type, $amount, $before, $after, $admin_id, $note, $status);
                if (!$ins->execute()) throw new Exception('Ошибка записи транзакции: '.$ins->error);
                $ins->close();

                $mysqli->commit();
                $success = "Пополнение выполнено. Новый баланс: " . number_format($after,2,'.',' ') . " TMT";
            } elseif (isset($pdo) && $pdo instanceof PDO) {
                $pdo->beginTransaction();
                $st = $pdo->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
                $st->execute([$target_id]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if (!$row) throw new Exception('Пользователь не найден');
                $before = (float)$row['balance'];
                $after = $before + $amount;
                $up = $pdo->prepare("UPDATE users SET balance = :b WHERE id = :id");
                $up->execute([':b'=>$after, ':id'=>$target_id]);
                $ins = $pdo->prepare("INSERT INTO accounting_transactions (user_id,type,amount,balance_before,balance_after,admin_id,note,status) VALUES (:uid,:type,:amt,:bb,:ba,:aid,:note,:status)");
                $ins->execute([':uid'=>$target_id, ':type'=>'credit', ':amt'=>$amount, ':bb'=>$before, ':ba'=>$after, ':aid'=>$user['id'] ?? null, ':note'=>$note, ':status'=>'completed']);
                $pdo->commit();
                $success = "Пополнение выполнено. Новый баланс: " . number_format($after,2,'.',' ') . " TMT";
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
<title>Добавить платёж — Admin</title>
<link rel="stylesheet" href="<?= esc($base ?? '/mehanik/public') ?>/assets/css/style.css">
<style>
.container{max-width:900px;margin:20px auto;padding:16px}
.form-row{margin-top:10px;display:flex;flex-direction:column;gap:6px}
input,select,textarea{padding:8px;border-radius:8px;border:1px solid #e6e9ef}
.btn{background:#0b57a4;color:#fff;padding:8px 12px;border-radius:8px;border:0}
.notice{padding:10px;border-radius:8px;margin-top:10px}
.notice.ok{background:#eafaf0;border:1px solid #cfead1;color:#116530}
.notice.err{background:#fff6f6;border:1px solid #f5c2c2;color:#8a1f1f}

/* search dropdown */
.search-wrap{position:relative}
.search-input{width:100%;padding:10px;border-radius:8px;border:1px solid #e6e9ef}
.results{position:absolute;left:0;right:0;top:100%;background:#fff;border:1px solid #e6e9ef;border-radius:8px;margin-top:6px;z-index:40;max-height:260px;overflow:auto;box-shadow:0 8px 30px rgba(2,6,23,0.06)}
.result-item{padding:8px 10px;border-bottom:1px solid #f1f3f5;cursor:pointer;display:flex;justify-content:space-between;align-items:center}
.result-item:last-child{border-bottom:none}
.result-item:hover, .result-item.active{background:#f6fbff}
.result-left{display:flex;flex-direction:column}
.result-id{font-weight:700;color:#0b57a4}
.result-sub{font-size:.95rem;color:#6b7280;margin-top:4px}
.user-card{margin-top:10px;padding:10px;border-radius:8px;background:#fbfdff;border:1px solid #eef3f7}
.small{color:#6b7280}

/* highlight */
mark { background: #fffd9a; color: #0b1720; padding:0 2px; border-radius:2px; }
</style>
</head>
<body>
<?php require_once __DIR__ . '/header.php'; ?>

<main class="container">
  <h1>Добавить платёж (пополнение)</h1>

  <?php if ($errors): ?><div class="notice err"><?= esc(implode('; ', $errors)) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="notice ok"><?= esc($success) ?></div><?php endif; ?>

  <form method="post" novalidate id="formAdd">
    <div class="form-row">
      <label>Пользователь (по ID / имени / телефону)</label>
      <div class="search-wrap">
        <input id="userSearch" class="search-input" type="search" placeholder="Введите #ID, имя или телефон... (подсказки появятся автоматически)">
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
      <button class="btn" type="submit">Выполнить пополнение</button>
    </div>
  </form>
</main>

<script>
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

  let debounceTimer = null;
  let activeIndex = -1;
  let currentItems = [];

  // utility: escape HTML
  function escHtml(s){
    return String(s).replace(/[&<>"']/g, function(m){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]; });
  }

  // highlight query inside text (case-insensitive) using <mark>
  function highlightText(text, q){
    if (!q) return escHtml(text);
    const qi = q.replace(/[#\s]/g,'').trim();
    // if query starts with # or is pure digits, we'll still try generic substring highlight first
    const re = new RegExp(q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&'), 'i');
    if (re.test(text)) {
      return escHtml(text).replace(new RegExp(q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&'), 'ig'), m => '<mark>'+escHtml(m)+'</mark>');
    }
    // fallback: case-insensitive search for characters sequence
    const qiSafe = q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');
    const re2 = new RegExp(qiSafe, 'i');
    if (re2.test(text)) {
      return escHtml(text).replace(re2, m => '<mark>'+escHtml(m)+'</mark>');
    }
    return escHtml(text);
  }

  // special highlight for phone when query contains digits: map digits positions
  function highlightPhone(phoneRaw, query){
    const onlyDigits = (query || '').replace(/\D+/g,'');
    if (!onlyDigits) return highlightText(phoneRaw, query);

    // build mapping from normalized index -> original index
    const orig = String(phoneRaw || '');
    let normalized = '';
    const map = []; // normalized idx -> original idx
    for (let i=0;i<orig.length;i++){
      const ch = orig[i];
      if (/\d/.test(ch)) {
        map.push(i);
        normalized += ch;
      }
    }
    const idx = normalized.indexOf(onlyDigits);
    if (idx === -1) return highlightText(phoneRaw, query);

    const startOrig = map[idx];
    const endOrig = map[idx + onlyDigits.length - 1];

    // produce highlighted HTML by iterating original string
    const before = escHtml(orig.slice(0, startOrig));
    const matched = escHtml(orig.slice(startOrig, endOrig+1));
    const after = escHtml(orig.slice(endOrig+1));
    return before + '<mark>' + matched + '</mark>' + after;
  }

  function clearResults(){ results.innerHTML=''; results.style.display='none'; currentItems = []; activeIndex = -1; }
  function openResults(){ if (results.children.length) { results.style.display='block'; } }
  function setActive(idx){
    const nodes = Array.from(results.querySelectorAll('.result-item'));
    nodes.forEach(n=>n.classList.remove('active'));
    if (idx >=0 && idx < nodes.length) {
      nodes[idx].classList.add('active');
      nodes[idx].scrollIntoView({block:'nearest'});
      activeIndex = idx;
    } else {
      activeIndex = -1;
    }
  }

  async function fetchSearch(q){
    if (!q || q.length < 1) { clearResults(); return; }
    try {
      const resp = await fetch('/mehanik/api/user_search.php?q=' + encodeURIComponent(q), { credentials: 'same-origin' });
      if (!resp.ok) { clearResults(); return; }
      const j = await resp.json();
      if (!j || !j.ok || !Array.isArray(j.items) || j.items.length === 0) { clearResults(); return; }
      renderResults(j.items, q);
    } catch(e) {
      console.error(e);
      clearResults();
    }
  }

  function renderResults(items, q){
    currentItems = items;
    results.innerHTML = '';
    items.forEach((it, idx) => {
      const div = document.createElement('div');
      div.className = 'result-item';
      div.tabIndex = 0;
      div.dataset.id = it.id;
      div.dataset.idx = idx;

      const left = document.createElement('div'); left.className = 'result-left';
      // highlight id, name and phone
      const idText = '#' + String(it.id || '');
      const nameText = String(it.name || '');
      const phoneText = String(it.phone || '');

      const idEl = document.createElement('div');
      idEl.className = 'result-id';
      idEl.innerHTML = highlightText(idText, q);

      const subEl = document.createElement('div');
      subEl.className = 'result-sub';
      // build combined subtitle: name · phone (highlighted)
      const nameHighlighted = highlightText(nameText, q);
      const phoneHighlighted = highlightPhone(phoneText, q);
      subEl.innerHTML = nameHighlighted + (phoneText ? ' · ' + phoneHighlighted : '');

      left.appendChild(idEl);
      left.appendChild(subEl);

      const right = document.createElement('div');
      right.className = 'small';
      right.textContent = (it.role ? it.role + ' · ' : '') + (it.status ? it.status : '');

      div.appendChild(left);
      div.appendChild(right);

      div.addEventListener('click', ()=> selectUser(it.id));
      div.addEventListener('keydown', (e)=> {
        if (e.key === 'Enter') selectUser(it.id);
        if (e.key === 'ArrowDown') {
          e.preventDefault();
          const next = Math.min(currentItems.length - 1, idx + 1);
          setActive(next);
        }
        if (e.key === 'ArrowUp') {
          e.preventDefault();
          const prev = Math.max(0, idx - 1);
          setActive(prev);
        }
      });

      results.appendChild(div);
    });
    openResults();
    // reset active
    setActive(0);
  }

  function selectUser(id){
    hid.value = id;
    clearResults();
    inEl.value = '';
    // load details
    fetch('/mehanik/api/user_get.php?id=' + encodeURIComponent(id), { credentials: 'same-origin' })
      .then(r => r.ok ? r.json() : Promise.reject(r))
      .then(j => {
        if (!j.ok || !j.user) { alert('Не удалось загрузить данные пользователя'); return; }
        const u = j.user;
        userCard.style.display = 'block';
        uTitle.innerHTML = '#' + escHtml(u.id) + ' — ' + escHtml(u.name || '-');
        uPhone.innerHTML = escHtml(u.phone || '-');
        uStatus.textContent = (u.status || '-');
        uRole.textContent = (u.role || '-');
        uBalance.textContent = Number(u.balance || 0).toFixed(2);
      }).catch(()=> alert('Ошибка при загрузке пользователя'));
  }

  // debounce wrapper
  function debounce(fn, wait){
    let t = null;
    return function(...args){
      if (t) clearTimeout(t);
      t = setTimeout(()=> { fn.apply(this, args); t = null; }, wait);
    };
  }

  const doSearchDebounced = debounce(function(q){
    if (!q || q.trim() === '') { clearResults(); return; }
    fetchSearch(q.trim());
  }, 250);

  inEl.addEventListener('input', function(e){
    const q = this.value;
    hid.value = '';
    userCard.style.display = 'none';
    doSearchDebounced(q);
  });

  // keyboard handling for main input: arrows + enter
  inEl.addEventListener('keydown', function(e){
    const nodes = Array.from(results.querySelectorAll('.result-item'));
    if (!nodes.length) return;
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      const next = (activeIndex + 1) < nodes.length ? activeIndex + 1 : 0;
      setActive(next);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      const prev = (activeIndex - 1) >= 0 ? activeIndex - 1 : nodes.length - 1;
      setActive(prev);
    } else if (e.key === 'Enter') {
      e.preventDefault();
      if (activeIndex >= 0 && nodes[activeIndex]) {
        const id = nodes[activeIndex].dataset.id;
        if (id) selectUser(id);
      }
    } else if (e.key === 'Escape') {
      clearResults();
    }
  });

  // click outside closes results
  document.addEventListener('click', function(e){
    if (!results.contains(e.target) && e.target !== inEl) clearResults();
  });

})();
</script>
</body>
</html>
