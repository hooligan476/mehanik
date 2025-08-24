<?php
require_once __DIR__.'/../../middleware.php';
require_admin();
require_once __DIR__.'/../../db.php';
require_once __DIR__.'/../../config.php';

// Получаем фильтр по пользователю (если есть)
$user_id = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null;

// Обработка возможного удаления через GET (только для админа)
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $delId = (int)$_GET['id'];
    $stmtDel = $mysqli->prepare("DELETE FROM products WHERE id = ?");
    if ($stmtDel) {
        $stmtDel->bind_param('i', $delId);
        $stmtDel->execute();
        $stmtDel->close();
        header('Location: ' . $config['base_url'] . '/admin/products.php' . ($user_id ? '?user_id=' . $user_id : ''));
        exit;
    }
}

// Обработка подтверждения/отклонения
if (isset($_GET['action']) && in_array($_GET['action'], ['approve','reject']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $status = $_GET['action'] === 'approve' ? 'approved' : 'rejected';

    $stmt = $mysqli->prepare("UPDATE products SET status = ? WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param('si', $status, $id);
        $stmt->execute();
        $stmt->close();
    }
    header('Location: ' . $config['base_url'] . '/admin/products.php' . ($user_id ? '?user_id=' . $user_id : ''));
    exit;
}

// Формируем запрос: если есть user_id — фильтруем, иначе показываем все
if ($user_id) {
    $sql = "SELECT p.*, u.name AS owner_name FROM products p LEFT JOIN users u ON u.id = p.user_id WHERE p.user_id = ? ORDER BY p.id DESC";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $user_id);
} else {
    $sql = "SELECT p.*, u.name AS owner_name FROM products p LEFT JOIN users u ON u.id = p.user_id ORDER BY p.id DESC";
    $stmt = $mysqli->prepare($sql);
}

$products = [];
if ($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $products[] = $row;
    }
    $stmt->close();
} else {
    $error = $mysqli->error;
}
?>

<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Админка — Товары</title>
  <link rel="stylesheet" href="/mehanik/assets/css/style.css">
  <style>
    .actions a { margin-right: 8px; }
    .small { font-size: 0.9em; color: #666; }
    .status-approved { color: green; font-weight: bold; }
    .status-rejected { color: red; font-weight: bold; }
    .status-pending { color: orange; font-weight: bold; }
  </style>
</head>
<body>
  <?php require_once __DIR__.'/header.php'; ?>
  <h2 style="padding:16px;">Товары<?= $user_id ? ' пользователя #' . htmlspecialchars($user_id) : '' ?></h2>
  <p style="padding:0 16px 16px;"><a href="<?= htmlspecialchars($config['base_url'] . '/admin/users.php') ?>">← Назад к пользователям</a></p>

  <?php if (!empty($error)): ?>
    <div class="alert">Ошибка запроса: <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <table class="table">
    <tr>
      <th>ID</th>
      <th>SKU</th>
      <th>Название</th>
      <th>Бренд ID</th>
      <th>Модель ID</th>
      <th>Годы</th>
      <th>Цена</th>
      <th>Доступно</th>
      <th>Владелец</th>
      <th>Создано</th>
      <th>Статус</th>
      <th>Действия</th>
    </tr>

    <?php if (count($products) === 0): ?>
      <tr><td colspan="12" style="text-align:center;">Товары не найдены.</td></tr>
    <?php else: ?>
      <?php foreach ($products as $p): ?>
        <tr>
          <td><?= htmlspecialchars($p['id']) ?></td>
          <td><?= htmlspecialchars($p['sku'] ?? '') ?></td>
          <td><?= htmlspecialchars($p['name'] ?? '') ?></td>
          <td><?= htmlspecialchars($p['brand_id'] ?? '') ?></td>
          <td><?= htmlspecialchars($p['model_id'] ?? '') ?></td>
          <td><?= htmlspecialchars(($p['year_from'] ?? '') . ' — ' . ($p['year_to'] ?? '')) ?></td>
          <td><?= htmlspecialchars($p['price'] ?? '') ?></td>
          <td><?= htmlspecialchars($p['availability'] ?? '') ?></td>
          <td><?= htmlspecialchars($p['owner_name'] ?? '') ?> (ID: <?= htmlspecialchars($p['user_id'] ?? '') ?>)</td>
          <td><?= htmlspecialchars($p['created_at'] ?? '') ?></td>
          <td>
            <?php if ($p['status'] === 'approved'): ?>
              <span class="status-approved">Подтверждён</span>
            <?php elseif ($p['status'] === 'rejected'): ?>
              <span class="status-rejected">Отклонён</span>
            <?php else: ?>
              <span class="status-pending">На модерации</span>
            <?php endif; ?>
          </td>
          <td class="actions">
            <a href="<?= htmlspecialchars($config['base_url'] . '/product.php?id=' . $p['id']) ?>" target="_blank">Просмотр</a>
            <a href="<?= htmlspecialchars($config['base_url'] . '/admin/edit_product.php?id=' . $p['id']) ?>">Ред.</a>
            <?php if ($p['status'] !== 'approved'): ?>
              <a href="<?= htmlspecialchars($config['base_url'] . '/admin/products.php?action=approve&id=' . $p['id'] . ($user_id ? '&user_id=' . $user_id : '')) ?>">Подтвердить</a>
            <?php endif; ?>
            <?php if ($p['status'] !== 'rejected'): ?>
              <a href="<?= htmlspecialchars($config['base_url'] . '/admin/products.php?action=reject&id=' . $p['id'] . ($user_id ? '&user_id=' . $user_id : '')) ?>">Отклонить</a>
            <?php endif; ?>
            <a href="<?= htmlspecialchars($config['base_url'] . '/admin/products.php?user_id=' . ($user_id ?: '') . '&action=delete&id=' . $p['id']) ?>" onclick="return confirm('Удалить товар #<?= htmlspecialchars($p['id']) ?>?')">Удал.</a>
          </td>
        </tr>
      <?php endforeach; ?>
    <?php endif; ?>
  </table>
</body>
</html>
