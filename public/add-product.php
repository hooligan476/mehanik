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
      <label>Название *</label>
      <input type="text" name="name" placeholder="Название" required>

      <label>Производитель</label>
      <input type="text" name="manufacturer" placeholder="Производитель">

      <label>Состояние *</label>
      <select name="quality" required>
        <option value="New">New</option>
        <option value="Used">Used</option>
      </select>

      <label>Качество (0.1–9.9) *</label>
      <input type="number" name="rating" step="0.1" min="0.1" max="9.9" value="5.0" required>

      <label>Наличие *</label>
      <input type="number" name="availability" placeholder="Наличие" value="1" required>

      <label>Цена *</label>
      <input type="number" step="0.01" name="price" placeholder="Цена" required>

      <label>Бренд *</label>
      <select name="brand_id" id="ap_brand" required>
        <option value="">-- выберите бренд --</option>
        <?php
          if (isset($mysqli)) {
            $brands = $mysqli->query("SELECT id, name FROM brands ORDER BY name");
            while ($b = $brands->fetch_assoc()) {
              echo '<option value="'.$b['id'].'">'.htmlspecialchars($b['name']).'</option>';
            }
          }
        ?>
      </select>

      <label>Модель *</label>
      <select name="model_id" id="ap_model" required>
        <option value="">-- выберите модель --</option>
      </select>

      <label>Годы выпуска</label>
      <div class="row">
        <input type="number" name="year_from" placeholder="от">
        <input type="number" name="year_to" placeholder="до">
      </div>

      <label>Комплексная часть *</label>
      <select name="complex_part_id" id="ap_cpart" required>
        <option value="">-- выберите часть --</option>
        <?php
          if (isset($mysqli)) {
            $cparts = $mysqli->query("SELECT id, name FROM complex_parts ORDER BY name");
            while ($c = $cparts->fetch_assoc()) {
              echo '<option value="'.$c['id'].'">'.htmlspecialchars($c['name']).'</option>';
            }
          }
        ?>
      </select>

      <label>Компонент *</label>
      <select name="component_id" id="ap_comp" required>
        <option value="">-- выберите компонент --</option>
      </select>

      <label>Описание</label>
      <textarea name="description" placeholder="Описание"></textarea>

      <label>Фото</label>
      <input type="file" name="photo" accept="image/*">

      <button type="submit" id="submitBtn">Сохранить</button>
    </form>
  </div>

<script>
// загрузка моделей по бренду
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

// загрузка компонентов по комплексной части
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

// обработка формы
document.getElementById('addProductForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  const btn = document.getElementById('submitBtn');
  btn.disabled = true;

  try {
    const fd = new FormData(this);
    const res = await fetch(this.action, {
      method: 'POST',
      body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await res.json();

    if (data && data.ok && data.id) {
      window.location.href = `/mehanik/public/product.php?id=${data.id}`;
    } else {
      alert('Ошибка: ' + (data.error || 'Неизвестная ошибка'));
    }
  } catch (err) {
    console.error(err);
    alert('Ошибка сети при сохранении товара');
  } finally {
    btn.disabled = false;
  }
});
</script>
</body>
</html>
