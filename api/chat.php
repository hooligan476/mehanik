<?php
// mehanik/api/chat.php
// Чат (пользовательская часть) — возвращает только JSON, не делает redirect.

require_once __DIR__ . '/../db.php'; // ожидаем $mysqli

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

$user_id = $_SESSION['user']['id'] ?? null;
if (empty($user_id)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_authenticated']);
    exit;
}

function get_open_chat_id($mysqli, $user_id) {
    try {
        $st = $mysqli->prepare("SELECT id, status FROM chats WHERE user_id = ? ORDER BY id DESC LIMIT 1");
        if (!$st) return null;
        $st->bind_param('i', $user_id);
        $st->execute();
        $res = $st->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $st->close();
        if ($row && (($row['status'] ?? '') !== 'closed')) return (int)$row['id'];
    } catch (Throwable $e) {}
    return null;
}

function create_chat($mysqli, $user_id) {
    $st = $mysqli->prepare("INSERT INTO chats (user_id, status, created_at) VALUES (?, 'open', NOW())");
    if (!$st) return null;
    $st->bind_param('i', $user_id);
    $st->execute();
    $id = $mysqli->insert_id;
    $st->close();
    return $id ? (int)$id : null;
}

function ensure_chat($mysqli, $user_id) {
    $cid = get_open_chat_id($mysqli, $user_id);
    if ($cid) return $cid;
    return create_chat($mysqli, $user_id);
}

$action = $_REQUEST['action'] ?? null;
$last_id = isset($_REQUEST['last_id']) ? (int)$_REQUEST['last_id'] : 0;

/* FETCH */
if ($action === null || $action === 'fetch' || $_SERVER['REQUEST_METHOD'] === 'GET') {
    $chat_id = ensure_chat($mysqli, $user_id);
    if (!$chat_id) { echo json_encode(['ok' => false, 'error' => 'cannot_create_chat']); exit; }

    try {
        $rows = [];
        if ($last_id > 0) {
            $st = $mysqli->prepare("SELECT id, sender, content, created_at FROM messages WHERE chat_id = ? AND id > ? ORDER BY id ASC");
            $st->bind_param('ii', $chat_id, $last_id);
        } else {
            $st = $mysqli->prepare("SELECT id, sender, content, created_at FROM messages WHERE chat_id = ? ORDER BY id ASC");
            $st->bind_param('i', $chat_id);
        }
        $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) {
            $rows[] = [
                'id' => (int)$r['id'],
                'sender' => $r['sender'],
                'content' => $r['content'],
                'created_at' => $r['created_at'],
            ];
        }
        $st->close();
        echo json_encode(['ok' => true, 'chat_id' => $chat_id, 'messages' => $rows]);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'db_error', 'message' => $e->getMessage()]);
        exit;
    }
}

/* SEND */
if ($action === 'send') {
    $content = trim((string)($_POST['content'] ?? $_POST['text'] ?? ''));
    if ($content === '') { echo json_encode(['ok' => false, 'error' => 'empty_message']); exit; }

    $chat_id = ensure_chat($mysqli, $user_id);
    if (!$chat_id) { echo json_encode(['ok' => false, 'error' => 'cannot_create_chat']); exit; }

    try {
        $st = $mysqli->prepare("INSERT INTO messages (chat_id, sender, content, created_at) VALUES (?, 'user', ?, NOW())");
        if (!$st) throw new RuntimeException('prepare_failed');
        $st->bind_param('is', $chat_id, $content);
        $ok = $st->execute();
        $msg_id = $mysqli->insert_id;
        $st->close();

        if (!$ok) { echo json_encode(['ok' => false, 'error' => 'db_error']); exit; }

        $msg = null;
        $st2 = $mysqli->prepare("SELECT id, sender, content, created_at FROM messages WHERE id = ? LIMIT 1");
        if ($st2) {
            $st2->bind_param('i', $msg_id);
            $st2->execute();
            $r = $st2->get_result()->fetch_assoc();
            $st2->close();
            if ($r) $msg = ['id'=>(int)$r['id'],'sender'=>$r['sender'],'content'=>$r['content'],'created_at'=>$r['created_at']];
        }

        echo json_encode(['ok' => true, 'id' => (int)$msg_id, 'chat_id' => $chat_id, 'message' => $msg]);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'exception', 'message' => $e->getMessage()]);
        exit;
    }
}

/* CLOSE */
if ($action === 'close') {
    try {
        $chat_id = get_open_chat_id($mysqli, $user_id);
        if (!$chat_id) { echo json_encode(['ok' => true, 'msg' => 'no_chat']); exit; }
        $st = $mysqli->prepare("UPDATE chats SET status='closed' WHERE id = ? AND user_id = ?");
        $st->bind_param('ii', $chat_id, $user_id);
        $ok = $st->execute();
        $st->close();
        if ($ok) echo json_encode(['ok' => true, 'chat_id' => $chat_id]);
        else echo json_encode(['ok' => false, 'error' => 'db_error']);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'exception', 'message' => $e->getMessage()]);
        exit;
    }
}

echo json_encode(['ok' => false, 'error' => 'unknown_action']);
exit;
