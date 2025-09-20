<?php
// /mehanik/api/cars.php
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

$logFile = '/tmp/mehanik_cars_error.log';
function dbglog($m){ global $logFile; @file_put_contents($logFile, date('[Y-m-d H:i:s] ').$m.PHP_EOL, FILE_APPEND|LOCK_EX); }

try {
    if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
        $err = "DB connection missing.";
        dbglog($err);
        echo json_encode(['ok'=>false,'error'=>$err], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $getStr = function($k){ return isset($_GET[$k]) ? trim((string)$_GET[$k]) : ''; };
    $getInt = function($k){ return (isset($_GET[$k]) && $_GET[$k] !== '') ? (int)$_GET[$k] : null; };

    // inputs
    $brand_raw = $getStr('brand');
    $model_raw = $getStr('model');
    $brand_id_in = is_numeric($brand_raw) ? (int)$brand_raw : null;
    $model_id_in = is_numeric($model_raw) ? (int)$model_raw : null;

    $year_from = $getInt('year_from');
    $year_to = $getInt('year_to');
    $price_from = (isset($_GET['price_from']) && $_GET['price_from'] !== '') ? (float)$_GET['price_from'] : null;
    $price_to   = (isset($_GET['price_to']) && $_GET['price_to'] !== '') ? (float)$_GET['price_to'] : null;

    $fuel_raw = $getStr('fuel_type'); $fuel_id = is_numeric($fuel_raw) ? (int)$fuel_raw : null;
    $gear_raw = $getStr('gearbox');   $gear_id = is_numeric($gear_raw) ? (int)$gear_raw : null;
    $vehicle_type_raw = $getStr('vehicle_type'); $vehicle_type_id = is_numeric($vehicle_type_raw) ? (int)$vehicle_type_raw : null;
    $vehicle_body_raw = $getStr('vehicle_body'); $vehicle_body_id = is_numeric($vehicle_body_raw) ? (int)$vehicle_body_raw : null;

    $q = $getStr('q');
    $mine = isset($_GET['mine']) && $_GET['mine'] === '1';
    $limit = 200;

    // base query
    $sql = "SELECT c.id, c.user_id, c.brand, c.model, c.year, c.mileage, c.body, c.photo, c.price, c.status, c.sku, c.created_at, c.fuel, c.transmission
            FROM cars c
            WHERE 1=1";
    $params = []; $types = '';

    if ($mine && !empty($_SESSION['user']['id'])) { $sql .= " AND c.user_id = ?"; $params[] = (int)$_SESSION['user']['id']; $types .= 'i'; }

    if ($brand_id_in !== null) {
        $sql .= " AND (CAST(c.brand_id AS SIGNED) = ?)";
        $params[] = $brand_id_in; $types .= 'i';
    } elseif ($brand_raw !== '') {
        $sql .= " AND LOWER(IFNULL(c.brand,'')) = LOWER(?)";
        $params[] = $brand_raw; $types .= 's';
    }

    if ($model_id_in !== null) {
        $sql .= " AND (CAST(c.model_id AS SIGNED) = ?)";
        $params[] = $model_id_in; $types .= 'i';
    } elseif ($model_raw !== '') {
        $sql .= " AND LOWER(IFNULL(c.model,'')) = LOWER(?)";
        $params[] = $model_raw; $types .= 's';
    }

    if ($year_from !== null) { $sql .= " AND (c.year IS NULL OR c.year >= ?)"; $params[] = $year_from; $types .= 'i'; }
    if ($year_to !== null)   { $sql .= " AND (c.year IS NULL OR c.year <= ?)"; $params[] = $year_to; $types .= 'i'; }

    if ($price_from !== null) { $sql .= " AND (c.price IS NULL OR c.price >= ?)"; $params[] = $price_from; $types .= 'd'; }
    if ($price_to !== null)   { $sql .= " AND (c.price IS NULL OR c.price <= ?)"; $params[] = $price_to; $types .= 'd'; }

    if ($fuel_id !== null) {
        $sql .= " AND (CAST(c.fuel_id AS SIGNED) = ? OR LOWER(IFNULL(c.fuel,'')) = LOWER(?))";
        $params[] = $fuel_id; $params[] = $fuel_raw; $types .= 'is';
    } elseif ($fuel_raw !== '') {
        $sql .= " AND LOWER(IFNULL(c.fuel,'')) = LOWER(?)";
        $params[] = $fuel_raw; $types .= 's';
    }

    if ($gear_id !== null) {
        $sql .= " AND (CAST(c.transmission_id AS SIGNED) = ? OR LOWER(IFNULL(c.transmission,'')) = LOWER(?))";
        $params[] = $gear_id; $params[] = $gear_raw; $types .= 'is';
    } elseif ($gear_raw !== '') {
        $sql .= " AND LOWER(IFNULL(c.transmission,'')) = LOWER(?)";
        $params[] = $gear_raw; $types .= 's';
    }

    if ($vehicle_type_id !== null) {
        $sql .= " AND (CAST(c.vehicle_type_id AS SIGNED) = ? OR LOWER(IFNULL(c.vehicle_type,'')) = LOWER(?))";
        $params[] = $vehicle_type_id; $params[] = $vehicle_type_raw; $types .= 'is';
    } elseif ($vehicle_type_raw !== '') {
        $sql .= " AND LOWER(IFNULL(c.vehicle_type,'')) = LOWER(?)";
        $params[] = $vehicle_type_raw; $types .= 's';
    }

    if ($vehicle_body_id !== null) {
        $sql .= " AND (CAST(c.body_id AS SIGNED) = ? OR LOWER(IFNULL(c.body,'')) = LOWER(?))";
        $params[] = $vehicle_body_id; $params[] = $vehicle_body_raw; $types .= 'is';
    } elseif ($vehicle_body_raw !== '') {
        $sql .= " AND LOWER(IFNULL(c.body,'')) = LOWER(?)";
        $params[] = $vehicle_body_raw; $types .= 's';
    }

    // status: only approved when not mine
    if (!$mine) {
        $sql .= " AND c.status = 'approved'";
    }

    // q search
    if ($q !== '') {
        if (ctype_digit($q)) {
            $sql .= " AND (c.id = ? OR c.sku LIKE CONCAT('%', ?, '%') OR c.model LIKE CONCAT('%', ?, '%') OR c.brand LIKE CONCAT('%', ?, '%'))";
            $params[] = (int)$q; $params[] = $q; $params[] = $q; $params[] = $q; $types .= 'isss';
        } else {
            $sql .= " AND (c.model LIKE CONCAT('%', ?, '%') OR c.brand LIKE CONCAT('%', ?, '%') OR c.sku LIKE CONCAT('%', ?, '%'))";
            $params[] = $q; $params[] = $q; $params[] = $q; $types .= 'sss';
        }
    }

    $sql .= " ORDER BY c.id DESC LIMIT " . (int)$limit;

    $stmt = $mysqli->prepare($sql);
    if ($stmt === false) {
        $err = "DB prepare failed (cars): ".$mysqli->error;
        dbglog($err . " sql: " . $sql);
        echo json_encode(['ok'=>false,'error'=>$err,'sql'=>$sql], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!empty($params)) {
        $bind = []; $bind[] = $types;
        for ($i=0;$i<count($params);$i++){ $name='b'.$i; $$name = $params[$i]; $bind[] = &$$name; }
        if (!@call_user_func_array([$stmt,'bind_param'], $bind)) {
            $err = "bind_param failed (cars): ".$stmt->error;
            dbglog($err);
            echo json_encode(['ok'=>false,'error'=>$err], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    if (!$stmt->execute()) {
        $err = "Execute failed (cars): ".$stmt->error;
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

    // gather lookups
    $response = ['ok'=>true, 'products'=>$rows, 'lookups'=>[
        'brands'=>[],'models'=>[],'vehicle_types'=>[],'vehicle_bodies'=>[],'fuel_types'=>[],'gearboxes'=>[],'vehicle_years'=>[]
    ]];

    $try = function($sqlq,$key) use ($mysqli,&$response){ $r=$mysqli->query($sqlq); if($r){ while($row=$r->fetch_assoc()) $response['lookups'][$key][] = $row; $r->free(); } };
    $try("SELECT id, name FROM brands ORDER BY name", 'brands');
    $try("SELECT id, name, brand_id FROM models ORDER BY name", 'models');
    $try("SELECT id, `key`, name FROM vehicle_types ORDER BY name", 'vehicle_types');
    $try("SELECT id, vehicle_type_id, `key`, name FROM vehicle_bodies ORDER BY name", 'vehicle_bodies');
    $try("SELECT id, `key`, name FROM fuel_types ORDER BY name", 'fuel_types');
    $try("SELECT id, `key`, name FROM gearboxes ORDER BY name", 'gearboxes');
    $try("SELECT id, `year` FROM vehicle_years ORDER BY `year` DESC", 'vehicle_years');

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    $m = "Unhandled (cars): ".$e->getMessage();
    dbglog($m);
    echo json_encode(['ok'=>false,'error'=>$m,'trace'=>$e->getTraceAsString()], JSON_UNESCAPED_UNICODE);
    exit;
}
