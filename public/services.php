<?php
// public/services.php

require_once __DIR__ . '/../db.php'; // подключаем $mysqli
include __DIR__ . '/header.php';     // подключаем хедер

$search = $_GET['q'] ?? '';
$services = [];

// Используем mysqli вместо PDO
if ($stmt = $mysqli->prepare("
    SELECT id, name, description, logo, rating, phone 
    FROM services 
    WHERE status = 'approved' AND (name LIKE ? OR description LIKE ?)
")) {
    $like = "%" . $search . "%";
    $stmt->bind_param("ss", $like, $like);
    $stmt->execute();
    $res = $stmt->get_result();
    $services = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>

<div class="container" style="max-width: 900px; margin: 20px auto; color:#333;">
    <h1 style="display:flex; justify-content:space-between; align-items:center;">
        <span>Автосервисы / Услуги</span>
        <?php if (!empty($_SESSION['user'])): ?>
            <a href="add-service.php" 
               style="background:#0066cc;color:#fff;padding:6px 12px;border-radius:6px;
                      text-decoration:none;font-size:0.95rem;">
                + Добавить сервис
            </a>
        <?php endif; ?>
    </h1>

    <form method="get" action="services.php" style="margin: 15px 0;">
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Поиск..." style="padding:6px; width:250px;">
        <button type="submit" style="padding:6px 12px;">Найти</button>
    </form>

    <?php if (count($services) > 0): ?>
        <div class="services-list" style="display:grid; gap:15px;">
            <?php foreach ($services as $s): ?>
                <div class="service-card" style="border:1px solid #ccc; border-radius:8px; padding:12px; display:flex; gap:12px; align-items:center;">
                    <?php if (!empty($s['logo'])): ?>
                        <img src="uploads/<?= htmlspecialchars($s['logo']) ?>" alt="logo" style="width:80px;height:80px;object-fit:cover;border-radius:8px;">
                    <?php endif; ?>
                    <div>
                        <h3>
                            <a href="service.php?id=<?= (int)$s['id'] ?>" style="color:#0066cc; text-decoration:none;">
                                <?= htmlspecialchars($s['name']) ?>
                            </a>
                        </h3>
                        <p>Рейтинг: <?= htmlspecialchars($s['rating']) ?>/5.0</p>
                        <p>Телефон: <?= htmlspecialchars($s['phone']) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p>Сервисов не найдено.</p>
    <?php endif; ?>
</div>
