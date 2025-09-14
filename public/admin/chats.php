<?php
// public/admin/chats.php
// Админ: управление чатами поддержки — новые / принятые и кнопка "Принять".
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

// === обработка действий до вывода HTML ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $chat_id = (int)($_POST['chat_id'] ?? 0);

    // принять чат (claim / accept) — только если ещё не принят
    if (!empty($_POST['accept'])) {
        if ($chat_id <= 0) {
            $err = 'Неправильный ID чата.';
        } else {
            try {
                // Атомарный UPDATE: сработает только если accepted_by IS NULL или 0 и статус не closed
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

    // Проверим, кто назначен на чат (accepted_by) — для последующих операций
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

    // отправить сообщение — только если вы назначены на чат или вы суперадмин
    if (!empty($_POST['send']) && !empty($_POST['content'])) {
        $content = trim((string)$_POST['content']);
        if ($chat_id <= 0 || $content === '') {
            $err = 'Неверные параметры для отправки.';
        } else {
            // если чат не назначен, отказ
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

    // открыть чат для просмотра — оставляем доступ для просмотра всем админам,
    // но действия внутри будут ограничены (см. ниже).
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
    .messages { display:flex; flex-direction:column; }
    .reply-form { margin-top:12px; display:flex; gap:8px; align-items:center; }
    .reply-form input[type=text] { flex:1; padding:8px; border-radius:8px; border:1px solid #e6eef7; }
    .note { font-size:13px; color:#6b7280; margin-bottom:8px; }
    .badge { display:inline-block; padding:4px 8px; border-radius:999px; background:#eef2ff; color:#0b57a4; font-weight:700; font-size:12px; }
    .small-muted { color:#6b7280; font-size:13px; }
    .warning { color:#b45309; font-weight:700; }
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
          <tr>
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

                <form method="post" style="display:inline">
                  <input type="hidden" name="chat_id" value="<?= (int)$row['id'] ?>">
                  <button class="btn btn-open" type="submit" name="reply" value="1">Открыть</button>
                </form>

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
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><?= htmlspecialchars($r['phone']) ?></td>
            <td><?= htmlspecialchars($r['admin_name'] ?? ('#'.(int)$r['accepted_by'])) ?></td>
            <td><span class="badge"><?= htmlspecialchars($r['status']) ?></span></td>
            <td class="meta"><?= htmlspecialchars($r['accepted_at'] ?? $r['created_at']) ?></td>
            <td>
              <div class="row-actions">
                <form method="post" style="display:inline">
                  <input type="hidden" name="chat_id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn-open" type="submit" name="reply" value="1">Открыть</button>
                </form>

                <!-- Закрыть / Удалить — только для админа, который принял, или суперадмина -->
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

  <?php
  // просмотр чата (если ?reply=ID)
  if (!empty($_GET['reply'])) {
      $chat_id = (int)$_GET['reply'];

      // получим инфо о чате и кто его принял
      $st = $mysqli->prepare("SELECT c.id, c.user_id, u.phone, c.status, c.accepted_by, c.accepted_at, a.name AS admin_name FROM chats c JOIN users u ON u.id = c.user_id LEFT JOIN users a ON a.id = c.accepted_by WHERE c.id = ? LIMIT 1");
      $st->bind_param('i', $chat_id);
      $st->execute();
      $chatInfo = $st->get_result()->fetch_assoc();
      $st->close();

      if (!$chatInfo) {
          echo '<div class="section"><div class="note">Чат не найден.</div></div>';
      } else {
          echo '<div class="chat-box">';
          echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">';
          echo '<div><strong>Чат #'.(int)$chatInfo['id'].'</strong> — пользователь: <span class="small-muted">'.htmlspecialchars($chatInfo['phone']).'</span></div>';
          if (!empty($chatInfo['accepted_by'])) {
              echo '<div>Принял: <strong>'.htmlspecialchars($chatInfo['admin_name'] ?? ('#'.(int)$chatInfo['accepted_by'])).'</strong></div>';
          } else {
              echo '<div class="small-muted">Чат еще не принят</div>';
          }
          echo '</div>';

          // вывод сообщений
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

          // Доступные действия: если вы назначены (или суперадмин) — показать форму ответа и кнопки закрыть/удалить.
          $chatAcceptedBy = !empty($chatInfo['accepted_by']) ? (int)$chatInfo['accepted_by'] : 0;
          $youCanAct = $isSuper || ($chatAcceptedBy > 0 && $chatAcceptedBy === $adminId);

          if ($youCanAct) {
              echo '<form method="post" class="reply-form" style="margin-top:14px;">';
              echo '<input type="hidden" name="chat_id" value="'.(int)$chat_id.'">';
              echo '<input type="text" name="content" placeholder="Ответ..." required>';
              echo '<button name="send" value="1" class="btn" style="background:#10b981;color:#fff;">Отправить</button>';
              echo '</form>';

              echo '<div style="margin-top:10px;display:flex;gap:8px;">';
              echo '<form method="post" style="display:inline"><input type="hidden" name="chat_id" value="'.(int)$chat_id.'"><button class="btn btn-close" name="close" value="1">Закрыть чат</button></form>';
              echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Удалить чат вместе с сообщениями?\');"><input type="hidden" name="chat_id" value="'.(int)$chat_id.'"><button class="btn btn-delete" name="delete" value="1">Удалить</button></form>';
              echo '</div>';
          } else {
              // Для непринявшего админа: показываем кнопку принять (если чат не принят) или сообщение, что вы не назначены.
              if ($chatAcceptedBy === 0) {
                  echo '<div style="margin-top:12px;"><form method="post" style="display:inline"><input type="hidden" name="chat_id" value="'.(int)$chat_id.'"><button class="btn btn-accept" name="accept" value="1">Принять чат</button></form></div>';
              } else {
                  echo '<div class="note" style="margin-top:12px;"><span class="warning">Вы не назначены на этот чат.</span> Действия доступны только назначенному админу.</div>';
              }
          }

          echo '</div>';
      }
  }
  ?>

</div>
<script>
(function(){
  // Жёстко используем корректный админский API-путь (у тебя: mehanik/api/admin)
  const apiBase = '/mehanik/api/admin';

  // Конфиг — URL API
  const listUrl  = apiBase + '/new_chats_list.php';
  const countUrl = apiBase + '/new_chats_count.php';
  const claimUrl = apiBase + '/claim_chat.php';

  const base = '<?= htmlspecialchars($base ?? '/mehanik/public', ENT_QUOTES) ?>'; // не используется для AJAX, но оставлен для совместимости

  // селекторы
  const newSection = document.querySelector('.section[aria-labelledby="newChats"]');
  const newTbody = newSection ? newSection.querySelector('tbody') : null;
  const headerBadge = document.getElementById('newChatsBadge');

  function escapeHtml(s){
    if (s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, function(m){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]; });
  }

  function renderRow(chat) {
    const id = Number(chat.id);
    const phone = chat.phone || '-';
    const status = chat.status || '';
    const created = chat.created_at || '';
    return `
      <tr data-chat-id="${id}">
        <td>${id}</td>
        <td>${escapeHtml(phone)}</td>
        <td><span class="badge">${escapeHtml(status)}</span></td>
        <td class="meta">${escapeHtml(created)}</td>
        <td>
          <div class="row-actions">
            <button class="btn btn-accept" data-chat-id="${id}" title="Принять чат">Принять</button>
            <button class="btn btn-open" data-chat-id="${id}" title="Открыть">Открыть</button>
          </div>
        </td>
      </tr>
    `;
  }

  async function refreshList() {
    if (!newTbody) return;
    try {
      const res = await fetch(listUrl, { credentials: 'same-origin', cache: 'no-store' });
      if (!res.ok) {
        console.warn('new_chats_list fetch failed', res.status);
        return;
      }
      const data = await res.json();
      if (!data || !data.ok) return;
      const list = data.list || [];
      if (list.length === 0) {
        newTbody.innerHTML = '<tr><td colspan="5" class="small-muted">Новых чатов нет.</td></tr>';
      } else {
        newTbody.innerHTML = list.map(renderRow).join('');
      }
      attachButtons();
    } catch (e) {
      console.warn('refreshList error', e);
    }
  }

  async function refreshCount() {
    try {
      const res = await fetch(countUrl, { credentials: 'same-origin', cache: 'no-store' });
      if (!res.ok) {
        console.warn('new_chats_count fetch failed', res.status);
        return;
      }
      const data = await res.json();
      if (!data || !data.ok) return;
      const count = Number(data.count || 0);
      if (headerBadge) {
        headerBadge.textContent = count > 0 ? String(count) : '';
        headerBadge.style.display = count > 0 ? 'inline-block' : 'none';
      }
    } catch (e) {
      console.warn('refreshCount error', e);
    }
  }

  function attachButtons() {
    if (!newTbody) return;
    newTbody.querySelectorAll('.btn-open').forEach(btn => {
      btn.addEventListener('click', function(){
        const id = this.dataset.chatId;
        if (!id) return;
        window.location.href = base.replace(/\/public$/, '') + '/public/admin/chats.php?reply=' + encodeURIComponent(id);
      });
    });

    newTbody.querySelectorAll('.btn-accept').forEach(btn => {
      btn.addEventListener('click', async function(){
        const id = this.dataset.chatId;
        if (!id) return;
        if (!confirm('Принять чат #' + id + '?')) return;
        try {
          const form = new FormData();
          form.append('chat_id', id);
          const res = await fetch(claimUrl, { method: 'POST', credentials: 'same-origin', body: form });
          if (!res.ok) {
            const txt = await res.text();
            alert('Ошибка принятия: ' + (txt || res.status));
            return;
          }
          const data = await res.json();
          if (data && data.ok) {
            // строка удалится из списка
            const tr = newTbody.querySelector('tr[data-chat-id="'+id+'"]');
            if (tr) tr.remove();
            refreshCount();
            // можно автоматически открыть чат:
            // window.location.href = base.replace(/\/public$/, '') + '/public/admin/chats.php?reply=' + encodeURIComponent(id);
          } else {
            alert('Не удалось принять чат: ' + (data && data.error ? data.error : 'неизвестная ошибка'));
            // обновим список на случай race
            refreshList();
            refreshCount();
          }
        } catch (e) {
          console.error('claim error', e);
          alert('Сетевая ошибка при принятии чата');
        }
      });
    });
  }

  // polling
  const POLL_MS = 3000;
  refreshList();
  refreshCount();
  const _pollInterval = setInterval(function(){
    refreshList();
    refreshCount();
  }, POLL_MS);

  window.addEventListener('beforeunload', function(){ clearInterval(_pollInterval); });

})();
</script>


</body>
</html>
