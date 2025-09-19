<?php
// mehanik/public/api/user_search.php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

// only admins allowed
$user = $_SESSION['user'] ?? null;
$role = strtolower((string)($user['role'] ?? ''));
$isSuper = ((int)($user['is_superadmin'] ?? 0) === 1);
if (!$user || !in_array($role, ['admin','superadmin'], true) && !$isSuper) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'access_denied']);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '') {
    echo json_encode(['ok'=>true,'items'=>[]]);
    exit;
}

// normalize
$raw = $q;
$items = [];
$limit = 12;

try {
    // try mysqli first (db.php should set $mysqli), fallback to PDO ($pdo)
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        // search by id if numeric
        if (preg_match('/^\s*#?(\d+)\s*$/', $raw, $m)) {
            $id = (int)$m[1];
            $st = $mysqli->prepare("SELECT id,name,phone,role,status,balance FROM users WHERE id = ? LIMIT ?");
            $st->bind_param('ii', $id, $limit);
            $st->execute();
            $res = $st->get_result();
            while ($r = $res->fetch_assoc()) $items[] = $r;
            $st->close();
        } else {
            // search by phone or name (partial)
            $like = '%' . str_replace(['%','_'], ['\\%','\\_'], $raw) . '%';
            $st = $mysqli->prepare("SELECT id,name,phone,role,status,balance FROM users WHERE phone LIKE ? OR name LIKE ? LIMIT ?");
            $st->bind_param('ssi', $like, $like, $limit);
            $st->execute();
            $res = $st->get_result();
            while ($r = $res->fetch_assoc()) $items[] = $r;
            $st->close();
        }
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        if (preg_match('/^\s*#?(\d+)\s*$/', $raw, $m)) {
            $id = (int)$m[1];
            $st = $pdo->prepare("SELECT id,name,phone,role,status,balance FROM users WHERE id = :id LIMIT :lim");
            $st->bindValue(':id', $id, PDO::PARAM_INT);
            $st->bindValue(':lim', $limit, PDO::PARAM_INT);
            $st->execute();
            $items = $st->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $like = '%' . str_replace(['%','_'], ['\\%','\\_'], $raw) . '%';
            $st = $pdo->prepare("SELECT id,name,phone,role,status,balance FROM users WHERE phone LIKE :like OR name LIKE :like LIMIT :lim");
            $st->bindValue(':like', $like, PDO::PARAM_STR);
            $st->bindValue(':lim', $limit, PDO::PARAM_INT);
            $st->execute();
            $items = $st->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        throw new Exception('no_db');
    }

    // normalize items (ensure fields are strings/numbers)
    $out = [];
    foreach ($items as $it) {
        $out[] = [
            'id' => (int)($it['id'] ?? 0),
            'name' => (string)($it['name'] ?? ''),
            'phone' => (string)($it['phone'] ?? ''),
            'role' => (string)($it['role'] ?? ''),
            'status' => (string)($it['status'] ?? ''),
            'balance' => isset($it['balance']) ? number_format((float)$it['balance'],2,'.','') : '0.00'
        ];
    }

    echo json_encode(['ok'=>true,'items'=>$out], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'server_error','message'=> $e->getMessage() ]);
    exit;
}
