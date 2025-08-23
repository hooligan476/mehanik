<?php
require_once __DIR__ . '/../db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: /mehanik/public/login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id = (int)($_POST['id'] ?? 0);
    $user_id = $_SESSION['user_id'];

    if ($product_id > 0) {
        $stmt = $mysqli->prepare("DELETE FROM products WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $product_id, $user_id);
        $stmt->execute();

        // после удаления перенаправляем
        header("Location: /mehanik/public/my-products.php?deleted=1");
        exit;
    }
}

// если что-то пошло не так
header("Location: /mehanik/public/my-products.php?error=1");
exit;
