<?php
require_once __DIR__ . '/../middleware.php';
require_once __DIR__ . '/../db.php';
require_auth();

$user_id = $_SESSION['user']['id'];

$stmt = $mysqli->prepare("SELECT * FROM products WHERE user_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();

include __DIR__ . '/header.php';
?>

<div class="container mt-5">
  <h2 class="mb-4">Мои товары</h2>

  <?php if ($res->num_rows > 0): ?>
    <div class="row row-cols-1 row-cols-md-3 g-4">
      <?php while ($p = $res->fetch_assoc()): ?>
        <div class="col">
          <div class="card h-100 shadow-sm">
            <a href="/mehanik/public/product.php?id=<?= $p['id'] ?>">
              <?php if ($p['photo']): ?>
                <img src="<?= htmlspecialchars($p['photo']) ?>" class="card-img-top" alt="<?= htmlspecialchars($p['name']) ?>">
              <?php else: ?>
                <img src="/mehanik/assets/no-photo.png" class="card-img-top" alt="Нет фото">
              <?php endif; ?>
            </a>
            <div class="card-body">
              <h5 class="card-title"><?= htmlspecialchars($p['name']) ?></h5>
              <p class="card-text text-muted">Цена: <?= number_format($p['price'], 2) ?> TMT</p>
            </div>
            <div class="card-footer d-flex justify-content-between">
              <a href="/mehanik/public/edit-product.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-warning">✏ Редактировать</a>

                <form method="post" action="/mehanik/api/delete-product.php" onsubmit="return confirm('Удалить товар?');" style="display:inline;">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button type="submit" class="btn btn-danger">Удалить</button>
              </form>

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
