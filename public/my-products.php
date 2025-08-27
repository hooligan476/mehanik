<?php
require_once __DIR__ . '/../middleware.php';
require_auth();
require_once __DIR__ . '/../db.php';

// ID текущего пользователя
$user_id = $_SESSION['user']['id'] ?? 0;

if (!$user_id) {
    die('Пользователь не найден в сессии.');
}

// Подключаем хедер
require_once __DIR__ . '/header.php';

// Получаем все товары пользователя
$stmt = $mysqli->prepare("SELECT * FROM products WHERE user_id = ? ORDER BY id DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
?>

<div class="container mt-5">
  <h2 class="mb-4">Мои товары</h2>

  <?php if ($res && $res->num_rows > 0): ?>
    <div class="row row-cols-1 row-cols-md-3 g-4">
      <?php while ($p = $res->fetch_assoc()): ?>
        <div class="col">
          <div class="card h-100 shadow-sm">
            <a href="/mehanik/public/product.php?id=<?= $p['id'] ?>">
              <img src="<?= htmlspecialchars($p['photo'] ?: '/mehanik/assets/no-photo.png') ?>"
                   class="card-img-top"
                   alt="<?= htmlspecialchars($p['name']) ?>">
            </a>
            <div class="card-body">
              <h5 class="card-title"><?= htmlspecialchars($p['name']) ?></h5>
              <p class="card-text text-muted">Цена: <?= number_format($p['price'], 2) ?> TMT</p>
              <p class="card-text text-muted">Наличие: <?= (int)$p['availability'] ?></p>
              <p class="card-text text-muted">Статус: <?= htmlspecialchars($p['status']) ?></p>
            </div>
            <div class="card-footer d-flex justify-content-between">
  <div>
    <a href="/mehanik/public/product.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-primary">👁 Просмотр</a>
    <a href="/mehanik/public/edit-product.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-warning">✏ Редактировать</a>
  </div>
  
  <form method="post" action="/mehanik/api/delete-product.php" onsubmit="return confirm('Удалить товар?');" style="display:inline;">
    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
    <button type="submit" class="btn btn-danger">Удалить</button>
  </form>
</div>

          </div>
        </div>
      <?php endwhile; ?>
    </div>
  <?php else: ?>
    <p>У вас пока нет добавленных товаров.</p>
  <?php endif; ?>
</div>

<!-- Подключение Bootstrap CSS для сетки и кнопок -->
<link rel="stylesheet" href="/mehanik/assets/css/header.css">
<link rel="stylesheet" href="/mehanik/assets/css/style.css">
