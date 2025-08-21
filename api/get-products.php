<?php
require_once __DIR__ . '/../../db.php';
header('Content-Type: application/json');

$filters = [
    'brand_id' => isset($_GET['brand_id']) ? (int)$_GET['brand_id'] : 0,
    'model_id' => isset($_GET['model_id']) ? (int)$_GET['model_id'] : 0,
    'year_from' => isset($_GET['year_from']) ? (int)$_GET['year_from'] : 0,
    'year_to' => isset($_GET['year_to']) ? (int)$_GET['year_to'] : 0,
    'complex_part_id' => isset($_GET['complex_part_id']) ? (int)$_GET['complex_part_id'] : 0,
    'component_id' => isset($_GET['component_id']) ? (int)$_GET['component_id'] : 0,
    'search' => isset($_GET['search']) ? $_GET['search'] : ''
];

$sql = "SELECT p.*, b.name AS brand_name, m.name AS model_name
        FROM products p
        LEFT JOIN brands b ON b.id = p.brand_id
        LEFT JOIN models m ON m.id = p.model_id
        WHERE 1";

$params = [];
$types = "";

if ($filters['brand_id']) { $sql .= " AND p.brand_id = ?"; $params[] = $filters['brand_id']; $types .= "i"; }
if ($filters['model_id']) { $sql .= " AND p.model_id = ?"; $params[] = $filters['model_id']; $types .= "i"; }
if ($filters['year_from']) { $sql .= " AND p.year_from >= ?"; $params[] = $filters['year_from']; $types .= "i"; }
if ($filters['year_to']) { $sql .= " AND p.year_to <= ?"; $params[] = $filters['year_to']; $types .= "i"; }
if ($filters['complex_part_id']) { $sql .= " AND p.complex_part_id = ?"; $params[] = $filters['complex_part_id']; $types .= "i"; }
if ($filters['component_id']) { $sql .= " AND p.component_id = ?"; $params[] = $filters['component_id']; $types .= "i"; }
if ($filters['search']) { $sql .= " AND (p.name LIKE ? OR p.sku LIKE ? OR p.id = ?)"; $params[] = "%".$filters['search']."%"; $params[] = "%".$filters['search']."%"; $params[] = (int)$filters['search']; $types .= "ssi"; }

$sql .= " ORDER BY p.id DESC";

$stmt = $mysqli->prepare($sql);

if ($params) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$res = $stmt->get_result();
$products = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode($products);
