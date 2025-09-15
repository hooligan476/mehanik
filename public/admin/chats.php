<?php
// public/admin/chats.php
// Админ: управление чатами поддержки — новые / принятые / закрытые + AJAX drawer + polling refresh
// Исправление: переключатель табов и кнопка "Открыть" — теперь надёжно работают (делегирование), кнопки имеют type="button"

require_once __DIR__ . '/../../middleware.php';
require_admin();
require_once __DIR__ . '/../../db.php';

$adminId = (int)($_SESSION['user']['id'] ?? 0);
$adminName = $_SESSION['user']['name'] ?? ('admin#'.$adminId);
$isSuper = (($_SESSION['user']['role'] ?? '') === 'superadmin') || (!empty($_SESSION['user']['is_superadmin']) && (int)$_SESSION['user']['is_superadmin'] === 1);

$msg = '';
$err = '';

/* ----------------- Best-effort schema fixes (non-fatal) ----------------- */
try {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $col = $mysqli->query("SHOW COLUMNS FROM `chats` LIKE 'accepted_by'");
        if (!$col || $col->num_rows === 0) {
            @$mysqli->query("ALTER TABLE `chats` 
                ADD COLUMN `accepted_by` INT NULL DEFAULT NULL AFTER `user_id`,
                ADD COLUMN `accepted_at` DATETIME NULL DEFAULT NULL AFTER `accepted_by`");
        }
        $col2 = $mysqli->query("SHOW COLUMNS FROM `chats` LIKE 'closed_at'");
        if (!$col2 || $col2->num_rows === 0) {
            @ $mysqli->query("ALTER TABLE `chats` ADD COLUMN `closed_at` DATETIME NULL AFTER `accepted_at`");
        }
    }
} catch (Throwable $e) {
    // silent
}

/* ----------------- Helper to fetch lists for AJAX refresh ----------------- */
function fetch_lists($mysqli, $adminId, $isSuper) {
    $out = ['new'=>[], 'accepted'=>[], 'closed'=>[]];

    // NEW: not accepted, not closed
    $newSql = "
      SELECT c.id, c.user_id, u.phone, c.status, c.created_at
      FROM chats c
      JOIN users u ON u.id = c.user_id
      WHERE (c.accepted_by IS NULL OR c.accepted_by = 0) AND (c.status IS NULL OR c.status <> 'closed')
      ORDER BY c.id DESC
    ";
    if ($res = $mysqli->query($newSql)) {
        while ($r = $res->fetch_assoc()) {
            $out['new'][] = $r;
        }
    }

    // ACCEPTED: depends on admin
    if ($isSuper) {
        $acceptedSql = "
          SELECT c.id, c.user_id, u.phone, c.status, c.accepted_by, c.accepted_at, a.name AS admin_name, c.created_at
          FROM chats c
          JOIN users u ON u.id = c.user_id
          LEFT JOIN users a ON a.id = c.accepted_by
          WHERE c.accepted_by IS NOT NULL AND c.accepted_by <> 0 AND (c.status IS NULL OR c.status <> 'closed')
          ORDER BY c.accepted_at DESC, c.id DESC
        ";
        if ($res = $mysqli->query($acceptedSql)) {
            while ($r = $res->fetch_assoc()) $out['accepted'][] = $r;
        }
    } else {
        $st = $mysqli->prepare("
          SELECT c.id, c.user_id, u.phone, c.status, c.accepted_by, c.accepted_at, a.name AS admin_name, c.created_at
          FROM chats c
          JOIN users u ON u.id = c.user_id
          LEFT JOIN users a ON a.id = c.accepted_by
          WHERE c.accepted_by = ? AND (c.status IS NULL OR c.status <> 'closed')
          ORDER BY c.accepted_at DESC, c.id DESC
        ");
        $st->bind_param('i', $adminId);
        $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) $out['accepted'][] = $r;
        $st->close();
    }

    // CLOSED: archive
    if ($isSuper) {
        $closedSql = "
          SELECT c.id, c.user_id, u.phone, c.status, c.accepted_by, c.accepted_at, c.closed_at, a.name AS admin_name
          FROM chats c
          JOIN users u ON u.id = c.user_id
          LEFT JOIN users a ON a.id = c.accepted_by
          WHERE c.status = 'closed'
          ORDER BY c.closed_at DESC, c.id DESC
        ";
        if ($res = $mysqli->query($closedSql)) {
            while ($r = $res->fetch_assoc()) $out['closed'][] = $r;
        }
    } else {
        $st = $mysqli->prepare("
          SELECT c.id, c.user_id, u.phone, c.status, c.accepted_by, c.accepted_at, c.closed_at, a.name AS admin_name
          FROM chats c
          JOIN users u ON u.id = c.user_id
          LEFT JOIN users a ON a.id = c.accepted_by
          WHERE c.status = 'closed' AND (c.accepted_by = ? OR c.accepted_by IS NULL)
          ORDER BY c.closed_at DESC, c.id DESC
        ");
        $st->bind_param('i', $adminId);
        $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) $out['closed'][] = $r;
        $st->close();
    }

    return $out;
}

/* ========================= AJAX: refresh lists =========================
   GET ?ajax=1&refresh=1 -> returns JSON with arrays new/accepted/closed
*/
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax']) && $_GET['ajax'] === '1' && !empty($_GET['refresh'])) {
    header('Content-Type: application/json; charset=utf-8');
    $lists = fetch_lists($mysqli, $adminId, $isSuper);
    echo json_encode(['ok'=>true,'lists'=>$lists]);
    exit;
}

