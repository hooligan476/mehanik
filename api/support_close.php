<?php
// mehanik/api/support_close.php
// POST: chat_id, rating (1-5), comment
// Требует авторизации пользователя (session)

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user']['id'])) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../db.php'; // должен создать $mysqli

$uid = (int)$_SESSION['user']['id'];
$chat_id = isset($_POST['chat_id']) ? (int)$_POST['chat_id'] : 0;
$rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
$comment = isset($_POST['comment']) ? trim((string)$_POST['comment']) : '';

if ($chat_id <= 0) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>'Invalid chat_id']);
    exit;
}
if ($rating < 1 || $rating > 5) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>'Rating must be 1..5']);
    exit;
}
if (mb_strlen($comment) > 2000) {
    $comment = mb_substr($comment, 0, 2000);
}

try {
    // Проверим, что чат принадлежит пользователю
    $st = $mysqli->prepare("SELECT id, status, accepted_by FROM chats WHERE id = ? LIMIT 1");
    $st->bind_param('i', $chat_id);
    $st->execute();
    $res = $st->get_result();
    $chat = $res ? $res->fetch_assoc() : null;
    $st->close();

    if (!$chat) {
        http_response_code(404);
        echo json_encode(['ok'=>false,'error'=>'Chat not found']);
        exit;
    }

    // optional: в таблице chats есть поле user_id - проверим принадлежность
    $st2 = $mysqli->prepare("SELECT user_id FROM chats WHERE id = ? LIMIT 1");
    $st2->bind_param('i', $chat_id);
    $st2->execute();
    $r2 = $st2->get_result()->fetch_assoc();
    $st2->close();
    $ownerId = (int)($r2['user_id'] ?? 0);
    if ($ownerId !== $uid) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'You are not the owner of this chat']);
        exit;
    }

    // Закроем чат (status='closed') и сохраним отзыв
    $mysqli->begin_transaction();

    $st3 = $mysqli->prepare("UPDATE chats SET status = 'closed', closed_at = NOW() WHERE id = ? LIMIT 1");
    $st3->bind_param('i', $chat_id);
    $st3->execute();
    $st3->close();

    // Создаём (если не существует) таблицу support_reviews — НЕ критично, но рекомендуется сделать миграцию вместо авто-create.
    // Ниже — попытка создать таблицу, если её нет (без прерывания, если нет прав)
    try {
        $mysqli->query("
          CREATE TABLE IF NOT EXISTS support_reviews (
            id INT AUTO_INCREMENT PRIMARY KEY,
            chat_id INT NOT NULL,
            user_id INT NOT NULL,
            admin_id INT NULL,
            rating TINYINT NOT NULL,
            comment TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (chat_id),
            INDEX (user_id)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    } catch (Throwable $e) {
        // ignore
    }

    $st4 = $mysqli->prepare("INSERT INTO support_reviews (chat_id, user_id, admin_id, rating, comment) VALUES (?, ?, ?, ?, ?)");
    // определим admin_id из accepted_by в chats (если есть)
    $admin_id = !empty($chat['accepted_by']) ? (int)$chat['accepted_by'] : null;
    // bind params: i i i i s  -> but admin_id nullable; use appropriate binding
    if ($admin_id === null) {
        // bind null as NULL
        $stmt = $mysqli->prepare("INSERT INTO support_reviews (chat_id, user_id, admin_id, rating, comment) VALUES (?, ?, NULL, ?, ?)");
        $stmt->bind_param('iis', $chat_id, $uid, $rating, $comment);
        $stmt->execute();
        $stmt->close();
    } else {
        $st4->bind_param('iiiss', $chat_id, $uid, $admin_id, $rating, $comment);
        // Note: 'iiiss' incorrect (we need ints and string): let's fallback to simple prepared dynamic approach:
        $st4->close();
        $stmt = $mysqli->prepare("INSERT INTO support_reviews (chat_id, user_id, admin_id, rating, comment) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('iiiis', $chat_id, $uid, $admin_id, $rating, $comment);
        $stmt->execute();
        $stmt->close();
    }

    $mysqli->commit();

    // OK
    echo json_encode(['ok'=>true,'closed_at'=>date('Y-m-d H:i:s')]);
    exit;

} catch (Throwable $e) {
    if (isset($mysqli) && $mysqli->in_transaction) $mysqli->rollback();
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Server error: '.$e->getMessage()]);
    exit;
}
