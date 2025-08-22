<?php 
require_once __DIR__ . '/../middleware.php'; 
require_auth(); 
require_once __DIR__ . '/../db.php'; 
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Добавить товар</title>
  <link rel="stylesheet" href="/mehanik/assets/css/style.css">
</head>
<body>
  <?php include __DIR__ . '/header.php'; ?>

  <div class="container">
    <h2>Добавление товара</h2>
    <form id="addProductForm" enctype="multipart/form-data" method="post" action="/mehanik/api/add-product.php">
      <input type="text" name="name" placeholder="Название" required>

      <!-- SKU: генерируем по умолчанию, можно оставить пустым -->
      <input type="text" id="skuField" name="sku" placeholder="ID/Артикул (необязательно)">

      <input type="text" name="manufacturer" placeholder="Производитель">

      <select name="quality">
        <option value="New">New</option>
        <option value="Used">Used</option>
      </select>

      <input type="number" name="availability" placeholder="Наличие" value="1">
      <input type="number" step="0.01" name="price" placeholder="Цена" required>

      <!-- Бренд -->
      <label>Бренд</label>
      <select name="brand_id" id="ap_brand" required>
        <option value="">-- выберите бренд --</option>
        <?php
          $brands = $mysqli->query("SELECT * FROM brands ORDER BY name");
          while ($b = $brands->fetch_assoc()) {
            echo '<option value="'.$b['id'].'">'.htmlspecialchars($b['name']).'</option>';
          }
        ?>
      </select>

      <!-- Модель -->
      <label>Модель</label>
      <select name="model_id" id="ap_model" required>
        <option value="">-- выберите модель --</option>
      </select>

      <!-- Годы -->
      <label>Годы</label>
      <div class="row">
        <input type="number" name="year_from" placeholder="от">
        <input type="number" name="year_to" placeholder="до">
      </div>

      <!-- Комплексная часть -->
      <label>Комплексная часть</label>
      <select name="complex_part_id" id="ap_cpart" required>
        <option value="">-- выберите часть --</option>
        <?php
          $cparts = $mysqli->query("SELECT * FROM complex_parts ORDER BY name");
          while ($c = $cparts->fetch_assoc()) {
            echo '<option value="'.$c['id'].'">'.htmlspecialchars($c['name']).'</option>';
          }
        ?>
      </select>

      <!-- Компонент -->
      <label>Компонент</label>
      <select name="component_id" id="ap_comp" required>
        <option value="">-- выберите компонент --</option>
      </select>

      <textarea name="description" placeholder="Описание"></textarea>
      <input type="file" name="photo" accept="image/*">

      <button type="submit">Сохранить</button>
    </form>
  </div>

<script>
// === Автогенерация SKU при открытии страницы ===
(function generateSKU() {
  const field = document.getElementById('skuField');
  if (!field.value) {
    field.value = "SKU-" + Math.random().toString(36).substring(2, 10).toUpperCase();
  }
})();

// === загрузка моделей по бренду ===
document.getElementById('ap_brand').addEventListener('change', async function() {
  const brandId = this.value;
  const modelSelect = document.getElementById('ap_model');
  modelSelect.innerHTML = '<option value="">-- выберите модель --</option>';
  if (!brandId) return;

  try {
    const res = await fetch(`/mehanik/api/get-models.php?brand_id=${brandId}`);
    const data = await res.json();
    data.forEach(m => {
      const opt = document.createElement('option');
      opt.value = m.id;
      opt.textContent = m.name;
      modelSelect.appendChild(opt);
    });
  } catch(e) {
    console.error("Ошибка загрузки моделей", e);
  }
});

// === загрузка компонентов по комплексной части ===
document.getElementById('ap_cpart').addEventListener('change', async function() {
  const cpartId = this.value;
  const compSelect = document.getElementById('ap_comp');
  compSelect.innerHTML = '<option value="">-- выберите компонент --</option>';
  if (!cpartId) return;

  try {
    const res = await fetch(`/mehanik/api/get-components.php?complex_part_id=${cpartId}`);
    const data = await res.json();
    data.forEach(c => {
      const opt = document.createElement('option');
      opt.value = c.id;
      opt.textContent = c.name;
      compSelect.appendChild(opt);
    });
  } catch(e) {
    console.error("Ошибка загрузки компонентов", e);
  }
});
</script>
</body>
</html>
