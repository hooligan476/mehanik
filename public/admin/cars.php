<?php 
require_once __DIR__.'/../../middleware.php'; 
require_admin(); 
require_once __DIR__.'/../../db.php'; 
?> 
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Админка — Бренды/Модели/Части</title>
  <link rel="stylesheet" href="/mehanik/assets/css/style.css">
  <style>
    body { background:#f5f6fa; font-family:Arial,sans-serif; }
    .container {
        max-width:1100px;
        margin:20px auto;
        background:#fff;
        border-radius:10px;
        padding:20px 30px;
        box-shadow:0 2px 6px rgba(0,0,0,.1);
    }
    h2 { margin-top:25px; margin-bottom:15px; }
    form.inline { display:inline-flex; gap:6px; margin:3px 0; }
    input[type=text] {
        padding:6px 10px;
        border:1px solid #ccc;
        border-radius:6px;
    }
    button {
        padding:6px 12px;
        border:none;
        border-radius:6px;
        cursor:pointer;
    }
    button[value=add_brand],
    button[value=add_part],
    button[value=add_model],
    button[value=add_component] {
        background:#2ecc71; color:#fff;
    }
    button[value=edit_brand],
    button[value=edit_part],
    button[value=edit_model],
    button[value=edit_component] {
        background:#3498db; color:#fff;
    }
    button[value=delete_brand],
    button[value=delete_part],
    button[value=delete_model],
    button[value=delete_component] {
        background:#e74c3c; color:#fff;
    }
    ul {
        list-style:none;
        margin:8px 0 12px 15px;
        padding:0;
    }
    ul li {
        margin:6px 0;
        padding:8px;
        background:#fafafa;
        border:1px solid #eee;
        border-radius:6px;
    }
    ul li ul li {
        background:#fff;
        margin:4px 0;
    }
  </style>
</head>
<body>
<?php require_once __DIR__.'/header.php'; ?>
<div class="container">

  <h2>Бренды и модели</h2>
  <!-- Форма добавления бренда -->
  <form method="post" style="margin-bottom:10px; display:flex; gap:8px;">
    <input type="text" name="brand" placeholder="Новый бренд">
    <button name="action" value="add_brand">Добавить бренд</button>
  </form>

  <?php
  // --- Обработка POST запросов ---
  if ($_SERVER['REQUEST_METHOD']==='POST') {
      if ($_POST['action']==='add_brand' && !empty($_POST['brand'])) {
          $stmt=$mysqli->prepare("INSERT INTO brands(name) VALUES(?)");
          $stmt->bind_param('s', $_POST['brand']);
          $stmt->execute();
      }
      if ($_POST['action']==='edit_brand') {
          $stmt=$mysqli->prepare("UPDATE brands SET name=? WHERE id=?");
          $stmt->bind_param('si', $_POST['name'], $_POST['id']);
          $stmt->execute();
      }
      if ($_POST['action']==='delete_brand') {
          $stmt=$mysqli->prepare("DELETE FROM brands WHERE id=?");
          $stmt->bind_param('i', $_POST['id']);
          $stmt->execute();
      }
      if ($_POST['action']==='add_model') {
          $stmt=$mysqli->prepare("INSERT INTO models(brand_id,name) VALUES(?,?)");
          $stmt->bind_param('is', $_POST['brand_id'], $_POST['model']);
          $stmt->execute();
      }
      if ($_POST['action']==='edit_model') {
          $stmt=$mysqli->prepare("UPDATE models SET name=? WHERE id=?");
          $stmt->bind_param('si', $_POST['name'], $_POST['id']);
          $stmt->execute();
      }
      if ($_POST['action']==='delete_model') {
          $stmt=$mysqli->prepare("DELETE FROM models WHERE id=?");
          $stmt->bind_param('i', $_POST['id']);
          $stmt->execute();
      }
      if ($_POST['action']==='add_part') {
          $stmt=$mysqli->prepare("INSERT INTO complex_parts(name) VALUES(?)");
          $stmt->bind_param('s', $_POST['part']);
          $stmt->execute();
      }
      if ($_POST['action']==='edit_part') {
          $stmt=$mysqli->prepare("UPDATE complex_parts SET name=? WHERE id=?");
          $stmt->bind_param('si', $_POST['name'], $_POST['id']);
          $stmt->execute();
      }
      if ($_POST['action']==='delete_part') {
          $stmt=$mysqli->prepare("DELETE FROM complex_parts WHERE id=?");
          $stmt->bind_param('i', $_POST['id']);
          $stmt->execute();
      }
      if ($_POST['action']==='add_component') {
          $stmt=$mysqli->prepare("INSERT INTO components(complex_part_id,name) VALUES(?,?)");
          $stmt->bind_param('is', $_POST['part_id'], $_POST['component']);
          $stmt->execute();
      }
      if ($_POST['action']==='edit_component') {
          $stmt=$mysqli->prepare("UPDATE components SET name=? WHERE id=?");
          $stmt->bind_param('si', $_POST['name'], $_POST['id']);
          $stmt->execute();
      }
      if ($_POST['action']==='delete_component') {
          $stmt=$mysqli->prepare("DELETE FROM components WHERE id=?");
          $stmt->bind_param('i', $_POST['id']);
          $stmt->execute();
      }
      header("Location: cars.php");
      exit;
  }

  // --- Вывод брендов и моделей ---
  $brands=$mysqli->query("SELECT * FROM brands ORDER BY name");
  ?>
  <ul>
    <?php while($b=$brands->fetch_assoc()): ?>
      <li>
        <form method="post" class="inline">
          <input type="hidden" name="id" value="<?= $b['id'] ?>">
          <input type="text" name="name" value="<?= htmlspecialchars($b['name']) ?>">
          <button name="action" value="edit_brand">Сохранить</button>
          <button name="action" value="delete_brand" onclick="return confirm('Удалить бренд?')">Удалить</button>
        </form>

        <!-- Добавление модели -->
        <form method="post" class="inline">
          <input type="hidden" name="brand_id" value="<?= $b['id'] ?>">
          <input type="text" name="model" placeholder="Новая модель">
          <button name="action" value="add_model">+</button>
        </form>

        <ul>
          <?php 
          $ms=$mysqli->query("SELECT * FROM models WHERE brand_id=".(int)$b['id']." ORDER BY name");
          while($m=$ms->fetch_assoc()): ?>
            <li>
              <form method="post" class="inline">
                <input type="hidden" name="id" value="<?= $m['id'] ?>">
                <input type="text" name="name" value="<?= htmlspecialchars($m['name']) ?>">
                <button name="action" value="edit_model">Сохранить</button>
                <button name="action" value="delete_model" onclick="return confirm('Удалить модель?')">Удалить</button>
              </form>
            </li>
          <?php endwhile; ?>
        </ul>
      </li>
    <?php endwhile; ?>
  </ul>

  <h2>Комплексные части</h2>
  <form method="post" style="margin-bottom:10px; display:flex; gap:8px;">
    <input type="text" name="part" placeholder="Новая часть">
    <button name="action" value="add_part">Добавить часть</button>
  </form>

  <?php
  $parts=$mysqli->query("SELECT * FROM complex_parts ORDER BY name");
  ?>
  <ul>
    <?php while($p=$parts->fetch_assoc()): ?>
      <li>
        <form method="post" class="inline">
          <input type="hidden" name="id" value="<?= $p['id'] ?>">
          <input type="text" name="name" value="<?= htmlspecialchars($p['name']) ?>">
          <button name="action" value="edit_part">Сохранить</button>
          <button name="action" value="delete_part" onclick="return confirm('Удалить часть?')">Удалить</button>
        </form>

        <!-- Добавление компонента -->
        <form method="post" class="inline">
          <input type="hidden" name="part_id" value="<?= $p['id'] ?>">
          <input type="text" name="component" placeholder="Новый компонент">
          <button name="action" value="add_component">+</button>
        </form>

        <ul>
          <?php 
          $cs=$mysqli->query("SELECT * FROM components WHERE complex_part_id=".(int)$p['id']." ORDER BY name");
          while($c=$cs->fetch_assoc()): ?>
            <li>
              <form method="post" class="inline">
                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                <input type="text" name="name" value="<?= htmlspecialchars($c['name']) ?>">
                <button name="action" value="edit_component">Сохранить</button>
                <button name="action" value="delete_component" onclick="return confirm('Удалить компонент?')">Удалить</button>
              </form>
            </li>
          <?php endwhile; ?>
        </ul>
      </li>
    <?php endwhile; ?>
  </ul>

</div>
</body>
</html>
