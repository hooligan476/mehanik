<?php
// login.php
session_start();

// DB настройки — при необходимости измени
$dbHost = '127.0.0.1';
$dbName = 'mehanik';
$dbUser = 'root';
$dbPass = '';

try {
    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    die("DB connection failed: " . $e->getMessage());
}

function clean($v) {
    return trim(htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
}

$errors = [];
$phone_local = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Входная форма — префикс +993 (неизменяемый) + поле phone_local (8 цифр)
    $phone_local = isset($_POST['phone_local']) ? preg_replace('/\D/', '', $_POST['phone_local']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if ($phone_local === '') $errors[] = 'Введите номер (8 цифр).';
    if (!preg_match('/^\d{8}$/', $phone_local)) $errors[] = 'Номер должен содержать ровно 8 цифр (без префикса +993).';
    if ($password === '') $errors[] = 'Введите пароль.';

    if (empty($errors)) {
        $phone_norm = '+993' . $phone_local;

        // берём session_version, если колонка есть
        $stmt = $pdo->prepare("SELECT id, name, phone, password_hash, role, created_at, verify_code, status, COALESCE(session_version, 0) AS session_version FROM users WHERE phone = :phone LIMIT 1");
        $stmt->execute([':phone' => $phone_norm]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $errors[] = 'Пользователь не найден.';
        } else {
            if (password_verify($password, $user['password_hash'])) {
                // Безопасность: регенерируем id сессии после успешного логина
                session_regenerate_id(true);

                // Убираем хэш пароля из массива и сохраняем только нужные поля
                unset($user['password_hash']);

                // Явно приводим session_version к int и записываем в сессию
                $session_version = isset($user['session_version']) ? (int)$user['session_version'] : 0;

                $_SESSION['user'] = [
                    'id' => (int)$user['id'],
                    'name' => $user['name'],
                    'phone' => $user['phone'],
                    'role' => $user['role'],
                    'created_at' => $user['created_at'] ?? null,
                    'verify_code' => $user['verify_code'] ?? null,
                    'status' => $user['status'] ?? null,
                    'session_version' => $session_version
                ];

                // Абсолютный редирект на public/index.php внутри проекта mehanik
                header('Location: http://localhost/mehanik/public/index.php');
                exit;
            } else {
                $errors[] = 'Неверный пароль.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Вход — Mehanik</title>
<meta name="viewport" content="width=device-width,initial-scale=1">

<style>
  :root{
    --bg1:#0f172a;
    --bg2:#0b57a4;
    --card:#ffffff;
    --muted:#6b7280;
    --accent:#0b57a4;
    --danger:#ef4444;
  }
  html,body{height:100%;margin:0;font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial;}
  body{
    background: linear-gradient(135deg,var(--bg1) 0%, #102b4a 40%, var(--bg2) 100%);
    display:flex; align-items:center; justify-content:center; padding:24px;
    color:#0b1220;
  }

  .wrap{
    width:100%; max-width:980px; display:grid; grid-template-columns: 1fr 420px; gap:36px; align-items:center;
  }

  .promo{
    color:#fff;
    padding:28px;
    border-radius:12px;
    background: linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.01));
    box-shadow: 0 10px 30px rgba(2,6,23,0.45);
  }
  .promo h1{ margin:0 0 10px; font-size:28px; line-height:1.05; }
  .promo p{ margin:0 0 18px; color: rgba(255,255,255,0.85); font-size:15px; }

  .card{
    background:var(--card);
    border-radius:12px;
    padding:20px;
    box-shadow: 0 8px 24px rgba(2,6,23,0.12);
  }

  .logo {
    display:inline-flex; align-items:center; gap:10px; text-decoration:none; color:var(--card);
    margin-bottom:12px;
  }
  .brand-box{ width:44px;height:44px;border-radius:9px;background:linear-gradient(180deg,#0b57a4,#083a6b); display:flex;align-items:center;justify-content:center;}
  .brand-box strong{color:#fff;font-size:18px;}
  .brand-title{color:#fff;font-weight:700;font-size:18px;}

  form{ display:flex; flex-direction:column; gap:12px; }
  label.small{ font-size:13px; color:var(--muted); display:block; margin-bottom:4px;}
  .input-inline{ display:flex; align-items:center; gap:0; }
  .prefix{ background:#f3f4f6; padding:10px 12px; border:1px solid #e6e9ef; border-right:0; border-radius:8px 0 0 8px; color:#0b1220; font-weight:600;}
  input[type="text"], input[type="password"], input[type="number"]{
    flex:1; padding:10px 12px; border:1px solid #e6e9ef; border-radius:0 8px 8px 0; outline:none; font-size:15px;
    box-sizing:border-box;
  }
  input[type="password"]{ border-radius:8px; padding:10px 12px; }
  .hint{ font-size:13px; color:var(--muted); }

  .errors{ background:#fff6f6; border:1px solid rgba(239,68,68,0.12); color:var(--danger); padding:10px; border-radius:8px; margin-bottom:6px;}
  .errors ul{ margin:0; padding-left:18px; }

  .actions{ display:flex; gap:10px; align-items:center; margin-top:6px;}
  button.primary{
    background: linear-gradient(180deg,var(--accent), #074b82); color:#fff; border:0; padding:10px 14px; border-radius:8px; font-weight:700; cursor:pointer;
    box-shadow: 0 8px 18px rgba(10,30,60,0.12);
  }
  a.link{ color:var(--accent); text-decoration:none; font-weight:600;}
  a.link:hover{ text-decoration:underline; }

  .small-muted{ font-size:13px; color:var(--muted); margin-top:6px; }

  /* mobile */
  @media (max-width:900px){
    .wrap{ grid-template-columns: 1fr; gap:18px; }
    .promo{ order:2; }
  }
</style>
</head>
<body>
<div class="wrap">
  <div class="promo">
    <h1>Mehanik — Маркет автозапчастей</h1>
    <p>Войдите, чтобы добавлять и управлять товарами, общаться с покупателями и отслеживать статусы.</p>
    <p style="margin-top:18px;"><strong>Подсказка:</strong> номер вводится без кода страны — префикс <code>+993</code> фиксирован.</p>
  </div>

  <div class="card" aria-live="polite">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
      <div style="display:flex;gap:12px;align-items:center;">
        <div class="brand-box"><strong>M</strong></div>
        <div>
          <div style="font-weight:700;font-size:16px;">Mehanik</div>
          <div style="font-size:13px;color:var(--muted);">Вход в личный кабинет</div>
        </div>
      </div>
      <div style="font-size:13px;color:var(--muted);">Не сохраняем пароли в браузере</div>
    </div>

    <?php if (!empty($errors)): ?>
      <div class="errors">
        <ul>
          <?php foreach ($errors as $e): ?>
            <li><?=htmlspecialchars($e)?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <!-- форма с autocomplete отключённым; есть "ловушка" для автозаполнения -->
    <form method="post" action="" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" id="loginForm" novalidate>
      <!-- ловушка: скрытое поле, чтобы браузеры подставляли его, а не реальные -->
      <input style="display:none" type="text" name="fakeusernameremembered" id="fakeusernameremembered" autocomplete="off">

      <label class="small" for="phone_local">Телефон</label>
      <div class="input-inline" style="margin-bottom:6px;">
        <div class="prefix">+993</div>
        <input id="phone_local" name="phone_local" type="text" inputmode="numeric" pattern="\d{8}" maxlength="8" placeholder="XXXXXXXX"
               autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
               value="<?= isset($phone_local) ? htmlspecialchars($phone_local) : '' ?>">
      </div>
      <div class="hint">Введите 8 цифр номера без префикса.</div>

      <label class="small" for="password">Пароль</label>
      <input id="password" name="password" type="password" placeholder="Ваш пароль"
             autocomplete="new-password" autocorrect="off" autocapitalize="off" spellcheck="false">

      <div class="actions">
        <button type="submit" class="primary">Войти</button>
        <a class="link" href="/mehanik/public/register.php">Регистрация</a>
      </div>

      <div class="small-muted">Если хотите полностью выйти — используйте <a href="/mehanik/public/logout.php" class="link">страницу выхода</a>, она уничтожит сессию.</div>
    </form>
  </div>
</div>

<script>
  // Отключаем автозаполнение и очищаем поля при загрузке.
  (function(){
    try {
      const form = document.getElementById('loginForm');
      form.setAttribute('autocomplete','off');

      // Очистим поля спустя небольшой таймаут — помогает обойти автозаполнение некоторых браузеров
      window.addEventListener('load', () => {
        setTimeout(() => {
          const phone = document.getElementById('phone_local');
          const pass  = document.getElementById('password');
          if (phone) { phone.value = phone.value ? phone.value.trim() : ''; }
          if (pass)  { pass.value = ''; }
          // дополнительно ресет формы — безопасно при загрузке
          try { form.reset(); } catch(e) {}
        }, 60);
      });

      // при выгрузке страницы очищаем значения (несколько приёмов)
      window.addEventListener('pagehide', () => {
        try {
          const phone = document.getElementById('phone_local');
          const pass  = document.getElementById('password');
          if (phone) phone.value = '';
          if (pass) pass.value = '';
        } catch(e){}
      });

      // также предотвращаем автозаполнение по focus (ежели браузер пытается подставить)
      document.querySelectorAll('input').forEach(input=>{
        input.addEventListener('focus', () => {
          // если браузер подставил значение до JS-очистки, очищаем его
          setTimeout(()=> {
            if (input && input.id === 'password') input.value = '';
          }, 30);
        });
      });
    } catch(e){
      console.warn('Autofill mitigation failed', e);
    }
  })();
</script>
</body>
</html>