/* ========================= AJAX: GET chat + messages =========================
   Request: GET ?reply=<id>&ajax=1
*/
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['reply']) && (!empty($_GET['ajax']) && $_GET['ajax'] === '1')) {
    $chat_id = (int)$_GET['reply'];
    header('Content-Type: application/json; charset=utf-8');

    if ($chat_id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid chat id']);
        exit;
    }

    // получим инфо о чате и кто его принял (включая closed_at)
    $st = $mysqli->prepare("SELECT c.id, c.user_id, u.phone, c.status, c.accepted_by, c.accepted_at, c.closed_at, a.name AS admin_name FROM chats c JOIN users u ON u.id = c.user_id LEFT JOIN users a ON a.id = c.accepted_by WHERE c.id = ? LIMIT 1");
    $st->bind_param('i', $chat_id);
    $st->execute();
    $chatInfo = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$chatInfo) {
        echo json_encode(['ok' => false, 'error' => 'Chat not found']);
        exit;
    }

    // fetch messages
    $msgs = [];
    if ($rs = $mysqli->prepare("SELECT id, sender, content, created_at FROM messages WHERE chat_id = ? ORDER BY id ASC")) {
        $rs->bind_param('i', $chat_id);
        $rs->execute();
        $res = $rs->get_result();
        while ($m = $res->fetch_assoc()) {
            $msgs[] = [
                'id' => (int)$m['id'],
                'sender' => $m['sender'],
                'content' => $m['content'],
                'created_at' => $m['created_at']
            ];
        }
        $rs->close();
    }

    // fetch latest review if table exists
    $review = null;
    $check = $mysqli->query("SHOW TABLES LIKE 'chat_reviews'");
    if ($check && $check->num_rows > 0) {
        if ($rvst = $mysqli->prepare("SELECT id, user_id, rating, comment, created_at FROM chat_reviews WHERE chat_id = ? ORDER BY id DESC LIMIT 1")) {
            $rvst->bind_param('i', $chat_id);
            $rvst->execute();
            $r = $rvst->get_result()->fetch_assoc();
            if ($r) {
                $review = [
                    'id' => (int)$r['id'],
                    'user_id' => (int)$r['user_id'],
                    'rating' => $r['rating'] !== null ? (int)$r['rating'] : null,
                    'comment' => $r['comment'],
                    'created_at' => $r['created_at']
                ];
            }
            $rvst->close();
        }
    }

    $chatAcceptedBy = !empty($chatInfo['accepted_by']) ? (int)$chatInfo['accepted_by'] : 0;
    $youCanAct = $isSuper || ($chatAcceptedBy > 0 && $chatAcceptedBy === $adminId);

    $out = [
        'ok' => true,
        'chat' => [
            'id' => (int)$chatInfo['id'],
            'user_id' => (int)$chatInfo['user_id'],
            'phone' => $chatInfo['phone'],
            'status' => $chatInfo['status'],
            'accepted_by' => $chatAcceptedBy,
            'accepted_name' => $chatInfo['admin_name'] ?? null,
            'accepted_at' => $chatInfo['accepted_at'] ?? null,
            'closed_at' => $chatInfo['closed_at'] ?? null,
        ],
        'messages' => $msgs,
        'review' => $review,
        'youCanAct' => $youCanAct,
        'isSuper' => $isSuper
    ];

    echo json_encode($out);
    exit;
}

