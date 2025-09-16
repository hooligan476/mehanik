<?php
// mehanik/api/lookups.php
require_once __DIR__.'/../db.php';
header('Content-Type: application/json; charset=utf-8');

$res = [
  'ok' => true,
  'brands' => [],
  'models' => [],
  'complex_parts' => [],
  'components' => [],
  'vehicle_types' => [],
  'vehicle_bodies' => [],
  'fuel_types' => [],
  'gearboxes' => [],
  'vehicle_years' => []
];

try {
  $r = $mysqli->query("SELECT id, name FROM brands ORDER BY name");
  if ($r) { while ($row = $r->fetch_assoc()) $res['brands'][] = $row; $r->free(); }

  $r = $mysqli->query("SELECT id, name, brand_id FROM models ORDER BY name");
  if ($r) { while ($row = $r->fetch_assoc()) $res['models'][] = $row; $r->free(); }

  $r = $mysqli->query("SELECT id, name FROM complex_parts ORDER BY name");
  if ($r) { while ($row = $r->fetch_assoc()) $res['complex_parts'][] = $row; $r->free(); }

  $r = $mysqli->query("SELECT id, name, complex_part_id FROM components ORDER BY name");
  if ($r) { while ($row = $r->fetch_assoc()) $res['components'][] = $row; $r->free(); }

  // optional additional tables - ignore errors if tables missing
  $r = $mysqli->query("SELECT id, `key`, name, `order` FROM vehicle_types ORDER BY `order` ASC, name ASC");
  if ($r) { while ($row = $r->fetch_assoc()) $res['vehicle_types'][] = $row; $r->free(); }

  $r = $mysqli->query("SELECT id, vehicle_type_id, `key`, name, `order` FROM vehicle_bodies ORDER BY vehicle_type_id ASC, `order` ASC, name ASC");
  if ($r) { while ($row = $r->fetch_assoc()) $res['vehicle_bodies'][] = $row; $r->free(); }

  $r = $mysqli->query("SELECT id, `key`, name, `order` FROM fuel_types WHERE COALESCE(active,1)=1 ORDER BY `order` ASC, name ASC");
  if ($r) { while ($row = $r->fetch_assoc()) $res['fuel_types'][] = $row; $r->free(); }

  $r = $mysqli->query("SELECT id, `key`, name, `order` FROM gearboxes WHERE COALESCE(active,1)=1 ORDER BY `order` ASC, name ASC");
  if ($r) { while ($row = $r->fetch_assoc()) $res['gearboxes'][] = $row; $r->free(); }

  $r = $mysqli->query("SELECT id, `year` FROM vehicle_years ORDER BY `year` DESC");
  if ($r) { while ($row = $r->fetch_assoc()) $res['vehicle_years'][] = $row; $r->free(); }

} catch (Throwable $e) {
  $res['ok'] = false;
  $res['error'] = $e->getMessage();
}

echo json_encode($res, JSON_UNESCAPED_UNICODE);
