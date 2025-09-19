<?php
// mehanik/api/admin-stats.php
header('Content-Type: application/json; charset=utf-8');

// подключаем middleware и проверяем права
require_once __DIR__ . '/../middleware.php';
require_admin();

// DB (можно заменить подключением из config.php)
$dbHost = '127.0.0.1';
$dbName = 'mehanik';
$dbUser = 'root';
$dbPass = '';

function json_error($msg, $code = 500) {
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    json_error('DB connection error');
}

// Helpers
function tableExists(PDO $pdo, string $db, string $table): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table");
    $st->execute([':db'=>$db, ':table'=>$table]);
    return (int)$st->fetchColumn() > 0;
}
function columnExists(PDO $pdo, string $db, string $table, string $col): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table AND COLUMN_NAME = :col");
    $st->execute([':db'=>$db, ':table'=>$table, ':col'=>$col]);
    return (int)$st->fetchColumn() > 0;
}
function dateSeriesBetween(PDO $pdo, string $table, string $dateCol, DateTime $start, DateTime $end) {
    // returns associative array date=>'count'
    $sql = "SELECT DATE({$dateCol}) AS dt, COUNT(*) AS cnt
            FROM {$table}
            WHERE {$dateCol} BETWEEN :start AND :end
            GROUP BY DATE({$dateCol})
            ORDER BY DATE({$dateCol}) ASC";
    $st = $pdo->prepare($sql);
    $st->execute([':start'=>$start->format('Y-m-d H:i:s'), ':end'=>$end->format('Y-m-d H:i:s')]);
    $rows = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    // ensure keys exist in format Y-m-d
    return $rows ?: [];
}

// Build a 30-day series from associative map (dt=>cnt)
function build30DaySeriesFromMap(array $map, DateTime $start, int $days = 30) {
    $out = [];
    $cur = clone $start;
    for ($i=0; $i<$days; $i++) {
        $d = $cur->format('Y-m-d');
        $out[] = ['date' => $d, 'count' => isset($map[$d]) ? (int)$map[$d] : 0];
        $cur->modify('+1 day');
    }
    return $out;
}

