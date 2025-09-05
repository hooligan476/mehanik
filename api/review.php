<?php
session_start();
require_once __DIR__ . '/../middleware.php';
require_once __DIR__ . '/../db.php';

$user = $_SESSION['user'] ?? null;
$userId = (int)($user['id'] ?? 0);
$isAdmin = ($user['role'] ?? '') === 'admin';

if (!$userId) {
    http_response_code(403);
    echo json_encode(['error' => 'Требуется вход']);
    exit;
}

$serviceId = (int)($_POST['service_id'] ?? 0);
$parentId  = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
$reviewId  = (int)($_POST['editing_review_id'] ?? 0);
$content   = trim($_POST['content'] ?? '');
$rating    = isset($_POST['rating']) ? (int)$_POST['rating'] : null;

if (!$serviceId || $content === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Нет данных']);
    exit;
}

// === UPDATE REVIEW (если editing_review_id) ===
if ($reviewId > 0) {
    $st = $mysqli->prepare("SELECT user_id FROM service_reviews WHERE id=? LIMIT 1");
    $st->bind_param('i', $reviewId);
    $st->execute();
    $owner = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$owner) {
        http_response_code(404);
        echo json_encode(['error' => 'Отзыв не найден']);
        exit;
    }

    if (!$isAdmin && $owner['user_id'] != $userId) {
        http_response_code(403);
        echo json_encode(['error' => 'Нет прав']);
        exit;
    }

    $st = $mysqli->prepare("UPDATE service_reviews SET content=?, rating=?, updated_at=NOW() WHERE id=?");
    $st->bind_param('sii', $content, $rating, $reviewId);
    $st->execute();
    $st->close();

    echo json_encode(['success' => true, 'message' => 'Отзыв обновлён']);
    exit;
}

// === NEW REVIEW ===
$st = $mysqli->prepare("INSERT INTO service_reviews (service_id, user_id, content, rating, parent_id, created_at) VALUES (?,?,?,?,?,NOW())");
$st->bind_param('iisii', $serviceId, $userId, $content, $rating, $parentId);
$st->execute();
$st->close();

echo json_encode(['success' => true, 'message' => 'Отзыв добавлен']);
