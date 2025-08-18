<?php require_once __DIR__.'/../middleware.php'; require_once __DIR__.'/../db.php'; header('Content-Type: application/json');
require_auth();
$uid=$_SESSION['user']['id'];
$action=$_GET['action'] ?? $_POST['action'] ?? 'list';

$stmt=$mysqli->prepare("SELECT id,status FROM chats WHERE user_id=? AND status='open' ORDER BY id DESC LIMIT 1");
$stmt->bind_param('i',$uid); $stmt->execute(); $chat=$stmt->get_result()->fetch_assoc();
if(!$chat){ $mysqli->query("INSERT INTO chats(user_id) VALUES ($uid)"); $chat=['id'=>$mysqli->insert_id,'status'=>'open']; }
$chat_id=(int)$chat['id'];

if($action==='send'){
  $content=trim($_POST['content'] ?? '');
  if($content===''){ http_response_code(422); echo json_encode(['ok'=>false]); exit; }
  $stmt=$mysqli->prepare("INSERT INTO messages(chat_id,sender,content) VALUES(?, 'user', ?)");
  $stmt->bind_param('is',$chat_id,$content); $stmt->execute();
  echo json_encode(['ok'=>true]);
  exit;
}

$ms=$mysqli->prepare("SELECT sender,content,created_at FROM messages WHERE chat_id=? ORDER BY id ASC");
$ms->bind_param('i',$chat_id); $ms->execute();
$rows=$ms->get_result()->fetch_all(MYSQLI_ASSOC);
echo json_encode(['ok'=>true,'chat_id'=>$chat_id,'messages'=>$rows]);
