<?php
// mehanik/api/admin-stats.php
header('Content-Type: application/json; charset=utf-8');

// подключаем middleware и проверяем права (путь к middleware.php)
require_once __DIR__ . '/../middleware.php';
require_admin();

// DB
$dbHost = '127.0.0.1';
$dbName = 'mehanik';
$dbUser = 'root';
$dbPass = '';

try {
    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection error']);
    exit;
}

try {
    // Основные счётчики
    $users = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $products = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
    $brands = (int)$pdo->query("SELECT COUNT(*) FROM brands")->fetchColumn();
    $models = (int)$pdo->query("SELECT COUNT(*) FROM models")->fetchColumn();
    $parts = (int)$pdo->query("SELECT COUNT(*) FROM complex_parts")->fetchColumn();
    $components = (int)$pdo->query("SELECT COUNT(*) FROM components")->fetchColumn();
    $messages = (int)$pdo->query("SELECT COUNT(*) FROM messages")->fetchColumn();

    // Чаты
    $closed_chats = (int)$pdo->query("SELECT COUNT(*) FROM chats WHERE status='closed'")->fetchColumn();
    $total_chats = (int)$pdo->query("SELECT COUNT(*) FROM chats")->fetchColumn();
    $open_chats = max(0, $total_chats - $closed_chats);

    // --- users_by_date (последние 30 дней, включая нули) ---
    $days = 30;
    $start = (new DateTime())->modify("-" . ($days-1) . " days")->setTime(0,0,0);
    $end = (new DateTime())->setTime(23,59,59);
    $stmt = $pdo->prepare("
        SELECT DATE(created_at) as dt, COUNT(*) as cnt
        FROM users
        WHERE created_at BETWEEN :start AND :end
        GROUP BY DATE(created_at)
        ORDER BY DATE(created_at) ASC
    ");
    $stmt->execute([':start' => $start->format('Y-m-d H:i:s'), ':end' => $end->format('Y-m-d H:i:s')]);
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // dt => cnt

    $users_by_date = [];
    $cur = clone $start;
    for ($i=0; $i<$days; $i++) {
        $d = $cur->format('Y-m-d');
        $users_by_date[] = ['date' => $d, 'count' => isset($rows[$d]) ? (int)$rows[$d] : 0];
        $cur->modify('+1 day');
    }

    // --- products_by_brand ---
    $colStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'products'");
    $colStmt->execute([':db' => $dbName]);
    $cols = $colStmt->fetchAll(PDO::FETCH_COLUMN);

    $products_by_brand = [];
    if (in_array('brand_id', $cols)) {
        $hasBrands = (bool)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$dbName}' AND TABLE_NAME = 'brands'")->fetchColumn();
        if ($hasBrands) {
            $sql = "
                SELECT COALESCE(b.name, 'Unknown') AS brand, COUNT(*) AS cnt
                FROM products p
                LEFT JOIN brands b ON b.id = p.brand_id
                GROUP BY COALESCE(b.name, 'Unknown')
                ORDER BY cnt DESC
                LIMIT 50
            ";
            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) $products_by_brand[] = ['brand' => $r['brand'], 'count' => (int)$r['cnt']];
        }
    } elseif (in_array('brand', $cols) || in_array('brand_name', $cols)) {
        $col = in_array('brand', $cols) ? 'brand' : 'brand_name';
        $sql = "SELECT COALESCE({$col}, 'Unknown') AS brand, COUNT(*) AS cnt FROM products GROUP BY COALESCE({$col}, 'Unknown') ORDER BY cnt DESC LIMIT 50";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) $products_by_brand[] = ['brand' => $r['brand'], 'count' => (int)$r['cnt']];
    } else {
        $products_by_brand = [];
    }

    // --- messages_by_date (последние 30 дней) ---
    $stmt = $pdo->prepare("
        SELECT DATE(created_at) as dt, COUNT(*) as cnt
        FROM messages
        WHERE created_at BETWEEN :start AND :end
        GROUP BY DATE(created_at)
        ORDER BY DATE(created_at) ASC
    ");
    $stmt->execute([':start' => $start->format('Y-m-d H:i:s'), ':end' => $end->format('Y-m-d H:i:s')]);
    $msgRows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $messages_by_date = [];
    $cur = clone $start;
    for ($i=0; $i<$days; $i++) {
        $d = $cur->format('Y-m-d');
        $messages_by_date[] = ['date' => $d, 'count' => isset($msgRows[$d]) ? (int)$msgRows[$d] : 0];
        $cur->modify('+1 day');
    }

    // Ответ
    $out = [
        'users' => $users,
        'products' => $products,
        'brands' => $brands,
        'models' => $models,
        'parts' => $parts,
        'components' => $components,
        'messages' => $messages,
        'open_chats' => $open_chats,
        'closed_chats' => $closed_chats,
        'users_by_date' => $users_by_date,
        'products_by_brand' => $products_by_brand,
        'messages_by_date' => $messages_by_date,
    ];

    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Query error']);
    exit;
}