/* ========================= AJAX: send message (support) =========================
   POST (ajax=1, send=1, chat_id, content) -> returns created message JSON
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax']) && $_POST['ajax'] === '1' && !empty($_POST['send'])) {
    header('Content-Type: application/json; charset=utf-8');
    $chat_id = (int)($_POST['chat_id'] ?? 0);
    $content = trim((string)($_POST['content'] ?? ''));

    if ($chat_id <= 0 || $content === '') {
        echo json_encode(['ok' => false, 'error' => 'Invalid parameters']);
        exit;
    }

    // кто назначен на чат
    $acceptedBy = 0;
    if ($st2 = $mysqli->prepare("SELECT accepted_by, status FROM chats WHERE id = ? LIMIT 1")) {
        $st2->bind_param('i', $chat_id);
        $st2->execute();
        $r = $st2->get_result()->fetch_assoc();
        $acceptedBy = $r ? (int)($r['accepted_by'] ?? 0) : 0;
        $chatStatus = $r ? ($r['status'] ?? '') : '';
        $st2->close();
    } else {
        $chatStatus = '';
    }

    if ($chatStatus === 'closed') {
        echo json_encode(['ok' => false, 'error' => 'Chat is closed']);
        exit;
    }

    if (!($isSuper || ($acceptedBy > 0 && $acceptedBy === $adminId))) {
        echo json_encode(['ok' => false, 'error' => 'You are not assigned to this chat.']);
        exit;
    }

    try {
        $stmt = $mysqli->prepare("INSERT INTO messages(chat_id,sender,content,created_at) VALUES (?, 'support', ?, NOW())");
        $stmt->bind_param('is', $chat_id, $content);
        $stmt->execute();
        $insertId = (int)$stmt->insert_id;
        $stmt->close();

        // fetch created_at
        $created_at = date('Y-m-d H:i:s');
        if ($st3 = $mysqli->prepare("SELECT created_at FROM messages WHERE id = ? LIMIT 1")) {
            $st3->bind_param('i', $insertId);
            $st3->execute();
            $r2 = $st3->get_result()->fetch_assoc();
            if ($r2 && !empty($r2['created_at'])) $created_at = $r2['created_at'];
            $st3->close();
        }

        echo json_encode(['ok' => true, 'message' => [
            'id' => $insertId,
            'sender' => 'support',
            'content' => $content,
            'created_at' => $created_at
        ]]);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'DB error: ' . $e->getMessage()]);
        exit;
    }
}

/* ========================= AJAX: claim / accept chat =========================
   POST (ajax=1, claim=1, chat_id)
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax']) && $_POST['ajax'] === '1' && !empty($_POST['claim'])) {
    header('Content-Type: application/json; charset=utf-8');
    $chat_id = (int)($_POST['chat_id'] ?? 0);
    if ($chat_id <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid chat id']); exit; }

    try {
        $st = $mysqli->prepare("UPDATE chats SET accepted_by = ?, accepted_at = NOW(), status = 'accepted' WHERE id = ? AND (accepted_by IS NULL OR accepted_by = 0) AND (status IS NULL OR status <> 'closed') LIMIT 1");
        $st->bind_param('ii', $adminId, $chat_id);
        $st->execute();
        $affected = $st->affected_rows;
        $st->close();

        if ($affected > 0) {
            echo json_encode(['ok'=>true]);
        } else {
            echo json_encode(['ok'=>false,'error'=>'Failed to claim — might be already accepted or closed']);
        }
        exit;
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>'DB error: '.$e->getMessage()]);
        exit;
    }
}

/* === Non-AJAX POST handlers (forms from the page) === */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $chat_id = (int)($_POST['chat_id'] ?? 0);

    // Accept (non-AJAX)
    if (!empty($_POST['accept'])) {
        if ($chat_id <= 0) {
            $err = 'Неправильный ID чата.';
        } else {
            try {
                $st = $mysqli->prepare("UPDATE chats SET accepted_by = ?, accepted_at = NOW(), status = 'accepted' WHERE id = ? AND (accepted_by IS NULL OR accepted_by = 0) AND (status IS NULL OR status <> 'closed') LIMIT 1");
                $st->bind_param('ii', $adminId, $chat_id);
                $st->execute();
                if ($st->affected_rows > 0) {
                    $msg = "Чат #{$chat_id} принят вами (" . htmlspecialchars($adminName, ENT_QUOTES) . ").";
                } else {
                    $err = "Не удалось принять чат — возможно, он уже принят другим админом или закрыт.";
                }
                $st->close();
            } catch (Throwable $e) {
                $err = 'DB error: ' . $e->getMessage();
            }
        }
    }

    // Send (non-AJAX)
    if (!empty($_POST['send']) && !empty($_POST['content']) && empty($_POST['ajax'])) {
        $content = trim((string)$_POST['content']);
        if ($chat_id <= 0 || $content === '') {
            $err = 'Неверные параметры для отправки.';
        } else {
            $acceptedBy = null;
            if ($chat_id > 0) {
                try {
                    $st2 = $mysqli->prepare("SELECT accepted_by FROM chats WHERE id = ? LIMIT 1");
                    $st2->bind_param('i', $chat_id);
                    $st2->execute();
                    $res = $st2->get_result();
                    $r = $res ? $res->fetch_assoc() : null;
                    $acceptedBy = $r ? (int)($r['accepted_by'] ?? 0) : null;
                    $st2->close();
                } catch (Throwable $e) {
                    // ignore
                }
            }

            if (!$isSuper && !($acceptedBy > 0 && $acceptedBy === $adminId)) {
                $err = 'Вы не назначены на этот чат — примите его, чтобы отвечать.';
            } else {
                try {
                    $stmt = $mysqli->prepare("INSERT INTO messages(chat_id,sender,content,created_at) VALUES (?, 'support', ?, NOW())");
                    $stmt->bind_param('is', $chat_id, $content);
                    $stmt->execute();
                    $stmt->close();
                    header("Location: chats.php?reply=".$chat_id);
                    exit;
                } catch (Throwable $e) {
                    $err = 'DB error: ' . $e->getMessage();
                }
            }
        }
    }

    // Close (non-AJAX)
    if (!empty($_POST['close'])) {
        if ($chat_id <= 0) {
            $err = 'Неверный ID для закрытия.';
        } else {
            $acceptedBy = null;
            if ($chat_id > 0) {
                try {
                    $st2 = $mysqli->prepare("SELECT accepted_by FROM chats WHERE id = ? LIMIT 1");
                    $st2->bind_param('i', $chat_id);
                    $st2->execute();
                    $res = $st2->get_result();
                    $r = $res ? $res->fetch_assoc() : null;
                    $acceptedBy = $r ? (int)($r['accepted_by'] ?? 0) : null;
                    $st2->close();
                } catch (Throwable $e) {}
            }
            if (!$isSuper && !($acceptedBy > 0 && $acceptedBy === $adminId)) {
                $err = 'Только админ, который принял чат, может его закрыть.';
            } else {
                try {
                    $st = $mysqli->prepare("UPDATE chats SET status='closed', closed_at = NOW() WHERE id = ? LIMIT 1");
                    $st->bind_param('i', $chat_id);
                    $st->execute();
                    header("Location: chats.php?closed=1");
                    exit;
                } catch (Throwable $e) {
                    $err = 'DB error: ' . $e->getMessage();
                }
            }
        }
    }

    // Delete (non-AJAX)
    if (!empty($_POST['delete'])) {
        if ($chat_id <= 0) {
            $err = 'Неверный ID для удаления.';
        } else {
            $acceptedBy = null;
            if ($chat_id > 0) {
                try {
                    $st2 = $mysqli->prepare("SELECT accepted_by FROM chats WHERE id = ? LIMIT 1");
                    $st2->bind_param('i', $chat_id);
                    $st2->execute();
                    $res = $st2->get_result();
                    $r = $res ? $res->fetch_assoc() : null;
                    $acceptedBy = $r ? (int)($r['accepted_by'] ?? 0) : null;
                    $st2->close();
                } catch (Throwable $e) {}
            }
            if (!$isSuper && !($acceptedBy > 0 && $acceptedBy === $adminId)) {
                $err = 'Только админ, который принял чат, может удалить его.';
            } else {
                try {
                    $stmt = $mysqli->prepare("DELETE FROM messages WHERE chat_id = ?");
                    $stmt->bind_param('i', $chat_id);
                    $stmt->execute();
                    $stmt->close();

                    $stmt2 = $mysqli->prepare("DELETE FROM chats WHERE id = ?");
                    $stmt2->bind_param('i', $chat_id);
                    $stmt2->execute();
                    $stmt2->close();

                    header("Location: chats.php?deleted=1");
                    exit;
                } catch (Throwable $e) {
                    $err = 'DB error: ' . $e->getMessage();
                }
            }
        }
    }

    // Open (non-AJAX fallback)
    if (!empty($_POST['reply'])) {
        header("Location: chats.php?reply=".$chat_id);
        exit;
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Админка — Чаты</title>
  <link rel="stylesheet" href="/mehanik/assets/css/style.css">
  <style>
    /* layout */
    body { background:#f6f7fb; font-family: Inter, Arial, sans-serif; color:#111827; }
    .wrap { max-width:1100px; margin:18px auto; padding:0 14px; }
    h2 { margin:6px 0 18px 0; display:flex; align-items:center; justify-content:space-between; gap:12px; font-size:20px; }

    /* segmented tabs */
    .tabs { display:inline-flex; background:transparent; gap:8px; align-items:center; }
    .segmented { display:inline-flex; background:#eef2f6; padding:4px; border-radius:999px; box-shadow:inset 0 0 0 1px rgba(11,37,84,0.03); }
    .tab { display:inline-flex; align-items:center; gap:8px; padding:6px 10px; border-radius:999px; cursor:pointer; font-weight:600; font-size:13px; color:#3f4b59; border:0; background:transparent; transition:all .12s ease; }
    .tab svg { width:14px; height:14px; display:block; opacity:.85; }
    .tab.badge { min-width:36px; justify-content:center; }
    .tab.active { background:#0b57a4; color:#fff; box-shadow: 0 6px 18px rgba(11,87,164,0.12); transform:translateY(-1px); }
    .tab:hover { transform:translateY(-1px); }

    /* compact section styling */
    .section { background:#fff; border-radius:8px; padding:12px; box-shadow:0 6px 18px rgba(2,6,23,0.04); margin-bottom:16px; }
    .chat-table { width:100%; border-collapse: collapse; margin-top:8px; font-size:14px; }
    .chat-table th, .chat-table td { padding:10px 12px; border-bottom:1px solid #eef2f6; text-align:left; vertical-align:middle; }
    .chat-table th { background:#fbfcfe; font-weight:700; color:#374151; font-size:13px; }
    .row-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .btn { padding:6px 10px; border-radius:8px; border:0; cursor:pointer; font-weight:700; font-size:13px; }
    .btn-accept { background:#0b57a4; color:#fff; }
    .btn-open { background:#3498db; color:#fff; }
    .btn-close { background:#f59e0b; color:#fff; }
    .btn-delete { background:#ef4444; color:#fff; }
    .meta { color:#6b7280; font-size:13px; }
    .badge { display:inline-block; padding:4px 8px; border-radius:999px; background:#eef2ff; color:#0b57a4; font-weight:700; font-size:12px; min-width:64px; text-align:center; }
    .small-muted { color:#6b7280; font-size:13px; }

    /* ---------- Chat drawer: улучшенная вёрстка ---------- */
#chatDrawer {
  position: fixed;
  right: 0;
  top: 0;
  bottom: 0;
  width: 420px;
  max-width: 95%;
  background: linear-gradient(180deg, #ffffff, #fbfdff);
  box-shadow: -18px 30px 60px rgba(8,24,48,0.12);
  z-index: 1400;
  transform: translateX(110%);
  transition: transform .22s cubic-bezier(.2,.9,.3,1);
  display: flex;
  flex-direction: column;
  border-radius: 12px 0 0 12px;
  overflow: hidden;
  font-family: Inter, Arial, sans-serif;
}

/* visible */
#chatDrawer.open { transform: translateX(0); }

/* header */
#chatDrawer .drawer-header {
  padding: 14px 16px;
  border-bottom: 1px solid #eef2f6;
  display: flex;
  align-items: center;
  gap: 12px;
  justify-content: space-between;
  background: linear-gradient(180deg,#fff,#fbfdff);
}
#chatDrawer .drawer-header strong { font-size: 15px; color: #0f1724; }
#chatDrawer .drawer-header .muted-small { color:#6b7280; font-size:13px; }

/* body (scroll area) */
#chatDrawer .drawer-body {
  padding: 14px;
  overflow-y: auto;
  flex: 1 1 auto;
  display: flex;
  flex-direction: column;
  gap: 10px;
  background: transparent;
}

/* footer (sticky actions) */
#chatDrawer .drawer-footer {
  padding: 12px;
  border-top: 1px solid #eef2f6;
  background: linear-gradient(180deg,#fff,#fbfdff);
}

/* message list wrapper */
.messages {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding-right: 6px; /* to not hide behind scrollbar */
}

/* message card */
.message {
  max-width: 86%;
  display: inline-flex;
  flex-direction: column;
  gap: 6px;
  line-height: 1.35;
  border-radius: 12px;
  padding: 10px 12px;
  box-shadow: none;
  word-break: break-word;
  font-size: 14px;
}

/* support vs user */
.message.support {
  align-self: flex-end;
  background: #ecf8ff;
  color: #08243a;
  border-bottom-right-radius: 6px;
}
.message.user {
  align-self: flex-start;
  background: #fff7e6;
  color: #1f2937;
  border-bottom-left-radius: 6px;
}

/* message header (sender) */
.message .msg-sender {
  font-weight: 700;
  font-size: 12.5px;
  color: #0f1724;
  opacity: 0.9;
}

/* message content */
.message .msg-content { white-space: pre-wrap; }

/* meta time */
.message .msg-meta {
  font-size: 12px;
  color: #6b7280;
  opacity: 0.9;
  margin-top: 4px;
}

/* review block (keeps style) */
.review-block { padding:10px; border-radius:8px; background:#fbfcfe; border:1px solid #eef2f6; font-size:14px; }

/* small screens */
@media (max-width: 720px) {
  #chatDrawer { width: 100%; right: 0; border-radius:0; }
  .message { max-width: 90%; }
}

  </style>
</head>
<body>
<?php require_once __DIR__ . '/header.php'; ?>

<div class="wrap">
  <h2>
    <span>Чаты поддержки</span>

    <div class="tabs" role="tablist" aria-label="Фильтр чатов">
      <div class="segmented" role="tablist" aria-label="Секции чатов">
        <?php
          // counts for nice UI
          $lists = fetch_lists($mysqli, $adminId, $isSuper);
          $cntNew = count($lists['new']);
          $cntAcc = count($lists['accepted']);
          $cntClosed = count($lists['closed']);
        ?>
        <button type="button" class="tab active" data-target="section-new" role="tab" aria-selected="true" title="Новые чаты">
          <!-- chat icon -->
          <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M21 15a2 2 0 01-2 2H8l-5 4V5a2 2 0 012-2h14a2 2 0 012 2v10z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <span>Новые</span>
          <span class="tab-count" style="opacity:.9; font-weight:700; margin-left:6px; font-size:12px; background:rgba(255,255,255,0.12); padding:2px 6px; border-radius:999px;"><?= $cntNew ?></span>
        </button>

        <button type="button" class="tab" data-target="section-accepted" role="tab" aria-selected="false" title="Принятые чаты">
          <svg viewBox="0 0 24 24" fill="none"><path d="M12 20l9-7-4-6-5 3-5-3-4 6 9 7z" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <span>Принятые</span>
          <span class="tab-count" style="opacity:.9; font-weight:700; margin-left:6px; font-size:12px;"><?= $cntAcc ?></span>
        </button>

        <button type="button" class="tab" data-target="section-closed" role="tab" aria-selected="false" title="Закрытые чаты">
          <svg viewBox="0 0 24 24" fill="none"><path d="M3 7h18M7 11h10M10 15h4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <span>Закрытые</span>
          <span class="tab-count" style="opacity:.9; font-weight:700; margin-left:6px; font-size:12px;"><?= $cntClosed ?></span>
        </button>
      </div>
    </div>
  </h2>

  <?php if ($msg): ?><div class="section"><div class="note" style="color:green;"><?= htmlspecialchars($msg) ?></div></div><?php endif; ?>
  <?php if ($err): ?><div class="section"><div class="note" style="color:#a11;"><?= htmlspecialchars($err) ?></div></div><?php endif; ?>

  <?php
  // initial server-side lists (for first paint)
  $newRes = $lists['new'];
  $accRes = $lists['accepted'];
  $closedRes = $lists['closed'];
  ?>

  <div id="section-new" class="section" aria-labelledby="newChats">
    <h3 id="newChats">Новые чаты <span class="small-muted">(еще не приняты)</span></h3>

    <?php if (!empty($newRes)): ?>
      <table class="chat-table" role="table" aria-label="Новые чаты">
        <thead><tr><th>ID</th><th>Пользователь</th><th>Статус</th><th>Создан</th><th>Действие</th></tr></thead>
        <tbody id="tbody-new">
        <?php foreach ($newRes as $row): ?>
          <tr data-chat-row="<?= (int)$row['id'] ?>">
            <td><?= (int)$row['id'] ?></td>
            <td><?= htmlspecialchars($row['phone']) ?></td>
            <td><span class="badge"><?= htmlspecialchars($row['status'] ?? '') ?></span></td>
            <td class="meta"><?= htmlspecialchars($row['created_at'] ?? '') ?></td>
            <td>
              <div class="row-actions">
                <form method="post" style="display:inline">
                  <input type="hidden" name="chat_id" value="<?= (int)$row['id'] ?>">
                  <button class="btn btn-accept" type="submit" name="accept" value="1" title="Принять чат">Принять</button>
                </form>

                <button type="button" class="btn btn-open" data-chat-id="<?= (int)$row['id'] ?>">Открыть</button>

                <?php if ($isSuper): ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Удалить чат вместе с сообщениями?');">
                  <input type="hidden" name="chat_id" value="<?= (int)$row['id'] ?>">
                  <button class="btn btn-delete" type="submit" name="delete" value="1">Удалить</button>
                </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <div class="small-muted">Новых чатов нет.</div>
    <?php endif; ?>
  </div>

  <div id="section-accepted" class="section" style="display:none" aria-labelledby="acceptedChats">
    <h3 id="acceptedChats">Принятые чаты <span class="small-muted">(назначены админам)</span></h3>

    <?php if (!empty($accRes)): ?>
      <table class="chat-table" role="table" aria-label="Принятые чаты">
        <thead><tr><th>ID</th><th>Пользователь</th><th>Принял</th><th>Статус</th><th>Принят</th><th>Действие</th></tr></thead>
        <tbody id="tbody-accepted">
        <?php foreach ($accRes as $r): ?>
          <tr data-chat-row="<?= (int)$r['id'] ?>">
            <td><?= (int)$r['id'] ?></td>
            <td><?= htmlspecialchars($r['phone']) ?></td>
            <td><?= htmlspecialchars($r['admin_name'] ?? ('#'.(int)$r['accepted_by'])) ?></td>
            <td><span class="badge"><?= htmlspecialchars($r['status'] ?? '') ?></span></td>
            <td class="meta"><?= htmlspecialchars($r['accepted_at'] ?? $r['created_at']) ?></td>
            <td>
              <div class="row-actions">
                <button type="button" class="btn btn-open" data-chat-id="<?= (int)$r['id'] ?>">Открыть</button>

                <?php if ($isSuper || ((int)$r['accepted_by'] === $adminId)): ?>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="chat_id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-close" type="submit" name="close" value="1">Закрыть</button>
                  </form>

                  <form method="post" style="display:inline" onsubmit="return confirm('Удалить чат вместе с сообщениями?');">
                    <input type="hidden" name="chat_id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-delete" type="submit" name="delete" value="1">Удалить</button>
                  </form>
                <?php else: ?>
                  <span class="small-muted">Действия доступны только назначенному админу</span>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <div class="small-muted">Принятых чатов пока нет.</div>
    <?php endif; ?>
  </div>

  <div id="section-closed" class="section" style="display:none" aria-labelledby="closedChats">
    <h3 id="closedChats">Закрытые чаты <span class="small-muted">(архив закрытых)</span></h3>

    <?php if (!empty($closedRes)): ?>
      <table class="chat-table" role="table" aria-label="Закрытые чаты">
        <thead><tr><th>ID</th><th>Пользователь</th><th>Принял</th><th>Принят</th><th>Закрыт</th><th>Действие</th></tr></thead>
        <tbody id="tbody-closed">
        <?php foreach ($closedRes as $c): ?>
          <tr data-chat-row="<?= (int)$c['id'] ?>">
            <td><?= (int)$c['id'] ?></td>
            <td><?= htmlspecialchars($c['phone']) ?></td>
            <td><?= htmlspecialchars($c['admin_name'] ?? ('#'.(int)$c['accepted_by'])) ?></td>
            <td class="meta"><?= htmlspecialchars($c['accepted_at'] ?? '-') ?></td>
            <td class="meta"><?= htmlspecialchars($c['closed_at'] ?? '-') ?></td>
            <td>
              <div class="row-actions">
                <button type="button" class="btn btn-open" data-chat-id="<?= (int)$c['id'] ?>">Открыть</button>
                <?php if ($isSuper || ((int)($c['accepted_by'] ?? 0) === $adminId)): ?>
                  <form method="post" style="display:inline" onsubmit="return confirm('Удалить архивный чат вместе с сообщениями?');">
                    <input type="hidden" name="chat_id" value="<?= (int)$c['id'] ?>">
                    <button class="btn btn-delete" type="submit" name="delete" value="1">Удалить</button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <div class="small-muted">Пока закрытых чатов нет.</div>
    <?php endif; ?>
  </div>

</div>

<!-- Drawer container -->
<div id="chatDrawer" aria-hidden="true">
  <div class="drawer-header">
    <div id="drawerTitle"><strong>Чат</strong></div>
    <div>
      <button class="drawer-close" id="drawerCloseBtn" title="Закрыть панель">×</button>
    </div>
  </div>
  <div class="drawer-body" id="drawerBody">
    <!-- messages + review injected here -->
  </div>
  <div class="drawer-footer" id="drawerFooter">
    <!-- reply / accept area injected here -->
  </div>
</div>

<script>
(function(){
  const selfUrl = '/mehanik/public/admin/chats.php';
  const drawer = document.getElementById('chatDrawer');
  const drawerBody = document.getElementById('drawerBody');
  const drawerFooter = document.getElementById('drawerFooter');
  const drawerTitle = document.getElementById('drawerTitle');
  const closeBtn = document.getElementById('drawerCloseBtn');

  // run when DOM fully ready to be safe
  document.addEventListener('DOMContentLoaded', function(){
    // Segmented control (delegation)
    const segmented = document.querySelector('.segmented');
    if (segmented) {
      segmented.addEventListener('click', (e) => {
        const btn = e.target.closest('.tab');
        if (!btn) return;
        segmented.querySelectorAll('.tab').forEach(t => {
          t.classList.remove('active');
          t.setAttribute('aria-selected', 'false');
        });
        btn.classList.add('active');
        btn.setAttribute('aria-selected', 'true');
        // show section
        const target = btn.dataset.target;
        document.querySelectorAll('#section-new, #section-accepted, #section-closed').forEach(s => s.style.display = 'none');
        const el = document.getElementById(target);
        if (el) el.style.display = 'block';
      });
    }

    function escapeHtml(s){
      if (s === null || s === undefined) return '';
      return String(s).replace(/[&<>"']/g, function(m){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]; });
    }

    function openDrawer(){ drawer.classList.add('open'); drawer.setAttribute('aria-hidden','false'); }
    function closeDrawer(){ drawer.classList.remove('open'); drawer.setAttribute('aria-hidden','true'); drawerBody.innerHTML=''; drawerFooter.innerHTML=''; }
    closeBtn.addEventListener('click', closeDrawer);

    function renderMessages(messages){
  // безопасно очищаем тело
  drawerBody.innerHTML = '';

  // wrapper
  const wrapper = document.createElement('div');
  wrapper.className = 'messages';

  if (!Array.isArray(messages) || messages.length === 0) {
    const empty = document.createElement('div');
    empty.className = 'muted-small';
    empty.textContent = 'Сообщений пока нет.';
    wrapper.appendChild(empty);
    drawerBody.appendChild(wrapper);
    // ensure scrolled to top
    drawerBody.scrollTop = 0;
    return;
  }

  messages.forEach(m => {
    const who = (m.sender === 'support') ? 'support' : 'user';
    const card = document.createElement('div');
    card.className = 'message ' + who;

    const sender = document.createElement('div');
    sender.className = 'msg-sender';
    // show human-friendly sender label
    sender.textContent = (m.sender === 'support') ? 'Поддержка' : (m.sender || 'Пользователь');

    const content = document.createElement('div');
    content.className = 'msg-content';
    // escape + preserve newlines
    content.innerHTML = (String(m.content || '')
                          .replace(/&/g,'&amp;')
                          .replace(/</g,'&lt;')
                          .replace(/>/g,'&gt;')
                          .replace(/"/g,'&quot;')
                          .replace(/'/g,'&#39;'))
                          .replace(/\n/g,'<br>');

    const meta = document.createElement('div');
    meta.className = 'msg-meta';
    meta.textContent = m.created_at || '';

    card.appendChild(sender);
    card.appendChild(content);
    card.appendChild(meta);
    wrapper.appendChild(card);
  });

  drawerBody.appendChild(wrapper);

  // small timeout so layout stabilises then scroll
  setTimeout(()=> {
    wrapper.scrollTop = wrapper.scrollHeight;
    drawerBody.scrollTop = drawerBody.scrollHeight;
  }, 40);
}


    function renderReview(review, chat) {
      if (!chat || chat.status !== 'closed') return;
      const note = document.createElement('div');
      note.className = 'muted-small';
      note.style.marginBottom = '8px';
      note.textContent = 'Пользователь закрыл чат';
      drawerBody.insertBefore(note, drawerBody.firstChild);

      if (!review) return;
      const block = document.createElement('div');
      block.className = 'review-block';
      let stars = '';
      const r = parseInt(review.rating || 0, 10);
      if (r > 0) {
        for (let i=0;i<r;i++) stars += '★';
        for (let i=r;i<5;i++) stars += '☆';
      } else {
        stars = '—';
      }
      const starsHtml = '<div style="display:flex;align-items:center;gap:12px;"><div class="stars">' + escapeHtml(stars) + '</div><div style="color:#6b7280;">' + (review.comment ? escapeHtml(review.comment) : '<i>Комментариев нет</i>') + '</div></div>';
      const meta = '<div style="margin-top:8px;font-size:12px;color:#6b7280;">' + escapeHtml(review.created_at || '') + '</div>';
      block.innerHTML = '<div style="font-weight:800;margin-bottom:8px;">Отзыв пользователя</div>' + starsHtml + meta;
      drawerBody.insertBefore(block, drawerBody.firstChild.nextSibling);
    }

    function renderFooter(chat, youCanAct){
      drawerFooter.innerHTML = '';
      if (!chat) return;
      if (chat.status === 'closed') {
        const note = document.createElement('div');
        note.className = 'muted-small';
        note.textContent = 'Чат закрыт. Действия недоступны.';
        drawerFooter.appendChild(note);
        return;
      }

      if (youCanAct) {
        const form = document.createElement('div'); form.className = 'reply-form';
        const input = document.createElement('input'); input.type='text'; input.placeholder='Ответ...'; input.style.flex='1'; input.id='drawerReplyInput';
        const sendBtn = document.createElement('button'); sendBtn.className='btn'; sendBtn.style.background='#10b981'; sendBtn.style.color='#fff'; sendBtn.textContent='Отправить';
        sendBtn.addEventListener('click', async function(){
          const content = input.value.trim();
          if (!content) return alert('Введите сообщение');
          sendBtn.disabled = true;
          try {
            const fd = new FormData();
            fd.append('chat_id', String(chat.id));
            fd.append('content', content);
            fd.append('send', '1');
            fd.append('ajax', '1');
            const res = await fetch(selfUrl, { method: 'POST', credentials: 'same-origin', body: fd });
            if (!res.ok) { alert('Ошибка отправки: ' + res.status); sendBtn.disabled=false; return; }
            const data = await res.json();
            if (!data || !data.ok) { alert('Ошибка: ' + (data && data.error ? data.error : 'неизвестная ошибка')); sendBtn.disabled=false; return; }
            const msg = data.message;
            const wrap = drawerBody.querySelector('.messages');
            if (wrap) {
              const div = document.createElement('div'); div.className='message support';
              const hdr = document.createElement('div'); hdr.style.fontSize='13px'; hdr.style.fontWeight='700'; hdr.textContent=msg.sender;
              const contentDiv = document.createElement('div'); contentDiv.innerHTML = escapeHtml(msg.content).replace(/\n/g,'<br>');
              const meta = document.createElement('div'); meta.className='meta'; meta.style.marginTop='6px'; meta.innerHTML = '<small>' + escapeHtml(msg.created_at) + '</small>';
              div.appendChild(hdr); div.appendChild(contentDiv); div.appendChild(meta); wrap.appendChild(div); wrap.scrollTop = wrap.scrollHeight;
            }
            input.value=''; sendBtn.disabled=false;
          } catch (e) { console.error('send error', e); alert('Сетевая ошибка при отправке сообщения'); sendBtn.disabled=false; }
        });
        form.appendChild(input); form.appendChild(sendBtn); drawerFooter.appendChild(form);
      } else {
        if (!chat.accepted_by || chat.accepted_by === 0) {
          const acceptWrap = document.createElement('div');
          const acceptBtn = document.createElement('button'); acceptBtn.className='btn btn-accept'; acceptBtn.type='button'; acceptBtn.textContent='Принять чат';
          acceptBtn.addEventListener('click', async function(){
            if (!confirm('Принять чат #' + chat.id + '?')) return;
            try {
              const fd = new FormData(); fd.append('chat_id', String(chat.id)); fd.append('claim','1'); fd.append('ajax','1');
              const res = await fetch(selfUrl, { method:'POST', credentials:'same-origin', body: fd });
              if (!res.ok) { alert('Ошибка принятия: ' + res.status); return; }
              const d = await res.json();
              if (!d || !d.ok) { alert('Не удалось принять: ' + (d && d.error ? d.error : 'неизвестная ошибка')); return; }
              loadChat(chat.id);
              refreshLists();
            } catch (e) { console.error('claim error', e); alert('Сетевая ошибка при принятии чата'); }
          });
          acceptWrap.appendChild(acceptBtn);
          drawerFooter.appendChild(acceptWrap);
        } else {
          const note = document.createElement('div'); note.className='muted-small'; note.textContent='Действия доступны только назначенному админу.';
          drawerFooter.appendChild(note);
        }
      }
    }

    async function loadChat(id){
      try {
        const url = selfUrl + '?reply=' + encodeURIComponent(id) + '&ajax=1';
        const res = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
        if (!res.ok) { alert('Ошибка загрузки чата: ' + res.status); return; }
        const data = await res.json();
        if (!data || !data.ok) { alert('Ошибка: ' + (data && data.error ? data.error : 'неизвестная ошибка')); return; }
        const chat = data.chat;
        drawerTitle.innerHTML = '<div><strong>Чат #' + chat.id + '</strong><div class="muted-small">' + escapeHtml(chat.phone) + '</div></div>';
        drawerBody.innerHTML = '';
        renderMessages(data.messages || []);
        if (chat.status === 'closed') {
          renderReview(data.review || null, chat);
        }
        renderFooter(chat, Boolean(data.youCanAct));
        openDrawer();
      } catch (e) {
        console.error('loadChat error', e);
        alert('Сетевая ошибка при загрузке чата');
      }
    }

    // Delegated handler for "Открыть" кнопок — сработает и для динамически добавленных строк
    document.addEventListener('click', function(e){
      const openBtn = e.target.closest('.btn-open');
      if (!openBtn) return;
      // prevent accidental form submit if inside one
      e.preventDefault();
      const id = openBtn.dataset.chatId || openBtn.getAttribute('data-chat-id');
      if (!id) return;
      loadChat(Number(id));
    });

    // Attach open handlers for any pre-existing elements (keeps backward compatibility)
    // (not required because of delegation, but harmless)
    function attachOpenButtons(root=document){ /* noop - delegation covers it */ }

      // ========== Polling / refresh lists (fixed, DOM-based rows to avoid quoting bugs) ==========
  async function refreshLists(){
    try {
      const res = await fetch(selfUrl + '?ajax=1&refresh=1', { credentials:'same-origin', cache:'no-store' });
      if (!res.ok) { console.warn('refreshLists HTTP', res.status); return; }
      const data = await res.json();
      if (!data || !data.ok) { console.warn('refreshLists bad data', data); return; }
      const lists = data.lists || {};

      const tbodyNew = document.getElementById('tbody-new');
      const tbodyAcc = document.getElementById('tbody-accepted');
      const tbodyClosed = document.getElementById('tbody-closed');

      function createCell(text, cls) {
        const td = document.createElement('td');
        if (cls) td.className = cls;
        td.textContent = text == null ? '' : String(text);
        return td;
      }

      function createBadge(text) {
        const span = document.createElement('span');
        span.className = 'badge';
        span.textContent = text || '';
        return span;
      }

      function createButton(text, classes, attrs) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = classes || 'btn';
        b.textContent = text;
        if (attrs) {
          for (const k in attrs) {
            if (k === 'dataset') {
              for (const dk in attrs.dataset) b.dataset[dk] = attrs.dataset[dk];
            } else {
              b.setAttribute(k, attrs[k]);
            }
          }
        }
        return b;
      }

      function createFormButton(formName, btnText, btnClass, chatId, confirmText) {
        const f = document.createElement('form');
        f.method = 'post';
        f.style.display = 'inline';
        if (confirmText) {
          f.onsubmit = function(){ return confirm(confirmText); };
        }
        const hid = document.createElement('input'); hid.type='hidden'; hid.name='chat_id'; hid.value = chatId;
        const btn = document.createElement('button');
        btn.type = 'submit';
        btn.name = formName;
        btn.value = '1';
        btn.className = btnClass;
        btn.textContent = btnText;
        f.appendChild(hid);
        f.appendChild(btn);
        return f;
      }

      // clear & populate helper
      function populateTbody(tbody, arr, type) {
        if (!tbody) return;
        tbody.innerHTML = '';
        if (!Array.isArray(arr) || arr.length === 0) return;
        for (const r of arr) {
          const tr = document.createElement('tr');
          tr.dataset.chatRow = r.id;

          if (type === 'new') {
            tr.appendChild(createCell(r.id));
            tr.appendChild(createCell(r.phone || ''));
            const tdStatus = document.createElement('td'); tdStatus.appendChild(createBadge(r.status || ''));
            tr.appendChild(tdStatus);
            tr.appendChild(createCell(r.created_at || '', 'meta'));

            const tdActions = document.createElement('td');
            const actions = document.createElement('div'); actions.className = 'row-actions';

            // Accept form
            const acceptForm = document.createElement('form');
            acceptForm.method = 'post';
            acceptForm.style.display = 'inline';
            const hid = document.createElement('input'); hid.type='hidden'; hid.name='chat_id'; hid.value = r.id;
            const acceptBtn = document.createElement('button'); acceptBtn.type='submit'; acceptBtn.name='accept'; acceptBtn.value='1';
            acceptBtn.className='btn btn-accept'; acceptBtn.textContent='Принять';
            acceptForm.appendChild(hid); acceptForm.appendChild(acceptBtn);
            actions.appendChild(acceptForm);

            // Open button
            const openBtn = createButton('Открыть', 'btn btn-open', { dataset: { chatId: r.id } });
            actions.appendChild(openBtn);

            // Delete if super
            if (<?php echo $isSuper? 'true' : 'false'; ?>) {
              const delForm = document.createElement('form');
              delForm.method = 'post';
              delForm.style.display = 'inline';
              delForm.onsubmit = function(){ return confirm('Удалить чат вместе с сообщениями?'); };
              const hid2 = document.createElement('input'); hid2.type='hidden'; hid2.name='chat_id'; hid2.value = r.id;
              const delBtn = document.createElement('button'); delBtn.type='submit'; delBtn.name='delete'; delBtn.value='1';
              delBtn.className = 'btn btn-delete'; delBtn.textContent = 'Удалить';
              delForm.appendChild(hid2); delForm.appendChild(delBtn);
              actions.appendChild(delForm);
            }

            tdActions.appendChild(actions);
            tr.appendChild(tdActions);
          }

          else if (type === 'accepted') {
            tr.appendChild(createCell(r.id));
            tr.appendChild(createCell(r.phone || ''));
            const acceptedName = r.admin_name ? r.admin_name : (r.accepted_by ? ('#' + r.accepted_by) : '');
            tr.appendChild(createCell(acceptedName));
            const tdStatus = document.createElement('td'); tdStatus.appendChild(createBadge(r.status || ''));
            tr.appendChild(tdStatus);
            tr.appendChild(createCell(r.accepted_at || r.created_at || '', 'meta'));

            const tdActions = document.createElement('td');
            const actions = document.createElement('div'); actions.className = 'row-actions';
            const openBtn = createButton('Открыть', 'btn btn-open', { dataset: { chatId: r.id } });
            actions.appendChild(openBtn);

            // Close/delete allowed?
            if (<?php echo $isSuper? 'true' : 'false'; ?> || (r.accepted_by && +r.accepted_by === <?php echo (int)$adminId; ?>)) {
              // close form
              const closeForm = document.createElement('form');
              closeForm.method='post'; closeForm.style.display='inline';
              const hidc = document.createElement('input'); hidc.type='hidden'; hidc.name='chat_id'; hidc.value = r.id;
              const closeBtn = document.createElement('button'); closeBtn.type='submit'; closeBtn.name='close'; closeBtn.value='1';
              closeBtn.className='btn btn-close'; closeBtn.textContent='Закрыть';
              closeForm.appendChild(hidc); closeForm.appendChild(closeBtn);
              actions.appendChild(closeForm);

              // delete form
              const delForm = document.createElement('form');
              delForm.method='post'; delForm.style.display='inline';
              delForm.onsubmit = function(){ return confirm('Удалить чат вместе с сообщениями?'); };
              const hidd = document.createElement('input'); hidd.type='hidden'; hidd.name='chat_id'; hidd.value = r.id;
              const delBtn = document.createElement('button'); delBtn.type='submit'; delBtn.name='delete'; delBtn.value='1';
              delBtn.className='btn btn-delete'; delBtn.textContent='Удалить';
              delForm.appendChild(hidd); delForm.appendChild(delBtn);
              actions.appendChild(delForm);
            } else {
              const span = document.createElement('span'); span.className='small-muted'; span.textContent = 'Действия доступны только назначенному админу';
              actions.appendChild(span);
            }

            tdActions.appendChild(actions);
            tr.appendChild(tdActions);
          }

          else if (type === 'closed') {
            tr.appendChild(createCell(r.id));
            tr.appendChild(createCell(r.phone || ''));
            const acceptedName = r.admin_name ? r.admin_name : (r.accepted_by ? ('#' + r.accepted_by) : '');
            tr.appendChild(createCell(acceptedName));
            tr.appendChild(createCell(r.accepted_at || '-', 'meta'));
            tr.appendChild(createCell(r.closed_at || '-', 'meta'));

            const tdActions = document.createElement('td');
            const actions = document.createElement('div'); actions.className = 'row-actions';
            const openBtn = createButton('Открыть', 'btn btn-open', { dataset: { chatId: r.id } });
            actions.appendChild(openBtn);

            if (<?php echo $isSuper? 'true' : 'false'; ?> || (r.accepted_by && +r.accepted_by === <?php echo (int)$adminId; ?>)) {
              const delForm = document.createElement('form');
              delForm.method='post'; delForm.style.display='inline';
              delForm.onsubmit = function(){ return confirm('Удалить архивный чат вместе с сообщениями?'); };
              const hid = document.createElement('input'); hid.type='hidden'; hid.name='chat_id'; hid.value = r.id;
              const delBtn = document.createElement('button'); delBtn.type='submit'; delBtn.name='delete'; delBtn.value='1';
              delBtn.className='btn btn-delete'; delBtn.textContent='Удалить';
              delForm.appendChild(hid); delForm.appendChild(delBtn);
              actions.appendChild(delForm);
            }

            tdActions.appendChild(actions);
            tr.appendChild(tdActions);
          }

          tbody.appendChild(tr);
        }
      }

      populateTbody(tbodyNew, lists.new || [], 'new');
      populateTbody(tbodyAcc, lists.accepted || [], 'accepted');
      populateTbody(tbodyClosed, lists.closed || [], 'closed');

      // rebind open buttons (they were created dynamically)
      attachOpenButtons(document);

      // update counts in tabs
      const counts = {
        new: (lists.new || []).length,
        accepted: (lists.accepted || []).length,
        closed: (lists.closed || []).length
      };
      const tabBtns = document.querySelectorAll('.segmented .tab');
      if (tabBtns && tabBtns.length >= 3) {
        const el0 = tabBtns[0].querySelector('.tab-count');
        const el1 = tabBtns[1].querySelector('.tab-count');
        const el2 = tabBtns[2].querySelector('.tab-count');
        if (el0) el0.textContent = counts.new;
        if (el1) el1.textContent = counts.accepted;
        if (el2) el2.textContent = counts.closed;
      }

    } catch (e) {
      console.error('refreshLists error', e);
    }
  }


    // start periodic refresh every 5s
    refreshLists();
    refreshTimer = setInterval(refreshLists, 5000);

    // expose manual refresh when needed
    window.refreshAdminChatLists = refreshLists;

  }); // DOMContentLoaded

})();
</script>

<script src="/mehanik/assets/js/main.js"></script>
</body>
</html>
