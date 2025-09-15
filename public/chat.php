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

/*
  Изменено: НЕ создаём чат автоматически при заходе.
  Ищем последний не-closed чат; если нет — оставляем chat_id = null.
*/
$chat_id = null;
$chat_status = 'none';
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
        // нет активного чата — оставляем chat_id = null, chat_status = 'none'
        $chat_id = null;
        $chat_status = 'none';
    }
} catch (Throwable $e) {
    $chat_id = null;
    $chat_status = 'error';
}

// Загрузим текущую историю сообщений (если есть chat_id)
$messages = [];
$has_user_messages = false;
if ($chat_id) {
    try {
        $st2 = $mysqli->prepare("SELECT id, sender, content, created_at FROM messages WHERE chat_id = ? ORDER BY id ASC");
        $st2->bind_param('i', $chat_id);
        $st2->execute();
        $res2 = $st2->get_result();
        while ($m = $res2->fetch_assoc()) {
            $messages[] = $m;
            // отметим, есть ли сообщения от пользователя (sender != 'support')
            if (!$has_user_messages && (!isset($m['sender']) || $m['sender'] !== 'support')) {
                $has_user_messages = true;
            }
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
    :root{--bg:#fff;--muted:#6b7280;--accent:#007bff;--user:#0b5cff;--support-bg:#eef2f8;--modal-bg:#ffffff}
    body{font-family:Inter,Arial,Helvetica,sans-serif;background:#f6f7fb;margin:0;padding:20px}
    .chat-container { max-width:900px; margin:24px auto; padding:18px; background:var(--bg); border-radius:12px; box-shadow:0 6px 18px rgba(2,6,23,.06); display:flex; flex-direction:column; height:72vh; min-height:420px; }
    /* Прозрачный хедер — без белой рамки */
    .chat-header { display:flex; justify-content:space-between; align-items:center; padding:8px 0 6px 0; gap:12px; background:transparent; border-bottom: none; }
    .chat-header h2 { margin:0; font-size:18px; color:#111; }
    .chat-header .user-sub { font-size:13px; color:var(--muted); margin-top:4px; }
    /* Окно сообщений прозрачное */
    #chatWindow { flex:1; overflow:auto; padding:12px; background:transparent; border-radius:8px; margin:12px 0; display:flex; flex-direction:column; gap:10px; }
    .chat-form { display:flex; gap:8px; border-top:1px solid #eee; padding-top:10px; }
    .chat-form input[type="text"]{ flex:1; padding:10px; border:1px solid #e6eef7; border-radius:8px; font-size:14px; background:#fff; }
    .chat-form button { padding:10px 14px; border-radius:8px; border:0; background:#10b981; color:#fff; cursor:pointer; font-weight:700; }
    /* Close button: красная, но когда disabled — серый стиль */
    #closeChatBtn { background:#ef4444; padding:8px 12px; color:#fff; border-radius:8px; border:0; cursor:pointer; font-weight:700; }
    #closeChatBtn:disabled { background:#9ca3af; cursor:not-allowed; color:#fff; opacity:0.95; }
    /* disabled send */
    #sendBtn:disabled { opacity:0.7; cursor:not-allowed; }
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

    /* modal (улучшенный визуал и поведение) */
    #closeModal { position:fixed; inset:0; display:none; align-items:center; justify-content:center; background:rgba(2,6,23,.5); z-index:10000; padding:18px; }
    #closeModal.open { display:flex; }
    #closeModal .m { background:var(--modal-bg); padding:18px; border-radius:12px; min-width:320px; max-width:640px; width:100%; box-shadow:0 10px 40px rgba(2,6,23,.3); }
    #closeModal .m h3 { margin:0 0 6px 0; }
    #closeModal .m p { margin:0 0 12px 0; color:var(--muted) }

    .stars { display:flex; gap:8px; font-size:28px; cursor:pointer; user-select:none; align-items:center; }
    .star { display:inline-flex; width:44px; height:44px; align-items:center; justify-content:center; border-radius:8px; transition: transform .12s ease, background .12s ease, color .12s ease; color:#cbd5e1; background:#f8fafc; }
    .star:hover { transform:translateY(-4px); }
    .star.active { color:#f59e0b; background:#fff7e6; box-shadow:0 6px 18px rgba(245, 158, 11, .12); }
    .star:focus { outline:2px solid rgba(11,90,255,.12); }

    .star-label { font-size:13px; color:var(--muted); margin-left:8px; }

    textarea.review { width:100%; min-height:92px; padding:10px; border-radius:8px; border:1px solid #e6eef7; resize:vertical; margin-top:8px; font-size:14px; }
    .modal-actions { display:flex; gap:8px; justify-content:flex-end; margin-top:12px; }
    .btn-ghost{ background:transparent;border:1px solid #e6eef7;padding:8px 10px;border-radius:8px;cursor:pointer; }
    .review-summary { margin-top:12px; padding:12px; border-radius:10px; background:#f8fafc; border:1px solid #e6eef7; display:flex; gap:12px; align-items:flex-start; }
    .review-summary .score { font-weight:800; font-size:20px; color:#111; margin-top:2px; }
    @media (max-width:720px){ .chat-container{height:72vh;padding:12px} .bubble{font-size:13px} #closeModal .m { padding:14px; } .star { width:40px; height:40px; } }
  </style>
</head>
<body>
  <?php include __DIR__ . '/header.php'; ?>

  <div class="chat-container"
       id="chatContainer"
       data-chat-id="<?= $chat_id !== null ? htmlspecialchars((string)$chat_id, ENT_QUOTES) : '' ?>"
       data-has-user="<?= $has_user_messages ? '1' : '0' ?>">
    <div class="chat-header">
      <div>
        <h2>Чат с поддержкой</h2>
        <div class="user-sub"><?= $userName ?></div>
      </div>
      <div>
        <button id="closeChatBtn" type="button"
          <?= ($chat_status === 'closed' || !$has_user_messages || $chat_id === null) ? 'disabled' : '' ?>>
          Закрыть
        </button>
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

  <!-- Modal отзыв при закрытии (улучшённый) -->
  <div id="closeModal" role="dialog" aria-hidden="true" aria-modal="true">
    <div class="m" role="document" aria-labelledby="closeModalTitle">
      <h3 id="closeModalTitle">Оцените работу поддержки</h3>
      <p class="small-muted">Выберите оценку (1–5) и оставьте комментарий (необязательно). Это поможет нам улучшить сервис.</p>

      <div style="display:flex; align-items:center; gap:8px;">
        <div class="stars" id="stars" role="radiogroup" aria-label="Оценка от 1 до 5">
          <button type="button" class="star" data-value="1" aria-checked="false" aria-label="1 звезда">★</button>
          <button type="button" class="star" data-value="2" aria-checked="false" aria-label="2 звезды">★</button>
          <button type="button" class="star" data-value="3" aria-checked="false" aria-label="3 звезды">★</button>
          <button type="button" class="star" data-value="4" aria-checked="false" aria-label="4 звезды">★</button>
          <button type="button" class="star" data-value="5" aria-checked="false" aria-label="5 звёзд">★</button>
        </div>
        <div class="star-label" id="starLabel">Отлично</div>
      </div>

      <div style="margin-top:12px;">
        <label class="small-muted" for="reviewComment">Комментарий (необязательно)</label>
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
  let chatId = chatContainer ? (chatContainer.dataset.chatId || null) : null;
  const chatWindow = document.getElementById('chatWindow');
  const msgInput = document.getElementById('message');
  const sendBtn = document.getElementById('sendBtn');
  const closeBtn = document.getElementById('closeChatBtn');

  // modal elements
  const closeModal = document.getElementById('closeModal');
  const starButtons = Array.from(closeModal.querySelectorAll('.star'));
  const starLabel = document.getElementById('starLabel');
  const reviewComment = document.getElementById('reviewComment');
  const cancelClose = document.getElementById('cancelClose');
  const submitClose = document.getElementById('submitClose');

  const STAR_TEXT = {
    1: 'Очень плохо',
    2: 'Плохо',
    3: 'Нормально',
    4: 'Хорошо',
    5: 'Отлично'
  };

  let pollTimer = null; // poll handle

  function esc(s){ return String(s||'').replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[m]); }
  function disableBodyScroll(){ document.documentElement.style.overflow = 'hidden'; document.body.style.overflow = 'hidden'; }
  function enableBodyScroll(){ document.documentElement.style.overflow = ''; document.body.style.overflow = ''; }

  async function tryParseJSON(response){
    const text = await response.text();
    try { return { json: JSON.parse(text), text }; } catch(e) { return { json: null, text }; }
  }

  function startPoll(){
    if (pollTimer) return;
    // only start if we have a chatId
    if (!chatId) return;
    pollTimer = setInterval(loadMessages, 3000);
  }
  function stopPoll(){
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  }

  function renderMessages(arr){
    if (!chatWindow) return;
    chatWindow.innerHTML = '';
    if (!Array.isArray(arr) || arr.length === 0) {
      const el = document.createElement('div');
      el.className = 'small-muted';
      el.textContent = 'Сообщений пока нет — напишите первое.';
      chatWindow.appendChild(el);
      updateCloseButtonState(false);
      return;
    }
    let foundUser = false;
    for (const m of arr) {
      const sender = m.sender === 'support' ? 'support' : 'user';
      if (sender === 'user') foundUser = true;
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
    updateCloseButtonState(foundUser);
  }

  // loadMessages НЕ будет ничего делать, если chatId === null
  async function loadMessages(){
    if (!chatId) return; // <-- ключевая правка: не стучим в API без chat_id
    try {
      const url = API + '?last_id=0&chat_id=' + encodeURIComponent(chatId);
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

  // sendMessage: если chatId отсутствует — сначала создаём чат (action=open), затем отправляем сообщение
  async function sendMessage(content){
    if (!content) return;
    if (sendBtn) sendBtn.disabled = true;

    try {
      // если чат ещё не создан — создаём (однократно)
      if (!chatId) {
        try {
          const fdOpen = new FormData();
          fdOpen.append('action', 'open');
          const resOpen = await fetch(API, { method:'POST', credentials:'same-origin', body: fdOpen });
          if (!resOpen.ok) {
            const parsed = await tryParseJSON(resOpen);
            throw new Error((parsed.json && parsed.json.error) ? parsed.json.error : ('HTTP ' + resOpen.status));
          }
          const jOpen = await resOpen.json();
          if (!(jOpen && jOpen.ok && jOpen.chat_id)) {
            throw new Error('Не удалось создать чат на сервере');
          }
          chatId = String(jOpen.chat_id);
          if (chatContainer) chatContainer.dataset.chatId = chatId;
          // теперь, когда чат создан, можно стартовать polling
          startPoll();
        } catch (e) {
          console.error('open chat error', e);
          alert('Не удалось создать чат. Попробуйте ещё раз.');
          sendBtn.disabled = false;
          return;
        }
      }

      // отправляем сообщение
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
        await loadMessages();
        msgInput.value = '';
        msgInput.focus();
        updateCloseButtonState(true);
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

  async function closeChatServer(payloadFormData){
    if (!chatId) return { ok:false, error:'no_chat' };
    try {
      const fd = payloadFormData || new FormData();
      fd.append('action','close');
      fd.append('chat_id', chatId);
      const res = await fetch(API, { method:'POST', credentials:'same-origin', body: fd });
      if (!res.ok) {
        const parsed = await tryParseJSON(res);
        return { ok:false, error: (parsed.json && parsed.json.error) ? parsed.json.error : parsed.text || ('HTTP ' + res.status) };
      }
      const j = await res.json();
      // после закрытия можно остановить polling — чтобы избежать лишних запросов на закрытый чат
      if (j && j.ok) {
        stopPoll();
      }
      return j || { ok:false, error:'invalid_json' };
    } catch (e) {
      console.error('closeChatServer error', e);
      return { ok:false, error:'network' };
    }
  }

  function setStars(val){
    starButtons.forEach(btn=>{
      const v = parseInt(btn.dataset.value,10);
      if (v <= val) {
        btn.classList.add('active');
        btn.setAttribute('aria-checked','true');
      } else {
        btn.classList.remove('active');
        btn.setAttribute('aria-checked','false');
      }
    });
    starLabel.textContent = STAR_TEXT[val] || '';
  }

  function bindStarKeyboard(){
    starButtons.forEach((btn, idx) => {
      btn.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowLeft' || e.key === 'ArrowDown') {
          e.preventDefault();
          const prev = starButtons[Math.max(0, idx-1)];
          prev.focus();
        } else if (e.key === 'ArrowRight' || e.key === 'ArrowUp') {
          e.preventDefault();
          const next = starButtons[Math.min(starButtons.length-1, idx+1)];
          next.focus();
        } else if (e.key === 'Enter' || e.key === ' ' ) {
          e.preventDefault();
          const v = parseInt(btn.dataset.value,10);
          ratingValue = v;
          setStars(v);
        }
      });
    });
  }

  let ratingValue = 5;

  // включаем кнопку Close только если есть пользовательское сообщение и чат существует и не закрыт
  function updateCloseButtonState(hasUserMsg) {
    const datasetHas = chatContainer && chatContainer.dataset.hasUser;
    const appearsToHaveUser = (datasetHas === '1') || Boolean(hasUserMsg);
    if (!closeBtn) return;
    const isClosed = <?= json_encode($chat_status === 'closed') ?>;
    const chatExists = Boolean(chatId);
    closeBtn.disabled = isClosed || !appearsToHaveUser || !chatExists;
    if (chatContainer) chatContainer.dataset.hasUser = appearsToHaveUser ? '1' : '0';
  }

  document.addEventListener('DOMContentLoaded', function(){
    // Если chatId задан сервером (есть активный чат) — грузим сообщения и стартуем poll.
    if (chatId) {
      loadMessages();
      startPoll();
    }

    // Инициализация состояния кнопки Close (берём значение dataset, установленное сервером)
    const initialHasUser = chatContainer && chatContainer.dataset.hasUser === '1';
    updateCloseButtonState(initialHasUser);

    window.addEventListener('beforeunload', ()=> stopPoll());

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

    starButtons.forEach(btn=>{
      btn.addEventListener('click', function(e){
        const v = parseInt(this.dataset.value,10);
        ratingValue = v;
        setStars(v);
      });
      btn.addEventListener('mouseenter', function(){ setStars(parseInt(this.dataset.value,10)); });
    });
    closeModal.addEventListener('mouseleave', function(){ setStars(ratingValue); });

    bindStarKeyboard();

    // Close flow: открываем modal
    if (closeBtn) {
      closeBtn.addEventListener('click', function(e){
        e.preventDefault();
        if (closeBtn.disabled) return;
        closeModal.classList.add('open');
        closeModal.setAttribute('aria-hidden','false');
        disableBodyScroll();
        setStars(ratingValue);
        setTimeout(()=> { if (starButtons[ ratingValue - 1 ]) starButtons[ ratingValue - 1 ].focus(); }, 120);
      });
    }
    if (cancelClose) {
      cancelClose.addEventListener('click', function(){
        closeModal.classList.remove('open');
        closeModal.setAttribute('aria-hidden','true');
        enableBodyScroll();
      });
    }

    if (submitClose) {
      submitClose.addEventListener('click', async function(){
        if (!chatId) { alert('Ошибка: нет чата'); return; }
        submitClose.disabled = true;
        submitClose.textContent = 'Отправка...';

        const payload = new FormData();
        payload.append('rating', String(ratingValue));
        payload.append('comment', reviewComment.value || '');

        const res = await closeChatServer(payload);
        submitClose.disabled = false;
        submitClose.textContent = 'Отправить отзыв и закрыть';
        if (res && res.ok) {
          msgInput.disabled = true;
          if (sendBtn) sendBtn.disabled = true;
          if (closeBtn) closeBtn.disabled = true;

          closeModal.classList.remove('open');
          closeModal.setAttribute('aria-hidden','true');
          enableBodyScroll();

          const reviewBlock = document.createElement('div');
          reviewBlock.className = 'review-summary';
          const score = document.createElement('div');
          score.className = 'score';
          score.textContent = ratingValue + '/5';
          const details = document.createElement('div');
          const title = document.createElement('div');
          title.style.fontWeight = '700';
          title.textContent = 'Спасибо за отзыв';
          const sub = document.createElement('div');
          sub.className = 'small-muted';
          sub.style.marginTop = '6px';
          sub.textContent = reviewComment.value ? reviewComment.value : '— комментариев нет —';
          details.appendChild(title);
          details.appendChild(sub);
          reviewBlock.appendChild(score);
          reviewBlock.appendChild(details);

          chatContainer.appendChild(reviewBlock);

          const sys = document.createElement('div');
          sys.className = 'msg support';
          const bub = document.createElement('div');
          bub.className = 'bubble support';
          bub.innerHTML = 'Чат закрыт. Спасибо за отзыв.';
          const meta = document.createElement('div');
          meta.className = 'meta';
          const closedAt = (res && res.closed_at) ? res.closed_at : (new Date()).toISOString().slice(0,19).replace('T',' ');
          meta.textContent = closedAt;
          sys.appendChild(bub);
          sys.appendChild(meta);
          chatWindow.appendChild(sys);
          chatWindow.scrollTop = chatWindow.scrollHeight;

          const existingNote = chatContainer.querySelector('.closed-note');
          if (!existingNote) {
            const note = document.createElement('div');
            note.className = 'closed-note';
            note.textContent = 'Этот чат закрыт. Спасибо за обращение.';
            chatContainer.appendChild(note);
          }
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
