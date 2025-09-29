// assets/js/chat.js (updated)
(function () {
  'use strict';

  const API = '/mehanik/api/chat.php';
  const win = document.getElementById('chatWindow');
  const form = document.getElementById('chatForm');
  const input = document.getElementById('message');
  const sendBtn = document.getElementById('sendBtn') || (form ? form.querySelector('button[type="submit"]') : null);
  const closeBtn = document.getElementById('closeChatBtn');
  const openBtn = document.getElementById('openChatBtn');

  let chatId = null;
  const container = document.getElementById('chatContainer');
  if (container && container.dataset && container.dataset.chatId) chatId = String(container.dataset.chatId);
  else {
    const hidden = document.querySelector('input[name="chat_id"]');
    if (hidden) chatId = String(hidden.value || '');
  }

  let lastId = 0;
  let polling = null;
  const POLL_MS = 2500;

  function esc(s) { return String(s || '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[m]); }
  function fmtMsg(m) { const cls = (m.sender === 'user') ? 'user' : 'support'; const time = esc(m.created_at || ''); const content = esc(m.content || ''); return `<div class="msg ${cls}"><div class="bubble ${cls}">${content}<div class="meta">${time}</div></div></div>`; }

  async function tryParseJSON(response) {
    const text = await response.text();
    try { return { json: JSON.parse(text), text }; } catch (e) { return { json: null, text }; }
  }

  async function load() {
    // НЕ будем стучать в API если у нас нет chatId — чтобы не создавать чат автоматически.
    if (!win || !chatId) return;
    try {
      const url = API + '?last_id=' + encodeURIComponent(lastId) + '&chat_id=' + encodeURIComponent(chatId);
      const res = await fetch(url, { method: 'GET', credentials: 'same-origin', cache: 'no-store' });

      if (res.status === 401 || res.redirected) { stopPolling(); console.warn('Not authenticated or redirected while loading chat.', res.status, res.url); return; }
      if (!res.ok) { const parsed = await tryParseJSON(res); console.warn('Load failed:', res.status, parsed.text); return; }

      const { json } = await tryParseJSON(res);
      if (!json) { console.warn('Non-JSON response for load:', (await res.clone().text()).slice(0,200)); return; }

      if (Array.isArray(json.messages) && json.messages.length) {
        json.messages.forEach(m => { if (!m.id || Number(m.id) <= lastId) return; win.insertAdjacentHTML('beforeend', fmtMsg(m)); lastId = Number(m.id); });
        win.scrollTop = win.scrollHeight;
      }
    } catch (err) { console.warn('chat load error', err); }
  }

  async function sendMessage(text) {
    if (!text) return;
    if (sendBtn) sendBtn.disabled = true;
    try {
      const fd = new FormData();
      fd.append('action', 'send');
      fd.append('content', text);
      if (chatId) fd.append('chat_id', chatId);

      const res = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' });

      if (res.status === 401 || res.redirected) {
        const parsed = await tryParseJSON(res);
        console.warn('sendMessage redirect/401', res.status, parsed.text);
        alert('Сессия истекла — пожалуйста, войдите снова.');
        return;
      }

      if (!res.ok) {
        const parsed = await tryParseJSON(res);
        const serverMsg = (parsed.json && parsed.json.error) ? parsed.json.error : parsed.text || ('HTTP ' + res.status);
        alert('Ошибка отправки: ' + serverMsg);
        return;
      }

      const parsed = await tryParseJSON(res);
      if (!parsed.json) { alert('Неправильный ответ сервера: ' + (parsed.text || 'no body')); console.error('Invalid JSON:', parsed.text); return; }

      if (parsed.json.ok) {
        // Если сервер вернул chat_id (созданный сейчас) — запомним его и стартуем polling
        if (parsed.json.chat_id && !chatId) {
          chatId = String(parsed.json.chat_id);
          if (container) container.dataset.chatId = chatId;
          if (!polling) startPolling();
        }

        if (parsed.json.message) {
          const m = parsed.json.message;
          if (m.id && Number(m.id) > lastId) { win.insertAdjacentHTML('beforeend', fmtMsg(m)); lastId = Number(m.id); win.scrollTop = win.scrollHeight; }
        } else {
          // если сообщение не пришло — загрузим свежие
          await load();
        }
        return;
      } else {
        alert('Ошибка отправки: ' + (parsed.json.error || 'unknown'));
      }
    } catch (e) {
      console.error('sendMessage error', e);
      alert('Сетевой сбой при отправке сообщения — проверьте соединение и повторите. Откройте консоль для деталей.');
    } finally {
      if (sendBtn) sendBtn.disabled = false;
    }
  }

  async function closeChatServer() {
    try {
      const fd = new FormData();
      fd.append('action', 'close');
      if (chatId) fd.append('chat_id', chatId);
      const res = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' });
      if (!res.ok) { const parsed = await tryParseJSON(res); return { ok: false, error: (parsed.json && parsed.json.error) ? parsed.json.error : parsed.text || 'HTTP ' + res.status }; }
      const { json } = await tryParseJSON(res);
      return json || { ok: false, error: 'invalid_json' };
    } catch (e) { console.error('close chat error', e); return { ok: false, error: 'network' }; }
  }

  function startPolling() { if (polling) clearInterval(polling); if (!chatId) return; polling = setInterval(load, POLL_MS); }
  function stopPolling() { if (polling) clearInterval(polling); polling = null; }

  document.addEventListener('DOMContentLoaded', function () {
    if (!win) return;
    lastId = 0; win.innerHTML = '';

    // Если сервер уже указал chat_id в разметке — загрузим и стартуем polling,
    // иначе не стучим в API и ждём первой отправки сообщения.
    if (chatId) {
      load().then(()=>win.scrollTop = win.scrollHeight);
      startPolling();
    }

    if (form) form.addEventListener('submit', function (e) { e.preventDefault(); const v = input.value.trim(); if (!v) return; input.value = ''; sendMessage(v); input.focus(); });

    if (closeBtn) closeBtn.addEventListener('click', async function () {
      const goHome = confirm('Закрыть чат?\n\nОК — перейти на главную\nОтмена — скрыть чат на этой странице');
      if (goHome) { await closeChatServer(); window.location.href = '/mehanik/public/index.php'; }
      else { const container = document.getElementById('chatContainer'); if (container) container.style.display = 'none'; if (openBtn) openBtn.style.display = 'block'; stopPolling(); }
    });

    if (openBtn) openBtn.addEventListener('click', function () { const container = document.getElementById('chatContainer'); if (container) container.style.display = 'flex'; openBtn.style.display = 'none'; lastId = 0; win.innerHTML = ''; if (chatId) { load(); startPolling(); } });
  }, false);
})();
