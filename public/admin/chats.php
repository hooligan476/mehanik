<?php require_once __DIR__.'/../../middleware.php'; require_admin(); require_once __DIR__.'/../../db.php'; ?> 
<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>Админка — Чаты</title>
<link rel="stylesheet" href="/mehanik/assets/css/style.css"></head><body>
<h2 style="padding:16px;">Чаты поддержки</h2>
<?php $ch=$mysqli->query("SELECT c.id,u.email,c.status,c.created_at FROM chats c JOIN users u ON u.id=c.user_id ORDER BY c.id DESC"); ?>
<table class="table">
  <tr><th>ID</th><th>Пользователь</th><th>Статус</th><th>Создан</th><th>Действие</th></tr>
  <?php while($row=$ch->fetch_assoc()): ?>
    <tr>
      <td><?= $row['id'] ?></td>
      <td><?= htmlspecialchars($row['email']) ?></td>
      <td><?= $row['status'] ?></td>
      <td><?= $row['created_at'] ?></td>
      <td>
        <form method="post" style="display:inline">
          <input type="hidden" name="chat_id" value="<?= $row['id'] ?>">
          <button name="reply" value="1">Открыть</button>
        </form>
        <form method="post" style="display:inline">
          <input type="hidden" name="chat_id" value="<?= $row['id'] ?>">
          <button name="close" value="1">Закрыть</button>
        </form>
      </td>
    </tr>
  <?php endwhile; ?>
</table>
<?php
if($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['reply'])){
  $chat_id=(int)$_POST['chat_id'];
  echo '<h3 style="padding:16px;">Чат #'.$chat_id.'</h3>';
  echo '<div style="padding:16px;">';
  $msgs=$mysqli->query("SELECT sender,content,created_at FROM messages WHERE chat_id=$chat_id ORDER BY id ASC");
  while($m=$msgs->fetch_assoc()){
    echo '<div><b>'.htmlspecialchars($m['sender']).':</b> '.htmlspecialchars($m['content']).' <small>'.$m['created_at'].'</small></div>';
  }
  echo '<form method="post"><input type="hidden" name="chat_id" value="'.$chat_id.'">';
  echo '<input type="text" name="content" placeholder="Ответ..." required>'; 
  echo '<button name="send" value="1">Отправить</button></form></div>';
}
if($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['send']) && !empty($_POST['content'])){
  $chat_id=(int)$_POST['chat_id'];
  $stmt=$mysqli->prepare("INSERT INTO messages(chat_id,sender,content) VALUES (?, 'support', ?)");
  $stmt->bind_param('is',$chat_id,$_POST['content']);
  $stmt->execute();
}
if($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['close'])){
  $chat_id=(int)$_POST['chat_id'];
  $mysqli->query("UPDATE chats SET status='closed' WHERE id=$chat_id");
}
?>
</body></html>
