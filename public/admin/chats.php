<?php
// public/admin/chats.php
// Админ: управление чатами поддержки — новые / принятые и кнопка "Принять".
// Модификация: AJAX для получения чата и отправки сообщений; правый drawer для просмотра чата.
// Правка: только админ, который принял чат (или суперадмин), может отвечать/закрывать/удалять.

require_once __DIR__.'/../../middleware.php';
require_admin();
require_once __DIR__.'/../../db.php';

$adminId = (int)($_SESSION['user']['id'] ?? 0);
$adminName = $_SESSION['user']['name'] ?? ('admin#'.$adminId);
$isSuper = (($_SESSION['user']['role'] ?? '') === 'superadmin') || (!empty($_SESSION['user']['is_superadmin']) && (int)$_SESSION['user']['is_superadmin'] === 1);

$msg = '';
$err = '';

// --- Попытка добавления колонок accepted_by / accepted_at, если их нет (без фатального фейла) ---
try {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $col = $mysqli->query("SHOW COLUMNS FROM `chats` LIKE 'accepted_by'");
        if (!$col || $col->num_rows === 0) {
            // попробуем аккуратно добавить колонки
            $mysqli->query("ALTER TABLE `chats` 
                ADD COLUMN `accepted_by` INT NULL DEFAULT NULL AFTER `user_id`,
                ADD COLUMN `accepted_at` DATETIME NULL DEFAULT NULL AFTER `accepted_by`");
        }
    }
} catch (Throwable $e) {
    // silent - если нет прав на ALTER или что-то ещё, просто пропустим
}

