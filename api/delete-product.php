<?php
// public/delete-product.php
require_once __DIR__ . '/../middleware.php';
require_auth();
require_once __DIR__ . '/../db.php';

// Разрешаем только POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /mehanik/public/my-products.php');
    exit;
}

$product_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$user_id    = $_SESSION['user']['id'] ?? 0;

if ($product_id <= 0 || $user_id <= 0) {
    header('Location: /mehanik/public/my-products.php?error=bad_request');
    exit;
}

// === 1. Достаём фото товара ===
$stmt = $mysqli->prepare("SELECT photo FROM products WHERE id = ? AND user_id = ?");
$stmt->bind_param('ii', $product_id, $user_id);
$stmt->execute();
$res = $stmt->get_result();
$product = $res->fetch_assoc();
$stmt->close();

if (!$product) {
    header('Location: /mehanik/public/my-products.php?error=not_found');
    exit;
}

$photoPath = $product['photo'] ?? null;

// === 2. Удаляем запись из БД ===
$stmt = $mysqli->prepare("DELETE FROM products WHERE id = ? AND user_id = ?");
$stmt->bind_param('ii', $product_id, $user_id);
$stmt->execute();
$deleted = $stmt->affected_rows > 0;
$stmt->close();

// === 3. Если удалено — чистим фото ===
if ($deleted && $photoPath) {
    // $photoPath в БД хранится как "/mehanik/uploads/products/000000123.jpg"
    $absolutePath = __DIR__ . '/..' . str_replace('/mehanik', '', $photoPath);

    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
    header('Location: /mehanik/public/my-products.php?deleted=1');
    exit;
}

// если не удалено
header('Location: /mehanik/public/my-products.php?error=delete_fail');
exit;
