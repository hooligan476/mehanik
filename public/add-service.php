<?php
// public/add-service.php
require_once __DIR__ . '/../db.php';
include __DIR__ . '/header.php';

if (empty($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $rating = floatval($_POST['rating'] ?? 0);
    $email = trim($_POST['email'] ?? '');
    $lat = trim($_POST['latitude'] ?? null);
    $lng = trim($_POST['longitude'] ?? null);

    // Загружаем логотип
    $logoName = '';
    if (!empty($_FILES['logo']['name'])) {
        $uploadDir = __DIR__ . "/uploads/";
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
        $logoName = uniqid('logo_') . "." . $ext;
        move_uploaded_file($_FILES['logo']['tmp_name'], $uploadDir . $logoName);
    }

    if ($name && $description && $phone) {
        if ($stmt = $mysqli->prepare("
            INSERT INTO services 
            (name, description, phone, email, address, latitude, longitude, rating, logo, status, created_at, user_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), ?)
        ")) {
            $stmt->bind_param(
                "ssssssds si",
                $name,
                $description,
                $phone,
                $email,
                $address,
                $lat,
                $lng,
                $rating,
                $logoName,
                $_SESSION['user']['id']
            );
            $stmt->execute();
            $serviceId = $stmt->insert_id;
            $stmt->close();

            // Загружаем дополнительные фото
            if (!empty($_FILES['photos']['name'][0])) {
                foreach ($_FILES['photos']['name'] as $i => $photoName) {
                    $ext = pathinfo($photoName, PATHINFO_EXTENSION);
                    $fileName = uniqid('photo_') . "." . $ext;
                    move_uploaded_file($_FILES['photos']['tmp_name'][$i], $uploadDir . $fileName);

                    $stmt2 = $mysqli->prepare("INSERT INTO service_photos (service_id, photo) VALUES (?, ?)");
                    $stmt2->bind_param("is", $serviceId, $fileName);
                    $stmt2->execute();
                    $stmt2->close();
                }
            }

            $message = "Сервис успешно добавлен и ожидает модерации.";
        }
    } else {
        $message = "Заполните все обязательные поля!";
    }
}
?>

<div class="container" style="max-width:700px; margin:20px auto; color:#333;">
    <h1>Добавить сервис</h1>

    <?php if ($message): ?>
        <div style="background:#f0f0f0; padding:10px; margin-bottom:15px; border-radius:6px;">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" style="display:grid; gap:12px;">
        <label>Название*:
            <input type="text" name="name" required style="width:100%; padding:6px;">
        </label>
        <label>Описание*:
            <textarea name="description" required rows="4" style="width:100%; padding:6px;"></textarea>
        </label>
        <label>Телефон*:
            <input type="text" name="phone" required style="width:100%; padding:6px;">
        </label>
        <label>Email:
            <input type="email" name="email" style="width:100%; padding:6px;">
        </label>
        <label>Адрес:
            <input type="text" name="address" style="width:100%; padding:6px;">
        </label>

        <!-- Карта -->
        <label>Местоположение (поставьте метку на карте):</label>
        <div id="map" style="height:300px; border:1px solid #ccc; border-radius:6px;"></div>
        <input type="hidden" name="latitude" id="latitude">
        <input type="hidden" name="longitude" id="longitude">

        <label>Рейтинг (0.1–5.0):
            <input type="number" name="rating" step="0.1" min="0.1" max="5.0" style="width:100%; padding:6px;">
        </label>
        <label>Логотип:
            <input type="file" name="logo" accept="image/*">
        </label>
        <label>Фотографии (можно несколько):
            <input type="file" name="photos[]" accept="image/*" multiple>
        </label>
        <button type="submit" style="background:#0066cc;color:#fff;padding:10px;border:none;border-radius:6px;cursor:pointer;">
            Сохранить
        </button>
    </form>
</div>

<!-- Leaflet.js -->
<link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
<script>
    const map = L.map('map').setView([37.95, 58.38], 13); // Ашхабад по умолчанию
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap'
    }).addTo(map);

    let marker;

    map.on('click', function(e) {
        if (marker) {
            marker.setLatLng(e.latlng);
        } else {
            marker = L.marker(e.latlng).addTo(map);
        }
        document.getElementById('latitude').value = e.latlng.lat;
        document.getElementById('longitude').value = e.latlng.lng;
    });
</script>
