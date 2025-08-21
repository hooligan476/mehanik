<?php require_once __DIR__.'/../../middleware.php'; require_admin(); require_once __DIR__.'/../../db.php'; ?> 
<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>Админка — Бренды/Модели</title>
<link rel="stylesheet" href="/mehanik/assets/css/style.css"></head><body>
  <?php require_once __DIR__.'/header.php'; ?>
<div class="container">
  <h2>Бренды</h2>
  <form method="post">
    <input type="text" name="brand" placeholder="Новый бренд">
    <button>Добавить</button>
  </form>
  <?php
  if($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['brand'])){
    $stmt=$mysqli->prepare("INSERT IGNORE INTO brands(name) VALUES(?)");
    $stmt->bind_param('s', $_POST['brand']);
    $stmt->execute();
  }
  $brands=$mysqli->query("SELECT * FROM brands ORDER BY name");
  ?>
  <ul>
    <?php while($b=$brands->fetch_assoc()): ?>
      <li>
        <strong><?= htmlspecialchars($b['name']) ?></strong>
        <form method="post" style="display:inline">
          <input type="hidden" name="brand_id" value="<?= $b['id'] ?>">
          <input type="text" name="model" placeholder="Новая модель">
          <button>+</button>
        </form>
        <ul>
          <?php $ms=$mysqli->query("SELECT * FROM models WHERE brand_id=".(int)$b['id']." ORDER BY name");
          while($m=$ms->fetch_assoc()): ?>
            <li><?= htmlspecialchars($m['name']) ?></li>
          <?php endwhile; ?>
        </ul>
      </li>
    <?php endwhile; ?>
  </ul>
  <?php
  if($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['brand_id']) && !empty($_POST['model'])){
    $stmt=$mysqli->prepare("INSERT IGNORE INTO models(brand_id,name) VALUES(?,?)");
    $stmt->bind_param('is', $_POST['brand_id'], $_POST['model']);
    $stmt->execute();
  }
  ?>
</div>
</body></html>
