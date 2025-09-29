<?php
// mehanik/api/services-map-data.php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/../middleware.php';
require_once __DIR__ . '/../db.php';

// helper to produce public URL (копия)
function toPublicUrl($rel) {
    if (!$rel) return '';
    if (preg_match('#^https?://#i',$rel)) return $rel;
    if (strpos($rel, '/') === 0) return $rel;
    return '/mehanik/' . ltrim($rel, '/');
}

$user = $_SESSION['user'] ?? null;
$isAdmin = ($user['role'] ?? '') === 'admin';

// Вернём только записи с координатами
// Добавляем avg_rating и извлекаем "city" — первая часть address до запятой (если есть)
$sql = "SELECT s.id, s.user_id, s.name, s.description, s.contact_name, s.logo, s.rating, s.phone, s.email, s.address, 
               s.latitude, s.longitude, s.status, s.created_at,
               (SELECT AVG(r.rating) FROM service_ratings r WHERE r.service_id = s.id) AS avg_rating,
               TRIM(SUBSTRING_INDEX(s.address, ',', 1)) AS city
        FROM services s
        WHERE s.latitude IS NOT NULL AND s.longitude IS NOT NULL AND s.latitude <> '' AND s.longitude <> ''";

if (!$isAdmin) {
    $sql .= " AND (s.status = 'approved' OR s.status = 'active')";
}
$sql .= " ORDER BY s.created_at DESC LIMIT 5000";

$services = [];
if ($st = $mysqli->prepare($sql)) {
    $st->execute();
    $res = $st->get_result();
    while ($r = $res->fetch_assoc()) {
        // приведение типов для JSON
        $r['latitude']  = isset($r['latitude'])  ? (float)$r['latitude']  : null;
        $r['longitude'] = isset($r['longitude']) ? (float)$r['longitude'] : null;
        $r['avg_rating'] = isset($r['avg_rating']) && $r['avg_rating'] !== null ? (float)round($r['avg_rating'],2) : 0.0;
        $r['logo'] = $r['logo'] ? toPublicUrl($r['logo']) : '';
        $r['city'] = $r['city'] ?? '';
        $services[] = $r;
    }
    $st->close();
} else {
    echo json_encode(['ok' => false, 'error' => 'prepare_failed','sql_error'=>$mysqli->error], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true, 'services' => $services], JSON_UNESCAPED_UNICODE);
