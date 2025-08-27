<?php
require_once __DIR__.'/../db.php';
header('Content-Type: application/json');

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
$types='';

if ($brand) { $sql .= " AND p.brand_id=?"; $params[]=$brand; $types.='i'; }
if ($model) { $sql .= " AND p.model_id=?"; $params[]=$model; $types.='i'; }
if ($year_from) { $sql .= " AND (p.year_from IS NULL OR p.year_from<=?)"; $params[]=$year_from; $types.='i'; }
if ($year_to) { $sql .= " AND (p.year_to IS NULL OR p.year_to>=?)"; $params[]=$year_to; $types.='i'; }
if ($cpart) { $sql .= " AND p.complex_part_id=?"; $params[]=$cpart; $types.='i'; }
if ($comp) { $sql .= " AND p.component_id=?"; $params[]=$comp; $types.='i'; }
if ($q!=='') {
  if (ctype_digit($q)) { 
    $sql .= " AND (p.id=? OR p.sku LIKE CONCAT('%',?,'%'))"; 
    $params[]=(int)$q; $types.='i'; 
    $params[]=$q; $types.='s'; 
  }
  else { 
    $sql .= " AND (p.name LIKE CONCAT('%',?,'%') OR p.sku LIKE CONCAT('%',?,'%'))"; 
    $params[]=$q; $params[]=$q; $types.='ss'; 
  }
}

$sql .= " ORDER BY p.id DESC LIMIT 200";

$stmt=$mysqli->prepare($sql);
if($params){ $stmt->bind_param($types, ...$params); }
$stmt->execute();
$res=$stmt->get_result();
$rows=[]; 
while($r=$res->fetch_assoc()){$rows[]=$r;}
echo json_encode(['products'=>$rows]);
