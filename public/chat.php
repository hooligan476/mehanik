<?php
// public/chat.php (пользовательский чат)
require_once __DIR__ . '/../middleware.php';
require_auth();

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../db.php';

$uid = (int)($_SESSION['user']['id'] ?? 0);
if ($uid <= 0) {
    header('Location: /mehanik/public/login.php');
    exit;
}

// Получим/создадим активный чат для пользователя (статусы: new/accepted/closed)
// Берём последний чат, который не closed; если нет — создаём новый
$chat_id = null;
$chat_status = 'new';
try {
    $st = $mysqli->prepare("SELECT id, status FROM chats WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $st->bind_param('i', $uid);
    $st->execute();
    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $st->close();

    if ($row && $row['status'] !== 'closed') {
        $chat_id = (int)$row['id'];
        $chat_status = $row['status'];
    } else {
        // создаём новый чат
        $ins = $mysqli->prepare("INSERT INTO chats(user_id, status, created_at) VALUES (?, 'new', NOW())");
        $ins->bind_param('i', $uid);
        $ins->execute();
        $chat_id = (int)$ins->insert_id;
        $chat_status = 'new';
        $ins->close();
    }
} catch (Throwable $e) {
    // на случай ошибок: оставим chat_id = null и покажем ошибку на клиенте
    $chat_id = null;
    $chat_status = 'error';
}

// Загрузим существующие сообщения (если есть)
$messages = [];
if ($chat_id) {
    try {
        $msgs = $mysqli->query("SELECT sender, content, created_at FROM messages WHERE chat_id = " . (int)$chat_id . " ORDER BY id ASC");
        while ($m = $msgs->fetch_assoc()) {
            $messages[] = $m;
        }
    } catch (Throwable $e) {
        // ignore
    }
}

// текущий пользователь (для вывода имени)
$userName = htmlspecialchars($_SESSION['user']['name'] ?? ($_SESSION['user']['phone'] ?? 'Пользователь'), ENT_QUOTES);

?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Чат с поддержкой</title>
  <link rel="stylesheet" href="/mehanik/assets/css/style.css">
  <style>
    .chat-container { max-width:800px; margin:40px auto; padding:18px; background:#fff; border-radius:12px; box-shadow:0 6px 18px rgba(0,0,0,.06); display:flex; flex-direction:column; height:70vh; }
    .chat-header { display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #eee; padding-bottom:8px; }
    #chatWindow { flex:1; overflow:auto; padding:12px; background:#fafafa; border-radius:8px; margin:12px 0; display:flex; flex-direction:column; gap:8px; }
    .chat-form { display:flex; gap:8px; border-top:1px solid #eee; padding-top:10px; }
    .chat-form input { flex:1; padding:10px; border:1px solid #ddd; border-radius:6px; }
    .chat-form button { padding:10px 14px; border-radius:6px; border:0; background:#28a745; color:#fff; cursor:pointer; }
    #closeChatBtn { background:#dc3545; padding:6px 10px; color:#fff; border-radius:6px; border:0; cursor:pointer; }
    #openChatBtn { position: fixed; right:18px; bottom:18px; display:none; background:#007bff; color:#fff; padding:12px 16px; border-radius:999px; border:0; cursor:pointer; box-shadow:0 6px 20px rgba(0,0,0,.12); z-index:9999; }
    .msg { margin-bottom:10px; display:flex; flex-direction:column; }
    .msg.row { display:flex; }
    .msg.user { align-items:flex-end; }
    .msg.support { align-items:flex-start; }
    .bubble { max-width:65%; padding:10px 12px; border-radius:12px; line-height:1.4; }
    .user .bubble { background:#007bff; color:#fff; border-bottom-right-radius:4px; }
    .support .bubble { background:#eef2f6; color:#111; border-bottom-left-radius:4px; }
    .meta { font-size:11px; color:#666; margin-top:6px; text-align:right; }
    /* modal */
    #closeModal { position:fixed; inset:0; display:none; align-items:center; justify-content:center; background:rgba(2,6,23,.6); z-index:10000; }
    #closeModal .m { background:#fff; padding:18px; border-radius:10px; min-width:320px; max-width:520px; box-shadow:0 10px 40px rgba(2,6,23,.3); }
    .stars { display:flex; gap:6px; font-size:24px; cursor:pointer; user-select:none; }
    .star { padding:4px; border-radius:6px; }
    .star.active { color:#f59e0b; }
    textarea.review { width:100%; min-height:80px; padding:8px; border-radius:8px; border:1px solid #e6eef7; resize:vertical; }
    .modal-actions { display:flex; gap:8px; justify-content:flex-end; margin-top:12px; }
    button.btn-ghost{ background:transparent;border:1px solid #e6eef7;padding:8px 10px;border-radius:8px;cursor:pointer; }
    .closed-note { text-align:center; color:#6b7280; padding:8px;border-radius:8px;border:1px dashed #e6eef7; margin-top:8px; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/header.php'; ?>

  <div class="chat-container" id="chatContainer" data-chat-id="<?= htmlspecialchars((string)$chat_id, ENT_QUOTES) ?>">
    <div class="chat-header">
      <div>
        <h2 style="margin:0;">Чат с поддержкой</h2>
        <div style="font-size:13px;color:#6b7280;"><?= $userName ?></div>
      </div>
      <div>
        <button id="closeChatBtn" type="button">Закрыть</button>
      </div>
    </div>

    <div id="chatWindow" aria-live="polite">
      <?php if ($chat_status === 'error'): ?>
        <div class="closed-note">Ошибка при получении чата — попробуйте обновить страницу.</div>
      <?php else: ?>
        <?php if (empty($messages)): ?>
          <div class="small-muted">Сообщений пока нет — напишите первое.</div>
        <?php endif; ?>

        <?php foreach ($messages as $m): 
            $cls = ($m['sender'] === 'support') ? 'support' : 'user';
        ?>
          <div class="msg <?= $cls ?>">
            <div class="bubble <?= $cls ?>"><?= nl2br(htmlspecialchars($m['content'], ENT_QUOTES)) ?></div>
            <div class="meta"><?= htmlspecialchars($m['created_at']) ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <form id="chatForm" class="chat-form" onsubmit="return false;">
      <input type="text" id="message" placeholder="Ваш вопрос..." autocomplete="off" required <?= $chat_status === 'closed' ? 'disabled' : '' ?>>
      <button type="submit" id="sendBtn" <?= $chat_status === 'closed' ? 'disabled' : '' ?>>Отправить</button>
    </form>

    <?php if ($chat_status === 'closed'): ?>
      <div class="closed-note">Этот чат закрыт. Спасибо за обращение.</div>
    <?php endif; ?>
  </div>

  <!-- Modal отзыв при закрытии -->
  <div id="closeModal" role="dialog" aria-hidden="true">
    <div class="m" role="document">
      <h3>Оцените работу поддержки</h3>
      <p class="small-muted">Пожалуйста, выберите рейтинг (1–5) и оставьте комментарий (необязательно).</p>

      <div style="margin-top:8px;">
        <div class="stars" id="stars" role="radiogroup" aria-label="Оценка от 1 до 5">
          <span class="star" data-value="1" role="radio" aria-checked="false">★</span>
          <span class="star" data-value="2" role="radio" aria-checked="false">★</span>
          <span class="star" data-value="3" role="radio" aria-checked="false">★</span>
          <span class="star" data-value="4" role="radio" aria-checked="false">★</span>
          <span class="star" data-value="5" role="radio" aria-checked="false">★</span>
        </div>
      </div>

      <div style="margin-top:12px;">
        <label class="small-muted">Комментарий (необязательно)</label>
        <textarea id="reviewComment" class="review" placeholder="Расскажите, как прошла поддержка..."></textarea>
      </div>

      <div class="modal-actions">
        <button id="cancelClose" class="btn-ghost" type="button">Отмена</button>
        <button id="submitClose" class="btn" type="button">Отправить отзыв и закрыть</button>
      </div>
    </div>
  </div>

  <script>
    (function(){
      const chatId = document.getElementById('chatContainer').dataset.chatId || null;
      const chatWindow = document.getElementById('chatWindow');
      const msgInput = document.getElementById('message');
      const sendBtn = document.getElementById('sendBtn');
      const closeBtn = document.getElementById('closeChatBtn');

      // Minimal send message via fetch to existing API (/api/chat.php?) — if your project already has one, adapt path.
      // We'll post to /mehanik/api/chat_message.php (create if not exists) which should insert into messages table.
      async function sendMessage(content) {
        if (!chatId) return;
        try {
          const fd = new FormData();
          fd.append('chat_id', chatId);
          fd.append('content', content);

          const res = await fetch('/mehanik/api/chat_send.php', {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
          });
          const data = await res.json();
          if (data && data.ok) {
            appendMessage('user', content, data.created_at || '');
            msgInput.value = '';
            chatWindow.scrollTop = chatWindow.scrollHeight;
          } else {
            alert('Ошибка отправки: ' + (data && data.error ? data.error : 'неизвестная'));
          }
        } catch (err) {
          console.error(err);
          alert('Сетевой сбой при отправке сообщения.');
        }
      }

      function appendMessage(sender, content, ts) {
        const div = document.createElement('div');
        div.className = 'msg ' + (sender === 'support' ? 'support' : 'user');
        const bub = document.createElement('div');
        bub.className = 'bubble ' + (sender === 'support' ? 'support' : 'user');
        bub.innerHTML = content.replace(/\n/g,'<br>');
        const meta = document.createElement('div');
        meta.className = 'meta';
        meta.textContent = ts || '';
        div.appendChild(bub);
        div.appendChild(meta);
        chatWindow.appendChild(div);
      }

      // send on form submit
      document.getElementById('chatForm').addEventListener('submit', function(e){
        e.preventDefault();
        const v = msgInput.value.trim();
        if (!v) return;
        sendMessage(v);
      });
      sendBtn.addEventListener('click', function(e){
        e.preventDefault();
        const v = msgInput.value.trim();
        if (!v) return;
        sendMessage(v);
      });

      // Close chat -> show modal
      const closeModal = document.getElementById('closeModal');
      const cancelClose = document.getElementById('cancelClose');
      const submitClose = document.getElementById('submitClose');
      const stars = document.getElementById('stars');
      const starNodes = Array.from(document.querySelectorAll('.star'));
      const reviewComment = document.getElementById('reviewComment');
      let rating = 5;

      function showModal() {
        closeModal.style.display = 'flex';
        closeModal.setAttribute('aria-hidden', 'false');
        highlightStars(rating);
      }
      function hideModal() {
        closeModal.style.display = 'none';
        closeModal.setAttribute('aria-hidden', 'true');
      }

      function highlightStars(val) {
        starNodes.forEach(s => {
          const v = parseInt(s.dataset.value, 10);
          if (v <= val) s.classList.add('active'); else s.classList.remove('active');
          s.setAttribute('aria-checked', v === val ? 'true' : 'false');
        });
      }

      starNodes.forEach(s => {
        s.addEventListener('click', function(){
          rating = parseInt(this.dataset.value, 10);
          highlightStars(rating);
        });
      });

      closeBtn.addEventListener('click', function(){
        // open modal
        showModal();
      });
      cancelClose.addEventListener('click', hideModal);

      // submit rating -> POST to api/support_close.php
      submitClose.addEventListener('click', async function(){
        if (!chatId) { alert('Ошибка: нет чата'); return; }

        const payload = new FormData();
        payload.append('chat_id', chatId);
        payload.append('rating', String(rating));
        payload.append('comment', reviewComment.value || '');

        submitClose.disabled = true;
        submitClose.textContent = 'Отправка...';

        try {
          const res = await fetch('/mehanik/api/support_close.php', {
            method: 'POST',
            credentials: 'same-origin',
            body: payload
          });
          const j = await res.json();
          if (j && j.ok) {
            hideModal();
            // пометим UI как закрытый
            msgInput.disabled = true;
            sendBtn.disabled = true;
            closeBtn.disabled = true;
            const note = document.createElement('div');
            note.className = 'closed-note';
            note.textContent = 'Спасибо! Ваш отзыв отправлен, чат закрыт.';
            document.getElementById('chatContainer').appendChild(note);
            // можно добавить системное сообщение
            appendMessage('support', 'Чат закрыт. Спасибо за отзыв.', j.closed_at || '');
          } else {
            alert('Ошибка: ' + (j && j.error ? j.error : 'неизвестная'));
          }
        } catch (err) {
          console.error(err);
          alert('Сетевая ошибка при отправке отзыва.');
        } finally {
          submitClose.disabled = false;
          submitClose.textContent = 'Отправить отзыв и закрыть';
        }
      });

      // scroll to bottom on load
      try { chatWindow.scrollTop = chatWindow.scrollHeight; } catch(e) {}

    })();
  </script>
</body>
</html>
