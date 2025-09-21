<?php
// /mehanik/api/cars.php — адаптирован под схему: id, sku, user_id, vehicle_type, body, brand, model, year, mileage, transmission, fuel, price, photo, description, contact_phone, status, created_at, vin
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

    // inputs (support both 'brand' and 'brand_car' etc.)
    $brand_raw = $getStr('brand') ?: $getStr('brand_car');
    $model_raw = $getStr('model') ?: $getStr('model_car');
    $year_from = $getInt('year_from');
    $year_to   = $getInt('year_to');
    $m_from    = $getInt('mileage_from');
    $m_to      = $getInt('mileage_to');
    $fuel_raw  = $getStr('fuel') ?: $getStr('fuel_type');
    $gear_raw  = $getStr('transmission') ?: $getStr('gearbox');
    $vehicle_type_raw = $getStr('vehicle_type');
    $vehicle_body_raw = $getStr('vehicle_body');
    $price_from = (isset($_GET['price_from']) && $_GET['price_from'] !== '') ? (float)$_GET['price_from'] : null;
    $price_to   = (isset($_GET['price_to']) && $_GET['price_to'] !== '') ? (float)$_GET['price_to'] : null;
    $q = $getStr('q');
    $mine = isset($_GET['mine']) && $_GET['mine'] === '1';
    $recommend = isset($_GET['recommendation']) && $_GET['recommendation'] === '1';
    $limit = 200;

    // recommendation quick path
    if ($recommend) {
        $qrec = "SELECT id, sku, user_id, vehicle_type, body, brand, model, year, mileage, transmission, fuel, price, photo, description, contact_phone, status, created_at, vin
                 FROM cars WHERE status IN ('active','approved') AND COALESCE(recommended,0)=1 ORDER BY id DESC LIMIT 40";
        $r = $mysqli->query($qrec);
        $items = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
        echo json_encode(['ok'=>true,'cars'=>$items], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Base query — use the exact columns you listed
    $sql = "SELECT c.id, c.sku, c.user_id, c.vehicle_type, c.body, c.brand, c.model, c.year, c.mileage, c.transmission, c.fuel, c.price, c.photo, c.description, c.contact_phone, c.status, c.created_at, c.vin
            FROM cars c
            WHERE 1=1";
    $params = []; $types = '';

    // mine: owner sees their items regardless of status
    if ($mine && !empty($_SESSION['user']['id'])) {
        $sql .= " AND c.user_id = ?";
        $params[] = (int)$_SESSION['user']['id'];
        $types .= 'i';
    } else {
        // public: only approved/published (adjust if your project uses 'active' instead)
        $sql .= " AND c.status = 'approved'";
    }

    // brand / model as TEXT fields (since your schema has brand/model columns)
    if ($brand_raw !== '') {
        // exact match (case-insensitive)
        $sql .= " AND LOWER(IFNULL(c.brand,'')) = LOWER(?)";
        $params[] = $brand_raw; $types .= 's';
    }

    if ($model_raw !== '') {
        $sql .= " AND LOWER(IFNULL(c.model,'')) = LOWER(?)";
        $params[] = $model_raw; $types .= 's';
    }

    // year filters — require non-null to match
    if ($year_from !== null) { $sql .= " AND (c.year IS NOT NULL AND c.year >= ?)"; $params[] = $year_from; $types .= 'i'; }
    if ($year_to !== null)   { $sql .= " AND (c.year IS NOT NULL AND c.year <= ?)"; $params[] = $year_to; $types .= 'i'; }

    // mileage filters
    if ($m_from !== null) { $sql .= " AND (c.mileage IS NOT NULL AND c.mileage >= ?)"; $params[] = $m_from; $types .= 'i'; }
    if ($m_to !== null)   { $sql .= " AND (c.mileage IS NOT NULL AND c.mileage <= ?)"; $params[] = $m_to; $types .= 'i'; }

    // transmission / fuel as text fields
    if ($gear_raw !== '') {
        $sql .= " AND LOWER(IFNULL(c.transmission,'')) = LOWER(?)";
        $params[] = $gear_raw; $types .= 's';
    }
    if ($fuel_raw !== '') {
        $sql .= " AND LOWER(IFNULL(c.fuel,'')) = LOWER(?)";
        $params[] = $fuel_raw; $types .= 's';
    }

    // vehicle type / body as text fields
    if ($vehicle_type_raw !== '') {
        $sql .= " AND LOWER(IFNULL(c.vehicle_type,'')) = LOWER(?)";
        $params[] = $vehicle_type_raw; $types .= 's';
    }
    if ($vehicle_body_raw !== '') {
        $sql .= " AND LOWER(IFNULL(c.body,'')) = LOWER(?)";
        $params[] = $vehicle_body_raw; $types .= 's';
    }

    if ($price_from !== null) { $sql .= " AND (c.price IS NULL OR c.price >= ?)"; $params[] = $price_from; $types .= 'd'; }
    if ($price_to !== null)   { $sql .= " AND (c.price IS NULL OR c.price <= ?)"; $params[] = $price_to; $types .= 'd'; }

    // q search: support ID / SKU / brand / model / VIN
    if ($q !== '') {
        if (ctype_digit($q)) {
            $sql .= " AND (c.id = ? OR c.sku LIKE CONCAT('%', ?, '%') OR c.brand LIKE CONCAT('%', ?, '%') OR c.model LIKE CONCAT('%', ?, '%') OR c.vin LIKE CONCAT('%', ?, '%'))";
            $params[] = (int)$q; $params[] = $q; $params[] = $q; $params[] = $q; $params[] = $q;
            $types .= 'issss';
        } else {
            $sql .= " AND (c.sku LIKE CONCAT('%', ?, '%') OR c.brand LIKE CONCAT('%', ?, '%') OR c.model LIKE CONCAT('%', ?, '%') OR c.vin LIKE CONCAT('%', ?, '%'))";
            $params[] = $q; $params[] = $q; $params[] = $q; $params[] = $q;
            $types .= 'ssss';
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

    // build lookups: try dedicated lookup tables first, otherwise fallback to DISTINCT from cars
    $response = ['ok'=>true, 'cars'=>$rows, 'lookups'=>[
        'brands'=>[],'models'=>[],'vehicle_types'=>[],'vehicle_bodies'=>[],'fuel_types'=>[],'gearboxes'=>[],'vehicle_years'=>[]
    ]];

    $try = function($sqlq,$key) use ($mysqli,&$response){
        $r = @$mysqli->query($sqlq);
        if ($r) {
            while($row=$r->fetch_assoc()) $response['lookups'][$key][] = $row;
            $r->free();
            return true;
        }
        return false;
    };

    // brands/models: prefer dedicated tables, else fallback to distinct
    if (!$try("SELECT id, name FROM brands ORDER BY name", 'brands')) {
        $r = $mysqli->query("SELECT DISTINCT brand AS name FROM cars WHERE brand <> '' ORDER BY brand");
        if ($r) { while($row=$r->fetch_assoc()) $response['lookups']['brands'][] = ['name' => $row['name']]; $r->free(); }
    }

    if (!$try("SELECT id, name, brand_id FROM models ORDER BY name", 'models')) {
        // fallback: DISTINCT model + brand
        $r = $mysqli->query("SELECT DISTINCT model AS name, brand FROM cars WHERE model <> '' ORDER BY model");
        if ($r) { while($row=$r->fetch_assoc()) $response['lookups']['models'][] = ['name'=>$row['name'], 'brand'=>$row['brand']]; $r->free(); }
    }

    // vehicle types & bodies
    if (!$try("SELECT id, `key`, name FROM vehicle_types ORDER BY name", 'vehicle_types')) {
        $r = $mysqli->query("SELECT DISTINCT vehicle_type AS name FROM cars WHERE vehicle_type <> '' ORDER BY vehicle_type");
        if ($r) { while($row=$r->fetch_assoc()) $response['lookups']['vehicle_types'][] = ['name'=>$row['name']]; $r->free(); }
    }
    if (!$try("SELECT id, vehicle_type_id, `key`, name FROM vehicle_bodies ORDER BY name", 'vehicle_bodies')) {
        $r = $mysqli->query("SELECT DISTINCT body AS name, vehicle_type FROM cars WHERE body <> '' ORDER BY body");
        if ($r) { while($row=$r->fetch_assoc()) $response['lookups']['vehicle_bodies'][] = ['name'=>$row['name'], 'vehicle_type'=>$row['vehicle_type']]; $r->free(); }
    }

    // fuel types / gearboxes
    if (!$try("SELECT id, `key`, name FROM fuel_types ORDER BY name", 'fuel_types')) {
        $r = $mysqli->query("SELECT DISTINCT fuel AS name FROM cars WHERE fuel <> '' ORDER BY fuel");
        if ($r) { while($row=$r->fetch_assoc()) $response['lookups']['fuel_types'][] = ['name'=>$row['name']]; $r->free(); }
    }
    if (!$try("SELECT id, `key`, name FROM gearboxes ORDER BY name", 'gearboxes')) {
        $r = $mysqli->query("SELECT DISTINCT transmission AS name FROM cars WHERE transmission <> '' ORDER BY transmission");
        if ($r) { while($row=$r->fetch_assoc()) $response['lookups']['gearboxes'][] = ['name'=>$row['name']]; $r->free(); }
    }

    // years from cars table
    $r = $mysqli->query("SELECT DISTINCT year FROM cars WHERE year IS NOT NULL ORDER BY year DESC");
    if ($r) { while($row=$r->fetch_assoc()) $response['lookups']['vehicle_years'][] = ['year' => $row['year']]; $r->free(); }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    $m = "Unhandled (cars): ".$e->getMessage();
    dbglog($m);
    echo json_encode(['ok'=>false,'error'=>$m,'trace'=>$e->getTraceAsString()], JSON_UNESCAPED_UNICODE);
    exit;
}
