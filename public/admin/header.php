<?php
// public/admin/header.php
// Хедер админки — считает pending'и, подтягивает баланс и обновляет сессию пользователя.
// Содержит логику инвалидации сессии через поле users.session_version.

if (session_status() === PHP_SESSION_NONE) session_start();

$projectRoot = dirname(__DIR__); // mehanik/public -> dirname -> mehanik
$configPath = $projectRoot . '/config.php';
$dbPath     = $projectRoot . '/db.php';

// Загрузка конфига (если есть)
if (file_exists($configPath)) {
    $cfg = require $configPath;
    if (is_array($cfg)) $config = $cfg + (isset($config) ? $config : []);
}
if (!isset($config)) $config = ['base_url' => '/mehanik/public'];

// base URL (используется при редиректах и формированиях ссылок)
$base = rtrim($config['base_url'] ?? '/mehanik/public', '/');

// Подключаем DB (db.php должен инициализировать $mysqli или $pdo)
if (file_exists($dbPath)) {
    require_once $dbPath;
}

// Если пользователь залогинен — подтягиваем свежие данные + проверяем session_version
if (!empty($_SESSION['user']['id'])) {
    $uid = (int)$_SESSION['user']['id'];
    try {
        $fresh = null;
        if (isset($mysqli) && $mysqli instanceof mysqli) {
            $sql = "SELECT id,name,phone,role,created_at,verify_code,status,ip,
                           COALESCE(is_superadmin,0) AS is_superadmin,
                           COALESCE(balance,0.00) AS balance,
                           COALESCE(session_version,0) AS session_version
                    FROM users WHERE id = ? LIMIT 1";
            if ($st = $mysqli->prepare($sql)) {
                $st->bind_param('i', $uid);
                $st->execute();
                $res = $st->get_result();
                $fresh = $res ? $res->fetch_assoc() : null;
                $st->close();
            }
        } elseif (isset($pdo) && $pdo instanceof PDO) {
            $sql = "SELECT id,name,phone,role,created_at,verify_code,status,ip,
                           COALESCE(is_superadmin,0) AS is_superadmin,
                           COALESCE(balance,0.00) AS balance,
                           COALESCE(session_version,0) AS session_version
                    FROM users WHERE id = :id LIMIT 1";
            $st = $pdo->prepare($sql);
            $st->execute([':id' => $uid]);
            $fresh = $st->fetch(PDO::FETCH_ASSOC);
        }

        if ($fresh) {
            // приводим типы
            $fresh['is_superadmin'] = (int)($fresh['is_superadmin'] ?? 0);
            $fresh['balance'] = isset($fresh['balance']) ? (float)$fresh['balance'] : 0.0;
            $fresh['session_version'] = isset($fresh['session_version']) ? (int)$fresh['session_version'] : 0;

            // версия в сессии (если была)
            $sessVersionInSession = isset($_SESSION['user']['session_version']) ? (int)$_SESSION['user']['session_version'] : null;
            $dbSessionVersion = $fresh['session_version'];

            if ($sessVersionInSession !== null && $dbSessionVersion !== $sessVersionInSession) {
                // session_version поменялся — аккуратно разлогиниваем пользователя
                $_SESSION = [];
                if (ini_get("session.use_cookies")) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000,
                        $params["path"], $params["domain"],
                        $params["secure"], $params["httponly"]
                    );
                }
                session_destroy();

                // Редиректим на логин (с параметром reason для дебага/UX)
                header('Location: ' . $base . '/login.php?reason=session_invalidated');
                exit;
            }

            // Сливаем свежие поля в сессию (если в сессии не было session_version — записываем её)
            $_SESSION['user'] = array_merge((array)$_SESSION['user'], (array)$fresh);
            if ($sessVersionInSession === null) {
                $_SESSION['user']['session_version'] = $dbSessionVersion;
            }
        }
    } catch (Throwable $e) {
        // не прерываем работу хедера из-за ошибок БД
        // лучше логировать, но здесь — silent fail
    }
}

$base = rtrim($config['base_url'] ?? '/mehanik/public', '/'); // на всякий случай
$user = $_SESSION['user'] ?? null;

