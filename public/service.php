<?php
// public/service.php
require_once __DIR__ . '/../db.php';
include __DIR__ . '/header.php';

$id = (int)($_GET['id'] ?? 0);

$service = null;
$photos = [];

if ($id > 0) {
    // Получаем сервис
    if ($stmt = $mysqli->prepare("
        SELECT id, name, description, logo, rating, phone, email, address, latitude, longitude 
        FROM services 
        WHERE id = ? AND status = 'approved'
    ")) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $service = $res->fetch_assoc();
        $stmt->close();
    }

    // Получаем фото
    if ($stmt = $mysqli->prepare("SELECT photo FROM service_photos WHERE service_id = ?")) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $photos = $res->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}
?>

<div class="container" style="max-width:900px; margin:20px auto; color:#333;">
    <?php if ($service): ?>
        <h1><?= htmlspecialchars($service['name']) ?></h1>

        <div style="display:flex; gap:20px; margin-bottom:20px;">
            <?php if (!empty($service['logo'])): ?>
                <img src="uploads/<?= htmlspecialchars($service['logo']) ?>" 
                     alt="logo" 
                     style="width:150px; height:150px; object-fit:cover; border-radius:10px; border:1px solid #ccc;">
            <?php endif; ?>

            <div>
                <p><b>Описание:</b><br><?= nl2br(htmlspecialchars($service['description'])) ?></p>
                <p><b>Телефон:</b> <?= htmlspecialchars($service['phone']) ?></p>
                <?php if (!empty($service['email'])): ?>
                    <p><b>Email:</b> <?= htmlspecialchars($service['email']) ?></p>
                <?php endif; ?>
                <?php if (!empty($service['address'])): ?>
                    <p><b>Адрес:</b> <?= htmlspecialchars($service['address']) ?></p>
                <?php endif; ?>
                <p><b>Рейтинг:</b> <?= htmlspecialchars($service['rating']) ?>/5.0</p>
            </div>
        </div>

        <!-- Карта -->
        <h2>Местоположение</h2>
        <div id="map" style="height:350px; border:1px solid #ccc; border-radius:6px; margin-bottom:20px;"></div>

        <!-- Галерея фото -->
        <?php if (count($photos) > 0): ?>
            <h2>Фотографии</h2>
            <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:15px;">
                <?php foreach ($photos as $p): ?>
                    <img src="uploads/<?= htmlspecialchars($p['photo']) ?>" 
                         alt="photo" 
                         style="width:100%; height:180px; object-fit:cover; border-radius:8px; border:1px solid #ccc;">
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <p>Сервис не найден или ещё не одобрен.</p>
    <?php endif; ?>
</div>

<!-- Leaflet.js -->
<link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
<script>
<?php if ($service && !empty($service['latitude']) && !empty($service['longitude'])): ?>
    const map = L.map('map').setView([<?= $service['latitude'] ?>, <?= $service['longitude'] ?>], 15);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap'
    }).addTo(map);

    L.marker([<?= $service['latitude'] ?>, <?= $service['longitude'] ?>]).addTo(map)
        .bindPopup("<?= htmlspecialchars($service['name']) ?>")
        .openPopup();
<?php endif; ?>
</script>