/**
 * AJAX GET: вернуть чат и сообщения в JSON
 * Запрос: GET /admin/chats.php?reply=<id>&ajax=1
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['reply']) && (!empty($_GET['ajax']) && $_GET['ajax'] === '1')) {
    $chat_id = (int)$_GET['reply'];
    header('Content-Type: application/json; charset=utf-8');

    if ($chat_id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid chat id']);
        exit;
    }

    // получим инфо о чате и кто его принял
    $st = $mysqli->prepare("SELECT c.id, c.user_id, u.phone, c.status, c.accepted_by, c.accepted_at, a.name AS admin_name FROM chats c JOIN users u ON u.id = c.user_id LEFT JOIN users a ON a.id = c.accepted_by WHERE c.id = ? LIMIT 1");
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
        ],
        'messages' => $msgs,
        'youCanAct' => $youCanAct,
        'isSuper' => $isSuper
    ];

    echo json_encode($out);
    exit;
}

/**
 * AJAX POST: отправить сообщение (support) и вернуть JSON
 * Запрос: POST /admin/chats.php (body: chat_id, content, send=1, ajax=1)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax']) && $_POST['ajax'] === '1' && !empty($_POST['send'])) {
    header('Content-Type: application/json; charset=utf-8');
    $chat_id = (int)($_POST['chat_id'] ?? 0);
    $content = trim((string)($_POST['content'] ?? ''));

    if ($chat_id <= 0 || $content === '') {
        echo json_encode(['ok' => false, 'error' => 'Invalid parameters']);
        exit;
    }

    // кто назначен
    $acceptedBy = 0;
    if ($st2 = $mysqli->prepare("SELECT accepted_by FROM chats WHERE id = ? LIMIT 1")) {
        $st2->bind_param('i', $chat_id);
        $st2->execute();
        $r = $st2->get_result()->fetch_assoc();
        $acceptedBy = $r ? (int)($r['accepted_by'] ?? 0) : 0;
        $st2->close();
    }

    if (!($isSuper || ($acceptedBy > 0 && $acceptedBy === $adminId))) {
        echo json_encode(['ok' => false, 'error' => 'You are not assigned to this chat.']);
        exit;
    }

    try {
        $stmt = $mysqli->prepare("INSERT INTO messages(chat_id,sender,content,created_at) VALUES (?, 'support', ?, NOW())");
        $stmt->bind_param('is', $chat_id, $content);
        $stmt->execute();
        $insertId = $stmt->insert_id;
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
            'id' => (int)$insertId,
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

/* === обработка POST-форм (non-AJAX) — оставлена прежней с редиректами === */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $chat_id = (int)($_POST['chat_id'] ?? 0);

    // принять чат (claim / accept) — только если ещё не принят
    if (!empty($_POST['accept'])) {
        if ($chat_id <= 0) {
            $err = 'Неправильный ID чата.';
        } else {
            try {
                $st = $mysqli->prepare("UPDATE chats SET accepted_by = ?, accepted_at = NOW(), status = 'accepted' WHERE id = ? AND (accepted_by IS NULL OR accepted_by = 0) AND status <> 'closed' LIMIT 1");
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

    // отправить сообщение — только если вы назначены на чат или вы суперадмин
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

    // закрыть чат — только если вы назначены (или суперадмин)
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
                    $st = $mysqli->prepare("UPDATE chats SET status='closed' WHERE id = ? LIMIT 1");
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

    // удалить чат — только если вы назначены (или суперадмин)
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

    // открыть чат для просмотра (non-AJAX) — оставляем редирект
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
    body { background:#f6f7fb; font-family: Inter, Arial, sans-serif; }
    .wrap { max-width:1100px; margin:20px auto; padding:0 12px; }
    h2 { margin:12px 0; }
    .section { background:#fff; border-radius:8px; padding:12px; box-shadow:0 6px 18px rgba(2,6,23,0.04); margin-bottom:18px; }
    .chat-table { width:100%; border-collapse: collapse; margin-top:8px; }
    .chat-table th, .chat-table td { padding:10px 12px; border-bottom:1px solid #eef2f6; text-align:left; vertical-align:middle; }
    .chat-table th { background:#fbfcfe; font-weight:700; color:#374151; }
    .row-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .btn { padding:6px 10px; border-radius:8px; border:0; cursor:pointer; font-weight:700; }
    .btn-accept { background:#0b57a4; color:#fff; }
    .btn-open { background:#3498db; color:#fff; }
    .btn-close { background:#f59e0b; color:#fff; }
    .btn-delete { background:#ef4444; color:#fff; }
    .meta { color:#6b7280; font-size:13px; }
    .chat-box { background:#fff; padding:16px; border-radius:8px; box-shadow:0 6px 18px rgba(2,6,23,0.04); margin-top:18px; }
    .message { padding:10px 12px; margin:8px 0; border-radius:8px; max-width:90%; }
    .message.support { background:#ecf5ff; align-self:flex-end; }
    .message.user { background:#fff7e6; align-self:flex-start; }
    .msg-row { display:flex; flex-direction:column; gap:6px; }
    .messages { display:flex; flex-direction:column; max-height:420px; overflow:auto; padding-right:8px; }
    .reply-form { margin-top:12px; display:flex; gap:8px; align-items:center; }
    .reply-form input[type=text] { flex:1; padding:8px; border-radius:8px; border:1px solid #e6eef7; }
    .note { font-size:13px; color:#6b7280; margin-bottom:8px; }
    .badge { display:inline-block; padding:4px 8px; border-radius:999px; background:#eef2ff; color:#0b57a4; font-weight:700; font-size:12px; }
    .small-muted { color:#6b7280; font-size:13px; }
    .warning { color:#b45309; font-weight:700; }
    /* Drawer styles */
    #chatDrawer { position:fixed; right:0; top:0; bottom:0; width:420px; max-width:95%; background:#fff; box-shadow: -12px 0 30px rgba(2,6,23,0.15); z-index:1400; transform:translateX(110%); transition:transform .22s ease; display:flex; flex-direction:column; }
    #chatDrawer.open { transform:translateX(0); }
    #chatDrawer .drawer-header { padding:12px 14px; border-bottom:1px solid #eef2f6; display:flex; align-items:center; gap:8px; justify-content:space-between; }
    #chatDrawer .drawer-body { padding:12px; overflow:auto; flex:1; display:flex; flex-direction:column; gap:8px; }
    #chatDrawer .drawer-footer { padding:12px; border-top:1px solid #eef2f6; }
    .drawer-close { background:#fff; border:1px solid #eee; padding:6px 8px; border-radius:8px; cursor:pointer; }
    .muted-small { color:#6b7280; font-size:13px; }
  </style>
</head>
<body>
<?php require_once __DIR__.'/header.php'; ?>

<div class="wrap">
  <h2>Чаты поддержки</h2>

  <?php if ($msg): ?><div class="section"><div class="note" style="color:green;"><?= htmlspecialchars($msg) ?></div></div><?php endif; ?>
  <?php if ($err): ?><div class="section"><div class="note" style="color:#a11;"><?= htmlspecialchars($err) ?></div></div><?php endif; ?>

  <?php
  // Секция 1: НОВЫЕ чаты — не приняты никем и не закрыты
  $newSql = "
    SELECT c.id, c.user_id, u.phone, c.status, c.created_at
    FROM chats c
    JOIN users u ON u.id = c.user_id
    WHERE (c.accepted_by IS NULL OR c.accepted_by = 0) AND c.status <> 'closed'
    ORDER BY c.id DESC
  ";
  $newRes = $mysqli->query($newSql);
  ?>

  <div class="section" aria-labelledby="newChats">
    <h3 id="newChats">Новые чаты <span class="small-muted">(еще не приняты)</span></h3>

    <?php if ($newRes && $newRes->num_rows > 0): ?>
      <table class="chat-table" role="table" aria-label="Новые чаты">
        <thead><tr><th>ID</th><th>Пользователь</th><th>Статус</th><th>Создан</th><th>Действие</th></tr></thead>
        <tbody>
        <?php while ($row = $newRes->fetch_assoc()): ?>
          <tr data-chat-row="<?= (int)$row['id'] ?>">
            <td><?= (int)$row['id'] ?></td>
            <td><?= htmlspecialchars($row['phone']) ?></td>
            <td><span class="badge"><?= htmlspecialchars($row['status']) ?></span></td>
            <td class="meta"><?= htmlspecialchars($row['created_at']) ?></td>
            <td>
              <div class="row-actions">
                <form method="post" style="display:inline">
                  <input type="hidden" name="chat_id" value="<?= (int)$row['id'] ?>">
                  <button class="btn btn-accept" type="submit" name="accept" value="1" title="Принять чат">Принять</button>
                </form>

                <button class="btn btn-open" data-chat-id="<?= (int)$row['id'] ?>">Открыть</button>

                <!-- Удаление новых чатов теперь доступно только суперадмину (или после принятия) -->
                <?php if ($isSuper): ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Удалить чат вместе с сообщениями?');">
                  <input type="hidden" name="chat_id" value="<?= (int)$row['id'] ?>">
                  <button class="btn btn-delete" type="submit" name="delete" value="1">Удалить</button>
                </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    <?php else: ?>
      <div class="small-muted">Новых чатов нет.</div>
    <?php endif; ?>
  </div>

  <?php
  // Секция 2: ПРИНЯТЫЕ чаты — показываем только те чаты, которые принял текущий админ (или все, если вы суперадмин)
  if ($isSuper) {
      $acceptedSql = "
        SELECT c.id, c.user_id, u.phone, c.status, c.accepted_by, c.accepted_at, a.name AS admin_name, c.created_at
        FROM chats c
        JOIN users u ON u.id = c.user_id
        LEFT JOIN users a ON a.id = c.accepted_by
        WHERE c.accepted_by IS NOT NULL AND c.accepted_by <> 0 AND c.status <> 'closed'
        ORDER BY c.accepted_at DESC, c.id DESC
      ";
      $accRes = $mysqli->query($acceptedSql);
  } else {
      // только чаты, которые принял текущий админ
      $st = $mysqli->prepare("
        SELECT c.id, c.user_id, u.phone, c.status, c.accepted_by, c.accepted_at, a.name AS admin_name, c.created_at
        FROM chats c
        JOIN users u ON u.id = c.user_id
        LEFT JOIN users a ON a.id = c.accepted_by
        WHERE c.accepted_by = ? AND c.status <> 'closed'
        ORDER BY c.accepted_at DESC, c.id DESC
      ");
      $st->bind_param('i', $adminId);
      $st->execute();
      $accRes = $st->get_result();
      $st->close();
  }
  ?>

  <div class="section" aria-labelledby="acceptedChats">
    <h3 id="acceptedChats">Принятые чаты <span class="small-muted">(назначены админам)</span></h3>

    <?php if ($accRes && $accRes->num_rows > 0): ?>
      <table class="chat-table" role="table" aria-label="Принятые чаты">
        <thead><tr><th>ID</th><th>Пользователь</th><th>Принял</th><th>Статус</th><th>Принят</th><th>Действие</th></tr></thead>
        <tbody>
        <?php while ($r = $accRes->fetch_assoc()): ?>
          <tr data-chat-row="<?= (int)$r['id'] ?>">
            <td><?= (int)$r['id'] ?></td>
            <td><?= htmlspecialchars($r['phone']) ?></td>
            <td><?= htmlspecialchars($r['admin_name'] ?? ('#'.(int)$r['accepted_by'])) ?></td>
            <td><span class="badge"><?= htmlspecialchars($r['status']) ?></span></td>
            <td class="meta"><?= htmlspecialchars($r['accepted_at'] ?? $r['created_at']) ?></td>
            <td>
              <div class="row-actions">
                <button class="btn btn-open" data-chat-id="<?= (int)$r['id'] ?>">Открыть</button>

                <!-- Закрыть / Удалить — только для админа, который принял, или суперадмина (оставляем в списке) -->
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
        <?php endwhile; ?>
        </tbody>
      </table>
    <?php else: ?>
      <div class="small-muted">Принятых чатов пока нет.</div>
    <?php endif; ?>
  </div>

  <!-- NOTE: прежний серверный блок просмотра чата (через ?reply=ID) оставлен для совместимости,
       но теперь основное открытие чата выполняется через AJAX в правой панели -->
  <?php
  if (!empty($_GET['reply']) && empty($_GET['ajax'])) {
      // Если пользователь открыл страницу с ?reply=ID непосредственно, оставляем прежний вывод как fallback.
      $chat_id = (int)$_GET['reply'];
      $st = $mysqli->prepare("SELECT c.id, c.user_id, u.phone, c.status, c.accepted_by, c.accepted_at, a.name AS admin_name FROM chats c JOIN users u ON u.id = c.user_id LEFT JOIN users a ON a.id = c.accepted_by WHERE c.id = ? LIMIT 1");
      $st->bind_param('i', $chat_id);
      $st->execute();
      $chatInfo = $st->get_result()->fetch_assoc();
      $st->close();

      if ($chatInfo) {
          echo '<div class="chat-box">';
          echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">';
          echo '<div><strong>Чат #'.(int)$chatInfo['id'].'</strong> — пользователь: <span class="small-muted">'.htmlspecialchars($chatInfo['phone']).'</span></div>';
          if (!empty($chatInfo['accepted_by'])) {
              echo '<div>Принял: <strong>'.htmlspecialchars($chatInfo['admin_name'] ?? ('#'.(int)$chatInfo['accepted_by'])).'</strong></div>';
          } else {
              echo '<div class="small-muted">Чат еще не принят</div>';
          }
          echo '</div>';

          // вывод сообщений (серверный fallback)
          echo '<div class="messages">';
          $msgs = $mysqli->query("SELECT id, sender, content, created_at FROM messages WHERE chat_id=".(int)$chat_id." ORDER BY id ASC");
          while($m = $msgs->fetch_assoc()){
              $cls = $m['sender']==='support' ? 'support' : 'user';
              echo '<div class="message '.$cls.'"><div style="font-size:13px;font-weight:700;">'.htmlspecialchars($m['sender']).'</div>';
              echo '<div>'.nl2br(htmlspecialchars($m['content'])).'</div>';
              echo '<div class="meta" style="margin-top:6px;"><small>'.htmlspecialchars($m['created_at']).'</small></div>';
              echo '</div>';
          }
          echo '</div>';
          echo '</div>';
      } else {
          echo '<div class="section"><div class="note">Чат не найден.</div></div>';
      }
  }
  ?>

</div>

<!-- Drawer container (initially empty, JS will populate) -->
<div id="chatDrawer" aria-hidden="true">
  <div class="drawer-header">
    <div id="drawerTitle"><strong>Чат</strong></div>
    <div>
      <button class="drawer-close" id="drawerCloseBtn" title="Закрыть панель">×</button>
    </div>
  </div>
  <div class="drawer-body" id="drawerBody">
    <!-- messages injected here -->
  </div>
  <div class="drawer-footer" id="drawerFooter">
    <!-- reply form injected here -->
  </div>
</div>

<script>
(function(){
  // API endpoints — claimUrl может быть внешним; используй собственный путь если нужно
  const claimUrl = '/mehanik/api/admin/claim_chat.php'; // если у вас другой путь — поправьте
  const chatsPageUrl = '/mehanik/public/admin/chats.php'; // для AJAX get (локальный путь)
  const drawer = document.getElementById('chatDrawer');
  const drawerBody = document.getElementById('drawerBody');
  const drawerFooter = document.getElementById('drawerFooter');
  const drawerTitle = document.getElementById('drawerTitle');
  const closeBtn = document.getElementById('drawerCloseBtn');

  function escapeHtml(s){
    if (s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, function(m){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]; });
  }

  function openDrawer(){
    if(!drawer) return;
    drawer.classList.add('open');
    drawer.setAttribute('aria-hidden','false');
  }
  function closeDrawer(){
    if(!drawer) return;
    drawer.classList.remove('open');
    drawer.setAttribute('aria-hidden','true');
    drawerBody.innerHTML = '';
    drawerFooter.innerHTML = '';
  }
  closeBtn.addEventListener('click', closeDrawer);

  // render messages array into drawerBody
  function renderMessages(messages){
    drawerBody.innerHTML = '';
    const wrapper = document.createElement('div');
    wrapper.className = 'messages';
    messages.forEach(m => {
      const div = document.createElement('div');
      div.className = 'message ' + (m.sender === 'support' ? 'support' : 'user');
      const hdr = document.createElement('div');
      hdr.style.fontSize = '13px';
      hdr.style.fontWeight = '700';
      hdr.textContent = m.sender;
      const content = document.createElement('div');
      content.innerHTML = escapeHtml(m.content).replace(/\n/g, '<br>');
      const meta = document.createElement('div');
      meta.className = 'meta';
      meta.style.marginTop = '6px';
      meta.innerHTML = '<small>' + escapeHtml(m.created_at) + '</small>';
      div.appendChild(hdr);
      div.appendChild(content);
      div.appendChild(meta);
      wrapper.appendChild(div);
    });
    drawerBody.appendChild(wrapper);
    // scroll to bottom
    setTimeout(()=> { wrapper.scrollTop = wrapper.scrollHeight; drawerBody.scrollTop = drawerBody.scrollHeight; }, 50);
  }

  // render reply area. If youCanAct true - show input; else if chat not accepted show accept btn; else show info
  function renderFooter(chat, youCanAct){
    drawerFooter.innerHTML = '';
    if (youCanAct) {
      const form = document.createElement('div');
      form.className = 'reply-form';
      const input = document.createElement('input');
      input.type = 'text';
      input.placeholder = 'Ответ...';
      input.style.flex = '1';
      input.id = 'drawerReplyInput';
      const sendBtn = document.createElement('button');
      sendBtn.className = 'btn';
      sendBtn.style.background = '#10b981';
      sendBtn.style.color = '#fff';
      sendBtn.textContent = 'Отправить';
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
          const res = await fetch(chatsPageUrl, { method: 'POST', credentials: 'same-origin', body: fd });
          if (!res.ok) {
            alert('Ошибка отправки: ' + res.status);
            sendBtn.disabled = false;
            return;
          }
          const data = await res.json();
          if (!data || !data.ok) {
            alert('Ошибка: ' + (data && data.error ? data.error : 'неизвестная ошибка'));
            sendBtn.disabled = false;
            return;
          }
          // append message to list
          const msg = data.message;
          // add to DOM
          const wrap = drawerBody.querySelector('.messages');
          if (wrap) {
            const div = document.createElement('div');
            div.className = 'message support';
            const hdr = document.createElement('div'); hdr.style.fontSize='13px'; hdr.style.fontWeight='700'; hdr.textContent = msg.sender;
            const contentDiv = document.createElement('div'); contentDiv.innerHTML = escapeHtml(msg.content).replace(/\n/g,'<br>');
            const meta = document.createElement('div'); meta.className='meta'; meta.style.marginTop='6px'; meta.innerHTML = '<small>' + escapeHtml(msg.created_at) + '</small>';
            div.appendChild(hdr); div.appendChild(contentDiv); div.appendChild(meta);
            wrap.appendChild(div);
            wrap.scrollTop = wrap.scrollHeight;
          }
          input.value = '';
          sendBtn.disabled = false;
        } catch (e) {
          console.error('send error', e);
          alert('Сетевая ошибка при отправке сообщения');
          sendBtn.disabled = false;
        }
      });
      form.appendChild(input);
      form.appendChild(sendBtn);
      drawerFooter.appendChild(form);
    } else {
      // if chat not accepted and has no accepted_by -> show "Принять" button which calls claimUrl
      if (!chat.accepted_by || chat.accepted_by === 0) {
        const acceptWrap = document.createElement('div');
        const acceptBtn = document.createElement('button');
        acceptBtn.className = 'btn btn-accept';
        acceptBtn.textContent = 'Принять чат';
        acceptBtn.addEventListener('click', async function(){
          if (!confirm('Принять чат #' + chat.id + '?')) return;
          try {
            const fd = new FormData();
            fd.append('chat_id', String(chat.id));
            // make request to claimUrl (assumed to return JSON)
            const res = await fetch(claimUrl, { method:'POST', credentials:'same-origin', body: fd });
            if (!res.ok) {
              alert('Ошибка принятия: ' + res.status);
              return;
            }
            const d = await res.json();
            if (!d || !d.ok) {
              alert('Не удалось принять: ' + (d && d.error ? d.error : 'неизвестная ошибка'));
              return;
            }
            // reload chat in drawer
            loadChat(chat.id);
            // also remove row from "new" table if exists
            const tr = document.querySelector('tr[data-chat-row="'+chat.id+'"]');
            if (tr) {
              // optionally move it to accepted list or mark - for simplicity, just update UI badge
              const badge = tr.querySelector('.badge');
              if (badge) badge.textContent = 'accepted';
            }
          } catch (e) {
            console.error('claim error', e);
            alert('Сетевая ошибка при принятии чата');
          }
        });
        acceptWrap.appendChild(acceptBtn);
        drawerFooter.appendChild(acceptWrap);
      } else {
        // if accepted by someone else
        const note = document.createElement('div');
        note.className = 'muted-small';
        note.textContent = 'Действия доступны только назначенному админу.';
        drawerFooter.appendChild(note);
      }
    }
  }

  // load chat by id (via AJAX)
  async function loadChat(id){
    try {
      const url = chatsPageUrl + '?reply=' + encodeURIComponent(id) + '&ajax=1';
      const res = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
      if (!res.ok) {
        alert('Ошибка загрузки чата: ' + res.status);
        return;
      }
      const data = await res.json();
      if (!data || !data.ok) {
        alert('Ошибка: ' + (data && data.error ? data.error : 'неизвестная ошибка'));
        return;
      }
      const chat = data.chat;
      drawerTitle.innerHTML = '<div><strong>Чат #' + chat.id + '</strong><div class="muted-small">' + escapeHtml(chat.phone) + '</div></div>';
      renderMessages(data.messages || []);
      renderFooter(chat, Boolean(data.youCanAct));
      openDrawer();
    } catch (e) {
      console.error('loadChat error', e);
      alert('Сетевая ошибка при загрузке чата');
    }
  }

  // attach handlers to open buttons
  function attachOpenButtons(){
    document.querySelectorAll('.btn-open').forEach(btn => {
      btn.addEventListener('click', function(){
        const id = this.dataset.chatId || this.getAttribute('data-chat-id');
        if (!id) return;
        loadChat(Number(id));
      });
    });
  }

  attachOpenButtons();

  // If new rows are dynamically refreshed, re-attach events (optional polling not implemented here)
  // (You can call attachOpenButtons() after any DOM refresh to rebind.)

})();
</script>

<script src="/mehanik/assets/js/main.js"></script>
</body>
</html>
