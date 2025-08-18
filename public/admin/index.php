<?php require_once __DIR__.'/../../middleware.php'; require_admin(); ?> 
<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>Админка — Дашборд</title>
<link rel="stylesheet" href="/mehanik/assets/css/style.css"></head><body>
<header class="topbar"><div class="brand">Админка</div>
  <nav>
    <a href="/mehanik/public/admin/users.php">Пользователи</a>
    <a href="/mehanik/public/admin/cars.php">Бренды/Модели</a>
    <a href="/mehanik/public/admin/chats.php">Чаты</a>
  </nav>
</header>
<div class="container">
  <h2>Статистика</h2>
  <div id="stats"></div>
</div>
<script>
fetch('/mehanik/api/admin-stats.php').then(r=>r.json()).then(d=>{
  const el = document.getElementById('stats');
  el.innerHTML = `Пользователи: ${d.users} · Товары: ${d.products} · Открытые чаты: ${d.open_chats}`;
});
</script>
</body></html>
