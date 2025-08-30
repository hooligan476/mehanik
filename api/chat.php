<?php
// api/chat.php
// Работа с чатами/сообщениями (пользовательская часть)
// Использует существующие таблицы `chats` и `messages` аналогично админке.

require_once __DIR__ . '/../middleware.php'; // должно дать require_auth()
require_auth();
require_once __DIR__ . '/../db.php'; // ожидаем $mysqli (как в проекте)

header('Content-Type: application/json; charset=utf-8');

$user_id = $_SESSION['user']['id'] ?? null;
if (!$user_id) {
    echo json_encode(['ok' => false, 'error' => 'not_authenticated']);
    exit;
}

// --- helpers ---
function get_open_chat_id($mysqli, $user_id) {
    $st = $mysqli->prepare("SELECT id, status FROM chats WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $st->bind_param('i', $user_id);
    $st->execute();
    $res = $st->get_result()->fetch_assoc();
    $st->close();
    if ($res && ($res['status'] ?? '') !== 'closed') return (int)$res['id'];
    return null;
}

function create_chat($mysqli, $user_id) {
    $st = $mysqli->prepare("INSERT INTO chats (user_id, status, created_at) VALUES (?, 'open', NOW())");
    $st->bind_param('i', $user_id);
    $st->execute();
    $id = $mysqli->insert_id;
    $st->close();
    return (int)$id;
}

function ensure_chat($mysqli, $user_id) {
    $cid = get_open_chat_id($mysqli, $user_id);
    if ($cid) return $cid;
    return create_chat($mysqli, $user_id);
}

// read incoming
$action = $_REQUEST['action'] ?? null; // POST or GET
$last_id = isset($_REQUEST['last_id']) ? (int)$_REQUEST['last_id'] : 0;

// --- FETCH messages (both GET and POST with action=fetch) ---
if ($action === null || $action === 'fetch' || $_SERVER['REQUEST_METHOD'] === 'GET') {
    // ensure there's a chat
    $chat_id = ensure_chat($mysqli, $user_id);

    // get messages after last_id (or all if last_id==0)
    $sql = "SELECT id, sender, content, created_at FROM messages WHERE chat_id = ? ";
    if ($last_id > 0) $sql .= " AND id > ? ";
    $sql .= " ORDER BY id ASC";
    if ($last_id > 0) {
        $st = $mysqli->prepare($sql);
        $st->bind_param('ii', $chat_id, $last_id);
    } else {
        $st = $mysqli->prepare($sql);
        $st->bind_param('i', $chat_id);
    }
    $st->execute();
    $res = $st->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = [
            'id' => (int)$r['id'],
            'sender' => $r['sender'],
            'content' => $r['content'],
            'created_at' => $r['created_at'],
        ];
    }
    $st->close();

    echo json_encode([
        'ok' => true,
        'chat_id' => $chat_id,
        'messages' => $rows
    ]);
    exit;
}

// --- SEND message (user) ---
if ($action === 'send') {
    // accept 'content' or 'text'
    $content = trim((string)($_POST['content'] ?? $_POST['text'] ?? ''));
    if ($content === '') {
        echo json_encode(['ok' => false, 'error' => 'empty_message']);
        exit;
    }

    $chat_id = ensure_chat($mysqli, $user_id);

    $st = $mysqli->prepare("INSERT INTO messages (chat_id, sender, content, created_at) VALUES (?, 'user', ?, NOW())");
    $st->bind_param('is', $chat_id, $content);
    $ok = $st->execute();
    $msg_id = $mysqli->insert_id;
    $st->close();

    if ($ok) {
        echo json_encode(['ok' => true, 'id' => (int)$msg_id, 'chat_id' => $chat_id]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'db_error']);
    }
    exit;
}

// --- CLOSE chat (user closes) ---
if ($action === 'close') {
    // закрываем последний открытый чат текущего пользователя
    $chat_id = get_open_chat_id($mysqli, $user_id);
    if (!$chat_id) {
        echo json_encode(['ok' => true, 'msg' => 'no_chat']);
        exit;
    }
    $st = $mysqli->prepare("UPDATE chats SET status='closed' WHERE id = ? AND user_id = ?");
    $st->bind_param('ii', $chat_id, $user_id);
    $ok = $st->execute();
    $st->close();
    if ($ok) echo json_encode(['ok' => true, 'chat_id' => $chat_id]);
    else echo json_encode(['ok' => false, 'error' => 'db_error']);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'unknown_action']);
exit;
