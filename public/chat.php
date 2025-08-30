<?php 
require_once __DIR__.'/../middleware.php'; 
require_auth(); 
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
    #chatWindow { flex:1; overflow:auto; padding:12px; background:#fafafa; border-radius:8px; margin:12px 0; }
    .chat-form { display:flex; gap:8px; border-top:1px solid #eee; padding-top:10px; }
    .chat-form input { flex:1; padding:10px; border:1px solid #ddd; border-radius:6px; }
    .chat-form button { padding:10px 14px; border-radius:6px; border:0; background:#28a745; color:#fff; cursor:pointer; }
    #closeChatBtn { background:#dc3545; padding:6px 10px; color:#fff; border-radius:6px; border:0; cursor:pointer; }
    #openChatBtn { position: fixed; right:18px; bottom:18px; display:none; background:#007bff; color:#fff; padding:12px 16px; border-radius:999px; border:0; cursor:pointer; box-shadow:0 6px 20px rgba(0,0,0,.12); z-index:9999; }
    .msg { margin-bottom:10px; display:flex; }
    .msg.user { justify-content:flex-end; }
    .msg.support { justify-content:flex-start; }
    .bubble { max-width:65%; padding:10px 12px; border-radius:12px; line-height:1.4; }
    .user .bubble { background:#007bff; color:#fff; border-bottom-right-radius:4px; }
    .support .bubble { background:#eef2f6; color:#111; border-bottom-left-radius:4px; }
    .meta { font-size:11px; color:#666; margin-top:6px; text-align:right; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/header.php'; ?>

  <div class="chat-container" id="chatContainer">
    <div class="chat-header">
      <h2 style="margin:0;">Чат с поддержкой</h2>
      <button id="closeChatBtn" type="button">Закрыть</button>
    </div>

    <div id="chatWindow"></div>

    <form id="chatForm" class="chat-form">
      <input type="text" id="message" placeholder="Ваш вопрос..." autocomplete="off" required>
      <button type="submit" id="sendBtn">Отправить</button>
    </form>
  </div>

  <button id="openChatBtn" aria-label="Открыть чат">Открыть чат</button>

  <script src="/mehanik/assets/js/chat.js"></script>
</body>
</html>
