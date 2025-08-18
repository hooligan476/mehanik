<?php require_once __DIR__.'/../middleware.php'; require_auth(); ?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>Чат с поддержкой</title>
<link rel="stylesheet" href="/mehanik/assets/css/style.css"></head><body>
<div class="chat">
  <div id="chatWindow" class="chat-window"></div>
  <form id="chatForm" class="chat-form">
    <input type="text" id="message" placeholder="Ваш вопрос..." required>
    <button type="submit">Отправить</button>
  </form>
</div>
<script src="/mehanik/assets/js/chat.js"></script>
</body></html>
