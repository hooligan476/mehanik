<?php require_once __DIR__.'/../../middleware.php'; require_admin(); require_once __DIR__.'/../../db.php'; ?> 
<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>Админка — Пользователи</title>
<link rel="stylesheet" href="/mehanik/assets/css/style.css"></head><body>
<h2 style="padding:16px;">Пользователи</h2>
<table class="table">
  <tr><th>ID</th><th>Имя</th><th>Email</th><th>Роль</th><th>Создан</th></tr>
  <?php $res=$mysqli->query("SELECT id,name,email,role,created_at FROM users ORDER BY id DESC");
  while($row=$res->fetch_assoc()): ?>
    <tr>
      <td><?= htmlspecialchars($row['id']) ?></td>
      <td><?= htmlspecialchars($row['name']) ?></td>
      <td><?= htmlspecialchars($row['email']) ?></td>
      <td><?= htmlspecialchars($row['role']) ?></td>
      <td><?= htmlspecialchars($row['created_at']) ?></td>
    </tr>
  <?php endwhile; ?>
</table>
</body></html>
