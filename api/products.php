<?php
// api/products.php
require_once __DIR__.'/../db.php';
header('Content-Type: application/json; charset=utf-8');

$brand = isset($_GET['brand']) ? (int)$_GET['brand'] : null;
$model = isset($_GET['model']) ? (int)$_GET['model'] : null;
$year_from = isset($_GET['year_from']) && $_GET['year_from']!=='' ? (int)$_GET['year_from'] : null;
$year_to = isset($_GET['year_to']) && $_GET['year_to']!=='' ? (int)$_GET['year_to'] : null;
$cpart = isset($_GET['complex_part']) ? (int)$_GET['complex_part'] : null;
$comp = isset($_GET['component']) ? (int)$_GET['component'] : null;
$q = trim($_GET['q'] ?? '');

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

if ($q !== '') {
    if (ctype_digit($q)) {
        // если введено число — ищем по id или по sku
        $sql .= " AND (p.id=? OR p.sku LIKE CONCAT('%',?,'%'))";
        $params[] = (int)$q; $types .= 'i';
        $params[] = $q; $types .= 's';
    } else {
        $sql .= " AND (p.name LIKE CONCAT('%',?,'%') OR p.sku LIKE CONCAT('%',?,'%'))";
        $params[] = $q; $params[] = $q; $types .= 'ss';
    }
}

$sql .= " ORDER BY p.id DESC LIMIT 200";

$response = [
    'ok' => true,
    'products' => [],
    'lookups' => [
        'brands' => [],
        'models' => [],
        'complex_parts' => [],
        'components' => []
    ]
];

// подготовка и выполнение основного запроса
$stmt = $mysqli->prepare($sql);
if ($stmt === false) {
    $response['ok'] = false;
    $response['error'] = 'DB prepare failed: ' . $mysqli->error;
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}
if (!empty($params)) {
    // bind_param требует передаваемые параметры по ссылке — используем аргументы в переменных
    $bind_names[] = $types;
    for ($i=0; $i<count($params); $i++) {
        $bind_name = 'bind' . $i;
        $$bind_name = $params[$i];
        $bind_names[] = &$$bind_name;
    }
    call_user_func_array([$stmt, 'bind_param'], $bind_names);
}
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($r = $res->fetch_assoc()) {
    $rows[] = $r;
}
$stmt->close();

$response['products'] = $rows;

// теперь подгружаем lookup-списки (бренды/модели/части/компоненты)
try {
    // Бренды
    $r = $mysqli->query("SELECT id, name FROM brands ORDER BY name");
    if ($r) {
        while ($row = $r->fetch_assoc()) $response['lookups']['brands'][] = $row;
        $r->free();
    }

    // Модели (включаем brand_id чтобы можно было фильтровать на фронте)
    $r = $mysqli->query("SELECT id, name, brand_id FROM models ORDER BY name");
    if ($r) {
        while ($row = $r->fetch_assoc()) $response['lookups']['models'][] = $row;
        $r->free();
    }

    // Комплексные части
    $r = $mysqli->query("SELECT id, name FROM complex_parts ORDER BY name");
    if ($r) {
        while ($row = $r->fetch_assoc()) $response['lookups']['complex_parts'][] = $row;
        $r->free();
    }

    // Компоненты (с complex_part_id)
    $r = $mysqli->query("SELECT id, name, complex_part_id FROM components ORDER BY name");
    if ($r) {
        while ($row = $r->fetch_assoc()) $response['lookups']['components'][] = $row;
        $r->free();
    }
} catch (Exception $e) {
    // не критично — просто вернём пустые lookups и products (или те, что есть)
    $response['lookups_error'] = $e->getMessage();
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
