<?php
// mehanik/api/services.php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/../db.php';

$format = strtolower(trim($_GET['format'] ?? 'json'));
$q = trim($_GET['q'] ?? '');
$sort = trim($_GET['sort'] ?? 'created_desc');
$user = $_SESSION['user'] ?? null;
$isAdmin = ($user['role'] ?? '') === 'admin';

$allowedSort = ['created_desc','created_asc','rating_desc','rating_asc'];
if (!in_array($sort, $allowedSort, true)) $sort = 'created_desc';
switch ($sort) {
    case 'rating_desc': $order = "ORDER BY COALESCE(avg_rating,0) DESC, s.created_at DESC"; break;
    case 'rating_asc': $order = "ORDER BY COALESCE(avg_rating,0) ASC, s.created_at DESC"; break;
    case 'created_asc': $order = "ORDER BY s.created_at ASC"; break;
    default: $order = "ORDER BY s.created_at DESC"; break;
}

if ($isAdmin) {
    $sql = "
      SELECT s.id, s.user_id, s.name, s.description, s.logo, s.contact_name, s.phone, s.email, s.address, s.status, s.created_at,
        (SELECT AVG(r.rating) FROM service_ratings r WHERE r.service_id = s.id) AS avg_rating,
        (SELECT COUNT(*) FROM service_reviews r WHERE r.service_id = s.id) AS reviews_count
      FROM services s
      WHERE (s.name LIKE ? OR s.description LIKE ?)
      {$order}
      LIMIT 500
    ";
} else {
    $sql = "
      SELECT s.id, s.user_id, s.name, s.description, s.logo, s.contact_name, s.phone, s.email, s.address, s.status, s.created_at,
        (SELECT AVG(r.rating) FROM service_ratings r WHERE r.service_id = s.id) AS avg_rating,
        (SELECT COUNT(*) FROM service_reviews r WHERE r.service_id = s.id) AS reviews_count
      FROM services s
      WHERE (s.status = 'approved' OR s.status = 'active')
        AND (s.name LIKE ? OR s.description LIKE ?)
      {$order}
      LIMIT 500
    ";
}

$out = ['success' => true, 'data' => []];
if ($st = $mysqli->prepare($sql)) {
    $like = '%' . $q . '%';
    $st->bind_param('ss', $like, $like);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $st->close();

    foreach ($rows as $r) {
        $logo = null;
        if (!empty($r['logo'])) {
            if (file_exists(__DIR__ . '/../public/uploads/services/' . $r['logo'])) $logo = '/mehanik/uploads/services/' . rawurlencode($r['logo']);
            elseif (file_exists(__DIR__ . '/../public/uploads/' . $r['logo'])) $logo = '/mehanik/uploads/' . rawurlencode($r['logo']);
        }
        $out['data'][] = [
            'id' => (int)$r['id'],
            'user_id' => (int)($r['user_id'] ?? 0),
            'name' => $r['name'],
            'description' => $r['description'],
            'logo' => $logo,
            'contact_name' => $r['contact_name'] ?? '',
            'address' => $r['address'] ?? '',
            'status' => $r['status'] ?? '',
            'created_at' => $r['created_at'] ?? '',
            'avg_rating' => isset($r['avg_rating']) && $r['avg_rating'] !== null ? round((float)$r['avg_rating'],1) : 0.0,
            'reviews_count' => (int)($r['reviews_count'] ?? 0),
        ];
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
exit;
