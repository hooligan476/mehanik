<?php
// mehanik/api/user_search.php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

// Подключаем DB (корень проекта один уровень выше /mehanik)
$projectRoot = dirname(__DIR__);
$dbPath = $projectRoot . '/db.php';
if (!file_exists($dbPath)) {
    echo json_encode(['ok'=>false,'error'=>'db.php not found']);
    exit;
}
require_once $dbPath;

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '') {
    echo json_encode(['ok'=>true,'items'=>[]], JSON_UNESCAPED_UNICODE);
    exit;
}

// normalize for phone search: strip non-digits
$digits = preg_replace('~\D+~', '', $q);

// prepare result container
$items = [];

try {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        // If query is all digits and not too long, try ID exact match first
        if (ctype_digit($q) && strlen($q) <= 10) {
            $id = (int)$q;
            $st = $mysqli->prepare("SELECT id,name,phone,role,status,balance FROM users WHERE id = ? LIMIT 1");
            if ($st) {
                $st->bind_param('i', $id);
                $st->execute();
                $res = $st->get_result();
                if ($r = $res->fetch_assoc()) {
                    $items[] = $r;
                }
                $st->close();
            }
        }

        // Then search by name / phone (LIKE)
        // Use prepared LIKE with surrounding %.
        // For phone: search REPLACE(phone, '[^0-9]','') LIKE %digits% — MySQL has no regex replace; simple approach: search phone LIKE '%q%' and also by digits tail if available.
        $like = '%' . $mysqli->real_escape_string($q) . '%';
        $sql = "SELECT id,name,phone,role,status,balance
                FROM users
                WHERE (name LIKE ? OR phone LIKE ?)";
        // avoid duplicating same row if id already added
        $st = $mysqli->prepare($sql);
        if ($st) {
            $st->bind_param('ss', $like, $like);
            $st->execute();
            $res = $st->get_result();
            while ($r = $res->fetch_assoc()) {
                // skip duplicate id
                $skip = false;
                foreach ($items as $it) { if ((int)$it['id'] === (int)$r['id']) { $skip = true; break; } }
                if (!$skip) $items[] = $r;
            }
            $st->close();
        }

        // If still empty and we have digits from q, try searching phone stripped of non-digits by fetching candidates and comparing digits
        if (empty($items) && $digits !== '') {
            $st2 = $mysqli->prepare("SELECT id,name,phone,role,status,balance FROM users WHERE phone IS NOT NULL AND phone <> '' LIMIT 50");
            if ($st2) {
                $st2->execute();
                $res2 = $st2->get_result();
                while ($r = $res2->fetch_assoc()) {
                    $p = preg_replace('~\D+~','', (string)$r['phone']);
                    if ($p !== '' && strpos($p, $digits) !== false) {
                        $items[] = $r;
                    }
                }
                $st2->close();
            }
        }

    } elseif (isset($pdo) && $pdo instanceof PDO) {
        // PDO path
        if (ctype_digit($q) && strlen($q) <= 10) {
            $id = (int)$q;
            $st = $pdo->prepare("SELECT id,name,phone,role,status,balance FROM users WHERE id = :id LIMIT 1");
            $st->execute([':id'=>$id]);
            if ($r = $st->fetch(PDO::FETCH_ASSOC)) $items[] = $r;
        }
        $like = '%' . $q . '%';
        $st = $pdo->prepare("SELECT id,name,phone,role,status,balance FROM users WHERE (name LIKE :like OR phone LIKE :like) LIMIT 100");
        $st->execute([':like'=>$like]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $skip = false;
            foreach ($items as $it) { if ((int)$it['id'] === (int)$r['id']) { $skip = true; break; } }
            if (!$skip) $items[] = $r;
        }
        if (empty($items) && $digits !== '') {
            $st2 = $pdo->query("SELECT id,name,phone,role,status,balance FROM users");
            $rows2 = $st2->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows2 as $r) {
                $p = preg_replace('~\D+~','', (string)$r['phone']);
                if ($p !== '' && strpos($p, $digits) !== false) $items[] = $r;
            }
        }
    }
} catch (Throwable $e) {
    // silent fail - return empty list
}

// limit items to 20 for performance
if (count($items) > 20) $items = array_slice($items, 0, 20);

// normalize minimal fields and return
$out = [];
foreach ($items as $r) {
    $out[] = [
        'id' => (int)($r['id'] ?? 0),
        'name' => $r['name'] ?? '',
        'phone' => $r['phone'] ?? '',
        'role' => $r['role'] ?? '',
        'status' => $r['status'] ?? '',
        'balance' => isset($r['balance']) ? (float)$r['balance'] : 0.0
    ];
}

echo json_encode(['ok'=>true,'items'=>$out], JSON_UNESCAPED_UNICODE);
exit;
