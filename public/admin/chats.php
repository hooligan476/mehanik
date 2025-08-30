<?php
require_once __DIR__.'/../../middleware.php';
require_admin();
require_once __DIR__.'/../../db.php';

// === обработка действий до вывода HTML ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $chat_id = (int)($_POST['chat_id'] ?? 0);

    // закрыть чат
    if (!empty($_POST['close'])) {
        $mysqli->query("UPDATE chats SET status='closed' WHERE id=$chat_id");
        header("Location: chats.php?closed=1");
        exit;
    }

    // удалить чат
    if (!empty($_POST['delete'])) {
        $mysqli->query("DELETE FROM messages WHERE chat_id=$chat_id");
        $mysqli->query("DELETE FROM chats WHERE id=$chat_id");
        header("Location: chats.php?deleted=1");
        exit;
    }

    // отправить сообщение
    if (!empty($_POST['send']) && !empty($_POST['content'])) {
        $stmt = $mysqli->prepare("INSERT INTO messages(chat_id,sender,content) VALUES (?, 'support', ?)");
        $stmt->bind_param('is', $chat_id, $_POST['content']);
        $stmt->execute();
        header("Location: chats.php?reply=".$chat_id);
        exit;
    }

    // открыть чат для просмотра
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
    .chat-table {
        width: 95%;
        margin: 20px auto;
        border-collapse: collapse;
        background: #fff;
        box-shadow: 0 2px 6px rgba(0,0,0,.1);
    }
    .chat-table th, .chat-table td {
        padding: 10px 14px;
        border-bottom: 1px solid #eee;
        text-align: left;
    }
    .chat-table th {
        background: #f9f9f9;
    }
    .btn {
        padding: 6px 12px;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        margin: 2px;
    }
    .btn-open { background:#3498db; color:#fff; }
    .btn-close { background:#e67e22; color:#fff; }
    .btn-delete { background:#e74c3c; color:#fff; }
    .chat-box {
        width: 95%;
        margin: 20px auto;
        background:#fff;
        border-radius:8px;
        box-shadow: 0 2px 6px rgba(0,0,0,.1);
        padding:16px;
    }
    .message {
        padding:8px 12px;
        margin:6px 0;
        border-radius:6px;
    }
    .message.support { background:#ecf5ff; }
    .message.user { background:#fef9e7; }
    .message small { color:#888; font-size:12px; }
    .reply-form {
        margin-top:12px;
        display:flex;
        gap:8px;
    }
    .reply-form input[type=text] {
        flex:1;
        padding:8px;
        border-radius:6px;
        border:1px solid #ccc;
    }
    .reply-form button {
        padding:8px 14px;
        border:none;
        border-radius:6px;
        background:#2ecc71;
        color:#fff;
        cursor:pointer;
    }
  </style>
</head>
<body>
<?php require_once __DIR__.'/header.php'; ?>

<h2 style="padding:16px;">Чаты поддержки</h2>

<?php
$ch = $mysqli->query("
    SELECT c.id, u.phone, c.status, c.created_at 
    FROM chats c 
    JOIN users u ON u.id = c.user_id 
    ORDER BY c.id DESC
");
?>
<table class="chat-table">
  <tr>
    <th>ID</th><th>Пользователь</th><th>Статус</th><th>Создан</th><th>Действие</th>
  </tr>
  <?php while($row = $ch->fetch_assoc()): ?>
    <tr>
      <td><?= $row['id'] ?></td>
      <td><?= htmlspecialchars($row['phone']) ?></td>
      <td><?= $row['status'] ?></td>
      <td><?= $row['created_at'] ?></td>
      <td>
        <form method="post" style="display:inline">
          <input type="hidden" name="chat_id" value="<?= $row['id'] ?>">
          <button class="btn btn-open" name="reply" value="1">Открыть</button>
        </form>
        <form method="post" style="display:inline">
          <input type="hidden" name="chat_id" value="<?= $row['id'] ?>">
          <button class="btn btn-close" name="close" value="1">Закрыть</button>
        </form>
        <form method="post" style="display:inline" onsubmit="return confirm('Удалить чат вместе с сообщениями?');">
          <input type="hidden" name="chat_id" value="<?= $row['id'] ?>">
          <button class="btn btn-delete" name="delete" value="1">Удалить</button>
        </form>
      </td>
    </tr>
  <?php endwhile; ?>
</table>

<?php
// просмотр чата
if (!empty($_GET['reply'])) {
    $chat_id = (int)$_GET['reply'];
    echo '<div class="chat-box">';
    echo '<h3>Чат #'.$chat_id.'</h3>';
    $msgs = $mysqli->query("SELECT sender, content, created_at FROM messages WHERE chat_id=$chat_id ORDER BY id ASC");
    while($m = $msgs->fetch_assoc()){
        $cls = $m['sender']==='support' ? 'support' : 'user';
        echo '<div class="message '.$cls.'"><b>'.htmlspecialchars($m['sender']).':</b> '
            .htmlspecialchars($m['content']).'<br><small>'.$m['created_at'].'</small></div>';
    }
    echo '<form method="post" class="reply-form">
            <input type="hidden" name="chat_id" value="'.$chat_id.'">
            <input type="text" name="content" placeholder="Ответ..." required>
            <button name="send" value="1">Отправить</button>
          </form>';
    echo '</div>';
}
?>

</body>
</html>
