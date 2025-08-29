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
    .chat-container {
      max-width: 800px;
      margin: 80px auto;
      padding: 20px;
      background: #fff;
      border-radius: 12px;
      box-shadow: 0 4px 15px rgba(0,0,0,0.1);
      display: flex;
      flex-direction: column;
      height: 80vh;
    }
    .chat-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding-bottom: 10px;
      border-bottom: 1px solid #ddd;
    }
    .chat-header h2 {
      margin: 0;
      font-size: 20px;
    }
    #closeChatBtn {
      background: #dc3545;
      border: none;
      color: #fff;
      padding: 6px 12px;
      border-radius: 6px;
      cursor: pointer;
      font-size: 14px;
    }
    #chatWindow {
      flex: 1;
      overflow-y: auto;
      padding: 15px;
      margin: 15px 0;
      border-radius: 8px;
      background: #f9f9f9;
      font-size: 15px;
    }
    .msg {
      margin-bottom: 10px;
      display: flex;
    }
    .msg.user {
      justify-content: flex-end;
    }
    .msg.support {
      justify-content: flex-start;
    }
    .bubble {
      max-width: 65%;
      padding: 10px 14px;
      border-radius: 12px;
      line-height: 1.4;
      position: relative;
    }
    .user .bubble {
      background: #007bff;
      color: #fff;
      border-bottom-right-radius: 4px;
    }
    .support .bubble {
      background: #e9ecef;
      color: #333;
      border-bottom-left-radius: 4px;
    }
    .meta {
      font-size: 11px;
      color: #666;
      margin-top: 4px;
      text-align: right;
    }
    .chat-form {
      display: flex;
      border-top: 1px solid #ddd;
      padding-top: 10px;
    }
    .chat-form input {
      flex: 1;
      padding: 10px;
      border: 1px solid #ccc;
      border-radius: 6px;
      font-size: 14px;
    }
    .chat-form button {
      margin-left: 10px;
      padding: 10px 18px;
      background: #28a745;
      border: none;
      border-radius: 6px;
      color: #fff;
      cursor: pointer;
      font-size: 14px;
    }
    .chat-form button:disabled {
      background: #aaa;
      cursor: not-allowed;
    }
  </style>
</head>
<body>
  <?php include __DIR__ . '/header.php'; ?>

  <div class="chat-container">
    <div class="chat-header">
      <h2>Чат с поддержкой</h2>
      <button id="closeChatBtn">Закрыть</button>
    </div>

    <div id="chatWindow"></div>

    <form id="chatForm" class="chat-form">
      <input type="text" id="message" placeholder="Напишите сообщение..." required>
      <button type="submit" id="sendBtn">Отправить</button>
    </form>
  </div>

  <script src="/mehanik/assets/js/chat.js"></script>
</body>
</html>
