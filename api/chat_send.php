<?php
// mehanik/api/chat_send.php
// Простая прокси-ручка для отправки сообщения — возвращает JSON.
// Если у вас уже есть api/chat.php, можно использовать его напрямую.
// Этот файл полезен если старый frontend вызывает chat_send.php.

require_once __DIR__ . '/chat.php'; // если chat.php уже реализует action=send и возвращает JSON, можно просто подключить
// Но безопаснее — делаем минимальную реализацию (на всякий случай), чтобы не зависеть от chat.php structure.

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json; charset=utf-8');

$user_id = $_SESSION['user']['id'] ?? null;
if (!$user_id) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_authenticated']);
    exit;
}

$content = trim((string)($_POST['content'] ?? $_POST['text'] ?? ''));
if ($content === '') {
    echo json_encode(['ok' => false, 'error' => 'empty_message']);
    exit;
}

// ensure chat exists (reuse simple logic from chat.php)
$st = $mysqli->prepare("SELECT id, status FROM chats WHERE user_id = ? ORDER BY id DESC LIMIT 1");
if ($st) {
    $st->bind_param('i', $user_id);
    $st->execute();
    $res = $st->get_result();
    $r = $res ? $res->fetch_assoc() : null;
    $st->close();
} else {
    $r = null;
}

$chat_id = null;
if ($r && ($r['status'] ?? '') !== 'closed') {
    $chat_id = (int)$r['id'];
} else {
    $ins = $mysqli->prepare("INSERT INTO chats (user_id, status, created_at) VALUES (?, 'open', NOW())");
    if ($ins) {
        $ins->bind_param('i', $user_id);
        $ins->execute();
        $chat_id = (int)$mysqli->insert_id;
        $ins->close();
    }
}

if (!$chat_id) {
    echo json_encode(['ok' => false, 'error' => 'cannot_create_chat']);
    exit;
}

$st2 = $mysqli->prepare("INSERT INTO messages (chat_id, sender, content, created_at) VALUES (?, 'user', ?, NOW())");
if (!$st2) {
    echo json_encode(['ok' => false, 'error' => 'db_prepare_failed']);
    exit;
}
$st2->bind_param('is', $chat_id, $content);
$ok = $st2->execute();
$msg_id = $mysqli->insert_id;
$st2->close();

if (!$ok) {
    echo json_encode(['ok' => false, 'error' => 'db_error']);
    exit;
}

// вернём только что созданное сообщение (удобно фронту)
$st3 = $mysqli->prepare("SELECT id, sender, content, created_at FROM messages WHERE id = ? LIMIT 1");
$msg = null;
if ($st3) {
    $st3->bind_param('i', $msg_id);
    $st3->execute();
    $res = $st3->get_result();
    $r = $res ? $res->fetch_assoc() : null;
    $st3->close();
    if ($r) $msg = ['id' => (int)$r['id'], 'sender' => $r['sender'], 'content' => $r['content'], 'created_at' => $r['created_at']];
}

echo json_encode(['ok' => true, 'id' => (int)$msg_id, 'chat_id' => $chat_id, 'message' => $msg]);
exit;