try {
    // Totals (basic)
    $users_total = tableExists($pdo,$dbName,'users') ? (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() : 0;
    $products_total = tableExists($pdo,$dbName,'products') ? (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn() : 0;
    $brands_total = tableExists($pdo,$dbName,'brands') ? (int)$pdo->query("SELECT COUNT(*) FROM brands")->fetchColumn() : 0;
    $models_total = tableExists($pdo,$dbName,'models') ? (int)$pdo->query("SELECT COUNT(*) FROM models")->fetchColumn() : 0;
    $parts_total = tableExists($pdo,$dbName,'complex_parts') ? (int)$pdo->query("SELECT COUNT(*) FROM complex_parts")->fetchColumn() : 0;
    $components_total = tableExists($pdo,$dbName,'components') ? (int)$pdo->query("SELECT COUNT(*) FROM components")->fetchColumn() : 0;
    $messages_total = tableExists($pdo,$dbName,'messages') ? (int)$pdo->query("SELECT COUNT(*) FROM messages")->fetchColumn() : 0;

    // Chats
    $open_chats = 0; $closed_chats = 0; $chats_avg_response = null;
    if (tableExists($pdo,$dbName,'chats')) {
        if (columnExists($pdo,$dbName,'chats','status')) {
            $st = $pdo->query("SELECT status, COUNT(*) AS cnt FROM chats GROUP BY status");
            foreach ($st->fetchAll() as $r) {
                $s = mb_strtolower((string)$r['status']);
                if (strpos($s,'close') !== false || strpos($s,'closed') !== false) $closed_chats += (int)$r['cnt'];
                else $open_chats += (int)$r['cnt'];
            }
        } else {
            $total_chats = (int)$pdo->query("SELECT COUNT(*) FROM chats")->fetchColumn();
            $closed_chats = columnExists($pdo,$dbName,'chats','closed') ? (int)$pdo->query("SELECT COUNT(*) FROM chats WHERE closed=1")->fetchColumn() : 0;
            $open_chats = max(0, $total_chats - $closed_chats);
        }
        // avg response attempt
        if (columnExists($pdo,$dbName,'chats','avg_response_seconds')) {
            $val = $pdo->query("SELECT AVG(avg_response_seconds) FROM chats")->fetchColumn();
            $chats_avg_response = $val !== null ? round((float)$val,2) : null;
        } elseif (columnExists($pdo,$dbName,'chats','created_at') && columnExists($pdo,$dbName,'chats','closed_at')) {
            $val = $pdo->query("SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, closed_at)) FROM chats WHERE closed_at IS NOT NULL")->fetchColumn();
            $chats_avg_response = $val !== null ? round((float)$val,2) : null;
        }
    }

    // date window
    $days = 30;
    $start = (new DateTime())->modify('-' . ($days - 1) . ' days')->setTime(0,0,0);
    $end = (new DateTime())->setTime(23,59,59);

    // === USERS: added_by_date + deleted_by_date (if possible) ===
    $users_added_map = [];
    $users_deleted_map = [];
    if (tableExists($pdo,$dbName,'users')) {
        if (columnExists($pdo,$dbName,'users','created_at')) {
            $users_added_map = dateSeriesBetween($pdo, 'users', 'created_at', $start, $end);
        }
        // detect deletes: deleted_at OR is_deleted+updated_at OR status->deleted with updated_at
        if (columnExists($pdo,$dbName,'users','deleted_at')) {
            $users_deleted_map = dateSeriesBetween($pdo, 'users', 'deleted_at', $start, $end);
        } elseif (columnExists($pdo,$dbName,'users','is_deleted') && columnExists($pdo,$dbName,'users','updated_at')) {
            $sql = "SELECT DATE(updated_at) AS dt, COUNT(*) AS cnt FROM users WHERE COALESCE(is_deleted,0)=1 AND updated_at BETWEEN :start AND :end GROUP BY DATE(updated_at) ORDER BY DATE(updated_at) ASC";
            $st = $pdo->prepare($sql);
            $st->execute([':start'=>$start->format('Y-m-d H:i:s'), ':end'=>$end->format('Y-m-d H:i:s')]);
            $users_deleted_map = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        } elseif (columnExists($pdo,$dbName,'users','status') && columnExists($pdo,$dbName,'users','updated_at')) {
            // consider statuses that likely mean deletion (older logic)
            $vals = ["deleted","removed","banned","inactive"];
            $conds = array_map(function($v) use ($pdo) { return "LOWER(status) = " . $pdo->quote($v); }, $vals);
            $sql = "SELECT DATE(updated_at) AS dt, COUNT(*) AS cnt FROM users WHERE (" . implode(' OR ', $conds) . ") AND updated_at BETWEEN :start AND :end GROUP BY DATE(updated_at) ORDER BY DATE(updated_at) ASC";
            $st = $pdo->prepare($sql);
            $st->execute([':start'=>$start->format('Y-m-d H:i:s'), ':end'=>$end->format('Y-m-d H:i:s')]);
            $users_deleted_map = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        }
    }
    $users_by_date = build30DaySeriesFromMap($users_added_map, $start, $days);
    $users_deleted_by_date = build30DaySeriesFromMap($users_deleted_map, $start, $days);
    $users_added_total = array_sum(array_column($users_by_date,'count'));
    $users_deleted_total = array_sum(array_column($users_deleted_by_date,'count'));

    // === USERS: roles & statuses (explicit) ===
    $users_by_role = [];
    if (tableExists($pdo,$dbName,'users') && columnExists($pdo,$dbName,'users','role')) {
        $st = $pdo->query("SELECT role, COUNT(*) AS cnt FROM users GROUP BY role");
        foreach ($st->fetchAll() as $r) $users_by_role[] = ['role'=>$r['role'],'count'=>(int)$r['cnt']];
    } else {
        // fallback: check boolean flags
        if (tableExists($pdo,$dbName,'users') && (columnExists($pdo,$dbName,'users','is_admin') || columnExists($pdo,$dbName,'users','admin'))) {
            $admins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE COALESCE(is_admin,admin,0)=1")->fetchColumn();
            $users_by_role[] = ['role'=>'admin','count'=>$admins];
            $users_by_role[] = ['role'=>'user','count'=> max(0, $users_total - $admins)];
        }
    }

    $users_by_status = [];
    if (tableExists($pdo,$dbName,'users') && columnExists($pdo,$dbName,'users','status')) {
        // your DB stores statuses as strings like 'pending','approved','rejected' — group by them directly
        $st = $pdo->query("SELECT status AS st, COUNT(*) AS cnt FROM users GROUP BY status");
        foreach ($st->fetchAll() as $r) $users_by_status[] = ['status'=>$r['st'] ?? 'unknown','count'=>(int)$r['cnt']];
    }

    // === PRODUCTS: added/deleted by date ===
    $products_added_map = [];
    $products_deleted_map = [];
    $products_by_status = [];
    $colsArr = [];
    if (tableExists($pdo,$dbName,'products')) {
        if (columnExists($pdo,$dbName,'products','created_at')) {
            $products_added_map = dateSeriesBetween($pdo, 'products', 'created_at', $start, $end);
        }
        if (columnExists($pdo,$dbName,'products','deleted_at')) {
            $products_deleted_map = dateSeriesBetween($pdo, 'products', 'deleted_at', $start, $end);
        } elseif (columnExists($pdo,$dbName,'products','status') && columnExists($pdo,$dbName,'products','updated_at')) {
            $vals = ["deleted","removed"];
            $conds = array_map(function($v) use ($pdo) { return "LOWER(status) = " . $pdo->quote($v); }, $vals);
            $sql = "SELECT DATE(updated_at) AS dt, COUNT(*) AS cnt FROM products WHERE (" . implode(' OR ', $conds) . ") AND updated_at BETWEEN :start AND :end GROUP BY DATE(updated_at) ORDER BY DATE(updated_at) ASC";
            $st = $pdo->prepare($sql);
            $st->execute([':start'=>$start->format('Y-m-d H:i:s'),':end'=>$end->format('Y-m-d H:i:s')]);
            $products_deleted_map = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        }

        // products status breakdown (your statuses expected: pending/approved/rejected)
        if (columnExists($pdo,$dbName,'products','status')) {
            $st = $pdo->query("SELECT status AS st, COUNT(*) AS cnt FROM products GROUP BY status ORDER BY cnt DESC");
            foreach ($st->fetchAll() as $r) $products_by_status[] = ['status'=>$r['st'] ?? 'unknown','count'=>(int)$r['cnt']];
        }

        // columns cache for fallback logic (cars/services)
        $cols = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'products'");
        $cols->execute([':db'=>$dbName]);
        $colsArr = $cols->fetchAll(PDO::FETCH_COLUMN);
    }
    $products_by_date = build30DaySeriesFromMap($products_added_map, $start, $days);
    $products_deleted_by_date = build30DaySeriesFromMap($products_deleted_map, $start, $days);
    $products_added_total = array_sum(array_column($products_by_date,'count'));
    $products_deleted_total = array_sum(array_column($products_deleted_by_date,'count'));

    // === CARS: try cars table OR products filter ===
    $cars_added_map = [];
    $cars_deleted_map = [];
    $cars_total = 0;
    $cars_by_status = [];
    if (tableExists($pdo,$dbName,'cars')) {
        $cars_total = (int)$pdo->query("SELECT COUNT(*) FROM cars")->fetchColumn();
        if (columnExists($pdo,$dbName,'cars','created_at')) $cars_added_map = dateSeriesBetween($pdo,'cars','created_at',$start,$end);
        if (columnExists($pdo,$dbName,'cars','deleted_at')) $cars_deleted_map = dateSeriesBetween($pdo,'cars','deleted_at',$start,$end);
        elseif (columnExists($pdo,$dbName,'cars','status') && columnExists($pdo,$dbName,'cars','updated_at')) {
            $vals = ["deleted","removed"];
            $conds = array_map(function($v) use ($pdo) { return "LOWER(status) = " . $pdo->quote($v); }, $vals);
            $sql = "SELECT DATE(updated_at) AS dt, COUNT(*) AS cnt FROM cars WHERE (" . implode(' OR ', $conds) . ") AND updated_at BETWEEN :start AND :end GROUP BY DATE(updated_at)";
            $st = $pdo->prepare($sql);
            $st->execute([':start'=>$start->format('Y-m-d H:i:s'),':end'=>$end->format('Y-m-d H:i:s')]);
            $cars_deleted_map = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        }
        // cars status breakdown (expects pending/approved/rejected or similar)
        if (columnExists($pdo,$dbName,'cars','status')) {
            $st = $pdo->query("SELECT status AS st, COUNT(*) AS cnt FROM cars GROUP BY status ORDER BY cnt DESC");
            foreach ($st->fetchAll() as $r) $cars_by_status[] = ['status'=>$r['st'] ?? 'unknown','count'=>(int)$r['cnt']];
        }
    } elseif (tableExists($pdo,$dbName,'products')) {
        // fallback: treat 'products' with type like 'auto' as cars
        $condParts = [];
        if (in_array('type',$colsArr)) $condParts[] = "(LOWER(type) LIKE '%auto%' OR LOWER(type) LIKE '%car%' OR LOWER(type) LIKE '%vehicle%')";
        if (in_array('vehicle_type',$colsArr)) $condParts[] = "vehicle_type IS NOT NULL";
        if (in_array('vehicle_body',$colsArr)) $condParts[] = "vehicle_body IS NOT NULL";
        if (!empty($condParts)) {
            $cars_total = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE (" . implode(' OR ', $condParts) . ")")->fetchColumn();
            if (columnExists($pdo,$dbName,'products','created_at')) {
                $sql = "SELECT DATE(created_at) AS dt, COUNT(*) AS cnt FROM products WHERE (" . implode(' OR ', $condParts) . ") AND created_at BETWEEN :start AND :end GROUP BY DATE(created_at) ORDER BY DATE(created_at) ASC";
                $st = $pdo->prepare($sql);
                $st->execute([':start'=>$start->format('Y-m-d H:i:s'), ':end'=>$end->format('Y-m-d H:i:s')]);
                $cars_added_map = $st->fetchAll(PDO::FETCH_KEY_PAIR);
            }
            if (columnExists($pdo,$dbName,'products','deleted_at')) {
                $sql = "SELECT DATE(deleted_at) AS dt, COUNT(*) AS cnt FROM products WHERE (" . implode(' OR ', $condParts) . ") AND deleted_at BETWEEN :start AND :end GROUP BY DATE(deleted_at) ORDER BY DATE(deleted_at) ASC";
                $st = $pdo->prepare($sql);
                $st->execute([':start'=>$start->format('Y-m-d H:i:s'), ':end'=>$end->format('Y-m-d H:i:s')]);
                $cars_deleted_map = $st->fetchAll(PDO::FETCH_KEY_PAIR);
            }
            // cars status from products
            if (in_array('status',$colsArr)) {
                $sql = "SELECT status AS st, COUNT(*) AS cnt FROM products WHERE (" . implode(' OR ', $condParts) . ") GROUP BY status ORDER BY cnt DESC";
                $st = $pdo->query($sql);
                foreach ($st->fetchAll() as $r) $cars_by_status[] = ['status'=>$r['st'] ?? 'unknown','count'=>(int)$r['cnt']];
            }
        }
    }
    $cars_by_date = build30DaySeriesFromMap($cars_added_map, $start, $days);
    $cars_deleted_by_date = build30DaySeriesFromMap($cars_deleted_map, $start, $days);
    $cars_added_total = array_sum(array_column($cars_by_date,'count'));
    $cars_deleted_total = array_sum(array_column($cars_deleted_by_date,'count'));

    // === SERVICES ===
    $services_added_map = [];
    $services_deleted_map = [];
    $services_total = 0;
    $services_by_status = [];
    if (tableExists($pdo,$dbName,'services')) {
        $services_total = (int)$pdo->query("SELECT COUNT(*) FROM services")->fetchColumn();
        if (columnExists($pdo,$dbName,'services','created_at')) $services_added_map = dateSeriesBetween($pdo,'services','created_at',$start,$end);
        if (columnExists($pdo,$dbName,'services','deleted_at')) $services_deleted_map = dateSeriesBetween($pdo,'services','deleted_at',$start,$end);
        elseif (columnExists($pdo,$dbName,'services','status') && columnExists($pdo,$dbName,'services','updated_at')) {
            $vals = ["deleted","removed"];
            $conds = array_map(function($v) use ($pdo) { return "LOWER(status) = " . $pdo->quote($v); }, $vals);
            $sql = "SELECT DATE(updated_at) AS dt, COUNT(*) AS cnt FROM services WHERE (" . implode(' OR ', $conds) . ") AND updated_at BETWEEN :start AND :end GROUP BY DATE(updated_at)";
            $st = $pdo->prepare($sql);
            $st->execute([':start'=>$start->format('Y-m-d H:i:s'),':end'=>$end->format('Y-m-d H:i:s')]);
            $services_deleted_map = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        }
        // services status breakdown
        if (columnExists($pdo,$dbName,'services','status')) {
            $st = $pdo->query("SELECT status AS st, COUNT(*) AS cnt FROM services GROUP BY status ORDER BY cnt DESC");
            foreach ($st->fetchAll() as $r) $services_by_status[] = ['status'=>$r['st'] ?? 'unknown','count'=>(int)$r['cnt']];
        }
    } elseif (tableExists($pdo,$dbName,'products') && columnExists($pdo,$dbName,'products','type')) {
        $sql = "SELECT DATE(created_at) AS dt, COUNT(*) AS cnt FROM products WHERE (LOWER(type) LIKE '%serv%' OR LOWER(type) LIKE '%service%') AND created_at BETWEEN :start AND :end GROUP BY DATE(created_at) ORDER BY DATE(created_at) ASC";
        $st = $pdo->prepare($sql);
        $st->execute([':start'=>$start->format('Y-m-d H:i:s'),':end'=>$end->format('Y-m-d H:i:s')]);
        $services_added_map = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        if (columnExists($pdo,$dbName,'products','deleted_at')) {
            $sql = "SELECT DATE(deleted_at) AS dt, COUNT(*) AS cnt FROM products WHERE (LOWER(type) LIKE '%serv%' OR LOWER(type) LIKE '%service%') AND deleted_at BETWEEN :start AND :end GROUP BY DATE(deleted_at) ORDER BY DATE(deleted_at) ASC";
            $st = $pdo->prepare($sql);
            $st->execute([':start'=>$start->format('Y-m-d H:i:s'),':end'=>$end->format('Y-m-d H:i:s')]);
            $services_deleted_map = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        }
        $services_total = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE LOWER(type) LIKE '%serv%' OR LOWER(type) LIKE '%service%'")->fetchColumn();
        if (in_array('status', $colsArr ?? [])) {
            $sql = "SELECT status AS st, COUNT(*) AS cnt FROM products WHERE (LOWER(type) LIKE '%serv%' OR LOWER(type) LIKE '%service%') GROUP BY status ORDER BY cnt DESC";
            $st = $pdo->query($sql);
            foreach ($st->fetchAll() as $r) $services_by_status[] = ['status'=>$r['st'] ?? 'unknown','count'=>(int)$r['cnt']];
        }
    }
    $services_by_date = build30DaySeriesFromMap($services_added_map, $start, $days);
    $services_deleted_by_date = build30DaySeriesFromMap($services_deleted_map, $start, $days);
    $services_added_total = array_sum(array_column($services_by_date,'count'));
    $services_deleted_total = array_sum(array_column($services_deleted_by_date,'count'));

    // === MESSAGES by date (added) ===
    $messages_by_date = [];
    $messages_deleted_by_date = [];
    $messages_added_total = 0;
    $messages_deleted_total = 0;
    if (tableExists($pdo,$dbName,'messages') && columnExists($pdo,$dbName,'messages','created_at')) {
        $messages_map = dateSeriesBetween($pdo,'messages','created_at',$start,$end);
        $messages_by_date = build30DaySeriesFromMap($messages_map,$start,$days);
        $messages_added_total = array_sum(array_column($messages_by_date,'count'));
        // deleted: rarely present, try deleted_at
        if (columnExists($pdo,$dbName,'messages','deleted_at')) {
            $mDel = dateSeriesBetween($pdo,'messages','deleted_at',$start,$end);
            $messages_deleted_by_date = build30DaySeriesFromMap($mDel,$start,$days);
            $messages_deleted_total = array_sum(array_column($messages_deleted_by_date,'count'));
        }
    }

    // === products_by_brand (top) ===
    $products_by_brand = [];
    if (tableExists($pdo,$dbName,'products')) {
        if (empty($colsArr)) {
            $cols = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'products'");
            $cols->execute([':db'=>$dbName]);
            $colsArr = $cols->fetchAll(PDO::FETCH_COLUMN);
        }
        if (in_array('brand_id', $colsArr) && tableExists($pdo,$dbName,'brands')) {
            $sql = "
                SELECT COALESCE(b.name,'Unknown') AS brand, COUNT(*) AS cnt
                FROM products p
                LEFT JOIN brands b ON b.id = p.brand_id
                GROUP BY COALESCE(b.name,'Unknown')
                ORDER BY cnt DESC
                LIMIT 50
            ";
            $st = $pdo->query($sql);
            foreach ($st->fetchAll() as $r) $products_by_brand[] = ['brand'=>$r['brand'],'count'=>(int)$r['cnt']];
        } elseif (in_array('brand', $colsArr) || in_array('brand_name',$colsArr)) {
            $col = in_array('brand',$colsArr) ? 'brand' : 'brand_name';
            $sql = "SELECT COALESCE({$col},'Unknown') AS brand, COUNT(*) AS cnt FROM products GROUP BY COALESCE({$col},'Unknown') ORDER BY cnt DESC LIMIT 50";
            $st = $pdo->query($sql);
            foreach ($st->fetchAll() as $r) $products_by_brand[] = ['brand'=>$r['brand'],'count'=>(int)$r['cnt']];
        }
    }

    // Ensure status arrays exist (empty if none)
    $products_by_status = $products_by_status ?? [];
    $services_by_status = $services_by_status ?? [];
    $cars_by_status = $cars_by_status ?? [];

    // Build final output
    $out = [
        // totals
        'users_total' => $users_total,
        'products_total' => $products_total,
        'brands_total' => $brands_total,
        'models_total' => $models_total,
        'parts_total' => $parts_total,
        'components_total' => $components_total,
        'messages_total' => $messages_total,

        // chats
        'open_chats' => $open_chats,
        'closed_chats' => $closed_chats,
        'chats_avg_response' => $chats_avg_response !== null ? (float)$chats_avg_response : null,

        // breakdowns
        'users_by_role' => $users_by_role,
        'users_by_status' => $users_by_status,
        'products_by_status' => $products_by_status,
        'services_by_status' => $services_by_status,
        'cars_by_status' => $cars_by_status,

        // products by brand
        'products_by_brand' => $products_by_brand,

        // time series (30 days): added / deleted
        'users_by_date' => $users_by_date,
        'users_deleted_by_date' => $users_deleted_by_date,
        'users_added_total' => $users_added_total,
        'users_deleted_total' => $users_deleted_total,

        'products_by_date' => $products_by_date,
        'products_deleted_by_date' => $products_deleted_by_date,
        'products_added_total' => $products_added_total,
        'products_deleted_total' => $products_deleted_total,

        'cars_by_date' => $cars_by_date,
        'cars_deleted_by_date' => $cars_deleted_by_date,
        'cars_added_total' => $cars_added_total,
        'cars_deleted_total' => $cars_deleted_total,

        'services_by_date' => $services_by_date,
        'services_deleted_by_date' => $services_deleted_by_date,
        'services_added_total' => $services_added_total,
        'services_deleted_total' => $services_deleted_total,

        'messages_by_date' => $messages_by_date,
        'messages_deleted_by_date' => $messages_deleted_by_date,
        'messages_added_total' => $messages_added_total,
        'messages_deleted_total' => $messages_deleted_total,
    ];

    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    json_error('Query error');
}
