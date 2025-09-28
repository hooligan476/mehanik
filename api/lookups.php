<?php
// mehanik/api/lookups.php
require_once __DIR__ . '/../db.php';
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
  'vehicle_years' => [],

  // new lookups
  'car_colors' => [],
  'engine_volumes' => [],
  'passenger_counts' => [],
  'interior_colors' => [],
  'upholstery_types' => [],
  'ignition_types' => [],
  'regions' => [],
  'districts' => [],
  'districts_by_region' => []
];

try {
  // helper to run SELECT and return array of rows (works with mysqli or PDO)
  $run = function(string $sql) {
    global $mysqli, $pdo;
    $out = [];
    if (isset($mysqli) && $mysqli instanceof mysqli) {
      $r = @$mysqli->query($sql);
      if ($r) {
        while ($row = $r->fetch_assoc()) $out[] = $row;
        $r->free();
      }
    } elseif (isset($pdo) && $pdo instanceof PDO) {
      $st = @$pdo->query($sql);
      if ($st) $out = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return $out;
  };

  // existing sets
  $res['brands'] = $run("SELECT id, name FROM brands ORDER BY name");
  $res['models'] = $run("SELECT id, name, brand_id FROM models ORDER BY name");
  $res['complex_parts'] = $run("SELECT id, name FROM complex_parts ORDER BY name");
  $res['components'] = $run("SELECT id, name, complex_part_id FROM components ORDER BY name");

  // optional additional tables
  $res['vehicle_types'] = $run("SELECT id, `key`, name, `order` FROM vehicle_types ORDER BY `order` ASC, name ASC");
  $res['vehicle_bodies'] = $run("SELECT id, vehicle_type_id, `key`, name, `order` FROM vehicle_bodies ORDER BY vehicle_type_id ASC, `order` ASC, name ASC");
  $res['fuel_types'] = $run("SELECT id, `key`, name, `order` FROM fuel_types WHERE COALESCE(active,1)=1 ORDER BY `order` ASC, name ASC");
  $res['gearboxes'] = $run("SELECT id, `key`, name, `order` FROM gearboxes WHERE COALESCE(active,1)=1 ORDER BY `order` ASC, name ASC");
  $res['vehicle_years'] = $run("SELECT id, `year` FROM vehicle_years ORDER BY `year` DESC");

  // new lookups
  $res['car_colors'] = $run("SELECT id, name, `key`, `order`, active FROM car_colors WHERE COALESCE(active,1)=1 ORDER BY `order` ASC, name ASC");
  $res['engine_volumes'] = $run("SELECT id, label, `order`, active FROM engine_volumes WHERE COALESCE(active,1)=1 ORDER BY `order` ASC, label ASC");
  $res['passenger_counts'] = $run("SELECT id, cnt, label, `order`, active FROM passenger_counts WHERE COALESCE(active,1)=1 ORDER BY `order` ASC, cnt ASC");
  $res['interior_colors'] = $run("SELECT id, name, `order`, active FROM interior_colors WHERE COALESCE(active,1)=1 ORDER BY `order` ASC, name ASC");
  $res['upholstery_types'] = $run("SELECT id, name, `order`, active FROM upholstery_types WHERE COALESCE(active,1)=1 ORDER BY `order` ASC, name ASC");
  $res['ignition_types'] = $run("SELECT id, name, `key`, `order`, active FROM ignition_types WHERE COALESCE(active,1)=1 ORDER BY `order` ASC, name ASC");

  // regions & districts
  $res['regions'] = $run("SELECT id, name, `order`, active FROM regions WHERE COALESCE(active,1)=1 ORDER BY `order` ASC, name ASC");
  $res['districts'] = $run("SELECT id, region_id, name, `order`, active FROM districts WHERE COALESCE(active,1)=1 ORDER BY region_id ASC, `order` ASC, name ASC");

  // build districts_by_region mapping
  if (!empty($res['districts'])) {
    foreach ($res['districts'] as $d) {
      $rid = (int)($d['region_id'] ?? 0);
      if (!isset($res['districts_by_region'][$rid])) $res['districts_by_region'][$rid] = [];
      $res['districts_by_region'][$rid][] = $d;
    }
  }

} catch (Throwable $e) {
  $res['ok'] = false;
  $res['error'] = $e->getMessage();
}

echo json_encode($res, JSON_UNESCAPED_UNICODE);
