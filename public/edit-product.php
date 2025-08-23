<?php
require_once __DIR__ . '/../db.php';

// Получаем ID продукта
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    die("Неверный ID продукта");
}

// Загружаем товар
$stmt = $mysqli->prepare("
    SELECT * FROM products WHERE id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();

if (!$product) {
    die("Продукт не найден");
}

// Обработка сохранения
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $manufacturer = $_POST['manufacturer'] ?? '';
    $quality = $_POST['quality'] ?? '';
    $rating = $_POST['rating'] ?? 0;
    $availability = $_POST['availability'] ?? 0;
    $price = $_POST['price'] ?? 0;
    $description = $_POST['description'] ?? '';

    // Обновление фото (если загружено новое)
    $photoPath = $product['photo'];
    if (!empty($_FILES['photo']['name'])) {
        $uploadDir = __DIR__ . '/../uploads/products/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $fileName = time() . "_" . basename($_FILES['photo']['name']);
        $targetFile = $uploadDir . $fileName;
        if (move_uploaded_file($_FILES['photo']['tmp_name'], $targetFile)) {
            $photoPath = '/mehanik/uploads/products/' . $fileName;
        }
    }

    // Обновляем запись
    $update = $mysqli->prepare("
        UPDATE products 
        SET name=?, manufacturer=?, quality=?, rating=?, availability=?, price=?, description=?, photo=? 
        WHERE id=?
    ");
    $update->bind_param("sssdiissi", 
        $name, $manufacturer, $quality, $rating, $availability, $price, $description, $photoPath, $id
    );
    $update->execute();

    header("Location: /mehanik/public/product.php?id=" . $id);
    exit;
}

include __DIR__ . '/header.php';
?>

<div class="container mt-5">
    <h2 class="mb-4">Редактировать товар</h2>
    <form method="post" enctype="multipart/form-data" class="card p-4 shadow-lg rounded-4">
        <div class="mb-3">
            <label class="form-label">Название</label>
            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($product['name']) ?>" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Производитель</label>
            <input type="text" name="manufacturer" class="form-control" value="<?= htmlspecialchars($product['manufacturer']) ?>">
        </div>

        <div class="mb-3">
            <label class="form-label">Качество</label>
            <select name="quality" class="form-select">
                <option value="Новый" <?= $product['quality'] === 'Новый' ? 'selected' : '' ?>>Новый</option>
                <option value="Б/У" <?= $product['quality'] === 'Б/У' ? 'selected' : '' ?>>Б/У</option>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Рейтинг</label>
            <input type="number" step="0.1" min="0" max="10" name="rating" class="form-control" value="<?= htmlspecialchars($product['rating']) ?>">
        </div>

        <div class="mb-3">
            <label class="form-label">Наличие (шт)</label>
            <input type="number" name="availability" class="form-control" value="<?= htmlspecialchars($product['availability']) ?>">
        </div>

        <div class="mb-3">
            <label class="form-label">Цена (TMT)</label>
            <input type="number" step="0.01" name="price" class="form-control" value="<?= htmlspecialchars($product['price']) ?>">
        </div>

        <div class="mb-3">
            <label class="form-label">Описание</label>
            <textarea name="description" class="form-control" rows="5"><?= htmlspecialchars($product['description']) ?></textarea>
        </div>

        <div class="mb-3">
            <label class="form-label">Фото</label><br>
            <?php if ($product['photo']): ?>
                <img src="<?= htmlspecialchars($product['photo']) ?>" alt="" class="img-thumbnail mb-2" style="max-height:150px;">
            <?php endif; ?>
            <input type="file" name="photo" class="form-control">
        </div>

        <button type="submit" class="btn btn-success">💾 Сохранить</button>
        <a href="/mehanik/public/product.php?id=<?= $id ?>" class="btn btn-secondary">Отмена</a>
    </form>
</div>