// подсчёт pending для пользователей/запчастей/сервисов/авто
$pendingUsers = $pendingProducts = $pendingServices = $pendingCars = 0;
try {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $res = $mysqli->query("SELECT COUNT(*) AS c FROM users WHERE status='pending'");
        if ($res) $pendingUsers = (int)($res->fetch_assoc()['c'] ?? 0);

        $res = $mysqli->query("SELECT COUNT(*) AS c FROM products WHERE status!='approved'");
        if ($res) $pendingProducts = (int)($res->fetch_assoc()['c'] ?? 0);

        $res = $mysqli->query("SELECT COUNT(*) AS c FROM services WHERE status='pending'");
        if ($res) $pendingServices = (int)($res->fetch_assoc()['c'] ?? 0);

        $res = $mysqli->query("SELECT COUNT(*) AS c FROM cars WHERE status!='approved'");
        if ($res) $pendingCars = (int)($res->fetch_assoc()['c'] ?? 0);
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        $pendingUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='pending'")->fetchColumn();
        $pendingProducts = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE status!='approved'")->fetchColumn();
        $pendingServices = (int)$pdo->query("SELECT COUNT(*) FROM services WHERE status='pending'")->fetchColumn();
        $pendingCars = (int)$pdo->query("SELECT COUNT(*) FROM cars WHERE status!='approved'")->fetchColumn();
    }
} catch (Throwable $e) {
    // ignore and leave zeros
}

// текущий путь (без query string) для выделения активного пункта
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '/';

