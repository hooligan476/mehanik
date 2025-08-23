<?php
require_once __DIR__ . '/../db.php';

// Получаем id из URL
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    die("Неверный ID продукта");
}

// Загружаем продукт
$stmt = $mysqli->prepare("
    SELECT p.*, 
           b.name AS brand_name, 
           m.name AS model_name, 
           cp.name AS complex_part_name,
           c.name AS component_name
    FROM products p
    LEFT JOIN brands b ON p.brand_id = b.id
    LEFT JOIN models m ON p.model_id = m.id
    LEFT JOIN complex_parts cp ON p.complex_part_id = cp.id
    LEFT JOIN components c ON p.component_id = c.id
    WHERE p.id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();

if (!$product) {
    die("Продукт не найден");
}

include __DIR__ . '/header.php';
?>

<div class="container mt-5">
    <div class="card shadow-lg border-0 rounded-4">
        <div class="row g-0">
            <!-- Фото -->
            <div class="col-md-5 text-center bg-light d-flex align-items-center">
                <?php if ($product['photo']): ?>
                    <img src="<?= htmlspecialchars($product['photo']) ?>" class="img-fluid p-3 rounded" alt="<?= htmlspecialchars($product['name']) ?>">
                <?php else: ?>
                    <img src="/mehanik/assets/no-photo.png" class="img-fluid p-3 rounded" alt="Нет фото">
                <?php endif; ?>
            </div>

            <!-- Инфо -->
            <div class="col-md-7">
                <div class="card-body">
                    <h2 class="card-title mb-3"><?= htmlspecialchars($product['name']) ?></h2>
                    
                    <ul class="list-group list-group-flush mb-3">
                        <li class="list-group-item"><strong>Код (SKU):</strong> <?= htmlspecialchars($product['sku']) ?></li>
                        <li class="list-group-item"><strong>Бренд:</strong> <?= htmlspecialchars($product['brand_name'] ?? '-') ?></li>
                        <li class="list-group-item"><strong>Модель:</strong> <?= htmlspecialchars($product['model_name'] ?? '-') ?></li>
                        <li class="list-group-item"><strong>Годы выпуска:</strong> 
                            <?= $product['year_from'] ? (int)$product['year_from'] : '-' ?> 
                            – <?= $product['year_to'] ? (int)$product['year_to'] : '-' ?>
                        </li>
                        <li class="list-group-item"><strong>Комплексная часть:</strong> <?= htmlspecialchars($product['complex_part_name'] ?? '-') ?></li>
                        <li class="list-group-item"><strong>Компонент:</strong> <?= htmlspecialchars($product['component_name'] ?? '-') ?></li>
                        <li class="list-group-item"><strong>Производитель:</strong> <?= htmlspecialchars($product['manufacturer']) ?></li>
                        <li class="list-group-item"><strong>Состояние:</strong> <?= htmlspecialchars($product['quality']) ?></li>
                        <li class="list-group-item"><strong>Качество:</strong> ⭐ <?= number_format((float)$product['rating'], 1) ?> / 10</li>
                        <li class="list-group-item"><strong>Наличие:</strong> <?= (int)$product['availability'] ?> шт.</li>
                        <li class="list-group-item"><strong>Цена:</strong> <span class="text-success fs-4"><?= number_format((float)$product['price'], 2) ?> TMT</span></li>
                        <li class="list-group-item"><strong>Дата добавления:</strong> <?= date('d.m.Y H:i', strtotime($product['created_at'])) ?></li>
                    </ul>

                    <?php if (!empty($product['description'])): ?>
                        <div class="mt-4">
                            <h5>Описание</h5>
                            <p class="border rounded p-3 bg-light"><?= nl2br(htmlspecialchars($product['description'])) ?></p>
                        </div>
                    <?php endif; ?>

                    <a href="/mehanik/public/my-products.php" class="btn btn-secondary mt-3">
                        ⬅ Назад к списку
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Похожие товары -->
    <h4 class="mt-5 mb-3">Похожие товары</h4>
    <div class="row row-cols-1 row-cols-md-4 g-3">
        <?php
        $similar_stmt = $mysqli->prepare("
            SELECT p.*, b.name AS brand_name, m.name AS model_name 
            FROM products p
            LEFT JOIN brands b ON p.brand_id = b.id
            LEFT JOIN models m ON p.model_id = m.id
            WHERE (p.model_id = ? OR p.brand_id = ?) AND p.id != ?
            ORDER BY p.created_at DESC
            LIMIT 4
        ");
        $similar_stmt->bind_param("iii", $product['model_id'], $product['brand_id'], $product['id']);
        $similar_stmt->execute();
        $similar_products = $similar_stmt->get_result();
        while($sp = $similar_products->fetch_assoc()):
        ?>
            <div class="col">
                <div class="card h-100 shadow-sm">
                    <?php if ($sp['photo']): ?>
                        <img src="<?= htmlspecialchars($sp['photo']) ?>" class="card-img-top" alt="<?= htmlspecialchars($sp['name']) ?>" style="height:180px;object-fit:cover;">
                    <?php else: ?>
                        <img src="/mehanik/assets/no-photo.png" class="card-img-top" alt="Нет фото" style="height:180px;object-fit:cover;">
                    <?php endif; ?>
                    <div class="card-body">
                        <h6 class="card-title"><?= htmlspecialchars($sp['name']) ?></h6>
                        <p class="mb-1"><strong>Цена:</strong> <?= number_format((float)$sp['price'],2) ?> TMT</p>
                        <p class="mb-0"><strong>Наличие:</strong> <?= (int)$sp['availability'] ?></p>
                        <a href="/mehanik/public/product.php?id=<?= $sp['id'] ?>" class="stretched-link"></a>
                    </div>
                </div>
            </div>
        <?php endwhile; ?>
    </div>
</div>