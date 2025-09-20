<?php
// /mehanik/api/products.php
// API только для запчастей (products). Устойчиво поддерживает фильтры по id или по названию.
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

$logFile = '/tmp/mehanik_products_error.log';
function dbglog($m){ global $logFile; @file_put_contents($logFile, date('[Y-m-d H:i:s] ').$m.PHP_EOL, FILE_APPEND|LOCK_EX); }

try {
    if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
        $err = "DB connection missing.";
        dbglog($err);
        echo json_encode(['ok'=>false,'error'=>$err], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $getInt = function($k){ return (isset($_GET[$k]) && $_GET[$k] !== '') ? (int)$_GET[$k] : null; };
    $getStr = function($k){ return isset($_GET[$k]) ? trim((string)$_GET[$k]) : ''; };

    // raw inputs and numeric detection (support both id and textual values)
    $brand_raw = $getStr('brand') ?: $getStr('brand_part');
    $model_raw = $getStr('model') ?: $getStr('model_part');
    $brand_id_in = is_numeric($brand_raw) ? (int)$brand_raw : null;
    $model_id_in = is_numeric($model_raw) ? (int)$model_raw : null;

    $year_from    = $getInt('year_from');
    $year_to      = $getInt('year_to');
    $cpart        = $getInt('complex_part');
    $comp         = $getInt('component');
    $q            = $getStr('q');
    $type         = strtolower($getStr('type')); // ignored for autos; we force parts
    $price_from   = isset($_GET['price_from']) && $_GET['price_from'] !== '' ? (float)$_GET['price_from'] : null;
    $price_to     = isset($_GET['price_to']) && $_GET['price_to'] !== '' ? (float)$_GET['price_to'] : null;
    $part_quality = $getStr('part_quality');
    $mine         = isset($_GET['mine']) && $_GET['mine'] === '1';
    $recommend    = isset($_GET['recommendation']) && $_GET['recommendation'] === '1';

    // recommendation quick path (for parts only)
    if ($recommend) {
        $qrec = "SELECT * FROM `products` WHERE status = 'approved' AND COALESCE(recommended,0)=1 ORDER BY id DESC LIMIT 40";
        $r = $mysqli->query($qrec);
        $items = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
        echo json_encode(['ok'=>true,'products'=>$items], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- Build query for parts ONLY ---
    // We treat "parts" as rows that have complex_part_id OR component_id
    $sql = "SELECT p.*, b.name AS brand_name, m.name AS model_name, cp.name AS complex_part_name, c.name AS component_name
            FROM products p
            LEFT JOIN brands b ON b.id = p.brand_id
            LEFT JOIN models m ON m.id = p.model_id
            LEFT JOIN complex_parts cp ON cp.id = p.complex_part_id
            LEFT JOIN components c ON c.id = p.component_id
            WHERE (p.complex_part_id IS NOT NULL OR p.component_id IS NOT NULL)";

    $params = []; $types = '';

    // mine: owner sees their items regardless of status
    if ($mine && !empty($_SESSION['user']['id'])) {
        $sql .= " AND p.user_id = ?";
        $params[] = (int)$_SESSION['user']['id'];
        $types .= 'i';
    } else {
        // public: only approved
        $sql .= " AND p.status = 'approved'";
    }

    // brand / model filters: prefer numeric id, otherwise do case-insensitive textual match
    if ($brand_id_in !== null) {
        $sql .= " AND (p.brand_id = ?)";
        $params[] = $brand_id_in; $types .= 'i';
    } elseif ($brand_raw !== '') {
        $sql .= " AND (LOWER(IFNULL(p.brand,'')) = LOWER(?))";
        $params[] = $brand_raw; $types .= 's';
    }

    if ($model_id_in !== null) {
        $sql .= " AND (p.model_id = ?)";
        $params[] = $model_id_in; $types .= 'i';
    } elseif ($model_raw !== '') {
        $sql .= " AND (LOWER(IFNULL(p.model,'')) = LOWER(?))";
        $params[] = $model_raw; $types .= 's';
    }

    if ($year_from !== null) { $sql .= " AND (p.year_from IS NULL OR p.year_from <= ?)"; $params[] = $year_from; $types .= 'i'; }
    if ($year_to !== null)   { $sql .= " AND (p.year_to IS NULL OR p.year_to >= ?)"; $params[] = $year_to; $types .= 'i'; }

    if ($cpart) { $sql .= " AND p.complex_part_id = ?"; $params[] = $cpart; $types .= 'i'; }
    if ($comp)  { $sql .= " AND p.component_id = ?"; $params[] = $comp; $types .= 'i'; }

    if ($price_from !== null) { $sql .= " AND (p.price IS NULL OR p.price >= ?)"; $params[] = $price_from; $types .= 'd'; }
    if ($price_to !== null)   { $sql .= " AND (p.price IS NULL OR p.price <= ?)"; $params[] = $price_to; $types .= 'd'; }

    if ($part_quality !== '') {
        $sql .= " AND (p.quality = ? OR p.part_quality = ? OR LOWER(IFNULL(p.quality,'')) = LOWER(?))";
        $params[] = $part_quality; $params[] = $part_quality; $params[] = $part_quality; $types .= 'sss';
    }

    // q filter (ID or text)
    if ($q !== '') {
        if (ctype_digit($q)) {
            $sql .= " AND (p.id = ? OR p.sku LIKE CONCAT('%', ?, '%') OR p.name LIKE CONCAT('%', ?, '%'))";
            $params[] = (int)$q; $params[] = $q; $params[] = $q; $types .= 'iss';
        } else {
            $sql .= " AND (p.name LIKE CONCAT('%', ?, '%') OR p.sku LIKE CONCAT('%', ?, '%'))";
            $params[] = $q; $params[] = $q; $types .= 'ss';
        }
    }

    $sql .= " ORDER BY p.id DESC LIMIT 200";

    $stmt = $mysqli->prepare($sql);
    if ($stmt === false) {
        $err = "DB prepare failed: ".$mysqli->error;
        dbglog($err . " sql: " . $sql);
        echo json_encode(['ok'=>false,'error'=>$err,'sql'=>$sql], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!empty($params)) {
        $bind = []; $bind[] = $types;
        for ($i=0;$i<count($params);$i++){ $name='b'.$i; $$name = $params[$i]; $bind[] = &$$name; }
        if (!@call_user_func_array([$stmt,'bind_param'], $bind)) {
            $err = "bind_param failed: ".$stmt->error;
            dbglog($err);
            echo json_encode(['ok'=>false,'error'=>$err], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    if (!$stmt->execute()) {
        $err = "Execute failed: ".$stmt->error;
        dbglog($err);
        echo json_encode(['ok'=>false,'error'=>$err], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $rows = [];
    if (method_exists($stmt,'get_result')) {
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        if ($res) $res->free();
    } else {
        $meta = $stmt->result_metadata();
        if ($meta) {
            $fields=[]; $out=[]; $bindParams=[];
            while ($f=$meta->fetch_field()) { $fields[]=$f->name; $out[$f->name]=null; $bindParams[]=&$out[$f->name]; }
            if (!empty($bindParams)) {
                call_user_func_array([$stmt,'bind_result'],$bindParams);
                while ($stmt->fetch()) { $row=[]; foreach($fields as $fn) $row[$fn]=$out[$fn]; $rows[]=$row; }
            }
            $meta->free();
        }
    }
    $stmt->close();

    // build lookups relevant for parts UI
    $response = ['ok'=>true, 'products'=>$rows, 'lookups'=>[
        'brands'=>[],'models'=>[],'complex_parts'=>[],'components'=>[],
        'vehicle_types'=>[],'vehicle_bodies'=>[],'fuel_types'=>[],'gearboxes'=>[],'vehicle_years'=>[]
    ]];

    $try = function($sqlq,$key) use ($mysqli,&$response){ $r=$mysqli->query($sqlq); if($r){ while($row=$r->fetch_assoc()) $response['lookups'][$key][] = $row; $r->free(); } };

    $try("SELECT id, name FROM brands ORDER BY name", 'brands');
    $try("SELECT id, name, brand_id FROM models ORDER BY name", 'models');
    $try("SELECT id, name FROM complex_parts ORDER BY name", 'complex_parts');
    $try("SELECT id, name, complex_part_id FROM components ORDER BY name", 'components');
    // keep vehicle lookups as parts can be vehicle-specific (optional)
    $try("SELECT id, `key`, name FROM vehicle_types ORDER BY name", 'vehicle_types');
    $try("SELECT id, vehicle_type_id, `key`, name FROM vehicle_bodies ORDER BY name", 'vehicle_bodies');
    $try("SELECT id, `key`, name FROM fuel_types ORDER BY name", 'fuel_types');
    $try("SELECT id, `key`, name FROM gearboxes ORDER BY name", 'gearboxes');
    $try("SELECT id, `year` FROM vehicle_years ORDER BY `year` DESC", 'vehicle_years');

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    $m = "Unhandled: ".$e->getMessage();
    dbglog($m);
    echo json_encode(['ok'=>false,'error'=>$m,'trace'=>$e->getTraceAsString()], JSON_UNESCAPED_UNICODE);
    exit;
}
