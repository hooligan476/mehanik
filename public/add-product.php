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

      <!-- SKU: генерируется и нередактируемый -->
      <label>Артикул</label>
      <input type="text" id="skuField" name="sku" readonly>

      <label>Производитель</label>
      <input type="text" name="manufacturer" placeholder="Производитель">

      <!-- Состояние товара (как и раньше было quality) -->
      <label>Состояние</label>
      <select name="quality">
        <option value="New">New</option>
        <option value="Used">Used</option>
      </select>

      <!-- Новое поле: Качество (бывший rating) -->
      <label>Качество (0.1–9.9)</label>
      <input type="number" name="rating" step="0.1" min="0.1" max="9.9" value="5.0" required>

      <label>Наличие</label>
      <input type="number" name="availability" placeholder="Наличие" value="1">

      <label>Цена</label>
      <input type="number" step="0.01" name="price" placeholder="Цена" required>

      <!-- Бренд -->
      <label>Бренд</label>
      <select name="brand_id" id="ap_brand" required>
        <option value="">-- выберите бренд --</option>
        <?php
          $brands = $mysqli->query("SELECT id, name FROM brands ORDER BY name");
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
          $cparts = $mysqli->query("SELECT id, name FROM complex_parts ORDER BY name");
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

      <label>Описание</label>
      <textarea name="description" placeholder="Описание"></textarea>

      <label>Фото</label>
      <input type="file" name="photo" accept="image/*">

      <button type="submit">Сохранить</button>
    </form>
  </div>

<script>
// === Генерация SKU и защита от правок ===
(function generateSKUOnce() {
  const field = document.getElementById('skuField');
  // если пусто — генерим
  if (!field.value) {
    const rand = Math.random().toString(36).slice(2, 10).toUpperCase();
    const num  = String(Math.floor(Math.random()*1e9)).padStart(9, '0');
    field.value = `SKU-${rand}-${num}`;
  }
  // дубль-защита от изменения с клавиатуры
  field.addEventListener('keydown', e => e.preventDefault());
  field.addEventListener('beforeinput', e => e.preventDefault());
})();

// === загрузка моделей по бренду ===
document.getElementById('ap_brand').addEventListener('change', async function() {
  const brandId = this.value;
  const modelSelect = document.getElementById('ap_model');
  modelSelect.innerHTML = '<option value="">-- выберите модель --</option>';
  if (!brandId) return;

  try {
    const res = await fetch(`/mehanik/api/get-models.php?brand_id=${encodeURIComponent(brandId)}`);
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
    const res = await fetch(`/mehanik/api/get-components.php?complex_part_id=${encodeURIComponent(cpartId)}`);
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
