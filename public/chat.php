<?php
// public/chat.php — пользовательский чат (полностью собран)
// Использует /mehanik/api/chat.php для fetch/send/close

require_once __DIR__ . '/../middleware.php';
require_auth();

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../db.php';

$uid = (int)($_SESSION['user']['id'] ?? 0);
if ($uid <= 0) {
    header('Location: /mehanik/public/login.php');
    exit;
}

// Получим или создадим активный чат (последний не-closed)
$chat_id = null;
$chat_status = 'new';
try {
    $st = $mysqli->prepare("SELECT id, status FROM chats WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $st->bind_param('i', $uid);
    $st->execute();
    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $st->close();

    if ($row && ($row['status'] ?? '') !== 'closed') {
        $chat_id = (int)$row['id'];
        $chat_status = $row['status'] ?: 'new';
    } else {
        $ins = $mysqli->prepare("INSERT INTO chats(user_id, status, created_at) VALUES (?, 'new', NOW())");
        $ins->bind_param('i', $uid);
        $ins->execute();
        $chat_id = (int)$ins->insert_id;
        $chat_status = 'new';
        $ins->close();
    }
} catch (Throwable $e) {
    $chat_id = null;
    $chat_status = 'error';
}

// Загрузим текущую историю сообщений (можно показать пока JS подтянет актуальную)
$messages = [];
if ($chat_id) {
    try {
        $st2 = $mysqli->prepare("SELECT id, sender, content, created_at FROM messages WHERE chat_id = ? ORDER BY id ASC");
        $st2->bind_param('i', $chat_id);
        $st2->execute();
        $res2 = $st2->get_result();
        while ($m = $res2->fetch_assoc()) {
            $messages[] = $m;
        }
        $st2->close();
    } catch (Throwable $e) {
        // ignore
    }
}

$userName = htmlspecialchars($_SESSION['user']['name'] ?? ($_SESSION['user']['phone'] ?? 'Пользователь'), ENT_QUOTES);
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Чат с поддержкой</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/mehanik/assets/css/style.css">
  <style>
    :root{--bg:#fff;--muted:#6b7280;--accent:#007bff;--user:#0b5cff;--support-bg:#eef2f8}
    body{font-family:Inter,Arial,Helvetica,sans-serif;background:#f6f7fb;margin:0;padding:20px}
    .chat-container { max-width:900px; margin:24px auto; padding:18px; background:var(--bg); border-radius:12px; box-shadow:0 6px 18px rgba(2,6,23,.06); display:flex; flex-direction:column; height:72vh; min-height:420px; }
    .chat-header { display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #eef2f6; padding-bottom:10px; gap:8px; }
    /* УБРАЛИ «белую рамку» — фон окна сообщений теперь прозрачный, чтобы не было внутренней белой панели */
    #chatWindow { flex:1; overflow:auto; padding:12px; background:transparent; border-radius:8px; margin:12px 0; display:flex; flex-direction:column; gap:10px; }
    .chat-form { display:flex; gap:8px; border-top:1px solid #eee; padding-top:10px; }
    .chat-form input[type="text"]{ flex:1; padding:10px; border:1px solid #e6eef7; border-radius:8px; font-size:14px; background:#fff; }
    .chat-form button { padding:10px 14px; border-radius:8px; border:0; background:#10b981; color:#fff; cursor:pointer; font-weight:700; }
    #closeChatBtn { background:#ef4444; padding:8px 12px; color:#fff; border-radius:8px; border:0; cursor:pointer; font-weight:700; }
    #openChatBtn { position: fixed; right:18px; bottom:18px; display:none; background:var(--accent); color:#fff; padding:12px 16px; border-radius:999px; border:0; cursor:pointer; box-shadow:0 6px 20px rgba(0,0,0,.12); z-index:9999; }
    .msg { display:flex; flex-direction:column; max-width:85%; }
    .msg.user { margin-left:auto; align-items:flex-end; }
    .msg.support { margin-right:auto; align-items:flex-start; }
    .bubble { padding:10px 12px; border-radius:12px; line-height:1.4; font-size:14px; word-break:break-word; box-shadow: none; border: 0; }
    .bubble.user { background:var(--user); color:#fff; border-bottom-right-radius:4px; }
    .bubble.support { background:var(--support-bg); color:#111; border-bottom-left-radius:4px; }
    .meta { font-size:12px; color:var(--muted); margin-top:6px; }
    .small-muted{ color:var(--muted); }
    .closed-note { text-align:center; color:var(--muted); padding:10px; border-radius:8px; border:1px dashed #e6eef7; margin-top:8px; background:transparent; }
    /* modal */
    #closeModal { position:fixed; inset:0; display:none; align-items:center; justify-content:center; background:rgba(2,6,23,.6); z-index:10000; }
    #closeModal .m { background:#fff; padding:18px; border-radius:10px; min-width:300px; max-width:520px; box-shadow:0 10px 40px rgba(2,6,23,.3); }
    .stars { display:flex; gap:6px; font-size:24px; cursor:pointer; user-select:none; }
    .star { padding:4px; border-radius:6px; }
    .star.active { color:#f59e0b; }
    textarea.review { width:100%; min-height:80px; padding:8px; border-radius:8px; border:1px solid #e6eef7; resize:vertical; margin-top:8px; }
    .modal-actions { display:flex; gap:8px; justify-content:flex-end; margin-top:12px; }
    .btn-ghost{ background:transparent;border:1px solid #e6eef7;padding:8px 10px;border-radius:8px;cursor:pointer; }
    @media (max-width:720px){ .chat-container{height:72vh;padding:12px} .bubble{font-size:13px} }
  </style>
</head>
<body>
  <?php include __DIR__ . '/header.php'; ?>

  <div class="chat-container" id="chatContainer" data-chat-id="<?= htmlspecialchars((string)$chat_id, ENT_QUOTES) ?>">
    <div class="chat-header">
      <div>
        <h2 style="margin:0;font-size:18px">Чат с поддержкой</h2>
        <div style="font-size:13px;color:#6b7280;"><?= $userName ?></div>
      </div>
      <div>
        <button id="closeChatBtn" type="button" <?= $chat_status === 'closed' ? 'disabled' : '' ?>>Закрыть</button>
      </div>
    </div>

    <div id="chatWindow" aria-live="polite" role="log">
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

    <form id="chatForm" class="chat-form" aria-label="Отправить сообщение">
      <input type="text" id="message" placeholder="Ваш вопрос..." autocomplete="off" required <?= $chat_status === 'closed' ? 'disabled' : '' ?> >
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
  'use strict';

  const API = '/mehanik/api/chat.php';
  const chatContainer = document.getElementById('chatContainer');
  const chatId = chatContainer ? chatContainer.dataset.chatId || null : null;
  const chatWindow = document.getElementById('chatWindow');
  const msgInput = document.getElementById('message');
  const sendBtn = document.getElementById('sendBtn');
  const closeBtn = document.getElementById('closeChatBtn');

  // helpers
  function esc(s){ return String(s||'').replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[m]); }

  async function tryParseJSON(response){
    const text = await response.text();
    try { return { json: JSON.parse(text), text }; } catch(e) { return { json: null, text }; }
  }

  // render messages array
  function renderMessages(arr){
    if (!chatWindow) return;
    chatWindow.innerHTML = '';
    if (!Array.isArray(arr) || arr.length === 0) {
      const el = document.createElement('div');
      el.className = 'small-muted';
      el.textContent = 'Сообщений пока нет — напишите первое.';
      chatWindow.appendChild(el);
      return;
    }
    for (const m of arr) {
      const sender = m.sender === 'support' ? 'support' : 'user';
      const wrapper = document.createElement('div');
      wrapper.className = 'msg ' + sender;
      const bub = document.createElement('div');
      bub.className = 'bubble ' + sender;
      bub.innerHTML = esc(m.content).replace(/\n/g,'<br>');
      const meta = document.createElement('div');
      meta.className = 'meta';
      meta.textContent = m.created_at || '';
      wrapper.appendChild(bub);
      wrapper.appendChild(meta);
      chatWindow.appendChild(wrapper);
    }
    chatWindow.scrollTop = chatWindow.scrollHeight;
  }

  // load messages (we request full history by last_id=0 for robustness)
  async function loadMessages(){
    try {
      const url = API + '?last_id=0' + (chatId ? '&chat_id=' + encodeURIComponent(chatId) : '');
      const res = await fetch(url, { method:'GET', credentials:'same-origin', cache:'no-store' });
      if (res.status === 401 || res.redirected) {
        console.warn('Not authenticated while loading chat');
        return;
      }
      if (!res.ok) {
        console.warn('loadMessages: HTTP', res.status);
        return;
      }
      const { json, text } = await tryParseJSON(res);
      if (!json) {
        console.warn('loadMessages: invalid json', text);
        return;
      }
      if (Array.isArray(json.messages)) {
        renderMessages(json.messages);
      }
    } catch (e) {
      console.warn('loadMessages error', e);
    }
  }

  // send message
  async function sendMessage(content){
    if (!content) return;
    if (sendBtn) sendBtn.disabled = true;
    try {
      const fd = new FormData();
      fd.append('action','send');
      fd.append('content', content);
      if (chatId) fd.append('chat_id', chatId);

      const res = await fetch(API, { method:'POST', credentials:'same-origin', body: fd });
      if (res.status === 401 || res.redirected) {
        alert('Сессия истекла, пожалуйста, войдите снова.');
        return;
      }
      if (!res.ok) {
        const parsed = await tryParseJSON(res);
        const srv = (parsed.json && parsed.json.error) ? parsed.json.error : parsed.text || ('HTTP ' + res.status);
        alert('Ошибка отправки: ' + srv);
        return;
      }
      const j = await res.json();
      if (j && j.ok) {
        // перезагружаем сообщения с сервера (чтобы админ увидел и чтобы время/ID были корректны)
        await loadMessages();
        msgInput.value = '';
        msgInput.focus();
      } else {
        alert('Ошибка отправки: ' + (j && j.error ? j.error : 'unknown'));
      }
    } catch (e) {
      console.error('sendMessage error', e);
      alert('Сетевой сбой при отправке сообщения — проверьте соединение и повторите.');
    } finally {
      if (sendBtn) sendBtn.disabled = false;
    }
  }

  // close chat with server (we will call API action=close and then optionally show modal)
  async function closeChatServer(payloadFormData){
    try {
      const fd = payloadFormData || new FormData();
      fd.append('action','close');
      if (chatId) fd.append('chat_id', chatId);
      const res = await fetch(API, { method:'POST', credentials:'same-origin', body: fd });
      if (!res.ok) {
        const parsed = await tryParseJSON(res);
        return { ok:false, error: (parsed.json && parsed.json.error) ? parsed.json.error : parsed.text || ('HTTP ' + res.status) };
      }
      const j = await res.json();
      return j || { ok:false, error:'invalid_json' };
    } catch (e) {
      console.error('closeChatServer error', e);
      return { ok:false, error:'network' };
    }
  }

  // bindings
  document.addEventListener('DOMContentLoaded', function(){
    // initial messages
    loadMessages();

    // start periodic refresh (keeps messages updated when admin replies)
    const poll = setInterval(loadMessages, 3000);
    window.addEventListener('beforeunload', ()=> clearInterval(poll));

    const form = document.getElementById('chatForm');
    if (form) {
      form.addEventListener('submit', function(e){
        e.preventDefault();
        const v = msgInput.value.trim();
        if (!v) return;
        sendMessage(v);
      });
    }
    if (sendBtn) {
      sendBtn.addEventListener('click', function(e){ e.preventDefault(); const v = msgInput.value.trim(); if (!v) return; sendMessage(v); });
    }

    // Close flow: open modal instead of immediate close
    const closeModal = document.getElementById('closeModal');
    const cancelClose = document.getElementById('cancelClose');
    const submitClose = document.getElementById('submitClose');
    const starNodes = Array.from(document.querySelectorAll('.star'));
    const reviewComment = document.getElementById('reviewComment');
    let rating = 5;

    function highlightStars(val){
      starNodes.forEach(s=>{
        const v = parseInt(s.dataset.value,10);
        if (v <= val) s.classList.add('active'); else s.classList.remove('active');
        s.setAttribute('aria-checked', v===val ? 'true' : 'false');
      });
    }
    starNodes.forEach(s => s.addEventListener('click', function(){ rating = parseInt(this.dataset.value,10); highlightStars(rating); }));

    if (closeBtn) {
      closeBtn.addEventListener('click', function(e){
        e.preventDefault();
        if (!closeModal) return;
        closeModal.style.display = 'flex';
        closeModal.setAttribute('aria-hidden','false');
        highlightStars(rating);
      });
    }
    if (cancelClose) {
      cancelClose.addEventListener('click', function(){ closeModal.style.display = 'none'; closeModal.setAttribute('aria-hidden','true'); });
    }

    if (submitClose) {
      submitClose.addEventListener('click', async function(){
        if (!chatId) { alert('Ошибка: нет чата'); return; }
        submitClose.disabled = true;
        submitClose.textContent = 'Отправка...';

        const payload = new FormData();
        payload.append('rating', String(rating));
        payload.append('comment', reviewComment.value || '');

        const res = await closeChatServer(payload);
        submitClose.disabled = false;
        submitClose.textContent = 'Отправить отзыв и закрыть';
        if (res && res.ok) {
          // UI: disable input and show note
          msgInput.disabled = true;
          if (sendBtn) sendBtn.disabled = true;
          if (closeBtn) closeBtn.disabled = true;
          closeModal.style.display = 'none';
          closeModal.setAttribute('aria-hidden','true');

          const note = document.createElement('div');
          note.className = 'closed-note';
          note.textContent = 'Спасибо! Ваш отзыв отправлен, чат закрыт.';
          chatContainer.appendChild(note);

          // добавить системное сообщение (локально)
          const sys = document.createElement('div');
          sys.className = 'msg support';
          const bub = document.createElement('div');
          bub.className = 'bubble support';
          bub.innerHTML = 'Чат закрыт. Спасибо за отзыв.';
          const meta = document.createElement('div');
          meta.className = 'meta';
          meta.textContent = res.closed_at || '';
          sys.appendChild(bub);
          sys.appendChild(meta);
          chatWindow.appendChild(sys);
          chatWindow.scrollTop = chatWindow.scrollHeight;
        } else {
          alert('Ошибка при закрытии: ' + (res && res.error ? res.error : 'неизвестная'));
        }
      });
    }
  });
})();
</script>
</body>
</html>
