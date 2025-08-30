// assets/js/chat.js
// Лёгкий, надёжный polling-клиент для /mehanik/api/chat.php

(function () {
  'use strict';

  const API = '/mehanik/api/chat.php';
  const win = document.getElementById('chatWindow');
  const form = document.getElementById('chatForm');
  const input = document.getElementById('message');
  const sendBtn = document.getElementById('sendBtn') || (form ? form.querySelector('button[type="submit"]') : null);
  const closeBtn = document.getElementById('closeChatBtn');
  const openBtn = document.getElementById('openChatBtn');

  let lastId = 0;
  let polling = null;
  const POLL_MS = 2500;

  function esc(s) {
    return String(s || '').replace(/[&<>"']/g, function (m) {
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[m];
    });
  }

  function fmtMsg(m) {
    const cls = (m.sender === 'user') ? 'user' : 'support';
    const time = esc(m.created_at || '');
    const content = esc(m.content || '');
    return `<div class="msg ${cls}"><div class="bubble">${content}<div class="meta">${time}</div></div></div>`;
  }

  async function load() {
    try {
      const res = await fetch(API + '?last_id=' + encodeURIComponent(lastId), { method: 'GET', credentials: 'same-origin' });
      if (!res.ok) throw new Error('network');
      const j = await res.json();
      if (!j.ok) {
        // backward compatibility: some endpoints may not set ok, handle like previous script
      }
      if (Array.isArray(j.messages) && j.messages.length > 0) {
        // append new messages
        j.messages.forEach(m => {
          // skip duplicates
          if (!m.id || Number(m.id) <= lastId) return;
          win.insertAdjacentHTML('beforeend', fmtMsg(m));
          lastId = Number(m.id);
        });
        win.scrollTop = win.scrollHeight;
      }
    } catch (e) {
      // console.warn('chat load error', e);
    }
  }

  async function sendMessage(text) {
    if (!text) return;
    if (sendBtn) sendBtn.disabled = true;
    try {
      const fd = new FormData();
      fd.append('action', 'send');
      fd.append('content', text);
      const res = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' });
      if (!res.ok) throw new Error('network');
      const j = await res.json();
      if (j.ok) {
        // загрузим новые сообщения (сервер вернёт отправленное сообщение)
        await load();
      } else {
        alert('Ошибка отправки: ' + (j.error || 'unknown'));
      }
    } catch (e) {
      alert('Ошибка сети при отправке сообщения');
      console.error(e);
    } finally {
      if (sendBtn) sendBtn.disabled = false;
    }
  }

  async function closeChatServer() {
    try {
      const fd = new FormData();
      fd.append('action', 'close');
      const res = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' });
      if (!res.ok) throw new Error('network');
      const j = await res.json();
      return j;
    } catch (e) {
      console.error('close chat error', e);
      return { ok: false, error: 'network' };
    }
  }

  function startPolling() {
    if (polling) clearInterval(polling);
    polling = setInterval(load, POLL_MS);
  }

  function stopPolling() {
    if (polling) clearInterval(polling);
    polling = null;
  }

  // UI handlers
  document.addEventListener('DOMContentLoaded', function () {
    if (!win) return;

    // initial load
    lastId = 0;
    win.innerHTML = ''; // clean
    load().then(() => {
      win.scrollTop = win.scrollHeight;
    });
    startPolling();

    if (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        const v = input.value.trim();
        if (!v) return;
        input.value = '';
        sendMessage(v);
        input.focus();
      });
    }

    if (closeBtn) {
      closeBtn.addEventListener('click', async function () {
        const goHome = confirm('Закрыть чат?\n\nОК — перейти на главную\nОтмена — скрыть чат на этой странице');
        if (goHome) {
          // Закрыть чат серверно, затем перейти на главную
          await closeChatServer();
          window.location.href = '/mehanik/public/index.php';
        } else {
          // просто скрываем контейнер и показываем кнопку "Открыть чат"
          const container = document.getElementById('chatContainer');
          if (container) container.style.display = 'none';
          if (openBtn) openBtn.style.display = 'block';
          stopPolling();
        }
      });
    }

    if (openBtn) {
      openBtn.addEventListener('click', function () {
        const container = document.getElementById('chatContainer');
        if (container) container.style.display = 'flex';
        openBtn.style.display = 'none';
        // reload messages and resume polling
        lastId = 0;
        win.innerHTML = '';
        load();
        startPolling();
      });
    }
  }, false);
})();
