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

/* CLOSE - now supports rating + comment saving into chat_reviews */
if ($action === 'close') {
    try {
        $chat_id = get_open_chat_id($mysqli, $user_id);
        if (!$chat_id) {
            // nothing to close
            echo json_encode(['ok' => true, 'msg' => 'no_chat']);
            exit;
        }

        // optional rating and comment from client
        $ratingRaw = isset($_POST['rating']) ? trim((string)$_POST['rating']) : null;
        $commentRaw = isset($_POST['comment']) ? trim((string)$_POST['comment']) : null;

        // normalize rating: allow numeric 1..5 (int or float), else null
        $rating = null;
        if ($ratingRaw !== null && $ratingRaw !== '') {
            // try float/int
            if (is_numeric($ratingRaw)) {
                $rnum = (float)$ratingRaw;
                // clamp to 1..5
                if ($rnum < 1) $rnum = 1;
                if ($rnum > 5) $rnum = 5;
                // store as INT rating (1-5) — you can change to DECIMAL if you prefer
                $rating = (int)round($rnum);
            }
        }

        $comment = ($commentRaw !== null && $commentRaw !== '') ? $commentRaw : null;

        // Ensure chat_reviews table exists (best-effort)
        try {
            $createSql = "
                CREATE TABLE IF NOT EXISTS chat_reviews (
                  id INT AUTO_INCREMENT PRIMARY KEY,
                  chat_id INT NOT NULL,
                  user_id INT NOT NULL,
                  rating TINYINT NULL,
                  comment TEXT NULL,
                  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                  INDEX (chat_id),
                  INDEX (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ";
            $mysqli->query($createSql);
        } catch (Throwable $e) {
            // ignore: if cannot create, we'll still try to continue (review saving may fail)
        }

        // Use transaction: update chats -> insert review (if provided)
        $mysqli->begin_transaction();

        // close chat and set closed_at
        $upd = $mysqli->prepare("UPDATE chats SET status='closed', closed_at = NOW() WHERE id = ? AND user_id = ?");
        if (!$upd) {
            $mysqli->rollback();
            echo json_encode(['ok' => false, 'error' => 'db_prepare_failed_close']);
            exit;
        }
        $upd->bind_param('ii', $chat_id, $user_id);
        $ok = $upd->execute();
        $upd->close();

        if (!$ok) {
            $mysqli->rollback();
            echo json_encode(['ok' => false, 'error' => 'db_error_closing_chat']);
            exit;
        }

        // optionally insert review only if rating or comment present
        $savedReview = null;
        if ($rating !== null || $comment !== null) {
            $ins = $mysqli->prepare("INSERT INTO chat_reviews (chat_id, user_id, rating, comment, created_at) VALUES (?, ?, ?, ?, NOW())");
            if ($ins) {
                // bind rating as i or null; use 's' for comment
                // MySQLi requires passing nulls explicitly: use bind_param with types "iiss" but rating may be null,
                // So we'll convert rating to NULL via variable and use 'i' type; when rating is null, pass null (bind_param converts to '')
                // Better approach: bind with types 'iiss' and set $ratingVal to either int or null -> use bind_param and pass $ratingVal and if null pass null via variable set to null.
                // But mysqli::bind_param doesn't accept null typed for integers well; workaround: use separate query building.
                // Simpler: prepare with placeholders and use bind_param where rating is int or NULL converted to NULL via binding with 'i' and passing NULL is allowed.
                // We'll attempt bind_param and if rating is null, use NULL (bind_param accepts nulls).
                $ratingParam = $rating;
                $commentParam = $comment;
                $ins->bind_param('iiss', $chat_id, $user_id, $ratingParam, $commentParam);
                $insOk = $ins->execute();
                $reviewId = (int)$ins->insert_id;
                $ins->close();
                if ($insOk) {
                    // fetch saved review
                    $rst = $mysqli->prepare("SELECT id, chat_id, user_id, rating, comment, created_at FROM chat_reviews WHERE id = ? LIMIT 1");
                    if ($rst) {
                        $rst->bind_param('i', $reviewId);
                        $rst->execute();
                        $rr = $rst->get_result()->fetch_assoc();
                        $rst->close();
                        if ($rr) {
                            $savedReview = [
                                'id' => (int)$rr['id'],
                                'chat_id' => (int)$rr['chat_id'],
                                'user_id' => (int)$rr['user_id'],
                                'rating' => $rr['rating'] !== null ? (int)$rr['rating'] : null,
                                'comment' => $rr['comment'],
                                'created_at' => $rr['created_at']
                            ];
                        }
                    }
                } else {
                    // insertion failed -> continue but note it
                    // we'll not abort closing chat because of review insert failure
                }
            } else {
                // cannot prepare insert; ignore review saving
            }
        }

        // fetch closed_at from DB
        $closedAt = null;
        $stc = $mysqli->prepare("SELECT closed_at FROM chats WHERE id = ? LIMIT 1");
        if ($stc) {
            $stc->bind_param('i', $chat_id);
            $stc->execute();
            $rcl = $stc->get_result()->fetch_assoc();
            if ($rcl && !empty($rcl['closed_at'])) $closedAt = $rcl['closed_at'];
            $stc->close();
        }

        $mysqli->commit();

        $resp = ['ok' => true, 'chat_id' => $chat_id, 'closed_at' => $closedAt];
        if ($savedReview !== null) $resp['review'] = $savedReview;

        echo json_encode($resp);
        exit;
    } catch (Throwable $e) {
        // try rollback if transaction active
        if ($mysqli && $mysqli->connect_errno === 0) {
            @$mysqli->rollback();
        }
        echo json_encode(['ok' => false, 'error' => 'exception', 'message' => $e->getMessage()]);
        exit;
    }
}

echo json_encode(['ok' => false, 'error' => 'unknown_action']);
exit;
