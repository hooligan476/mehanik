// /mehanik/assets/js/chat.js
(function () {
  'use strict';

  const API = '/mehanik/api/chat.php';
  const win = document.getElementById('chatWindow');
  const form = document.getElementById('chatForm');
  const input = document.getElementById('message');
  const POLL_MS = 3000;

  // session keys
  const KEY_HTML = 'mehanik_chat_html';
  const KEY_LAST = 'mehanik_chat_last_id';

  // state
  let lastId = parseInt(sessionStorage.getItem(KEY_LAST) || '0', 10) || 0;
  let sending = false;
  let poller = null;

  function esc(s) {
    return String(s || '').replace(/[&<>"']/g, function (m) {
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]);
    });
  }

  function saveCache() {
    try {
      sessionStorage.setItem(KEY_HTML, win.innerHTML);
      sessionStorage.setItem(KEY_LAST, String(lastId || 0));
    } catch (e) {
      // ignore storage errors
      // console.warn('sessionStorage set error', e);
    }
  }

  function formatMsgHtml(m) {
    const senderClass = esc(m.sender || 'user');
    const content = esc(m.content || '');
    const time = esc(m.created_at || '');
    return `<div class="msg ${senderClass}"><b>${senderClass}:</b> ${content} <span>${time}</span></div>`;
  }

  function appendMessage(m) {
    if (!m || typeof m.id === 'undefined') return;
    if (Number(m.id) <= Number(lastId)) return; // уже есть
    // добавляем
    win.insertAdjacentHTML('beforeend', formatMsgHtml(m));
    lastId = Number(m.id);
    saveCache();
    // автоскрол в конец
    win.scrollTop = win.scrollHeight;
  }

  async function loadAll() {
    try {
      const res = await fetch(API, { method: 'GET', credentials: 'same-origin' });
      if (!res.ok) throw new Error('network');
      const j = await res.json();
      if (!j || !Array.isArray(j.messages)) return;
      // если в sessionStorage есть HTML — используем его немедленно, но затем применим дельту
      // На случай первой загрузки, если нет кеша — рендерим всё
      if (!sessionStorage.getItem(KEY_HTML)) {
        win.innerHTML = j.messages.map(formatMsgHtml).join('');
        if (j.messages.length) lastId = Number(j.messages[j.messages.length - 1].id || lastId);
        saveCache();
        win.scrollTop = win.scrollHeight;
        return;
      }
      // иначе пройдёмся по сообщениям и добавим только новые (id > lastId)
      j.messages.forEach(m => appendMessage(m));
    } catch (e) {
      console.error('chat load error', e);
    }
  }

  async function sendMessage(text) {
    if (!text || sending) return;
    sending = true;
    try {
      const fd = new FormData();
      fd.append('action', 'send');
      fd.append('content', text);
      const res = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' });
      if (!res.ok) throw new Error('network');
      // мы не требуем от API полноценного ответа, но загрузим новые сообщения после отправки
      await loadAll();
    } catch (e) {
      console.error('sendMessage error', e);
      alert('Ошибка отправки сообщения (проверьте соединение).');
    } finally {
      sending = false;
    }
  }

  // инициализация UI, handlers
  function initUI() {
    // попробуем восстановить HTML из sessionStorage для мгновенного отображения
    try {
      const cached = sessionStorage.getItem(KEY_HTML);
      if (cached && win.innerHTML.trim() === '') {
        win.innerHTML = cached;
        // можно попытаться восстановить lastId тоже
        const sLast = sessionStorage.getItem(KEY_LAST);
        if (sLast) lastId = parseInt(sLast, 10) || lastId;
        // прокручиваем вниз
        win.scrollTop = win.scrollHeight;
      }
    } catch (e) {
      // ignore
    }

    if (form) {
      form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const v = input.value.trim();
        if (!v) return;
        input.value = '';
        input.focus();
        await sendMessage(v);
      });
    }
  }

  function startPolling() {
    if (poller) clearInterval(poller);
    poller = setInterval(loadAll, POLL_MS);
  }

  // Run on DOM ready
  document.addEventListener('DOMContentLoaded', () => {
    initUI();
    // ensure immediate load to sync with server (and fill missed messages)
    loadAll();
    startPolling();
  }, false);

  // expose helpers for debugging if needed
  window.__mehanik_chat = {
    reload: loadAll,
    send: sendMessage,
    clearCache: function(){ sessionStorage.removeItem(KEY_HTML); sessionStorage.removeItem(KEY_LAST); lastId = 0; win.innerHTML=''; }
  };
})();
