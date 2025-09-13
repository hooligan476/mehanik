<?php
// public/header.php — header с балансом и пополнением (демо)
// Обновлён: использует middleware.php для старта сессии, обновления last_seen,
// централизованной проверки session_version и refresh_session_user().

$projectRoot = dirname(__DIR__);

// Попытаемся включить middleware — он стартует сессию и подгружает DB (если есть)
$middlewarePath = $projectRoot . '/middleware.php';
if (file_exists($middlewarePath)) {
    require_once $middlewarePath;
} else {
    // Если middleware отсутствует, делаем минимальный старт сессии и подключаем db.php
    if (session_status() === PHP_SESSION_NONE) session_start();
    $dbPath = $projectRoot . '/db.php';
    if (file_exists($dbPath)) {
        require_once $dbPath;
    }
}

// load config (fallback)
$configPath = $projectRoot . '/config.php';
$config = ['base_url' => '/mehanik/public'];
if (file_exists($configPath)) {
    $cfg = require $configPath;
    if (is_array($cfg)) $config = array_merge($config, $cfg);
}
$base = rtrim($config['base_url'] ?? '/mehanik/public', '/');

// Если middleware предоставляет refresh_session_user(), вызовем его, чтобы гарантированно подтянуть balance/session_version
if (function_exists('refresh_session_user')) {
    try { refresh_session_user(); } catch (Throwable $e) { /* ignore */ }
}

// enforce_session_version уже вызывается автоматически в middleware.php (если он подключён).
// Если middleware не подключён, попробуем вызвать явную проверку, если функция доступна.
if (function_exists('enforce_session_version')) {
    try { enforce_session_version(); } catch (Throwable $e) { /* ignore */ }
}

// получим данные пользователя из сессии
$user = $_SESSION['user'] ?? null;
$uid = !empty($user['id']) ? (int)$user['id'] : 0;

// Если по какой-то причине в сессии нет balance / is_superadmin / role — попробуем подгрузить вручную через $mysqli/$pdo
if ($uid && (!isset($user['role']) || !array_key_exists('balance', $user) || !isset($user['is_superadmin']))) {
    // если в сессии нет нужных полей и middleware не сделал работу, подтянем минимально нужные поля
    $dbPath = $projectRoot . '/db.php';
    if (file_exists($dbPath) && !function_exists('refresh_session_user')) {
        try {
            require_once $dbPath;
            if (isset($mysqli) && $mysqli instanceof mysqli) {
                $sql = "SELECT id,name,phone,role,created_at,verify_code,status,ip,
                               COALESCE(is_superadmin,0) AS is_superadmin,
                               COALESCE(balance,0.00) AS balance
                        FROM users WHERE id = ? LIMIT 1";
                if ($st = $mysqli->prepare($sql)) {
                    $st->bind_param('i', $uid);
                    $st->execute();
                    $res = $st->get_result();
                    $fresh = $res ? $res->fetch_assoc() : null;
                    $st->close();
                    if ($fresh) {
                        $fresh['is_superadmin'] = (int)($fresh['is_superadmin'] ?? 0);
                        $fresh['balance'] = (float)($fresh['balance'] ?? 0.0);
                        $_SESSION['user'] = array_merge((array)$_SESSION['user'], (array)$fresh);
                        $user = $_SESSION['user'];
                    }
                }
            } elseif (isset($pdo) && $pdo instanceof PDO) {
                $sql = "SELECT id,name,phone,role,created_at,verify_code,status,ip,
                               COALESCE(is_superadmin,0) AS is_superadmin,
                               COALESCE(balance,0.00) AS balance
                        FROM users WHERE id = :id LIMIT 1";
                $st = $pdo->prepare($sql);
                $st->execute([':id' => $uid]);
                $fresh = $st->fetch(PDO::FETCH_ASSOC);
                if ($fresh) {
                    $fresh['is_superadmin'] = (int)($fresh['is_superadmin'] ?? 0);
                    $fresh['balance'] = (float)($fresh['balance'] ?? 0.0);
                    $_SESSION['user'] = array_merge((array)$_SESSION['user'], (array)$fresh);
                    $user = $_SESSION['user'];
                }
            }
        } catch (Throwable $e) {
            // ignore — header must not fail on DB problems
        }
    }
}

// fallback admin phone
if (!defined('ADMIN_PHONE_FOR_VERIFY')) define('ADMIN_PHONE_FOR_VERIFY', '+99363722023');

