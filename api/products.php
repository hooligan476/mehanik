<?php
// api/products.php (debug / verbose version)
// Замените временно на эту версию для диагностики.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__.'/../db.php';
header('Content-Type: application/json; charset=utf-8');

$logFile = '/tmp/mehanik_products_error.log';
function dbglog($msg) {
    global $logFile;
    @file_put_contents($logFile, date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}

try {
    if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
        $err = "DB connection \$mysqli not found or invalid.";
        dbglog($err);
        echo json_encode(['ok' => false, 'error' => $err], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // input parsing
    $brand = isset($_GET['brand']) && $_GET['brand'] !== '' ? (int)$_GET['brand'] : null;
    $model = isset($_GET['model']) && $_GET['model'] !== '' ? (int)$_GET['model'] : null;
    $year_from = isset($_GET['year_from']) && $_GET['year_from'] !== '' ? (int)$_GET['year_from'] : null;
    $year_to = isset($_GET['year_to']) && $_GET['year_to'] !== '' ? (int)$_GET['year_to'] : null;
    $cpart = isset($_GET['complex_part']) && $_GET['complex_part'] !== '' ? (int)$_GET['complex_part'] : null;
    $comp = isset($_GET['component']) && $_GET['component'] !== '' ? (int)$_GET['component'] : null;
    $q = trim($_GET['q'] ?? '');
    $type = isset($_GET['type']) ? trim($_GET['type']) : '';

    $sql = "SELECT p.*, b.name as brand_name, m.name as model_name, cp.name as cpart_name, c.name as comp_name
            FROM products p
            LEFT JOIN brands b ON b.id=p.brand_id
            LEFT JOIN models m ON m.id=p.model_id
            LEFT JOIN complex_parts cp ON cp.id=p.complex_part_id
            LEFT JOIN components c ON c.id=p.component_id
            WHERE p.status = 'approved'";

    $params = [];
    $types = '';

    if ($brand) { $sql .= " AND p.brand_id=?"; $params[]=$brand; $types.='i'; }
    if ($model) { $sql .= " AND p.model_id=?"; $params[]=$model; $types.='i'; }
    if ($year_from) { $sql .= " AND (p.year_from IS NULL OR p.year_from<=?)"; $params[]=$year_from; $types.='i'; }
    if ($year_to) { $sql .= " AND (p.year_to IS NULL OR p.year_to>=?)"; $params[]=$year_to; $types.='i'; }
    if ($cpart) { $sql .= " AND p.complex_part_id=?"; $params[]=$cpart; $types.='i'; }
    if ($comp) { $sql .= " AND p.component_id=?"; $params[]=$comp; $types.='i'; }

    // server-side type filtering (auto / part)
    if ($type !== '') {
        $t = strtolower($type);
        if ($t === 'auto' || $t === 'vehicle' || $t === 'car') {
            $sql .= " AND (
                (p.is_part IS NULL OR p.is_part = 0)
                OR LOWER(IFNULL(p.item_type, '')) IN ('auto','vehicle','car')
                OR LOWER(IFNULL(p.type, '')) IN ('auto','vehicle','car')
                OR (p.brand_id IS NOT NULL OR p.model_id IS NOT NULL OR p.year_from IS NOT NULL OR p.year_to IS NOT NULL)
            )";
        } elseif ($t === 'part' || $t === 'parts' || $t === 'component') {
            $sql .= " AND (
                (p.is_part = 1)
                OR LOWER(IFNULL(p.item_type, '')) IN ('part','component')
                OR LOWER(IFNULL(p.type, '')) IN ('part','component')
                OR p.complex_part_id IS NOT NULL
                OR p.component_id IS NOT NULL
            )";
        } else {
            // comma-separated list handling
            $parts = array_map('trim', explode(',', $type));
            $conds = [];
            foreach ($parts as $pt) {
                if ($pt === '') continue;
                if (in_array(strtolower($pt), ['auto','vehicle','car'])) {
                    $conds[] = "((p.is_part IS NULL OR p.is_part = 0) OR LOWER(IFNULL(p.item_type, '')) IN ('auto','vehicle','car'))";
                } elseif (in_array(strtolower($pt), ['part','component'])) {
                    $conds[] = "((p.is_part = 1) OR LOWER(IFNULL(p.item_type, '')) IN ('part','component') OR p.complex_part_id IS NOT NULL)";
                }
            }
            if ($conds) {
                $sql .= ' AND (' . implode(' OR ', $conds) . ')';
            }
        }
    }

    // search
    if ($q !== '') {
        if (ctype_digit($q)) {
            $sql .= " AND (p.id=? OR p.sku LIKE CONCAT('%',?,'%'))";
            $params[] = (int)$q; $types .= 'i';
            $params[] = $q; $types .= 's';
        } else {
            $sql .= " AND (p.name LIKE CONCAT('%',?,'%') OR p.sku LIKE CONCAT('%',?,'%'))";
            $params[] = $q; $params[] = $q; $types .= 'ss';
        }
    }

    $sql .= " ORDER BY p.id DESC LIMIT 200";

    // prepare
    $stmt = $mysqli->prepare($sql);
    if ($stmt === false) {
        $err = "DB prepare failed: " . $mysqli->error . " -- SQL: " . $sql;
        dbglog($err);
        echo json_encode(['ok'=>false,'error'=>$err,'sql'=>$sql], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // bind params
    if (!empty($params)) {
        $bind_names = [];
        $bind_names[] = $types;
        for ($i = 0; $i < count($params); $i++) {
            $bind_name = 'bind' . $i;
            $$bind_name = $params[$i];
            $bind_names[] = &$$bind_name;
        }
        // call bind_param
        if (!call_user_func_array([$stmt, 'bind_param'], $bind_names)) {
            $err = "bind_param failed: " . $stmt->error . " -- types: " . $types . " params: " . json_encode($params);
            dbglog($err);
            echo json_encode(['ok'=>false,'error'=>$err,'sql'=>$sql,'params'=>$params,'types'=>$types], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    if (!$stmt->execute()) {
        $err = "Execute failed: " . $stmt->error . " -- SQL: " . $sql;
        dbglog($err);
        echo json_encode(['ok'=>false,'error'=>$err,'sql'=>$sql], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // get_result may not be available on some php builds; try fallback
    $rows = [];
    if (method_exists($stmt, 'get_result')) {
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $rows[] = $r;
    } else {
        // fallback using bind_result
        $meta = $stmt->result_metadata();
        if ($meta) {
            $fields = [];
            $out = [];
            while ($f = $meta->fetch_field()) {
                $fields[] = $f->name;
                $out[$f->name] = null;
                $bindParams[] = &$out[$f->name];
            }
            if (!empty($bindParams)) {
                call_user_func_array([$stmt, 'bind_result'], $bindParams);
                while ($stmt->fetch()) {
                    $row = [];
                    foreach ($fields as $fname) $row[$fname] = $out[$fname];
                    $rows[] = $row;
                }
            }
        }
    }
    $stmt->close();

    $response = [
        'ok' => true,
        'products' => $rows,
        'lookups' => [
            'brands' => [],
            'models' => [],
            'complex_parts' => [],
            'components' => [],
            'vehicle_types' => [],
            'vehicle_bodies' => [],
            'fuel_types' => [],
            'gearboxes' => [],
            'vehicle_years' => []
        ]
    ];

    // lookups (safe queries)
    $try = function($sqlq, $appendTo) use ($mysqli, &$response, $logFile) {
        $r = $mysqli->query($sqlq);
        if ($r) {
            while ($row = $r->fetch_assoc()) $response['lookups'][$appendTo][] = $row;
            $r->free();
        } else {
            dbglog("Lookup query failed: {$sqlq} -- " . $mysqli->error);
        }
    };

    $try("SELECT id, name FROM brands ORDER BY name", 'brands');
    $try("SELECT id, name, brand_id FROM models ORDER BY name", 'models');
    $try("SELECT id, name FROM complex_parts ORDER BY name", 'complex_parts');
    $try("SELECT id, name, complex_part_id FROM components ORDER BY name", 'components');

    // optional additional lookups — if tables missing, failures are logged but not fatal
    $try("SELECT id, `key`, name, `order` FROM vehicle_types ORDER BY `order` ASC, name ASC", 'vehicle_types');
    $try("SELECT id, vehicle_type_id, `key`, name, `order` FROM vehicle_bodies ORDER BY vehicle_type_id ASC, `order` ASC, name ASC", 'vehicle_bodies');
    $try("SELECT id, `key`, name, `order` FROM fuel_types WHERE COALESCE(active,1)=1 ORDER BY `order` ASC, name ASC", 'fuel_types');
    $try("SELECT id, `key`, name, `order` FROM gearboxes WHERE COALESCE(active,1)=1 ORDER BY `order` ASC, name ASC", 'gearboxes');
    $try("SELECT id, `year` FROM vehicle_years ORDER BY `year` DESC", 'vehicle_years');

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    $msg = "Unhandled exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine();
    dbglog($msg);
    echo json_encode(['ok'=>false,'error'=>$msg,'trace'=>$e->getTraceAsString()], JSON_UNESCAPED_UNICODE);
    exit;
}