// helper: возвращает 'active' если $link ведёт на текущий путь (совпадение или startsWith)
function isActiveLink(string $link, string $currentPath, bool $strict = false): bool {
    $linkPath = parse_url($link, PHP_URL_PATH) ?: $link;
    $lp = rtrim($linkPath, '/');
    $cp = rtrim($currentPath, '/');

    if ($lp === '') $lp = '/';
    if ($cp === '') $cp = '/';

    if ($strict) return $lp === $cp;
    if ($lp === $cp) return true;
    if ($lp !== '/' && strpos($cp, $lp) === 0) return true;
    return false;
}
?>
<style>
/* header занимает всю ширину, внутри — контейнер wrap с отступами */
.admin-top { background:#0f1724; color:#fff; padding:10px 0; box-shadow:0 2px 6px rgba(0,0,0,.08); border-bottom: 1px solid rgba(255,255,255,0.02); }
.admin-top .wrap{ max-width:1200px; margin:0 auto; padding:0 16px; box-sizing:border-box; display:flex; gap:12px; align-items:center; }

/* левый блок (бренд + навигация) растягивается, чтобы навигация могла центрироваться */
.admin-top .wrap > div:first-child { display:flex; align-items:center; gap:8px; flex:1 1 auto; min-width:0; }

/* бренд */
.admin-top .brand { font-weight:700; font-size:1.05rem; color:#fff; text-decoration:none; margin-right:8px; }

/* навигация по центру — теперь занимает доступную ширину и при сжатии переносится на следующую строку (без скролла) */
.nav-admin {
  display:flex;
  gap:10px;
  align-items:center;
  margin-left:8px;
  flex:1 1 auto;
  min-width:0;              /* важно, чтобы flex child мог ужиматься */
  flex-wrap:wrap;           /* перенос элементов на новую строку при нехватке места */
  padding-bottom:2px;
  /* убрали overflow-x, чтобы не показывался скролл */
}

/* ссылки навигации */
.nav-admin a {
  color:#e6eef7;
  text-decoration:none;
  padding:6px 10px;
  border-radius:8px;
  font-weight:600;
  font-size:0.95rem;
  display:inline-flex;
  align-items:center;
  white-space:nowrap; /* предотвращаем перенос внутри одной ссылки */
}
.nav-admin a:hover { background: rgba(255,255,255,0.03); color:#fff; }

/* активный пункт */
.nav-admin a.active { background: linear-gradient(180deg,#164e9a,#0b57a4); color:#fff; box-shadow: 0 6px 18px rgba(11,87,164,0.12); }

/* btn-catalog теперь не навязывает цвет по умолчанию,
   он служит только для специального оформления активного состояния */
.btn-catalog { color:#e6eef7; padding:6px 10px; border-radius:8px; text-decoration:none; font-weight:700; }
.btn-catalog:hover { background: rgba(255,255,255,0.03); color:#fff; }
.btn-catalog.active { background: linear-gradient(180deg,#184f96,#0b57a4); box-shadow:0 6px 14px rgba(11,87,164,0.12); color:#fff; }

.badge{ display:inline-block; background:#ef4444; color:#fff; padding:2px 7px; border-radius:999px; margin-left:6px; font-weight:700; font-size:.8rem; vertical-align:middle; }

/* правая часть (информация о текущем админ-пользователе) */
.header-right { margin-left:auto; text-align:right; display:flex; flex-direction:column; gap:6px; align-items:flex-end; min-width:0; }
.header-right .name { font-weight:700; color:#fff; }
.header-right .sub { font-size:.85rem; color:#9ca3af; }
.header-actions { margin-top:6px; display:flex; gap:8px; align-items:center; flex-wrap:nowrap; }

/* баланс и кнопка пополнить */
.balance { font-weight:800; color:#f0f9ff; background:transparent; padding:6px 10px; border-radius:8px; }

/* topup button */
.topup-btn { background:#0b57a4; color:#fff; padding:6px 10px; border-radius:8px; font-weight:700; text-decoration:none; border:0; cursor:pointer; }
.topup-btn[disabled] { opacity:0.6; cursor:not-allowed; }

/* links inside header-actions */
.header-actions a { color:#dbeafe; text-decoration:none; padding:6px 10px; border-radius:8px; background:transparent; border:1px solid rgba(219,234,254,0.06); font-weight:600; }
.header-actions a.logout { background:transparent; border:1px solid rgba(255,255,255,0.03); color:#ffdede; }

/* compact buttons style (used in nav too) */
.header-actions .small-btn {
  background: transparent;
  border: 1px solid rgba(219,234,254,0.06);
  color: #dbeafe;
  padding: 6px 10px;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
}
.header-actions .small-btn:hover { background: rgba(255,255,255,0.03); color:#fff; }

/* --- FIX: модалка скрыта по умолчанию и показывается только при aria-hidden="false" --- */
#adminTopupModal {
  position: fixed;
  inset: 0;
  display: none !important;
  visibility: hidden;
  background: rgba(2,6,23,0.6);
  align-items: center;
  justify-content: center;
  z-index: 9999;
}
#adminTopupModal[aria-hidden="false"] {
  display: flex !important;
  visibility: visible;
}
#adminTopupModal .m { background:#fff; color:#111; padding:18px; border-radius:10px; min-width:320px; max-width:520px; box-shadow:0 10px 30px rgba(2,6,23,0.3); }
#adminTopupModal .m h3 { margin:0 0 8px 0; }
#adminTopupModal .m .row { display:flex; gap:8px; margin-top:8px; }
#adminTopupModal .m button { padding:8px 12px; border-radius:8px; cursor:pointer; border:0; }

/* --- Супер-компактный режим: уменьшаем paddings / font-size на узких экранах --- */
@media (max-width: 800px) {
  .admin-top .wrap { padding: 8px 12px; gap:8px; }
  .admin-top .brand { font-size: 0.98rem; }
  .nav-admin a { padding:5px 8px; font-size:0.88rem; }
  .nav-admin { gap:8px; }
  .header-actions { gap:6px; }
  .balance { padding:4px 8px; font-size:0.95rem; }
  .topup-btn { padding:5px 8px; font-size:0.92rem; }
}

@media (max-width: 480px) {
  .admin-top .brand { font-size: 0.92rem; }
  .nav-admin a { padding:4px 7px; font-size:0.82rem; border-radius:6px; }
  .nav-admin { gap:6px; }
  .header-actions a, .header-actions .small-btn, .topup-btn { padding:4px 7px; font-size:0.82rem; border-radius:6px; }
  .balance { padding:3px 6px; font-size:0.85rem; }
  /* уменьшение высоты модалки на очень узких экранах */
  #adminTopupModal .m { min-width:260px; max-width:380px; padding:12px; }
  #adminTopupModal .m button { padding:6px 10px; }
}

/* mobile: столбцы */
@media (max-width:900px) {
  .admin-top .wrap { flex-direction:column; align-items:stretch; gap:8px; }
  .admin-top .wrap > div:first-child { flex:unset; }
  .header-right { align-items:flex-start; }
}
</style>

<header class="admin-top" role="banner">
  <div class="wrap">
    <div>
      <a class="brand" href="<?= htmlspecialchars($base . '/admin/index.php') ?>">Mehanik — Admin</a>

      <nav class="nav-admin" aria-label="Админ навигация">
        <?php
          $links = [
            ['href' => $base . '/admin/users.php', 'label' => 'Пользователи', 'badge' => $pendingUsers, 'class' => ''],
            ['href' => $base . '/admin/services.php', 'label' => 'Сервисы/Услуги', 'badge' => $pendingServices, 'class' => ''],
            ['href' => $base . '/admin/products.php', 'label' => 'Запчасти', 'badge' => $pendingProducts, 'class' => ''],
            ['href' => $base . '/admin/chats.php', 'label' => 'Чаты', 'badge' => 0, 'class' => ''],
            ['href' => $base . '/admin/cars.php', 'label' => 'Бренд/Модель', 'badge' => 0, 'class' => ''],
            ['href' => $base . '/admin/cars_moderation.php', 'label' => 'Авто', 'badge' => $pendingCars, 'class' => 'btn-catalog'],

            // Уведомления и Бухгалтерия как обычные nav-элементы
            ['href' => $base . '/admin/notifications.php', 'label' => 'Уведомления', 'badge' => 0, 'class' => ''],
            ['href' => $base . '/admin/accounting.php', 'label' => 'Бухгалтерия', 'badge' => 0, 'class' => ''],

            ['href' => $base . '/index.php', 'label' => 'Открыть сайт', 'badge' => 0, 'class' => '']
          ];

          foreach ($links as $ln) {
              $href = $ln['href'];
              $label = $ln['label'];
              $badge = (int)($ln['badge'] ?? 0);
              $extraClass = trim($ln['class'] ?? '');

              $active = isActiveLink($href, $currentPath) ? ' active' : '';
              $classAttr = trim(($extraClass ? $extraClass : '') . $active);
              $classHtml = $classAttr ? ' class="'.htmlspecialchars($classAttr).'"' : '';

              // Вариант A: НЕ рендерим пустой span-бейдж — создаём его только если есть >0
              if ($label === 'Чаты') {
                  if ($badge > 0) {
                      // если есть счётчик — рендерим с id для JS-обновления
                      echo '<a href="'.htmlspecialchars($href).'"' . $classHtml . '>' . htmlspecialchars($label)
                           . ' <span id="newChatsBadge" class="badge">'.htmlspecialchars($badge).'</span></a>';
                  } else {
                      // если 0 — рендерим простую ссылку без пустого span
                      echo '<a href="'.htmlspecialchars($href).'"' . $classHtml . '>' . htmlspecialchars($label) . '</a>';
                  }
              } else {
                  echo '<a href="'.htmlspecialchars($href).'"' . $classHtml . '>' . htmlspecialchars($label);
                  if ($badge) echo " <span class='badge'>".htmlspecialchars($badge)."</span>";
                  echo '</a>';
              }
          }
        ?>
      </nav>
    </div>

    <div class="header-right" role="region" aria-live="polite">
      <?php if ($user): ?>
        <div class="name"><?= htmlspecialchars($user['name'] ?? $user['phone'] ?? 'admin') ?> <span style="font-weight:400;color:#9ca3af;">#<?= (int)($user['id'] ?? 0) ?></span></div>
        <div class="sub"><?= htmlspecialchars($user['phone'] ?? '') ?> · <?= htmlspecialchars($user['role'] ?? '') ?></div>

        <div class="header-actions" style="margin-top:8px;">
          <div class="balance"><?= number_format((float)($user['balance'] ?? 0.0), 2, '.', ' ') ?> TMT</div>
          <button id="adminTopupBtn" class="topup-btn" type="button" aria-controls="adminTopupModal" aria-expanded="false">Пополнить</button>

          <a class="header-action logout" href="<?= htmlspecialchars($base . '/logout.php') ?>">Выйти</a>
        </div>
      <?php else: ?>
        <div style="text-align:right;">
          <a href="<?= htmlspecialchars($base . '/login.php') ?>" class="header-actions">Войти</a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</header>

<!-- Admin Top-up modal (demo) -->
<div id="adminTopupModal" role="dialog" aria-modal="true" aria-hidden="true">
  <div class="m" role="document">
    <h3>Пополнить баланс (демо)</h3>
    <p>Тестовое пополнение баланса. В продакшне замените на платёжный шлюз.</p>

    <label>Сумма (TMT)</label>
    <input id="adminTopupAmount" type="number" step="0.01" min="0" value="100" style="width:100%;padding:8px;border-radius:6px;border:1px solid #ddd;margin-top:6px;">

    <div class="row" style="justify-content:flex-end;">
      <button id="adminTopupClose" style="background:#f3f4f6;">Отмена</button>
      <button id="adminTopupDemoConfirm" style="background:#0b57a4;color:#fff;">Пополнить (демо)</button>
    </div>
  </div>
</div>

<script>
(function(){
  const modal = document.getElementById('adminTopupModal');
  const btn = document.getElementById('adminTopupBtn');
  const closeBtn = document.getElementById('adminTopupClose');
  const confirmBtn = document.getElementById('adminTopupDemoConfirm');
  const amountInput = document.getElementById('adminTopupAmount');

  // ensure modal starts hidden
  if (modal) modal.setAttribute('aria-hidden','true');

  function showModal() {
    if (!modal) return;
    modal.setAttribute('aria-hidden','false');
    if (btn) { btn.setAttribute('aria-expanded','true'); btn.classList.add('active'); }
    document.body.style.overflow = 'hidden'; // prevent background scroll
  }
  function hideModal() {
    if (!modal) return;
    modal.setAttribute('aria-hidden','true');
    if (btn) { btn.setAttribute('aria-expanded','false'); btn.classList.remove('active'); }
    document.body.style.overflow = ''; // restore
  }

  if (btn && modal) {
    btn.addEventListener('click', function(){
      // toggle
      if (modal.getAttribute('aria-hidden') === 'true') showModal();
      else hideModal();
    });
    if (closeBtn) closeBtn.addEventListener('click', hideModal);

    modal.addEventListener('click', function(e){
      if (e.target === modal) hideModal();
    });

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
            const balanceEl = document.querySelector('.admin-top .balance');
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

  // Убедимся, что Escape закрывает модалку
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') {
      hideModal();
    }
  });

  // small helper: безопасно установить/удалить бейдж новых чатов
  window.setNewChatsCount = function(n) {
    const nav = document.querySelector('.nav-admin');
    if (!nav) return;
    let badge = document.getElementById('newChatsBadge');
    // если ноль — удаляем бейдж если он есть
    if (!n || n <= 0) {
      if (badge) badge.remove();
      return;
    }
    // если бейдж ещё нет — найдем ссылку "Чаты" и добавим
    if (!badge) {
      // ищем ссылку по href (best-effort)
      const links = nav.querySelectorAll('a');
      let chatLink = null;
      links.forEach(a=>{
        if (!chatLink) {
          try {
            if (a.textContent.trim().startsWith('Чаты')) chatLink = a;
          } catch(e){}
        }
      });
      if (!chatLink) {
        // fallback: если не нашли по тексту — возьмём первый
        chatLink = links[0];
      }
      if (chatLink) {
        const span = document.createElement('span');
        span.id = 'newChatsBadge';
        span.className = 'badge';
        span.textContent = String(n);
        chatLink.appendChild(document.createTextNode(' '));
        chatLink.appendChild(span);
      }
    } else {
      badge.textContent = String(n);
    }
  };

  // Навигация: Уведомления и Бухгалтерия — обычные ссылки в nav, JS не нужен.
})();
</script>
