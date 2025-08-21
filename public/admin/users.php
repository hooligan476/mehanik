<?php
require_once __DIR__.'/../../middleware.php';
require_admin();
require_once __DIR__.'/../../db.php';
require_once __DIR__.'/../../config.php';
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Админка — Пользователи</title>
  <link rel="stylesheet" href="/mehanik/assets/css/style.css">
</head>
<body>
  <?php require_once __DIR__.'/header.php'; ?>
  <h2 style="padding:16px;">Пользователи</h2>

  <table class="table">
    <tr>
      <th>ID</th>
      <th>Имя</th>
      <th>Email</th>
      <th>Роль</th>
      <th>Создан</th>
      <th>Товаров</th>
    </tr>

    <?php
    $sql = "
      SELECT
        u.id,
        u.name,
        u.email,
        u.role,
        u.created_at,
        COUNT(p.id) AS products_count
      FROM users u
      LEFT JOIN products p ON p.user_id = u.id
      GROUP BY u.id
      ORDER BY u.id DESC
    ";

    $res = $mysqli->query($sql);
    if ($res):
      while ($row = $res->fetch_assoc()):
        $uid = (int)$row['id'];
        $count = (int)$row['products_count'];
    ?>
      <tr>
        <td><?= htmlspecialchars($uid) ?></td>
        <td><?= htmlspecialchars($row['name']) ?></td>
        <td><?= htmlspecialchars($row['email']) ?></td>
        <td><?= htmlspecialchars($row['role']) ?></td>
        <td><?= htmlspecialchars($row['created_at']) ?></td>
        <td style="text-align:center;">
          <a href="<?= htmlspecialchars($config['base_url'] . '/admin/products.php?user_id=' . $uid) ?>">
            <?= $count ?>
          </a>
        </td>
      </tr>
    <?php
      endwhile;
      $res->free();
    else:
    ?>
      <tr><td colspan="6">Ошибка запроса: <?= htmlspecialchars($mysqli->error) ?></td></tr>
    <?php endif; ?>
  </table>
</body>
</html>