// compute visibility of admin panel link
$isAdminPanelVisible = false;
if (!empty($user)) {
    $role = strtolower((string)($user['role'] ?? ''));
    $isSuperFlag = ((int)($user['is_superadmin'] ?? 0) === 1);
    $cfgSuperId = isset($config['superadmin_id']) ? (int)$config['superadmin_id'] : 0;
    if ($role === 'admin' || $role === 'superadmin' || $isSuperFlag || ($cfgSuperId && $uid === $cfgSuperId)) {
        $isAdminPanelVisible = true;
    }
}

// css
$cssPath = htmlspecialchars($base . '/assets/css/header.css', ENT_QUOTES, 'UTF-8');
?>
<link rel="stylesheet" href="<?= $cssPath ?>">

<style>
/* lightweight local tweaks to ensure centered nav and responsive movement */
.topbar { background: #0f1724; color: #fff; border-bottom:1px solid rgba(255,255,255,0.03); }
.topbar .wrap { display:flex; align-items:center; gap:12px; max-width:1200px; margin:0 auto; padding:10px 12px; box-sizing:border-box; }
.brand { font-weight:700; font-size:1.15rem; color:#fff; text-decoration:none; }
.nav-center { flex:1; display:flex; justify-content:center; align-items:center; gap:10px; flex-wrap:wrap; }
.nav-center a { color:#e6eef7; text-decoration:none; padding:6px 10px; border-radius:6px; font-weight:600; }
.nav-center a:hover { background: rgba(255,255,255,0.03); color:#fff; }
.user-block { margin-left:12px; text-align:right; display:flex; flex-direction:column; gap:4px; align-items:flex-end; }
.balance { font-weight:700; color:#f0f9ff; }
.topup-btn { margin-top:6px; padding:6px 10px; border-radius:8px; background:#0b57a4; color:#fff; text-decoration:none; border:0; cursor:pointer; font-weight:700; }
.topup-btn:disabled { opacity:0.6; cursor:not-allowed; }

/* modal */
#topupModal { position:fixed; inset:0; display:none; background:rgba(2,6,23,0.6); align-items:center; justify-content:center; z-index:9999; }
#topupModal .m { background:#fff; color:#111; padding:18px; border-radius:10px; min-width:320px; max-width:420px; box-shadow:0 10px 30px rgba(2,6,23,0.3); }
#topupModal .m h3 { margin:0 0 8px 0; }
#topupModal .m .row { display:flex; gap:8px; margin-top:8px; }
#topupModal .m button { padding:8px 12px; border-radius:8px; cursor:pointer; border:0; }

/* small screens: stack properly */
@media (max-width:900px) {
  .nav-center { justify-content:flex-start; flex: 1 1 auto; }
  .user-block { align-items:flex-end; }
}
</style>

<header class="topbar" role="banner">
  <div class="wrap">
    <div style="display:flex;align-items:center;gap:12px;">
      <a class="brand" href="<?= htmlspecialchars($base . '/index.php') ?>">Mehanik</a>
    </div>

    <nav class="nav-center" aria-label="Главная навигация">
      <?php if (!empty($user)): // показываем ссылки на Сервисы/Услуги и Авто только авторизованным ?>
        <a href="<?= htmlspecialchars($base . '/services.php') ?>">Сервисы/Услуги</a>
        <a href="<?= htmlspecialchars($base . '/my-cars.php') ?>">Авто</a>
        <a href="<?= htmlspecialchars($base . '/my-products.php') ?>">Запчасти</a>
        <a href="<?= htmlspecialchars($base . '/chat.php') ?>">Техподдержка чат</a>

        <?php if ($isAdminPanelVisible): ?>
          <a href="<?= htmlspecialchars($base . '/admin/index.php') ?>">Админка</a>
        <?php endif; ?>

        <a href="<?= htmlspecialchars($base . '/logout.php') ?>">Выйти</a>
      <?php else: ?>
        <a href="<?= htmlspecialchars($base . '/login.php') ?>">Войти</a>
        <a href="<?= htmlspecialchars($base . '/register.php') ?>">Регистрация</a>
      <?php endif; ?>
    </nav>

    <div class="user-block" aria-live="polite">
      <?php if (!empty($user)): ?>
        <?php $status = $user['status'] ?? 'pending'; ?>
        <?php if ($status === 'pending'): ?>
          <div style="font-weight:700;">ОЖИДАНИЕ ПОДТВЕРЖДЕНИЯ</div>
          <div style="font-size:.9rem;">Отправьте SMS с номера <strong><?= htmlspecialchars($user['phone']) ?></strong> код <strong><?= htmlspecialchars($user['verify_code'] ?? '-') ?></strong></div>
          <div style="font-size:.85rem;margin-top:6px;">на номер <strong><?= ADMIN_PHONE_FOR_VERIFY ?></strong></div>
        <?php else: ?>
          <div style="font-weight:700;"><?= htmlspecialchars($user['name'] ?? $user['phone']) ?></div>
          <div style="font-size:.85rem;color:#e6f2ff;"><?= htmlspecialchars($user['phone'] ?? '') ?></div>

          <div style="display:flex;gap:8px;align-items:center;margin-top:6px;">
            <div class="balance"><?= number_format((float)($user['balance'] ?? 0.0), 2, '.', ' ') ?> TMT</div>
            <button id="topupBtn" class="topup-btn" type="button">Пополнить</button>
          </div>

          <?php if (!empty($user['is_superadmin']) && (int)$user['is_superadmin'] === 1): ?>
            <div style="font-size:.75rem;color:#ffd9a8;margin-top:6px;">Superadmin</div>
          <?php endif; ?>
        <?php endif; ?>
      <?php else: ?>
        <div>Гость</div>
      <?php endif; ?>
    </div>
  </div>
</header>

<!-- Top-up modal (demo) -->
<div id="topupModal" role="dialog" aria-modal="true" aria-hidden="true">
  <div class="m" role="document">
    <h3>Пополнить баланс (демо)</h3>
    <p>Это тестовый пополнятор баланса. В продакшне замените на платёжный шлюз.</p>

    <label>Сумма (TMT)</label>
    <input id="topupAmount" type="number" step="0.01" min="0" value="50" style="width:100%;padding:8px;border-radius:6px;border:1px solid #ddd;margin-top:6px;">

    <div class="row" style="justify-content:flex-end;">
      <button id="topupClose" style="background:#f3f4f6;">Отмена</button>
      <button id="topupDemoConfirm" style="background:#0b57a4;color:#fff;">Пополнить (демо)</button>
    </div>
  </div>
</div>

<script>
(function(){
  const topupBtn = document.getElementById('topupBtn');
  const modal = document.getElementById('topupModal');
  const closeBtn = document.getElementById('topupClose');
  const confirmBtn = document.getElementById('topupDemoConfirm');
  const amountInput = document.getElementById('topupAmount');

  function showModal() {
    if (!modal) return;
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
  }
  function hideModal() {
    if (!modal) return;
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
  }

  if (topupBtn && modal) {
    topupBtn.addEventListener('click', showModal);
    if (closeBtn) closeBtn.addEventListener('click', hideModal);

    // close on backdrop click
    modal.addEventListener('click', function(e){
      if (e.target === modal) hideModal();
    });

    // handle demo confirm
    if (confirmBtn) {
      confirmBtn.addEventListener('click', async function(){
        const amount = parseFloat(amountInput.value || '0');
        if (!amount || amount <= 0) {
          alert('Введите корректную сумму.');
          return;
        }

        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Обработка...';

        try {
          const form = new FormData();
          form.append('amount', String(amount));

          const res = await fetch('<?= htmlspecialchars($base, ENT_QUOTES) ?>/api/topup_demo.php', {
            method: 'POST',
            credentials: 'same-origin',
            body: form
          });
          const data = await res.json();
          if (data && data.ok) {
            // обновим баланс в хедере
            const balanceEl = document.querySelector('.balance');
            if (balanceEl && typeof data.balance !== 'undefined') {
              balanceEl.textContent = Number(data.balance).toFixed(2) + ' TMT';
            }
            alert('Баланс успешно пополнен на ' + Number(data.amount).toFixed(2) + ' TMT (демо).');
          } else {
            alert('Ошибка: ' + (data && data.error ? data.error : 'неизвестная'));
          }
        } catch (err) {
          console.error(err);
          alert('Сетевая ошибка при пополнении. Смотрите консоль.');
        } finally {
          confirmBtn.disabled = false;
          confirmBtn.textContent = 'Пополнить (демо)';
          hideModal();
        }
      });
    }
  }

  // accessibility: ESC closes modal
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') {
      hideModal();
    }
  });
})();
</script>
